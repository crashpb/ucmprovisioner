<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\{OperatorWorkflowService,IdentityDeliveryService};
$expect=static function(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject=static function(callable $operation): void { try { $operation(); } catch (DomainException|InvalidArgumentException $expected) { return; }throw new RuntimeException('Unsafe staged operation accepted'); };
$module=new class {
    private PDO $db;
    public function __construct() { $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);OperatorWorkflowService::ensureSchema($this->db);
        $this->db->exec('CREATE TABLE ucmp_directory_sources(id INTEGER,source_key TEXT,domain TEXT,enabled INTEGER,last_success INTEGER,last_accepted_revision TEXT)');$this->db->exec("INSERT INTO ucmp_directory_sources VALUES(1,'fixture','example.test',1,100,'r1')"); }
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function getSite(int $site): array { return ['id'=>1,'site_key'=>'owner']; }
};
$service=new OperatorWorkflowService($module);$packets=[];
for ($n=0;$n<129;$n++) { $packets[]=IdentityDeliveryService::normalize(['extension'=>(string)(1000+$n),'origin_site_key'=>'controller','identity_site_key'=>'owner','adoption'=>'managed','binding_mode'=>'ad_primary','extension_revision'=>1,'canonical'=>['display_name'=>'Fixture','label'=>'Fixture']])+['force'=>true]; }
$begin=['origin_site_key'=>'controller','total_count'=>129,'content_hash'=>IdentityDeliveryService::hash($packets,true),'_remote_request'=>true];
$input=$service->beginDeliveryInput(1,$begin);$read=new ReflectionMethod(OperatorWorkflowService::class,'deliveryInput');
$reject(fn()=>$read->invoke($service,1,['input_id'=>$input['input_id']]));
$reject(fn()=>$service->appendDeliveryInput(1,['input_id'=>$input['input_id'],'index'=>1,'identities'=>[$packets[128]]]));
$chunk=['input_id'=>$input['input_id'],'index'=>0,'identities'=>array_slice($packets,0,128),'_remote_request'=>true];
$first=$service->appendDeliveryInput(1,$chunk);$again=$service->appendDeliveryInput(1,$chunk);
$expect($first['received_count']===128 && !$first['ready'] && $first===$again,'Identical repeated chunk is idempotent and incomplete input remains nonexecutable');
$tampered=$chunk;$tampered['identities'][0]['force']=false;$reject(fn()=>$service->appendDeliveryInput(1,$tampered));
$last=$service->appendDeliveryInput(1,['input_id'=>$input['input_id'],'index'=>1,'identities'=>[$packets[128]],'_remote_request'=>true]);
$expect($last['ready'] && $read->invoke($service,1,['input_id'=>$input['input_id'],'_remote_request'=>true])===$packets,'All ordered chunks reconstruct the exact declared content before planning');
$reject(fn()=>$service->beginDeliveryInput(1,array_replace($begin,['origin_site_key'=>'owner'])));
$spoof=$packets[0];$spoof['origin_site_key']='owner';$reject(fn()=>$read->invoke($service,1,['identities'=>[$spoof],'_remote_request'=>true]));
$local=$service->beginDeliveryInput(1,['origin_site_key'=>'owner','total_count'=>1,'content_hash'=>str_repeat('a',64)]);
$reject(fn()=>$service->appendDeliveryInput(1,['input_id'=>$local['input_id'],'index'=>0,'identities'=>[$spoof],'_remote_request'=>true]));
$wrong=$service->beginDeliveryInput(1,array_replace($begin,['total_count'=>1,'content_hash'=>str_repeat('0',64)]));
$reject(fn()=>$service->appendDeliveryInput(1,['input_id'=>$wrong['input_id'],'index'=>0,'identities'=>[$packets[0]]]));
$reject(fn()=>$service->beginDeliveryInput(1,array_replace($begin,['total_count'=>5001])));
$secret=$packets[0];$secret['unused_password']='must-not-persist';
$reject(fn()=>$service->appendDeliveryInput(1,['input_id'=>$wrong['input_id'],'index'=>0,'identities'=>[$secret]]));
$expect(!str_contains(implode('',$module->getDb()->query('SELECT plan_json FROM ucmp_operator_changes')->fetchAll(PDO::FETCH_COLUMN)),'must-not-persist'),'Unknown sensitive packet fields never persist');
$sources=$service->handle(['op'=>'identity_sources']);$expect($sources['ok'] && $sources['sources'][0]['source_key']==='fixture' && $sources['sources'][0]['domain']==='example.test' && OperatorWorkflowService::isReadOnly(['op'=>'identity_sources']),'Source selection uses the real schema and remains read-only');
foreach ([['extension_ids'=>[]],['source_id'=>0],['site_id'=>0]] as $scope) { $reject(fn()=>$service->planIdentityRefresh(['scope'=>$scope])); }
$plansBefore=(int)$module->getDb()->query('SELECT COUNT(*) FROM ucmp_operator_changes')->fetchColumn();
$empty=$service->planIdentityRefresh(['scope'=>['targets'=>[]]]);
$expect(!$empty['ok'] && $empty['state']==='selection_required' && $empty['eligible_count']===0 && empty($empty['plan_id']),'Empty selection returns an actionable non-executable result');
$expect($plansBefore===(int)$module->getDb()->query('SELECT COUNT(*) FROM ucmp_operator_changes')->fetchColumn(),'Empty selection must not save a plan');
echo "Staged delivery integrity, interruption, idempotency, origin spoof and scope fixtures passed\n";
