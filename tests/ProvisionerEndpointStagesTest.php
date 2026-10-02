<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner {
    // Only the resolver and privileged glue are substituted; the orchestrator,
    // generator, endpoint binder, SQLite ledger and SFTP transaction are real.
    class TemplateEngine {
        public static string $secret = 'fixture-only';
        public static string $driver = 'pjsip';
        public function __construct(private $ucm) {}
        public function resolvePhone(array $phone, bool $full = true): array {
            return ['phone'=>$phone, 'model'=>['id'=>1, 'vendor'=>'Yealink', 'model'=>'T54W', 'family'=>'yealink'],
                'settings'=>['pbx_addr'=>'192.0.2.1', 'driver'=>self::$driver, 'transport'=>'udp', 'sip_port'=>5160,
                    '_endpoint_profile'=>['driver'=>self::$driver, 'transport'=>'udp', 'port'=>5160]],
                'lines'=>[
                    ['button'=>1, 'featureId'=>9, 'lineIndex'=>1, 'name'=>'700', 'authName'=>'700', 'secret'=>self::$secret,'label'=>$phone['label']],
                    ['button'=>2, 'featureId'=>9, 'lineIndex'=>2, 'name'=>'701', 'authName'=>'701', 'secret'=>self::$secret],
                ]];
        }
    }
    class AsteriskGlue {
        public static int $restored = 0;
        public static $duringApply = null;
        public function __construct($ucm) {}
        public function plan(array $phones): array { return ['requires_patch'=>false]; }
        public function snapshot(): array { return []; }
        public function apply(array $phones): array { if (self::$duringApply) { (self::$duringApply)(); } return ['ok'=>true]; }
        public function restoreSnapshot(array $snapshot, bool $reload): array { self::$restored++; return ['ok'=>true]; }
    }
    class PhoneCommand {
        public static int $sent = 0;
        public function __construct($ucm) {}
        public function send(int $id, string $command): array { self::$sent++; return ['ok'=>false, 'message'=>'fixture command failed']; }
    }
}

namespace {
    require_once __DIR__ . '/TftpGenerationTransactionTest.php';
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/ConfigurationWriteLockTest.php';

    final class StageCore {
        public array $devices = [];
        public array $attempts = [];
        public ?string $fail = null;
        public function getDevice(string $ext): array { return $this->devices[$ext] ?? []; }
        public function delDevice(string $ext, bool $edit): bool { unset($this->devices[$ext]); return true; }
        public function addDevice(string $ext, string $tech, array $settings, bool $edit): bool {
            $this->attempts[] = $ext;
            if ($this->fail === $ext) { return false; }
            $this->devices[$ext] = ['id'=>$ext, 'tech'=>$tech];
            foreach ($settings as $key=>$value) { $this->devices[$ext][$key] = $value['value']; }
            return true;
        }
    }
    final class StageFramework {
        public bool $ok = true;
        public function doReload(): array { return ['status'=>$this->ok, 'message'=>'fixture reload']; }
    }
    final class FreePBX {
        public static StageCore $core;
        public static StageFramework $framework;
        public static function Core(): StageCore { return self::$core; }
        public static function Framework(): StageFramework { return self::$framework; }
    }
    final class StageMetadataPdo extends PDO {
        public string $fault = '';
        public function commit(): bool {
            if ($this->fault === 'committed_throw' || $this->fault === 'committed_false') {
                parent::commit();
                if ($this->fault === 'committed_throw') { throw new RuntimeException('fixture lost commit response'); }
                return false;
            }
            if (str_starts_with($this->fault, 'rollback_')) { return false; }
            return parent::commit();
        }
        public function rollBack(): bool {
            if ($this->fault === 'rollback_false') { return false; }
            if ($this->fault === 'rollback_throw') { throw new RuntimeException('fixture rollback response lost'); }
            return parent::rollBack();
        }
    }
    final class StageModule {
        public ConfigurationLockModule $configurationLock;
        public PDO $db;
        public GenerationSftpStub $sftp;
        public bool $listener = true;
        public array $extraPhones = [];
        public function __construct() {
            $this->configurationLock = new ConfigurationLockModule();
            $this->sftp = new GenerationSftpStub();
            $this->db = new StageMetadataPdo('sqlite::memory:');
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->exec("CREATE TABLE ucmp_phones (id INTEGER PRIMARY KEY,updated INTEGER DEFAULT 0,last_applied INTEGER,config_filename TEXT,config_mode TEXT,config_override_sha256 TEXT,config_overridden_at INTEGER,applied_driver TEXT,applied_transport TEXT,applied_sip_port INTEGER)");
            $this->db->exec("ALTER TABLE ucmp_phones ADD COLUMN label TEXT DEFAULT 'Fixture'");
            $this->db->exec('INSERT INTO ucmp_phones(id) VALUES(1)');
        }
        public function getDb(): PDO { return $this->db; }
        public function alignedSipSecret(string $extension,string $secret): string { return \FreePBX\modules\Ucmprovisioner\SipSecretPolicy::aligned($extension,$secret,str_repeat('fixture-key',4)); }
        public function withConfigurationSyncLock(callable $operation): mixed { return $this->configurationLock->withConfigurationSyncLock($operation); }
        public function localSiteId(): int { return 1; }
        public function getPhones(): array { return [array_replace([
            'id'=>1, 'site_id'=>1, 'extension'=>'700', 'model_id'=>1, 'mac'=>'001122334455', 'label'=>'Fixture',
            'last_applied'=>0, 'config_mode'=>'managed',
        ], $this->db->query('SELECT * FROM ucmp_phones WHERE id=1')->fetch(PDO::FETCH_ASSOC)), ...$this->extraPhones]; }
        public function siteConnection(int $site): object {
            return new class($this) {
                public function __construct(private StageModule $module) {}
                public function dockerSftp(): GenerationSftpStub { return $this->module->sftp; }
                public function tftpRoot(): string { return '/tftpboot'; }
                public function asterisk(string $command): string {
                    if ($command==='sip show settings') {return $this->module->listener ? "UDP Bindaddress: 0.0.0.0:5160\n" : '';}
                    return $this->module->listener ? "Transport:  0.0.0.0-udp udp 3 96 0.0.0.0:5160\n" : '';
                }
            };
        }
    }
    // Real rebuild + production mutex: an editor attempts to save after resolution.
    $module = new StageModule(); $editor = new ConfigurationLockModule();
    FreePBX::$core = new StageCore(); FreePBX::$framework = new StageFramework();
    foreach (['700','701'] as $ext) { FreePBX::$core->devices[$ext] = ['id'=>$ext,'tech'=>'pjsip','transport'=>'0.0.0.0-udp','secret'=>'fixture-only','user'=>$ext]; }
    $blocked = false;
    \FreePBX\modules\Ucmprovisioner\AsteriskGlue::$duringApply = function () use ($editor,&$blocked): void {
        try { $editor->withConfigurationSyncLock(static function (): void { throw new LogicException('Editor entered during publication'); }); }
        catch (RuntimeException) { $blocked = true; }
    };
    $provisioner = new \FreePBX\modules\Ucmprovisioner\Provisioner($module,new \FreePBX\modules\Ucmprovisioner\ExtensionBinder($module,static function(array $receipt): void {}));
    $preview = $provisioner->rebuild('1');
    $result = $provisioner->rebuild('1',true,1,false,$preview['plan_hash']);
    \FreePBX\modules\Ucmprovisioner\AsteriskGlue::$duringApply = null;
    $expect(!empty($result['ok']) && $blocked,'Build did not exclude the competing configuration writer');
    $editor->withConfigurationSyncLock(function () use ($module): void {
        $clock = (int)$module->db->query('SELECT last_applied FROM ucmp_phones WHERE id=1')->fetchColumn();
        $expression = \FreePBX\modules\Ucmprovisioner\LogicalExtensionService::phoneUpdateExpression($module->db);
        $module->db->prepare("UPDATE ucmp_phones SET label='Saved after build',updated=$expression WHERE id=1")->execute([$clock]);
    });
    $saved = $module->getPhones()[0];
    $expect($saved['updated'] > $saved['last_applied'],'Same-second save after build was not pending');
    $expect(!str_contains($module->sftp->files['/tftpboot/001122334455.cfg'],'Saved after build'),'Saved edit incorrectly appeared in the earlier generation');
    $preview = $provisioner->rebuild('1');
    $result = $provisioner->rebuild('1',true,1,false,$preview['plan_hash']);
    $saved = $module->getPhones()[0];
    $expect(!empty($result['ok']) && $saved['updated'] <= $saved['last_applied'] && str_contains($module->sftp->files['/tftpboot/001122334455.cfg'],'Saved after build'), 'Next build did not publish and acknowledge the newer configuration revision');

    foreach (['missing_secondary','missing_listener','second_edit','reload','success','publication_reply'] as $scenario) {
        $module = new StageModule();
        FreePBX::$core = new StageCore(); FreePBX::$framework = new StageFramework();
        foreach (['700','701'] as $ext) {
            FreePBX::$core->devices[$ext] = ['id'=>$ext, 'tech'=>'pjsip', 'transport'=>'0.0.0.0-tcp', 'secret'=>'fixture-only', 'user'=>$ext];
        }
        if ($scenario === 'missing_secondary') { unset(FreePBX::$core->devices['701']); }
        if ($scenario === 'missing_listener') { $module->listener = false; }
        if ($scenario === 'second_edit') { FreePBX::$core->fail = '701'; }
        if ($scenario === 'reload') { FreePBX::$framework->ok = false; }
        $restoreCount=\FreePBX\modules\Ucmprovisioner\AsteriskGlue::$restored;
        if ($scenario==='publication_reply') { $module->sftp->loseCommittedReceipt=true; }
        $coreReceipts = [];
        $binder = new \FreePBX\modules\Ucmprovisioner\ExtensionBinder($module,static function(array $receipt) use (&$coreReceipts): void { $coreReceipts[] = $receipt; });
        $provisioner = new \FreePBX\modules\Ucmprovisioner\Provisioner($module,$binder);
        $preview = $provisioner->rebuild('1');
        $result = $provisioner->rebuild('1', true, 1, false, $preview['plan_hash']);
        if (str_starts_with($scenario, 'missing_')) {
            $expect(empty($result['ok']) && $result['stages']['files_published'] === false, 'All-account preflight must stop publication');
            $expect(!isset($module->sftp->files['/tftpboot/001122334455.cfg']) && FreePBX::$core->attempts === [], 'Preflight failure cannot publish or edit any account');
        } elseif ($scenario==='publication_reply') {
            $expect(empty($result['ok'])&&!empty($result['recovery_required'])&&isset($module->sftp->files['/tftpboot/001122334455.cfg'])
                &&\FreePBX\modules\Ucmprovisioner\AsteriskGlue::$restored===$restoreCount,
                'Uncertain final generation receipt must retain published files and Asterisk state');
        } else {
            $expect(in_array('700', FreePBX::$core->attempts, true) && in_array('701', FreePBX::$core->attempts, true), 'Both bound accounts must be synchronized');
            $expect($result['stages']['files_published'] === true && $result['stages']['device_observed'] === null, 'File evidence must not infer device acceptance');
            $expect($result['ok'] === ($scenario === 'success') && $result['stages']['pbx_activated'] === ($scenario === 'success'), 'Required Core/reload failure must produce a partial failed result');
            $row = $module->db->query('SELECT state,details_json FROM ucmp_apply_transactions')->fetch(PDO::FETCH_ASSOC);
            $expect($row['state'] === ($scenario === 'success' ? 'committed' : 'failed'), 'Durable final transaction result must match required stage failures');
        }
    }
    foreach (['matching','transport_drift','secret_drift','sip_transport_list','sip_transport_mismatch'] as $scenario) {
        $module=new StageModule();FreePBX::$core=new StageCore();FreePBX::$framework=new StageFramework();
        foreach (['700','701'] as $ext) { FreePBX::$core->devices[$ext]=['id'=>$ext,'tech'=>'pjsip','transport'=>'0.0.0.0-udp','secret'=>'fixture-only','user'=>$ext]; }
        if ($scenario==='transport_drift') { FreePBX::$core->devices['701']['transport']='0.0.0.0-tcp'; }
        if ($scenario==='secret_drift') { FreePBX::$core->devices['701']['secret']='changed-fixture'; }
        if (str_starts_with($scenario,'sip_transport_')) {
            \FreePBX\modules\Ucmprovisioner\TemplateEngine::$driver='sip';
            foreach (['700','701'] as $ext) {FreePBX::$core->devices[$ext]['tech']='sip';FreePBX::$core->devices[$ext]['transport']=$scenario==='sip_transport_list'?'udp,tcp,tls':'tcp,tls';}
        }
        $binder=new \FreePBX\modules\Ucmprovisioner\ExtensionBinder($module,static function(array $receipt): void {});
        $provisioner=new \FreePBX\modules\Ucmprovisioner\Provisioner($module,$binder);
        $before=FreePBX::$core->devices;$sent=\FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent;
        $preview=$provisioner->rebuild('1',false,1,false,null,false,true);
        $result=$provisioner->rebuild('1',true,1,false,$preview['plan_hash'],false,true);
        $expect(FreePBX::$core->devices===$before && FreePBX::$core->attempts===[],'Identity-only path must never edit endpoint driver, transport or secret');
        $expect(\FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent===$sent,'Identity-only path must never notify or reboot phones');
        $expect(!empty($result['ok'])===in_array($scenario,['matching','sip_transport_list'],true),'Identity-only path must accept supported SIP lists and reject endpoint/secret drift before publication: '.$scenario);
        \FreePBX\modules\Ucmprovisioner\TemplateEngine::$driver='pjsip';
    }
    foreach ([false,true] as $restartAfter) {
        $module=new StageModule();FreePBX::$core=new StageCore();FreePBX::$framework=new StageFramework();
        foreach (['700','701'] as $ext) { FreePBX::$core->devices[$ext]=['id'=>$ext,'tech'=>'pjsip','transport'=>'0.0.0.0-udp','secret'=>'fixture-only','user'=>$ext]; }
        $module->db->exec("UPDATE ucmp_phones SET applied_driver='pjsip',applied_transport='tcp',applied_sip_port=5160");
        $binder=new \FreePBX\modules\Ucmprovisioner\ExtensionBinder($module,static function(array $receipt): void {});
        $provisioner=new \FreePBX\modules\Ucmprovisioner\Provisioner($module,$binder);
        $preview=$provisioner->rebuild('all');
        $sent=\FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent;
        $result=$provisioner->rebuild('all',true,1,$restartAfter,$preview['plan_hash']);
        $expect(\FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent-$sent===($restartAfter?1:0),'Generate all must honor the restart choice even when endpoint settings changed');
        $expect(count($result['restarts'])===($restartAfter?1:0),'Device receipts must match explicitly requested commands');
        $expect($restartAfter || ($result['stages']['device_action']===null && !str_contains($result['message'],'requested')),'Unchecked restart must not report a device command');
    }
    $module=new StageModule();FreePBX::$core=new StageCore();FreePBX::$framework=new StageFramework();
    $oldSecret=str_repeat('fixture-long',24);
    \FreePBX\modules\Ucmprovisioner\TemplateEngine::$secret=$oldSecret;
    foreach (['700','701'] as $ext) { FreePBX::$core->devices[$ext]=['id'=>$ext,'tech'=>'pjsip','transport'=>'0.0.0.0-udp','secret'=>$oldSecret,'user'=>$ext]; }
    $provisioner=new \FreePBX\modules\Ucmprovisioner\Provisioner($module,new \FreePBX\modules\Ucmprovisioner\ExtensionBinder($module,static function(array $receipt): void {}));
    $module->extraPhones=[array_replace($module->getPhones()[0],['id'=>2,'mac'=>'001122334466'])];
    try { $provisioner->rebuild('1'); $expect(false,'Shared account alignment must require every affected phone in the selection'); }
    catch (\RuntimeException $error) { $expect(str_contains($error->getMessage(),'phone #2'),'Shared account failure must identify the unselected phone'); }
    $expect(FreePBX::$core->attempts===[] && !isset($module->sftp->files['/tftpboot/001122334455.cfg']), 'Shared account guard must precede Core and file mutation');
    $module->extraPhones=[];
    $preview=$provisioner->rebuild('all');$again=$provisioner->rebuild('all');
    $expect($preview['plan_hash']===$again['plan_hash'] && FreePBX::$core->devices['700']['secret']===$oldSecret,'Password preview must be stable and leave Core unchanged');
    $sent=\FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent;
    $result=$provisioner->rebuild('all',true,1,false,$preview['plan_hash']);
    foreach (['700','701'] as $ext) {
        $newSecret=FreePBX::$core->devices[$ext]['secret'];
        $expect(strlen($newSecret)===16 && str_contains($module->sftp->files['/tftpboot/001122334455.cfg'],$newSecret),'Apply must change each extension password to exactly the published credential');
        $expect(!str_contains(json_encode($result),$newSecret),'Public build receipts must never contain an aligned password');
    }
    $expect(!empty($result['ok']) && \FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent===$sent,'Automatic password alignment must honor the unchecked restart choice');
    \FreePBX\modules\Ucmprovisioner\TemplateEngine::$secret='fixture-only';
    foreach (['endpoint_tracking','final_receipt'] as $scenario) {
        $module=new StageModule();FreePBX::$core=new StageCore();FreePBX::$framework=new StageFramework();
        foreach (['700','701'] as $ext) { FreePBX::$core->devices[$ext]=['id'=>$ext,'tech'=>'pjsip','transport'=>'0.0.0.0-udp','secret'=>'fixture-only','user'=>$ext]; }
        $binder=new \FreePBX\modules\Ucmprovisioner\ExtensionBinder($module,static function(array $receipt): void {});
        $provisioner=new \FreePBX\modules\Ucmprovisioner\Provisioner($module,$binder);
        $preview=$provisioner->rebuild('1');
        if ($scenario==='endpoint_tracking') {
            $module->db->exec("CREATE TRIGGER fail_tracking BEFORE UPDATE OF applied_driver ON ucmp_phones BEGIN SELECT RAISE(ABORT,'fixture tracking failure'); END");
        } else {
            \FreePBX\modules\Ucmprovisioner\BuildTransactionService::ensureSchema($module->db);
            $module->db->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE OF state ON ucmp_apply_transactions WHEN NEW.state='committed' BEGIN SELECT RAISE(ABORT,'fixture receipt failure'); END");
        }
        $result=$provisioner->rebuild('1',true,1,false,$preview['plan_hash']);
        $expect(!empty($result['ok']) && $result['stages']['files_published'] && $result['stages']['pbx_activated']
            && count($result['reporting_errors'])===1 && isset($module->sftp->files['/tftpboot/001122334455.cfg']),
            'Late database reporting failure must preserve known live stages and published files');
    }
    foreach (['committed_throw','committed_false','rollback_false','rollback_throw'] as $fault) {
        $module=new StageModule();FreePBX::$core=new StageCore();FreePBX::$framework=new StageFramework();
        foreach (['700','701'] as $ext) { FreePBX::$core->devices[$ext]=['id'=>$ext,'tech'=>'pjsip','transport'=>'0.0.0.0-udp','secret'=>'fixture-only','user'=>$ext]; }
        $binder=new \FreePBX\modules\Ucmprovisioner\ExtensionBinder($module,static function(array $receipt): void {});
        $provisioner=new \FreePBX\modules\Ucmprovisioner\Provisioner($module,$binder);
        $preview=$provisioner->rebuild('1');
        $module->db->fault=$fault;
        $restored=\FreePBX\modules\Ucmprovisioner\AsteriskGlue::$restored;
        $sent=\FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent;
        $result=$provisioner->rebuild('1',true,1,false,$preview['plan_hash']);
        $expect(empty($result['ok']) && !empty($result['recovery_required']) && $result['stages']['files_published']===true,
            'Uncertain metadata commit must retain the known file stage and require reconciliation: '.$fault);
        $expect(isset($module->sftp->files['/tftpboot/001122334455.cfg'])
            && isset($module->sftp->files['/tftpboot/.ucmprovisioner/write-lock/owner.json'])
            && \FreePBX\modules\Ucmprovisioner\AsteriskGlue::$restored===$restored,
            'Uncertain metadata must preserve published files, glue and the writer lock: '.$fault);
        $expect(FreePBX::$core->attempts===[] && \FreePBX\modules\Ucmprovisioner\PhoneCommand::$sent===$sent,
            'Uncertain metadata must stop before endpoint and handset actions');
        if (str_starts_with($fault,'committed_')) {
            $expect((int)$module->db->query('SELECT last_applied FROM ucmp_phones WHERE id=1')->fetchColumn()>0,
                'Known committed metadata must remain paired with its published files');
        }
        $module->db->fault='';
        if ($module->db->inTransaction()) { $module->db->rollBack(); }
    }
    echo "Provisioner all-account and identity-only stage outcome fixtures passed\n";
}
