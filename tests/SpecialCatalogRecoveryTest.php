<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/MediaChunkTransfer.php';
require_once __DIR__.'/../lib/RpcOperationException.php';

// Execute the actual production method with a filesystem rename fault at its
// namespace boundary; staging, permission checks and file contents stay real.
$source=(string)file_get_contents(__DIR__.'/../Ucmprovisioner.class.php');
$start=strpos($source,'    private static function replaceSpecialCatalogFiles(');
$end=strpos($source,'    /** @return array<string,mixed> */',$start);
if ($start===false||$end===false) { throw new RuntimeException('Special catalog writer boundary missing'); }
$method=str_replace('private static function','public static function',substr($source,$start,$end-$start));
eval('namespace SpecialCatalogFaultFixture; function rename(string $from,string $to):bool {
    if (str_contains($from,".ucmp-tmp-")) { $GLOBALS["catalogActivations"]++;
        if ($GLOBALS["catalogActivations"]===2) { throw new \\RuntimeException("Injected second publication failure"); }
    } return \\rename($from,$to);
} final class Writer {'.$method.'}');
$root=sys_get_temp_dir().'/ucmp-catalog-fault-'.bin2hex(random_bytes(8));mkdir($root);
try {
    $a=$root.'/a.bmp';$b=$root.'/manifest.json';file_put_contents($a,'old image');file_put_contents($b,'old manifest');
    $GLOBALS['catalogActivations']=0;
    try {
        SpecialCatalogFaultFixture\Writer::replaceSpecialCatalogFiles([$a=>'new image',$b=>'new manifest']);
        throw new LogicException('Late publication failure was swallowed');
    } catch (FreePBX\modules\Ucmprovisioner\RpcOperationException $failure) {
        $receipt=$failure->receipt;
        if (empty($receipt['recovery_required'])||file_get_contents($a)!=='new image'
            ||file_get_contents($receipt['backups'][$a])!=='old image'
            ||file_get_contents($receipt['backups'][$b])!=='old manifest'
            ||file_get_contents($receipt['staged'][$b])!=='new manifest') {
            throw new RuntimeException('Partial publication lost its new bytes, before-state or recovery receipt');
        }
    }
} finally {
    foreach ((array)glob($root.'/*') as $path) { unlink($path); }
    rmdir($root);
}
echo "Special catalog late-publication recovery passed\n";
