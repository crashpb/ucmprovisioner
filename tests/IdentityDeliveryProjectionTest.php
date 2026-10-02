<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\IdentityDeliveryService as Delivery;
use FreePBX\modules\Ucmprovisioner\OperatorWorkflowService as Operator;

$expect=static function(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$base=['extension'=>'400','origin_site_key'=>'source','identity_site_key'=>'owner','adoption'=>'managed','binding_mode'=>'ad_primary',
    'extension_revision'=>2,'source_id'=>1,'object_key'=>'person','source_revision'=>'accepted','accepted_at'=>1,
    'canonical'=>['display_name'=>'Example Person','label'=>'example'],'sync_name'=>true,'sync_label'=>true];
$a=Delivery::normalize($base);
$expect($a['canonical']['extension']==='400' && $a['canonical']['display_name']==='Example Person','Explicit extension must complete canonical payload');
$b=Delivery::normalize($base+['force'=>true,'received_at'=>123]);
$expect($a['delivery_hash']===$b['delivery_hash'],'Force and receive time must not create semantic drift');
$reaccepted=Delivery::normalize(array_replace($base,['accepted_at'=>99,'source_revision'=>'new-accepted','sync_name'=>false]));
$expect($a['delivery_hash']===$reaccepted['delivery_hash'],'Unchanged relationship/presentation can be reaccepted or change synchronization policy at the same revision');
$released=Delivery::normalize(array_replace($base,['released'=>true,'canonical'=>[],'extension_revision'=>3]));
$expect($released['released'] && $released['extension_revision']===3,'Explicit release supports no person values');
foreach ([array_replace($base,['origin_site_key'=>'']),array_replace($base,['extension_revision'=>0]),array_replace($base,['adoption'=>'legacy_review']),
    array_replace($base,['final_overrides'=>[['field_name'=>'password','mode'=>'final','value_json'=>'"sensitive"']]])] as $invalid) {
    try { Delivery::normalize($invalid);throw new RuntimeException('Accepted invalid identity'); } catch (InvalidArgumentException $expected) {}
}
$module=new class($a) {
    public string $raw;
    public function __construct(array $entry) { $this->raw=json_encode(['schema'=>1,'entries'=>['owner:400'=>$entry]]); }
    public function localSiteId(): int { return 1; }
    public function getSetting(...$args): string { return $this->raw; }
};
$expect(isset(Delivery::cache($module)['entries']['owner:400']),'Validated projection roundtrip');
$module->raw=json_encode(['schema'=>1,'entries'=>['wrong:400'=>$a]]);
try { Delivery::cache($module);throw new RuntimeException('Accepted mismatched cache key'); } catch (RuntimeException $e) { $expect($e->getMessage()!=='Accepted mismatched cache key','Reject key mismatch'); }
$projection=Operator::projectResolved(['phone'=>['label'=>'Visible'],'model'=>['model'=>'Test','password'=>'hidden'],
    'settings'=>['password'=>'secret','_template_trace'=>[['template_id'=>1,'password'=>'secret']],'_setting_trace'=>['password'=>[['source'=>'site','value'=>'secret']]]],
    'lines'=>[['featureId'=>9,'accountIndex'=>1,'name'=>'400','label'=>'Example','displayName'=>'Example Person','secret'=>'secret','authName'=>'hidden']]]);
$encoded=json_encode($projection);
$expect(!str_contains($encoded,'secret') && !str_contains($encoded,'hidden'),'Operator projection excludes credentials');
$expect(Operator::isReadOnly(['op'=>'explain']) && !Operator::isReadOnly(['op'=>'delivery_execute']) && !Operator::isReadOnly(['op'=>'unknown']),'Operation classification fails closed');
echo "Identity delivery projection and operator secret boundary fixtures passed\n";
