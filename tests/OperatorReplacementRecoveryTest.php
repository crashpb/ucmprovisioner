<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner {
    // Isolated resolver boundary; real move/swap, review receipts and revision guards below.
    final class TemplateEngine {
        public function __construct($module){}
        public function resolvePhone(array $phone):array{return ['phone'=>$phone,'model'=>['id'=>$phone['model_id']],'settings'=>[],'lines'=>[]];}
    }
}
namespace {
require_once dirname(__DIR__).'/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\{OperatorWorkflowService,PhoneAssignment,IdentityDeliveryService};
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$module=new class {
    private PDO $db;public array $phones=[];public array $writes=[];
    public function __construct(){
        $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);OperatorWorkflowService::ensureSchema($this->db);
        $this->db->exec('CREATE TABLE ucmp_extensions (id INTEGER,site_id INTEGER,extension TEXT,revision INTEGER)');
        $this->db->exec("INSERT INTO ucmp_extensions VALUES (1,1,'100',1),(2,1,'200',1)");
    }
    public function getDb():PDO{return $this->db;}
    public function localSiteId():int{return 1;}
    public function getPhone(int $id,int $site):?array{return $this->phones[$id]??null;}
    public function getPhones(array $filter):array{return array_values($this->phones);}
    public function savePhoneAssignmentLocal(array $request):array {
        $this->writes[]=$request;$target=$this->phones[$request['id']];$other=null;
        foreach($this->phones as $phone)if($request['extension']!=='' && $phone['id']!==$target['id'] && $phone['extension']===$request['extension'])$other=$phone;
        if(IdentityDeliveryService::hash($target)!==$request['expected_target_hash'] || (int)($other['id']??0)!==$request['expected_other_id']
            || ($other?IdentityDeliveryService::hash($other):'')!==$request['expected_other_hash'])throw new DomainException('Stale physical assignment');
        foreach($request['expected_identity_revisions'] as $number=>$revision){$st=$this->db->prepare('SELECT revision FROM ucmp_extensions WHERE extension=?');$st->execute([(string)$number]);if((int)$st->fetchColumn()!==$revision)throw new DomainException('Stale person');}
        $plan=PhoneAssignment::plan($target,$other,$request['extension'],$request['collision_action']);
        $this->phones[$target['id']]['extension']=$plan['target_extension']??'';
        if($other)$this->phones[$other['id']]['extension']=$plan['other_extension']??'';
        return ['ok'=>true,'affected_ids'=>array_keys($this->phones),'unassigned_ids'=>array_column(array_filter($this->phones,fn($p)=>$p['extension']===''),'id')];
    }
};
$service=new OperatorWorkflowService($module);
$phone=static fn(int $id,string $extension):array=>['id'=>$id,'site_id'=>1,'model_id'=>1,'mac'=>sprintf('%012X',$id),'extension'=>$extension,'secret'=>'never-project-secret','overrides'=>'private-config'];
$execute=static fn(array $plan):array=>$service->executeReplacement(1,['plan_id'=>$plan['plan_id'],'plan_hash'=>$plan['plan_hash']]);
foreach([['100','200','swap'],['100','200','move'],['','200','move'],['100','','move'],['','','move']] as [$old,$other,$action]){
    $module->phones=[1=>$phone(1,$old),2=>$phone(2,$other)];$before=array_map(fn($p)=>$p['extension'],$module->phones);
    $plan=$service->previewReplacement(1,['phone_id'=>1,'extension'=>'200','collision_action'=>$action]);
    $expect(!str_contains(json_encode($plan),'never-project-secret') && !str_contains(json_encode($plan),'private-config'),'Review does not expose private phone fields');
    $result=$execute($plan);$expect($result['ok'] && !$result['deployed'] && count($result['post_change_checklist'])>=4,'Saved assignment has explicit server/device checklist');
    $row=$module->getDb()->query("SELECT plan_json,result_json FROM ucmp_operator_changes WHERE id='".$plan['plan_id']."'")->fetch(PDO::FETCH_ASSOC);
    $expect(!str_contains(json_encode($row),'never-project-secret') && !str_contains(json_encode($row),'private-config'),'Durable replacement receipt retains only safe metadata');
    $expect(isset(json_decode($row['plan_json'],true)['assignment_before']) && isset(json_decode($row['result_json'],true)['assignment_after']),'Before and observed after mappings survive navigation');
    foreach($result['reverse_review']['steps'] as $step){
        if($module->phones[$step['phone_id']]['extension']===$step['extension'])continue;
        $reverse=$service->previewReplacement(1,$step);$expect($execute($reverse)['ok'],'Recorded reverse step can execute through a fresh guarded review');
    }
    $expect(array_map(fn($p)=>$p['extension'],$module->phones)===$before,'Move/swap/free-number/unassigned cases recover original mappings through fresh reviews');
}
$module->phones=[1=>$phone(1,'100'),2=>$phone(2,'200')];
$plan=$service->previewReplacement(1,['phone_id'=>1,'extension'=>'200','collision_action'=>'swap']);
$module->phones[1]['model_id']=2;$result=$execute($plan);
$expect(!$result['ok'] && $result['assignment_after']===null && $result['assignment_before'][0]['extension']==='100','Stale target preserves before metadata without claiming successful restore');
foreach($module->writes as $write)$expect(!array_intersect_key($write,array_flip(['secret','apply_transport','reboot'])),'Reviewed assignment does not rotate credentials or reboot');
echo "Replacement retained mappings, fresh reverse reviews, unassignment and checklist fixtures passed\n";
}
