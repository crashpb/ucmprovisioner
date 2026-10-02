<?php
require_once __DIR__ . '/../lib/ModelCatalog.php';
require_once __DIR__ . '/../lib/FirmwareService.php';
require_once __DIR__ . '/../lib/AssetCatalog.php';
require_once __DIR__ . '/../lib/AssetFileInspector.php';
require_once __DIR__ . '/../lib/ManagedAssetRegistry.php';
require_once __DIR__ . '/../lib/ManagedAssetScanJob.php';
require_once __DIR__ . '/../lib/AssetLifecycleService.php';
require_once __DIR__ . '/../lib/TftpStore.php';

use FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$method = new ReflectionMethod(TftpStore::class, 'iniBytes');
$method->setAccessible(true);
foreach (['8M' => 8 * 1024 * 1024, '256K' => 256 * 1024, '1G' => 1024 * 1024 * 1024, '123' => 123] as $input => $expected) {
    if ($method->invoke(null, $input) !== $expected) {
        throw new RuntimeException("Failed to parse PHP size $input");
    }
}

$ucmStub = new class {
    public int $attempts = 0;
    public function siteConnection(int $siteId): never
    {
        $this->attempts++;
        throw new RuntimeException('Unsafe filename reached the site connection');
    }
};
$store = new TftpStore($ucmStub);
foreach (['../SEP001122334455.cnf.xml', 'nested/config.cfg', '..', 'bad name.cfg'] as $filename) {
    $result = $store->putConfig(1, $filename, 'test');
    if (!empty($result['ok'])) {
        throw new RuntimeException("Unsafe generated filename was accepted: $filename");
    }
}
if ($ucmStub->attempts !== 0) {
    throw new RuntimeException('Unsafe generated filenames must fail before opening SFTP');
}

final class TftpBoundedReadStub
{
    public int $reads = 0;
    public int $requestedLength = 0;

    public function __construct(private int $declaredSize, private string $content) {}

    public function stat(string $path): array
    {
        return ['type'=>1, 'size'=>$this->declaredSize];
    }

    public function get(string $path, $local = false, int $offset = 0, int $length = -1): string
    {
        $this->reads++;
        $this->requestedLength = $length;
        return $length < 0
            ? substr($this->content, $offset)
            : substr($this->content, $offset, $length);
    }
}

$boundedRead = new ReflectionMethod(TftpStore::class, 'readBounded');
$boundedRead->setAccessible(true);
$oversized = new TftpBoundedReadStub(2 * 1024 * 1024 + 1, 'not transferred');
$oversizedResult = $boundedRead->invoke($store, $oversized, '/tftpboot/large.xml', 2 * 1024 * 1024, 'text editor');
if (!empty($oversizedResult['ok']) || $oversized->reads !== 0) {
    throw new RuntimeException('An oversized editor file must be rejected before transfer');
}

$race = new TftpBoundedReadStub(4, str_repeat('x', 33));
$raceResult = $boundedRead->invoke($store, $race, '/tftpboot/growing.xml', 16, 'text editor');
if (!empty($raceResult['ok']) || $race->requestedLength !== 17) {
    throw new RuntimeException('The SFTP transfer itself must be capped and reject a file that grows after stat');
}

$small = new TftpBoundedReadStub(4, 'test');
$smallResult = $boundedRead->invoke($store, $small, '/tftpboot/test.xml', 16, 'text editor');
if (empty($smallResult['ok']) || ($smallResult['content'] ?? '') !== 'test') {
    throw new RuntimeException('A bounded normal TFTP editor read must still succeed');
}

final class TftpScanSftpStub
{
    public function __construct(private bool $failTransfer = false) {}

    public function rawlist(string $path): array
    {
        return [
            '.'=>['type'=>2],
            '..'=>['type'=>2],
            'New.raw'=>['type'=>1, 'size'=>4],
        ];
    }

    public function get(string $remote, $local = false, int $offset = 0, int $length = -1)
    {
        if ($this->failTransfer) { return false; }
        if (is_string($local)) {
            return file_put_contents($local, 'tone') === 4;
        }
        return 'tone';
    }
}

final class TftpScanConnectionStub
{
    public function __construct(private TftpScanSftpStub $sftp) {}
    public function dockerSftp(): TftpScanSftpStub { return $this->sftp; }
    public function tftpRoot(): string { return '/tftpboot'; }
}

final class TftpScanUcmStub
{
    public function __construct(private PDO $db, private TftpScanSftpStub $sftp) {}
    public function getDb(): PDO { return $this->db; }
    public function siteConnection(int $siteId): TftpScanConnectionStub
    {
        return new TftpScanConnectionStub($this->sftp);
    }
}

$scanDb = new PDO('sqlite::memory:');
$scanDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ManagedAssetRegistry::ensureSchema($scanDb);
$oldRegistry = new ManagedAssetRegistry($scanDb, static fn(): int => 1);
$oldTone = $oldRegistry->upsert([
    'site_id'=>1,
    'kind'=>'ringtone',
    'content_sha256'=>str_repeat('9', 64),
    'filename'=>'Old.raw',
    'size_bytes'=>3,
    'content_type'=>'audio/basic',
    'vendor'=>'Cisco',
    'family'=>'cisco_xml',
    'profile'=>'cisco-ringtone',
    'bundle_key'=>'cisco-ringtones-standard',
    'bundle_role'=>'tone',
]);
$rescan = new ReflectionMethod(TftpStore::class, 'rescanManagedAssets');
$rescan->setAccessible(true);
$partialFailed = false;
try {
    $rescan->invoke(new TftpStore(new TftpScanUcmStub($scanDb, new TftpScanSftpStub(true))), 1);
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'Cannot stream New.raw')) { throw $e; }
    $partialFailed = true;
}
if (!$partialFailed) {
    throw new RuntimeException('A failed managed asset transfer must fail the rescan');
}
$checkRegistry = new ManagedAssetRegistry($scanDb);
if (($checkRegistry->get(1, $oldTone['asset_key'])['state'] ?? '') !== 'current') {
    throw new RuntimeException('A partial managed asset rescan must not stale missing rows');
}
$complete = $rescan->invoke(new TftpStore(new TftpScanUcmStub($scanDb, new TftpScanSftpStub(false))), 1);
if (empty($complete['complete']) || ($complete['files'] ?? 0) !== 1) {
    throw new RuntimeException('A bounded complete managed asset rescan must report its completion');
}
if (!empty($complete['ok']) || ($complete['status'] ?? '')!=='partial'
    || (empty($complete['firmware_reconciliation']['issues']) && empty($complete['timezone_reconciliation']['issues']))) {
    throw new RuntimeException('Unavailable fixture catalogs must report partial reconciliation while retaining completed file scanning');
}
if (($checkRegistry->get(1, $oldTone['asset_key'])['state'] ?? '') !== 'stale') {
    throw new RuntimeException('Only a complete managed asset rescan may stale a missing row');
}
$inventoryStore = new TftpStore(new TftpScanUcmStub($scanDb, new TftpScanSftpStub(false)));
$currentInventory = $inventoryStore->handle([
    'op'=>'managed_asset_inventory',
    'site_id'=>1,
]);
$historyInventory = $inventoryStore->handle([
    'op'=>'managed_asset_inventory',
    'site_id'=>1,
    'state'=>'all',
]);
if (count((array)($currentInventory['catalog_assets'] ?? [])) !== 1
    || count((array)($historyInventory['catalog_assets'] ?? [])) !== 2) {
    throw new RuntimeException('Managed inventory must default to current assets and expose retired history only when requested');
}

final class TftpEmptyImageListStub
{
    /** @var array<string,string> */
    public array $files = ['/tftpboot/Desktops/95x34x1/List.xml'=>'old'];
    public function nlist(string $path): array { return ['.','..','List.xml']; }
    public function is_dir(string $path): bool { return false; }
    public function file_exists(string $path): bool { return array_key_exists($path, $this->files); }
    public function delete(string $path): bool { unset($this->files[$path]); return true; }
}

$listDb = new PDO('sqlite::memory:');
$listDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ManagedAssetRegistry::ensureSchema($listDb);
$listUcm = new class($listDb) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
};
$listStore = new TftpStore($listUcm);
$metadataMethod = new ReflectionMethod(TftpStore::class, 'managedAssetMetadata');
$metadataMethod->setAccessible(true);
$listMetadata = $metadataMethod->invoke(null, 'Desktops/95x34x1/List.xml');
$listAsset = (new ManagedAssetRegistry($listDb))->upsert([
    'site_id'=>1,
    'content_sha256'=>str_repeat('a', 64),
    'size_bytes'=>3,
] + $listMetadata);
$listSftp = new TftpEmptyImageListStub();
$regenerateList = new ReflectionMethod(TftpStore::class, 'regenerateImageList');
$regenerateList->setAccessible(true);
$emptyList = $regenerateList->invoke(
    $listStore,
    1,
    $listSftp,
    '/tftpboot/Desktops/95x34x1',
    'Desktops/95x34x1'
);
if (empty($emptyList['ok'])
    || ($emptyList['count'] ?? -1) !== 0
    || isset($listSftp->files['/tftpboot/Desktops/95x34x1/List.xml'])
    || ((new ManagedAssetRegistry($listDb))->get(1, $listAsset['asset_key'])['state'] ?? '') !== 'stale') {
    throw new RuntimeException('A wallpaper profile with no complete image pair must remove List.xml and retire its asset row');
}

$firmwareDb = new PDO('sqlite::memory:');
$firmwareDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$firmwareDb->exec('CREATE TABLE ucmp_firmware_bundles (
    id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, vendor TEXT NOT NULL,
    profile TEXT NOT NULL, load_name TEXT NOT NULL, files_json TEXT NOT NULL,
    missing_json TEXT NOT NULL, status TEXT NOT NULL, scanned_at INTEGER NOT NULL
)');
$firmwareDb->exec('CREATE TABLE ucmp_models (id INTEGER PRIMARY KEY, vendor TEXT, model TEXT)');
$firmwareDb->exec("INSERT INTO ucmp_firmware_bundles VALUES
    (1,1,'Cisco','cisco:sip41','SIP41.test','[]','[]','complete',1)");
$firmwareUcm = new class($firmwareDb) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
};
$unfilteredFirmware = (new TftpStore($firmwareUcm))->handle([
    'op'=>'firmware_inventory', 'site_id'=>1, 'model_id'=>0, 'profile'=>'',
]);
if (count((array)($unfilteredFirmware['bundles'] ?? [])) !== 1) {
    throw new RuntimeException('A federated inventory request with empty model/profile filters must return the full firmware inventory');
}

echo "TftpStore tests passed\n";
