<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/directory.php');
$start=strpos($source,'$softkeyDefaults=');
$end=strpos($source,'$cut =',$start);
$layout=['cisco_softkeys'=>['menu'=>['Search','Previous','Next','None'],'list'=>['Search','Previous','Next','EditDial']]];
eval(substr($source,$start,$end-$start));
foreach (['menu'=>['Select','Exit'],'list'=>['Dial','Exit']] as $view=>$required) {
    foreach ($required as $action) { if (!in_array($action,$layout['cisco_softkeys'][$view],true)) { throw new RuntimeException('Required navigation key overwritten'); } }
}
echo "Phone-facing softkey repair preserves every required navigation action\n";

$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$root=sys_get_temp_dir().'/ucmp-directory-http-'.bin2hex(random_bytes(6));mkdir($root,0700);
$endpoint=str_replace("\$root = '/var/lib/asterisk/ucmprovisioner/directory';",'$root = '.var_export($root,true).';',$source);
file_put_contents($root.'/ucmp-d.php',$endpoint);file_put_contents($root.'/token','fixture-token');
$entries=[];for($i=0;$i<40;$i++)$entries[]=['source_key'=>'entry-'.$i,'display_name'=>'Fixture '.sprintf('%02d',$i),'extension'=>(string)(1000+$i),'department'=>'Group '.sprintf('%02d',$i)];
$book=['book_key'=>'company','filename'=>'company.xml','is_default'=>true,'layout'=>['group_by'=>'none','cisco_page_size'=>32],'entries'=>$entries];
file_put_contents($root.'/directory.json',json_encode(['phonebooks'=>[$book]]));
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot reserve directory fixture port');
$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,'-t',$root],[0=>['pipe','r'],1=>['file',$root.'/output.log','a'],2=>['file',$root.'/error.log','a']],$pipes,null,null,['bypass_shell'=>true]);
if(!is_resource($process))throw new RuntimeException('Cannot start directory fixture');fclose($pipes[0]);
$request=static function(string $path)use($address):array{
    $body=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>5]]));
    $headers=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$headers[0]??'',$match);
    return [(int)($match[1]??0),(string)$body,implode("\n",$headers)];
};
try {
    $ready=false;for($i=0;$i<30;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($probe){fclose($probe);$ready=true;break;}usleep(50000);}
    $expect($ready,'Directory fixture did not start');
    [$code,$xml,$headers]=$request('/ucmp-d.php/fixture-token/company');
    $expect($code===200&&substr_count($xml,'<DirectoryEntry>')===32&&!str_contains($xml,'SoftKeyItem'),'Legacy directory must preserve its supported XML subset and page bound');
    $expect(str_contains($headers,'Refresh: 0; url=http://'.$address.'/ucmp-d.php/fixture-token/company/list/root/1/0'),'Legacy first page must expose manual native Next navigation');
    [$code,$xml,$headers]=$request('/ucmp-d.php/fixture-token/company/list/root/1/0');
    $expect($code===200&&substr_count($xml,'<DirectoryEntry>')===8&&str_contains($xml,'Fixture 39')&&str_contains($headers,'/list/root/0/0'),'Every later contact must be reachable and the last page must wrap to the first');
    [$code]=$request('/ucmp-d.php?token=fixture-token&format=cisco&file=missing.xml');$expect($code===404,'An unknown explicit filename must not silently serve the default book');
    [$code,$xml]=$request('/ucmp-d.php?token=fixture-token&format=cisco&book=company&file=display-alias.xml');
    $expect($code===200&&str_contains($xml,'SoftKeyItem'),'An explicit stable book key may retain its display filename alias and modern softkeys');
    $searchBook=$book;
    $searchBook['entries']=[
        ['source_key'=>'override','display_name'=>'Shared Desk','extension'=>'2001','directory_label_override_set'=>1,'directory_label_override'=>'Security'],
        ['source_key'=>'metadata','display_name'=>'Unrelated Person','extension'=>'2002','canonical_label'=>'u.person','department'=>'Security','org_unit'=>'Security','company'=>'Security','mail'=>'security@example.test','site_name'=>'Security','label'=>'Security / Unrelated Person'],
        ['source_key'=>'manual:fixture','display_name'=>'Security Desk','label'=>'Guard','extension'=>'2003','manual'=>1],
        ['source_key'=>'username','display_name'=>'Other Name','canonical_label'=>'a.bc2','extension'=>'2004'],
    ];
    file_put_contents($root.'/directory.json',json_encode(['phonebooks'=>[$searchBook]]));
    foreach (['sec','secu','SECURITY'] as $query) {
        foreach (['/ucmp-d.php/fixture-token/company/list/root/0/0?q=', '/ucmp-d.php?token=fixture-token&format=cisco&book=company&view=list&q='] as $url) {
            [$code,$xml]=$request($url.rawurlencode($query));
            $expect($code===200 && str_contains($xml,'2001') && str_contains($xml,'2003') && !str_contains($xml,'2002'),'Handset search must find final labels and manual contacts without matching hidden department metadata');
        }
    }
    foreach (['/ucmp-d.php/fixture-token/company/list/root/0/0?', '/ucmp-d.php?token=fixture-token&format=cisco&book=company&view=list&'] as $url) {
        [$code,$xml]=$request($url.'q=sec&label_pattern=%7Bdepartment%7D');
        $expect($code===200 && !str_contains($xml,'2002'),'A display pattern must not make department metadata searchable');
        [$code,$xml]=$request($url.'q=a.bc2');
        $expect($code===200 && substr_count($xml,'<DirectoryEntry>')===1 && str_contains($xml,'2004'),'Username digits must not also match unrelated numbers');
        [$code,$xml]=$request($url.'n=2002');
        $expect($code===200 && substr_count($xml,'<DirectoryEntry>')===1 && str_contains($xml,'2002'),'Dedicated numeric input must still find numbers');
        [$code,$xml]=$request($url.'q=secu&n=2003');
        $expect($code===200 && substr_count($xml,'<DirectoryEntry>')===1 && str_contains($xml,'2003'),'Name and numeric inputs must intersect');
    }
    $book['layout']['group_by']='department';file_put_contents($root.'/directory.json',json_encode(['phonebooks'=>[$book]]));
    [$code,$xml,$headers]=$request('/ucmp-d.php/fixture-token/company');
    $expect($code===200&&str_contains($xml,'CiscoIPPhoneMenu')&&str_contains($headers,'/menu/root/1/0'),'Legacy category menus must also expose remaining categories');
} finally {
    proc_terminate($process);proc_close($process);
    foreach(['ucmp-d.php','token','directory.json','output.log','error.log'] as $file)if(is_file($root.'/'.$file))unlink($root.'/'.$file);
    rmdir($root);
}
echo "Directory HTTP selection and legacy pagination fixtures passed\n";
