<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/ServiceFilePermissions.php';

use FreePBX\modules\Ucmprovisioner\ServiceFilePermissions;

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $call, string $message) use ($expect): void {
    try { $call(); } catch (RuntimeException $error) { return; }
    $expect(false, $message);
};
$root = sys_get_temp_dir() . '/ucmp-service-permissions-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$held = null;
try {
    $directory = $root . '/media/family/model';
    ServiceFilePermissions::directory($directory);
    $file = $directory . '/asset.png'; file_put_contents($file, 'synthetic-media');
    ServiceFilePermissions::apply($file, 0660);
    ServiceFilePermissions::directory($directory);
    ServiceFilePermissions::apply($file, 0660);
    $expect(file_get_contents($file) === 'synthetic-media', 'Permission normalization changed media content');
    if (PHP_OS_FAMILY !== 'Windows') {
        $group = posix_getgrnam('asterisk');
        clearstatcache();
        foreach ([$root . '/media', $root . '/media/family', $directory] as $path) {
            $expect((fileperms($path) & 07777) === 0770 && filegroup($path) === $group['gid'], 'New shared directories must work without SGID');
        }
        $expect((fileperms($file) & 0777) === 0660 && filegroup($file) === $group['gid'], 'Published media requires the service group');
        chmod($directory, 02770);
        ServiceFilePermissions::directory($directory);
        clearstatcache();
        $expect((fileperms($directory) & 07777) === 02770, 'Already compatible inherited SGID need not be rewritten');
        // An ordinary framework reset can clear SGID; later writes still get the group.
        chmod($directory, 0770);
        $next = $directory . '/after-reset.png'; file_put_contents($next, 'after-reset');
        ServiceFilePermissions::apply($next, 0660);
        clearstatcache();
        $expect(filegroup($next) === $group['gid'], 'Write after a normal-mode reset lost service access');
    }
    $lockPath = $root . '/scheduler/job.lock';
    $held = ServiceFilePermissions::lock($lockPath);
    $expect(is_resource($held), 'Initial lock must be acquired');
    $inode = fileinode($lockPath);
    $expect(ServiceFilePermissions::lock($lockPath) === false, 'Contention must return false without replacing the held lock');
    flock($held, LOCK_UN); fclose($held); $held = null;
    $held = ServiceFilePermissions::lock($lockPath);
    $expect(is_resource($held) && fileinode($lockPath) === $inode && filesize($lockPath) === 0, 'Stable lock must be reusable and empty');
    flock($held, LOCK_UN); fclose($held); $held = null;
    mkdir($root . '/scheduler/not-a-file.lock');
    $reject(fn()=>ServiceFilePermissions::lock($root . '/scheduler/not-a-file.lock'), 'Invalid lock storage must throw instead of reporting a busy job');
    $reject(fn()=>ServiceFilePermissions::apply($directory, 0660), 'A shared file operation cannot target a directory');
    $reject(fn()=>ServiceFilePermissions::apply($file, 0777), 'Unexpected permission expansion must be rejected');
    $reject(fn()=>ServiceFilePermissions::directory(''), 'An empty root is invalid');
    if (@symlink($directory, $root . '/linked-directory')) {
        $reject(fn()=>ServiceFilePermissions::directory($root . '/linked-directory/child'), 'Shared directory writes must reject linked ancestors');
        unlink($root . '/linked-directory');
    } else { echo "Symlink creation unavailable on this host; shared-directory link fixture skipped\n"; }
    echo "Shared service directory/file permissions and stable lock/error contracts passed\n";
    if (PHP_OS_FAMILY === 'Windows') { echo "POSIX group/mode and distinct service-account access remain Linux acceptance checks\n"; }
} finally {
    if (is_resource($held)) { flock($held, LOCK_UN); fclose($held); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
