<?php
declare(strict_types=1);

interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

use FreePBX\modules\Ucmprovisioner;

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $call, string $message) use ($expect): void {
    try { $call(); } catch (RuntimeException $error) { return; }
    $expect(false, $message);
};
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/ucmp-native-permissions-' . bin2hex(random_bytes(8));
$root = $base . '/state'; $key = $base . '/keys/ucmp_id_rsa';
$paths = [
    'web-phonebook.json'=>0640, 'web-phonebook.lock'=>0640, 'job.lock'=>0660,
    'secrets/credentials.key'=>0640, 'secrets/credential-migration.json'=>0640,
    'secrets/credential-migration.lock'=>0660, 'secrets/directory/reader.json'=>0600,
    'secrets/directory/reader.lock'=>0600, 'directory/directory.json'=>0640,
    'directory/token'=>0640, 'directory/incoming/part'=>0640, 'api-nonces/nonce'=>0640,
    'site-snapshots/site/view.json'=>0640, 'asterisk-incoming/archive.tar.gz'=>0640,
    'asterisk-bundles/archive.tar.gz'=>0640, 'media/family/model/image.png'=>0660,
    'media/family/model/.catalogs/sample/manifest.json'=>0660,
    'media/family/model/.retired-assets/transaction/receipt.json'=>0600,
    'media-quarantine/old/family/model/.retired-assets/transaction/receipt.json'=>0600,
    'media-transfers/transfer/receipt.json'=>0660, 'publish-mesh/receipt.json'=>0660,
    'publish-mesh/writer.lock'=>0660, 'publish-mesh/.writer-owner'=>0660,
    'publish-mesh/retained/snapshot.json'=>0660,
    'core-recovery/before.json'=>0600, 'configuration-packages/export.zip'=>0600,
    'configuration-packages/import-one/database-before.json'=>0600, 'unknown-private/state'=>0600,
];
foreach ($paths as $relative=>$mode) {
    $path = $root . '/' . $relative;
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
    file_put_contents($path, 'synthetic-fixture-' . $relative);
}
$retained = [];
foreach (['media/family/model', 'media-quarantine/old/family/model'] as $library) {
    $retired = $root . '/' . $library . '/.retired-assets/transaction';
    mkdir($retired . '/catalog', 0770);
    file_put_contents($retired . '/catalog/source.png', 'synthetic-source');
    file_put_contents($retired . '/payload', 'synthetic-payload');
    chmod($retired . '/payload', 0660); chmod($retired . '/catalog/source.png', 0660);
    foreach (['catalog', 'catalog/source.png', 'payload'] as $relative) {
        $retained[$retired . '/' . $relative] = fileperms($retired . '/' . $relative) & 0777;
    }
}
mkdir(dirname($key), 0700, true);
foreach (['', '.pub', '.lock'] as $suffix) { file_put_contents($key . $suffix, 'synthetic-not-a-key'); }
$snapshot = static function (string $directory): array {
    clearstatcache(); $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $result[str_replace('\\', '/', $item->getPathname())] = [$item->isDir(), $item->getPerms(), $item->getOwner(), $item->getGroup(), $item->isFile() ? hash_file('sha256', $item->getPathname()) : null];
    }
    ksort($result); return $result;
};
$method = new ReflectionMethod(Ucmprovisioner::class, 'permissionResetPaths');
$invoke = static fn(): array => $method->invoke(null, $root, $key);
try {
    $xml = simplexml_load_file(dirname(__DIR__) . '/module.xml');
    $hook = $xml->hooks->framework;
    $expect((string)$hook['class'] === 'Chown' && (string)$hook['namespace'] === 'FreePBX\\Console\\Command', 'Native framework hook target is wrong');
    $expect((string)$hook->method['callingMethod'] === 'fwcChownFiles'
        && (string)$hook->method['class'] === 'Ucmprovisioner'
        && (string)$hook->method['namespace'] === 'FreePBX\\modules'
        && (string)$hook->method === 'chownFreepbx', 'Native hook dispatch registration is wrong');
    $expect((new ReflectionMethod(Ucmprovisioner::class, 'chownFreepbx'))->isPublic(), 'FreePBX must be able to call the hook');
    $before = $snapshot($base); $plan = $invoke();
    $expect($snapshot($base) === $before, 'Permission declaration mutated fixture files or contents');
    $map = [];
    foreach ($plan as $entry) {
        $path = str_replace('\\', '/', $entry['path']);
        $expect(!isset($map[$path]), 'Duplicate permission declaration');
        $expect(in_array($entry['type'], ['dir', 'file'], true), 'No recursive operation may widen a nested private store');
        $expect(is_int($entry['perms']) && $entry['perms'] >= 0 && $entry['perms'] <= 0777, 'Mode must satisfy the FreePBX normal-mode contract');
        $expect($entry['group'] === 'asterisk', 'Native reset must restore the service group');
        $map[$path] = $entry;
    }
    foreach ($paths as $relative=>$mode) { $expect(($map[$root . '/' . $relative]['perms'] ?? null) === $mode, 'Incorrect native file mode for ' . $relative); }
    foreach ([''=>0770, '/secrets'=>0750, '/secrets/directory'=>0700, '/media-transfers'=>0770,
        '/media-transfers/transfer'=>0770,
        '/publish-mesh'=>0770, '/publish-mesh/retained'=>0770, '/core-recovery'=>0700, '/configuration-packages'=>0770,
        '/configuration-packages/import-one'=>0700, '/asterisk-bundles'=>0750,
        '/media/family/model/.retired-assets'=>0700, '/media/family/model/.retired-assets/transaction'=>0700] as $relative=>$mode) {
        $expect($map[$root . $relative]['perms'] === $mode, 'Incorrect native directory mode for ' . $relative);
    }
    foreach ($retained as $path=>$mode) { $expect($map[$path]['perms'] === $mode, 'Reset must retain rename-restored media permissions'); }
    foreach ([''=>0640, '.pub'=>0644, '.lock'=>0660] as $suffix=>$mode) { $expect($map[$key . $suffix]['perms'] === $mode, 'Module key mode changed'); }
    $expect($map[$root . '/asterisk-bundles/archive.tar.gz']['owner'] === 'root', 'Root-owned patch bundle ownership was weakened');
    $expect($map[$root . '/secrets/directory/reader.json']['owner'] === 'asterisk', 'AD reader files must remain asterisk-owned');
    if (function_exists('posix_getpwuid')) {
        foreach (['media-transfers/transfer/receipt.json', 'publish-mesh/receipt.json', 'core-recovery/before.json', 'configuration-packages/import-one/database-before.json'] as $relative) {
            $owner = posix_getpwuid(fileowner($root . '/' . $relative))['name'];
            // Framework's earlier asterisk ownership pass is overridden by this
            // pre-pass snapshot, not by reading an already-reset owner afterward.
            $expect($map[$root . '/' . $relative]['owner'] === $owner, 'Private-work owner must survive the global reset');
        }
    }
    $expect($invoke() === $plan, 'Repeated metadata collection must produce the same declaration');
    unlink($root . '/web-phonebook.json'); mkdir($root . '/web-phonebook.json');
    $reject($invoke, 'A directory cannot masquerade as the web policy file');
    rmdir($root . '/web-phonebook.json'); file_put_contents($root . '/web-phonebook.json', 'synthetic-restored');
    if (@symlink($key, $root . '/linked-key')) {
        $reject($invoke, 'The hook must reject linked files before returning operations');
        unlink($root . '/linked-key');
    } else { echo "Symlink creation unavailable on this host; link rejection fixture skipped\n"; }
    echo "FreePBX hook registration, metadata-only declaration, private modes, ownership and retained-media contracts passed\n";
    if (PHP_OS_FAMILY === 'Windows') { echo "Linux ownership, chmod and cross-service access are not proven by this metadata fixture\n"; }
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($base);
}
