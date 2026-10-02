<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\LocalCredentialKey;
use FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService;
$expect=static function(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$reject=static function(callable $operation): void {
    try { $operation(); } catch (RuntimeException $expected) { return; }
    throw new LogicException('Unsafe credential operation was not rejected');
};
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE ucmp_settings(id INTEGER PRIMARY KEY,site_id INTEGER,skey TEXT,sval TEXT)');
$db->exec('CREATE TABLE ucmp_sites(id INTEGER PRIMARY KEY,site_key TEXT,pbx_api_secret TEXT)');
$db->exec('CREATE TABLE ucmp_configuration_sync_jobs(id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE ucmp_configuration_sync_items(id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE ucmp_assets(id INTEGER PRIMARY KEY,site_id INTEGER,kind TEXT)');
$key=str_repeat('a',64); $plain='fixture-credential-only'; $iv=str_repeat('i',12); $tag='';
$cipher=openssl_encrypt($plain,'aes-256-gcm',hash('sha256',$key,true),OPENSSL_RAW_DATA,$iv,$tag);
$sealed='gcm:' . base64_encode($iv . $tag . $cipher);
$db->prepare("INSERT INTO ucmp_settings VALUES(1,NULL,'crypt_key',?)")->execute([$key]);
$db->prepare("INSERT INTO ucmp_sites VALUES(1,'fixture-site',?)")->execute([$sealed]);
$root=str_replace('\\','/',sys_get_temp_dir()) . '/ucmp-key-fixture-' . bin2hex(random_bytes(8));
$store=new LocalCredentialKey($db,$root);
$before=$db->query('SELECT * FROM ucmp_settings')->fetchAll(PDO::FETCH_ASSOC);
$expect($store->read()===$key && !file_exists($root),'Ordinary reads created host files');
$expect($before===$db->query('SELECT * FROM ucmp_settings')->fetchAll(PDO::FETCH_ASSOC),'Ordinary reads changed legacy database key');
$reject(fn()=>$store->migrate($root . '/missing.zip','fixture-site',static fn()=>['ok'=>true]));
$expect(!file_exists($root),'Unverified backup allowed private key creation');
$ucm=new class { public function withConfigurationSyncLock(callable $operation): mixed { return $operation(); } public function localSiteId(): int { return 1; } public function getSite(int $id): array { return ['site_key'=>'fixture-site','id'=>1]; } };
$archives=new ConfigurationArchiveService($db,$ucm,$root);
$configuration=$archives->export(['sites','settings'],'fixture');
$reject(fn()=>$store->migrate($configuration['path'],'fixture-site',[$archives,'verifyRecoveryBackup']));
$expect(!is_dir($root . '/secrets'),'Secret-free configuration package was accepted as key recovery backup');
$backup=$archives->export(['sites','settings'],'fixture','recovery');
$result=$store->migrate($backup['path'],'fixture-site',[$archives,'verifyRecoveryBackup']);
$expect($result['ok'] && $store->read()===$key && (int)$db->query("SELECT COUNT(*) FROM ucmp_settings WHERE skey='crypt_key'")->fetchColumn()===0,'Explicit migration failed');
$expect(is_file($backup['path']) && $db->query('SELECT pbx_api_secret FROM ucmp_sites')->fetchColumn()===$sealed,'Migration lost backup or changed encrypted credential bytes');
$decoded=openssl_decrypt($cipher,'aes-256-gcm',hash('sha256',$store->read(),true),OPENSSL_RAW_DATA,$iv,$tag);
$expect($decoded===$plain,'Credentials cannot be decrypted after key migration');
$repeat=$store->migrate($backup['path'],'fixture-site',[$archives,'verifyRecoveryBackup']);
$expect($repeat['ok'],'Explicit repeated migration was not idempotent');
rename($root . '/secrets/credentials.key',$root . '/secrets/credentials.retained');
$reject(fn()=>$store->read());
rename($root . '/secrets/credentials.retained',$root . '/secrets/credentials.key');
$restored=$archives->import($backup['path'],['sites','settings'],'recovery');
$expect($restored['ok'] && $store->read()===$key && (int)$db->query("SELECT COUNT(*) FROM ucmp_settings WHERE skey='crypt_key'")->fetchColumn()===1,'Matching database/key rollback failed');
$db->exec("INSERT INTO ucmp_settings VALUES(2,1,'crypt_key','different-fixture-key')");
$reject(fn()=>$store->read());
// Cleanup is limited to this newly allocated test tree, never a module runtime path.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
    if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
}
rmdir($root);
echo "Read-only key lookup, backup-gated migration, unchanged ciphertext and paired rollback fixtures passed\n";
