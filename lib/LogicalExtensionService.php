<?php
declare(strict_types=1);

/**
 * Logical extension identity and persistence.
 *
 * A site must be selected by an accepted AD mapping rule before this service is
 * called.  PBX presence is only an observation and is never used to infer the
 * identity's site or canonical fields.
 */

namespace FreePBX\modules\Ucmprovisioner;

final class LogicalExtensionService
{
    /** Configuration revision in the existing timestamp field, including same-second edits. */
    public static function nextPhoneUpdate(array $phone, ?int $now = null): int
    {
        return max($now ?? time(), (int)($phone['updated'] ?? 0) + 1, (int)($phone['last_applied'] ?? 0) + 1);
    }

    /** One bound clock value; both supported databases evaluate against the current row. */
    public static function phoneUpdateExpression(\PDO $db): string
    {
        $maximum = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'MAX' : 'GREATEST';
        return "$maximum(?+0,COALESCE(updated,0)+1,COALESCE(last_applied,0)+1)";
    }

    public const CANONICAL_FIELDS = [
        'display_name',
        'given_name',
        'initial',
        'surname',
        'label',
        'extension',
        'department',
        'company',
        'org_unit',
        'domain',
        'mail',
        'mobile',
    ];

    public const FINAL_FIELDS = [
        'phone_label',
        'line_label',
        'blf_label',
        'directory_label',
    ];

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /** Idempotent MySQL/MariaDB DDL used by the BMO's versioned migration. */
    public static function extensionsTableSql(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS ucmp_extensions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    extension VARCHAR(64) NOT NULL,
    ad_source_id INT NULL,
    ad_object_key VARCHAR(128) NULL,
    ad_source_revision VARCHAR(64) NULL,
    binding_mode VARCHAR(24) NOT NULL DEFAULT 'ad_primary',
    linked_source_id INT NULL,
    linked_object_key VARCHAR(128) NULL,
    identity_adoption VARCHAR(24) NOT NULL DEFAULT 'legacy_review',
    manual_identity_json LONGTEXT NULL,
    canonical_identity_hash CHAR(64) NULL,
    group_id INT NULL,
    pbx_presence TINYINT(1) NOT NULL DEFAULT 0,
    pbx_last_confirmed INT NOT NULL DEFAULT 0,
    desired_identity_hash CHAR(64) NULL,
    applied_identity_hash CHAR(64) NULL,
    apply_status VARCHAR(24) NOT NULL DEFAULT 'unbound',
    apply_error TEXT NULL,
    revision INT NOT NULL DEFAULT 1,
    updated INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_ucmp_extensions_site_ext (site_id, extension),
    UNIQUE KEY uniq_ucmp_extensions_ad_object (ad_source_id, ad_object_key),
    KEY idx_ucmp_extensions_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
    }

    /** Add only identity-v3 fields; never replay historical phone/transport migrations. */
    public static function ensureIdentitySchema(\PDO $db): void
    {
        $sqlite = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $columns = $sqlite
            ? array_column($db->query('PRAGMA table_info(ucmp_extensions)')->fetchAll(\PDO::FETCH_ASSOC), 'name')
            : $db->query('SHOW COLUMNS FROM ucmp_extensions')->fetchAll(\PDO::FETCH_COLUMN);
        if (!$columns) { throw new \RuntimeException('Logical extension schema is unavailable'); }
        foreach ([
            'binding_mode'=>"VARCHAR(24) NOT NULL DEFAULT 'ad_primary'",
            'linked_source_id'=>'INT NULL',
            'linked_object_key'=>'VARCHAR(128) NULL',
            'identity_adoption'=>"VARCHAR(24) NOT NULL DEFAULT 'legacy_review'",
            'manual_identity_json'=>'LONGTEXT NULL',
            'canonical_identity_hash'=>'CHAR(64) NULL',
        ] as $name=>$definition) {
            if (!in_array($name, $columns, true)) {
                $db->exec("ALTER TABLE ucmp_extensions ADD COLUMN {$name} {$definition}");
            }
        }
        // A previous applied hash does not prove that an arbitrary legacy label
        // was operator-managed. Adoption remains an explicit reviewed decision.
    }

    /**
     * Override values are JSON so an explicit empty string remains distinct
     * from deleting the override.  scope_key avoids nullable-UNIQUE semantics.
     */
    public static function overridesTableSql(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS ucmp_identity_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope_key VARCHAR(128) NOT NULL,
    scope_type VARCHAR(16) NOT NULL,
    source_id INT NULL,
    object_key VARCHAR(128) NULL,
    extension_id INT NULL,
    site_id INT NULL,
    field_name VARCHAR(48) NOT NULL,
    mode VARCHAR(12) NOT NULL,
    value_json LONGTEXT NOT NULL,
    reason VARCHAR(255) NULL,
    source_revision VARCHAR(64) NULL,
    created_by VARCHAR(128) NOT NULL,
    updated_by VARCHAR(128) NOT NULL,
    created INT NOT NULL DEFAULT 0,
    updated INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_ucmp_identity_override (scope_key, field_name, mode),
    KEY idx_ucmp_identity_override_extension (extension_id),
    KEY idx_ucmp_identity_override_object (source_id, object_key),
    KEY idx_ucmp_identity_override_site (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
    }

    public static function phoneExtensionColumnDdl(): string
    {
        return 'extension_id INT NULL AFTER site_id';
    }

    public static function phoneExtensionIndexDdl(): string
    {
        return 'INDEX idx_ucmp_phones_extension_id (extension_id)';
    }

    public static function normalizeExtension($value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('Extension must be a string or integer');
        }
        $extension = trim((string)$value);
        if ($extension === '') {
            throw new \InvalidArgumentException('Extension cannot be empty');
        }
        if (strlen($extension) > 64) {
            throw new \InvalidArgumentException('Extension cannot exceed 64 bytes');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $extension)) {
            throw new \InvalidArgumentException('Extension contains control characters');
        }
        return $extension;
    }

    /**
     * Normalize the canonical identity fields accepted from a completed AD
     * revision.  The caller retains arbitrary AD attributes in the directory
     * object; this record contains only identity values used by provisioning.
     *
     * @return array<string,string|null>
     */
    public static function normalizeAdIdentity(array $identity): array
    {
        if (!array_key_exists('extension', $identity)) {
            throw new \InvalidArgumentException('AD identity is missing its extension');
        }

        $normalized = [];
        foreach (self::CANONICAL_FIELDS as $field) {
            $value = $identity[$field] ?? null;
            if ($value === null) {
                $normalized[$field] = null;
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new \InvalidArgumentException("AD identity field {$field} must be scalar or null");
            }
            $normalized[$field] = $field === 'extension'
                ? self::normalizeExtension($value)
                : trim((string)$value);
        }

        if (($normalized['display_name'] ?? '') === '') {
            throw new \InvalidArgumentException('AD identity is missing its display name');
        }
        return $normalized;
    }

    /**
     * Resolve canonical overrides, format labels, then apply final overrides.
     * Vendor/model length and charset validation deliberately remains the last
     * step in the generator and cannot be bypassed here.
     *
     * @param array<int,array<string,mixed>> $overrides
     * @return array<string,mixed>
     */
    public static function resolveIdentity(
        array $adIdentity,
        array $overrides = [],
        ?callable $formatter = null
    ): array {
        return self::resolveIdentityFromSource($adIdentity, 'ad', $overrides, $formatter);
    }

    /**
     * Resolve the same label/override pipeline when no accepted AD link exists.
     * The caller supplies a complete FreePBX/legacy fallback identity; final
     * audited overrides retain the same precedence as the AD-backed path.
     *
     * @param array<int,array<string,mixed>> $overrides
     * @return array<string,mixed>
     */
    public static function resolveProvisioningIdentity(
        array $identity,
        string $source,
        array $overrides = [],
        ?callable $formatter = null
    ): array {
        if (!in_array($source, ['freepbx', 'legacy'], true)) {
            throw new \InvalidArgumentException('Provisioning identity source must be freepbx or legacy');
        }
        return self::resolveIdentityFromSource($identity, $source, $overrides, $formatter);
    }

    /** @return array<string,mixed> */
    private static function resolveIdentityFromSource(
        array $identity,
        string $baseSource,
        array $overrides,
        ?callable $formatter
    ): array {
        $base = self::normalizeAdIdentity($identity);
        $canonical = $base;
        $sourceId = $identity['ad_source_id'] ?? $identity['source_id'] ?? null;
        $sourceRevision = $identity['ad_source_revision'] ?? $identity['source_revision'] ?? null;
        $canonicalTrace = [];

        foreach (self::CANONICAL_FIELDS as $field) {
            $canonicalTrace[$field] = [
                'source' => $baseSource,
                'source_id' => $sourceId,
                'source_revision' => $sourceRevision,
            ];
        }

        foreach ($overrides as $override) {
            if (($override['mode'] ?? '') !== 'canonical') {
                continue;
            }
            $field = self::validateOverrideField((string)($override['field_name'] ?? ''), 'canonical');
            $canonical[$field] = self::normalizeCanonicalOverride($field, self::overrideValue($override));
            $canonicalTrace[$field] = self::overrideTrace($override);
        }

        $outputs = $formatter
            ? $formatter($canonical)
            : self::defaultOutputs($canonical);
        if (!is_array($outputs)) {
            throw new \UnexpectedValueException('Identity formatter must return an array');
        }

        $normalizedOutputs = [];
        $outputTrace = [];
        foreach (self::FINAL_FIELDS as $field) {
            $value = $outputs[$field] ?? '';
            $normalizedOutputs[$field] = self::normalizeFinalValue($value);
            $outputTrace[$field] = ['source' => $formatter ? 'formatter' : 'default'];
        }

        foreach ($overrides as $override) {
            if (($override['mode'] ?? '') !== 'final') {
                continue;
            }
            $field = self::validateOverrideField((string)($override['field_name'] ?? ''), 'final');
            $normalizedOutputs[$field] = self::normalizeFinalValue(self::overrideValue($override));
            $outputTrace[$field] = self::overrideTrace($override);
        }

        return [
            // Keep the deprecated key for API compatibility; base/base_source
            // make the fallback provenance explicit to new callers.
            'ad' => $base,
            'base' => $base,
            'base_source' => $baseSource,
            'canonical' => $canonical,
            'outputs' => $normalizedOutputs,
            'trace' => [
                'canonical' => $canonicalTrace,
                'outputs' => $outputTrace,
            ],
            'canonical_hash' => self::stableHash($canonical),
            'effective_hash' => self::stableHash([
                'canonical' => $canonical,
                'outputs' => $normalizedOutputs,
            ]),
        ];
    }

    public static function canonicalHash(array $identity): string
    {
        return self::stableHash(self::normalizeAdIdentity($identity));
    }

    /**
     * Build a non-destructive migration plan from legacy physical-phone rows.
     * Labels are review candidates only; they are never silently promoted above
     * AD.  Conflicting legacy groups likewise remain unresolved.
     *
     * @param array<int,array<string,mixed>> $phones
     * @return array{extensions:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>}
     */
    public static function planLegacyBackfill(array $phones): array
    {
        $grouped = [];
        $skipped = [];

        foreach ($phones as $phone) {
            $id = (int)($phone['id'] ?? 0);
            $siteId = (int)($phone['site_id'] ?? 0);
            try {
                if ($id < 1 || $siteId < 1) {
                    throw new \InvalidArgumentException('Phone and site IDs must be positive');
                }
                $extension = self::normalizeExtension($phone['extension'] ?? null);
            } catch (\InvalidArgumentException $e) {
                $skipped[] = ['phone_id' => $id, 'reason' => $e->getMessage()];
                continue;
            }

            $key = json_encode([$siteId, $extension], JSON_THROW_ON_ERROR);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'site_id' => $siteId,
                    'extension' => $extension,
                    'phone_ids' => [],
                    '_groups' => [],
                    '_labels' => [],
                ];
            }
            $grouped[$key]['phone_ids'][] = $id;

            $groupId = (int)($phone['group_id'] ?? 0);
            if ($groupId > 0) {
                $grouped[$key]['_groups'][$groupId] = true;
            }
            $label = trim((string)($phone['label'] ?? ''));
            if ($label !== '') {
                $grouped[$key]['_labels'][$label] = true;
            }
        }

        $extensions = [];
        foreach ($grouped as $row) {
            $groups = array_map('intval', array_keys($row['_groups']));
            sort($groups, SORT_NUMERIC);
            $labels = array_keys($row['_labels']);
            sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
            sort($row['phone_ids'], SORT_NUMERIC);

            $extensions[] = [
                'site_id' => $row['site_id'],
                'extension' => $row['extension'],
                'phone_ids' => $row['phone_ids'],
                'group_id' => count($groups) === 1 ? $groups[0] : null,
                'group_conflict' => count($groups) > 1,
                'legacy_group_ids' => $groups,
                'label_candidates' => $labels,
            ];
        }
        usort($extensions, static function (array $left, array $right): int {
            return [$left['site_id'], strnatcasecmp($left['extension'], $right['extension'])]
                <=> [$right['site_id'], 0];
        });

        return ['extensions' => $extensions, 'skipped' => $skipped];
    }

    /**
     * Create compatibility logical rows and link physical phones.  Existing
     * phone group/label columns remain intact until the reviewed migration.
     *
     * @return array<string,mixed>
     */
    public function backfillLegacyPhones(?int $now = null): array
    {
        $rows = $this->db->query(
            "SELECT id,site_id,extension,group_id,label
               FROM ucmp_phones
              WHERE site_id IS NOT NULL AND extension IS NOT NULL AND TRIM(extension)<>''"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $plan = self::planLegacyBackfill($rows);
        $now = $now ?? time();
        $started = !$this->db->inTransaction();

        if ($started) {
            $this->db->beginTransaction();
        }
        try {
            $created = 0;
            $linked = 0;
            foreach ($plan['extensions'] as $candidate) {
                $existing = $this->findBySiteExtension(
                    (int)$candidate['site_id'],
                    (string)$candidate['extension']
                );
                if (!$existing) {
                    $insert = $this->db->prepare(
                        "INSERT INTO ucmp_extensions
                            (site_id,extension,group_id,apply_status,revision,updated)
                         VALUES (?,?,?,'unbound',1,?)"
                    );
                    $insert->execute([
                        $candidate['site_id'],
                        $candidate['extension'],
                        $candidate['group_id'],
                        $now,
                    ]);
                    $extensionId = (int)$this->db->lastInsertId();
                    $created++;
                } else {
                    $extensionId = (int)$existing['id'];
                    if (
                        empty($existing['group_id'])
                        && !$candidate['group_conflict']
                        && !empty($candidate['group_id'])
                    ) {
                        $this->db->prepare(
                            'UPDATE ucmp_extensions SET group_id=?,updated=? WHERE id=? AND group_id IS NULL'
                        )->execute([$candidate['group_id'], $now, $extensionId]);
                    }
                }

                $ids = array_values(array_unique(array_map('intval', $candidate['phone_ids'])));
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $link = $this->db->prepare(
                    "UPDATE ucmp_phones
                        SET extension_id=?
                      WHERE site_id=? AND id IN ({$marks})
                        AND (extension_id IS NULL OR extension_id<>?)"
                );
                $link->execute(array_merge(
                    [$extensionId, $candidate['site_id']],
                    $ids,
                    [$extensionId]
                ));
                $linked += $link->rowCount();
            }

            if ($started) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'created' => $created,
            'linked' => $linked,
            'group_conflicts' => array_values(array_filter(
                $plan['extensions'],
                static fn(array $row): bool => (bool)$row['group_conflict']
            )),
            'label_candidates' => array_values(array_filter(
                $plan['extensions'],
                static fn(array $row): bool => $row['label_candidates'] !== []
            )),
            'skipped' => $plan['skipped'],
        ];
    }

    /**
     * Ensure a PBX-observed extension has a stable logical row.
     *
     * This does not infer identity or site from PBX reachability: the owning
     * site is supplied by the caller. It only records that the already-selected
     * site's PBX currently exposes this extension.
     *
     * @return array<string,mixed>
     */
    public function ensurePbxExtension(int $siteId, string $extension, ?int $now = null): array
    {
        if ($siteId < 1) {
            throw new \InvalidArgumentException('Site ID must be positive');
        }
        $extension = self::normalizeExtension($extension);
        $now = $now ?? time();
        $existing = $this->findBySiteExtension($siteId, $extension);
        if (!$existing) {
            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO ucmp_extensions
                        (site_id,extension,pbx_presence,pbx_last_confirmed,apply_status,revision,updated)
                     VALUES (?,?,1,?,'unbound',1,?)"
                );
                $stmt->execute([$siteId, $extension, $now, $now]);
                return $this->findById((int)$this->db->lastInsertId())
                    ?: throw new \RuntimeException('Logical extension could not be reloaded');
            } catch (\PDOException $e) {
                // Another request may have inserted the same unique
                // (site,extension) between our SELECT and INSERT.
                $existing = $this->findBySiteExtension($siteId, $extension);
                if (!$existing) {
                    throw $e;
                }
            }
        }
        $this->db->prepare(
            'UPDATE ucmp_extensions
                SET pbx_presence=1,pbx_last_confirmed=?,updated=?
              WHERE id=?'
        )->execute([$now, $now, (int)$existing['id']]);
        $existing['pbx_presence'] = 1;
        $existing['pbx_last_confirmed'] = $now;
        $existing['updated'] = $now;
        return $existing;
    }

    /**
     * Make group membership an extension property and mirror it to linked
     * physical rows only for compatibility with the existing generators.
     *
     * No placeholder phone is ever created.
     *
     * @param array<int,string|int> $memberExtensions
     * @param array<int,string|int> $validExtensions
     * @return array{group_id:int,members:array<int,string>,created:int,linked_phones:int}
     */
    public function synchronizeGroupMembers(
        int $siteId,
        int $groupId,
        array $memberExtensions,
        array $validExtensions,
        ?int $now = null
    ): array {
        if ($siteId < 1 || $groupId < 1) {
            throw new \InvalidArgumentException('Site and group IDs must be positive');
        }
        $this->assertGroupBelongsToSite($groupId, $siteId);
        $normalizeList = static function (array $values): array {
            $result = [];
            foreach ($values as $value) {
                $extension = self::normalizeExtension($value);
                $result['extension:' . $extension] = $extension;
            }
            return array_values($result);
        };
        $members = $normalizeList($memberExtensions);
        $valid = array_fill_keys($normalizeList($validExtensions), true);
        $invalid = array_values(array_filter(
            $members,
            static fn(string $extension): bool => !isset($valid[$extension])
        ));
        if ($invalid) {
            throw new \InvalidArgumentException(
                'Unknown PBX extension(s): ' . implode(', ', array_slice($invalid, 0, 10))
            );
        }
        sort($members, SORT_NATURAL);
        $now = $now ?? time();
        $started = !$this->db->inTransaction();
        if ($started) {
            $this->db->beginTransaction();
        }
        try {
            $currentStmt = $this->db->prepare(
                'SELECT id,extension FROM ucmp_extensions WHERE site_id=? AND group_id=?'
            );
            $currentStmt->execute([$siteId, $groupId]);
            $current = [];
            foreach ($currentStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $currentRow) {
                $current[(string)$currentRow['extension']] = (int)$currentRow['id'];
            }
            $selected = array_fill_keys($members, true);
            $removed = array_diff_key($current, $selected);
            foreach ($removed as $extensionId) {
                $this->db->prepare(
                    'UPDATE ucmp_extensions
                        SET group_id=NULL,revision=revision+1,updated=?
                      WHERE id=? AND group_id=?'
                )->execute([$now, $extensionId, $groupId]);
                $this->db->prepare(
                    'UPDATE ucmp_phones
                        SET group_id=NULL,updated=' . self::phoneUpdateExpression($this->db) . '
                      WHERE extension_id=? AND (site_id=? OR site_id IS NULL)'
                )->execute([$now, $extensionId, $siteId]);
            }

            $created = 0;
            $linked = 0;
            $changed = count($removed);
            foreach ($members as $extension) {
                $logical = $this->findBySiteExtension($siteId, $extension);
                if (!$logical) {
                    $logical = $this->ensurePbxExtension($siteId, $extension, $now);
                    $created++;
                }
                if ((int)($logical['group_id'] ?? 0) !== $groupId) {
                    $this->db->prepare(
                        'UPDATE ucmp_extensions
                            SET group_id=?,revision=revision+1,updated=?
                          WHERE id=?'
                    )->execute([$groupId, $now, (int)$logical['id']]);
                    $changed++;
                }
                $stmt = $this->db->prepare(
                    'UPDATE ucmp_phones
                        SET extension_id=?,group_id=?,site_id=?,updated=' . self::phoneUpdateExpression($this->db) . '
                      WHERE (site_id=? OR site_id IS NULL)
                        AND (extension_id=? OR extension=?)
                        AND (
                            extension_id IS NULL OR extension_id<>?
                            OR group_id IS NULL OR group_id<>?
                            OR site_id IS NULL
                        )'
                );
                $stmt->execute([
                    (int)$logical['id'],
                    $groupId,
                    $siteId,
                    $now,
                    $siteId,
                    (int)$logical['id'],
                    $extension,
                    (int)$logical['id'],
                    $groupId,
                ]);
                $linked += $stmt->rowCount();
            }
            if ($started) {
                $this->db->commit();
            }
            return [
                'group_id' => $groupId,
                'members' => $members,
                'created' => $created,
                'linked_phones' => $linked,
                'changed_extensions' => $changed,
            ];
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Link one physical phone to its logical extension. Passing null unassigns
     * the device. When $replaceGroup is true the submitted group becomes an
     * extension-level policy; otherwise the logical extension keeps its group.
     *
     * @return array<string,mixed>|null
     */
    public function linkPhone(
        int $phoneId,
        int $siteId,
        ?string $extension,
        bool $replaceGroup = false,
        ?int $groupId = null,
        ?int $now = null
    ): ?array {
        if ($phoneId < 1 || $siteId < 1) {
            throw new \InvalidArgumentException('Phone and site IDs must be positive');
        }
        $now = $now ?? time();
        $phoneStmt = $this->db->prepare(
            'SELECT id,site_id,extension_id,group_id
               FROM ucmp_phones
              WHERE id=? AND (site_id=? OR site_id IS NULL)'
        );
        $phoneStmt->execute([$phoneId, $siteId]);
        $phone = $phoneStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$phone) {
            throw new \InvalidArgumentException('The physical phone does not belong to this site');
        }
        if ($extension === null || trim($extension) === '') {
            if ($phone['extension_id'] !== null || $phone['group_id'] !== null) {
                $this->db->prepare(
                    'UPDATE ucmp_phones SET extension_id=NULL,group_id=NULL,updated=' . self::phoneUpdateExpression($this->db) . ' WHERE id=?'
                )->execute([$now, $phoneId]);
            }
            return null;
        }
        if ($groupId !== null && $groupId < 1) {
            $groupId = null;
        }
        $logical = $this->ensurePbxExtension($siteId, $extension, $now);
        if ($replaceGroup && (int)($logical['group_id'] ?? 0) !== (int)$groupId) {
            if ($groupId !== null) {
                $this->assertGroupBelongsToSite($groupId, $siteId);
            }
            $this->db->prepare(
                'UPDATE ucmp_extensions
                    SET group_id=?,revision=revision+1,updated=?
                  WHERE id=?'
            )->execute([$groupId, $now, (int)$logical['id']]);
            $logical['group_id'] = $groupId;
        }
        $desiredGroup = $logical['group_id'] ?? null;
        if ((int)($phone['extension_id'] ?? 0) !== (int)$logical['id']
            || (int)($phone['group_id'] ?? 0) !== (int)$desiredGroup
            || (int)($phone['site_id'] ?? 0) !== $siteId
        ) {
            $this->db->prepare(
                'UPDATE ucmp_phones
                    SET extension_id=?,group_id=?,site_id=?,updated=' . self::phoneUpdateExpression($this->db) . '
                  WHERE id=?'
            )->execute([
                (int)$logical['id'],
                $desiredGroup,
                $siteId,
                $now,
                $phoneId,
            ]);
        }
        return $logical;
    }

    private function assertGroupBelongsToSite(int $groupId, int $siteId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM ucmp_groups WHERE id=? AND site_id=?'
        );
        $stmt->execute([$groupId, $siteId]);
        if (!$stmt->fetchColumn()) {
            throw new \InvalidArgumentException('The provisioning group does not belong to this site');
        }
    }

    /**
     * Bind one completed AD identity revision to its AD-derived site.  A site or
     * number move is refused here so a later reconciliation transaction can
     * preview and apply it explicitly.
     *
     * @return array<string,mixed>
     */
    public function bindAdIdentity(
        int $siteId,
        int $sourceId,
        string $objectKey,
        string $sourceRevision,
        array $identity,
        ?int $now = null
    ): array {
        if ($siteId < 1 || $sourceId < 1) {
            throw new \InvalidArgumentException('Site and AD source IDs must be positive');
        }
        $objectKey = self::normalizeObjectKey($objectKey);
        $sourceRevision = self::normalizeSourceRevision($sourceRevision);
        $canonical = self::normalizeAdIdentity($identity);
        $extension = $canonical['extension'];
        $desiredHash = self::stableHash($canonical);
        $now = $now ?? time();

        $objectBinding = $this->findByAdObject($sourceId, $objectKey);
        if (
            $objectBinding
            && (
                (int)$objectBinding['site_id'] !== $siteId
                || (string)$objectBinding['extension'] !== $extension
            )
        ) {
            throw new \DomainException(
                'AD identity site/number changed; staged logical-extension reconciliation is required'
            );
        }

        $existing = $this->findBySiteExtension($siteId, $extension);
        if ($existing && ($existing['binding_mode'] ?? 'ad_primary') !== 'ad_primary') {
            throw new \DomainException('This extension has an explicit additional or manual/shared identity binding');
        }
        if (
            $existing
            && !empty($existing['ad_source_id'])
            && (
                (int)$existing['ad_source_id'] !== $sourceId
                || (string)$existing['ad_object_key'] !== $objectKey
            )
        ) {
            throw new \DomainException('Logical extension is already bound to another AD object');
        }

        if (!$existing) {
            $insert = $this->db->prepare(
                "INSERT INTO ucmp_extensions
                    (site_id,extension,ad_source_id,ad_object_key,ad_source_revision,
                     desired_identity_hash,canonical_identity_hash,identity_adoption,apply_status,revision,updated)
                 VALUES (?,?,?,?,?,?,?,'managed','pending',1,?)"
            );
            try {
                $insert->execute([
                    $siteId,
                    $extension,
                    $sourceId,
                    $objectKey,
                    $sourceRevision,
                    $desiredHash,
                    $desiredHash,
                    $now,
                ]);
            } catch (\PDOException $e) {
                if ((string)$e->getCode() !== '23000') {
                    throw $e;
                }
                $existing = $this->findBySiteExtension($siteId, $extension);
                if (!$existing) {
                    throw $e;
                }
            }
            if (!$existing) {
                return $this->findById((int)$this->db->lastInsertId())
                    ?? throw new \RuntimeException('Created logical extension could not be reloaded');
            }
        }

        // Recheck the row loaded after a unique-key race as well as the initial read.
        if (($existing['binding_mode'] ?? 'ad_primary') !== 'ad_primary'
            || (!empty($existing['ad_source_id']) && ((int)$existing['ad_source_id'] !== $sourceId || (string)$existing['ad_object_key'] !== $objectKey))) {
            throw new \DomainException('Logical extension ownership changed while binding');
        }
        $bindingChanged = (int)($existing['ad_source_id'] ?? 0) !== $sourceId
            || (string)($existing['ad_object_key'] ?? '') !== $objectKey;
        $identityChanged = !hash_equals(
            (string)($existing['canonical_identity_hash'] ?? ''),
            $desiredHash
        );
        $revision = (int)$existing['revision'] + ($bindingChanged || $identityChanged ? 1 : 0);
        $status = $identityChanged
            ? 'pending'
            : (string)($existing['apply_status'] ?? 'unbound');
        if ($bindingChanged && !$identityChanged) {
            $status = hash_equals((string)($existing['applied_identity_hash'] ?? ''), $desiredHash)
                ? 'applied'
                : 'pending';
        }

        $update = $this->db->prepare(
            'UPDATE ucmp_extensions
                SET ad_source_id=?,ad_object_key=?,ad_source_revision=?,
                    canonical_identity_hash=?,apply_status=?,apply_error=?,
                    revision=?,updated=?
              WHERE id=? AND revision=?'
        );
        $update->execute([
            $sourceId,
            $objectKey,
            $sourceRevision,
            $desiredHash,
            $status,
            $identityChanged ? null : ($existing['apply_error'] ?? null),
            $revision,
            $now,
            $existing['id'],
            $existing['revision'],
        ]);
        $saved = $this->findById((int)$existing['id'])
            ?? throw new \RuntimeException('Updated logical extension could not be reloaded');
        if ((int)$saved['revision'] !== $revision || (int)$saved['ad_source_id'] !== $sourceId || (string)$saved['ad_object_key'] !== $objectKey) {
            throw new \DomainException('Logical extension changed while binding; retry from its current state');
        }
        return $saved;
    }

    /**
     * Apply every AD move that is unambiguous in the supplied authoritative
     * revision. A complete chain or swap is safe; a destination occupied by
     * an object outside that move set is left for explicit review.
     *
     * @param array<int,array<string,mixed>> $moves
     * @return array{applied:array<int,array<string,mixed>>,blocked:array<int,array<string,mixed>>}
     */
    public function reconcileAuthoritativeAdIdentityMoves(array $moves, ?int $now = null): array
    {
        if (!$moves) { return ['applied'=>[], 'blocked'=>[]]; }

        $candidates = [];
        $targetClaims = [];
        $duplicateObjects = [];
        foreach ($moves as $move) {
            $sourceId = (int)($move['source_id'] ?? 0);
            $siteId = (int)($move['site_id'] ?? 0);
            if ($sourceId < 1 || $siteId < 1) {
                throw new \InvalidArgumentException('Every AD move requires a valid source and destination site');
            }
            $objectKey = self::normalizeObjectKey((string)($move['object_key'] ?? ''));
            $sourceRevision = self::normalizeSourceRevision((string)($move['source_revision'] ?? ''));
            $identity = self::normalizeAdIdentity((array)($move['identity'] ?? $move));
            $current = $this->findByAdObject($sourceId, $objectKey);
            if (!$current || (
                (int)$current['site_id'] === $siteId
                && (string)$current['extension'] === (string)$identity['extension']
            )) {
                continue;
            }

            $objectId = $sourceId . "\0" . $objectKey;
            if (isset($candidates[$objectId])) {
                $duplicateObjects[$objectId] = true;
                continue;
            }
            $targetId = $siteId . "\0" . (string)$identity['extension'];
            $candidates[$objectId] = [
                'source_id'=>$sourceId,
                'object_key'=>$objectKey,
                'source_revision'=>$sourceRevision,
                'site_id'=>$siteId,
                'extension'=>(string)$identity['extension'],
                'identity'=>$identity,
            ];
            $targetClaims[$targetId][] = $objectId;
        }

        $blocked = [];
        if (count($candidates) > 500) {
            throw new \InvalidArgumentException('At most five hundred actual AD identity moves may be evaluated at once');
        }
        foreach ($duplicateObjects as $objectId=>$_duplicate) {
            $blocked[$objectId] = 'One AD object has more than one destination in this accepted revision';
        }
        foreach ($targetClaims as $claimants) {
            if (count($claimants) < 2) { continue; }
            foreach ($claimants as $objectId) {
                $blocked[$objectId] = 'More than one AD object claims the same site and extension';
            }
        }

        $dependencies = [];
        foreach ($candidates as $objectId=>$move) {
            if (isset($blocked[$objectId])) { continue; }
            $target = $this->findBySiteExtension((int)$move['site_id'], (string)$move['extension']);
            if ($target && ($target['binding_mode'] ?? 'ad_primary') !== 'ad_primary') {
                $blocked[$objectId] = 'Destination has an explicit additional or manual/shared identity binding';
                continue;
            }
            if (!$target || empty($target['ad_source_id']) || empty($target['ad_object_key'])) { continue; }
            $occupantId = (int)$target['ad_source_id'] . "\0" . (string)$target['ad_object_key'];
            if ($occupantId === $objectId) { continue; }
            $dependencies[$objectId] = $occupantId;
            if (!isset($candidates[$occupantId])) {
                $blocked[$objectId] = 'Destination is occupied by an AD object that is not moving in this accepted revision';
            }
        }
        do {
            $changed = false;
            foreach ($dependencies as $objectId=>$occupantId) {
                if (!isset($blocked[$objectId]) && isset($blocked[$occupantId])) {
                    $blocked[$objectId] = 'Destination depends on another ambiguous or blocked AD move';
                    $changed = true;
                }
            }
        } while ($changed);

        $eligible = array_diff_key($candidates, $blocked);
        $applied = $eligible ? $this->reconcileAdIdentityMoves(array_values($eligible), $now) : [];
        $blockedRows = [];
        foreach ($blocked as $objectId=>$reason) {
            if (!isset($candidates[$objectId])) { continue; }
            $blockedRows[] = [
                'source_id'=>$candidates[$objectId]['source_id'],
                'object_key'=>$candidates[$objectId]['object_key'],
                'site_id'=>$candidates[$objectId]['site_id'],
                'extension'=>$candidates[$objectId]['extension'],
                'reason'=>$reason,
            ];
        }
        return ['applied'=>$applied, 'blocked'=>$blockedRows];
    }

    /**
     * Apply a validated set of AD object moves without moving phones or the
     * site/extension slots that own group membership and final overrides.
     *
     * All source bindings are cleared before any destination is filled so a
     * swap or chain (A leaves 301 while B enters 301) is one atomic action.
     * A destination occupied by an object outside the reviewed batch aborts
     * the complete transaction.
     *
     * @param array<int,array<string,mixed>> $moves
     * @return array<int,array<string,mixed>>
     */
    public function reconcileAdIdentityMoves(array $moves, ?int $now = null): array
    {
        if (!$moves || count($moves) > 500) {
            throw new \InvalidArgumentException('Choose between one and five hundred AD identity moves');
        }
        $now = $now ?? time();
        $normalized = [];
        $objects = [];
        $targets = [];
        foreach ($moves as $move) {
            $sourceId = (int)($move['source_id'] ?? 0);
            $siteId = (int)($move['site_id'] ?? 0);
            if ($sourceId < 1 || $siteId < 1) {
                throw new \InvalidArgumentException('Every AD move requires a valid source and destination site');
            }
            $objectKey = self::normalizeObjectKey((string)($move['object_key'] ?? ''));
            $sourceRevision = self::normalizeSourceRevision((string)($move['source_revision'] ?? ''));
            $identity = self::normalizeAdIdentity((array)($move['identity'] ?? $move));
            $extension = (string)$identity['extension'];
            $objectIdentity = $sourceId . "\0" . $objectKey;
            $targetIdentity = $siteId . "\0" . $extension;
            if (isset($objects[$objectIdentity])) {
                throw new \InvalidArgumentException('The same AD object was selected more than once');
            }
            if (isset($targets[$targetIdentity])) {
                throw new \InvalidArgumentException("More than one AD object targets site $siteId extension $extension");
            }
            $objects[$objectIdentity] = true;
            $targets[$targetIdentity] = true;
            $normalized[] = [
                'source_id'=>$sourceId,
                'object_key'=>$objectKey,
                'source_revision'=>$sourceRevision,
                'site_id'=>$siteId,
                'extension'=>$extension,
                'identity'=>$identity,
                'desired_hash'=>self::stableHash($identity),
            ];
        }

        $started = !$this->db->inTransaction();
        if ($started) { $this->db->beginTransaction(); }
        else { $this->db->exec('SAVEPOINT ucmp_identity_moves'); }
        try {
            foreach ($normalized as &$move) {
                $current = $this->findByAdObject($move['source_id'], $move['object_key']);
                if (!$current) {
                    throw new \DomainException('One selected AD object no longer has the binding shown in the conflict list; refresh before reconciling');
                }
                $target = $this->findBySiteExtension($move['site_id'], $move['extension']);
                if ($target && ($target['binding_mode'] ?? 'ad_primary') !== 'ad_primary') {
                    throw new \DomainException('Destination has an explicit additional or manual/shared identity binding');
                }
                if ($target && !empty($target['ad_source_id']) && !empty($target['ad_object_key'])) {
                    $occupant = (int)$target['ad_source_id'] . "\0" . (string)$target['ad_object_key'];
                    if (!isset($objects[$occupant])) {
                        throw new \DomainException(
                            'Destination site ' . $move['site_id'] . ' extension ' . $move['extension']
                            . ' is still bound to an AD object outside the reviewed move set'
                        );
                    }
                }
                $move['from'] = [
                    'extension_id'=>(int)$current['id'],
                    'site_id'=>(int)$current['site_id'],
                    'extension'=>(string)$current['extension'],
                ];
            }
            unset($move);

            $clear = $this->db->prepare(
                "UPDATE ucmp_extensions
                    SET ad_source_id=NULL,ad_object_key=NULL,ad_source_revision=NULL,
                        canonical_identity_hash=NULL,desired_identity_hash=NULL,applied_identity_hash=NULL,
                        apply_status='unbound',apply_error=NULL,revision=revision+1,updated=?
                  WHERE id=? AND ad_source_id=? AND ad_object_key=?"
            );
            foreach ($normalized as $move) {
                $this->retainOverrideOwner((int)$move['from']['extension_id'], $move['source_id'], $move['object_key']);
                $clear->execute([
                    $now,
                    (int)$move['from']['extension_id'],
                    $move['source_id'],
                    $move['object_key'],
                ]);
                if ($clear->rowCount() !== 1) {
                    throw new \RuntimeException('An AD binding changed while reconciliation was in progress');
                }
            }

            $insert = $this->db->prepare(
                "INSERT INTO ucmp_extensions
                    (site_id,extension,ad_source_id,ad_object_key,ad_source_revision,
                     desired_identity_hash,canonical_identity_hash,identity_adoption,applied_identity_hash,apply_status,apply_error,revision,updated)
                 VALUES (?,?,?,?,?,?,?,'managed',NULL,'pending',NULL,1,?)"
            );
            $update = $this->db->prepare(
                "UPDATE ucmp_extensions
                    SET ad_source_id=?,ad_object_key=?,ad_source_revision=?,
                        desired_identity_hash=?,canonical_identity_hash=?,applied_identity_hash=NULL,
                        apply_status='pending',apply_error=NULL,revision=revision+1,updated=?
                  WHERE id=? AND revision=? AND ad_source_id IS NULL AND ad_object_key IS NULL AND binding_mode='ad_primary'"
            );
            foreach ($normalized as &$move) {
                $target = $this->findBySiteExtension($move['site_id'], $move['extension']);
                if ($target) {
                    if (($target['binding_mode'] ?? 'ad_primary') !== 'ad_primary'
                        || $target['ad_source_id'] !== null || $target['ad_object_key'] !== null) {
                        throw new \DomainException('Destination ownership changed while reconciling AD identities');
                    }
                    $update->execute([
                        $move['source_id'],
                        $move['object_key'],
                        $move['source_revision'],
                        $move['desired_hash'],
                        $move['desired_hash'],
                        $now,
                        (int)$target['id'],
                        (int)$target['revision'],
                    ]);
                    if ($update->rowCount() !== 1) { throw new \DomainException('Destination revision changed while reconciling AD identities'); }
                    $targetId = (int)$target['id'];
                } else {
                    $insert->execute([
                        $move['site_id'],
                        $move['extension'],
                        $move['source_id'],
                        $move['object_key'],
                        $move['source_revision'],
                        $move['desired_hash'],
                        $move['desired_hash'],
                        $now,
                    ]);
                    $targetId = (int)$this->db->lastInsertId();
                }
                $move['to'] = [
                    'extension_id'=>$targetId,
                    'site_id'=>$move['site_id'],
                    'extension'=>$move['extension'],
                ];
            }
            unset($move);
            if ($started) {
                if (!$this->db->commit()) { throw new \RuntimeException('Identity move commit was not confirmed'); }
            } else { $this->db->exec('RELEASE SAVEPOINT ucmp_identity_moves'); }
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                if ($started) { $this->db->rollBack(); }
                else {
                    $this->db->exec('ROLLBACK TO SAVEPOINT ucmp_identity_moves');
                    $this->db->exec('RELEASE SAVEPOINT ucmp_identity_moves');
                }
            }
            throw $error;
        }

        return array_map(static fn(array $move): array => [
            'source_id'=>$move['source_id'],
            'object_key'=>$move['object_key'],
            'from'=>$move['from'],
            'to'=>$move['to'],
        ], $normalized);
    }

    /** PBX presence is recorded as runtime evidence, never as identity authority. */
    public function confirmPbxPresence(int $extensionId, bool $present, ?int $confirmedAt = null): void
    {
        if ($extensionId < 1) {
            throw new \InvalidArgumentException('Logical extension ID must be positive');
        }
        $this->db->prepare(
            'UPDATE ucmp_extensions SET pbx_presence=?,pbx_last_confirmed=? WHERE id=?'
        )->execute([$present ? 1 : 0, $confirmedAt ?? time(), $extensionId]);
    }

    /**
     * Revision/hash guard prevents an offline worker from acknowledging a stale
     * desired identity after a newer AD revision has been accepted.
     */
    public function markApplied(
        int $extensionId,
        int $revision,
        string $desiredHash,
        ?int $appliedAt = null
    ): bool {
        self::assertHash($desiredHash);
        $stmt = $this->db->prepare(
            "UPDATE ucmp_extensions
                SET applied_identity_hash=?,apply_status='applied',apply_error=NULL,updated=?
              WHERE id=? AND revision=? AND desired_identity_hash=?"
        );
        $stmt->execute([$desiredHash, $appliedAt ?? time(), $extensionId, $revision, $desiredHash]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $row = $this->findById($extensionId);
        return $row
            && (int)$row['revision'] === $revision
            && hash_equals((string)$row['desired_identity_hash'], $desiredHash)
            && hash_equals((string)$row['applied_identity_hash'], $desiredHash)
            && (string)$row['apply_status'] === 'applied';
    }

    public function markApplyFailed(
        int $extensionId,
        int $revision,
        string $desiredHash,
        string $error,
        ?int $failedAt = null
    ): bool {
        return $this->markApplyProblem($extensionId,$revision,$desiredHash,$error,'failed',$failedAt);
    }

    public function markApplySkipped(int $extensionId,int $revision,string $desiredHash,string $reason): bool
    {
        return $this->markApplyProblem($extensionId,$revision,$desiredHash,$reason,'skipped');
    }

    private function markApplyProblem(int $extensionId,int $revision,string $desiredHash,string $error,string $status,?int $failedAt=null): bool
    {
        self::assertHash($desiredHash);
        $error = trim($error);
        if ($error === '') {
            throw new \InvalidArgumentException('Apply failure must include an error');
        }
        $error = substr($error, 0, 4000);
        $stmt = $this->db->prepare(
            "UPDATE ucmp_extensions
                SET apply_status=?,apply_error=?,updated=?
              WHERE id=? AND revision=? AND desired_identity_hash=?"
        );
        $stmt->execute([$status, $error, $failedAt ?? time(), $extensionId, $revision, $desiredHash]);
        if ($stmt->rowCount() > 0) { return true; }
        $row=$this->findById($extensionId);
        return $row && (int)$row['revision']===$revision && hash_equals((string)$row['desired_identity_hash'],$desiredHash)
            && $row['apply_status']===$status && $row['apply_error']===$error;
    }

    /** Only an already accepted complete source revision can release departed primary owners. */
    public function reconcileAcceptedSourceBindings(int $sourceId, string $revision, array $people, bool $complete, ?int $now = null): array
    {
        if (!$complete) { throw new \DomainException('Incomplete directory reads cannot reconcile identity ownership'); }
        $check = $this->db->prepare('SELECT last_accepted_revision FROM ucmp_directory_sources WHERE id=?');
        $check->execute([$sourceId]);
        if (!hash_equals((string)$check->fetchColumn(), $revision) || $revision === '') { throw new \DomainException('Source revision is not the accepted revision'); }
        $byPerson = [];
        foreach ($people as $person) {
            $key = (string)($person['_object_key'] ?? $person['object_key'] ?? '');
            if ($key === '' || isset($byPerson[$key])) { throw new \DomainException('Accepted snapshot contains ambiguous person identities'); }
            $byPerson[$key] = $person;
        }
        $now ??= time();
        $released = [];
        $started = !$this->db->inTransaction();
        if ($started) { $this->db->beginTransaction(); }
        try {
            foreach ($this->enumerateBindings($sourceId) as $row) {
                if (($row['binding_mode'] ?? 'ad_primary') !== 'ad_primary') { continue; }
                $person = $byPerson[(string)$row['ad_object_key']] ?? null;
                if ($person !== null && trim((string)($person['extension'] ?? '')) !== '') { continue; }
                $this->retainOverrideOwner((int)$row['id'], $sourceId, (string)$row['ad_object_key']);
                $stmt = $this->db->prepare("UPDATE ucmp_extensions SET ad_source_id=NULL,ad_object_key=NULL,ad_source_revision=NULL,canonical_identity_hash=NULL,desired_identity_hash=NULL,applied_identity_hash=NULL,apply_status='unbound',revision=revision+1,updated=? WHERE id=? AND revision=?");
                $stmt->execute([$now,(int)$row['id'],(int)$row['revision']]);
                if ($stmt->rowCount() !== 1) { throw new \DomainException('Primary ownership changed during reconciliation'); }
                $released[] = ['extension_id'=>(int)$row['id'],'site_id'=>(int)$row['site_id'],'extension'=>(string)$row['extension']];
            }
            if ($started) { $this->db->commit(); }
        } catch (\Throwable $e) { if ($started && $this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        return ['released'=>$released,'accepted_revision'=>$revision];
    }

    /** The existing primary UNIQUE constraint deliberately excludes additional links. */
    public static function identityBinding(array $row): array
    {
        $mode = (string)($row['binding_mode'] ?? 'ad_primary');
        return [
            'mode'=>$mode,
            'source_id'=>$mode === 'manual_shared' ? 0 : (int)($row[$mode === 'linked_additional' ? 'linked_source_id' : 'ad_source_id'] ?? 0),
            'object_key'=>$mode === 'manual_shared' ? '' : (string)($row[$mode === 'linked_additional' ? 'linked_object_key' : 'ad_object_key'] ?? ''),
            'adoption'=>(string)($row['identity_adoption'] ?? 'legacy_review'),
        ];
    }

    /** Complete operational enumeration, independent of inventory display limits. */
    public function enumerateBindings(?int $sourceId = null, ?string $objectKey = null, ?int $siteId = null): array
    {
        $rows = $this->db->query('SELECT * FROM ucmp_extensions ORDER BY site_id,extension,id')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter($rows, static function (array $row) use ($sourceId, $objectKey, $siteId): bool {
            $binding = self::identityBinding($row);
            return ($sourceId === null || $binding['source_id'] === $sourceId)
                && ($objectKey === null || $binding['object_key'] === $objectKey)
                && ($siteId === null || (int)$row['site_id'] === $siteId);
        }));
    }

    /**
     * Read-only compatibility plan for pre-identity-v3 AD bindings.
     *
     * A legacy-review row is eligible only when its durable AD source/object
     * points at one person in the currently accepted source revision, the
     * accepted person still owns the same site/number, and every extension
     * override carries matching source provenance.  No hash or PBX observation
     * is treated as proof of ownership by itself.
     *
     * @return array{ok:bool,examined:int,eligible_count:int,uncertain_count:int,eligible:array<int,array<string,mixed>>,uncertain:array<int,array<string,mixed>>}
     */
    public function planLegacyIdentityAdoption(int $limit = 5000, ?int $sourceId = null): array
    {
        $limit = max(1, min(5000, $limit));
        $where = "x.binding_mode='ad_primary' AND x.identity_adoption='legacy_review'
              AND x.ad_source_id IS NOT NULL AND x.ad_source_id>0
              AND x.ad_object_key IS NOT NULL AND x.ad_object_key<>''
              AND x.ad_source_revision IS NOT NULL AND x.ad_source_revision<>''";
        $params = [];
        if ($sourceId !== null) {
            if ($sourceId < 1) { throw new \InvalidArgumentException('Directory source ID must be positive'); }
            $where .= ' AND x.ad_source_id=?';
            $params[] = $sourceId;
        }
        $stmt = $this->db->prepare(
            "SELECT x.*,d.source_id AS person_source_id,d.object_key AS person_object_key,
                    d.source_revision AS person_source_revision,d.display_name AS person_display_name,
                    d.label AS person_label,d.extension AS person_extension,d.department AS person_department,
                    d.company AS person_company,d.org_unit AS person_org_unit,d.mail AS person_mail,
                    d.mobile AS person_mobile,d.attributes AS person_attributes,
                    d.site_id AS person_site_id,d.conflicted AS person_conflicted,s.last_accepted_revision
               FROM ucmp_extensions x
               LEFT JOIN ucmp_directory_entries d
                 ON d.source_id=x.ad_source_id AND d.object_key=x.ad_object_key
               LEFT JOIN ucmp_directory_sources s ON s.id=d.source_id
              WHERE {$where}
              ORDER BY x.id
              LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $overrides = $this->legacyOverrideRows(array_map(static fn(array $row): int => (int)$row['id'], $rows));
        $eligible = [];
        $uncertain = [];
        foreach ($rows as $row) {
            $person = $this->legacyPersonFromPlanRow($row);
            $check = $this->legacyIdentityEligibility($row, $person, $overrides[(int)$row['id']] ?? []);
            $item = [
                'extension_id'=>(int)$row['id'],
                'site_id'=>(int)$row['site_id'],
                'extension'=>(string)$row['extension'],
                'revision'=>(int)$row['revision'],
                'source_id'=>(int)$row['ad_source_id'],
                'object_key'=>(string)$row['ad_object_key'],
                'source_revision'=>(string)$row['ad_source_revision'],
                'display_name'=>(string)($person['display_name'] ?? ''),
                'label'=>(string)($person['label'] ?? ''),
                'override_count'=>count($overrides[(int)$row['id']] ?? []),
            ];
            if ($check['eligible']) {
                $eligible[] = $item;
            } else {
                $item['reasons'] = $check['reasons'];
                $uncertain[] = $item;
            }
        }
        return [
            'ok'=>true,
            'examined'=>count($rows),
            'eligible_count'=>count($eligible),
            'uncertain_count'=>count($uncertain),
            'eligible'=>$eligible,
            'uncertain'=>$uncertain,
        ];
    }

    /**
     * Explicit, revision-guarded compatibility commit. The caller must pass
     * rows returned by planLegacyIdentityAdoption; ordinary reads never call it.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array{ok:bool,updated:int,extension_ids:array<int,int>}
     */
    public function adoptLegacyIdentityRelationships(array $relationships, ?int $now = null): array
    {
        if (count($relationships) > 5000) { throw new \InvalidArgumentException('Identity adoption exceeds 5000 relationships'); }
        if (!$relationships) { return ['ok'=>true,'updated'=>0,'extension_ids'=>[]]; }
        $now ??= time();
        $ids = [];
        foreach ($relationships as $item) {
            $id = (int)($item['extension_id'] ?? 0);
            $revision = (int)($item['revision'] ?? 0);
            if ($id < 1 || $revision < 1 || (int)($item['source_id'] ?? 0) < 1
                || trim((string)($item['object_key'] ?? '')) === ''
                || trim((string)($item['source_revision'] ?? '')) === '') {
                throw new \InvalidArgumentException('Legacy identity adoption requires the complete reviewed relationship');
            }
            if (isset($ids[$id])) { throw new \InvalidArgumentException('Duplicate legacy identity relationship'); }
            $ids[$id] = $item;
        }
        $started = !$this->db->inTransaction();
        if ($started) { $this->db->beginTransaction(); }
        $updated = [];
        try {
            foreach ($ids as $id=>$item) {
                $lock = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $read = $this->db->prepare('SELECT * FROM ucmp_extensions WHERE id=?' . $lock);
                $read->execute([$id]);
                $row = $read->fetch(\PDO::FETCH_ASSOC) ?: null;
                if (!$row || (int)$row['revision'] !== (int)$item['revision']) {
                    throw new \DomainException('Identity adoption plan is stale; review it again');
                }
                $person = $this->acceptedLegacyPerson((int)$row['ad_source_id'], (string)$row['ad_object_key']);
                $overrideRows = $this->legacyOverrideRows([$id])[$id] ?? [];
                $check = $this->legacyIdentityEligibility($row, $person, $overrideRows);
                if (!$check['eligible']
                    || (int)$row['ad_source_id'] !== (int)$item['source_id']
                    || (string)$row['ad_object_key'] !== (string)$item['object_key']
                    || (string)$row['ad_source_revision'] !== (string)$item['source_revision']) {
                    throw new \DomainException('Identity adoption evidence changed; review it again');
                }
                $update = $this->db->prepare(
                    "UPDATE ucmp_extensions
                        SET identity_adoption='managed',desired_identity_hash=NULL,
                            apply_status='pending',apply_error=NULL,revision=revision+1,updated=?
                      WHERE id=? AND revision=? AND identity_adoption='legacy_review'"
                );
                $update->execute([$now, $id, (int)$item['revision']]);
                if ($update->rowCount() !== 1) { throw new \DomainException('Identity adoption changed during commit'); }
                $updated[] = $id;
            }
            if ($started) { $this->db->commit(); }
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        return ['ok'=>true,'updated'=>count($updated),'extension_ids'=>$updated];
    }

    /** @return array<int,array<string,mixed>> */
    private function legacyOverrideRows(array $extensionIds): array
    {
        $extensionIds = array_values(array_unique(array_filter(array_map('intval', $extensionIds), static fn(int $id): bool => $id > 0)));
        if (!$extensionIds) { return []; }
        $marks = implode(',', array_fill(0, count($extensionIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT extension_id,source_id,object_key,source_revision,field_name,mode
               FROM ucmp_identity_overrides
              WHERE scope_type='extension' AND extension_id IN ({$marks})"
        );
        $stmt->execute($extensionIds);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['extension_id']][] = $row;
        }
        return $out;
    }

    private function acceptedLegacyPerson(int $sourceId, string $objectKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*,s.last_accepted_revision
               FROM ucmp_directory_entries d
               JOIN ucmp_directory_sources s ON s.id=d.source_id AND s.last_accepted_revision=d.source_revision
              WHERE d.source_id=? AND d.object_key=?'
        );
        $stmt->execute([$sourceId, $objectKey]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function legacyPersonFromPlanRow(array $row): ?array
    {
        if (($row['person_source_id'] ?? null) === null) { return null; }
        return [
            'source_id'=>(int)$row['person_source_id'],
            'object_key'=>(string)$row['person_object_key'],
            'source_revision'=>(string)$row['person_source_revision'],
            'last_accepted_revision'=>(string)$row['last_accepted_revision'],
            'display_name'=>(string)$row['person_display_name'],
            'label'=>(string)$row['person_label'],
            'extension'=>(string)$row['person_extension'],
            'department'=>(string)$row['person_department'],
            'company'=>(string)$row['person_company'],
            'org_unit'=>(string)$row['person_org_unit'],
            'mail'=>(string)$row['person_mail'],
            'mobile'=>(string)$row['person_mobile'],
            'attributes'=>$row['person_attributes'] ?? [],
            'site_id'=>(int)$row['person_site_id'],
            'conflicted'=>(int)$row['person_conflicted'],
        ];
    }

    /** @return array{eligible:bool,reasons:array<int,string>} */
    private function legacyIdentityEligibility(array $row, ?array $person, array $overrides): array
    {
        $reasons = [];
        if (($row['binding_mode'] ?? 'ad_primary') !== 'ad_primary') { $reasons[] = 'The relationship is not an AD primary binding'; }
        if (($row['identity_adoption'] ?? 'legacy_review') !== 'legacy_review') { $reasons[] = 'The relationship is already classified'; }
        if (trim((string)($row['manual_identity_json'] ?? '')) !== '') { $reasons[] = 'A manual identity is present'; }
        if (!$person) {
            $reasons[] = 'The AD object is absent from the currently accepted source revision';
        } else {
            if (!empty($person['conflicted'])) { $reasons[] = 'The accepted directory person has an unresolved conflict'; }
            if ((int)($person['source_id'] ?? 0) !== (int)($row['ad_source_id'] ?? 0)
                || (string)($person['object_key'] ?? '') !== (string)($row['ad_object_key'] ?? '')) {
                $reasons[] = 'The stable AD object identity does not match';
            }
            if (trim((string)($person['source_revision'] ?? '')) === ''
                || (string)$person['source_revision'] !== (string)($person['last_accepted_revision'] ?? '')) {
                $reasons[] = 'The accepted source revision is missing or stale';
            }
            if (trim((string)($row['ad_source_revision'] ?? '')) !== (string)($person['source_revision'] ?? '')) {
                $reasons[] = 'The logical relationship points to an older source revision';
            }
            if (trim((string)($person['display_name'] ?? '')) === '') { $reasons[] = 'The accepted AD person has no display name'; }
            if (trim((string)($person['extension'] ?? '')) === '') { $reasons[] = 'The accepted AD person has no extension'; }
            if ((int)($person['site_id'] ?? 0) !== (int)($row['site_id'] ?? 0)
                || (string)$person['extension'] !== (string)($row['extension'] ?? '')) {
                $reasons[] = 'The accepted AD person no longer owns this site/extension slot';
            }
        }
        foreach ($overrides as $override) {
            $source = (int)($override['source_id'] ?? 0);
            $object = trim((string)($override['object_key'] ?? ''));
            if ($source < 1 || $object === '') {
                $reasons[] = 'An existing identity override has no AD source/object provenance';
                continue;
            }
            if ($source !== (int)($row['ad_source_id'] ?? 0) || $object !== (string)($row['ad_object_key'] ?? '')) {
                $reasons[] = 'An existing identity override belongs to another AD object';
                continue;
            }
            $revision = trim((string)($override['source_revision'] ?? ''));
            if ($revision !== '' && $revision !== (string)($row['ad_source_revision'] ?? '')) {
                $reasons[] = 'An existing identity override belongs to another source revision';
            }
        }
        return ['eligible'=>!$reasons,'reasons'=>array_values(array_unique($reasons))];
    }

    public function setIdentityBinding(int $extensionId, string $mode, ?int $sourceId = null, ?string $objectKey = null, array $manualIdentity = [], ?int $expectedRevision = null): array
    {
        if (!in_array($mode, ['ad_primary','linked_additional','manual_shared'], true)) {
            throw new \InvalidArgumentException('Unknown identity binding mode');
        }
        $row = $this->findById($extensionId) ?: throw new \InvalidArgumentException('Logical extension not found');
        if ($expectedRevision !== null && $expectedRevision !== (int)$row['revision']) {
            throw new \DomainException('Identity binding changed; refresh before saving');
        }
        if ($mode === 'linked_additional') {
            if (($sourceId ?? 0) < 1) { throw new \InvalidArgumentException('Select an accepted directory person'); }
            $objectKey = self::normalizeObjectKey($objectKey ?? '');
            $person = $this->db->prepare('SELECT COUNT(*) FROM ucmp_directory_entries d JOIN ucmp_directory_sources s ON s.id=d.source_id AND s.last_accepted_revision=d.source_revision WHERE d.source_id=? AND d.object_key=?');
            $person->execute([$sourceId, $objectKey]);
            if ((int)$person->fetchColumn() !== 1) { throw new \DomainException('Selected person is absent or ambiguous in the accepted directory'); }
        }
        $manual = null;
        if ($mode === 'manual_shared') {
            $manualIdentity['extension'] = (string)$row['extension'];
            $manual = json_encode(self::normalizeAdIdentity($manualIdentity), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $started = !$this->db->inTransaction();
        if ($started) { $this->db->beginTransaction(); }
        try {
            $stmt = $this->db->prepare("UPDATE ucmp_extensions SET binding_mode=?,linked_source_id=?,linked_object_key=?,manual_identity_json=?,ad_source_id=NULL,ad_object_key=NULL,ad_source_revision=NULL,canonical_identity_hash=NULL,desired_identity_hash=NULL,applied_identity_hash=NULL,identity_adoption=?,apply_status='pending',apply_error=NULL,revision=revision+1,updated=? WHERE id=? AND revision=?");
            $stmt->execute([$mode, $mode === 'linked_additional' ? $sourceId : null, $mode === 'linked_additional' ? $objectKey : null, $manual, $mode === 'ad_primary' ? 'legacy_review' : 'managed', time(), $extensionId, (int)$row['revision']]);
            if ($stmt->rowCount() !== 1) { throw new \DomainException('Identity binding changed while saving'); }
            $previous = self::identityBinding($row);
            if ($previous['source_id'] > 0) { $this->retainOverrideOwner($extensionId, $previous['source_id'], $previous['object_key']); }
            if ($started) { $this->db->commit(); }
        } catch (\Throwable $e) { if ($started && $this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        return $this->findById($extensionId);
    }

    /** Resolve a slot against the accepted person, never against the person's former number. */
    public static function resolveBoundIdentity(array $row, ?array $acceptedPerson, array $overrides = [], ?callable $formatter = null): array
    {
        $binding = self::identityBinding($row);
        if ($binding['mode'] === 'manual_shared') {
            $identity = json_decode((string)($row['manual_identity_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($identity)) { throw new \DomainException('Manual/shared identity has not been configured'); }
            $source = 'legacy';
        } else {
            if ($acceptedPerson === null) {
                return ['resolved'=>false,'binding'=>$binding,'warnings'=>['Linked person is absent from the last accepted directory'],'ownership'=>['identity'=>$binding['mode']],'full_name'=>null,'short_name'=>null];
            }
            if ((int)($acceptedPerson['source_id'] ?? 0) !== $binding['source_id'] || (string)($acceptedPerson['object_key'] ?? '') !== $binding['object_key']) {
                throw new \DomainException('Accepted person does not match the stable identity binding');
            }
            $identity = $acceptedPerson;
            $source = 'ad';
        }
        $identity['extension'] = (string)$row['extension'];
        $warnings = [];
        $attributes = $identity['attributes'] ?? [];
        if (is_string($attributes)) { $attributes = json_decode($attributes, true) ?: []; }
        $attr = static function (string $key) use ($attributes): string {
            $value = $attributes[strtolower($key)] ?? '';
            return trim((string)(is_array($value) ? ($value[0] ?? '') : $value));
        };
        if ($source === 'ad') {
            $sam = $attr('sAMAccountName');
            $upn = explode('@', $attr('userPrincipalName'), 2)[0];
            if ($sam !== '' && $upn !== '' && strcasecmp($sam, $upn) !== 0) { $warnings[] = 'sAMAccountName and UPN prefix differ; sAMAccountName is the short name'; }
            if ($sam !== '' || $upn !== '') { $identity['label'] = $sam !== '' ? $sam : $upn; }
            if ($sam === '' && $upn !== '') { $warnings[] = 'Short name uses UPN prefix because sAMAccountName is absent'; }
            if ($sam === '' && $upn === '') { $warnings[] = 'Account short-name attributes are absent; accepted legacy label is retained'; }
            foreach (['given_name'=>'givenName','surname'=>'sn','initial'=>'initials'] as $field=>$attribute) { $identity[$field] = $attr($attribute); }
        }
        $eligible = [];
        foreach ($overrides as $override) {
            if (!empty($override['source_id']) && ((int)$override['source_id'] !== $binding['source_id'] || (string)$override['object_key'] !== $binding['object_key'])) {
                $warnings[] = 'An override belonging to the previous person is retained for review';
                continue;
            }
            $eligible[] = $override;
        }
        $resolved = self::resolveIdentityFromSource($identity, $source, $eligible, $formatter);
        return $resolved + ['resolved'=>true,'binding'=>$binding,'full_name'=>$resolved['canonical']['display_name'],'short_name'=>$resolved['canonical']['label'],'warnings'=>$warnings,'ownership'=>['identity'=>$binding['mode'],'caller_name'=>$binding['adoption'],'labels'=>$binding['adoption']]];
    }

    public function previewIdentityAdoption(int $extensionId, array $legacyValues, array $proposedIdentity): array
    {
        $row = $this->findById($extensionId) ?: throw new \InvalidArgumentException('Logical extension not found');
        $fields = [];
        foreach (array_merge(['display_name'], self::FINAL_FIELDS) as $field) {
            $fields[$field] = ['current'=>$legacyValues[$field] ?? null,'proposed'=>$proposedIdentity['outputs'][$field] ?? $proposedIdentity['canonical'][$field] ?? null,'choice_required'=>true];
        }
        return ['extension_id'=>$extensionId,'revision'=>(int)$row['revision'],'binding'=>self::identityBinding($row),'fields'=>$fields,'overrides'=>$this->overridesForExtension($extensionId, true)];
    }

    /** Caller supplies explicit per-field managed/preserve decisions from the preview. */
    public function adoptIdentity(int $extensionId, int $expectedRevision, array $choices, string $actor): array
    {
        $row = $this->findById($extensionId) ?: throw new \InvalidArgumentException('Logical extension not found');
        if ((int)$row['revision'] !== $expectedRevision || trim($actor) === '') { throw new \DomainException('Refresh the adoption preview and identify its reviewer'); }
        foreach (array_merge(['display_name'], self::FINAL_FIELDS) as $field) {
            $choice = $choices[$field] ?? [];
            if (!in_array($choice['mode'] ?? '', ['managed','preserve'], true) || (($choice['mode'] ?? '') === 'preserve' && !array_key_exists('value', $choice))) {
                throw new \InvalidArgumentException('Every identity field needs an explicit managed or preserve decision');
            }
        }
        $started = !$this->db->inTransaction();
        if ($started) { $this->db->beginTransaction(); }
        try {
            $lock=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $read=$this->db->prepare('SELECT revision FROM ucmp_extensions WHERE id=?'.$lock);$read->execute([$extensionId]);
            if ((int)$read->fetchColumn()!==$expectedRevision) { throw new \DomainException('Identity changed before adoption'); }
            foreach ($choices as $field=>$choice) {
                $mode = $field === 'display_name' ? 'canonical' : 'final';
                if ($choice['mode'] === 'preserve') {
                    $this->saveExtensionOverride($extensionId, $field, $mode, $choice['value'], 'Reviewed identity adoption', $actor);
                } else { $this->revertExtensionOverride($extensionId, $field, $mode); }
            }
            $postOverrideRevision=(int)$this->findById($extensionId)['revision'];
            $stmt = $this->db->prepare("UPDATE ucmp_extensions SET identity_adoption='managed',desired_identity_hash=NULL,apply_status='pending',revision=revision+1,updated=? WHERE id=? AND revision=?");
            $stmt->execute([time(),$extensionId,$postOverrideRevision]);
            if ($stmt->rowCount() !== 1) { throw new \DomainException('Identity changed during adoption'); }
            if ($started) { $this->db->commit(); }
        } catch (\Throwable $e) { if ($started && $this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        return $this->findById($extensionId);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_extensions WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySiteExtension(int $siteId, string $extension): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ucmp_extensions WHERE site_id=? AND extension=?'
        );
        $stmt->execute([$siteId, self::normalizeExtension($extension)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByAdObject(int $sourceId, string $objectKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ucmp_extensions WHERE ad_source_id=? AND ad_object_key=?'
        );
        $stmt->execute([$sourceId, self::normalizeObjectKey($objectKey)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Store an extension-scoped override without altering the accepted AD row.
     *
     * @return array<string,mixed>
     */
    public function saveExtensionOverride(
        int $extensionId,
        string $field,
        string $mode,
        $value,
        string $reason,
        string $actor,
        ?string $sourceRevision = null,
        ?int $now = null
    ): array {
        return $this->changeExtensionOverride($extensionId,fn()=>$this->saveExtensionOverrideValue($extensionId,$field,$mode,$value,$reason,$actor,$sourceRevision,$now));
    }

    /** Save a set of fields as one identity revision; reject the complete set on any invalid field. */
    public function saveExtensionOverrides(int $extensionId, array $fields, string $reason, string $actor): array
    {
        if (!$fields || count($fields) > count(self::CANONICAL_FIELDS) + count(self::FINAL_FIELDS)) {
            throw new \InvalidArgumentException('Choose at least one supported identity field');
        }
        return $this->changeExtensionOverride($extensionId, function () use ($extensionId,$fields,$reason,$actor): array {
            $saved = [];
            foreach ($fields as $field=>$value) {
                $mode = in_array($field, self::FINAL_FIELDS, true) ? 'final' : 'canonical';
                $saved[] = $this->saveExtensionOverrideValue($extensionId, (string)$field, $mode, $value, $reason, $actor);
            }
            return $saved;
        });
    }

    private function saveExtensionOverrideValue(
        int $extensionId,
        string $field,
        string $mode,
        $value,
        string $reason,
        string $actor,
        ?string $sourceRevision = null,
        ?int $now = null
    ): array {
        $extension = $this->findById($extensionId);
        if (!$extension) {
            throw new \InvalidArgumentException('Logical extension was not found');
        }
        $field = self::validateOverrideField($field, $mode);
        self::validateOverrideValue($field, $mode, $value);
        $reason = trim($reason);
        $actor = trim($actor);
        if ($reason === '' || $actor === '') {
            throw new \InvalidArgumentException('Override reason and actor are required');
        }
        if (strlen($reason) > 255 || strlen($actor) > 128) {
            throw new \InvalidArgumentException('Override reason or actor is too long');
        }
        $sourceRevision = $sourceRevision !== null
            ? self::normalizeSourceRevision($sourceRevision)
            : ($extension['ad_source_revision'] ?? null);
        $scopeKey = self::extensionScopeKey($extensionId);
        $valueJson = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        $now = $now ?? time();

        $sql = 'INSERT INTO ucmp_identity_overrides
                (scope_key,scope_type,source_id,object_key,extension_id,site_id,
                 field_name,mode,value_json,reason,source_revision,
                 created_by,updated_by,created,updated)
             VALUES (?,\'extension\',?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                value_json=VALUES(value_json),reason=VALUES(reason),source_id=VALUES(source_id),object_key=VALUES(object_key),
                source_revision=VALUES(source_revision),updated_by=VALUES(updated_by),
                updated=VALUES(updated)';
        if ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(scope_key,field_name,mode) DO UPDATE SET', $sql);
            $sql = preg_replace('/VALUES\(([a-z_]+)\)/', 'excluded.$1', $sql);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $scopeKey,
            self::identityBinding($extension)['source_id'] ?: null,
            self::identityBinding($extension)['object_key'] ?: null,
            $extensionId,
            $extension['site_id'],
            $field,
            $mode,
            $valueJson,
            $reason,
            $sourceRevision,
            $actor,
            $actor,
            $now,
            $now,
        ]);

        foreach ($this->overridesForExtension($extensionId) as $override) {
            if ($override['field_name'] === $field && $override['mode'] === $mode) {
                return $override;
            }
        }
        throw new \RuntimeException('Saved identity override could not be reloaded');
    }

    /** @return array<int,array<string,mixed>> */
    private function retainOverrideOwner(int $extensionId, int $sourceId, string $objectKey): void
    {
        $this->db->prepare('UPDATE ucmp_identity_overrides SET source_id=?,object_key=? WHERE extension_id=? AND source_id IS NULL')->execute([$sourceId,$objectKey,$extensionId]);
    }

    public function overridesForExtension(int $extensionId, bool $includeInactive = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ucmp_identity_overrides
              WHERE scope_key=? ORDER BY mode,field_name,id'
        );
        $stmt->execute([self::extensionScopeKey($extensionId)]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (!$includeInactive) {
            $binding = self::identityBinding($this->findById($extensionId) ?? []);
            $rows = array_values(array_filter($rows, static fn(array $row): bool => empty($row['source_id']) || ((int)$row['source_id'] === $binding['source_id'] && (string)$row['object_key'] === $binding['object_key'])));
        }
        foreach ($rows as &$row) {
            try {
                $row['value'] = json_decode(
                    (string)$row['value_json'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $e) {
                throw new \RuntimeException(
                    'Stored identity override ' . (int)$row['id'] . ' contains invalid JSON',
                    0,
                    $e
                );
            }
        }
        unset($row);
        return $rows;
    }

    public function revertExtensionOverride(int $extensionId, string $field, string $mode): bool
    {
        return $this->changeExtensionOverride($extensionId,function() use ($extensionId,$field,$mode): bool {
            $field = self::validateOverrideField($field, $mode);
            $stmt = $this->db->prepare('DELETE FROM ucmp_identity_overrides WHERE scope_key=? AND field_name=? AND mode=?');
            $stmt->execute([self::extensionScopeKey($extensionId), $field, $mode]);
            return $stmt->rowCount() > 0;
        });
    }

    /** Keep effective override changes and their review revision inseparable. */
    private function changeExtensionOverride(int $extensionId,callable $change)
    {
        $started=!$this->db->inTransaction();if ($started) { $this->db->beginTransaction(); }
        try {
            $lock=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $read=$this->db->prepare('SELECT revision FROM ucmp_extensions WHERE id=?'.$lock);$read->execute([$extensionId]);$revision=$read->fetchColumn();
            if ($revision===false) { throw new \InvalidArgumentException('Logical extension was not found'); }
            $result=$change();
            if ($result!==false) {
                $update=$this->db->prepare("UPDATE ucmp_extensions SET revision=revision+1,desired_identity_hash=NULL,applied_identity_hash=NULL,apply_status='pending',apply_error=NULL,updated=? WHERE id=? AND revision=?");
                $update->execute([time(),$extensionId,(int)$revision]);
                if ($update->rowCount()!==1) { throw new \DomainException('Identity changed while saving its override'); }
            }
            if ($started && !$this->db->commit()) { throw new \RuntimeException('Override transaction commit was not confirmed'); }
            return $result;
        } catch (\Throwable $error) { if ($started && $this->db->inTransaction()) { $this->db->rollBack(); }throw $error; }
    }

    private static function defaultOutputs(array $identity): array
    {
        $label = (string)(($identity['label'] ?? '') !== ''
            ? $identity['label']
            : $identity['display_name']);
        return array_fill_keys(self::FINAL_FIELDS, $label);
    }

    private static function normalizeCanonicalOverride(string $field, $value): string
    {
        self::validateOverrideValue($field, 'canonical', $value);
        return $field === 'extension'
            ? self::normalizeExtension($value)
            : trim((string)$value);
    }

    private static function normalizeFinalValue($value): string
    {
        self::validateOverrideValue('phone_label', 'final', $value);
        return (string)$value;
    }

    private static function validateOverrideValue(string $field, string $mode, $value): void
    {
        if ($value === null || (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value))) {
            throw new \InvalidArgumentException('Identity override value must be a non-null scalar');
        }
        $string = (string)$value;
        if (strlen($string) > 4096) {
            throw new \InvalidArgumentException('Identity override value is too long');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $string)) {
            throw new \InvalidArgumentException('Identity override contains control characters');
        }
        if ($mode === 'canonical' && in_array($field, ['display_name', 'extension'], true) && trim($string) === '') {
            throw new \InvalidArgumentException("Canonical {$field} override cannot be empty");
        }
        if ($mode === 'canonical' && $field === 'extension') {
            self::normalizeExtension($value);
        }
    }

    private static function validateOverrideField(string $field, string $mode): string
    {
        $field = trim($field);
        $allowed = $mode === 'canonical'
            ? self::CANONICAL_FIELDS
            : ($mode === 'final' ? self::FINAL_FIELDS : []);
        if (!in_array($field, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported {$mode} identity override field: {$field}");
        }
        return $field;
    }

    private static function overrideValue(array $override)
    {
        if (array_key_exists('value', $override)) {
            return $override['value'];
        }
        if (!array_key_exists('value_json', $override)) {
            throw new \InvalidArgumentException('Identity override has no value');
        }
        try {
            return json_decode((string)$override['value_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Identity override JSON is invalid', 0, $e);
        }
    }

    private static function overrideTrace(array $override): array
    {
        return [
            'source' => 'override',
            'override_id' => isset($override['id']) ? (int)$override['id'] : null,
            'reason' => $override['reason'] ?? null,
            'source_revision' => $override['source_revision'] ?? null,
        ];
    }

    private static function extensionScopeKey(int $extensionId): string
    {
        if ($extensionId < 1) {
            throw new \InvalidArgumentException('Logical extension ID must be positive');
        }
        return 'extension:' . $extensionId;
    }

    private static function normalizeObjectKey(string $objectKey): string
    {
        $objectKey = trim($objectKey);
        if ($objectKey === '' || strlen($objectKey) > 128) {
            throw new \InvalidArgumentException('AD object key is empty or too long');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $objectKey)) {
            throw new \InvalidArgumentException('AD object key contains control characters');
        }
        return $objectKey;
    }

    private static function normalizeSourceRevision(string $revision): string
    {
        $revision = trim($revision);
        if ($revision === '' || strlen($revision) > 64) {
            throw new \InvalidArgumentException('AD source revision is empty or too long');
        }
        return $revision;
    }

    private static function assertHash(string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            throw new \InvalidArgumentException('Identity hash must be SHA-256');
        }
    }

    private static function stableHash(array $value): string
    {
        $stable = self::stableValue($value);
        return hash(
            'sha256',
            json_encode(
                $stable,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
            )
        );
    }

    private static function stableValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'stableValue'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = self::stableValue($item);
        }
        unset($item);
        return $value;
    }
}
