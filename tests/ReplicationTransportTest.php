<?php
declare(strict_types=1);
interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
use FreePBX\modules\Ucmprovisioner;
use FreePBX\modules\Ucmprovisioner\{ApiUnavailableException,RpcOperationException};

final class ReplicationConnectionFixture
{
    public string $mode='healthy';
    public array $calls=[],$receipts=[];
    public int $mutations=0;
    public int $busyRemaining=0;
    public array $operationIds=[];
    public int $rangeFailures=0;
    public function execApiFile(array $request,$stream,bool $upload,?callable $progress=null):array {
        if(isset($request['offset'])){
            $this->calls[]=['api','range'];$this->operationIds[]=$request['operation_id'];
            if($this->rangeFailures-->0)throw new RuntimeException('fixture range connection lost');
            return ['ok'=>true,'bytes'=>$request['length'],'offset'=>$request['offset']];
        }
        return $this->execApi($request + ['args'=>[['op'=>'upload_file']]]);
    }
    public function execApi(array $request): array {
        $op=$request['args'][0]['op']??'';$this->calls[]=['api',$op];
        if(isset($request['operation_id']))$this->operationIds[]=$request['operation_id'];
        if($this->busyRemaining-->0)throw new RpcOperationException(\FreePBX\modules\Ucmprovisioner\StorageBusyRetry::LEGACY_MESSAGE,[
            'rpc_version'=>2,'state'=>'failed','operation_id'=>$request['operation_id'],'message'=>\FreePBX\modules\Ucmprovisioner\StorageBusyRetry::LEGACY_MESSAGE]);
        if ($this->mode==='partial') {
            $this->mutations++;
            return \FreePBX\modules\Ucmprovisioner\RpcResponse::unwrap([
                'rpc_version'=>2,'state'=>'completed','operation_id'=>$request['operation_id'],
                'result'=>['ok'=>false,'stages'=>['files_published'=>true,'device_action'=>false],'results'=>[1=>['ok'=>true],2=>['ok'=>false]]],
            ]);
        }
        if($this->mode==='unavailable')throw new ApiUnavailableException('fixture unavailable');
        if($this->mode==='auth')throw new RuntimeException('fixture authentication rejected');
        if($this->mode==='business')throw new RpcOperationException('fixture capability rejected',[]);
        if($op==='capabilities')return ['ok'=>true,'protocol'=>$this->mode==='old'?0:1];
        $this->mutations++;
        $id=$request['operation_id'];
        $this->receipts[$id]=['rpc_version'=>2,'state'=>$this->mode==='unknown'?'running':'completed','operation_id'=>$id];
        if($this->mode!=='unknown')$this->receipts[$id]['result']=['ok'=>true,'committed'=>true];
        if($this->mode==='lost_partial')$this->receipts[$id]['result']=['ok'=>false,'copied'=>1,'recovery_required'=>true,'message'=>'fixture incomplete finalization'];
        if(in_array($this->mode,['lost','unknown','lost_partial'],true))throw new ApiUnavailableException('fixture response lost');
        return ['ok'=>true,'committed'=>true];
    }
    public function execAgent(string $payload): string {
        $request=json_decode(base64_decode($payload),true);$this->calls[]=['ssh',$request['method']];
        if($request['method']==='rpcOperationStatusLocal')return json_encode($this->receipts[$request['args'][0]]);
        if(($request['args'][0]['op']??'')==='capabilities')return json_encode(['ok'=>true,'protocol'=>1]);
        $this->mutations++;return json_encode(['ok'=>true]);
    }
    public function dockerSftp(): object { $this->calls[]=['sftp','connect'];return new stdClass(); }
}
final class ReplicationModuleFixture extends Ucmprovisioner
{
    public function __construct(public ReplicationConnectionFixture $connection) {}
    public function isSiteLocal(int $id): bool { return $id===1; }
    public function getSite(int $id,bool $withSecrets=false): ?array { return ['pbx_api_enabled'=>1,'pbx_api_ready'=>1,'pbx_agent_ready'=>1]; }
    public function siteConnection(int $id) { return $this->connection; }
    public function replicationProgress(int $siteId,string $stage,string $message,?array $result=null,?array $bytes=null):void{}
}
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$connection=new ReplicationConnectionFixture();$module=new ReplicationModuleFixture($connection);
$connection->busyRemaining=2;$connection->calls=[];$connection->operationIds=[];
$busyResult=$module->replicationTransfer(2,['op'=>'upload'],'api');
$expect($busyResult['ok'] && $connection->mutations===1 && count(array_unique($connection->operationIds))===3,'Confirmed legacy pre-write lock refusals must wait and use fresh receipted attempts, then write once');
$connection->mutations=0;$connection->calls=[];
foreach([
    ['state'=>'unknown','result'=>['status'=>'busy','mutation_started'=>false]],
    ['state'=>'completed','result'=>['status'=>'busy','mutation_started'=>true]],
    ['state'=>'completed','result'=>['status'=>'busy','mutation_started'=>false,'recovery_required'=>true]],
    ['state'=>'failed','message'=>\FreePBX\modules\Ucmprovisioner\StorageBusyRetry::LEGACY_MESSAGE,'result'=>['copied'=>1]],
]as$unsafeReceipt){
    $attempts=0;$error=new RpcOperationException('fixture refusal',$unsafeReceipt);
    try { \FreePBX\modules\Ucmprovisioner\StorageBusyRetry::run(function()use(&$attempts,$error):never{$attempts++;throw $error;},'tftpTransferLocal',['op'=>'commit']); }
    catch(RpcOperationException $observed){$expect($observed===$error && $attempts===1,'Uncertain, started, partial or recovery-required work was retried');}
}
$attempts=0;
try { \FreePBX\modules\Ucmprovisioner\StorageBusyRetry::run(function()use(&$attempts):never{$attempts++;throw new RpcOperationException('busy',['status'=>'busy','mutation_started'=>false]);},'tftpTransferLocal',['op'=>'upload'],null,0); }
catch(RpcOperationException $observed){$expect($attempts===1,'Persistent lock wait must be bounded and never remove ownership');}
$expect($module->replicationTransport(1)==='local','Local destination used network transport');
$expect($module->replicationTransport(2)==='api','Healthy API was not selected');
$connection->mode='unavailable';$connection->calls=[];
$expect($module->replicationTransport(2)==='sftp','Read-only API availability failure did not select backup');
$expect($connection->calls===[['api','capabilities'],['ssh','tftpTransferLocal'],['sftp','connect']],'Backup preflight did not verify both enrolled channels');
foreach(['auth','business','old'] as $mode){
    $connection->mode=$mode;$connection->calls=[];$rejected=false;
    try{$module->replicationTransport(2);}catch(RuntimeException $expected){$rejected=true;}
    $expect($rejected && count($connection->calls)===1,'Authenticated/capability rejection downgraded to SFTP');
}
$connection->mode='lost';$connection->calls=[];$connection->mutations=0;
$result=$module->replicationTransfer(2,['op'=>'commit','transfer_id'=>'20260915T000000Z-0123456789abcdef'],'api');
$expect(!empty($result['committed'])&&$connection->mutations===1,'Lost response caused a duplicate commit');
$expect($connection->calls===[['api','commit'],['ssh','rpcOperationStatusLocal']],'Lost acknowledgment did more than reconcile its receipt');
$connection->mode='unknown';$connection->calls=[];$connection->mutations=0;$rejected=false;
try{$module->replicationTransfer(2,['op'=>'commit','transfer_id'=>'20260915T000000Z-0123456789abcdef'],'api');}
catch(RpcOperationException $expected){$rejected=($expected->receipt['state']??'')==='running';}
$expect($rejected&&$connection->mutations===1,'Unknown outcome was replayed or reported successful');
$expect($connection->calls===[['api','commit'],['ssh','rpcOperationStatusLocal']],'Unknown outcome switched transports for execution');
$connection->mode='lost_partial';$connection->calls=[];$connection->mutations=0;$preserved=false;
try{$module->replicationTransfer(2,['op'=>'commit','transfer_id'=>'20260915T000000Z-0123456789abcdef'],'api');}
catch(RpcOperationException $failure){$preserved=($failure->receipt['state']??'')==='completed' && ($failure->receipt['result']['copied']??0)===1;}
$expect($preserved && $connection->mutations===1,'Receipt reconciliation discarded the known completed failure or repeated its mutation');

$stream=tmpfile();fwrite($stream,'fixture');
try {
    foreach(['lost','unknown','lost_partial'] as $mode){
        $connection->mode=$mode;$connection->calls=[];$connection->mutations=0;$outcome=null;
        try{$outcome=$module->replicationUploadFile(2,'20260919T000000Z-0123456789abcdef',['path'=>'firmware.bin','size'=>7],$stream);}
        catch(RpcOperationException $error){$outcome=$error->receipt;}
        $expect($connection->mutations===1 && $connection->calls===[['api','upload_file'],['ssh','rpcOperationStatusLocal']], 'Binary lost response must query its receipt once without retransmission or fallback execution');
        $expect($mode==='lost' ? !empty($outcome['ok']) : ($mode==='unknown' ? ($outcome['state']??'')==='running' : ($outcome['result']['copied']??0)===1), 'Binary reconciliation must retain completed, partial and unknown outcomes');
    }
} finally {fclose($stream);}

$rangeStream=tmpfile();fwrite($rangeStream,'fixture');
try{
    $connection->calls=[];$connection->operationIds=[];$connection->rangeFailures=1;
    $rangeResult=$module->replicationUploadFile(2,'20260919T000000Z-0123456789abcdef',['path'=>'firmware.bin','size'=>7],$rangeStream,null,['offset'=>0,'length'=>7]);
    $expect($rangeResult['ok'] && $connection->calls===[['api','range'],['api','range']] && count(array_unique($connection->operationIds))===2,
        'A range connection loss must retry the idempotent private range over the same API');
    $connection->calls=[];$connection->rangeFailures=9;$stopped=null;
    try{$module->replicationUploadFile(2,'20260919T000000Z-0123456789abcdef',['path'=>'firmware.bin','size'=>7],$rangeStream,null,['offset'=>0,'length'=>7]);}
    catch(RpcOperationException $e){$stopped=$e->receipt;}
    $expect(count($connection->calls)===3 && !empty($stopped['result']['resumable']) && $stopped['result']['publication_completed']===false,
        'Persistent interruption must stop after three attempts and retain a resumable staging outcome');
}finally{fclose($rangeStream);}

foreach (['rebuild','sendPhoneCommands'] as $method) {
    $connection->mode='partial';$connection->calls=[];$connection->mutations=0;
    $result=$module->remoteQuery(2,$method,[]);
    $expect(empty($result['ok']) && !empty($result['stages']['files_published']) && count($result['results'])===2,
        'Completed remote action lost its stage/per-phone details');
    $expect($connection->mutations===1 && count($connection->calls)===1,'Completed partial action was replayed');
}
echo "Replication API preflight, SFTP backup, downgrade rejection and lost-response reconciliation passed\n";

// Exercise the actual dispatcher/journal and the receiving PBX's local-site binding.
$engine=new class {
    public int $writes=0;
    public function transfer(int $site,array $request):array {
        if(($request['op']??'')!=='capabilities')$this->writes++;
        return ['ok'=>true,'site'=>$site,'protocol'=>1];
    }
};
$receiver=new class extends Ucmprovisioner {
    private string $testKey;
    public function __construct() { $this->testKey=bin2hex(random_bytes(32)); }
    public function localSiteId():int { return 7; }
    public function enc(string $plain):string { return \FreePBX\modules\Ucmprovisioner\ApiCodec::seal(['data'=>$plain],$this->testKey); }
    public function dec(string $stored):string { return \FreePBX\modules\Ucmprovisioner\ApiCodec::open($stored,$this->testKey)['data']; }
};
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
(new ReflectionProperty(Ucmprovisioner::class,'db'))->setValue($receiver,$db);
(new ReflectionProperty(Ucmprovisioner::class,'engines'))->setValue($receiver,['TftpStore'=>$engine]);
$dispatch=static fn(array $request):array=>json_decode($receiver->remoteDispatch(base64_encode(json_encode($request))),true,32,JSON_THROW_ON_ERROR);
$rpc=['rpc_version'=>2,'method'=>'tftpTransferLocal','args'=>[['op'=>'capabilities','site_id'=>99]]];
$capabilities=$dispatch($rpc);
$expect(($capabilities['result']['site']??null)===7 && $engine->writes===0,'Dispatcher allowed a site pivot or treated capabilities as a mutation');
$expect((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='ucmp_rpc_operations'")->fetchColumn()===0,'Capability read created a journal');
$rpc['args'][0]['op']='commit';
$missingId=$dispatch($rpc);
$expect(($missingId['ok']??null)===false && $engine->writes===0,'A write without an operation identity reached storage');
$rpc['operation_id']=bin2hex(random_bytes(16));
$legacy=$dispatch(array_replace($rpc,['rpc_version'=>1]));
$expect(($legacy['ok']??null)===false && $engine->writes===0,'Legacy RPC bypassed the transfer journal');
$first=$dispatch($rpc);$second=$dispatch($rpc);
$expect(($first['state']??'')==='completed' && ($second['state']??'')==='completed' && $first['result']===$second['result'] && $engine->writes===1,'Duplicate dispatched transfer mutated twice');
$rpc['args'][0]['op']='prepare';$different=$dispatch($rpc);
$expect(($different['ok']??null)===false && $engine->writes===1,'An operation identity was reused for a different transfer request');
echo "Transfer dispatcher local authority, version guard and duplicate-write suppression passed\n";
