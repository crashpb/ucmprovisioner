<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/sign-local-module.php';
$directory=sys_get_temp_dir().'/ucmp-sign-pair-'.bin2hex(random_bytes(8));mkdir($directory);
define('SignaturePairFixture\SIGNER_HOME',$directory);
$reflection=new ReflectionFunction('UcmProvisioner\Signing\publishSignaturePair');
$source=file($reflection->getFileName());
$body=implode('',array_slice($source,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
eval('namespace SignaturePairFixture; use \\RuntimeException; use \\Throwable; use \\UcmProvisioner\\Signing\\SignaturePublicationException;
    function ensureRootDirectory(string $path,int $mode):void{if(!is_dir($path)){mkdir($path,$mode);}}
    function validateReplaceableFile(string $path,bool $root):void{if(is_link($path)){throw new RuntimeException("Linked fixture target");}}
    function atomicWrite(string $path,string $bytes,int $uid,int $gid,int $mode):void {
        if($path===($GLOBALS["signatureFailPath"]??"")){throw new RuntimeException("Injected signature write failure");}
        if(file_put_contents($path,$bytes)!==strlen($bytes)){throw new RuntimeException("Fixture write failure");}
    }'.$body);
try{
    $secure=$directory.'/secure.sig';$module=$directory.'/module.sig';
    file_put_contents($secure,'previous secure signature');file_put_contents($module,'previous module signature');
    $GLOBALS['signatureFailPath']=$module;
    try{
        SignaturePairFixture\publishSignaturePair($secure,'prepared secure signature',$module,'prepared module signature',100,100,str_repeat('A',40));
        throw new LogicException('Second signature write failure was hidden');
    }catch(UcmProvisioner\Signing\SignaturePublicationException $error){
        $receipt=json_decode((string)file_get_contents($error->receipt['receipt']),true,32,JSON_THROW_ON_ERROR);
        $transaction=$error->receipt['transaction'];
        if($receipt['state']!=='secure_installed'||empty($error->receipt['recovery_required'])
            ||file_get_contents($secure)!=='prepared secure signature'||file_get_contents($module)!=='previous module signature'
            ||file_get_contents($transaction.'/before-0.sig')!=='previous secure signature'
            ||file_get_contents($transaction.'/before-1.sig')!=='previous module signature'
            ||file_get_contents($transaction.'/prepared-1.sig')!=='prepared module signature'){
            throw new RuntimeException('Incomplete signature pair lost its prepared/before bytes or truthful receipt');
        }
    }
}finally{
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}
    rmdir($directory);
}
echo "Signature pair late-write recovery passed\n";
