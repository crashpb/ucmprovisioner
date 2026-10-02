<?php
declare(strict_types=1);
require __DIR__.'/PhoneAssignmentGuardTest.php';

foreach (['success','stale_binding','late_database_failure','lost_commit','second_file_failure'] as $fault) {
    [$db,$module]=$fixture();
    $db->exec('CREATE TABLE ucmp_phone_builds (phone_id INTEGER)');
    $storage=new class($db) {
        public bool $locked=false;
        public int $removed=0;
        public int $failAt=0;
        public function __construct(private PDO $db){}
        public function withSiteLock(int $site,callable $action):array {
            if($this->locked){throw new LogicException('Unexpected separate storage lock');}
            $this->locked=true;
            try { return $action(); } finally {$this->locked=false;}
        }
        public function deletePhoneConfig(int $site,string $filename):array {
            if(!$this->locked||!$this->db->inTransaction()){throw new LogicException('Phone cleanup escaped its transaction/exclusion boundary');}
            ++$this->removed;
            return ['ok'=>$this->removed!==$this->failAt,'filename'=>$filename,'message'=>'Injected cleanup stage'];
        }
    };
    (new ReflectionProperty(FreePBX\modules\Ucmprovisioner::class,'engines'))->setValue($module,['TftpStore'=>$storage]);
    $db->atCommit=static function()use($storage,$expect):void{$expect($storage->locked,'Storage exclusion ended before the binding commit');};
    if($fault==='late_database_failure'){$db->exec("CREATE TRIGGER reject_binding BEFORE UPDATE ON ucmp_phones BEGIN SELECT RAISE(ABORT,'injected binding failure'); END");}
    if($fault==='lost_commit'){$db->loseCommit=true;}
    if($fault==='second_file_failure'){$storage->failAt=2;}
    $revision=FreePBX\modules\Ucmprovisioner::phoneBindingRevision($module->getPhoneLocal(1));
    if($fault==='stale_binding'){$db->exec("UPDATE ucmp_phones SET mac='001122334499' WHERE id=1");}
    $result=$module->deletePhoneLocal(1,$revision);
    if($fault==='success'){
        $expect(!empty($result['ok'])&&$module->getPhoneLocal(1)['mac']===null,'Phone deletion did not clear the binding');
    }elseif($fault==='stale_binding'){
        $expect(empty($result['ok'])&&!empty($result['conflict'])&&$storage->removed===0&&$module->getPhoneLocal(1)['mac']==='001122334499','Stale page deleted a replacement binding');
    }else{
        $expect(empty($result['ok'])&&!empty($result['recovery_required'])&&!empty($result['tftp']),'Incomplete deletion lost its per-file result or recovery requirement');
        $expectedMac=$fault==='lost_commit'?null:'001122334455';
        $expect($module->getPhoneLocal(1)['mac']===$expectedMac,'Binding outcome differs from its actual transaction');
        if($fault==='lost_commit'){$expect($result['status']==='unknown'&&$result['binding_retired']===null,'Lost deletion acknowledgement was mislabeled as a preserved binding');}
        if($fault==='second_file_failure'){$expect(count($result['tftp'])===2&&$storage->removed===2,'A failed cleanup should retain earlier results and stop');}
    }
    $expect(!$db->inTransaction(),'Phone deletion left an open transaction');
}
echo "Phone deletion exclusion, partial cleanup and commit recovery passed\n";
