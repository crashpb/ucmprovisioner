<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/ModelCatalog.php';
require_once dirname(__DIR__).'/lib/AssetCatalog.php';
require_once dirname(__DIR__).'/lib/ManagedAssetRegistry.php';
require_once dirname(__DIR__).'/lib/AssetLifecycleService.php';
require_once dirname(__DIR__).'/lib/AssetRetirementService.php';
require_once dirname(__DIR__).'/lib/AssetStorageReferences.php';
use FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry;
use FreePBX\modules\Ucmprovisioner\AssetRetirementService;
use FreePBX\modules\Ucmprovisioner\AssetStorageReferences;
$expect=static function(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);ManagedAssetRegistry::ensureSchema($db);
$db->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY,site_id INTEGER,skey TEXT,sval TEXT)');
$db->exec('CREATE TABLE ucmp_templates (id INTEGER PRIMARY KEY,site_id INTEGER,name TEXT,phonebook_filename TEXT,setting_overrides TEXT)');
$db->exec('CREATE TABLE ucmp_phones (id INTEGER PRIMARY KEY,site_id INTEGER,mac TEXT,extension TEXT,overrides TEXT)');
$db->exec('CREATE TABLE ucmp_phonebooks (id INTEGER PRIMARY KEY,name TEXT,filename TEXT,publish_sites TEXT)');
$db->exec('CREATE TABLE ucmp_site_jobs (id INTEGER PRIMARY KEY,site_id INTEGER,job_type TEXT,object_key TEXT,payload_ref TEXT,state TEXT)');
foreach (['media','firmware','configuration'] as $type) {
    $db->exec('CREATE TABLE ucmp_'.$type.'_'.($type==='configuration'?'sync':'replication').'_jobs (id INTEGER PRIMARY KEY,name TEXT,source_site_id INTEGER,enabled INTEGER,scopes_json TEXT,targets_json TEXT,last_status TEXT)');
}
$db->exec('CREATE TABLE ucmp_configuration_sync_items (id INTEGER PRIMARY KEY,job_id INTEGER,target_site_id INTEGER,object_type TEXT,object_key TEXT,status TEXT,intended_json TEXT)');
$db->exec('ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN packages_json TEXT');
$db->exec('ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN timezone_bundle_key TEXT');
foreach (['firmware','timezones','locales'] as $kind) { $db->exec('ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN include_all_'.$kind.' INTEGER'); }
$registry=new ManagedAssetRegistry($db);
$make=static fn(string $name,string $hash): array=>$registry->upsert(['site_id'=>1,'kind'=>'ringtone','filename'=>$name,'relative_path'=>$name,'content_sha256'=>$hash,'size_bytes'=>160,'profile'=>'cisco_xml:ringtone:raw','bundle_role'=>'payload','validation_status'=>'verified']);
$old=$make('Old.raw',str_repeat('a',64));$new=$make('New.raw',str_repeat('b',64));
$service=new AssetRetirementService($db);$storage=['complete'=>true,'scan_complete'=>true,'revision'=>'storage-1','references'=>[]];
$free=$service->preview(1,$old['asset_id'],$storage);$expect($free['can_retire']&&$free['reclaimable_bytes']===160,'An exact unreferenced verified asset must be eligible for retained quarantine');
$incomplete=$service->preview(1,$old['asset_id'],array_replace($storage,['scan_complete'=>false]));$expect(!$incomplete['can_retire'],'No fresh full scan must block retirement');
$db->exec("INSERT INTO ucmp_settings VALUES (1,1,'ringtone','Old.raw')");
$used=$service->preview(1,$old['asset_id'],$storage);$expect(!$used['can_retire']&&$used['mutable_count']===1,'An assigned asset must be protected and identify its mutable owner');
$db->exec("INSERT INTO ucmp_settings VALUES (2,NULL,'ringtone','Old.raw')");
$global=$service->preview(1,$old['asset_id'],$storage);$expect($global['reference_count']===2&&$global['mutable_count']===1,'Global defaults must remain immutable protected references');
$db->exec('DELETE FROM ucmp_settings WHERE id=2');
$db->exec("INSERT INTO ucmp_site_jobs VALUES (1,1,'build','x','opaque','pending')");
$pending=$service->preview(1,$old['asset_id'],$storage);$expect($pending['reference_count']===2,'Pending opaque jobs must protect referenced deployment state');
$db->exec("UPDATE ucmp_site_jobs SET state='completed'");
$preview=$service->preview(1,$old['asset_id'],$storage,$new['asset_id']);$expect($preview['can_reassign'],'Compatible exact replacement must offer configuration reassignment');
$rejected=false;try {$service->reassign(1,$old['asset_id'],$new['asset_id'],'stale',$storage);}catch(RuntimeException){$rejected=true;}
$expect($rejected&&$db->query('SELECT sval FROM ucmp_settings WHERE id=1')->fetchColumn()==='Old.raw','Stale reassignment must not mutate its owner');
$changed=$service->reassign(1,$old['asset_id'],$new['asset_id'],$preview['revision'],$storage);
$expect($changed['ok']&&$db->query('SELECT sval FROM ucmp_settings WHERE id=1')->fetchColumn()==='New.raw','Exact selected configuration reference must be replaced');
$live=$storage;$live['references'][$old['asset_key']]=[['type'=>'retained_transaction','label'=>'rollback generation','mutable'=>false]];
$expect(!$service->preview(1,$old['asset_id'],$live)['can_retire'],'Retained rollback use must still block after configuration reassignment');
$db->exec('DROP TABLE ucmp_site_jobs');$expect(!$service->preview(1,$old['asset_id'],$storage)['can_retire'],'Missing job schema must fail closed');

$sftp=new class {
    public bool $fail=false;
    public function rawlist(string $path): array|false { return $this->fail?false:['SEPTEST.cnf.xml'=>['type'=>1,'size'=>strlen('<tone>Old.raw</tone>')]]; }
    public function get(...$args): string { return '<tone>Old.raw</tone>'; }
};
$scan=AssetStorageReferences::scan($sftp,'/tftp',[$old]);$expect($scan['complete']&&!empty($scan['references'][$old['asset_key']]),'Live configuration must be indexed without exposing its bytes');
$expect(!str_contains(json_encode($scan),'<tone>'),'Reference response must contain hashes and labels only');
$sftp->fail=true;$expect(!AssetStorageReferences::scan($sftp,'/tftp',[$old])['complete'],'Unreadable storage must fail closed');
echo "Asset retirement reference and CAS fixtures passed\n";

require_once dirname(__DIR__).'/lib/TftpStore.php';
require_once dirname(__DIR__).'/lib/ManagedAssetScanJob.php';
require_once dirname(__DIR__).'/lib/CiscoTimezoneService.php';
require_once dirname(__DIR__).'/lib/CiscoTimezoneBundleManager.php';
require_once __DIR__.'/TimezoneRuntimeFixture.php';
$db->exec('CREATE TABLE ucmp_site_jobs (id INTEGER PRIMARY KEY,site_id INTEGER,job_type TEXT,object_key TEXT,payload_ref TEXT,state TEXT)');
final class RetirementSftpFixture
{
    public array $files=['/tftp/Tone.raw'=>'retained original'];
    public array $dirs=['/tftp'=>true];
    public string $failure='';
    public function file_exists(string $path): bool {return isset($this->files[$path])||isset($this->dirs[$path]);}
    public function is_dir(string $path): bool {return isset($this->dirs[$path]);}
    public function mkdir(string $path,...$args): bool {if($this->file_exists($path))return false;$this->dirs[$path]=true;return true;}
    public function rmdir(string $path): bool {unset($this->dirs[$path]);return true;}
    public function chmod(...$args): bool {return true;}
    public function stat(string $path): array|false {return isset($this->files[$path])?['type'=>1,'size'=>strlen($this->files[$path])]: (isset($this->dirs[$path])?['type'=>2]:false);}
    public function lstat(string $path): array|false {return $this->stat($path);}
    public function put(string $path,string $bytes,...$args): bool {if($this->failure==='receipt'&&str_ends_with($path,'receipt.json'))return false;$this->files[$path]=$bytes;return true;}
    public function get(string $path,mixed $local=false,int $offset=0,int $length=-1): string|bool {
        if(!isset($this->files[$path]))return false;
        if($this->failure==='verification'&&str_ends_with($path,'/payload'))return false;
        $bytes=$length<0?substr($this->files[$path],$offset):substr($this->files[$path],$offset,$length);
        return is_string($local)?file_put_contents($local,$bytes)!==false:$bytes;
    }
    public function delete(string $path): bool {unset($this->files[$path]);return true;}
    public function rename(string $from,string $to): bool {if($this->failure==='move'&&$from==='/tftp/Tone.raw')return false;if(!isset($this->files[$from])||isset($this->files[$to]))return false;$this->files[$to]=$this->files[$from];unset($this->files[$from]);return true;}
    public function rawlist(string $path): array {
        $rows=[];$path=rtrim($path,'/');
        foreach($this->dirs as $name=>$_)if(dirname($name)===$path)$rows[basename($name)]=['type'=>2];
        foreach($this->files as $name=>$bytes)if(dirname($name)===$path)$rows[basename($name)]=['type'=>1,'size'=>strlen($bytes)];
        return $rows;
    }
}
$remote=new RetirementSftpFixture();
$ucm=new class($db,$remote) {
    public function __construct(private PDO $db,private RetirementSftpFixture $sftp){}
    public function getDb(): PDO {return $this->db;}
    public function localSiteId(): int {return 2;}
    public function siteConnection(int $siteId): object {return new class($this->sftp){public function __construct(private RetirementSftpFixture $sftp){}public function dockerSftp(): RetirementSftpFixture{return $this->sftp;}public function tftpRoot(): string{return '/tftp';}};}
};
$store=new FreePBX\modules\Ucmprovisioner\TftpStore($ucm);
$stored=$registry->upsert(['site_id'=>2,'kind'=>'ringtone','filename'=>'Tone.raw','relative_path'=>'Tone.raw','content_sha256'=>hash('sha256','retained original'),'size_bytes'=>strlen('retained original'),'profile'=>'cisco-ringtone','bundle_role'=>'payload','validation_status'=>'verified']);
FreePBX\modules\Ucmprovisioner\ManagedAssetScanJob::ensureSchema($db);
$db->prepare('INSERT INTO ucmp_asset_scan_receipts VALUES (?,?,?)')->execute([2,time(),'["ringtone"]']);
foreach(['receipt','move','verification'] as $failure){
    $preview=$store->handle(['op'=>'asset_retirement_preview','site_id'=>2,'asset_id'=>$stored['asset_id']]);
    $expect(!empty($preview['preview']['can_retire']),'Fresh unreferenced storage fixture must be retirement eligible: '.json_encode($preview));
    $remote->failure=$failure;
    $failed=$store->handle(['op'=>'asset_retire','site_id'=>2,'asset_id'=>$stored['asset_id'],'expected_revision'=>$preview['preview']['revision']]);
    $expect(empty($failed['ok'])&&($remote->files['/tftp/Tone.raw']??'')==='retained original','Failure at '.$failure.' must preserve or restore the exact source');
    $remote->failure='';
}
$preview=$store->handle(['op'=>'asset_retirement_preview','site_id'=>2,'asset_id'=>$stored['asset_id']]);
$retired=$store->handle(['op'=>'asset_retire','site_id'=>2,'asset_id'=>$stored['asset_id'],'expected_revision'=>$preview['preview']['revision']]);
$expect(!empty($retired['ok'])&&!isset($remote->files['/tftp/Tone.raw'])&&$retired['reclaimed_bytes']===0,'Successful retirement must quarantine rather than destroy bytes');
$remote->files['/tftp/Tone.raw']='new unrelated bytes';
$conflict=$store->handle(['op'=>'asset_restore','site_id'=>2,'recovery_id'=>$retired['recovery_id']]);
$expect(empty($conflict['ok'])&&$remote->files['/tftp/Tone.raw']==='new unrelated bytes','Recovery must never overwrite a new file');
unset($remote->files['/tftp/Tone.raw']);
$restored=$store->handle(['op'=>'asset_restore','site_id'=>2,'recovery_id'=>$retired['recovery_id']]);
$expect(!empty($restored['ok'])&&$remote->files['/tftp/Tone.raw']==='retained original','Recovery must restore the exact retained bytes');
$expect($registry->getById(2,$stored['asset_id'])['state']==='current','Verified recovery must restore registry availability');
echo "Asset quarantine failure, no-overwrite recovery and retained-byte fixtures passed\n";

$clock=100;$bundles=new FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager($db,static function()use(&$clock):int{return ++$clock;});$versions=[];
foreach(['2020a','2021a','2022a','2023a'] as $version){
    $payload=timezoneRuntimeFixture('tzupdater.jar','tzdata'.$version);
    $inspection=FreePBX\modules\Ucmprovisioner\CiscoTimezoneService::inspect(['tzupdater.jar'],static fn(string $name):string=>$payload);
    $files=array_values(array_map(static fn(array $file):array=>['name'=>$file['name'],'stored_name'=>$file['sha256'].'.blob','size'=>$file['size'],'sha256'=>$file['sha256']],array_filter($inspection['files'],static fn(array $file):bool=>!empty($file['readable']))));
    $versions[]=$bundles->store(3,$inspection,$files,'.ucmprovisioner/timezone-bundles/'.$inspection['fingerprint']);
}
$firstAsset=FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager::assetsFromBundles([$versions[0]])[0];
$db->prepare('INSERT INTO ucmp_templates VALUES (?,?,?,?,?)')->execute([1,3,'Pinned fixture','',json_encode(['cisco_timezone_by_profile'=>[$firstAsset['profile']=>['asset_key'=>$firstAsset['asset_key']]]])]);
$protection=new ReflectionMethod($store,'protectedTimezoneBundles');
$protected=$protection->invoke($store,3,$bundles);
$expect(in_array($versions[0]['id'],$protected,true)&&count($protected)===1,'Retention must resolve the exact old template pin, not protect unrelated versions by accident');
$secondAsset=FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager::assetsFromBundles([$versions[1]])[0];
$remote->files['/tftp/SEPTEST.cnf.xml']='<tz>'.$secondAsset['published_filename'].'</tz>';
$protected=$protection->invoke($store,3,$bundles);
$expect(in_array($versions[1]['id'],$protected,true),'An already generated phone alias must independently protect its retained bundle');
$bundles->prune(3,3,$protected);
$expect($bundles->get(3,$versions[0]['id'])!==null&&$bundles->get(3,$versions[1]['id'])!==null,'Pruning must preserve pinned and generated-consumer revisions');
echo "Timezone template-pin and generated-alias retention fixtures passed\n";

require_once dirname(__DIR__).'/lib/HostedAssetRetirement.php';
$directory=sys_get_temp_dir().'/ucmp-hosted-retirement-'.bin2hex(random_bytes(6));
mkdir($directory,0700);mkdir($directory.'/.catalogs',0700);mkdir($directory.'/.catalogs/logo',0700);
file_put_contents($directory.'/Logo.bmp','published fixture');
file_put_contents($directory.'/.catalogs/logo/source.png','editable fixture');
file_put_contents($directory.'/.catalogs/logo/manifest.json','{"published_name":"Logo.bmp"}');
file_put_contents($directory.'/.catalogs/logo/untracked.txt','keep the entire old source');
$http=new FreePBX\modules\Ucmprovisioner\HostedAssetRetirement($db,$directory,'cisco_cnf','all');
$external=['complete'=>true,'active_scan'=>false,'references'=>[],'revision'=>'fixture','subject_asset_key'=>$http->asset('Logo.bmp')['asset_key']];
try {
    $preview=$http->preview(4,'Logo.bmp',$external,'logo');
    $expect($preview['can_retire']&&count($preview['dependencies'])===3,'HTTP catalog retirement must include its complete editable source without counting its own manifest as an external consumer');
    $db->prepare('INSERT INTO ucmp_settings VALUES (?,?,?,?)')->execute([4,4,'cnf_logo_url','http://fixture.test/ucmp-m.php/cisco_cnf/all/Logo.bmp']);
    $expect(!$http->preview(4,'Logo.bmp',$external,'logo')['can_retire'],'Saved HTTP logo URLs must protect the exact file');
    $db->exec('DELETE FROM ucmp_settings WHERE id=4');
    mkdir($directory.'/.catalogs/.renamed-older',0700);file_put_contents($directory.'/.catalogs/.renamed-older/manifest.json','{"published_name":"Logo.bmp"}');
    $expect(!$http->preview(4,'Logo.bmp',$external,'logo')['can_retire'],'Retained old-catalog aliases must protect their published HTTP file');
    unlink($directory.'/.catalogs/.renamed-older/manifest.json');rmdir($directory.'/.catalogs/.renamed-older');
    file_put_contents($directory.'/upload.wav.ucmp-part','partial');
    $expect(!$http->preview(4,'Logo.bmp',$external,'logo')['can_retire'],'Pending HTTP transfers must block retirement proof');unlink($directory.'/upload.wav.ucmp-part');
    $asset=$http->asset('Logo.bmp');$live=$external;$live['references'][$asset['asset_key']]=[['type'=>'retained_transaction','label'=>'phone rollback','mutable'=>false]];
    $expect(!$http->preview(4,'Logo.bmp',$live,'logo')['can_retire'],'Generated and rollback TFTP consumers must protect HTTP URLs');
    $preview=$http->preview(4,'Logo.bmp',$external,'logo');
    file_put_contents($directory.'/Logo.bmp','changed fixture');$rejected=false;
    try{$http->retire(4,'Logo.bmp',$external,$preview['revision'],'logo');}catch(RuntimeException){$rejected=true;}
    $expect($rejected&&is_file($directory.'/.catalogs/logo/source.png'),'Stale HTTP content must block before source movement');
    file_put_contents($directory.'/Logo.bmp','published fixture');$preview=$http->preview(4,'Logo.bmp',$external,'logo');
    $retired=$http->retire(4,'Logo.bmp',$external,$preview['revision'],'logo');
    $expect($retired['ok']&&!is_file($directory.'/Logo.bmp')&&!is_dir($directory.'/.catalogs/logo')&&$retired['reclaimed_bytes']===0,'HTTP retirement must retain both published and source bytes privately');
    $expect(is_file($directory.'/.retired-assets/'.$retired['recovery_id'].'/catalog/untracked.txt'),'HTTP quarantine must preserve unexpected source files');
    file_put_contents($directory.'/Logo.bmp','new conflicting file');$rejected=false;
    try{$http->restore(4,$retired['recovery_id']);}catch(RuntimeException){$rejected=true;}
    $expect($rejected&&file_get_contents($directory.'/Logo.bmp')==='new conflicting file','HTTP restore must not overwrite a newly published asset');unlink($directory.'/Logo.bmp');
    $restored=$http->restore(4,$retired['recovery_id']);
    $expect($restored['ok']&&file_get_contents($directory.'/Logo.bmp')==='published fixture'&&is_file($directory.'/.catalogs/logo/source.png'),'HTTP restore must recover exact served and editable content');
    $expect($http->restore(4,$retired['recovery_id'])['ok'],'HTTP restore must be safely repeatable');
    $broken=new FreePBX\modules\Ucmprovisioner\HostedAssetRetirement($db,$directory,'cisco_cnf','all',
        static fn(string $from,string $to):bool=>str_ends_with($to,'/catalog')?rename($from,$to):false);
    $preview=$http->preview(4,'Logo.bmp',$external,'logo');
    $partial=$broken->retire(4,'Logo.bmp',$external,$preview['revision'],'logo');
    $expect(!$partial['ok']&&$partial['recovery_required']&&is_file($directory.'/Logo.bmp')&&!is_dir($directory.'/.catalogs/logo'),
        'Failed catalog compensation must retain the destination lock through recovery_required');
    $expect($http->restore(4,$partial['recovery_id'])['ok'],'Retained catalog must remain recoverable after failed compensation');
    $preview=$http->preview(4,'Logo.bmp',$external,'logo');
    $retired=$http->retire(4,'Logo.bmp',$external,$preview['revision'],'logo');
    $broken=new FreePBX\modules\Ucmprovisioner\HostedAssetRetirement($db,$directory,'cisco_cnf','all',
        static fn(string $from,string $to):bool=>str_ends_with($from,'/payload')?rename($from,$to):false);
    $partial=$broken->restore(4,$retired['recovery_id']);
    $expect(!$partial['ok']&&$partial['recovery_required']&&is_file($directory.'/Logo.bmp')&&!is_dir($directory.'/.catalogs/logo'),
        'Partial restore must preserve explicit recovery state after the public file moved');
    $expect($http->restore(4,$retired['recovery_id'])['ok'],'Partial restore can resume without overwriting restored bytes');
} finally {
    $remove=static function(string $path)use(&$remove):void{if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $entry)if($entry!=='.'&&$entry!=='..')$remove($path.'/'.$entry);rmdir($path);}else unlink($path);};
    $remove($directory);
}
echo "HTTP reference, stale-preview, complete-source quarantine and no-overwrite recovery fixtures passed\n";
