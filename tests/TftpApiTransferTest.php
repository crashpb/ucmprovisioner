<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\{TftpStore,TftpSyncService,ManagedAssetRegistry};

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $run, string $message): void {
    try { $run(); } catch (RuntimeException|InvalidArgumentException $expected) { return; }
    throw new LogicException($message);
};

/** Real temporary files exercise bounded seek/write behavior without any network or production state. */
final class TransferStorageFixture
{
    public int $largestRead = 0;
    public ?string $failActivation = null;
    public bool $failRestore = false;
    public ?string $fakeLink = null;
    public bool $corruptStream = false;
    public function __construct(public string $directory) { if (!is_dir($directory . '/tftpboot')) { mkdir($directory . '/tftpboot',0770,true); } }
    private function path(string $p): string { return $this->directory . $p; }
    public function clearStatCache(): void { clearstatcache(); }
    public function file_exists(string $p): bool { return file_exists($this->path($p)); }
    public function is_dir(string $p): bool { return is_dir($this->path($p)); }
    public function stat(string $p): array|false { return $this->lstat($p); }
    public function lstat(string $p): array|false {
        if ($p === $this->fakeLink) { return ['type'=>3,'size'=>4]; }
        clearstatcache();$stat=@lstat($this->path($p));
        return $stat===false?false:['size'=>$stat['size'],'mtime'=>$stat['mtime'],'type'=>is_link($this->path($p))?3:(is_dir($this->path($p))?2:1)];
    }
    public function mkdir(string $p,int $mode=-1,bool $recursive=false): bool { return @mkdir($this->path($p),$mode<0?0770:$mode,$recursive); }
    public function rmdir(string $p): bool { return rmdir($this->path($p)); }
    public function chmod(int $mode,string $p): bool { return chmod($this->path($p),$mode); }
    public function delete(string $p): bool { return unlink($this->path($p)); }
    public function rename(string $from,string $to): bool {
        if ($to===$this->failActivation && str_contains($from,'/stage/')) { $this->failActivation=null;return false; }
        if ($this->failRestore && str_contains($from,'/backup/')) { return false; }
        return rename($this->path($from),$this->path($to));
    }
    public function posix_rename(string $from,string $to): bool { return $this->rename($from,$to); }
    public function get(string $p,mixed $local=false,int $offset=0,int $length=-1): string|bool {
        if (is_string($local)) { return copy($this->path($p),$local); }
        if (is_resource($local)) { $input=fopen($this->path($p),'rb');try{fseek($input,$offset);return stream_copy_to_stream($input,$local,$length)>=0;}finally{fclose($input);} }
        $bytes=file_get_contents($this->path($p),false,null,$offset,$length<0?null:$length);
        if (is_string($bytes)) { $this->largestRead=max($this->largestRead,strlen($bytes)); }
        return $bytes;
    }
    public function put(string $p,mixed $content,int $mode=2,int $offset=-1): bool {
        $h=fopen($this->path($p),$offset<0?'wb':'c+b');if (!$h) { return false; }
        try { if($offset>=0)fseek($h,$offset);if(is_resource($content)){$ok=stream_copy_to_stream($content,$h)===(int)fstat($content)['size'];if($this->corruptStream){fseek($h,0);fwrite($h,'!');}return $ok;}return fwrite($h,$content)===strlen($content); } finally { fclose($h); }
    }
}

final class TransferConnectionFixture
{
    public function __construct(public TransferStorageFixture $storage) {}
    public function dockerSftp(): TransferStorageFixture { return $this->storage; }
    public function tftpRoot(): string { return '/tftpboot'; }
}

final class TransferModuleFixture
{
    public PDO $db;
    public array $stores=[],$connections=[],$channels=[],$calls=[];
    public bool $breakApiAfterPrepare=false;
    public string $commitFailure='';
    public array $firmwareScans=[];
    public bool $batchCapable=false;
    public bool $streamCapable=false;
    public bool $resumeCapable=false;
    public ?int $interruptRangeAt=null;
    public array $settings=[],$uploadOffsets=[];
    public function getSetting(string $key,?int $site=null,$default=null){return $this->settings[$site][$key]??$default;}
    public function setSetting(string $key,$value,?int $site=null):void{$this->settings[$site][$key]=$value;}
    public bool $oldSource=false;
    public int $sourceSnapshots=0,$fileRequests=0;
    public array $progress=[];
    public function replicationTransferCapabilities(int $site): array { return ($this->batchCapable?['upload_batch_bytes'=>1048576,'upload_batch_chunks'=>64]:[])+['file_stream'=>$this->streamCapable&&!($site===1&&$this->oldSource)?1:0,'file_resume_bytes'=>$this->resumeCapable?16777216:0]; }
    public function replicationSourceFile(int $site,array $file,string $transport) { $this->sourceSnapshots++;return $this->stores[$site]->transferSourceFile($site,$file); }
    public function replicationUploadFile(int $site,string $id,array $file,$stream,?callable $progress=null,?array $range=null):array {
        if($range!==null){
            $this->uploadOffsets[]=$range['offset'];
            if($range['offset']===$this->interruptRangeAt){$this->interruptRangeAt=null;throw new RuntimeException('fixture disconnected before next range');}
            $part=tmpfile();fseek($stream,$range['offset']);stream_copy_to_stream($stream,$part,$range['length']);rewind($part);
            try{return $this->stores[$site]->transfer($site,['op'=>'upload_file','transfer_id'=>$id,'path'=>$file['path'],'stream'=>$part]+$range);}finally{fclose($part);}
        }
        $this->fileRequests++;if($progress)$progress((int)$file['size']);
        return $this->stores[$site]->transfer($site,['op'=>'upload_file','transfer_id'=>$id,'path'=>$file['path'],'stream'=>$stream]);
    }
    public function replicationProgress(int $site,string $stage,string $message,?array $result=null):void { $this->progress[]=[$site,$stage,$message,$result]; }
    public function __construct() {
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        ManagedAssetRegistry::ensureSchema($this->db);
    }
    public function getDb(): PDO { return $this->db; }
    public function replicateTftpPaths(int $source,array $targets,array $paths,bool $overwrite): array { return (new TftpSyncService($this))->copy($source,$targets,$paths,$overwrite); }
    public function tftpOp(array $request): array {
        if ($request['op']==='firmware_inventory') { return ['ok'=>true,'bundles'=>[['profile'=>'fixture','load_name'=>'fixture-load','status'=>'complete','files'=>[['path'=>'another.bin']]]]]; }
        if ($request['op']==='firmware_rescan') { return $this->firmwareScans[(int)$request['site_id']] ?? ['ok'=>true,'bundles'=>[['profile'=>'fixture','load_name'=>'fixture-load','status'=>'complete']]]; }
        throw new RuntimeException('Unexpected fixture operation');
    }
    public function siteConnection(int $id): TransferConnectionFixture { return $this->connections[$id]; }
    public function replicationTransport(int $id): string { $this->calls[]=['select',$id];return $this->channels[$id]??'api'; }
    public function replicationTransfer(int $id,array $request,string $channel): array {
        $this->calls[]=[$request['op'],$id,$channel];
        if($request['op']==='commit' && $this->commitFailure==='unknown')throw new RuntimeException('fixture lost local commit result');
        if($request['op']==='commit' && $this->commitFailure==='returned')return ['ok'=>false,'copied'=>1,'skipped'=>2,'status'=>'unknown','recovery_required'=>true,'failed_stage'=>'registration','message'=>'fixture returned failure'];
        if($request['op']==='commit' && $this->commitFailure==='completed')throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('fixture completed failure',[
            'state'=>'completed','operation_id'=>str_repeat('a',32),'result'=>['ok'=>false,'copied'=>1,'skipped'=>2,'recovery_required'=>true,'message'=>'fixture finalization failed'],
        ]);
        if($this->breakApiAfterPrepare && $request['op']==='upload')throw new RuntimeException('fixture lost API connection');
        return $this->stores[$id]->transfer($id,$request);
    }
}

if (($argv[1] ?? '') === '--hold-lock') {
    $holder = new TransferModuleFixture();
    $holder->connections[1] = new TransferConnectionFixture(new TransferStorageFixture($argv[2]));
    (new TftpStore($holder))->withSiteLock(1, static function () use ($argv): void {
        file_put_contents($argv[2] . '/ready', 'ready'); usleep(750000);
    });
    exit(0);
}

$temporary=sys_get_temp_dir().'/ucmp-api-transfer-'.bin2hex(random_bytes(8));
mkdir($temporary,0770,true);
try {
    $module=new TransferModuleFixture();
    foreach([1,2,3] as $site) {
        $module->connections[$site]=new TransferConnectionFixture(new TransferStorageFixture($temporary.'/'.$site));
        $module->stores[$site]=new TftpStore($module);
    }
    $source=$module->connections[1]->storage;$destination=$module->connections[2]->storage;
    // Above the old hosted-media limit, with every network chunk bounded independently.
    $source->put('/tftpboot/package.bin',str_repeat('firmware-fixture',650000));
    $source->put('/tftpboot/empty.bin','');
    $source->put('/tftpboot/another.bin','new file');
    $destination->put('/tftpboot/package.bin','previous package');
    $sync=new TftpSyncService($module);
    $result=$sync->copy(1,[2],['package.bin','empty.bin'],true);
    $expect(!empty($result['ok']),json_encode($result));
    $expect($source->largestRead<=262144 && $destination->largestRead<=262144,'A whole firmware file was loaded into a network response');
    $expect(hash_file('sha256',$temporary.'/1/tftpboot/package.bin')===hash_file('sha256',$temporary.'/2/tftpboot/package.bin'),'API copy differs');
    $expect($module->stores[2]->siteLockStatus(2)['state']==='unlocked','Successful destination commit left a lock');
    $expect((int)$module->db->query('SELECT COUNT(*) FROM ucmp_assets WHERE site_id=2')->fetchColumn()===2,'Destination inventory was not registered locally');
    $id=$result['sites']['2']['recovery_id'];
    $again=$module->stores[2]->transfer(2,['op'=>'commit','transfer_id'=>$id]);
    $expect(!empty($again['ok']),'A repeated acknowledged commit was not idempotent');
    $destination->fakeLink='/tftpboot/package.bin';
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'commit','transfer_id'=>$id]),'Committed replay accepted a replaced symlink');
    $destination->fakeLink=null;

    $module->channels[3]='sftp';
    $fallback=$sync->copy(1,[3],['package.bin'],true);
    $expect(!empty($fallback['ok']) && $fallback['sites']['3']['transport']==='sftp','SFTP staging backup failed');
    $expect(hash_file('sha256',$temporary.'/1/tftpboot/package.bin')===hash_file('sha256',$temporary.'/3/tftpboot/package.bin'),'Backup copy differs');
    $expect(!array_filter($module->calls,static fn(array $c): bool=>$c[0]==='upload'&&$c[1]===3),'Backup bytes were sent through the API');

    $preserve=$sync->copy(1,[2],['package.bin'],false);
    $expect(!empty($preserve['ok']) && $preserve['sites']['2']['skipped']===1,'Equal hashes should skip writes');
    $module->batchCapable=true;$module->calls=[];$module->progress=[];$small=[];
    for($n=0;$n<65;$n++) { $path='batch-'.$n.'.bin';$small[]=$path;$source->put('/tftpboot/'.$path,str_repeat(chr(65+$n%26),1024)); }
    $bulk=$sync->copy(1,[2],$small,true);
    $uploads=array_values(array_filter($module->calls,static fn(array $call):bool=>$call[0]==='upload_batch'));
    $expect(!empty($bulk['ok']) && count($uploads)===2 && $bulk['sites'][2]['copied']===65,'65 small files must use two bounded API batches, not 65 RPCs');
    $expect(count(array_filter($module->progress,static fn(array $p):bool=>$p[1]==='Transferring files'))===2,'Acknowledged batch bytes must publish intermediate progress');
    $destination->put('/tftpboot/package.bin','old firmware');$module->calls=[];
    $bulkFirmware=$sync->copy(1,[2],['package.bin'],true);
    $expect(!empty($bulkFirmware['ok']) && count(array_filter($module->calls,static fn(array $call):bool=>$call[0]==='upload_batch'))===10,'Large firmware must use bounded 1 MiB batches');
    $module->batchCapable=false;

    if (\FreePBX\modules\Ucmprovisioner\ApiFileStream::available()) {
        $module->batchCapable=true;$module->streamCapable=true;$module->channels[3]='api';$module->calls=[];
        $source->put('/tftpboot/stream-fw.bin',str_repeat('binary-file',300000));$source->put('/tftpboot/stream-small.bin','small');
        $streamed=$sync->copy(1,[2,3],['stream-fw.bin','stream-small.bin'],true);
        $expect(!empty($streamed['ok']) && $module->fileRequests===2 && $module->sourceSnapshots===1,'Each destination needs one whole-file request; source snapshot must be reused');
        $expect(count(array_filter($module->calls,static fn(array $c):bool=>$c[0]==='upload_batch'))===2,'Small companions retain efficient batches');
        foreach([2,3] as $site){$expect(hash_file('sha256',$temporary.'/1/tftpboot/stream-fw.bin')===hash_file('sha256',$temporary.'/'.$site.'/tftpboot/stream-fw.bin'),'Binary staging must publish identical bytes');}
        $source->put('/tftpboot/stream-fw.bin',str_repeat('changed-file',300000));$before=file_get_contents($temporary.'/2/tftpboot/stream-fw.bin');$destination->corruptStream=true;
        $bad=$sync->copy(1,[2],['stream-fw.bin'],true);
        $expect(empty($bad['ok']) && file_get_contents($temporary.'/2/tftpboot/stream-fw.bin')===$before,'Destination hash must refuse a damaged staged file before publication');
        $destination->corruptStream=false;
        $module->oldSource=true;$module->channels[1]='api';$beforeRequests=$module->fileRequests;
        $legacySource=$sync->copy(1,[3],['stream-fw.bin'],true);
        $expect(!empty($legacySource['ok']) && $module->fileRequests===$beforeRequests,'An older remote API source must keep compatible chunks even when the receiver supports streams');
        $module->oldSource=false;$module->channels[1]='local';$module->streamCapable=false;$module->batchCapable=false;
    }

    $collision=$temporary.'/collision';$other=new TransferModuleFixture();
    $other->connections[1]=new TransferConnectionFixture(new TransferStorageFixture($collision));
    $process=proc_open([PHP_BINARY,__FILE__,'--hold-lock',$collision],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $expect(is_resource($process),'Could not launch independent writer fixture');fclose($pipes[0]);
    try {
        $deadline=microtime(true)+5;while(!is_file($collision.'/ready')&&microtime(true)<$deadline){usleep(20000);clearstatcache();}
        $expect(is_file($collision.'/ready'),'Independent writer never acquired its lock');
        $waits=0;$writes=0;$otherStore=new TftpStore($other);
        $contended=\FreePBX\modules\Ucmprovisioner\StorageBusyRetry::run(
            function()use($otherStore,&$writes):array{return $otherStore->withSiteLock(1,function()use(&$writes):array{$writes++;return ['ok'=>true];});},
            'tftpTransferLocal',['op'=>'upload'],function()use(&$waits):void{$waits++;},3000);
        $expect($contended['ok'] && $waits>0 && $writes===1,'Concurrent writer must wait, then execute once after natural lock release');
        $expect($otherStore->siteLockStatus(1)['state']==='unlocked','Contention handling left an owned lock');
    } finally { $childOut=stream_get_contents($pipes[1]);$childErr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process); }
    $expect($exit===0,'Independent writer failed: '.$childOut.$childErr);
    $destination->put('/tftpboot/another.bin','destination edit');
    $compact=\FreePBX\modules\Ucmprovisioner\AssetFilename::content('r','source ringtone','raw');
    $source->put('/tftpboot/'.$compact,'source ringtone');
    $destination->put('/tftpboot/'.$compact,'different existing ringtone');
    $collisionResult=$sync->copy(1,[2],[$compact],true);
    $expect(empty($collisionResult['ok']) && $destination->get('/tftpboot/'.$compact)==='different existing ringtone','Overwrite must not bypass the compact filename collision guard');
    $conflict=$sync->copy(1,[2],['another.bin'],false);
    $expect(empty($conflict['ok']) && $destination->get('/tftpboot/another.bin')==='destination edit','Overwrite protection failed');

    $manifest=$module->stores[1]->transfer(1,['op'=>'manifest','paths'=>['another.bin']])['files'];
    $newId=static fn(): string=>gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8));
    $prepare=function(string $id)use($module,$manifest):array{return $module->stores[2]->transfer(2,['op'=>'prepare','transfer_id'=>$id,'files'=>$manifest,'overwrite'=>true,'transport'=>'api']);};
    $id=$newId();$prepare($id);
    $batchRequest=['op'=>'upload_batch','transfer_id'=>$id,'chunks'=>[
        ['path'=>'another.bin','offset'=>0,'content'=>base64_encode('new file')],
        ['path'=>'outside.bin','offset'=>0,'content'=>base64_encode('x')],
    ]];
    $partialBatch=$module->stores[2]->transfer(2,$batchRequest);
    $expect(empty($partialBatch['ok']) && $partialBatch['staged_chunks']===1 && $partialBatch['staged_bytes']===8 && !$partialBatch['publication_completed'],'A partially staged batch must preserve exact acknowledged work and never publish');
    $expect($destination->get('/tftpboot/another.bin')==='destination edit','Failed staging changed the live file');
    $batchRequest['chunks']=array_fill(0,65,['path'=>'another.bin','offset'=>0,'content'=>base64_encode('x')]);
    $reject(fn()=>$module->stores[2]->transfer(2,$batchRequest),'Unbounded batch count accepted');
    $envelope=\FreePBX\modules\Ucmprovisioner\ApiCodec::seal(['payload'=>['method'=>'tftpTransferLocal','args'=>[['op'=>'upload_batch','transfer_id'=>$id,'chunks'=>array_fill(0,4,['path'=>str_repeat('a',512),'offset'=>0,'content'=>base64_encode(str_repeat('x',262144))])]]]],str_repeat('a',64));
    $expect(strlen($envelope)<\FreePBX\modules\Ucmprovisioner\ApiCodec::MAX_ENVELOPE_BYTES,'Negotiated batch does not fit the authenticated API envelope');
    $id=$newId();$prepare($id);
    $upload=['op'=>'upload','transfer_id'=>$id,'path'=>'another.bin','offset'=>0,'content'=>base64_encode('new file')];
    $module->stores[2]->transfer(2,$upload);$module->stores[2]->transfer(2,$upload);
    $reject(fn()=>$module->stores[2]->transfer(2,array_replace($upload,['content'=>base64_encode('bad file')])),'Different repeated chunk accepted');
    $destination->put('/tftpboot/another.bin','newer external edit');
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'commit','transfer_id'=>$id]),'Destination revision race accepted');
    $expect($destination->get('/tftpboot/another.bin')==='newer external edit','Race damaged destination');

    $id=$newId();$prepare($id);
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'upload','transfer_id'=>$id,'path'=>'another.bin','offset'=>1,'content'=>base64_encode('new')]),'Out-of-order chunk accepted');
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'commit','transfer_id'=>$id]),'Incomplete payload published');
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'prepare','transfer_id'=>$id,'files'=>$manifest,'overwrite'=>true,'transport'=>'sftp']),'Transfer transport switched in flight');
    $corrupt=array_replace($upload,['transfer_id'=>$id,'content'=>base64_encode('bad file')]);
    $module->stores[2]->transfer(2,$corrupt);
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'commit','transfer_id'=>$id]),'Full-length corrupted payload published');
    foreach(['package.bin', [['path'=>'package.bin']], [true], array_fill(0,501,'package.bin')] as $badPaths) {
        $reject(fn()=>$module->stores[1]->transfer(1,['op'=>'manifest','paths'=>$badPaths]),'Malformed or oversized path list accepted');
    }
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'indexes','ringtones'=>[true,false,true]]),'Unbounded ringtone index request accepted');
    foreach(['../file.bin','/package.bin','.ucmprovisioner/write-lock/owner.json','SEP001122334455.cnf.xml'] as $unsafe) {
        $reject(fn()=>$module->stores[1]->transfer(1,['op'=>'manifest','paths'=>[$unsafe]]),'Unsafe source path exported');
    }
    $source->fakeLink='/tftpboot/link';
    $reject(fn()=>$module->stores[1]->transfer(1,['op'=>'manifest','paths'=>['link/file.bin']]),'Symlink escaped transfer root');
    $source->fakeLink=null;

    $module->streamCapable=true;$module->resumeCapable=true;$module->oldSource=false;
    $source->put('/tftpboot/resume.bin',str_repeat('r',33554433));
    $module->interruptRangeAt=16777216;$module->uploadOffsets=[];
    $interrupted=(new TftpSyncService($module))->copy(1,[2],['resume.bin'],true);
    $expect(empty($interrupted['ok']) && ($interrupted['sites']['2']['staged_bytes']??0)===16777216,'Interrupted run must report only confirmed ranges');
    $resumeId=$interrupted['sites']['2']['recovery_id'];$module->uploadOffsets=[];
    $resumed=(new TftpSyncService($module))->copy(1,[2],['resume.bin'],true);
    $expect(!empty($resumed['ok']) && $resumed['sites']['2']['recovery_id']===$resumeId,'A new invocation must resume the same receiving transaction');
    $expect($module->uploadOffsets===[16777216,33554432],'Resumption retransmitted a confirmed prefix');
    $expect(hash_file('sha256',$temporary.'/1/tftpboot/resume.bin')===hash_file('sha256',$temporary.'/2/tftpboot/resume.bin'),'Resumed publication differs from the source');
    $module->uploadOffsets=[];(new TftpSyncService($module))->copy(1,[2],['resume.bin'],true);
    $expect($module->uploadOffsets===[],'Completed files must be skipped on the next run');

    $source->put('/tftpboot/ranges.bin','abcdefghijkl');
    $rangeManifest=$module->stores[1]->transfer(1,['op'=>'manifest','paths'=>['ranges.bin']])['files'];
    $rangeId='20260919T000000Z-'.bin2hex(random_bytes(8));
    $rangePrepare=['op'=>'prepare','transfer_id'=>$rangeId,'files'=>$rangeManifest,'overwrite'=>true,'transport'=>'api','file_stream'=>1,'file_resume'=>1];
    $module->stores[2]->transfer(2,$rangePrepare);
    $part=tmpfile();fwrite($part,'abcdef');rewind($part);
    $partRequest=['op'=>'upload_file','transfer_id'=>$rangeId,'path'=>'ranges.bin','offset'=>0,'length'=>6,'stream'=>$part];
    $first=$module->stores[2]->transfer(2,$partRequest);$repeat=$module->stores[2]->transfer(2,$partRequest);
    $expect($first===$repeat,'Lost acknowledgement must permit exact range replay without duplicate bytes');
    rewind($part);fwrite($part,'xxxxxx');rewind($part);
    $reject(fn()=>$module->stores[2]->transfer(2,$partRequest),'Different bytes reused a confirmed range');
    rewind($part);fwrite($part,'ghijkl');rewind($part);$partRequest['offset']=6;
    $destination->put('/tftpboot/.ucmprovisioner/copies/'.$rangeId.'/stage/ranges.bin','ghi',2,6);
    $module->stores[2]->transfer(2,$partRequest);fclose($part);
    $expect($module->stores[2]->transfer(2,$rangePrepare)['offsets']['ranges.bin']===12,'Partial private write was not safely completed');
    $destination->put('/tftpboot/.ucmprovisioner/copies/'.$rangeId.'/stage/ranges.bin','!',2,0);
    $reject(fn()=>$module->stores[2]->transfer(2,['op'=>'commit','transfer_id'=>$rangeId]),'Resumed corrupt staging bypassed the final whole-file hash');
    $module->streamCapable=false;$module->resumeCapable=false;

    $module->firmwareScans[2]=['ok'=>false,'message'=>'fixture inventory unavailable'];
    $verifiedCopy=$module->stores[1]->handle(['op'=>'sync_firmware_bundle','site_id'=>1,'profile'=>'fixture','load_name'=>'fixture-load','target_site_ids'=>[2,3],'overwrite'=>true]);
    $expect(empty($verifiedCopy['ok']) && empty($verifiedCopy['sites'][2]['ok']) && !empty($verifiedCopy['sites'][3]['ok']),
        'A failed destination rescan must fail only that destination after copying: '.json_encode($verifiedCopy));
    $expect(($verifiedCopy['sites'][2]['copied'] ?? 0)===1 && !empty($verifiedCopy['sites'][2]['inventory']) && $destination->get('/tftpboot/another.bin')==='new file',
        'Verification failure must retain copied counts, bytes and the rescan result');
    $module->firmwareScans[2]=['ok'=>true,'bundles'=>[]];
    $missingBundle=$module->stores[1]->handle(['op'=>'sync_firmware_bundle','site_id'=>1,'profile'=>'fixture','load_name'=>'fixture-load','target_site_ids'=>[2],'overwrite'=>true]);
    $expect(empty($missingBundle['ok']), 'A successful scan without the requested complete bundle cannot verify the transfer');
    $module->firmwareScans=[];

    $private='.ucmprovisioner/timezone-bundles/'.str_repeat('a',64).'/'.str_repeat('b',64).'.blob';
    $source->mkdir(dirname('/tftpboot/'.$private),0770,true);$source->put('/tftpboot/'.$private,'retained timezone fixture');
    $retained=$sync->copy(1,[3],[$private],true);
    $expect(!empty($retained['ok']) && $module->connections[3]->storage->get('/tftpboot/'.$private)==='retained timezone fixture','Allowed retained bundle was not copied');

    $destination->put('/tftpboot/another.bin','old a');$destination->put('/tftpboot/empty.bin','old b');
    $destination->failActivation='/tftpboot/empty.bin';
    $failed=$sync->copy(1,[2],['another.bin','empty.bin'],true);
    $expect(empty($failed['ok']) && $destination->get('/tftpboot/another.bin')==='old a' && $destination->get('/tftpboot/empty.bin')==='old b','Partial activation was not rolled back');
    $expect($module->stores[2]->siteLockStatus(2)['state']==='unlocked','Verified rollback left a lock');

    $destination->failActivation='/tftpboot/empty.bin';$destination->failRestore=true;
    $failed=$sync->copy(1,[2],['another.bin','empty.bin'],true);
    $expect(empty($failed['ok']) && !empty($failed['sites']['2']['recovery_required']),'Failed compensation lost recovery status');
    $expect($module->stores[2]->siteLockStatus(2)['state']==='locked','Unresolved publication lock was released');
    $unrelated=$sync->copy(1,[2,3],['another.bin'],true);
    $expect(empty($unrelated['sites']['2']['ok'])&&!empty($unrelated['sites']['3']['ok']),'One locked target blocked another destination');

    $module->breakApiAfterPrepare=true;$module->channels[3]='api';$module->calls=[];
    $source->put('/tftpboot/another.bin','changed source');$module->calls=[];
    $failed=$sync->copy(1,[3],['another.bin'],true);
    $expect(empty($failed['ok']) && count(array_filter($module->calls,static fn(array $c):bool=>$c[0]==='select'&&$c[1]===3))===1,'A mid-transfer API failure selected another transport');
    $module->breakApiAfterPrepare=false;
    $module->commitFailure='unknown';
    $unknownCommit=$sync->copy(1,[3],['another.bin'],true);
    $expect(!empty($unknownCommit['sites'][3]['recovery_required']),'An untyped commit exception must not be presented as an ordinary retryable failure');
    $module->commitFailure='completed';
    $knownFailure=$sync->copy(1,[3],['another.bin'],true);
    $expect(($knownFailure['sites'][3]['copied']??0)===1 && ($knownFailure['sites'][3]['skipped']??0)===2 && !empty($knownFailure['sites'][3]['recovery_required']),'A completed remote failure lost its destination counts or recovery detail');
    $module->commitFailure='';
    $source->put('/tftpboot/finalize.bin','published fixture');
    $module->commitFailure='returned';
    $returnedFailure=$sync->copy(1,[3],['another.bin'],true);
    $expect(($returnedFailure['sites'][3]['copied']??0)===1 && ($returnedFailure['sites'][3]['skipped']??0)===2 && ($returnedFailure['sites'][3]['failed_stage']??'')==='registration', 'Returned transfer failure lost its structured receipt');
    $module->commitFailure='';
    $module->db->exec("CREATE TRIGGER refuse_registration BEFORE INSERT ON ucmp_assets WHEN NEW.site_id=3 BEGIN SELECT RAISE(ABORT,'fixture registry failure'); END");
    $failed=$sync->copy(1,[3],['finalize.bin'],true);
    $expect(empty($failed['ok']) && !empty($failed['sites']['3']['recovery_required']),'Inventory failure was reported as successful publication');
    $expect($module->connections[3]->storage->get('/tftpboot/finalize.bin')==='published fixture' && $module->stores[3]->siteLockStatus(3)['state']==='locked','Unverified finalization lost published evidence or its recovery lock');
    echo "API/SFTP staged transfer, large-file bounds, destination registration, replay, conflict and recovery assertions passed\n";
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($temporary);
}
