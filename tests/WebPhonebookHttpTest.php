<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/WebPhonebook.php';
require_once __DIR__ . '/WebPhonebookFixture.php';
use FreePBX\modules\Ucmprovisioner\WebPhonebook;

$expect=static function(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}};
$root=sys_get_temp_dir().'/ucmp-webbook-http-'.bin2hex(random_bytes(8));
mkdir($root.'/directory',0700,true);
file_put_contents($root.'/directory/directory.json',json_encode(webPhonebookFixture(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$source=dirname(__DIR__);
$router="<?php\n";
foreach(['WebPhonebook','PhonebookWorkbook','WebPhonebookHttp'] as $class){$router.='require_once '.var_export($source.'/lib/'.$class.'.php',true).";\n";}
$router.='\\FreePBX\\modules\\Ucmprovisioner\\WebPhonebookHttp::run(new \\FreePBX\\modules\\Ucmprovisioner\\WebPhonebook('.var_export($root,true)."));\n";
file_put_contents($root.'/router.php',$router);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errorCode,$errorMessage);
if(!$socket){throw new RuntimeException('Cannot reserve a loopback test port.');}
$address=stream_socket_get_name($socket,false); fclose($socket);
$pipes=[];
$process=proc_open([PHP_BINARY,'-S',$address,$root.'/router.php'],[0=>['pipe','r'],1=>['file',$root.'/server-output.txt','a'],2=>['file',$root.'/server-errors.txt','a']],$pipes,$source,null,['bypass_shell'=>true]);
if(!is_resource($process)){throw new RuntimeException('Cannot start the local HTTP regression server.');}
fclose($pipes[0]);
$base='http://'.$address.'/?action=';
$request=static function(string $action,string $method='GET',string $body='',array $headers=[])use($base):array{
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>5]]);
    $response=file_get_contents($base.$action,false,$context);
    $responseHeaders=$http_response_header??[];
    preg_match('/^HTTP\/\S+ (\d+)/',$responseHeaders[0]??'',$match);
    return [(int)($match[1]??0),(string)$response,$responseHeaders];
};
try{
    $ready=false;
    for($attempt=0;$attempt<50;$attempt++){
        $probe=@stream_socket_client('tcp://'.$address,$errorCode,$errorMessage,.1);
        if($probe){fclose($probe);$ready=true;break;}
        usleep(50000);
    }
    $expect($ready,'Local HTTP test server did not become ready');
    foreach(['page','css','js','favicon','data','export'] as $action){[$code]=$request($action);$expect($code===403,'Disabled policy exposed '.$action);}
    $service=new WebPhonebook($root);
    $service->savePolicy(['enabled'=>true,'networks'=>['127.0.0.1/32'],'books'=>['company','services'],'fields'=>WebPhonebook::DEFAULT_FIELDS],$service->policyRevision());
    [$code,$html,$headers]=$request('page');
    $expect($code===200&&str_contains($html,'id="ucmp-webbook"'),'Employee page failed');
    $expect(str_contains($html,'<title>Company Directory</title>'),'Requested browser title missing');
    [$code]=$request('favicon');$expect($code===404,'Unset favicon should not expose a file');
    $pixels=imagecreatetruecolor(16,16); ob_start(); imagepng($pixels); $png=ob_get_clean(); imagedestroy($pixels);
    $icon=WebPhonebook::faviconFromImage($png);
    $service->savePolicy(array_replace($service->policy(),['favicon'=>$icon]),$service->policyRevision());
    [$code,$iconBytes,$iconHeaders]=$request('favicon');
    $expect($code===200 && $iconBytes===base64_decode($icon) && str_contains(implode("\n",$iconHeaders),'image/png'),'Favicon bytes/type did not roundtrip');
    [$code,$html]=$request('page');$expect(str_contains($html,'rel="icon"') && str_contains($html,'action=favicon'),'Uploaded icon missing from page');
    $expect(str_contains(implode("\n",$headers),"frame-ancestors 'none'"),'Framing protection missing');
    $expect(str_contains(implode("\n",$headers),'no-store'),'Contact responses may be cached');
    $expect(!str_contains($html,'fixture-person'),'Contact data was embedded in HTML');
    foreach(['css','js'] as $action){[$code,$body]=$request($action);$expect($code===200&&strlen($body)>100,'Standalone '.$action.' failed');}
    [$code,$json]=$request('data&book=company');$expect($code===200,'Data endpoint failed');
    $model=json_decode($json,true,64,JSON_THROW_ON_ERROR);
    $expect(count($model['contacts'])===36&&!str_contains($json,'004726'),'Private fields leaked through HTTP');
    foreach(['page','css','js','favicon','data','export'] as $action){
        [$code]=$request($action,'GET','',['X-Forwarded-For: 127.0.0.1']);$expect($code===($action==='export'?405:200),'Direct private access was changed by a forwarding header: '.$action);
        [$code]=$request($action,'GET','',['Sec-Fetch-Site: cross-site']);$expect($code===403,'Cross-site request bypassed '.$action);
    }
    [$code]=$request('data&book=private');$expect($code===404,'Hidden phonebook accessible');
    [$code]=$request('data&book%5B%5D=company');$expect($code===400,'Array book argument accepted');
    [$code]=$request('unknown');$expect($code===400,'Unknown action accepted');
    [$code]=$request('export');$expect($code===405,'GET export accepted');
    [$code]=$request('export','POST','x=1',['Content-Type: application/x-www-form-urlencoded']);$expect($code===415,'Cross-site form export accepted');
    $payload=['book'=>'company','revision'=>$model['revision'],'scope'=>'selected','columns'=>['name','extension'],'ids'=>array_column(array_slice($model['contacts'],0,3),'id')];
    [$code,$xlsx,$headers]=$request('export','POST',json_encode($payload),['Content-Type: application/json']);
    $expect($code===200&&str_starts_with($xlsx,'PK'),'Real workbook response failed');
    $expect(str_contains(implode("\n",$headers),'spreadsheetml.sheet'),'Wrong XLSX content type');
    $payload['columns'][]='employee';
    [$code]=$request('export','POST',json_encode($payload),['Content-Type: application/json']);$expect($code===400,'Hidden field export succeeded');
    $payload['columns']=['name'];$payload['revision']='stale';
    [$code]=$request('export','POST',json_encode($payload),['Content-Type: application/json']);$expect($code===409,'Stale export was not rejected');
    [$code]=$request('export','POST',str_repeat('x',1048577),['Content-Type: application/json']);$expect($code===413,'Oversized export request accepted');
    $service->savePolicy(array_replace($service->policy(),['export_enabled'=>false]),$service->policyRevision());
    [$code]=$request('export','POST',json_encode($payload),['Content-Type: application/json']);$expect($code===403,'Policy-disabled export route accepted');
    $service->savePolicy(array_replace($service->policy(),['access_mode'=>'trusted_proxy','trusted_proxies'=>['127.0.0.1'],'client_header'=>'HTTP_X_REAL_IP']),$service->policyRevision());
    foreach(['page','css','js','favicon','data','export'] as $action){
        [$code]=$request($action,'GET','',[]);$expect($code===403,'Proxy missing client header exposed '.$action);
        [$code]=$request($action,'GET','',['X-Real-IP: 127.0.0.1','X-Forwarded-For: 127.0.0.1']);$expect($code===403,'Conflicting proxy headers exposed '.$action);
        [$code]=$request($action,'GET','',['X-Real-IP: 127.0.0.1, 127.0.0.2']);$expect($code===403,'Chained proxy headers exposed '.$action);
        if($action!=='export'){[$code]=$request($action,'GET','',['X-Real-IP: 127.0.0.1']);$expect($code===200,'Valid trusted proxy denied '.$action);}
    }
    echo "Web phonebook HTTP routes, headers, access denial, stale exports and XLSX response passed\n";
    if (in_array('--browser',$argv??[],true)) {
        $service->savePolicy(array_replace($service->policy(),['access_mode'=>'direct','export_enabled'=>true,'fields'=>array_keys(WebPhonebook::FIELDS),'site_labels'=>['Main office'=>'Head office']]),$service->policyRevision());
        echo 'Browser fixture: http://'.$address."/ (press Enter to stop)\n";
        fgets(STDIN);
    }
}finally{
    proc_terminate($process);proc_close($process);
    foreach(['router.php','server-output.txt','server-errors.txt','web-phonebook.json','web-phonebook.lock','directory/directory.json'] as $file){if(is_file($root.'/'.$file)){unlink($root.'/'.$file);}}
    rmdir($root.'/directory');rmdir($root);
}
