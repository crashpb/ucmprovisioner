<?php
declare(strict_types=1);

/**
 * Last-known-good cache for site read operations.
 *
 * Failed reads never replace a successful snapshot. Mutations are rejected
 * before their callback can run, and results containing secret-like keys are
 * not persisted.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/ServiceFilePermissions.php';

final class SiteSnapshotCache
{
    public const INTENT_READ = 'read';
    private const SCHEMA = 1;
    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D';
    private const SECRET_KEY_PATTERN = '/(?:\A|[_-])(?:password|passwd|secret|token|credential|private[_-]?key|authorization|cookie|api[_-]?key)(?:\z|[_-])/i';

    private ?string $root = null;
    private ?\PDO $db = null;
    /** @var callable */
    private $clock;

    public function __construct(
        $storage = '/var/lib/asterisk/ucmprovisioner/site-snapshots',
        ?callable $clock = null
    ) {
        if ($storage instanceof \PDO) {
            $this->db = $storage;
        } elseif (is_string($storage)) {
            $this->root = rtrim($storage, '/\\');
            if ($this->root === '') {
                throw new \InvalidArgumentException('Snapshot root is required');
            }
        } else {
            throw new \InvalidArgumentException('Snapshot storage must be a PDO connection or directory');
        }
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Execute a site read and return live data or its last successful snapshot.
     *
     * The callback must return an array. An explicit `ok=false` result counts as
     * a failed read and is never cached.
     *
     * @return array{data:?array,meta:array{
     *   site_key:string,resource_key:string,source:string,fresh:bool,stale:bool,
     *   offline:bool,fetched_at:?int,age:?int,error:?string
     * }}
     */
    public function readThrough(
        string $intent,
        string $siteKey,
        string $resourceKey,
        int $freshForSeconds,
        callable $reader,
        ?callable $snapshotFilter = null
    ): array {
        if ($intent !== self::INTENT_READ) {
            throw new \LogicException('Last-known-good snapshots are available only to read operations');
        }
        $this->assertKey($siteKey, 'site');
        $this->assertKey($resourceKey, 'resource');
        if ($freshForSeconds < 0) {
            throw new \InvalidArgumentException('Freshness interval cannot be negative');
        }

        try {
            $data = $reader();
        } catch (\Throwable $e) {
            return $this->offlineResult(
                $siteKey,
                $resourceKey,
                $freshForSeconds,
                $this->safeError($e->getMessage())
            );
        }

        if (!is_array($data)) {
            return $this->offlineResult(
                $siteKey,
                $resourceKey,
                $freshForSeconds,
                'Site read returned an invalid result'
            );
        }
        if (array_key_exists('ok', $data) && $data['ok'] !== true) {
            return $this->offlineResult(
                $siteKey,
                $resourceKey,
                $freshForSeconds,
                $this->safeError((string)($data['message'] ?? 'Site read returned ok=false'))
            );
        }

        $snapshotData = $data;
        if ($snapshotFilter !== null) {
            $snapshotData = $snapshotFilter($data);
            if (!is_array($snapshotData)) {
                throw new \UnexpectedValueException('Snapshot filter must return an array');
            }
        }
        $this->assertSecretFree($snapshotData);
        $fetchedAt = $this->now();
        $error = null;
        try {
            $this->store($siteKey, $resourceKey, $fetchedAt, $snapshotData);
        } catch (\Throwable $e) {
            // The live read is still useful. Report the cache failure without
            // misrepresenting the site as offline.
            $error = $this->safeError('Snapshot cache write failed: ' . $e->getMessage());
        }

        return $this->envelope(
            $siteKey,
            $resourceKey,
            $data,
            'live',
            true,
            false,
            false,
            $fetchedAt,
            0,
            $error
        );
    }

    private function offlineResult(
        string $siteKey,
        string $resourceKey,
        int $freshForSeconds,
        string $error
    ): array {
        $this->recordFailure($siteKey, $resourceKey, $error);
        $snapshot = $this->load($siteKey, $resourceKey);
        if ($snapshot === null) {
            return $this->envelope(
                $siteKey,
                $resourceKey,
                null,
                'none',
                false,
                false,
                true,
                null,
                null,
                $error
            );
        }

        $age = max(0, $this->now() - $snapshot['fetched_at']);
        $fresh = $age <= $freshForSeconds;
        return $this->envelope(
            $siteKey,
            $resourceKey,
            $snapshot['data'],
            'cache',
            $fresh,
            !$fresh,
            true,
            $snapshot['fetched_at'],
            $age,
            $error
        );
    }

    private function recordFailure(string $siteKey, string $resourceKey, string $error): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE ucmp_site_snapshots
                    SET last_error=?,last_error_at=?
                  WHERE site_id=? AND resource_type=?'
            );
            $stmt->execute([$error, $this->now(), (int)$siteKey, $resourceKey]);
        } catch (\Throwable $ignored) {
            // Cache diagnostics must never prevent the cached read itself.
        }
    }

    private function store(string $siteKey, string $resourceKey, int $fetchedAt, array $data): void
    {
        if ($this->db !== null) {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $stmt = $this->db->prepare(
                'INSERT INTO ucmp_site_snapshots
                    (site_id,resource_type,payload_json,payload_hash,api_version,fetched_at,last_error,last_error_at)
                 VALUES (?,?,?,?,?,?,NULL,0)
                 ON DUPLICATE KEY UPDATE
                    payload_json=VALUES(payload_json),payload_hash=VALUES(payload_hash),
                    api_version=VALUES(api_version),fetched_at=VALUES(fetched_at),
                    last_error=NULL,last_error_at=0'
            );
            $stmt->execute([
                (int)$siteKey,
                $resourceKey,
                $json,
                hash('sha256', $json),
                (string)self::SCHEMA,
                $fetchedAt,
            ]);
            return;
        }

        $path = $this->path($siteKey, $resourceKey);
        $dir = dirname($path);
        ServiceFilePermissions::directory($dir);

        $document = [
            'schema' => self::SCHEMA,
            'site_key' => $siteKey,
            'resource_key' => $resourceKey,
            'fetched_at' => $fetchedAt,
            'data' => $data,
        ];
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($tmp, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Could not write the snapshot');
            }
            ServiceFilePermissions::apply($tmp, 0640);
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('Could not publish the snapshot');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @return null|array{fetched_at:int,data:array} */
    private function load(string $siteKey, string $resourceKey): ?array
    {
        if ($this->db !== null) {
            $stmt = $this->db->prepare(
                'SELECT payload_json,payload_hash,api_version,fetched_at
                   FROM ucmp_site_snapshots
                  WHERE site_id=? AND resource_type=?'
            );
            $stmt->execute([(int)$siteKey, $resourceKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$row || (string)($row['api_version'] ?? '') !== (string)self::SCHEMA) {
                return null;
            }
            $json = (string)($row['payload_json'] ?? '');
            $hash = (string)($row['payload_hash'] ?? '');
            if ($json === '' || !hash_equals($hash, hash('sha256', $json))) {
                return null;
            }
            try {
                $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                return null;
            }
            return is_array($data)
                ? ['fetched_at'=>(int)$row['fetched_at'], 'data'=>$data]
                : null;
        }

        $path = $this->path($siteKey, $resourceKey);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        try {
            $document = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($document)
            || ($document['schema'] ?? null) !== self::SCHEMA
            || ($document['site_key'] ?? null) !== $siteKey
            || ($document['resource_key'] ?? null) !== $resourceKey
            || !is_int($document['fetched_at'] ?? null)
            || !is_array($document['data'] ?? null)
        ) {
            return null;
        }
        return ['fetched_at' => $document['fetched_at'], 'data' => $document['data']];
    }

    private function path(string $siteKey, string $resourceKey): string
    {
        if ($this->root === null) {
            throw new \LogicException('Filesystem snapshot storage is not configured');
        }
        return $this->root
            . DIRECTORY_SEPARATOR . hash('sha256', $siteKey)
            . DIRECTORY_SEPARATOR . hash('sha256', $resourceKey) . '.json';
    }

    private function assertKey(string $key, string $label): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException("Invalid $label snapshot key");
        }
    }

    private function assertSecretFree(array $value, string $prefix = ''): void
    {
        foreach ($value as $key => $item) {
            $name = (string)$key;
            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            if (!is_int($key) && preg_match(self::SECRET_KEY_PATTERN, $name) === 1) {
                throw new \InvalidArgumentException("Snapshot data contains a sensitive field: $path");
            }
            if (is_array($item)) {
                $this->assertSecretFree($item, $path);
            } elseif (is_object($item) || is_resource($item)) {
                throw new \InvalidArgumentException("Snapshot data contains a non-serializable field: $path");
            }
        }
    }

    private function safeError(string $message): string
    {
        $message = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));
        $message = (string)preg_replace(
            '/(^|[^A-Za-z0-9])(password|passwd|secret|token|credential|authorization|cookie|api[_-]?key)["\']?\s*[:=]\s*.*/i',
            '$1$2=[redacted]',
            $message
        );
        $message = (string)preg_replace(
            '#(https?://[^:/\s]+:)[^@\s]+@#i',
            '$1[redacted]@',
            $message
        );
        return substr($message !== '' ? $message : 'Site read failed', 0, 500);
    }

    private function now(): int
    {
        return (int)call_user_func($this->clock);
    }

    private function envelope(
        string $siteKey,
        string $resourceKey,
        ?array $data,
        string $source,
        bool $fresh,
        bool $stale,
        bool $offline,
        ?int $fetchedAt,
        ?int $age,
        ?string $error
    ): array {
        return [
            'data' => $data,
            'meta' => [
                'site_key' => $siteKey,
                'resource_key' => $resourceKey,
                'source' => $source,
                'fresh' => $fresh,
                'stale' => $stale,
                'offline' => $offline,
                'fetched_at' => $fetchedAt,
                'age' => $age,
                'error' => $error,
            ],
        ];
    }
}
