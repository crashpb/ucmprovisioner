<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/CiscoTimezoneService.php';
require_once dirname(__DIR__) . '/lib/FirmwareService.php';
require_once dirname(__DIR__) . '/lib/AssetCatalog.php';
require_once dirname(__DIR__) . '/lib/AssetFileInspector.php';
require_once dirname(__DIR__) . '/lib/ManagedAssetRegistry.php';
require_once dirname(__DIR__) . '/lib/AssetLifecycleService.php';
require_once dirname(__DIR__) . '/lib/TftpStore.php';
require_once dirname(__DIR__) . '/lib/DirectoryService.php';

use FreePBX\modules\Ucmprovisioner\DirectoryService;
use FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ManagedAssetRegistry::ensureSchema($db);
$ucm = new class($db) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 7; }
};

$directory = new DirectoryService($ucm);
$recordPhonebook = new ReflectionMethod(DirectoryService::class, 'registerPublishedPhonebook');
$recordPhonebook->setAccessible(true);
$payloadOne = '{"generated":100,"phonebooks":[{"book_key":"company"}]}';
$first = $recordPhonebook->invoke($directory, $payloadOne, 4);
$expect(
    ($first['kind'] ?? '') === 'phonebook'
    && ($first['filename'] ?? '') === 'directory.json'
    && ($first['content_sha256'] ?? '') === hash('sha256', $payloadOne)
    && (int)($first['size_bytes'] ?? -1) === strlen($payloadOne)
    && ($first['relative_path'] ?? '') === 'directory/directory.json'
    && ($first['validation_status'] ?? '') === 'verified'
    && ($first['provenance'] ?? '') === 'managed_write'
    && ($first['signature_status'] ?? '') === 'not_applicable',
    'Published phonebook registration must hash and classify the exact managed cache bytes'
);

$registry = new ManagedAssetRegistry($db);
$independent = $registry->upsert([
    'site_id'=>7,
    'kind'=>'phonebook',
    'content_sha256'=>str_repeat('9', 64),
    'filename'=>'independent-directory.json',
    'bundle_key'=>'independent-phonebook',
]);
$payloadTwo = '{"generated":101,"phonebooks":[{"book_key":"company"},{"book_key":"support"}]}';
$second = $recordPhonebook->invoke($directory, $payloadTwo, 4);
$phonebooks = $registry->list(7, ['kind'=>'phonebook', 'state'=>'all']);
$expect(count($phonebooks) === 2, 'Superseded generated phonebook hashes must be purged instead of retained as stale history');
$expect(
    count(array_filter($phonebooks, static fn(array $row): bool => $row['state'] === 'current')) === 2
    && $registry->get(7, (string)$first['asset_key']) === null
    && ($registry->get(7, (string)$second['asset_key'])['state'] ?? '') === 'current',
    'Only the latest complete generated phonebook cache may remain in inventory'
);
$expect(
    ($registry->get(7, (string)$independent['asset_key'])['state'] ?? '') === 'current',
    'Publishing the managed cache must not stale a different phonebook bundle'
);
$publishedAsset = null;
foreach ((new \FreePBX\modules\Ucmprovisioner\AssetLifecycleService($db))->inventory(7, ['kind'=>'phonebook']) as $candidate) {
    if (($candidate['bundle_key'] ?? '') === 'phonebook-cache') { $publishedAsset = $candidate; break; }
}
$expect(
    is_array($publishedAsset)
    && ($publishedAsset['status'] ?? '') === 'verified'
    && ($publishedAsset['provenance'] ?? '') === 'managed_write'
    && (int)($publishedAsset['usage_count'] ?? 0) === 1
    && (($publishedAsset['usage'][0]['label'] ?? '') === 'Published phone directory'),
    'The Asset Index must show the generated cache as verified, module-written and used by the directory endpoint'
);

$classify = new ReflectionMethod(TftpStore::class, 'managedAssetMetadata');
$classify->setAccessible(true);
$background = $classify->invoke(null, 'Desktops/320x212x16/Company.png');
$thumbnail = $classify->invoke(null, 'Desktops/320x212x16/Company-tn.png');
$imageList = $classify->invoke(null, 'Desktops/320x212x16/List.xml');
$ringtone = $classify->invoke(null, 'Classic.raw');
$ringList = $classify->invoke(null, 'Ringlist-wb.xml');
$locale = $classify->invoke(null, 'j9-tzdata.jar');
$timezoneAliasKey = str_repeat('a', 64);
$timezoneAlias = $classify->invoke(null, 'ucmp-tz-j9-' . $timezoneAliasKey . '.jar');
$localeJar = $classify->invoke(null, 'English_United_States/mk-sip.jar');
$localeUnique = $classify->invoke(null, 'English_United_States/tc-sip.jar');
$localeTones = $classify->invoke(null, 'g3-tones.xml');
$firmware = $classify->invoke(null, 'Firmware/Cisco/sip88xx.14-2-1.loads');
$expect(
    ($background['kind'] ?? '') === 'background'
    && ($background['bundle_role'] ?? '') === 'full'
    && ($thumbnail['bundle_role'] ?? '') === 'thumbnail'
    && ($background['bundle_key'] ?? '') === ($thumbnail['bundle_key'] ?? ''),
    'Cisco full and thumbnail payloads must share deterministic bundle metadata'
);
$expect(($imageList['bundle_role'] ?? '') === 'catalog', 'Generated Cisco List.xml must be recognized as a background catalog');
$expect(
    ($ringtone['kind'] ?? '') === 'ringtone'
    && ($ringList['bundle_key'] ?? '') === 'cisco-ringtones-wideband',
    'Cisco ringtone payloads and wideband catalogs must be classified'
);
$expect(
    ($locale['kind'] ?? '') === 'locale'
    && ($locale['profile'] ?? '') === 'cisco-78xx-88xx'
    && ($timezoneAlias['kind'] ?? '') === 'locale'
    && ($timezoneAlias['profile'] ?? '') === 'cisco-78xx-88xx'
    && ($timezoneAlias['metadata']['timezone_asset_key'] ?? '') === $timezoneAliasKey,
    'Canonical and hash-published Cisco timezone payloads must share their runtime profile'
);
$expect(
    ($localeJar['kind'] ?? '') === 'locale'
    && ($localeJar['profile'] ?? '') === 'cisco:shared-locale'
    && str_contains((string)($localeJar['metadata']['locale_profiles'] ?? ''), 'cisco:sip41-locale')
    && str_contains((string)($localeJar['metadata']['locale_profiles'] ?? ''), 'cisco:sip42-locale')
    && ($localeUnique['profile'] ?? '') === 'cisco:sip11-locale'
    && ($localeTones['bundle_role'] ?? '') === 'network-tones',
    'Unique locale companions must retain their exact profile while shared filenames expose every compatible profile'
);
$expect(($firmware['kind'] ?? '') === 'firmware', 'Recognized firmware uploads must enter the managed registry');
$expect(
    $classify->invoke(null, 'SEP001122334455.cnf.xml') === null
    && $classify->invoke(null, '../j9-tzdata.jar') === null,
    'Phone configs and unsafe relative paths must not be classified as managed assets'
);
foreach ([$background, $thumbnail, $imageList, $ringtone, $ringList, $locale, $timezoneAlias, $localeJar, $localeUnique, $localeTones, $firmware] as $metadata) {
    $expect(!array_key_exists('path', $metadata), 'TFTP classification must never project a filesystem path');
}

$store = new TftpStore($ucm);
$recordPayload = new ReflectionMethod(TftpStore::class, 'registerManagedPayload');
$recordPayload->setAccessible(true);
$bytes = "\x89PNG\r\n\x1a\nmanaged-png-fixture";
$recorded = $recordPayload->invoke(
    $store,
    7,
    'Desktops/320x212x16/Managed.png',
    hash('sha256', $bytes),
    strlen($bytes)
);
$expect(
    ($recorded['asset']['kind'] ?? '') === 'background'
    && ($recorded['asset']['content_sha256'] ?? '') === hash('sha256', $bytes)
    && ($recorded['asset']['filename'] ?? '') === 'Managed.png',
    'TFTP integration must persist the actual caller-calculated content hash'
);
$expect(
    $recordPayload->invoke($store, 7, 'SEP001122334455.cnf.xml', hash('sha256', 'config'), 6) === [],
    'Unmanaged TFTP writes must not create registry rows'
);

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/TftpStore.php');
$expect(
    str_contains($source, "hash_file('sha256', \$uploadPath)")
    && str_contains($source, "hash_file('sha256', \$tmp)"),
    'Upload and cross-site copy paths must stream SHA-256 from local files'
);
$storedJson = (string)$db->query('SELECT GROUP_CONCAT(companions_json) FROM ucmp_assets')->fetchColumn();
$expect(!str_contains($storedJson, 'Desktops/'), 'Registry JSON must not contain TFTP paths');

echo "Managed asset integration fixtures passed\n";
