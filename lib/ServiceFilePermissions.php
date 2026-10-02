<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Shared media/cache writers must not depend on directory SGID surviving chown. */
final class ServiceFilePermissions
{
    public static function directory(string $path): void
    {
        if ($path === '' || dirname($path) === $path) { throw new \RuntimeException('Shared service directory must be an explicit application path'); }
        $missing = [];
        for ($parent = $path; !is_dir($parent); $parent = dirname($parent)) {
            if (is_link($parent) || file_exists($parent) || dirname($parent) === $parent) {
                throw new \RuntimeException('Shared service directory is unsafe: ' . $path);
            }
            $missing[] = $parent;
        }
        self::assertNoLinks($path);
        foreach (array_reverse($missing) as $directory) {
            if (!@mkdir($directory, 0770) && !is_dir($directory)) {
                throw new \RuntimeException('Cannot create shared service directory: ' . $directory);
            }
            self::apply($directory, 0770);
        }
        self::apply($path, 0770);
    }

    /** @return resource|false False means contention, never a permission failure. */
    public static function lock(string $path)
    {
        self::directory(dirname($path));
        self::assertNoLinks($path);
        if (file_exists($path) && !is_file($path)) {
            throw new \RuntimeException('Shared service lock must be a regular file: ' . $path);
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) { throw new \RuntimeException('Cannot open shared service lock: ' . $path); }
        try {
            self::apply($path, 0660);
            if (flock($handle, LOCK_EX | LOCK_NB)) { return $handle; }
        } catch (\Throwable $error) { fclose($handle); throw $error; }
        fclose($handle);
        return false;
    }

    public static function apply(string $path, int $mode): void
    {
        self::assertNoLinks($path);
        clearstatcache(true, $path);
        if (!in_array($mode, [0640, 0660, 0770], true)
            || ($mode === 0770 ? !is_dir($path) : !is_file($path))) {
            throw new \RuntimeException('Invalid shared service permission target: ' . $path);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $group = posix_getgrnam('asterisk');
            if (!$group || (filegroup($path) !== $group['gid'] && !@chgrp($path, $group['gid']))) {
                throw new \RuntimeException('Cannot assign the existing asterisk service group: ' . $path);
            }
        }
        // An existing file may belong to the other service account. No chmod is
        // needed when its permissions are already correct, including directory SGID.
        if ((fileperms($path) & 0777) !== $mode && !@chmod($path, $mode)) {
            throw new \RuntimeException('Cannot apply shared service permissions: ' . $path);
        }
    }

    public static function assertNoLinks(string $path): void
    {
        for ($parent = $path; dirname($parent) !== $parent; $parent = dirname($parent)) {
            if (is_link($parent)) { throw new \RuntimeException('Shared service path cannot use symbolic links: ' . $path); }
        }
    }
}
