<?php
declare(strict_types=1);
interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
use FreePBX\modules\Ucmprovisioner;
use FreePBX\modules\Ucmprovisioner\LocalCredentialKey;
use FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService;
final class CredentialUpgradePdo extends PDO {
    public int $commitCount=0;
    public int $failCommit=0;
    public bool $uncertain=false;
    public function commit(): bool {
        if (++$this->commitCount===$this->failCommit) {
            if ($this->uncertain) { parent::commit(); }
            return false;
        }
        return parent::commit();
    }
}
final class CredentialUpgradeModule extends Ucmprovisioner {
    public function __construct(PDO $db,public string $testRoot) {
        parent::__construct(new class($db) { public function __construct(private PDO $db) {} public function Database(): PDO { return $this->db; } });
    }
    protected function credentialKeyStore(): LocalCredentialKey { return new LocalCredentialKey($this->getDb(),$this->testRoot); }
    protected function credentialArchives(): ConfigurationArchiveService { return new ConfigurationArchiveService($this->getDb(),$this,$this->testRoot); }
    public function directorySecretRoot(): string { return $this->testRoot . '/secrets/directory'; }
    public function siteConnection(int $siteId) { throw new LogicException('Credential installation must not contact a TFTP/PBX backend'); }
}
$expect=static function(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject=static function(callable $operation): void {
    try { $operation(); } catch (RuntimeException $expected) { return; }
    throw new LogicException('Unsafe upgrade was accepted');
};
$fixture=static function(bool $fresh=false): array {
    $db=new CredentialUpgradePdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE ucmp_settings(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,skey TEXT,sval TEXT)');
    $db->exec('CREATE TABLE ucmp_sites(id INTEGER PRIMARY KEY,site_key TEXT,is_local INTEGER,pbx_api_secret TEXT)');
    $db->exec('CREATE TABLE ucmp_directory_sources(id INTEGER PRIMARY KEY,source_key TEXT,enabled INTEGER,secret_ref TEXT,tls_mode TEXT,updated INTEGER,last_accepted_revision TEXT,endpoints_json TEXT)');
    foreach (['ucmp_directory_entries','ucmp_directory_conflicts','ucmp_phonebooks','ucmp_configuration_sync_jobs','ucmp_configuration_sync_items','ucmp_assets'] as $table) { $db->exec('CREATE TABLE '.$table.'(id INTEGER PRIMARY KEY)'); }
    $db->exec("INSERT INTO ucmp_sites VALUES(1,'fixture-controller',1,'')");
    $db->exec("INSERT INTO ucmp_directory_sources VALUES(7,'legacy-default',".($fresh?'0':'1').",'legacy-setting:ad_bind_password','ldap',20,'accepted-42','[{\"host\":\"dc.example.test\",\"port\":389}]')");
    $root=str_replace('\\','/',sys_get_temp_dir()).'/ucmp-credential-upgrade-'.bin2hex(random_bytes(8));
    $module=new CredentialUpgradeModule($db,$root);
    if (!$fresh) {
        $module->setSetting('crypt_key',str_repeat('a',64));
        $module->setSetting('ad_bind_user','reader@example.test');
        $module->setSetting('ad_bind_password',$module->enc('synthetic-reader-password'));
        $db->prepare('UPDATE ucmp_sites SET pbx_api_secret=?')->execute([$module->enc('synthetic-site-secret')]);
    }
    return [$db,$module,$root];
};
$cleanup=static function(string $root): void {
    if (!is_dir($root)) { return; }
    // Only the unique fixture tree allocated above is disposable.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
};
foreach (['upgrade','fresh','backup_failure','missing_site','invalid_key','missing_password','bad_cipher','key_commit','directory_commit','uncertain_directory_commit','conflicting_file'] as $case) {
    [$db,$module,$root]=$fixture($case==='fresh');
    $keyPath=$root.'/secrets/credentials.key';$secretPath=$root.'/secrets/directory/legacy-default.json';
    $keyRows=static fn(): int=>(int)$db->query("SELECT COUNT(*) FROM ucmp_settings WHERE skey='crypt_key'")->fetchColumn();
    $reference=static fn(): string=>(string)$db->query('SELECT secret_ref FROM ucmp_directory_sources WHERE id=7')->fetchColumn();
    $sealed=(string)$db->query('SELECT pbx_api_secret FROM ucmp_sites WHERE id=1')->fetchColumn();
    try {
        if ($case==='backup_failure') { mkdir($root,0700);file_put_contents($root.'/configuration-packages','fixture blocker'); }
        if ($case==='missing_site') { $db->exec("UPDATE ucmp_sites SET site_key='' WHERE id=1"); }
        if ($case==='invalid_key') { $db->exec("UPDATE ucmp_settings SET sval='invalid' WHERE skey='crypt_key'"); }
        if ($case==='missing_password') { $db->exec("DELETE FROM ucmp_settings WHERE skey='ad_bind_password'"); }
        if ($case==='bad_cipher') { $db->exec("UPDATE ucmp_settings SET sval='gcm:invalid' WHERE skey='ad_bind_password'"); }
        if ($case==='key_commit') { $db->failCommit=1; }
        if (in_array($case,['directory_commit','uncertain_directory_commit'],true)) { $db->failCommit=2;$db->uncertain=$case==='uncertain_directory_commit'; }
        if ($case==='conflicting_file') {
            mkdir(dirname($secretPath),0700,true);
            file_put_contents($secretPath,json_encode(['version'=>1,'source_key'=>'legacy-default','bind_user'=>'different-reader','password'=>'different-fixture-password']));chmod($secretPath,0600);
        }
        if (!in_array($case,['upgrade','fresh'],true)) {
            $reject(fn()=>$module->upgradeCredentialStorage());
            $expect($db->query('SELECT pbx_api_secret FROM ucmp_sites WHERE id=1')->fetchColumn()===$sealed,$case.': encrypted site bytes changed');
            if (in_array($case,['backup_failure','missing_site','invalid_key'],true)) {
                $expect($keyRows()===1&&!is_file($keyPath),$case.': key moved without readiness');
            }
            if ($case!=='uncertain_directory_commit') { $expect($reference()==='legacy-setting:ad_bind_password',$case.': legacy reference lost'); }
            if (in_array($case,['key_commit','directory_commit','uncertain_directory_commit'],true)) {
                $db->failCommit=0;
                $published=is_file($secretPath)?hash_file('sha256',$secretPath):null;
                $module->upgradeCredentialStorage();
                $expect($keyRows()===0&&$reference()==='file:legacy-default',$case.': retry did not complete');
                if ($published!==null) { $expect(hash_file('sha256',$secretPath)===$published,$case.': retry rewrote the retained credential'); }
                $expect($module->dec($sealed)==='synthetic-site-secret',$case.': retry lost decryption');
            }
            continue;
        }
        $result=$module->upgradeCredentialStorage();
        $beforeReadiness=$db->query('SELECT * FROM ucmp_settings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $expect(!empty($module->credentialStorageReadiness()['ok']),$case.': runtime credential readback failed');
        $expect($beforeReadiness===$db->query('SELECT * FROM ucmp_settings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Credential readiness mutated settings');
        $expect($result['ok']&&$keyRows()===0&&is_file($keyPath)&&$reference()==='file:legacy-default',$case.': automatic conversion failed');
        $expect($db->query('SELECT tls_mode FROM ucmp_directory_sources WHERE id=7')->fetchColumn()==='ldap','Upgrade changed the saved protocol');
        $expect($db->query('SELECT last_accepted_revision FROM ucmp_directory_sources WHERE id=7')->fetchColumn()==='accepted-42','Upgrade changed accepted directory history');
        $backup=$root.'/configuration-packages/'.$result['backup'];
        $archives=new ConfigurationArchiveService($db,$module,$root);
        $verified=$archives->verifyRecoveryBackup($backup,'fixture-controller');
        $expect(isset($verified['database_members']['database/directory.json']),'Backup lacks original directory reference');
        if ($case==='fresh') { $expect(!is_file($secretPath),'Fresh installation fabricated an AD password'); }
        else {
            $secret=json_decode((string)file_get_contents($secretPath),true,16,JSON_THROW_ON_ERROR);
            $expect($secret['password']==='synthetic-reader-password'&&$module->dec($sealed)==='synthetic-site-secret','Upgrade lost existing secrets');
            $expect($db->query("SELECT COUNT(*) FROM ucmp_settings WHERE skey='ad_bind_password'")->fetchColumn()===0,'Old reader password remains active');
        }
        $before=glob($root.'/configuration-packages/*.zip');$key=(string)file_get_contents($keyPath);
        $module->upgradeCredentialStorage();
        $expect(glob($root.'/configuration-packages/*.zip')===$before&&file_get_contents($keyPath)===$key,'Repeated upgrade generated another backup or rotated the key');
        if ($case==='upgrade') {
            unlink($secretPath);
            $expect(empty($module->credentialStorageReadiness()['ok']),'Missing active reader credential was reported ready');
            $db->exec("UPDATE ucmp_directory_sources SET enabled=0");
            $db->exec("UPDATE ucmp_sites SET pbx_api_secret='gcm:invalid'");
            $expect(empty($module->credentialStorageReadiness()['ok']),'Undecryptable existing ciphertext was reported ready');
        }
    } finally { $cleanup($root); }
}
$install=(string)file_get_contents(dirname(__DIR__).'/install.php');
$expect(strpos($install,'->upgradeCredentialStorage()')>strpos($install,'foreach ($localDefaults'),'Credential conversion must run after the local site/defaults exist');
$expect(!str_contains((string)file_get_contents(dirname(__DIR__).'/views/settings.php'),'value="migratecredentialkey"'),'Manual key migration form returned');
echo "Automatic credential install, local backup, unchanged ciphertext, failure and interruption/retry checks passed\n";
