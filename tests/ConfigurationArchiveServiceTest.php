<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ConfigurationArchiveService.php';

use FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService;

if (!class_exists(ZipArchive::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Configuration archive fixture skipped (ZipArchive/SQLite unavailable)\n";
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY, site_id INTEGER, skey TEXT, sval TEXT)');
$db->exec('CREATE TABLE ucmp_configuration_sync_jobs (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE ucmp_configuration_sync_items (id INTEGER PRIMARY KEY, object_key TEXT)');
$db->exec('CREATE TABLE ucmp_assets (id INTEGER PRIMARY KEY, site_id INTEGER, kind TEXT, filename TEXT, relative_path TEXT)');
$db->exec("INSERT INTO ucmp_settings VALUES (7,1,'cisco_cnf_sip_port','5070')");

$root = sys_get_temp_dir() . '/ucmp-archive-test-' . bin2hex(random_bytes(5));
$ucm = new class {
    public function withConfigurationSyncLock(callable $operation): mixed { return $operation(); }
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['id'=>$id,'site_key'=>'test','name'=>'Test PBX']; }
};
$service = new ConfigurationArchiveService($db, $ucm, $root);
$transient = new ReflectionMethod(ConfigurationArchiveService::class, 'isTransientTftpPath');
$transient->setAccessible(true);
foreach ([
    '.ucmprovisioner/current.json',
    '.ucmprovisioner/transactions/abc/manifest.json',
    '.ucmprovisioner/quarantine/old/file.xml',
    '.ucmprovisioner/wallpaper-sets-retired/old/manifest.json',
    '.ucmprovisioner/timezone-stage-abc/j9-tzdata.jar',
] as $runtimePath) {
    if (!$transient->invoke($service, $runtimePath)) {
        throw new RuntimeException("Transient TFTP bookkeeping was exportable: $runtimePath");
    }
}
foreach ([
    '.ucmprovisioner/wallpaper-sets/RG_Base/manifest.json',
    '.ucmprovisioner/timezone-bundles/' . str_repeat('a', 64) . '/tzupdater.jar',
    'SEP001122334455.cnf.xml',
] as $managedPath) {
    if ($transient->invoke($service, $managedPath)) {
        throw new RuntimeException("Managed configuration asset was excluded: $managedPath");
    }
}
$export = $service->export(['settings'], '0.9.0');
if (!is_file($export['path']) || ($export['manifest']['scopes'] ?? []) !== ['settings']) {
    throw new RuntimeException('Settings-only configuration export was not created');
}

$db->exec("UPDATE ucmp_settings SET sval='9999' WHERE id=7");
$result = $service->import($export['path'], ['settings']);
$restored = (string)$db->query('SELECT sval FROM ucmp_settings WHERE id=7')->fetchColumn();
if (empty($result['ok']) || $restored !== '5070' || (int)$result['rows'] !== 1) {
    throw new RuntimeException('Configuration import did not restore the matching stored row');
}

$snapshotSource=$root.'/source.bin';file_put_contents($snapshotSource,'captured-bytes');
$snapshotZip=$root.'/snapshot.zip';$archive=new ZipArchive();$archive->open($snapshotZip,ZipArchive::CREATE);
$members=[];$counters=['files'=>0,'bytes'=>0];$temporaries=[];
$addFile=new ReflectionMethod(ConfigurationArchiveService::class,'addFile');
$addFile->invokeArgs($service,[$archive,$snapshotSource,'private','patches','asterisk-bundles/source.bin',&$members,&$counters,&$temporaries]);
file_put_contents($snapshotSource,'changed-after-manifest');
$archive->close();$archive->open($snapshotZip);
$captured=$archive->getFromName($members[0]['archive_path']);$archive->close();
if ($captured!=='captured-bytes' || hash('sha256',$captured)!==$members[0]['sha256']) {
    throw new RuntimeException('An export source change after manifest hashing corrupted the finalized archive');
}
foreach ($temporaries as $temporary) { unlink($temporary); }
unlink($snapshotSource);unlink($snapshotZip);
@unlink($export['path']);
@rmdir($root . '/configuration-packages');
@rmdir($root);
echo "Configuration archive scope, ZIP and primary-key merge assertions passed\n";
