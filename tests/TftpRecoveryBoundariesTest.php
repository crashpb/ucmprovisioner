<?php
declare(strict_types=1);
require_once __DIR__.'/TftpGenerationTransactionTest.php';
require_once __DIR__.'/TimezoneRuntimeFixture.php';
require_once __DIR__.'/../lib/CiscoTimezoneBundleManager.php';
require_once __DIR__.'/../lib/ManagedAssetRegistry.php';

use FreePBX\modules\Ucmprovisioner\TftpStore;
use FreePBX\modules\Ucmprovisioner\CiscoTimezoneService;
use FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager;
use FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry;

final class RecoveryCommitDb extends PDO
{
    public bool $loseCommit=false;
    public function commit(): bool {
        $done=parent::commit();
        if ($this->loseCommit) { throw new RuntimeException('Injected lost commit acknowledgement'); }
        return $done;
    }
}
final class RecoverySftp
{
    public GenerationSftpStub $inner;
    public string $loseMove='';
    public function __construct() { $this->inner=new GenerationSftpStub(); }
    public function __call(string $method,array $args): mixed { return $this->inner->$method(...$args); }
    public function nlist(string $path): array { return array_keys($this->inner->rawlist($path)); }
    public function posix_rename(string $from,string $to): bool { return $this->rename($from,$to); }
    public function rename(string $from,string $to): bool {
        $result=$this->inner->rename($from,$to);
        if ($result&&$from===$this->loseMove) { $this->loseMove='';throw new RuntimeException('Injected lost move reply'); }
        return $result;
    }
}
$makeRecovery=static function():array {
    $db=new RecoveryCommitDb('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $sftp=new RecoverySftp();
    $module=new class($db,$sftp) {
        public function __construct(private PDO $db,private object $sftp){}
        public function getDb():PDO{return $this->db;}
        public function localSiteId():int{return 1;}
        public function phoneConfigFiles(int $site):array{return ['SEP001122334455.cnf.xml'=>[]];}
        public function siteConnection(int $site):object{return new class($this->sftp){
            public function __construct(private object $sftp){}
            public function dockerSftp():object{return $this->sftp;}
            public function tftpRoot():string{return '/tftpboot';}
        };}
    };
    return [$db,$sftp,new TftpStore($module)];
};
foreach (['lost_move','lost_commit','success'] as $fault) {
    [$db,$sftp,$store]=$makeRecovery();
    $manager=new CiscoTimezoneBundleManager($db);
    $contents=['tzupdater.ver'=>"2024a\n"];
    foreach (['j9-tzdata.jar','tzupdater.jar','TzDataCSV.csv'] as $name) { $contents[$name]=timezoneRuntimeFixture($name,'tzdata2024a'); }
    $inspection=CiscoTimezoneService::inspect(array_keys($contents),static fn(string $name)=>$contents[$name]??false);
    $relative='.ucmprovisioner/timezone-bundles/'.$inspection['fingerprint'];$sftp->mkdir('/tftpboot/'.$relative,0770,true);$files=[];
    foreach ($contents as $name=>$bytes) {
        $hash=hash('sha256',$bytes);$sftp->put('/tftpboot/'.$relative.'/'.$hash.'.blob',$bytes);
        $files[]=['name'=>$name,'stored_name'=>$hash.'.blob','size'=>strlen($bytes),'sha256'=>$hash];
    }
    $bundle=$manager->store(1,$inspection,$files,$relative);
    $sftp->put('/tftpboot/tzupdater.ver','unusable-old-version');
    if ($fault==='lost_move') { $sftp->loseMove='/tftpboot/tzupdater.ver'; }
    $db->loseCommit=$fault==='lost_commit';
    $result=$store->handle(['op'=>'timezone_activate','site_id'=>1,'bundle_id'=>$bundle['id']]);
    if ($fault==='success') {
        $expect(!empty($result['ok'])&&$store->siteLockStatus(1)['state']==='unlocked','Verified timezone activation must finish normally: '.json_encode($result));
    } else {
        $expect(empty($result['ok'])&&!empty($result['recovery_required'])&&$store->siteLockStatus(1)['state']==='locked','Uncertain timezone activation must retain exclusion: '.json_encode($result));
        $expect(($sftp->inner->files['/tftpboot/'.$result['backup_path'].'/tzupdater.ver']??'')==='unusable-old-version','Previous timezone bytes were removed before database acknowledgement');
        if ($fault==='lost_commit') {
            $expect(!empty($result['files_verified'])&&$manager->get(1,$bundle['id'])['active']===1,'Lost acknowledgement must preserve committed metadata and verified publication');
            foreach($contents as $name=>$bytes){$expect(($sftp->inner->files['/tftpboot/'.$name]??'')===$bytes,'Lost metadata acknowledgement undid a published file');}
        }
    }
}
foreach (['lost_move','lost_commit','success'] as $fault) {
    [$db,$sftp,$store]=$makeRecovery();ManagedAssetRegistry::ensureSchema($db);
    foreach(['ucmp_firmware_bundles','ucmp_timezone_bundles','ucmp_asset_scan_jobs'] as $table){$db->exec('CREATE TABLE '.$table.' (site_id INTEGER)');}
    $sftp->put('/tftpboot/SEP001122334455.cnf.xml','phone');$sftp->put('/tftpboot/old.raw','old');
    if($fault==='lost_move'){$sftp->loseMove='/tftpboot/old.raw';}
    $db->loseCommit=$fault==='lost_commit';
    $result=$store->handle(['op'=>'reset_except_phone_configs','site_id'=>1,'apply'=>1,'confirm'=>'PRESERVE_PHONE_CONFIGS']);
    $expect(($sftp->inner->files['/tftpboot/SEP001122334455.cnf.xml']??'')==='phone','Reset changed a managed phone config');
    $expect(isset($result['quarantine']),'Reset lost its recovery identity: '.json_encode($result));
    $receipt=json_decode($sftp->inner->files[$result['quarantine'].'/current.json']??'',true);
    $expect(($sftp->inner->files[$result['quarantine'].'/tftp/old.raw']??'')==='old','Quarantined bytes were lost');
    if($fault==='success'){$expect(!empty($result['ok'])&&$receipt['state']==='complete','Reset completion was not recorded');}
    else{$expect(empty($result['ok'])&&!empty($result['recovery_required'])&&$store->siteLockStatus(1)['state']==='locked','Uncertain reset released exclusion');}
    if($fault==='lost_move'){$expect(($receipt['pending_move']['source']??'')==='/tftpboot/old.raw','Lost move has no durable pending intent');}
    if($fault==='lost_commit'){$expect($receipt['state']==='files_quarantined_database_unknown','Lost commit was reported as a definitive failure');}
}
echo "Timezone and reset recovery boundaries passed\n";
