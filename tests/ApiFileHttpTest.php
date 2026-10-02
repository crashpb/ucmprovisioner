<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\{ApiFileStream,SiteConnection};
if (!ApiFileStream::available() || !function_exists('curl_init')) { echo "Binary HTTP fixtures require PHP sodium and curl\n"; return; }
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/ucmp-binary-http-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$server=null;$pipes=[];
try {
    $source=fopen($dir.'/source.bin','w+b');$size=100*1024*1024;
    $block=random_bytes(1024*1024);for($n=0;$n<100;$n++)fwrite($source,$block);unset($block);fflush($source);
    $hash=hash_file('sha256',$dir.'/source.bin');
    $bootstrap= <<<'PHP'
<?php
interface BMO {}
class FreePBX_Helpers {}
require ROOT_PATH . '/vendor/autoload.php';
require ROOT_PATH . '/Ucmprovisioner.class.php';
class BinaryFixtureModule extends \FreePBX\modules\Ucmprovisioner {
 public function __construct() {}
 public function localSiteId():int{return 1;}
 public function getSetting(string $key,?int $siteId=null,$default=null){return $key==='federation_api_enabled'?'1':str_repeat('ab',32);}
 public function dec(string $stored):string{return $stored;}
 public function logOperation(?int $siteId,string $operation,string $level,string $message,string $detail=''):void{}
 public function receiveTftpFileLocal(array $request,$stream):array{
  $out=fopen(FIXTURE_PATH.'/received.bin','wb');try{stream_copy_to_stream($stream,$out);}finally{fclose($out);}
  return \FreePBX\modules\Ucmprovisioner\RpcResponse::completed(['ok'=>true,'transfer_id'=>$request['transfer_id'],'path'=>$request['file']['path'],'bytes'=>filesize(FIXTURE_PATH.'/received.bin')],$request['operation_id']);
 }
 public function openTftpSourceFileLocal(array $file){return fopen(FIXTURE_PATH.'/source.bin','rb');}
 public function remoteDispatch(string $payload):string{return json_encode(['rpc_version'=>2,'state'=>'completed','result'=>['ok'=>true,'probe'=>'ordinary RPC after binary']]);}
}
class FreePBX {public static function Ucmprovisioner(){return new BinaryFixtureModule();}}
PHP;
    $bootstrap=str_replace(['ROOT_PATH','FIXTURE_PATH'],[var_export($root,true),var_export($dir,true)],$bootstrap);
    file_put_contents($dir.'/bootstrap.php',$bootstrap);
    $endpoint=str_replace(["require_once '/etc/freepbx.conf';","'/var/lib/asterisk/ucmprovisioner/api-nonces'"],
        ['require_once '.var_export($dir.'/bootstrap.php',true).';',var_export($dir.'/nonces',true)],file_get_contents($root.'/api.php'));
    file_put_contents($dir.'/ucmprovisioner-api.php',$endpoint);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $command=[PHP_BINARY,'-d','memory_limit=32M'];
    if(PHP_OS_FAMILY==='Windows'){$command[]='-d';$command[]='extension=sodium';}
    array_push($command,'-S','127.0.0.1:'.$port,'-t',$dir);
    $server=proc_open($command,[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$dir,null,['bypass_shell'=>true]);
    if(!is_resource($server))throw new RuntimeException('Cannot start isolated HTTP fixture');fclose($pipes[0]);unset($pipes[0]);
    $ready=false;for($attempt=0;$attempt<50;$attempt++){$probe=@fsockopen('127.0.0.1',$port,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(100000);}
    $expect($ready,'Isolated HTTP fixture did not start');
    $connection=new SiteConnection(['is_local'=>0,'pbx_api_host'=>'127.0.0.1','pbx_api_port'=>$port,'pbx_api_scheme'=>'http','pbx_api_secret'=>str_repeat('ab',32)]);
    $file=['path'=>'firmware.bin','size'=>$size,'sha256'=>$hash];$progress=[];$started=microtime(true);
    $uploaded=$connection->execApiFile(['method'=>'tftp_file_upload','operation_id'=>str_repeat('c',32),'transfer_id'=>'20260919T000000Z-'.str_repeat('d',16),'file'=>$file],$source,true,static function(int $bytes)use(&$progress):void{$progress[]=$bytes;});
    $seconds=microtime(true)-$started;
    $expect(!empty($uploaded['ok'])&&$uploaded['bytes']===$size&&hash_file('sha256',$dir.'/received.bin')===$hash,'Actual HTTP endpoint must receive every byte under its 32 MiB memory limit');
    $expect($progress&&max($progress)===$size,'Upload reports payload progress through cURL');
    $download=tmpfile();$downloaded=$connection->execApiFile(['method'=>'tftp_file_download','file'=>$file],$download,false);
    $digest=hash_init('sha256');hash_update_stream($digest,$download);
    $expect(!empty($downloaded['ok'])&&hash_final($digest)===$hash,'Actual binary HTTP download must round-trip the pinned file');
    fclose($download);
    $rangeOffset=$size-1048583;$rangeLength=1048583;
    $rangeResult=$connection->execApiFile(['method'=>'tftp_file_upload','operation_id'=>str_repeat('e',32),
        'transfer_id'=>'20260919T000000Z-'.str_repeat('d',16),'file'=>$file,'offset'=>$rangeOffset,'length'=>$rangeLength],$source,true);
    $expectedRange=file_get_contents($dir.'/source.bin',false,null,$rangeOffset,$rangeLength);
    $expect($rangeResult['bytes']===$rangeLength && hash_file('sha256',$dir.'/received.bin')===hash('sha256',$expectedRange),
        'Actual HTTP range upload must use its bounded length and exact source offset');
    fclose($source);
    $rpc=$connection->execApi(['method'=>'moduleStatus','args'=>[]]);
    $expect(($rpc['probe']??'')==='ordinary RPC after binary','Reused cURL handle must reset binary callbacks and request mode before ordinary RPC');
    $probe=curl_init('http://127.0.0.1:'.$port.'/ucmprovisioner-api.php');
    $secret=str_repeat('ab',32);$nonce=bin2hex(random_bytes(16));
    $metadata=\FreePBX\modules\Ucmprovisioner\ApiCodec::seal(['timestamp'=>time(),'nonce'=>$nonce,'payload'=>['method'=>'tftp_file_upload','file'=>$file]],$secret);
    curl_setopt_array($probe,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['X-UCMP-File: '.$metadata]]);
    try {
        $wrongMethod=\FreePBX\modules\Ucmprovisioner\ApiCodec::open((string)curl_exec($probe),$secret);
        $expect(empty($wrongMethod['result']['ok'])&&str_contains($wrongMethod['result']['message'],'invalid file request method'),'Binary uploads must refuse form POST before receiving/staging bytes');
        $replay=\FreePBX\modules\Ucmprovisioner\ApiCodec::open((string)curl_exec($probe),$secret);
        $expect(empty($replay['result']['ok'])&&str_contains($replay['result']['message'],'replayed request'),'Binary metadata nonce replay must be refused');
        curl_setopt($probe,CURLOPT_HTTPHEADER,['X-UCMP-File: damaged-authentication']);curl_exec($probe);
        $expect(curl_getinfo($probe,CURLINFO_RESPONSE_CODE)===403,'Unauthenticated file headers must fail closed');
    } finally {curl_close($probe);}
    printf("100 MiB binary HTTP upload/download passed with a 32 MiB receiver limit; local fixture upload %.2fs (not a WAN benchmark)\n",$seconds);
} finally {
    if(isset($source)&&is_resource($source))fclose($source);
    if(isset($download)&&is_resource($download))fclose($download);
    if(is_resource($server)){proc_terminate($server);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($server);}
    if(is_dir($dir)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($dir);}
}
