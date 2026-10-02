<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/AssetCatalog.php';
require_once dirname(__DIR__) . '/lib/ManagedAssetRegistry.php';
require_once dirname(__DIR__) . '/lib/CiscoTimezoneService.php';
require_once dirname(__DIR__) . '/lib/AssetLifecycleService.php';

use FreePBX\modules\Ucmprovisioner\AssetLifecycleService;
use FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry;
use FreePBX\modules\Ucmprovisioner\ModelCatalog;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ManagedAssetRegistry::ensureSchema($db);
$db->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY, site_id INTEGER, skey TEXT, sval TEXT)');
$db->exec('CREATE TABLE ucmp_templates (id INTEGER PRIMARY KEY, site_id INTEGER, name TEXT, phonebook_filename TEXT, setting_overrides TEXT)');
$db->exec('CREATE TABLE ucmp_phones (id INTEGER PRIMARY KEY, site_id INTEGER, mac TEXT, extension TEXT, overrides TEXT)');
$db->exec('CREATE TABLE ucmp_phonebooks (id INTEGER PRIMARY KEY, name TEXT, filename TEXT)');

$now = 1000;
$registry = new ManagedAssetRegistry($db, static function () use (&$now): int { return $now; });
$backgroundProfile = (string)ModelCatalog::backgroundProfileKey('Cisco', '8841');
$fullHash = str_repeat('1', 64);
$thumbHash = str_repeat('2', 64);
$catalogHash = str_repeat('3', 64);
$thumb = $registry->upsert([
    'site_id'=>1, 'kind'=>'wallpaper', 'content_sha256'=>$thumbHash,
    'filename'=>'Company-tn.png', 'relative_path'=>'Desktops/800x480x24/Company-tn.png',
    'size_bytes'=>200, 'content_type'=>'image/png', 'vendor'=>'Cisco',
    'family'=>'cisco_xml', 'profile'=>$backgroundProfile,
    'bundle_key'=>'company-wallpaper', 'bundle_role'=>'thumbnail',
    'validation_status'=>'verified', 'signature_status'=>'not_applicable',
    'provenance'=>'generated', 'validated_at'=>1000,
    'metadata'=>['width'=>123, 'height'=>111, 'format'=>'png', 'magic'=>'PNG', 'alpha'=>false, 'animated'=>false],
]);
$full = $registry->upsert([
    'site_id'=>1, 'kind'=>'background', 'content_sha256'=>$fullHash,
    'filename'=>'Company.png', 'relative_path'=>'Desktops/800x480x24/Company.png',
    'size_bytes'=>1200, 'content_type'=>'image/png', 'vendor'=>'Cisco',
    'family'=>'cisco_xml', 'profile'=>$backgroundProfile,
    'bundle_key'=>'company-wallpaper', 'bundle_role'=>'full',
    'validation_status'=>'verified', 'signature_status'=>'not_applicable',
    'provenance'=>'generated', 'validated_at'=>1000,
    'metadata'=>['width'=>800, 'height'=>480, 'format'=>'png', 'magic'=>'PNG', 'alpha'=>false, 'animated'=>false],
    'companions'=>[[
        'kind'=>'background', 'content_sha256'=>$thumbHash,
        'filename'=>'Company-tn.png', 'profile'=>$backgroundProfile,
        'relation'=>'thumbnail', 'required'=>true,
    ]],
]);
$catalog = $registry->upsert([
    'site_id'=>1, 'kind'=>'background', 'content_sha256'=>$catalogHash,
    'filename'=>'List.xml', 'relative_path'=>'Desktops/800x480x24/List.xml',
    'size_bytes'=>400, 'content_type'=>'application/xml', 'vendor'=>'Cisco',
    'family'=>'cisco_xml', 'profile'=>$backgroundProfile,
    'bundle_key'=>'wallpaper-catalog', 'bundle_role'=>'catalog',
    'validation_status'=>'verified', 'signature_status'=>'not_applicable',
    'provenance'=>'generated', 'validated_at'=>1000,
]);
$db->prepare('INSERT INTO ucmp_templates VALUES (?,?,?,?,?)')->execute([
    8, 1, '8841 Base', null, json_encode(['wallpaper_asset_id'=>(int)$full['asset_id']]),
]);

$service = new AssetLifecycleService($db);
$wallpapers = $service->inventory(1, ['kind'=>'wallpaper', 'profile'=>$backgroundProfile]);
$fullCatalog = array_values(array_filter($wallpapers, static fn(array $asset): bool => $asset['filename'] === 'Company.png'))[0] ?? null;
$expect(
    is_array($fullCatalog)
    && $fullCatalog['status'] === 'verified'
    && $fullCatalog['dependency_complete'] === true
    && $fullCatalog['usage_count'] === 1
    && ($fullCatalog['usage'][0]['type'] ?? '') === 'template',
    'Verified wallpaper must expose its complete manifest, dependency state, and template usage'
);
$db->prepare('INSERT INTO ucmp_settings VALUES (?,?,?,?)')->execute([
    9, 1, 'cisco_wallpaper_defaults', json_encode([$backgroundProfile=>'Company.png']),
]);
$catalogUsage = $service->get(1, (int)$catalog['asset_id']);
$expect(
    ($catalogUsage['usage_count'] ?? 0) > 0
    && in_array('cisco_wallpaper_defaults', array_column((array)$catalogUsage['usage'], 'label'), true),
    'A generated List.xml must inherit usage from a site-default wallpaper assigned in its profile'
);
$selection = $service->validateSelection(1, (int)$full['asset_id'], [
    'kind'=>'wallpaper', 'vendor'=>'Cisco', 'family'=>'cisco_xml', 'model'=>'8841', 'role'=>'desk',
]);
$expect($selection['ok'] === true, 'A verified wallpaper must validate by stable site-scoped asset ID');
$expect(
    $service->selections(1, [
        'kind'=>'wallpaper', 'vendor'=>'Cisco', 'family'=>'cisco_xml', 'model'=>'8841', 'role'=>'desk',
    ])['selected']['asset_id'] === $full['asset_id'],
    'Compatibility-filtered choices must select the only verified full wallpaper, not its thumbnail or List.xml'
);

$orphan = $registry->upsert([
    'site_id'=>1, 'kind'=>'background', 'content_sha256'=>str_repeat('4', 64),
    'filename'=>'Orphan.png', 'relative_path'=>'Desktops/800x480x24/Orphan.png',
    'size_bytes'=>500, 'content_type'=>'image/png', 'profile'=>$backgroundProfile,
    'bundle_key'=>'orphan-wallpaper', 'bundle_role'=>'full',
    'validation_status'=>'verified', 'provenance'=>'operator_upload',
    'signature_status'=>'not_applicable', 'validated_at'=>1000,
    'companions'=>[[
        'kind'=>'background', 'content_sha256'=>str_repeat('5', 64),
        'filename'=>'Orphan-tn.png', 'profile'=>$backgroundProfile,
        'relation'=>'thumbnail', 'required'=>true,
    ]],
]);
$orphanCatalog = $service->get(1, (int)$orphan['asset_id']);
$expect(
    $orphanCatalog['status'] === 'incomplete'
    && in_array('required_companion_missing', array_column($orphanCatalog['issues'], 'code'), true),
    'A claimed verified asset with a missing required companion must fail closed as incomplete'
);

$timezone = $registry->upsert([
    'site_id'=>1, 'kind'=>'timezone', 'content_sha256'=>str_repeat('6', 64),
    'filename'=>'j9-tzdata.jar', 'relative_path'=>'j9-tzdata.jar',
    'size_bytes'=>9000, 'content_type'=>'application/java-archive',
    'vendor'=>'Cisco', 'family'=>'cisco_xml', 'profile'=>'cisco-78xx-88xx',
    'asset_version'=>'2025b', 'bundle_key'=>'cisco-timezone:cisco-78xx-88xx',
    'bundle_role'=>'timezone-data', 'validation_status'=>'verified',
    'provenance'=>'operator_upload', 'validated_at'=>1000,
    'signature_status'=>'unavailable',
    'metadata'=>['declared_tzdata_version'=>'2025b', 'timezone_profile'=>'cisco-78xx-88xx', 'magic'=>'ZIP'],
]);
$binding = [
    'olson_zone'=>'Asia/Tehran', 'timezone_profile'=>'cisco-78xx-88xx',
    'payload_asset_id'=>$timezone['asset_id'], 'tzdata_version'=>'2025b',
    'sha256'=>$timezone['content_sha256'],
];
$expect($service->validateTimezoneBinding(1, $binding)['ok'] === true, 'Exact Cisco timezone payload binding must validate');
$binding['sha256'] = str_repeat('7', 64);
$expect(
    $service->validateTimezoneBinding(1, $binding)['ok'] === false,
    'A changed Cisco timezone payload hash must make the bound selection stale'
);

$timezoneFiles = [
    'tzupdater.ver'=>'version',
    'j9-tzdata.jar'=>'j9-payload',
    'TzDataCSV.csv'=>'csv-payload',
];
$timezoneInspectionFiles = [];
foreach ($timezoneFiles as $filename=>$role) {
    $hash = hash('sha256', $filename);
    $registry->upsert([
        'site_id'=>2, 'kind'=>'locale', 'content_sha256'=>$hash,
        'filename'=>$filename, 'relative_path'=>$filename,
        'size_bytes'=>strlen($filename), 'content_type'=>$filename === 'tzupdater.ver' ? 'text/plain' : 'application/octet-stream',
        'vendor'=>'Cisco', 'family'=>'cisco_xml',
        'profile'=>$filename === 'j9-tzdata.jar' ? 'cisco-78xx-88xx' : ($filename === 'TzDataCSV.csv' ? 'cisco-39xx-69xx-894x' : 'cisco-timezone-metadata'),
        'bundle_key'=>'cisco-timezone:' . strtolower($filename), 'bundle_role'=>$role,
        'asset_version'=>$filename === 'tzupdater.ver' ? null : '2020a',
        'validation_status'=>'discovered', 'provenance'=>'tftp_observation',
    ]);
    $timezoneInspectionFiles[] = [
        'name'=>$filename, 'actual_name'=>$filename, 'role'=>$role,
        'present'=>true, 'readable'=>true, 'exact_case'=>true,
        'size'=>strlen($filename), 'sha256'=>$hash,
    ];
}
foreach (['tzupdater.jar'=>'java-payload','tzdb.dat'=>'database'] as $filename=>$role) {
    $timezoneInspectionFiles[] = ['name'=>$filename, 'role'=>$role, 'present'=>false];
}
$timezoneReconciliation = $service->reconcileTimezone(2, [
    'ok'=>true,
    'status'=>'partial',
    'served_version'=>'2026a',
    'derived_version'=>'2026a',
    'files'=>$timezoneInspectionFiles,
    'compatibility'=>[
        'cisco-78xx-88xx'=>[
            'payload'=>'j9-tzdata.jar',
            'required_files'=>['j9-tzdata.jar'],
            'status'=>'unverified',
            'configuration_usable'=>true,
            'actual_version'=>'',
        ],
        'cisco-79xx-8961-99x1'=>[
            'payload'=>'tzupdater.jar',
            'required_files'=>['tzupdater.jar'],
            'status'=>'incomplete',
        ],
        'cisco-39xx-69xx-894x'=>[
            'payload'=>'TzDataCSV.csv',
            'required_files'=>['TzDataCSV.csv'],
            'status'=>'unverified',
            'configuration_usable'=>true,
            'actual_version'=>'',
        ],
    ],
    'issues'=>[],
]);
$reconciledTimezone = $service->inventory(2, ['kind'=>'locale']);
$timezoneByName = [];
foreach ($reconciledTimezone as $asset) { $timezoneByName[(string)$asset['filename']] = $asset; }
$expect(
    $timezoneReconciliation['updated'] === 3
    && count($reconciledTimezone) === 3
    && ($timezoneByName['tzupdater.ver']['status'] ?? '') === 'verified'
    && ($timezoneByName['tzupdater.ver']['asset_version'] ?? '') === '2026a'
    && ($timezoneByName['j9-tzdata.jar']['status'] ?? '') === 'verified'
    && ($timezoneByName['j9-tzdata.jar']['asset_version'] ?? null) === '2026a'
    && ($timezoneByName['j9-tzdata.jar']['metadata']['version_evidence'] ?? '') === 'declared_by_tzupdater.ver'
    && ($timezoneByName['TzDataCSV.csv']['status'] ?? '') === 'verified'
    && ($timezoneByName['TzDataCSV.csv']['asset_version'] ?? null) === '2026a',
    'Configuration-usable J9 and CSV payloads must be verified against their active set while retaining explicit declared-version provenance'
);

$firmwareProfile = (string)ModelCatalog::firmwareProfile('Cisco', '8841');
$primary = $registry->upsert([
    'site_id'=>1, 'kind'=>'firmware', 'content_sha256'=>str_repeat('8', 64),
    'filename'=>'sip88xx.14-2-1.loads', 'relative_path'=>'sip88xx.14-2-1.loads',
    'size_bytes'=>100, 'content_type'=>'text/plain', 'vendor'=>'Cisco',
    'profile'=>$firmwareProfile, 'bundle_key'=>'firmware-observation',
    'bundle_role'=>'manifest', 'validation_status'=>'discovered',
    'provenance'=>'tftp_observation', 'signature_status'=>'unverified',
]);
$dependency = $registry->upsert([
    'site_id'=>1, 'kind'=>'firmware', 'content_sha256'=>str_repeat('9', 64),
    'filename'=>'sip88xx.14-2-1.sbn', 'relative_path'=>'sip88xx.14-2-1.sbn',
    'size_bytes'=>200, 'content_type'=>'application/octet-stream',
    'bundle_role'=>'dependency', 'validation_status'=>'discovered',
    'provenance'=>'tftp_observation', 'signature_status'=>'unverified',
]);
$reconciled = $service->reconcileFirmware(1, [[
    'vendor'=>'Cisco', 'profile'=>$firmwareProfile,
    'load_name'=>'sip88xx.14-2-1', 'status'=>'complete',
    'files'=>[
        ['path'=>'sip88xx.14-2-1.loads', 'name'=>'sip88xx.14-2-1.loads', 'role'=>'primary'],
        ['path'=>'sip88xx.14-2-1.sbn', 'name'=>'sip88xx.14-2-1.sbn', 'role'=>'dependency'],
    ],
]]);
$firmware = $service->get(1, (int)$primary['asset_id']);
$dependencyAsset = $service->get(1, (int)$dependency['asset_id']);
$expect(
    $reconciled['updated'] === 2
    && $firmware['status'] === 'verified'
    && $dependencyAsset['status'] === 'verified'
    && count($firmware['manifest']) === 2
    && $firmware['signature_status'] === 'unverified'
    && $firmware['asset_version'] === '14-2-1',
    'Complete firmware must validate every observed package member against one exact manifest without falsely claiming vendor authenticity'
);
$expect(
    $service->validateSelection(1, (int)$primary['asset_id'], [
        'kind'=>'firmware', 'vendor'=>'Cisco', 'family'=>'cisco_xml', 'model'=>'8841', 'role'=>'desk',
    ])['ok'] === true,
    'Verified firmware manifest must validate for its exact compatibility profile'
);
$expect((int)$dependency['asset_id'] !== (int)$primary['asset_id'], 'File observations must retain stable distinct asset IDs');

$now = 1100;
$repeat = $registry->upsert([
    'site_id'=>1, 'kind'=>'background', 'content_sha256'=>$fullHash,
    'filename'=>'Company.png', 'relative_path'=>'Desktops/800x480x24/Company.png',
    'profile'=>$backgroundProfile,
]);
$expect((int)$repeat['asset_id'] === (int)$full['asset_id'], 'Rediscovery must retain the stable numeric asset ID');
$expect($service->get(1, (int)$full['asset_id'])['status'] === 'verified', 'Byte-only rediscovery must retain validation metadata');

$registry->markSlotStale(
    1,
    'background',
    'Orphan.png',
    $backgroundProfile,
    'orphan-wallpaper'
);
$currentWallpapers = $service->inventory(1, ['kind'=>'background', 'state'=>'current']);
$allWallpapers = $service->inventory(1, ['kind'=>'background', 'state'=>'all']);
$expect(
    !in_array('Orphan.png', array_column($currentWallpapers, 'filename'), true)
    && in_array('Orphan.png', array_column($allWallpapers, 'filename'), true),
    'Normal lifecycle inventory must omit retired assets while explicit history can include them'
);
$badStateRejected = false;
try {
    $service->inventory(1, ['state'=>'deleted']);
} catch (InvalidArgumentException $error) {
    $badStateRejected = str_contains($error->getMessage(), 'state');
}
$expect($badStateRejected, 'Lifecycle inventory must reject unsupported asset states');

for ($index=0;$index<510;$index++) {
    $registry->upsert(['site_id'=>9,'kind'=>'background','filename'=>'History'.$index.'.png','content_sha256'=>hash('sha256',(string)$index),'size_bytes'=>1]);
}
$late=$registry->upsert(['site_id'=>9,'kind'=>'locale','filename'=>'late.jar','content_sha256'=>str_repeat('f',64),'size_bytes'=>1]);
$expect(count($service->inventory(9,['kind'=>'locale']))===1,'Kind filtering must precede the first-page limit');
$expect($service->get(9,$late['asset_id'])['asset_key']===$late['asset_key'],'Stable ID lookup must include assets after row 500');
$first=$service->inventoryPage(9);$second=$service->inventoryPage(9,['offset'=>$first['next_offset']]);
$expect($first['total']===511&&$first['truncated']&&count($first['assets'])+count($second['assets'])===511&&!$second['truncated'],'Pagination must expose total and every site asset without silent truncation');
$phoneSources=$db->query('SELECT id,site_id,overrides FROM ucmp_phones')->fetchAll(PDO::FETCH_ASSOC);
$beforeUsage=$service->usageIndex(1);
$db->exec('ALTER TABLE ucmp_phones DROP COLUMN mac');
$referenceGraph=(new FreePBX\modules\Ucmprovisioner\AssetReferenceIndex($db))->snapshot(1);
$expect(count(array_filter($referenceGraph['sources'],static fn(array $source):bool=>$source['type']==='phone'))===count(array_filter($phoneSources,static fn(array $row):bool=>(int)$row['site_id']===1)),
    'The shared reference collector must retain each phone source without an unused display column');
$expect($service->usageIndex(1)===$beforeUsage,'Usage must come from the same shared reference collector without redundant legacy schema queries');
echo "Asset lifecycle service fixtures passed\n";
