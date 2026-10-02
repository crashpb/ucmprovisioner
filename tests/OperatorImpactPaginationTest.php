<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner {
    // Isolate rendering/runtime reads; the actual operator service consumes real dry-run result shapes.
    final class TemplateEngine {
        public ?array $draft;
        public function __construct($module,?array $draft=null) {$this->draft=$draft;if($draft && $draft['site_id']!==1)throw new \RuntimeException('Draft crossed owner');}
        public function resolvePhone(array $phone):array {
            if((int)$phone['id']===3)throw new \RuntimeException('synthetic unresolved');
            return ['phone'=>['label'=>'Fixture'],'settings'=>['_template_trace'=>(int)$phone['id']===4?[['template_id'=>7,'status'=>'skipped','reason'=>'family mismatch']]:[]],'lines'=>[]];
        }
    }
    final class Provisioner {
        private TemplateEngine $resolver;public static int $previews=0;
        public function __construct($module,$binder,TemplateEngine $resolver){$this->resolver=$resolver;}
        public function rebuild(string $selection,bool $apply,?int $site):array {
            if($apply || $site!==1)throw new \LogicException('Impact must stay owner-only dry-run');self::$previews++;
            $id=(int)$selection;$draft=$this->resolver->draft!==null;$changed=$draft&&$id===1;
            return ['ok'=>true,'plan_hash'=>str_repeat($draft?'a':'b',64),'files'=>[
                ['filename'=>'phone'.$id.'.cfg','role'=>'phone_config','sha256'=>$changed?'after':'before'],
                ['filename'=>'shared.xml','role'=>'softkey_policy','sha256'=>$changed?'after':'before']],
                'manifest'=>['asterisk_glue_sha256'=>$draft&&$id===5?'new-glue':'old-glue'],
                'phones'=>[['id'=>$id,'driver'=>'pjsip','transport'=>'udp','port'=>5060,'accounts'=>[(string)$id]]],
                'glue'=>['raw_config'=>'do-not-expose-this-secret']];
        }
    }
}
namespace {
require_once dirname(__DIR__).'/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\{OperatorWorkflowService,Provisioner};
$expect=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$module=new class {
    public array $phones=[];
    public function __construct(){for($id=1;$id<=205;$id++)$this->phones[]=['id'=>$id,'site_id'=>1,'config_mode'=>$id===2?'manual':'managed'];$this->phones[]=['id'=>999,'site_id'=>2];}
    public function getPhones(array $filters):array{if($filters!==['site_id'=>1])throw new LogicException('Unscoped inventory');return $this->phones;}
};
$service=new OperatorWorkflowService($module);$request=['kind'=>'template','id'=>7,'values'=>['name'=>'Draft'],'page_size'=>5];
$first=$service->previewImpact(1,$request);
$expect($first['pagination']['owner_phone_count']===205 && $first['pagination']['next_after_phone_id']===5,'Large owner site is paginated, foreign phone excluded');
$expect($first['counts']===['affected'=>2,'unchanged'=>0,'manual'=>1,'incompatible'=>1,'unresolved'=>1],'Page classifications include bytes, glue, excluded, incompatible and unresolved');
$expect($first['rows'][0]['required_stages']['file_publication'] && $first['rows'][4]['required_stages']['pbx_activation'],'Real preview shape drives file and Asterisk stages');
$expect($first['rows'][0]['required_stages']['device_restart']===null,'Never infer a restart requirement');
$expect($first['shared_files'][0]['filename']==='shared.xml' && $first['shared_files'][0]['referenced_by_phone_ids']===[1,4,5],'Companion references aggregate page consumers');
$expect(!str_contains(json_encode($first),'do-not-expose') && !$first['saved'] && !$first['deployed'],'No raw glue or mutation claims');
$seen=array_column($first['rows'],'phone_id');$page=$first;
while($page['pagination']['next_after_phone_id']!==null){$page=$service->previewImpact(1,$request+['after_phone_id'=>$page['pagination']['next_after_phone_id'],'inventory_hash'=>$first['pagination']['inventory_hash']]);$seen=array_merge($seen,array_column($page['rows'],'phone_id'));}
$expect($seen===range(1,205),'All owner phones enumerate exactly once across pages');
$expect($page['cross_site_consumers']['status']==='not_evaluated' && $page['counts_scope']==='this_page','No fleet-wide or cross-site proof fabricated');
$bounded=$service->previewImpact(1,array_replace($request,['page_size'=>1000]));$expect(count($bounded['rows'])===50,'Caller cannot remove per-request bound');
$module->phones[0]['config_mode']='manual';
try{$service->previewImpact(1,$request+['after_phone_id'=>5,'inventory_hash'=>$first['pagination']['inventory_hash']]);throw new LogicException('Stale inventory accepted');}catch(DomainException $expected){}
echo "Operator owner-only paginated impact, stage projection and companion-reference fixtures passed\n";
}
