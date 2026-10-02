<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/LogicalExtensionService.php';
require_once dirname(__DIR__).'/lib/DirectoryService.php';
use FreePBX\modules\Ucmprovisioner\DirectoryService;
$expect=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);};
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE ucmp_phonebooks (id INTEGER PRIMARY KEY AUTOINCREMENT,book_key TEXT UNIQUE,name TEXT,filename TEXT UNIQUE,title TEXT,include_mode TEXT,rules TEXT,exclusions TEXT,manual_entries TEXT,group_by TEXT,hierarchy TEXT,publish_sites TEXT,name_format TEXT,sort_by TEXT,include_mobile INTEGER,cisco_page_size INTEGER,is_default INTEGER,updated INTEGER)');
$db->exec('CREATE TABLE ucmp_directory_sources (id INTEGER,enabled INTEGER,last_success INTEGER,last_accepted_revision TEXT)');
$db->exec("INSERT INTO ucmp_directory_sources VALUES (1,1,1234,'accepted-one')");
$db->exec('CREATE TABLE ucmp_site_jobs (site_id INTEGER,job_type TEXT,object_key TEXT,desired_hash TEXT,payload_ref TEXT,state TEXT,attempts INTEGER,next_attempt INTEGER,created INTEGER,updated INTEGER,last_error TEXT,UNIQUE(site_id,job_type,object_key,desired_hash))');
$module=new class($db) {
    public array $sites=[['id'=>1,'site_key'=>'owner','name'=>'Owner','enabled'=>1],['id'=>2,'site_key'=>'peer','name'=>'Peer','enabled'=>1]];
    public array $deliveries=[],$refreshes=[];public int $tokens=0;public bool $fail=false;public $afterFirst=null;
    public function __construct(private PDO $db){}
    public function getDb():PDO{return $this->db;}
    public function localSiteId():int{return 1;}
    public function getSites():array{return $this->sites;}
    public function getSite(int $id):array{foreach($this->sites as $site)if($site['id']===$id)return $site;return [];}
    public function getSiteSettingsLocal(int $id):array{return [];}
    public function directoryTokenForSite(array $site):string{$this->tokens++;return 'private-fixture-token';}
    public function importDirectorySnapshot(int $site,array $wire,string $token):array {
        $this->deliveries[]=['site'=>$site,'wire'=>$wire];
        if(count($this->deliveries)===1 && $this->afterFirst)($this->afterFirst)();
        return ['ok'=>!$this->fail,'message'=>'synthetic destination'];
    }
    public function directoryBaseUrl(int $site):string{return 'https://fixture.invalid/'.$site;}
    public function refreshDirectorySourceTokens(int $site,array $catalog):array{$this->refreshes[]=$site;return ['ok'=>true];}
};
$directory=new class($module) extends DirectoryService {
    public array $catalog=[['source_key'=>'safe-contact-key','object_key'=>'synthetic-object','display_name'=>'Synthetic Person','extension'=>'123','label'=>'synthetic']];
    public array $layout=['title'=>'Fixture'];
    public function entries(bool $includeConflicted=true,int $limit=5000):array{return $this->catalog;}
    public function phonebookLayout():array{return $this->layout;}
};
$directory->importPhonebook(['book_key'=>'staff','name'=>'Staff','title'=>'Staff','filename'=>'staff.xml','include_mode'=>'all','group_by'=>'none','publish_sites'=>['*']]);
$module->deliveries=[];$module->refreshes=[];$module->tokens=0;
$plan=$directory->planAcceptedPhonebooks();
$expect(array_keys($plan)===['plan_hash','destinations'] && strlen($plan['plan_hash'])===64,'Public plan contains only hash and fixed destinations');
$expect($module->tokens===0 && !$module->deliveries,'Planning neither mints tokens nor delivers');
$expect(!str_contains(json_encode($plan),'Synthetic Person') && !str_contains(json_encode($plan),'private-fixture-token'),'Public plan excludes employee data and tokens');
$reject=static function(callable $mutate,callable $restore,string $message)use($directory,$module,$plan,$expect):void {
    $mutate();$caught=false;try{$directory->publishAcceptedPhonebooks($plan);}catch(DomainException $e){$caught=true;}
    $expect($caught && !$module->deliveries && $module->tokens===0,$message);$restore();
};
$reject(fn()=>$directory->catalog[0]['display_name']='Changed',fn()=>$directory->catalog[0]['display_name']='Synthetic Person','Changed contact rejects before any delivery');
$reject(fn()=>$directory->layout['title']='Changed',fn()=>$directory->layout['title']='Fixture','Changed layout rejects before any delivery');
$reject(fn()=>$db->exec("UPDATE ucmp_phonebooks SET title='Changed'"),fn()=>$db->exec("UPDATE ucmp_phonebooks SET title='Staff'"),'Changed book definition rejects before any delivery');
$reject(fn()=>$db->exec("UPDATE ucmp_directory_sources SET last_accepted_revision='new'"),fn()=>$db->exec("UPDATE ucmp_directory_sources SET last_accepted_revision='accepted-one'"),'Changed accepted freshness rejects before any delivery');
$reject(fn()=>$module->sites[1]['enabled']=0,fn()=>$module->sites[1]['enabled']=1,'Disabled reviewed destination rejects before any delivery');
$reject(fn()=>$module->sites[1]['site_key']='other',fn()=>$module->sites[1]['site_key']='peer','Rekeyed reviewed destination rejects before any delivery');
$peer=$module->sites[1];$reject(function()use($module){array_pop($module->sites);},function()use($module,$peer){$module->sites[]=$peer;},'Removed reviewed destination rejects before any delivery');
$module->sites[]=['id'=>3,'site_key'=>'new','name'=>'New','enabled'=>1];
$module->afterFirst=function()use($directory,$db):void {
    $directory->catalog[0]['display_name']='Changed during delivery';$directory->layout=['title'=>'Changed during delivery'];
    $db->exec("UPDATE ucmp_directory_sources SET last_success=9999");$db->exec("UPDATE ucmp_phonebooks SET title='Changed during delivery'");
};
$result=$directory->publishAcceptedPhonebooks($plan);
$expect($result['ok_count']===2 && array_column($module->deliveries,'site')===[1,2] && $module->refreshes===[1,2],'Reviewed publication and token refresh never add newly enabled sites');
$expect($module->deliveries[0]['wire']===$module->deliveries[1]['wire'],'Every destination receives one captured contact/book/layout/freshness snapshot despite mutations during delivery');
$expect($module->deliveries[1]['wire'][1]['display_name']==='Synthetic Person','Later destination cannot read changed contacts');
$module->afterFirst=null;$module->deliveries=[];$module->refreshes=[];
$directory->publishAcceptedPhonebooks();
$expect(array_column($module->deliveries,'site')===[1,2,3] && $module->refreshes===[1,2,3],'Default scheduled publication still targets current enabled sites');
$module->fail=true;$module->deliveries=[];$module->refreshes=[];$failedPlan=$directory->planAcceptedPhonebooks();
$first=$directory->publishAcceptedPhonebooks($failedPlan);$second=$directory->publishAcceptedPhonebooks($failedPlan);
$expect($first['queued']===3 && $second['queued']===3 && !$module->refreshes,'Failed fixed publication is queued without false token refresh');
$expect((int)$db->query("SELECT MIN(attempts) FROM ucmp_site_jobs WHERE state='pending'")->fetchColumn()===2,'SQLite failed publication retry upsert retains attempts');
echo "Fixed phonebook publication privacy, snapshot CAS, captured deliveries and queued retry fixtures passed\n";
