<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/IdentityBindingModesTest.php';
use FreePBX\modules\Ucmprovisioner\IdentityDeliveryService as Delivery;
use FreePBX\modules\Ucmprovisioner\ExtensionBinder;

$receiver=new class($db) {
    public array $cache=['schema'=>1,'entries'=>[]];
    public function __construct(private PDO $db) {}
    public function getDb():PDO { return $this->db; }
    public function localSiteId():int { return 1; }
    public function getSite(int $id):array { return ['id'=>$id,'site_key'=>'receiver-installation']; }
    public function getSetting(string $key,?int $site=null,$default=null) { return $key===Delivery::CACHE_KEY?json_encode($this->cache):$default; }
    public function getPhones(array $filter=[]):array { return []; }
};
$binder=new class($receiver) extends ExtensionBinder {
    public function getDevice(string $extension):?array { return ['id'=>$extension]; }
};
$packet=Delivery::normalize(['identity_site_key'=>'controller-peer-entry','origin_site_key'=>'controller',
    'extension'=>'990','binding_mode'=>'ad_primary','adoption'=>'managed','extension_revision'=>3,
    'source_id'=>4,'object_key'=>'remote-person','canonical'=>['display_name'=>'Remote Example','label'=>'r.example'],
    'sync_name'=>true,'sync_label'=>true]);
$logical->ensurePbxExtension(1,'990');
$receiver->cache['entries']['controller-peer-entry:990']=$packet;
$expect(Delivery::resolveCached($receiver,1,'990')===null,'Unmapped foreign key must not be guessed from extension number');
$service=new Delivery($receiver,$binder,'controller-peer-entry');
$plan=$service->planLocal([$packet]);
$expect($plan['ok'] && count($plan['core_targets'])===1,'Different controller and receiver site keys must still target the local account');
$absent=new class($receiver) extends ExtensionBinder {public function getDevice(string $extension):?array {return null;}};
$blocked=(new Delivery($receiver,$absent,'controller-peer-entry'))->planLocal([$packet+['force'=>true]]);
$expect($blocked['ok'] && !$blocked['items'] && !$blocked['core_targets'] && !$blocked['force_reload']
    && str_contains($blocked['skipped'][0]['reason'],'account 990 does not exist'),'Missing account is an actionable skip without a forced reload');
$expect(Delivery::hash($blocked['cache_after']['entries'])===Delivery::hash($receiver->cache['entries']),'Skipped identity must preserve the prior projection');
$reject(fn()=>(new Delivery($receiver,$absent,'controller-peer-entry'))->planLocal([$packet,$packet]));
$mixedBinder=new class($receiver) extends ExtensionBinder {public function getDevice(string $extension):?array {return $extension==='990'?null:['id'=>$extension];}};
$valid=Delivery::normalize(array_replace($packet,['extension'=>'991']));
$mixed=(new Delivery($receiver,$mixedBinder,'controller-peer-entry'))->planLocal([$packet,$valid]);
$expect($mixed['ok'] && count($mixed['skipped'])===1 && array_column($mixed['core_targets'],'extension')===['991'],'One missing account must not block a valid sibling');
$expect($plan['plan_hash']!==$blocked['plan_hash'],'Account eligibility must be bound into the reviewed plan');
$expect($plan['receiver_site_key']==='controller-peer-entry','Receiver must acknowledge the exact mapping');
$receiver->cache=$plan['cache_after'];
$expect(Delivery::resolveCached($receiver,1,'990')['short_name']==='r.example','Persisted received projection must resolve through explicit mapping');
$oldHash=$plan['plan_hash'];
$other=(new Delivery($receiver,$binder))->planLocal([$packet]);
$expect($other['plan_hash']!==$oldHash,'The reviewed plan must bind its receiver mapping');
$reject(fn()=>(new Delivery($receiver,$binder,'changed-peer-entry'))->planLocal([$packet]));
$reject(fn()=>(new Delivery($receiver,$binder,'controller'))->planLocal([$packet]));
$row=$logical->findBySiteExtension(1,'990');
$logical->setIdentityBinding((int)$row['id'],'manual_shared',null,null,['display_name'=>'Local Desk','label'=>'Local']);
$expect(Delivery::resolveCached($receiver,1,'990')===null,'Received mapping must preserve local identity authority');
$plan=$service->planLocal([$packet]);
$expect(!$plan['core_targets'] && count($plan['excluded'])===1,'Mapped delivery must not write over a locally owned identity');
$db->exec("DELETE FROM ucmp_extensions WHERE extension='990'");
$receiver->cache['entries']['controller-peer-entry:990']=Delivery::normalize(array_replace($packet,['released'=>true,'extension_revision'=>4]));
$expect(Delivery::resolveCached($receiver,1,'990')===null,'Mapped releases must suppress stale identities');
$receiver->cache['entries']['controller-peer-entry:990']=$packet;
$receiver->cache['receiver_aliases']['other-controller']='other-peer-entry';
$receiver->cache['entries']['other-peer-entry:990']=Delivery::normalize(array_replace($packet,['identity_site_key'=>'other-peer-entry','origin_site_key'=>'other-controller']));
$reject(fn()=>Delivery::resolveCached($receiver,1,'990'));
echo "Receiver site mapping, owner targeting, releases, conflicts and local ownership passed\n";
