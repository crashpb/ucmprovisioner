<?php
declare(strict_types=1);
if (PHP_OS_FAMILY==='Windows'||!function_exists('posix_geteuid')||posix_geteuid()!==0) {
    echo "Signer service-UID publication requires isolated Linux root acceptance; skipped here\n";return;
}
require_once __DIR__.'/../tools/sign-local-module.php';
$account=posix_getpwnam('asterisk');
if (!$account) { throw new RuntimeException('Existing asterisk service account is required for ownership acceptance'); }
$root=sys_get_temp_dir().'/ucmp-sign-owner-'.bin2hex(random_bytes(8));mkdir($root,0755);chmod($root,0755);
try {
    $signer=$root.'/sign-local-module.php';copy(__DIR__.'/../tools/sign-local-module.php',$signer);chmod($signer,0444);
    $directory=$root.'/service';mkdir($directory,0700);chown($directory,$account['uid']);chgrp($directory,$account['gid']);
    $victim=$root.'/root-owned';file_put_contents($victim,'root-owned fixture');chmod($victim,0600);
    $destination=$directory.'/module.sig';symlink($victim,$destination);
    $code='require $argv[1]; \\UcmProvisioner\\Signing\\atomicWrite($argv[2],"verified signature bytes",(int)$argv[3],(int)$argv[4],0644);';
    \UcmProvisioner\Signing\runCommand([PHP_BINARY,'-r',$code,$signer,$destination,(string)$account['uid'],(string)$account['gid']]);
    clearstatcache();
    if (is_link($destination)||file_get_contents($destination)!=='verified signature bytes'||fileowner($destination)!==$account['uid']
        ||file_get_contents($victim)!=='root-owned fixture'||fileowner($victim)!==0) { throw new RuntimeException('Service-UID publication crossed its ownership boundary'); }
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());
    }rmdir($root);
}
echo "Real root-to-service signature publication and protected-target boundary passed\n";
