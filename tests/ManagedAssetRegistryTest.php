<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ManagedAssetRegistry.php';

use FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$rejects = static function (callable $operation, string $message) use ($expect): void {
    try {
        $operation();
    } catch (InvalidArgumentException $e) {
        return;
    }
    $expect(false, $message);
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ManagedAssetRegistry::ensureSchema($db);

$now = 100;
$registry = new ManagedAssetRegistry($db, static function () use (&$now): int {
    return $now;
});

$firmwareHash = str_repeat('a', 64);
$localeHash = str_repeat('b', 64);
$ringtoneHash = str_repeat('c', 64);
$firmware = $registry->upsert([
    'site_id' => 1,
    'kind' => 'firmware',
    'content_sha256' => strtoupper($firmwareHash),
    'filename' => 'sip88xx.14-2-1.loads',
    'size_bytes' => 2048,
    'content_type' => 'application/octet-stream',
    'vendor' => 'Cisco',
    'family' => 'cisco_xml',
    'series_key' => 'cisco-88xx',
    'model' => '8841',
    'role' => 'desk',
    'profile' => 'cisco:sip88xx',
    'asset_version' => '14-2-1',
    'bundle_key' => 'cisco-sip88xx-14-2-1',
    'bundle_role' => 'load',
    'companions' => [
        [
            'kind' => 'ringtone',
            'content_sha256' => $ringtoneHash,
            'filename' => 'Classic.raw',
            'profile' => 'cisco-ringtone',
            'relation' => 'ringtone',
            'required' => false,
        ],
        [
            'kind' => 'locale',
            'content_sha256' => $localeHash,
            'filename' => 'j9-tzdata.jar',
            'profile' => 'cisco-78xx-88xx',
            'relation' => 'timezone',
            'required' => true,
        ],
    ],
]);

$expectedKey = ManagedAssetRegistry::address('firmware', $firmwareHash, 'sip88xx.14-2-1.loads', 'cisco:sip88xx');
$expect($firmware['asset_key'] === $expectedKey, 'Asset address must be derived from kind, content hash, and filename');
$expect($firmware['content_sha256'] === $firmwareHash, 'Content SHA-256 must be normalized to lowercase');
$expect($firmware['state'] === 'current' && $firmware['first_seen'] === 100, 'A new asset must be current and timestamped');
$companionKeys = array_column($firmware['companions'], 'asset_key');
$sortedCompanionKeys = $companionKeys;
sort($sortedCompanionKeys, SORT_STRING);
$expect($companionKeys === $sortedCompanionKeys, 'Companion references must have deterministic ordering');
$expect(
    array_column($firmware['companions'], 'relation') !== []
    && !isset($firmware['companions'][0]['filename']),
    'Companion metadata must persist hash references, not paths or filenames'
);

$now = 120;
foreach ([
    ['kind'=>'background', 'content_sha256'=>str_repeat('d', 64), 'filename'=>'Company.png', 'content_type'=>'image/png'],
    ['kind'=>'ringtone', 'content_sha256'=>$ringtoneHash, 'filename'=>'Classic.raw', 'content_type'=>'audio/basic'],
    ['kind'=>'locale', 'content_sha256'=>$localeHash, 'filename'=>'j9-tzdata.jar', 'content_type'=>'application/java-archive'],
    ['kind'=>'phonebook', 'content_sha256'=>str_repeat('e', 64), 'filename'=>'company-directory.xml', 'content_type'=>'application/xml'],
] as $asset) {
    $registry->upsert(['site_id'=>1, 'size_bytes'=>1] + $asset);
}
$registry->upsert([
    'site_id'=>2,
    'kind'=>'firmware',
    'content_sha256'=>$firmwareHash,
    'filename'=>'sip88xx.14-2-1.loads',
    'size_bytes'=>2048,
    'profile'=>'cisco:sip88xx',
]);

$siteOne = $registry->list(1, ['state'=>'all']);
$expect(
    array_column($siteOne, 'kind') === ['background', 'firmware', 'locale', 'phonebook', 'ringtone'],
    'Asset listing must be deterministic and include every supported kind'
);
$expect(count($registry->list(1, ['bundle_key'=>'cisco-sip88xx-14-2-1'])) === 1, 'Bundle filtering must remain site scoped');
$expect($registry->get(2, $expectedKey) !== null && $registry->get(3, $expectedKey) === null, 'Asset lookup must never cross site boundaries');

$now = 150;
$expect($registry->markStale(1, 'firmware', 120) === 1, 'Completed scan must stale only older assets of its site and kind');
$expect(count($registry->list(1)) === 4, 'Default listing must omit stale assets');
$expect(($registry->get(1, $expectedKey)['state'] ?? '') === 'stale', 'Marked asset must remain addressable as stale');
$expect(($registry->get(2, $expectedKey)['state'] ?? '') === 'current', 'Mark-stale must not affect another site');
$expect($registry->markStale(1, 'background', 120) === 0, 'Assets seen exactly at the scan cutoff must remain current');

$now = 160;
$revived = $registry->upsert([
    'site_id' => 1,
    'kind' => 'firmware',
    'content_sha256' => $firmwareHash,
    'filename' => 'sip88xx.14-2-1.loads',
    'size_bytes' => 4096,
    'profile' => 'cisco:sip88xx',
]);
$expect($revived['id'] === $firmware['id'], 'Repeated discovery must update the same deterministic asset row');
$expect(
    $revived['state'] === 'current'
    && $revived['first_seen'] === 100
    && $revived['last_seen'] === 160
    && $revived['size_bytes'] === 4096,
    'Rediscovery must revive a stale asset while preserving first-seen history'
);
$expect(
    $revived['asset_version'] === '14-2-1'
    && count($revived['companions']) === 2
    && $revived['model'] === '8841',
    'A byte-only rescan must not erase richer manager metadata it did not supply'
);

$now = 170;
$replacement = $registry->upsert([
    'site_id' => 1,
    'kind' => 'firmware',
    'content_sha256' => str_repeat('1', 64),
    'filename' => 'sip88xx.14-3.loads',
]);
$expect(
    $registry->markOtherVersionsStale(1, 'firmware', [$replacement['asset_key']]) === 1,
    'A complete current set must retire other content versions deterministically'
);
$expect(
    ($registry->get(1, $expectedKey)['state'] ?? '') === 'stale'
    && ($registry->get(1, $replacement['asset_key'])['state'] ?? '') === 'current'
    && ($registry->get(2, $expectedKey)['state'] ?? '') === 'current',
    'Complete-set retirement must preserve the selected version and remain site scoped'
);
$rejects(
    static fn() => $registry->markOtherVersionsStale(1, 'firmware', []),
    'An empty current set must fail closed'
);

$otherProfile = $registry->upsert([
    'site_id'=>1,
    'kind'=>'firmware',
    'content_sha256'=>$firmwareHash,
    'filename'=>'sip88xx.14-2-1.loads',
    'profile'=>'cisco:sip78xx',
]);
$expect(
    $otherProfile['asset_key'] !== $expectedKey,
    'Equal bytes and basename in different compatibility profiles must remain distinct assets'
);

$now = 180;
$oldSlot = $registry->upsert([
    'site_id'=>1,
    'kind'=>'background',
    'content_sha256'=>str_repeat('2', 64),
    'filename'=>'Brand.png',
    'profile'=>'cisco-background:800x480x24',
    'bundle_key'=>'cisco-bg:800x480x24:brand',
]);
$otherSlot = $registry->upsert([
    'site_id'=>1,
    'kind'=>'background',
    'content_sha256'=>str_repeat('3', 64),
    'filename'=>'Brand.png',
    'profile'=>'cisco-background:320x212x16',
    'bundle_key'=>'cisco-bg:320x212x16:brand',
]);
$now = 181;
$newSlot = $registry->upsert([
    'site_id'=>1,
    'kind'=>'background',
    'content_sha256'=>str_repeat('4', 64),
    'filename'=>'Brand.png',
    'profile'=>'cisco-background:800x480x24',
    'bundle_key'=>'cisco-bg:800x480x24:brand',
]);
$expect(
    $registry->markSlotStale(
        1,
        'background',
        'Brand.png',
        'cisco-background:800x480x24',
        'cisco-bg:800x480x24:brand',
        $newSlot['asset_key']
    ) === 1,
    'Replacing one logical asset slot must retire its previous content'
);
$expect(
    ($registry->get(1, $oldSlot['asset_key'])['state'] ?? '') === 'stale'
    && ($registry->get(1, $newSlot['asset_key'])['state'] ?? '') === 'current'
    && ($registry->get(1, $otherSlot['asset_key'])['state'] ?? '') === 'current',
    'Slot retirement must preserve the replacement and equal filenames in other profiles'
);
$expect(
    $registry->markSlotStale(
        1,
        'background',
        'Brand.png',
        'cisco-background:800x480x24',
        'cisco-bg:800x480x24:brand'
    ) === 1
    && ($registry->get(1, $newSlot['asset_key'])['state'] ?? '') === 'stale'
    && ($registry->get(1, $otherSlot['asset_key'])['state'] ?? '') === 'current',
    'A successful delete must retire only the deleted logical asset slot'
);

$countBeforeRejects = (int)$db->query('SELECT COUNT(*) FROM ucmp_assets')->fetchColumn();
$secret = str_repeat('b', 32);
$rejects(static fn() => $registry->upsert([
    'site_id'=>1, 'kind'=>'firmware', 'content_sha256'=>str_repeat('f', 64),
    'filename'=>'new.loads', 'password'=>$secret,
]), 'Secret-bearing fields must be rejected');
$rejects(static fn() => $registry->upsert([
    'site_id'=>1, 'kind'=>'background', 'content_sha256'=>str_repeat('f', 64),
    'filename'=>'../Company.png',
]), 'Relative paths must not be accepted as asset filenames');
$rejects(static fn() => $registry->upsert([
    'site_id'=>1, 'kind'=>'phonebook', 'content_sha256'=>str_repeat('f', 64),
    'filename'=>'https://pbx.example/company.xml',
]), 'URLs must not be accepted as asset filenames');
$rejects(static fn() => $registry->upsert([
    'site_id'=>1, 'kind'=>'certificate', 'content_sha256'=>str_repeat('f', 64),
    'filename'=>'device.pem',
]), 'Unmanaged asset kinds must be rejected');
$rejects(static fn() => $registry->upsert([
    'site_id'=>1, 'kind'=>'firmware', 'content_sha256'=>str_repeat('f', 64),
    'filename'=>'new.loads', 'companions'=>[[
        'kind'=>'locale', 'content_sha256'=>$localeHash, 'filename'=>'j9-tzdata.jar',
        'token'=>$secret,
    ]],
]), 'Companion metadata must reject secret-bearing fields');
$expect((int)$db->query('SELECT COUNT(*) FROM ucmp_assets')->fetchColumn() === $countBeforeRejects, 'Rejected input must not alter the registry');
$expect(!str_contains((string)$db->query('SELECT GROUP_CONCAT(companions_json) FROM ucmp_assets')->fetchColumn(), $secret), 'Registry storage must remain secret free');

$sameSecond=['site_id'=>9,'kind'=>'background','content_sha256'=>str_repeat('f',64),'filename'=>'missing.png','size_bytes'=>4];
$missing=$registry->upsert($sameSecond);
$seen=$registry->upsert(array_replace($sameSecond,['filename'=>'seen.png']));
$baseline=$registry->scanCandidates(9,['background']);
$registry->upsert(array_replace($sameSecond,['filename'=>'seen.png']));
$new=$registry->upsert(array_replace($sameSecond,['filename'=>'new.png']));
$expect($registry->retireUnobserved(9,$baseline)===1,'Complete scan must retire a missing asset registered in the same second');
$expect($registry->get(9,$missing['asset_key'])['state']==='stale'
    &&$registry->get(9,$seen['asset_key'])['state']==='current'
    &&$registry->get(9,$new['asset_key'])['state']==='current','Observed and concurrently inserted same-second assets must remain current');
echo "Managed asset registry fixtures passed\n";
