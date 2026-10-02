<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/AssetCatalog.php';

use FreePBX\modules\Ucmprovisioner\AssetCatalog;
use FreePBX\modules\Ucmprovisioner\ModelCatalog;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$reasonCodes = static function (array $incompatible): array {
    $codes = [];
    foreach ($incompatible as $row) {
        foreach ((array)($row['reasons'] ?? []) as $reason) {
            $codes[] = (string)($reason['code'] ?? '');
        }
    }
    return $codes;
};

$expect(AssetCatalog::normalizeKind('firmware bundle') === 'firmware', 'Firmware alias was not normalized');
$expect(AssetCatalog::normalizeKind('wallpaper/background') === 'background', 'Wallpaper alias was not normalized');
$expect(AssetCatalog::normalizeKind('Ring Tone') === 'ringtone', 'Ringtone alias was not normalized');
$expect(AssetCatalog::normalizeKind('timezone/locale') === 'locale', 'Timezone/locale alias was not normalized');
$expect(AssetCatalog::normalizeKind('certificate') === null, 'Unknown artifact kind was accepted');

$secret = str_repeat('a', 32);
$backgroundProfile = ModelCatalog::backgroundProfileKey('Cisco', '8841');
$records = [
    ['bundles'=>[
        [
            'id'=>11, 'site_id'=>3, 'vendor'=>'Cisco', 'profile'=>'cisco:sip88xx',
            'load_name'=>'sip88xx.14-2-1-0201-40', 'status'=>'complete',
            'compatible_models'=>[['vendor'=>'Cisco','model'=>'8841']],
            'password'=>$secret, 'metadata'=>['pbx_api_secret'=>$secret],
        ],
        [
            'id'=>12, 'site_id'=>3, 'vendor'=>'Cisco', 'profile'=>'cisco:sip8845_65',
            'load_name'=>'sip8845_65.14-2-1-0201-40', 'status'=>'complete',
            'compatible_models'=>[['vendor'=>'Cisco','model'=>'8845']],
        ],
        [
            'id'=>13, 'site_id'=>3, 'vendor'=>'Cisco', 'profile'=>'cisco:sip88xx',
            'load_name'=>'sip88xx.incomplete', 'status'=>'incomplete',
            'compatible_models'=>[['vendor'=>'Cisco','model'=>'8841']],
        ],
    ]],
    [
        'kind'=>'wallpaper', 'family'=>'cisco_xml', 'profile'=>$backgroundProfile, 'model'=>'all',
        'items'=>[
            [
                'name'=>'Company.png', 'size'=>1200, 'mtime'=>100,
                'url'=>"https://media:$secret@pbx.example/ucmprovisioner-media.php"
                    . "?family=cisco_xml&model=all&file=Company.png&token=$secret#private",
                'authorization'=>$secret,
            ],
            ['name'=>'Company-tn.png', 'size'=>300, 'mtime'=>100],
        ],
    ],
    [
        'family'=>'cisco_xml', 'model'=>'all',
        'items'=>[['name'=>'Classic.raw', 'size'=>8000, 'mtime'=>101]],
    ],
    [
        'family'=>'cisco_xml',
        'entries'=>[
            ['name'=>'j9-tzdata.jar', 'size'=>9000, 'mtime'=>102, 'dir'=>false],
            ['name'=>'README.txt', 'size'=>20, 'mtime'=>102, 'dir'=>false],
        ],
    ],
];
$target = ['vendor'=>'Cisco', 'family'=>'cisco_xml', 'model'=>'8841', 'role'=>'desk'];
$typedEnvelope=AssetCatalog::select(['kind'=>'ringtone','bundles'=>[['name'=>'Typed.raw','status'=>'verified']]],$target+['kind'=>'ringtone']);
$expect(($typedEnvelope['selected']['name']??'')==='Typed.raw','An explicit envelope kind must not be overwritten by the bundles default');
$nested=AssetCatalog::select([['kind'=>'ringtone','name'=>'Private.raw','manifest'=>['auth_token'=>$secret,'files'=>[
    ['filename'=>'Private.raw','password'=>$secret,'source_url'=>'https://user:'.$secret.'@example.test/file?token='.$secret],
]],'issues'=>['request failed: password='.$secret]]],$target+['kind'=>'ringtone']);
$expect(!str_contains(json_encode($nested),$secret),'Nested manifest fields, URLs and embedded diagnostics must remain secret-free');

$firmware = AssetCatalog::select($records, ['kind'=>'firmware'] + $target);
$expect(($firmware['target']['profile'] ?? '') === 'cisco:sip88xx', 'Firmware profile was not derived from ModelCatalog');
$expect(($firmware['selected']['id'] ?? 0) === 11, 'The complete compatible firmware bundle was not selected');
$expect(count($firmware['available']) === 1, 'Incompatible or incomplete firmware remained available');
$firmwareCodes = $reasonCodes($firmware['incompatible']);
$expect(in_array('profile_mismatch', $firmwareCodes, true), 'Wrong firmware profile lacks an incompatibility reason');
$expect(in_array('firmware_not_complete', $firmwareCodes, true), 'Incomplete firmware lacks an availability reason');
$expect(!str_contains((string)json_encode($firmware), $secret), 'Firmware result exposed a secret-bearing source field');

$background = AssetCatalog::select($records, ['kind'=>'background'] + $target);
$expect(($background['target']['profile'] ?? '') === $backgroundProfile, 'Background profile was not derived from ModelCatalog');
$expect(($background['selected']['name'] ?? '') === 'Company.png', 'The full compatible wallpaper was not selected');
$expect(in_array('thumbnail_not_selectable', $reasonCodes($background['incompatible']), true), 'Thumbnail was not rejected explicitly');
$expect(!str_contains((string)json_encode($background), $secret), 'Background result exposed URL credentials or a source secret');
$expect(
    ($background['selected']['url'] ?? '') === 'https://pbx.example/ucmprovisioner-media.php?family=cisco_xml&model=all&file=Company.png',
    'Safe media URL fields were not preserved after secret removal'
);

$ringtone = AssetCatalog::select($records, ['kind'=>'ringtone'] + $target);
$expect(($ringtone['kind'] ?? '') === 'ringtone', 'Ringtone selection returned the wrong canonical kind');
$expect(($ringtone['selected']['name'] ?? '') === 'Classic.raw', 'Managed ringtone was not inferred and selected');
$expect(($ringtone['target']['profile'] ?? '') === 'cisco-ringtone', 'Cisco ringtone compatibility profile was not derived');

$locale = AssetCatalog::select($records, ['kind'=>'timezone/locale'] + $target);
$expect(($locale['kind'] ?? '') === 'locale', 'Locale selection returned the wrong canonical kind');
$expect(($locale['selected']['name'] ?? '') === 'j9-tzdata.jar', 'Timezone payload was not inferred and selected');
$expect($locale['unrecognized_count'] === 1, 'Unrecognized TFTP entries were not counted without exposing them');

$unsupported = AssetCatalog::select(
    [['kind'=>'background', 'family'=>'cisco_xml', 'name'=>'Company.png']],
    ['kind'=>'wallpaper', 'vendor'=>'Cisco', 'family'=>'cisco_xml', 'model'=>'7841', 'role'=>'desk']
);
$expect($unsupported['selected'] === null
    && ($unsupported['missing_reason']['code'] ?? '') === 'unsupported_for_model',
    'A model without a verified wallpaper profile did not fail closed');

$roleMismatch = AssetCatalog::select(
    [[
        'kind'=>'firmware', 'vendor'=>'Yealink', 'profile'=>'yealink:w60b',
        'load_name'=>'firmware/yealink/w60b/W60B.rom', 'status'=>'complete',
    ]],
    ['kind'=>'firmware', 'vendor'=>'Yealink', 'family'=>'yealink', 'model'=>'W60B', 'role'=>'desk']
);
$expect(($roleMismatch['missing_reason']['code'] ?? '') === 'target_role_mismatch',
    'A caller-supplied role that contradicts ModelCatalog was not rejected');

$ambiguous = AssetCatalog::select(
    [
        ['kind'=>'firmware', 'vendor'=>'Cisco', 'profile'=>'cisco:sip88xx', 'load_name'=>'release-a', 'status'=>'complete'],
        ['kind'=>'firmware', 'vendor'=>'Cisco', 'profile'=>'cisco:sip88xx', 'load_name'=>'release-b', 'status'=>'complete'],
    ],
    ['kind'=>'firmware'] + $target
);
$expect($ambiguous['selected'] === null
    && ($ambiguous['selection_reason']['code'] ?? '') === 'ambiguous'
    && count($ambiguous['available']) === 2,
    'Equally compatible firmware releases were selected arbitrarily');

$missing = AssetCatalog::select([], ['kind'=>'locale'] + $target);
$expect(($missing['missing_reason']['code'] ?? '') === 'no_managed_artifacts',
    'An empty managed catalog lacks a missing-artifact reason');

echo "Asset catalog fixtures passed\n";
