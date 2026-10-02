<?php
declare(strict_types=1);
interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Ucmprovisioner.class.php';

$module=new class extends FreePBX\modules\Ucmprovisioner {
    public array $calls=[];
    public int $failSite=0;
    public int $skipSite=0;
    public function __construct() {}
    public function localSiteId(): int { return 1; }
    public function getSites(): array { return [['id'=>1,'site_key'=>'controller','enabled'=>1],['id'=>2,'site_key'=>'owner','enabled'=>1],['id'=>3,'site_key'=>'blf-peer','enabled'=>1],['id'=>4,'site_key'=>'disabled','enabled'=>0]]; }
    public function getSite(int $id,bool $withSecrets=false): ?array { foreach ($this->getSites() as $site) { if ($site['id']===$id) { return $site; } }return null; }
    public function operatorWorkflowAtSite(int $site,array $request): array {
        $this->calls[]=['site'=>$site]+$request;
        if ($site===$this->failSite) { return ['ok'=>false,'message'=>'Synthetic old peer has no capability']; }
        if ($request['op']==='delivery_plan') {
            $skipped=[];
            foreach ($request['identities'] as $identity) { if ($site===$this->skipSite && $identity['extension']==='101') { $skipped[]=['target'=>'owner:101','reason'=>'Synthetic missing account']; } }
            return ['ok'=>true,'identity_delivery_schema'=>1,'plan_hash'=>hash('sha256',(string)$site),'skipped'=>$skipped];
        }
        if ($request['op']!=='delivery_execute') { throw new LogicException('Unexpected RPC'); }
        return ['ok'=>true,'identity_delivery_schema'=>1,'results'=>['100'=>['ok'=>true,'callerid_checked'=>true,'changed'=>$site===2]],'stages'=>['pbx_activated'=>true,'device_observed'=>null]];
    }
};
$packets=[['extension'=>'100','identity_site_key'=>'owner','origin_site_key'=>'controller']];
$result=$module->applyDirectoryIdentities(2,$packets);
if (empty($result['ok']) || count($module->calls)!==6 || empty($result['results']['100']['changed'])) { throw new RuntimeException('Owner and all enabled derived peers were not checked'); }
foreach ($module->calls as $call) {
    if ($call['site']===4 || !in_array($call['op'],['delivery_plan','delivery_execute'],true) || $call['identities']!==$packets) { throw new RuntimeException('Identity delivery retargeted or used a legacy capability'); }
}
$module->calls=[];$module->failSite=3;
try { $module->applyDirectoryIdentities(2,$packets);throw new LogicException('Unavailable peer accepted'); }
catch (RuntimeException $expected) { if (!str_contains($expected->getMessage(),'Synthetic old peer')) { throw $expected; } }
if (count($module->calls)!==3 || array_filter($module->calls,static fn($call)=>$call['op']==='delivery_execute')) { throw new RuntimeException('All sites must be reviewed before any delivery'); }
$module->failSite=0;
foreach ([2,3] as $skipSite) {
    $module->calls=[];$module->skipSite=$skipSite;
    $mixed=[$packets[0],array_replace($packets[0],['extension'=>'101'])];
    $result=$module->applyDirectoryIdentities(2,$mixed);
    if (!$result['ok'] || empty($result['results']['100']['ok']) || empty($result['results']['101']['skipped']) || !empty($result['results']['101']['ok'])) { throw new RuntimeException('Individual rejection poisoned the batch or was falsely acknowledged'); }
    $executions=array_filter($module->calls,static fn($call)=>$call['op']==='delivery_execute');
    if (count($executions)!==3) { throw new RuntimeException('Eligible identity did not reach every enabled destination'); }
    foreach ($executions as $call) { if ($call['identities']!==$packets) { throw new RuntimeException('Skipped identity leaked into an owner or derived consumer update'); } }
    $module->calls=[];$allSkipped=$module->applyDirectoryIdentities(2,[$mixed[1]]);
    if (!$allSkipped['ok'] || empty($allSkipped['results']['101']['skipped']) || count($module->calls)!==3) { throw new RuntimeException('An entirely skipped batch must perform no delivery'); }
}
try { $module->applyDirectoryIdentities(4,$packets);throw new LogicException('Disabled owner accepted'); }
catch (DomainException $expected) {}
echo "Identity owner and derived-peer fixed capability routing assertions passed\n";

// Exercise the real routing boundary: the caller cannot inject a different
// receiver alias, and a legacy receiver must be rejected before execution.
$router=new class extends FreePBX\modules\Ucmprovisioner {
    public bool $legacy=false;
    public array $request=[];
    public function __construct() {}
    public function getSite(int $id,bool $withSecrets=false):?array { return ['id'=>$id,'site_key'=>'saved-owner']; }
    public function isSiteLocal(int $siteId):bool { return true; }
    public function operatorWorkflowLocal(array $request):array {
        $this->request=$request;
        return ['ok'=>true]+($this->legacy?[]:['receiver_site_key'=>$request['receiver_site_key']]);
    }
};
$router->operatorWorkflowAtSite(1,['op'=>'delivery_plan','receiver_site_key'=>'injected']);
if ($router->request['receiver_site_key']!=='saved-owner') { throw new RuntimeException('Saved destination context was not enforced'); }
$router->legacy=true;
try { $router->operatorWorkflowAtSite(1,['op'=>'delivery_plan']);throw new LogicException('Legacy unacknowledged mapping accepted'); }
catch (RuntimeException $expected) { if (!str_contains($expected->getMessage(),'did not acknowledge')) { throw $expected; } }
echo "Identity transport mapping and legacy receiver gate passed\n";
