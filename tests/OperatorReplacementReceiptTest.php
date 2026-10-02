<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner {
    // Only vendor rendering is isolated; real reviewed workflow and guarded DB save run below.
    final class TemplateEngine {
        public function __construct($module) {}
        public function resolvePhone(array $phone): array { return ['phone'=>$phone,'model'=>['id'=>$phone['model_id']],'settings'=>[],'lines'=>[]]; }
    }
}
namespace {
require __DIR__.'/PhoneAssignmentGuardTest.php';
use FreePBX\modules\Ucmprovisioner\OperatorWorkflowService;
[$db,$module]=$fixture();OperatorWorkflowService::ensureSchema($db);$service=new OperatorWorkflowService($module);
$before=$snapshot($db);$review=$service->previewReplacement(1,['phone_id'=>1,'extension'=>'200','collision_action'=>'swap']);
$expect($before===$snapshot($db),'Replacement review does not change assignment');
$result=$service->executeReplacement(1,$review);
$expect($result['ok'] && !$result['deployed'] && count($result['affected_phone_ids'])===2,'Real reviewed wrapper saves assignment without deployment');
$expect($module->getPhoneLocal(1)['extension']==='200' && $module->getPhoneLocal(2)['extension']==='100','Reviewed swap reaches real guarded save');
$status=$service->changeHistory(1,['op'=>'change_status','plan_id'=>$review['plan_id']]);
$expect($status['changes'][0]['state']==='completed' && !isset($status['changes'][0]['plan_json']),'Status is a safe durable receipt projection');
$expect(!$service->changeHistory(2,['op'=>'change_status','plan_id'=>$review['plan_id']])['changes'],'Status is site scoped');
try {$service->executeReplacement(1,$review);throw new LogicException('Replay accepted');}catch(DomainException $expected){}
[$db,$module]=$fixture();OperatorWorkflowService::ensureSchema($db);$service=new OperatorWorkflowService($module);
$review=$service->previewReplacement(1,['phone_id'=>1,'extension'=>'200','collision_action'=>'swap']);
$db->exec("UPDATE ucmp_phones SET label='concurrent' WHERE id=2");$before=$snapshot($db);
$result=$service->executeReplacement(1,$review);$expect(!$result['ok'] && $before===$snapshot($db),'Stale collision through real wrapper fails before mutation');
$expect($service->changeHistory(1,['op'=>'change_status','plan_id'=>$review['plan_id']])['changes'][0]['state']==='failed','Failure receipt survives');
[$db,$module]=$fixture();OperatorWorkflowService::ensureSchema($db);$service=new OperatorWorkflowService($module);
$review=$service->previewReplacement(1,['phone_id'=>1,'extension'=>'200','collision_action'=>'swap']);
$db->exec("CREATE TRIGGER prevent_receipt BEFORE UPDATE ON ucmp_operator_changes WHEN NEW.state='completed' BEGIN SELECT RAISE(IGNORE); END");
$result=$service->executeReplacement(1,$review);
$expect(!$result['ok'] && $result['operation_ok'] && !$result['receipt_saved'] && $result['state']==='uncertain'
    && count($result['assignment_after'])===2 && isset($result['reporting_error']),
    'Final receipt failure must remain explicit while retaining verified assignment outcomes');
$status=$service->changeHistory(1,['op'=>'change_status','plan_id'=>$review['plan_id']]);
$expect($status['changes'][0]['state']==='applying' && $status['changes'][0]['uncertain'],'Failed finalization retains uncertain non-replayable state');
$expect($module->getPhoneLocal(1)['extension']==='200','Receipt failure does not falsely imply physical assignment rollback');
try {$service->executeReplacement(1,$review);throw new LogicException('Replay accepted after failed finalization');}catch(DomainException $expected){}
echo "Real replacement preview/save, stale collision, status isolation and failed-finalization receipts passed\n";
}
