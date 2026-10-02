<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/WallpaperEditorInput.php';
use FreePBX\modules\Ucmprovisioner\WallpaperEditorInput;
$root=sys_get_temp_dir().'/ucmp-editor-'.bin2hex(random_bytes(6));
$expect=static function(bool $ok,string $message):void { if (!$ok) { throw new RuntimeException($message); } };
try {
    $input=new WallpaperEditorInput($root);$calls=0;
    $payload=['op'=>'put_media_pairs','site_id'=>999,'pairs'=>str_repeat('x',1100000)];
    $json=json_encode($payload,JSON_THROW_ON_ERROR);$identity=['transfer_id'=>bin2hex(random_bytes(16)),'sha256'=>hash('sha256',$json),'size'=>strlen($json)];
    $apply=static function(array $request) use(&$calls,$expect):array { $calls++;$expect($request['site_id']===7,'Owner site was not forced');return ['ok'=>true,'written'=>3]; };
    foreach (str_split($json,WallpaperEditorInput::CHUNK) as $index=>$chunk) {
        $request=$identity+['op'=>'chunk','index'=>$index,'content'=>base64_encode($chunk)];
        $expect($input->handle($request,7,$apply)['ok'],'Staging failed');
        $expect($input->handle($request,7,$apply)['ok'],'Identical chunk replay failed');
    }
    $result=$input->handle($identity+['op'=>'apply'],7,$apply);
    $expect($result===['ok'=>true,'written'=>3]&&$calls===1,'Large input did not apply once');
    $expect($input->handle($identity+['op'=>'apply'],7,$apply)===$result&&$calls===1,'Completed apply replayed mutation');
    try { $input->handle(array_replace($identity,['op'=>'apply','sha256'=>str_repeat('0',64)]),7,$apply);throw new LogicException('Changed identity accepted'); }
    catch (DomainException) {}
    $json=json_encode(['op'=>'wallpaper_set_delete','set_id'=>'one']);$identity=['transfer_id'=>bin2hex(random_bytes(16)),'sha256'=>hash('sha256',$json),'size'=>strlen($json)];
    $input->handle($identity+['op'=>'chunk','index'=>0,'content'=>base64_encode($json)],7,$apply);
    $lost=static function(array $request) use(&$calls):array { $calls++;throw new RuntimeException('lost after mutation'); };
    $result=$input->handle($identity+['op'=>'apply'],7,$lost);
    $expect(!empty($result['recovery_required'])&&$calls===2,'Lost apply was falsely confirmed');
    $result=$input->handle($identity+['op'=>'apply'],7,$lost);
    $expect(!empty($result['recovery_required'])&&$calls===2,'Uncertain apply was replayed');

    $source=(string)file_get_contents(__DIR__.'/../Ucmprovisioner.class.php');
    $start=strpos($source,'    public function tftpOp(array $req): array');
    $end=strpos($source,'    public function wallpaperEditorInputLocal(', $start);
    eval('final class WallpaperRoutingFixture {
        public array $calls=[];public int $writes=0;
        public function __construct(private \\FreePBX\\modules\\Ucmprovisioner\\WallpaperEditorInput $input){}
        public function localSiteId():int{return 1;}
        public function isSiteLocal(int $site):bool{return $site===1;}
        private function engine(string $name):object{throw new RuntimeException("Controller must not open destination storage");}
        private function remoteLiveQuery(int $site,string $method,array $args):array{
            if($site!==7||$method!=="wallpaperEditorInputLocal"||strlen(base64_encode(json_encode($args)))>1048576){throw new RuntimeException("Unbounded or misrouted owner request");}
            $this->calls[]=$args[0]["op"];
            return $this->input->handle($args[0],7,function(array $payload):array{
                if($payload["site_id"]!==7){throw new RuntimeException("Wrong owner site");}
                $this->writes++;return ["ok"=>true];
            });
        }
    '.substr($source,$start,$end-$start).'}');
    $routing=new WallpaperRoutingFixture($input);
    $expect($routing->tftpOp(['op'=>'put_media_pairs','site_id'=>7,'pairs'=>str_repeat('x',1500000)])['ok']
        &&$routing->writes===1&&end($routing->calls)==='apply'&&count($routing->calls)>2,'Actual owner routing did not stage a bounded request before one apply');
} finally {
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        } rmdir($root);
    }
}
echo "Wallpaper owner input, replay and uncertain completion passed\n";
