<?php
declare(strict_types=1);

/**
 * Site-scoped, hash-addressed inventory for managed provisioning assets.
 *
 * The registry stores only sanitized relative paths and whitelisted inspection
 * metadata; never absolute paths, URLs, credentials, or caller-defined blobs.
 * Asset content remains owned by the existing media, firmware, and phonebook
 * services. The numeric ID and immutable content address are safe selection
 * handles within their owning site.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

final class ManagedAssetRegistry
{
    public const KINDS = ['firmware', 'background', 'ringtone', 'locale', 'phonebook'];
    public const VALIDATION_STATUSES = ['discovered', 'incomplete', 'verified', 'invalid'];
    public const SIGNATURE_STATUSES = ['unverified', 'not_applicable', 'verified', 'invalid', 'unavailable'];
    public const CATALOG_REVISION = '2026-07-26.1';

    private const INPUT_FIELDS = [
        'site_id', 'kind', 'content_sha256', 'filename', 'size_bytes',
        'content_type', 'vendor', 'family', 'series_key', 'model', 'role',
        'profile', 'asset_version', 'bundle_key', 'bundle_role', 'companions',
        'relative_path', 'validation_status', 'provenance', 'manifest',
        'validated_at', 'catalog_revision', 'signature_status', 'metadata',
    ];

    private \PDO $db;
    private \Closure $clock;

    public function __construct(\PDO $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock === null
            ? static fn(): int => time()
            : \Closure::fromCallable($clock);
    }

    /**
     * FreePBX normally creates this table from module.xml. The install hook
     * calls this idempotent fallback so both fresh installs and upgrades have
     * the registry before later services begin writing inventory.
     */
    public static function ensureSchema(\PDO $db): void
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_assets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    site_id INTEGER NOT NULL,
                    asset_key VARCHAR(64) NOT NULL,
                    kind VARCHAR(16) NOT NULL,
                    content_sha256 VARCHAR(64) NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    size_bytes INTEGER NOT NULL DEFAULT 0,
                    content_type VARCHAR(96) NULL,
                    vendor VARCHAR(32) NULL,
                    family VARCHAR(32) NULL,
                    series_key VARCHAR(64) NULL,
                    model VARCHAR(128) NULL,
                    role VARCHAR(24) NULL,
                    profile VARCHAR(64) NULL,
                    asset_version VARCHAR(64) NULL,
                    bundle_key VARCHAR(128) NULL,
                    bundle_role VARCHAR(32) NULL,
                    companions_json TEXT NULL,
                    relative_path TEXT NULL,
                    validation_status VARCHAR(16) NOT NULL DEFAULT 'discovered',
                    provenance VARCHAR(32) NOT NULL DEFAULT 'discovered',
                    manifest_json TEXT NULL,
                    validated_at INTEGER NOT NULL DEFAULT 0,
                    catalog_revision VARCHAR(32) NULL,
                    signature_status VARCHAR(16) NOT NULL DEFAULT 'unverified',
                    metadata_json TEXT NULL,
                    state VARCHAR(16) NOT NULL DEFAULT 'current',
                    first_seen INTEGER NOT NULL DEFAULT 0,
                    last_seen INTEGER NOT NULL DEFAULT 0,
                    updated INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (site_id, asset_key)
                )"
            );
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ucmp_assets_site_kind_state ON ucmp_assets (site_id, kind, state)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ucmp_assets_site_bundle ON ucmp_assets (site_id, bundle_key)");
            self::ensureLifecycleColumns($db);
            return;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `ucmp_assets` (
                `id` INTEGER NOT NULL AUTO_INCREMENT,
                `site_id` INTEGER NOT NULL,
                `asset_key` VARCHAR(64) NOT NULL,
                `kind` VARCHAR(16) NOT NULL,
                `content_sha256` VARCHAR(64) NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `size_bytes` INTEGER NOT NULL DEFAULT 0,
                `content_type` VARCHAR(96) NULL,
                `vendor` VARCHAR(32) NULL,
                `family` VARCHAR(32) NULL,
                `series_key` VARCHAR(64) NULL,
                `model` VARCHAR(128) NULL,
                `role` VARCHAR(24) NULL,
                `profile` VARCHAR(64) NULL,
                `asset_version` VARCHAR(64) NULL,
                `bundle_key` VARCHAR(128) NULL,
                `bundle_role` VARCHAR(32) NULL,
                `companions_json` LONGTEXT NULL,
                `relative_path` TEXT NULL,
                `validation_status` VARCHAR(16) NOT NULL DEFAULT 'discovered',
                `provenance` VARCHAR(32) NOT NULL DEFAULT 'discovered',
                `manifest_json` LONGTEXT NULL,
                `validated_at` INTEGER NOT NULL DEFAULT 0,
                `catalog_revision` VARCHAR(32) NULL,
                `signature_status` VARCHAR(16) NOT NULL DEFAULT 'unverified',
                `metadata_json` LONGTEXT NULL,
                `state` VARCHAR(16) NOT NULL DEFAULT 'current',
                `first_seen` INTEGER NOT NULL DEFAULT 0,
                `last_seen` INTEGER NOT NULL DEFAULT 0,
                `updated` INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ucmp_assets_site_key` (`site_id`, `asset_key`),
                KEY `idx_ucmp_assets_site_kind_state` (`site_id`, `kind`, `state`),
                KEY `idx_ucmp_assets_site_bundle` (`site_id`, `bundle_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::ensureLifecycleColumns($db);
    }

    public static function address(string $kind, string $contentSha256, string $filename, ?string $profile = null): string
    {
        $kind = self::kind($kind);
        $contentSha256 = self::sha256($contentSha256);
        $filename = self::filename($filename);
        $profile = self::identifier($profile, 64, 'profile') ?? '';
        return hash('sha256', $kind . "\0" . $profile . "\0" . $contentSha256 . "\0" . $filename);
    }

    /**
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    public function upsert(array $asset): array
    {
        $unknown = array_diff(array_keys($asset), self::INPUT_FIELDS);
        if ($unknown) {
            throw new \InvalidArgumentException('Unsupported asset fields: ' . implode(', ', $unknown));
        }

        $siteId = self::siteId($asset['site_id'] ?? null);
        $kind = self::kind((string)($asset['kind'] ?? ''));
        $contentSha256 = self::sha256((string)($asset['content_sha256'] ?? ''));
        $filename = self::filename((string)($asset['filename'] ?? ''));
        $profile = self::identifier($asset['profile'] ?? null, 64, 'profile');
        $assetKey = self::address($kind, $contentSha256, $filename, $profile);
        $now = ($this->clock)();
        if ($now < 1) {
            throw new \RuntimeException('Asset registry clock returned an invalid timestamp');
        }

        $companions = self::companions($asset['companions'] ?? [], $assetKey, $profile);
        $manifest = self::manifest($asset['manifest'] ?? []);
        $metadata = self::metadata($asset['metadata'] ?? []);
        $row = [
            'site_id' => $siteId,
            'asset_key' => $assetKey,
            'kind' => $kind,
            'content_sha256' => $contentSha256,
            'filename' => $filename,
            'size_bytes' => self::size($asset['size_bytes'] ?? 0),
            'content_type' => self::contentType($asset['content_type'] ?? null),
            'vendor' => self::identifier($asset['vendor'] ?? null, 32, 'vendor'),
            'family' => self::identifier($asset['family'] ?? null, 32, 'family'),
            'series_key' => self::identifier($asset['series_key'] ?? null, 64, 'series key'),
            'model' => self::identifier($asset['model'] ?? null, 128, 'model'),
            'role' => self::identifier($asset['role'] ?? null, 24, 'role'),
            'profile' => $profile,
            'asset_version' => self::identifier($asset['asset_version'] ?? null, 64, 'asset version'),
            'bundle_key' => self::identifier($asset['bundle_key'] ?? null, 128, 'bundle key'),
            'bundle_role' => self::identifier($asset['bundle_role'] ?? null, 32, 'bundle role'),
            'companions_json' => $companions
                ? json_encode($companions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : null,
            'relative_path' => self::relativePath($asset['relative_path'] ?? null),
            'validation_status' => self::validationStatus($asset['validation_status'] ?? 'discovered'),
            'provenance' => self::identifier($asset['provenance'] ?? 'discovered', 32, 'provenance'),
            'manifest_json' => $manifest
                ? json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : null,
            'validated_at' => self::timestamp($asset['validated_at'] ?? 0, 'validation time'),
            'catalog_revision' => self::identifier(
                $asset['catalog_revision'] ?? self::CATALOG_REVISION,
                32,
                'catalog revision'
            ),
            'signature_status' => self::signatureStatus($asset['signature_status'] ?? 'unverified'),
            'metadata_json' => $metadata
                ? json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : null,
            'state' => 'current',
            'first_seen' => $now,
            'last_seen' => $now,
            'updated' => $now,
        ];

        $columns = array_keys($row);
        $quoted = array_map(static fn(string $column): string => "`$column`", $columns);
        $placeholders = array_map(static fn(string $column): string => ":$column", $columns);
        $mutable = array_values(array_diff($columns, ['site_id', 'asset_key', 'first_seen']));
        // A rescan can prove bytes/path compatibility without knowing richer
        // metadata previously recorded by a manager (for example wallpaper
        // companions or an operator-supplied firmware version). Omitted
        // optional fields therefore retain their existing value; callers can
        // still clear one explicitly by supplying null.
        foreach ([
            'vendor', 'family', 'series_key', 'model', 'role', 'profile',
            'asset_version', 'bundle_key', 'bundle_role',
            'companions_json' => 'companions',
            'relative_path', 'validation_status', 'provenance',
            'manifest_json' => 'manifest', 'validated_at', 'catalog_revision',
            'signature_status', 'metadata_json' => 'metadata',
        ] as $column => $inputField) {
            if (is_int($column)) {
                $column = $inputField;
            }
            if (!array_key_exists($inputField, $asset)) {
                $mutable = array_values(array_diff($mutable, [$column]));
            }
        }
        $sqlite = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $assignments = array_map(
            static fn(string $column): string => $sqlite
                ? "`$column` = excluded.`$column`"
                : "`$column` = VALUES(`$column`)",
            $mutable
        );
        $assignments[] = '`observation_revision` = `observation_revision` + 1';
        $conflict = $sqlite
            ? "ON CONFLICT (`site_id`, `asset_key`) DO UPDATE SET " . implode(', ', $assignments)
            : "ON DUPLICATE KEY UPDATE " . implode(', ', $assignments);
        $statement = $this->db->prepare(
            "INSERT INTO `ucmp_assets` (" . implode(', ', $quoted) . ")
             VALUES (" . implode(', ', $placeholders) . ") $conflict"
        );
        $statement->execute($row);

        return $this->get($siteId, $assetKey)
            ?? throw new \RuntimeException('Managed asset upsert did not return the stored row');
    }

    /** @return array<string,mixed>|null */
    public function get(int $siteId, string $assetKey): ?array
    {
        $siteId = self::siteId($siteId);
        $assetKey = self::sha256($assetKey);
        $statement = $this->db->prepare(
            "SELECT * FROM `ucmp_assets` WHERE `site_id` = ? AND `asset_key` = ? LIMIT 1"
        );
        $statement->execute([$siteId, $assetKey]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function getById(int $siteId, int $assetId): ?array
    {
        $siteId = self::siteId($siteId);
        if ($assetId < 1) {
            throw new \InvalidArgumentException('Asset ID must be a positive integer');
        }
        $statement = $this->db->prepare(
            "SELECT * FROM `ucmp_assets` WHERE `site_id` = ? AND `id` = ? LIMIT 1"
        );
        $statement->execute([$siteId, $assetId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Persist a validator result without changing the immutable content
     * address. Only recognized, secret-free manifest/inspection fields enter
     * the catalog.
     *
     * @param array<int,array<string,mixed>> $manifest
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function recordValidation(
        int $siteId,
        int $assetId,
        string $status,
        array $manifest = [],
        array $metadata = [],
        ?string $version = null,
        string $signatureStatus = 'unverified',
        ?int $validatedAt = null
    ): array {
        $asset = $this->getById($siteId, $assetId);
        if ($asset === null) {
            throw new \InvalidArgumentException('Managed asset was not found on this site');
        }
        $status = self::validationStatus($status);
        $signatureStatus = self::signatureStatus($signatureStatus);
        $manifestJson = self::manifest($manifest);
        $metadataJson = self::metadata($metadata);
        $validatedAt = self::timestamp($validatedAt ?? ($this->clock)(), 'validation time');
        if ($validatedAt < 1) {
            throw new \InvalidArgumentException('Validation time must be positive');
        }
        $version = self::identifier($version, 64, 'asset version');
        $statement = $this->db->prepare(
            "UPDATE `ucmp_assets`
                SET `validation_status` = :status,
                    `manifest_json` = :manifest,
                    `metadata_json` = :metadata,
                    `asset_version` = :version,
                    `signature_status` = :signature_status,
                    `validated_at` = :validated_at,
                    `catalog_revision` = :catalog_revision,
                    `updated` = :updated
              WHERE `site_id` = :site_id AND `id` = :asset_id"
        );
        $statement->execute([
            'status'=>$status,
            'manifest'=>$manifestJson ? json_encode($manifestJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
            'metadata'=>$metadataJson ? json_encode($metadataJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
            'version'=>$version,
            'signature_status'=>$signatureStatus,
            'validated_at'=>$validatedAt,
            'catalog_revision'=>self::CATALOG_REVISION,
            'updated'=>($this->clock)(),
            'site_id'=>self::siteId($siteId),
            'asset_id'=>$assetId,
        ]);
        return $this->getById($siteId, $assetId)
            ?? throw new \RuntimeException('Managed asset validation did not return the stored row');
    }

    /**
     * Lists current assets unless `state` is explicitly `stale` or `all`.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(int $siteId, array $filters = []): array
    {
        $allowed = [
            'kind', 'state', 'validation_status', 'bundle_key', 'vendor',
            'family', 'profile', 'provenance', 'signature_status', 'limit', 'offset',
        ];
        $unknown = array_diff(array_keys($filters), $allowed);
        if ($unknown) {
            throw new \InvalidArgumentException('Unsupported asset filters: ' . implode(', ', $unknown));
        }

        $where = ['`site_id` = :site_id'];
        $params = ['site_id' => self::siteId($siteId)];
        if (isset($filters['kind'])) {
            $where[] = '`kind` = :kind';
            $params['kind'] = self::kind((string)$filters['kind']);
        }
        $state = strtolower(trim((string)($filters['state'] ?? 'current')));
        if (!in_array($state, ['current', 'stale', 'all'], true)) {
            throw new \InvalidArgumentException('Asset state must be current, stale, or all');
        }
        if ($state !== 'all') {
            $where[] = '`state` = :state';
            $params['state'] = $state;
        }
        if (isset($filters['validation_status'])) {
            $where[] = '`validation_status` = :validation_status';
            $params['validation_status'] = self::validationStatus((string)$filters['validation_status']);
        }
        if (isset($filters['signature_status'])) {
            $where[] = '`signature_status` = :signature_status';
            $params['signature_status'] = self::signatureStatus((string)$filters['signature_status']);
        }
        foreach (['bundle_key'=>128, 'vendor'=>32, 'family'=>32, 'profile'=>64] as $field => $limit) {
            if (!array_key_exists($field, $filters)) { continue; }
            $value = self::identifier($filters[$field], $limit, str_replace('_', ' ', $field));
            if ($value === null) {
                $where[] = "`$field` IS NULL";
            } else {
                $where[] = "`$field` = :$field";
                $params[$field] = $value;
            }
        }
        if (array_key_exists('provenance', $filters)) {
            $value = self::identifier($filters['provenance'], 32, 'provenance');
            if ($value === null) {
                $where[] = '`provenance` IS NULL';
            } else {
                $where[] = '`provenance` = :provenance';
                $params['provenance'] = $value;
            }
        }
        $limit = self::limit($filters['limit'] ?? 200);
        $offset=filter_var($filters['offset'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);
        if ($offset===false) { throw new \InvalidArgumentException('Asset offset is outside the supported range'); }
        $statement = $this->db->prepare(
            "SELECT * FROM `ucmp_assets`
             WHERE " . implode(' AND ', $where) . "
             ORDER BY `kind`, COALESCE(`vendor`, ''), `filename`, `asset_key`
             LIMIT $limit OFFSET $offset"
        );
        $statement->execute($params);
        return array_map(
            static fn(array $row): array => self::hydrate($row),
            $statement->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /** Complete internal enumeration, independent of browser display pagination. */
    public function all(int $siteId, array $filters = []): array
    {
        unset($filters['limit'],$filters['offset']); $rows=[];
        for ($offset=0;$offset<=100000;$offset+=500) {
            $page=$this->list($siteId,$filters+['limit'=>500,'offset'=>$offset]);
            $rows=array_merge($rows,$page);
            if (count($page)<500) { return $rows; }
        }
        throw new \LengthException('Complete asset inventory exceeds the supported bound; deletion is blocked');
    }

    /**
     * Marks assets not observed in the completed site/kind scan as stale.
     */
    public function scanCandidates(int $siteId, array $kinds): array
    {
        $kinds=array_map([self::class,'kind'],$kinds);
        if (!$kinds) { throw new \InvalidArgumentException('A scan must select asset kinds'); }
        $query=$this->db->prepare("SELECT id,observation_revision FROM ucmp_assets WHERE site_id=? AND state='current' AND kind IN (".implode(',',array_fill(0,count($kinds),'?')).') LIMIT 50001');
        $query->execute(array_merge([self::siteId($siteId)],$kinds));$rows=$query->fetchAll(\PDO::FETCH_KEY_PAIR);
        if (count($rows)>50000) { throw new \LengthException('Asset scan baseline exceeds its supported bound'); }
        return array_map('intval',$rows);
    }

    /** Retire only unchanged observations from before this complete scan. */
    public function retireUnobserved(int $siteId,array $baseline): int
    {
        if (count($baseline)>50000) { throw new \LengthException('Asset scan baseline exceeds its supported bound'); }
        $statement=$this->db->prepare("UPDATE ucmp_assets SET state='stale',updated=? WHERE site_id=? AND id=? AND observation_revision=? AND state='current'");
        $count=0;$started=!$this->db->inTransaction();
        if ($started&&!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot start scan reconciliation'); }
        try {
            foreach ($baseline as $id=>$revision) {
                if ((int)$id<1||(int)$revision<0) { throw new \InvalidArgumentException('Invalid asset scan observation'); }
                $statement->execute([($this->clock)(),self::siteId($siteId),(int)$id,(int)$revision]);$count+=$statement->rowCount();
            }
            if ($started&&!$this->db->commit()) { throw new \RuntimeException('Scan reconciliation commit was not confirmed'); }
            return $count;
        } catch (\Throwable $error) {
            if ($started&&$this->db->inTransaction()) { $this->db->rollBack(); }throw $error;
        }
    }

    public function markStale(int $siteId, string $kind, int $seenBefore): int
    {
        $siteId = self::siteId($siteId);
        $kind = self::kind($kind);
        if ($seenBefore < 1) {
            throw new \InvalidArgumentException('Stale cutoff must be a positive timestamp');
        }
        $updated = ($this->clock)();
        if ($updated < 1) {
            throw new \RuntimeException('Asset registry clock returned an invalid timestamp');
        }
        $statement = $this->db->prepare(
            "UPDATE `ucmp_assets`
             SET `state` = 'stale', `updated` = :updated
             WHERE `site_id` = :site_id AND `kind` = :kind
               AND `state` = 'current' AND `last_seen` < :cutoff"
        );
        $statement->execute([
            'updated' => $updated,
            'site_id' => $siteId,
            'kind' => $kind,
            'cutoff' => $seenBefore,
        ]);
        return $statement->rowCount();
    }

    /**
     * Retire the previous content occupying one logical TFTP asset slot.
     *
     * A slot includes profile and bundle identity so deleting or replacing a
     * common basename such as List.xml cannot stale another resolution or
     * firmware directory. Pass the current asset key after a successful write;
     * omit it after a successful delete.
     */
    public function markSlotStale(
        int $siteId,
        string $kind,
        string $filename,
        ?string $profile,
        ?string $bundleKey,
        ?string $currentAssetKey = null
    ): int {
        $siteId = self::siteId($siteId);
        $kind = self::kind($kind);
        $filename = self::filename($filename);
        $profile = self::identifier($profile, 64, 'profile');
        $bundleKey = self::identifier($bundleKey, 128, 'bundle key');
        $params = [
            'updated' => ($this->clock)(),
            'site_id' => $siteId,
            'kind' => $kind,
            'filename' => $filename,
            'profile' => $profile ?? '',
            'bundle_key' => $bundleKey ?? '',
        ];
        if ($params['updated'] < 1) {
            throw new \RuntimeException('Asset registry clock returned an invalid timestamp');
        }
        $currentClause = '';
        if ($currentAssetKey !== null) {
            $params['current_asset_key'] = self::sha256($currentAssetKey);
            $currentClause = ' AND `asset_key` <> :current_asset_key';
        }
        $statement = $this->db->prepare(
            "UPDATE `ucmp_assets`
             SET `state` = 'stale', `updated` = :updated
             WHERE `site_id` = :site_id AND `kind` = :kind
               AND `filename` = :filename
               AND COALESCE(`profile`, '') = :profile
               AND COALESCE(`bundle_key`, '') = :bundle_key
               AND `state` = 'current'$currentClause"
        );
        $statement->execute($params);
        return $statement->rowCount();
    }

    /**
     * Marks every other version stale after a caller has published a complete
     * current set. A non-empty explicit set is required so partial scans and
     * error paths cannot accidentally retire a site's whole asset catalog.
     *
     * @param string[] $currentAssetKeys
     */
    public function markOtherVersionsStale(
        int $siteId,
        string $kind,
        array $currentAssetKeys,
        ?string $bundleKey = null
    ): int
    {
        $siteId = self::siteId($siteId);
        $kind = self::kind($kind);
        if (!$currentAssetKeys || count($currentAssetKeys) > 500) {
            throw new \InvalidArgumentException('Current asset set must contain between 1 and 500 addresses');
        }
        $keys = [];
        foreach ($currentAssetKeys as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Current asset addresses must be SHA-256 strings');
            }
            $keys[self::sha256($key)] = true;
        }
        $params = [
            'updated' => ($this->clock)(),
            'site_id' => $siteId,
            'kind' => $kind,
        ];
        if ($params['updated'] < 1) {
            throw new \RuntimeException('Asset registry clock returned an invalid timestamp');
        }
        $placeholders = [];
        foreach (array_keys($keys) as $index => $key) {
            $name = 'current_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $key;
        }
        $bundleClause = '';
        if ($bundleKey !== null) {
            $normalizedBundle = self::identifier($bundleKey, 128, 'bundle key');
            if ($normalizedBundle === null) {
                throw new \InvalidArgumentException('Asset bundle key cannot be empty');
            }
            $params['bundle_key'] = $normalizedBundle;
            $bundleClause = ' AND `bundle_key` = :bundle_key';
        }
        $statement = $this->db->prepare(
            "UPDATE `ucmp_assets`
             SET `state` = 'stale', `updated` = :updated
             WHERE `site_id` = :site_id AND `kind` = :kind
               AND `state` = 'current'
               $bundleClause
               AND `asset_key` NOT IN (" . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);
        return $statement->rowCount();
    }

    private static function ensureLifecycleColumns(\PDO $db): void
    {
        $sqlite = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite) {
            $existing = [];
            foreach ($db->query("PRAGMA table_info('ucmp_assets')")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $column) {
                $existing[(string)$column['name']] = true;
            }
            $definitions = [
                'relative_path'=>"TEXT NULL",
                'validation_status'=>"VARCHAR(16) NOT NULL DEFAULT 'discovered'",
                'provenance'=>"VARCHAR(32) NOT NULL DEFAULT 'discovered'",
                'manifest_json'=>"TEXT NULL",
                'validated_at'=>"INTEGER NOT NULL DEFAULT 0",
                'catalog_revision'=>"VARCHAR(32) NULL",
                'signature_status'=>"VARCHAR(16) NOT NULL DEFAULT 'unverified'",
                'metadata_json'=>"TEXT NULL",
            ];
        } else {
            $existing = [];
            foreach ($db->query("SHOW COLUMNS FROM `ucmp_assets`")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $column) {
                $existing[(string)$column['Field']] = true;
            }
            $definitions = [
                'relative_path'=>"TEXT NULL",
                'validation_status'=>"VARCHAR(16) NOT NULL DEFAULT 'discovered'",
                'provenance'=>"VARCHAR(32) NOT NULL DEFAULT 'discovered'",
                'manifest_json'=>"LONGTEXT NULL",
                'validated_at'=>"INTEGER NOT NULL DEFAULT 0",
                'catalog_revision'=>"VARCHAR(32) NULL",
                'signature_status'=>"VARCHAR(16) NOT NULL DEFAULT 'unverified'",
                'metadata_json'=>"LONGTEXT NULL",
            ];
        }
        $definitions['observation_revision']='INTEGER NOT NULL DEFAULT 0';
        foreach ($definitions as $column => $definition) {
            if (!isset($existing[$column])) {
                $db->exec("ALTER TABLE `ucmp_assets` ADD COLUMN `$column` $definition");
            }
        }
    }

    /** @param mixed $value */
    private static function siteId($value): int
    {
        $siteId = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($siteId === false) {
            throw new \InvalidArgumentException('Asset site ID must be a positive integer');
        }
        return (int)$siteId;
    }

    private static function kind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        $kind = ['wallpaper'=>'background', 'timezone'=>'locale', 'time_zone'=>'locale'][$kind] ?? $kind;
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported managed asset kind');
        }
        return $kind;
    }

    private static function sha256(string $hash): string
    {
        $hash = strtolower(trim($hash));
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \InvalidArgumentException('Managed assets require an exact SHA-256 hash');
        }
        return $hash;
    }

    private static function filename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '' || strlen($filename) > 255
            || $filename === '.' || $filename === '..'
            || str_contains($filename, '/') || str_contains($filename, '\\')
            || preg_match('/^[\pL\pN][\pL\pN ._()+@-]*$/uD', $filename) !== 1) {
            throw new \InvalidArgumentException('Asset filename must be a safe basename, not a path');
        }
        return $filename;
    }

    /** @param mixed $value */
    private static function size($value): int
    {
        $size = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0, 'max_range'=>2147483647]]);
        if ($size === false) {
            throw new \InvalidArgumentException('Asset size must be between 0 and 2147483647 bytes');
        }
        return (int)$size;
    }

    /** @param mixed $value */
    private static function contentType($value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || strlen($value) > 96
            || preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/iD', $value) !== 1) {
            throw new \InvalidArgumentException('Asset content type is invalid');
        }
        return strtolower($value);
    }

    /** @param mixed $value */
    private static function relativePath($value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Asset relative path must be text');
        }
        $value = trim(str_replace('\\', '/', $value), '/');
        if ($value === '' || strlen($value) > 512 || str_contains($value, "\0")
            || str_contains($value, '?') || str_contains($value, '#')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $value) === 1
            || preg_match('#^[\pL\pN][\pL\pN ._()+@/-]*$#uD', $value) !== 1) {
            throw new \InvalidArgumentException('Asset relative path is unsafe');
        }
        return $value;
    }

    private static function validationStatus(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::VALIDATION_STATUSES, true)) {
            throw new \InvalidArgumentException('Asset validation status is invalid');
        }
        return $value;
    }

    private static function signatureStatus(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::SIGNATURE_STATUSES, true)) {
            throw new \InvalidArgumentException('Asset signature status is invalid');
        }
        return $value;
    }

    /** @param mixed $value */
    private static function timestamp($value, string $label): int
    {
        $timestamp = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0, 'max_range'=>2147483647]]);
        if ($timestamp === false) {
            throw new \InvalidArgumentException("Asset $label must be a valid Unix timestamp");
        }
        return (int)$timestamp;
    }

    /** @param mixed $value */
    private static function identifier($value, int $limit, string $label): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Asset $label must be text");
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $limit
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:@()+-]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException("Asset $label contains unsafe characters");
        }
        return $value;
    }

    /** @param mixed $value */
    private static function limit($value): int
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>500]]);
        if ($limit === false) {
            throw new \InvalidArgumentException('Asset list limit must be between 1 and 500');
        }
        return (int)$limit;
    }

    /**
     * @param mixed $value
     * @return array<int,array{asset_key:string,kind:string,relation:string,required:bool}>
     */
    private static function companions($value, string $selfKey, ?string $parentProfile): array
    {
        if ($value === null || $value === []) { return []; }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Asset companions must be a list');
        }
        $normalized = [];
        foreach ($value as $companion) {
            if (!is_array($companion)) {
                throw new \InvalidArgumentException('Each asset companion must be an object');
            }
            $allowed = ['kind', 'content_sha256', 'filename', 'profile', 'relation', 'required'];
            $unknown = array_diff(array_keys($companion), $allowed);
            if ($unknown) {
                throw new \InvalidArgumentException('Unsupported companion fields: ' . implode(', ', $unknown));
            }
            $kind = self::kind((string)($companion['kind'] ?? ''));
            $profile = self::identifier($companion['profile'] ?? $parentProfile, 64, 'companion profile');
            $key = self::address(
                $kind,
                (string)($companion['content_sha256'] ?? ''),
                (string)($companion['filename'] ?? ''),
                $profile
            );
            if ($key === $selfKey) {
                throw new \InvalidArgumentException('An asset cannot be its own companion');
            }
            $relation = self::identifier($companion['relation'] ?? 'companion', 32, 'companion relation');
            $required = $companion['required'] ?? true;
            if (!is_bool($required)) {
                throw new \InvalidArgumentException('Companion required must be boolean');
            }
            $normalized[$key . "\0" . $relation] = [
                'asset_key' => $key,
                'kind' => $kind,
                'profile' => $profile,
                'relation' => (string)$relation,
                'required' => $required,
            ];
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private static function manifest($value): array
    {
        if ($value === null || $value === []) { return []; }
        if (!is_array($value) || !array_is_list($value) || count($value) > 256) {
            throw new \InvalidArgumentException('Asset manifest must be a list of at most 256 files');
        }
        $normalized = [];
        foreach ($value as $file) {
            if (!is_array($file)) {
                throw new \InvalidArgumentException('Each asset manifest file must be an object');
            }
            $allowed = [
                'role', 'relative_path', 'filename', 'size_bytes',
                'content_sha256', 'content_type', 'parsed_version',
                'asset_key', 'required', 'status',
            ];
            $unknown = array_diff(array_keys($file), $allowed);
            if ($unknown) {
                throw new \InvalidArgumentException('Unsupported asset manifest fields: ' . implode(', ', $unknown));
            }
            $path = self::relativePath($file['relative_path'] ?? null);
            $filename = isset($file['filename'])
                ? self::filename((string)$file['filename'])
                : ($path !== null ? basename($path) : null);
            if ($filename === null) {
                throw new \InvalidArgumentException('Asset manifest files require a filename or relative path');
            }
            $required = $file['required'] ?? true;
            if (!is_bool($required)) {
                throw new \InvalidArgumentException('Asset manifest required must be boolean');
            }
            $row = [
                'role'=>self::identifier($file['role'] ?? 'file', 32, 'manifest role'),
                'relative_path'=>$path ?? $filename,
                'filename'=>$filename,
                'required'=>$required,
            ];
            if (array_key_exists('size_bytes', $file)) { $row['size_bytes'] = self::size($file['size_bytes']); }
            if (array_key_exists('content_sha256', $file)) { $row['content_sha256'] = self::sha256((string)$file['content_sha256']); }
            if (array_key_exists('content_type', $file)) { $row['content_type'] = self::contentType($file['content_type']); }
            if (array_key_exists('parsed_version', $file)) {
                $row['parsed_version'] = self::identifier($file['parsed_version'], 64, 'manifest parsed version');
            }
            if (array_key_exists('asset_key', $file)) { $row['asset_key'] = self::sha256((string)$file['asset_key']); }
            if (array_key_exists('status', $file)) {
                $row['status'] = self::identifier($file['status'], 24, 'manifest status');
            }
            $normalized[] = array_filter($row, static fn($item): bool => $item !== null);
        }
        usort($normalized, static fn(array $left, array $right): int =>
            strcmp((string)$left['relative_path'], (string)$right['relative_path'])
                ?: strcmp((string)$left['role'], (string)$right['role'])
        );
        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function metadata($value): array
    {
        if ($value === null || $value === []) { return []; }
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Asset inspection metadata must be an object');
        }
        $allowed = [
            'width', 'height', 'bits', 'channels', 'format', 'animated',
            'alpha', 'container', 'container_valid', 'audio_codec', 'sample_rate',
            'channel_count', 'duration_ms', 'compression', 'magic',
            'olson_zone', 'timezone_profile', 'timezone_asset_key', 'canonical_payload', 'architecture',
            'declared_tzdata_version', 'version_evidence', 'locale_profiles',
            'display_name',
        ];
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown) {
            throw new \InvalidArgumentException('Unsupported asset inspection metadata: ' . implode(', ', $unknown));
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            if (in_array($key, ['animated', 'alpha', 'container_valid'], true)) {
                if (!is_bool($item)) {
                    throw new \InvalidArgumentException("Asset metadata $key must be boolean");
                }
                $normalized[$key] = $item;
                continue;
            }
            if (in_array($key, ['width','height','bits','channels','sample_rate','channel_count','duration_ms'], true)) {
                $number = filter_var($item, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0, 'max_range'=>2147483647]]);
                if ($number === false) {
                    throw new \InvalidArgumentException("Asset metadata $key must be a non-negative integer");
                }
                $normalized[$key] = (int)$number;
                continue;
            }
            if (!is_scalar($item)) {
                throw new \InvalidArgumentException("Asset metadata $key must be scalar");
            }
            $text = trim((string)$item);
            if ($text === '' || strlen($text) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $text) === 1
                || preg_match('/(?:password|passwd|secret|token|credential|authorization|cookie|api[_-]?key)\s*[:=]/i', $text) === 1) {
                throw new \InvalidArgumentException("Asset metadata $key contains unsafe text");
            }
            $normalized[$key] = $text;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        $companions = [];
        if (is_string($row['companions_json'] ?? null) && $row['companions_json'] !== '') {
            $decoded = json_decode($row['companions_json'], true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) { $companions = $decoded; }
        }
        $manifest = [];
        if (is_string($row['manifest_json'] ?? null) && $row['manifest_json'] !== '') {
            $decoded = json_decode($row['manifest_json'], true, 64, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) { $manifest = $decoded; }
        }
        $metadata = [];
        if (is_string($row['metadata_json'] ?? null) && $row['metadata_json'] !== '') {
            $decoded = json_decode($row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) { $metadata = $decoded; }
        }
        unset($row['companions_json'], $row['manifest_json'], $row['metadata_json']);
        foreach (['id', 'site_id', 'size_bytes', 'validated_at', 'first_seen', 'last_seen', 'updated'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['asset_id'] = $row['id'];
        $row['companions'] = $companions;
        $row['manifest'] = $manifest;
        $row['metadata'] = $metadata;
        return $row;
    }
}
