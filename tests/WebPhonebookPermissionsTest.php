<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/WebPhonebook.php';
use FreePBX\modules\Ucmprovisioner\WebPhonebook;

$expect = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$reject = static function (callable $action, string $message) use ($expect): void {
    try { $action(); } catch (RuntimeException) { return; }
    $expect(false, $message);
};
$mode = static function (string $path): int {
    clearstatcache(true, $path);
    return fileperms($path) & 0777;
};
$root = sys_get_temp_dir() . '/ucmp-web-permissions-' . bin2hex(random_bytes(8));
mkdir($root . '/secrets/directory', 0700, true);
$policyPath = $root . '/web-phonebook.json';
$lockPath = $root . '/web-phonebook.lock';
$secretPath = $root . '/secrets/directory/fixture.json';
$policy = WebPhonebook::defaults();
$policyBytes = json_encode($policy, JSON_THROW_ON_ERROR) . "\n";
file_put_contents($policyPath, $policyBytes);
file_put_contents($lockPath, '');
file_put_contents($secretPath, 'synthetic-private-sentinel');
chmod($policyPath, 0600);
chmod($lockPath, 0600);
chmod($secretPath, 0600);
$secretMode = $mode($secretPath);
$secretDirMode = $mode(dirname($secretPath));
$rootMode = $mode($root);
$service = new WebPhonebook($root);
$held = null;

try {
    $service->installPolicyPermissions();
    $expect(file_get_contents($policyPath) === $policyBytes, 'Install changed policy content');
    $expect(file_get_contents($secretPath) === 'synthetic-private-sentinel'
        && $mode($secretPath) === $secretMode && $mode(dirname($secretPath)) === $secretDirMode,
        'Policy permission upgrade touched reader-secret content or modes');
    $expect($mode($root) === $rootMode, 'Policy permission upgrade changed the data-directory mode');
    if (PHP_OS_FAMILY !== 'Windows') {
        $expect($mode($policyPath) === 0640 && $mode($lockPath) === 0640, 'Install did not apply exact policy/lock modes');
        $group = posix_getgrnam('asterisk');
        $expect(is_array($group) && filegroup($policyPath) === $group['gid'] && filegroup($lockPath) === $group['gid'], 'Policy and lock require the existing service group');
    }
    $service->installPolicyPermissions();
    $expect(file_get_contents($policyPath) === $policyBytes, 'Repeat install rewrote policy');
    $lockInode = fileinode($lockPath);

    $held = fopen($lockPath, 'rb');
    $expect(is_resource($held) && flock($held, LOCK_EX | LOCK_NB), 'Read-only handle could not hold the stable lock');
    $reject(fn()=> $service->savePolicy($policy, $service->policyRevision()), 'Concurrent save bypassed the stable lock');
    $reject(fn()=> $service->installPolicyPermissions(), 'Install bypassed the active settings lock');
    $expect(file_get_contents($policyPath) === $policyBytes, 'Contended operation changed policy');
    flock($held, LOCK_UN);
    fclose($held);
    $held = null;

    $formSnapshot=$service->policySnapshot();
    $expect($formSnapshot['policy']===$policy && $formSnapshot['revision']===$service->policyRevision()
        && $formSnapshot['management']===[],'Form values and revision must describe the same policy document');
    $policy['policy_label'] = 'Synthetic updated policy';
    $service->savePolicy($policy, $service->policyRevision());
    $expect($service->policy() === $policy, 'Atomic save lost policy content');
    $reject(fn()=>$service->savePolicy($formSnapshot['policy'],$formSnapshot['revision']),
        'A form snapshot taken before a concurrent policy update must not overwrite it');
    $expect($service->policy()===$policy,'Rejected stale form replaced the concurrent update');
    $expect(filesize($lockPath) === 0 && fileinode($lockPath) === $lockInode, 'Save replaced or wrote the stable lock');
    if (PHP_OS_FAMILY !== 'Windows') {
        $expect($mode($policyPath) === 0640 && filegroup($policyPath) === $group['gid'], 'Atomic replacement lost mode or group');
    }

    // Reject unsafe paths before chmod; never follow a policy link into a secret.
    unlink($policyPath);
    if (@symlink($secretPath, $policyPath)) {
        $reject(fn()=> $service->installPolicyPermissions(), 'Install followed a symlinked policy');
        $expect($mode($secretPath) === $secretMode, 'Symlink rejection changed secret permissions');
        unlink($policyPath);
    }
    mkdir($policyPath);
    $reject(fn()=> $service->installPolicyPermissions(), 'Install accepted a directory as its policy file');
    rmdir($policyPath);
    unlink($lockPath);
    $service->installPolicyPermissions();
    $expect(!file_exists($policyPath) && is_file($lockPath), 'Fresh install must create only the empty lock, never enable a policy');

    $install = (string)file_get_contents(dirname(__DIR__) . '/install.php');
    $expect(str_contains($install, 'WebPhonebook())->installPolicyPermissions()'), 'Normal install must upgrade existing policy permissions');
    echo "Web-policy permission upgrade, private-secret isolation, stable lock and atomic-save checks passed\n";
    if (PHP_OS_FAMILY === 'Windows') { echo "POSIX mode/group and cross-user enforcement require Linux acceptance; not claimed on Windows\n"; }
} finally {
    if (is_resource($held)) { flock($held, LOCK_UN); fclose($held); }
    if (is_dir($policyPath)) { rmdir($policyPath); }
    foreach ([$policyPath, $lockPath, $secretPath] as $file) {
        if (is_file($file) || is_link($file)) { unlink($file); }
    }
    rmdir($root . '/secrets/directory');
    rmdir($root . '/secrets');
    rmdir($root);
}
