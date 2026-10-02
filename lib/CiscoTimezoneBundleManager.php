<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/AssetFilename.php';

/**
 * Persistent index for immutable Cisco timezone payload sets.
 *
 * Payload bytes remain on the owning site's TFTP volume.  The database stores
 * their hashes, compatibility and internal archive location so activation can
 * verify bytes before replacing the phone-visible canonical filenames.
 */
final class CiscoTimezoneBundleManager
{
    private \PDO $db;
    /** @var callable():int */
    private $clock;

    public function __construct(\PDO $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? static fn(): int => time();
        // Runtime readers may join an object transaction. MySQL DDL would
        // implicitly commit it; installation initializes this table beforehand.
        if (!$db->inTransaction()) { self::ensureSchema($db); }
    }

    public static function ensureSchema(\PDO $db): void
    {
        if ($db->inTransaction()) { throw new \LogicException('Timezone schema initialization requires no active transaction'); }
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_timezone_bundles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    site_id INTEGER NOT NULL,
                    bundle_key VARCHAR(64) NOT NULL,
                    served_version VARCHAR(64) NOT NULL,
                    iana_version VARCHAR(16) NULL,
                    status VARCHAR(16) NOT NULL DEFAULT 'partial',
                    compatibility_json TEXT NOT NULL,
                    files_json TEXT NOT NULL,
                    storage_path VARCHAR(255) NOT NULL,
                    active INTEGER NOT NULL DEFAULT 0,
                    created INTEGER NOT NULL DEFAULT 0,
                    activated_at INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (site_id, bundle_key)
                )"
            );
            $db->exec('CREATE INDEX IF NOT EXISTS idx_ucmp_timezone_site_active ON ucmp_timezone_bundles (site_id, active)');
            return;
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_timezone_bundles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                bundle_key CHAR(64) NOT NULL,
                served_version VARCHAR(64) NOT NULL,
                iana_version VARCHAR(16) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'partial',
                compatibility_json LONGTEXT NOT NULL,
                files_json LONGTEXT NOT NULL,
                storage_path VARCHAR(255) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 0,
                created INT NOT NULL DEFAULT 0,
                activated_at INT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_ucmp_timezone_site_bundle (site_id,bundle_key),
                KEY idx_ucmp_timezone_site_active (site_id,active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * @param array<string,mixed> $inspection CiscoTimezoneService::inspect()
     * @param array<int,array<string,mixed>> $storedFiles
     * @return array<string,mixed>
     */
    public function store(
        int $siteId,
        array $inspection,
        array $storedFiles,
        string $storagePath
    ): array {
        if ($siteId < 1) {
            throw new \InvalidArgumentException('Choose a valid site');
        }
        $bundleKey = strtolower(trim((string)($inspection['fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $bundleKey) !== 1) {
            throw new \InvalidArgumentException('Timezone bundle fingerprint is invalid');
        }
        $servedVersion = trim((string)($inspection['served_version'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $servedVersion) !== 1) {
            throw new \InvalidArgumentException('Timezone bundle has no safe served version');
        }
        $ianaVersion = strtolower(trim((string)($inspection['iana_version'] ?? '')));
        if ($ianaVersion !== '' && preg_match('/^20\d{2}[a-z]$/D', $ianaVersion) !== 1) {
            throw new \InvalidArgumentException('Timezone bundle IANA version is invalid');
        }
        $status = (string)($inspection['status'] ?? '');
        if (!in_array($status, ['complete', 'partial', 'quarantined'], true)) {
            throw new \InvalidArgumentException('Timezone bundle status is not retainable');
        }
        $storagePath = trim(str_replace('\\', '/', $storagePath), '/');
        if (preg_match('#^\.ucmprovisioner/timezone-bundles/[a-f0-9]{64}$#D', $storagePath) !== 1) {
            throw new \InvalidArgumentException('Timezone bundle storage path is invalid');
        }

        $files = [];
        foreach ($storedFiles as $file) {
            $name = (string)($file['name'] ?? '');
            $storedName = (string)($file['stored_name'] ?? '');
            $sha256 = strtolower((string)($file['sha256'] ?? ''));
            if (!array_key_exists($name, CiscoTimezoneService::FILES)
                || preg_match('/^[a-f0-9]{64}\.blob$/D', $storedName) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                throw new \InvalidArgumentException('Timezone bundle file manifest is invalid');
            }
            $files[] = [
                'name'=>$name,
                'role'=>(string)CiscoTimezoneService::FILES[$name],
                'stored_name'=>$storedName,
                'size'=>max(0, (int)($file['size'] ?? 0)),
                'sha256'=>$sha256,
            ];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        if (!$files) {
            throw new \InvalidArgumentException('Timezone bundle contains no files');
        }

        $compatibility = (array)($inspection['compatibility'] ?? []);
        $compatibilityJson = json_encode($compatibility, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $filesJson = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = ($this->clock)();
        if ($now < 1) {
            throw new \RuntimeException('Timezone bundle clock returned an invalid timestamp');
        }

        $find = $this->db->prepare('SELECT id FROM ucmp_timezone_bundles WHERE site_id=? AND bundle_key=?');
        $find->execute([$siteId, $bundleKey]);
        $id = (int)$find->fetchColumn();
        if ($id > 0) {
            $update = $this->db->prepare(
                'UPDATE ucmp_timezone_bundles
                    SET served_version=?,iana_version=?,status=?,compatibility_json=?,files_json=?,storage_path=?
                  WHERE id=? AND site_id=?'
            );
            $update->execute([
                $servedVersion, $ianaVersion !== '' ? $ianaVersion : null, $status,
                $compatibilityJson, $filesJson, $storagePath, $id, $siteId,
            ]);
        } else {
            $insert = $this->db->prepare(
                'INSERT INTO ucmp_timezone_bundles
                    (site_id,bundle_key,served_version,iana_version,status,compatibility_json,
                     files_json,storage_path,active,created,activated_at)
                 VALUES (?,?,?,?,?,?,?,?,0,?,0)'
            );
            $insert->execute([
                $siteId, $bundleKey, $servedVersion, $ianaVersion !== '' ? $ianaVersion : null,
                $status, $compatibilityJson, $filesJson, $storagePath, $now,
            ]);
            $id = (int)$this->db->lastInsertId();
        }
        return $this->get($siteId, $id)
            ?? throw new \RuntimeException('Timezone bundle was saved but cannot be read back');
    }

    /** @return array<int,array<string,mixed>> */
    public function list(int $siteId): array
    {
        if ($siteId < 1) {
            throw new \InvalidArgumentException('Choose a valid site');
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM ucmp_timezone_bundles
              WHERE site_id=?
              ORDER BY active DESC,
                       CASE WHEN activated_at>created THEN activated_at ELSE created END DESC,
                       id DESC'
        );
        $stmt->execute([$siteId]);
        return array_map([self::class, 'decode'], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Flatten retained sets into independently selectable runtime assets.
     * The same payload bytes/version are emitted once even when several
     * captured sets contained them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function assets(int $siteId): array
    {
        return self::assetsFromBundles($this->list($siteId));
    }

    /** @return array<int,array<string,mixed>> */
    public static function assetsFromBundles(array $bundles): array
    {
        $assets = [];
        foreach ($bundles as $bundle) {
            if (!is_array($bundle) || (string)($bundle['status'] ?? '') === 'quarantined') { continue; }
            $files = [];
            foreach ((array)($bundle['files'] ?? []) as $file) {
                if (is_array($file) && isset($file['name'])) { $files[(string)$file['name']] = $file; }
            }
            foreach (ModelCatalog::CISCO_TIMEZONE_PROFILES as $profile=>$definition) {
                $payload = (string)$definition['payload'];
                $file = (array)($files[$payload] ?? []);
                $sha256 = strtolower((string)($file['sha256'] ?? ''));
                if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) { continue; }
                $state = (array)(($bundle['compatibility'] ?? [])[$profile] ?? []);
                $usable = !empty($state['configuration_usable'])
                    || in_array((string)($state['status'] ?? ''), ['complete','legacy_frozen','unverified'], true);
                if (!$usable) { continue; }
                $version = trim((string)($state['actual_version'] ?? ''));
                if ($version === '' && !empty($state['version_generated'])) {
                    $version = 'ucmp-' . substr($sha256, 0, 12);
                } elseif ($version === '') {
                    $version = trim((string)($state['advertised_version'] ?? $bundle['served_version'] ?? ''));
                }
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $version) !== 1) {
                    $version = 'ucmp-' . substr($sha256, 0, 12);
                }
                // A retained runtime asset is identified only by its documented
                // architecture and exact payload bytes.  A companion version
                // file, or another architecture uploaded beside it, must never
                // create a duplicate identity for the same phone-facing file.
                $assetKey = hash('sha256', $profile . "\0" . $sha256);
                if (isset($assets[$assetKey])) { continue; }
                $assets[$assetKey] = [
                    'asset_key'=>$assetKey,
                    'profile'=>$profile,
                    'label'=>(string)$definition['label'],
                    'architecture'=>(string)($definition['architecture'] ?? ''),
                    'payload'=>$payload,
                    'version'=>$version,
                    'actual_version'=>(string)($state['actual_version'] ?? ''),
                    'version_source'=>(string)($state['version_inspection'] ?? ''),
                    'models'=>array_values((array)($definition['models'] ?? [])),
                    'firmware_profiles'=>array_values((array)($definition['firmware_profiles'] ?? [])),
                    'sha256'=>$sha256,
                    'size'=>max(0, (int)($file['size'] ?? 0)),
                    'stored_name'=>(string)($file['stored_name'] ?? ''),
                    'storage_path'=>(string)($bundle['storage_path'] ?? ''),
                    'source_bundle_id'=>(int)($bundle['id'] ?? 0),
                    'source_bundle_key'=>(string)($bundle['bundle_key'] ?? ''),
                    'source_bundle_active'=>!empty($bundle['active']),
                    'status'=>(string)($state['status'] ?? 'usable'),
                    'published_filename'=>self::publishedFilename($profile, $assetKey),
                ];
            }
        }
        return array_values($assets);
    }

    /** @return array<string,mixed>|null */
    public function asset(int $siteId, string $profile, string $assetKey): ?array
    {
        $assetKey = strtolower(trim($assetKey));
        foreach ($this->assets($siteId) as $asset) {
            if ((string)$asset['profile'] === $profile && hash_equals((string)$asset['asset_key'], $assetKey)) {
                return $asset;
            }
        }
        return null;
    }

    public static function publishedFilename(string $profile, string $assetKey): string
    {
        if (!isset(ModelCatalog::CISCO_TIMEZONE_PROFILES[$profile])
            || preg_match('/^[a-f0-9]{64}$/D', $assetKey) !== 1) {
            throw new \InvalidArgumentException('Timezone asset identity is invalid');
        }
        $definition = ModelCatalog::CISCO_TIMEZONE_PROFILES[$profile];
        $payload = (string)$definition['payload'];
        $extension = strtolower((string)pathinfo($payload, PATHINFO_EXTENSION));
        $type = ['ucmp-tz-java'=>'a', 'ucmp-tz-j9'=>'j', 'ucmp-tz-csv'=>'c'][(string)$definition['published_prefix']];
        return AssetFilename::fromHash($type, $assetKey, $extension);
    }

    /** @return array<string,mixed>|null */
    public function get(int $siteId, int $id): ?array
    {
        if ($siteId < 1 || $id < 1) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM ucmp_timezone_bundles WHERE site_id=? AND id=?');
        $stmt->execute([$siteId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? self::decode($row) : null;
    }

    /** @return array<string,mixed> */
    public function activate(int $siteId, int $id): array
    {
        $bundle = $this->get($siteId, $id);
        if (!$bundle) {
            throw new \InvalidArgumentException('Choose a timezone bundle from this site');
        }
        if (!in_array((string)($bundle['status'] ?? ''), ['complete', 'partial'], true)) {
            throw new \InvalidArgumentException('A quarantined timezone snapshot cannot be activated');
        }
        $now = ($this->clock)();
        $this->db->beginTransaction();
        try {
            $clear = $this->db->prepare('UPDATE ucmp_timezone_bundles SET active=0 WHERE site_id=?');
            $clear->execute([$siteId]);
            $set = $this->db->prepare(
                'UPDATE ucmp_timezone_bundles SET active=1,activated_at=? WHERE site_id=? AND id=?'
            );
            $set->execute([$now, $siteId, $id]);
            if (!$this->db->commit()) { throw new \RuntimeException('Timezone activation commit was not confirmed'); }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $this->get($siteId, $id)
            ?? throw new \RuntimeException('Activated timezone bundle cannot be read back');
    }

    /**
     * Keep the newest bounded history while never deleting a recorded active row.
     *
     * @return array<int,array<string,mixed>> removed rows, including storage paths
     */
    public function prune(
        int $siteId,
        int $maximum = 3,
        array $protectedIds = [],
        int $maximumQuarantined = 1
    ): array
    {
        $maximum = max(1, min(10, $maximum));
        $maximumQuarantined = max(0, min(3, $maximumQuarantined));
        $rows = $this->list($siteId);
        $keep = [];
        $known = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $rows), true);
        foreach ($protectedIds as $protectedId) {
            $protectedId = (int)$protectedId;
            if (!empty($known[$protectedId])) { $keep[$protectedId] = true; }
        }
        foreach ($rows as $row) {
            if (!empty($row['active'])) { $keep[(int)$row['id']] = true; }
        }
        $profileCounts = array_fill_keys(array_keys(ModelCatalog::CISCO_TIMEZONE_PROFILES), 0);
        $quarantineCount = 0;
        foreach ($rows as $row) {
            if (!isset($keep[(int)$row['id']])) { continue; }
            if (($row['status'] ?? '') === 'quarantined') { $quarantineCount++; }
            else {
                foreach (self::profilesInBundle($row) as $profile) { $profileCounts[$profile]++; }
            }
        }
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($keep[$id]) || ($row['status'] ?? '') === 'quarantined') {
                continue;
            }
            $profiles = self::profilesInBundle($row);
            if (!$profiles || !array_filter(
                $profiles,
                static fn(string $profile): bool => ($profileCounts[$profile] ?? 0) < $maximum
            )) { continue; }
            $keep[$id] = true;
            foreach ($profiles as $profile) { $profileCounts[$profile]++; }
        }
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($keep[$id]) || ($row['status'] ?? '') !== 'quarantined' || $quarantineCount >= $maximumQuarantined) {
                continue;
            }
            $keep[$id] = true;
            $quarantineCount++;
        }
        $removed = array_values(array_filter(
            $rows,
            static fn(array $row): bool => empty($keep[(int)$row['id']])
        ));
        if (!$removed) { return []; }
        $delete = $this->db->prepare('DELETE FROM ucmp_timezone_bundles WHERE site_id=? AND id=? AND active=0');
        $this->db->beginTransaction();
        try {
            foreach ($removed as $row) {
                $delete->execute([$siteId, (int)$row['id']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        return $removed;
    }

    /** @return string[] */
    private static function profilesInBundle(array $row): array
    {
        $profiles = [];
        foreach (ModelCatalog::CISCO_TIMEZONE_PROFILES as $profile=>$definition) {
            $state = (array)(($row['compatibility'] ?? [])[$profile] ?? []);
            if (!empty($state['configuration_usable'])) { $profiles[] = $profile; }
        }
        return $profiles;
    }

    /** @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['compatibility_json'=>'compatibility', 'files_json'=>'files'] as $column => $field) {
            $decoded = json_decode((string)($row[$column] ?? ''), true);
            $row[$field] = is_array($decoded) ? $decoded : [];
            unset($row[$column]);
        }
        foreach (['id', 'site_id', 'active', 'created', 'activated_at'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        return $row;
    }
}
