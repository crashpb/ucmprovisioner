<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/tools/sign-local-module.php';

use function UcmProvisioner\Signing\buildManifest;
use function UcmProvisioner\Signing\runCommand;

$expect = static function (bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$hash = str_repeat('a',64); $fingerprint = str_repeat('B',40);
$manifest = buildManifest(['lib/Fixture.php'=>$hash], 'manual', $fingerprint);
$parsed = parse_ini_string($manifest,true,INI_SCANNER_RAW);
$expect(($parsed['hashes']['lib/Fixture.php'] ?? '')===$hash, 'Safe filenames must round-trip through the FreePBX INI format');
foreach (["bad\n[config]",'foo=bar','foo;comment','[config]','../escape','dir/../escape','./relative','foo"bar'] as $filename) {
    try { buildManifest([$filename=>$hash], 'manual', $fingerprint); throw new LogicException('Unsafe signing filename accepted'); }
    catch (RuntimeException $expected) {}
}
$input = str_repeat('input-',50000);
$child = 'fwrite(STDERR,str_repeat("e",262144)); fwrite(STDOUT,str_repeat("o",262144)); $input=stream_get_contents(STDIN); fwrite(STDOUT,hash("sha256",$input));';
$result = runCommand([PHP_BINARY,'-r',$child],$input);
$expect($result['stdout']===str_repeat('o',262144).hash('sha256',$input) && $result['stderr']===str_repeat('e',262144), 'Both output streams must drain while the child waits for input');
try { runCommand([PHP_BINARY,'-r','fwrite(STDERR,"fixture failure"); exit(7);']); throw new LogicException('Nonzero child accepted'); }
catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'fixture failure'),'Child failure detail was lost'); }
$started = microtime(true);
try { runCommand([PHP_BINARY,'-r','usleep(5000000);'],null,null,0.1); throw new LogicException('Unbounded child accepted'); }
catch (RuntimeException $expected) {}
$expect(microtime(true)-$started<3, 'Timed out signing child must be stopped promptly');
try { runCommand([PHP_BINARY,'-r','fwrite(STDOUT,str_repeat("x",33554433));']); throw new LogicException('Oversized child output accepted'); }
catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'bound'), 'Oversized child failed for an unrelated reason'); }
echo "Local signer INI, concurrent streams, timeout and output-bound assertions passed\n";
