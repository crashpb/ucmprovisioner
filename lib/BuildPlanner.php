<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Produces the one canonical manifest used by preview and apply.
 *
 * File contents stay outside the manifest; only immutable metadata and hashes
 * are returned to callers/UI.  The plan hash deliberately excludes the
 * transaction id and timestamp so an apply can prove that it is committing the
 * exact configuration that the operator previewed.
 */
final class BuildPlanner
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<int,array<string,mixed>> $resolvedPhones
     * @param array<int,array<string,mixed>> $files rows containing filename/content
     * @param array<string,mixed> $gluePlan
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function plan(
        int $siteId,
        array $resolvedPhones,
        array $files,
        array $gluePlan,
        array $context = []
    ): array {
        if ($siteId < 1) {
            throw new \InvalidArgumentException('A build requires a valid site id');
        }

        $manifestFiles = [];
        $seen = [];
        foreach ($files as $file) {
            $filename = trim((string)($file['filename'] ?? ''));
            $content = (string)($file['content'] ?? '');
            self::assertFilename($filename);
            $hash = hash('sha256', $content);
            if (isset($seen[$filename]) && !hash_equals($seen[$filename], $hash)) {
                throw new \RuntimeException("Build contains conflicting content for $filename");
            }
            if (isset($seen[$filename])) {
                continue;
            }
            $seen[$filename] = $hash;
            $manifestFiles[] = [
                'filename' => $filename,
                'role' => self::token((string)($file['role'] ?? 'config'), 48, 'config'),
                'phone_id' => self::positiveIntOrNull($file['phone_id'] ?? null),
                'extension' => self::cleanText((string)($file['extension'] ?? ''), 64),
                'asset_id' => self::positiveIntOrNull($file['asset_id'] ?? null),
                'bytes' => strlen($content),
                'sha256' => $hash,
            ];
        }
        usort($manifestFiles, static fn(array $a, array $b): int => strcmp($a['filename'], $b['filename']));

        $phones = [];
        foreach ($resolvedPhones as $resolved) {
            $phone = is_array($resolved['phone'] ?? null) ? $resolved['phone'] : [];
            $model = is_array($resolved['model'] ?? null) ? $resolved['model'] : [];
            $settings = is_array($resolved['settings'] ?? null) ? $resolved['settings'] : [];
            $id = (int)($phone['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $phones[] = [
                'phone_id' => $id,
                'extension_id' => self::positiveIntOrNull($phone['extension_id'] ?? null),
                'extension' => self::cleanText((string)($phone['extension'] ?? ''), 64),
                'model_id' => self::positiveIntOrNull($phone['model_id'] ?? $model['id'] ?? null),
                'vendor' => self::cleanText((string)($model['vendor'] ?? ''), 64),
                'model' => self::cleanText((string)($model['model'] ?? ''), 64),
                'family' => self::token((string)($model['family'] ?? ''), 48),
                'catalog_revision' => self::cleanText((string)($model['catalog_revision'] ?? ''), 64),
                'template_stack' => self::normalizeTrace($settings['_template_stack'] ?? []),
                'template_trace' => self::normalizeTrace($settings['_template_trace'] ?? []),
                'ad_source_revision' => self::cleanText(
                    (string)($settings['_ad_source_revision'] ?? $phone['source_revision'] ?? ''),
                    128
                ),
                'override_revision' => self::cleanText(
                    (string)($settings['_identity_override_revision'] ?? $phone['revision'] ?? ''),
                    128
                ),
                'asset_ids' => self::assetIds($settings),
            ];
        }
        usort($phones, static fn(array $a, array $b): int => $a['phone_id'] <=> $b['phone_id']);

        $stable = [
            'schema_version' => self::SCHEMA_VERSION,
            'site_id' => $siteId,
            'previous_generation_id' => self::cleanText(
                (string)($context['previous_generation_id'] ?? ''),
                96
            ),
            'catalog_revision' => self::cleanText((string)($context['catalog_revision'] ?? ''), 128),
            'directory_revisions' => self::normalizeScalarMap($context['directory_revisions'] ?? []),
            'phones' => $phones,
            'files' => $manifestFiles,
            'asterisk_glue_sha256' => hash('sha256', self::canonicalJson(self::stableGlue($gluePlan))),
        ];
        $planHash = hash('sha256', self::canonicalJson($stable));

        return $stable + [
            'transaction_id' => self::transactionId(),
            'created_at' => time(),
            'plan_hash' => $planHash,
        ];
    }

    public static function assertFilename(string $filename): void
    {
        if (
            $filename === ''
            || basename($filename) !== $filename
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $filename)
        ) {
            throw new \InvalidArgumentException('Generated TFTP filename must be one safe basename');
        }
    }

    public static function verifyManifest(array $manifest): void
    {
        $expected = (string)($manifest['plan_hash'] ?? '');
        unset($manifest['plan_hash'], $manifest['transaction_id'], $manifest['created_at']);
        if (!hash_equals($expected, hash('sha256', self::canonicalJson($manifest)))) {
            throw new \RuntimeException('Staged manifest does not match its preview hash');
        }
    }

    public static function assertTransactionId(string $transactionId): void
    {
        if (!preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/D', $transactionId)) {
            throw new \InvalidArgumentException('Invalid build transaction id');
        }
    }

    /** @param mixed $value */
    private static function positiveIntOrNull($value): ?int
    {
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private static function transactionId(): string
    {
        return gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
    }

    private static function cleanText(string $value, int $max): string
    {
        $value = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private static function token(string $value, int $max, string $default = ''): string
    {
        $value = strtolower(trim($value));
        return preg_match('/\A[a-z0-9][a-z0-9_.-]{0,' . max(0, $max - 1) . '}\z/D', $value)
            ? $value
            : $default;
    }

    /** @param mixed $trace */
    private static function normalizeTrace($trace): array
    {
        if (!is_array($trace)) {
            return [];
        }
        $out = [];
        foreach (array_slice($trace, 0, 64) as $row) {
            if (is_scalar($row) || $row === null) {
                $out[] = self::cleanText((string)$row, 256);
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $safe = [];
            foreach (array_slice($row, 0, 24, true) as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $safe[self::cleanText((string)$key, 64)] = self::cleanText((string)$value, 512);
                }
            }
            $out[] = $safe;
        }
        return $out;
    }

    /** @param array<string,mixed> $settings */
    private static function assetIds(array $settings): array
    {
        $ids = [];
        foreach ($settings as $key => $value) {
            if (!str_ends_with((string)$key, '_asset_id')) {
                continue;
            }
            $id = (int)$value;
            if ($id > 0) {
                $ids[(string)$key] = $id;
            }
        }
        ksort($ids, SORT_STRING);
        return $ids;
    }

    /** @param mixed $value */
    private static function normalizeScalarMap($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $out[self::cleanText((string)$key, 128)] = self::cleanText((string)$item, 256);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<string,mixed> $plan */
    private static function stableGlue(array $plan): array
    {
        $stable = [];
        foreach ([
            'sip_custom_post_block', 'extensions_override_block', 'cross_site_hints_block',
            'requires_patch', 'managed_extensions', 'subscribes', 'cross_site_targets',
            'conflicts', 'migrations', 'migration_objects', 'conflict_objects', 'onetime',
        ] as $key) {
            if (array_key_exists($key, $plan)) {
                $stable[$key] = $plan[$key];
            }
        }
        return $stable;
    }

    /** @param mixed $value */
    private static function canonicalJson($value): string
    {
        $sort = static function ($item) use (&$sort) {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        return (string)json_encode(
            $sort($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
