<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$source = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$ensureStart = strpos($source, 'private function ensureProvisionerKey(): array');
$ensureEnd = strpos($source, '/** Parse, reject private material', $ensureStart === false ? 0 : $ensureStart);
$expect($ensureStart !== false && $ensureEnd !== false, 'Provisioner key method boundaries must remain discoverable');
$ensure = substr($source, $ensureStart, $ensureEnd - $ensureStart);
$expect(str_contains($ensure, 'foreach ([$preferredDir, $agentDir] as $candidateDir)'), 'Key selection must inspect both key locations');
$expect(str_contains($ensure, '$existing !== null'), 'Key selection must retain an existing enrolled pair');
$expect(str_contains($ensure, 'Provisioner public key exists without its private enrollment key'), 'Orphan public enrollment must fail closed');
$expect(str_contains($ensure, 'is_link($current)'), 'Key selection must reject symlinked parent paths');

// Execute the production selector against isolated directories: an empty writable preferred
// directory must not rotate an enrolled fallback pair, and an orphan preferred public key must
// fail before any fallback/new key can be selected.
$fixtureRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/ucmp-key-selection-' . bin2hex(random_bytes(8));
$preferredDir = $fixtureRoot . '/preferred';
$fallbackDir = $fixtureRoot . '/fallback';
mkdir($preferredDir, 0700, true);
mkdir($fallbackDir, 0700, true);
$fallbackKey = $fallbackDir . '/ucmp_id_rsa';
$fallbackPub = $fallbackKey . '.pub';
$fallbackPrivate = \phpseclib3\Crypt\RSA::createKey(2048)->toString('PKCS8');
file_put_contents($fallbackKey, $fallbackPrivate);
$isolatedEnsure = str_replace(
    ['private function ensureProvisionerKey(): array', "'/etc/asterisk/keys'", "'/var/lib/ucmp-pbx/keys'"],
    ['public function ensureProvisionerKeyForTest(): array', var_export($preferredDir, true), var_export($fallbackDir, true)],
    substr($source, $ensureStart, $ensureEnd - $ensureStart)
);
$selector = eval('return new class {' . $isolatedEnsure . '};');
$selected = $selector->ensureProvisionerKeyForTest();
$expect($selected['private'] === $fallbackKey, 'Existing fallback key was not selected when preferred directory was empty');
$expect(file_get_contents($fallbackKey) === $fallbackPrivate, 'Fallback private enrollment key was rotated');
$expect(!file_exists($preferredDir . '/ucmp_id_rsa'), 'Empty preferred directory unexpectedly received a new key');

$preferredPub = $preferredDir . '/ucmp_id_rsa.pub';
file_put_contents($preferredPub, "orphan-public-fixture\n");
try {
    $selector->ensureProvisionerKeyForTest();
    throw new LogicException('Orphan preferred public key was silently ignored');
} catch (RuntimeException) {
    // Expected fail-closed behavior.
}
$expect(file_get_contents($fallbackKey) === $fallbackPrivate, 'Orphan handling changed the enrolled fallback key');
foreach ([$preferredPub, $fallbackKey, $fallbackPub, $fallbackKey . '.lock'] as $fixture) { if (is_file($fixture)) { unlink($fixture); } }
rmdir($preferredDir);
rmdir($fallbackDir);
rmdir($fixtureRoot);

$bootStart = strpos($source, 'public function bootstrapPbxAgent(');
$bootEnd = strpos($source, 'private function fingerprintHostKey(', $bootStart === false ? 0 : $bootStart);
$expect($bootStart !== false && $bootEnd !== false, 'PBX agent bootstrap method boundaries must remain discoverable');
$bootstrap = substr($source, $bootStart, $bootEnd - $bootStart);
$expect(str_contains($bootstrap, '$symlinkPreflight'), 'Bootstrap must preflight managed root paths for symlinks');
foreach (['/usr/local/libexec/ucmp-pbx-agent', '/usr/local/libexec/ucmp-pbx-dispatch', '/var/lib/ucmp-pbx/.ssh/authorized_keys', '/etc/asterisk/keys/ucmp_id_rsa'] as $path) {
    $expect(str_contains($bootstrap, $path), "Bootstrap symlink preflight must cover $path");
}
$expect(str_contains($bootstrap, '/usr/bin/php -r') && str_contains($bootstrap, 'random_bytes(32)'), 'Bootstrap password must be generated on the PBX');
$expect(str_contains($bootstrap, 'unset account_password'), 'Remote bootstrap password must be cleared after chpasswd');
$exitCheck = strpos($bootstrap, '$bootstrapExit = $ssh->getExitStatus();');
$keyLogin = strpos($bootstrap, 'if (!$test->login(\'ucmp-pbx\'');
$expect($exitCheck !== false && $keyLogin !== false && $exitCheck < $keyLogin, 'Bootstrap must verify remote script exit status before key login');
$expect(str_contains($bootstrap, 'if ($bootstrapExit !== 0)'), 'Bootstrap must fail closed on a non-zero remote script exit');
$expect(!str_contains($bootstrap, '$randomAccountPassword'), 'Bootstrap must not generate a password in PHP');
$expect(!str_contains($bootstrap, "escapeshellarg('ucmp-pbx:'"), 'Bootstrap password must not be embedded in the remote command argv');
$expect(!str_contains($bootstrap, 'usermod -L ucmp-pbx'), 'Bootstrap must not change account-lock policy implicitly');

echo "Existing-key selection and remote bootstrap secret-boundary fixtures passed\n";
