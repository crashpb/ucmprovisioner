<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\ProvisionerKeyPair;
$root=str_replace('\\','/',sys_get_temp_dir()) . '/ucmp-key-pair-' . bin2hex(random_bytes(8)); mkdir($root,0700);
$path=$root . '/fixture_key';
$original=\phpseclib3\Crypt\RSA::createKey(2048)->toString('PKCS8');
file_put_contents($path,$original); chmod($path,0600);
$result=ProvisionerKeyPair::ensure($path);
if (file_get_contents($path)!==$original || !is_file($path . '.pub')) { throw new RuntimeException('Missing public key rotated or lost private enrollment'); }
$again=ProvisionerKeyPair::ensure($path);
if ($again!==$result || file_get_contents($path)!==$original) { throw new RuntimeException('Key pair repair is not stable'); }
file_put_contents($path . '.pub',\phpseclib3\Crypt\RSA::createKey(2048)->getPublicKey()->toString('OpenSSH'));
try { ProvisionerKeyPair::ensure($path); throw new LogicException('Mismatched enrollment was accepted'); } catch (RuntimeException) {}
if (file_get_contents($path)!==$original) { throw new RuntimeException('Mismatch replaced enrolled private key'); }
unlink($path);
try { ProvisionerKeyPair::ensure($path); throw new LogicException('Missing enrolled private key was silently rotated'); } catch (RuntimeException) {}
// Where the host permits symlink creation, direct key-path links must fail closed.
$link = $root . '/linked_key';
if (@symlink($path . '.pub', $link)) {
    try { ProvisionerKeyPair::ensure($link); throw new LogicException('Symlinked enrollment key was accepted'); } catch (RuntimeException) {}
    unlink($link);
}
if (PHP_OS_FAMILY !== 'Windows') {
    $parentLink=$root . '-link';
    if (!symlink($root,$parentLink)) { throw new RuntimeException('Cannot create linked parent fixture'); }
    try {
        $rejected=false;
        try { ProvisionerKeyPair::ensure($parentLink . '/new_key'); } catch (RuntimeException) { $rejected=true; }
        if (!$rejected || file_exists($root . '/new_key.lock')) { throw new RuntimeException('A linked key directory was written before validation'); }
    } finally { unlink($parentLink); }
}
foreach (glob($root . '/*') as $file) { unlink($file); } rmdir($root);
echo "Missing-public repair and no-private-rotation fixtures passed\n";
