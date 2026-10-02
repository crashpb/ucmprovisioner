<?php
declare(strict_types=1);

/**
 * Secret-free compatibility join for existing firmware/media inventories.
 *
 * It owns no storage and performs no I/O. Callers supply FirmwareService
 * bundles or media/TFTP items and receive only a small, safe projection.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/AssetFilename.php';

final class AssetCatalog
{
    private const KINDS = [
        'firmware'=>'firmware', 'fw'=>'firmware', 'firmware_bundle'=>'firmware',
        'wallpaper'=>'background', 'background'=>'background', 'wallpaper_background'=>'background',
        'image'=>'background', 'logo'=>'background',
        'ringtone'=>'ringtone', 'ring'=>'ringtone', 'ring_tone'=>'ringtone', 'audio'=>'ringtone',
        'timezone'=>'locale', 'time_zone'=>'locale', 'locale'=>'locale',
        'timezone_locale'=>'locale', 'tzdata'=>'locale', 'timezone_data'=>'locale',
        'network_locale'=>'locale', 'language'=>'locale',
        'phonebook'=>'phonebook', 'directory'=>'phonebook', 'contacts'=>'phonebook',
        'directory_catalog'=>'phonebook',
    ];

    public static function normalizeKind(string $kind): ?string
    {
        $kind = strtolower(trim($kind));
        if (str_starts_with($kind, 'image/')) { return 'background'; }
        if (str_starts_with($kind, 'audio/')) { return 'ringtone'; }
        $kind = trim((string)preg_replace('/[^a-z0-9]+/', '_', $kind), '_');
        return self::KINDS[$kind] ?? null;
    }

    /**
     * Select one kind of asset for a vendor/family/profile/model/role target.
     *
     * Existing `bundles`, `items`, and `entries` envelopes are accepted. Put
     * compatibility fields on an envelope to apply them to every child.
     *
     * @param array<int|string,mixed> $artifacts
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    public static function select(array $artifacts, array $target): array
    {
        $kind = self::normalizeKind((string)($target['kind'] ?? ''));
        if ($kind === null) {
            throw new \InvalidArgumentException('Choose a supported asset kind');
        }
        $context = self::target($target, $kind);
        $available = [];
        $incompatible = [];
        $observed = 0;
        $unrecognized = 0;

        foreach (self::expand($artifacts) as $record) {
            $recordKind = self::recordKind($record);
            if ($recordKind === null) {
                $unrecognized++;
                continue;
            }
            if ($recordKind !== $kind) { continue; }
            $observed++;

            $asset = self::publicAsset($record, $kind);
            $reasons = $context['issues'];
            if (!$context['supported']) {
                $reasons[] = self::reason(
                    'unsupported_for_model',
                    trim($context['vendor'] . ' ' . $context['model']) . " has no verified $kind compatibility profile."
                );
            }
            $reasons = array_merge($reasons, self::availability($record, $kind, $asset));
            [$matchReasons, $score] = self::match($record, $kind, $context);
            $reasons = array_merge($reasons, $matchReasons);
            if ($reasons) {
                $incompatible[] = ['asset'=>$asset, 'reasons'=>$reasons];
                continue;
            }

            if (self::truthy($record['preferred'] ?? $record['default'] ?? $record['selected'] ?? false)) {
                $score += 64;
                $asset['preferred'] = true;
            }
            $key = hash('sha256', (string)json_encode($asset, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            if (!isset($available[$key]) || $score > $available[$key]['score']) {
                $available[$key] = ['asset'=>$asset, 'score'=>$score];
            }
        }

        $available = array_values($available);
        usort($available, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: strnatcasecmp((string)$left['asset']['name'], (string)$right['asset']['name']);
        });
        $selected = null;
        $selectionReason = null;
        if ($available) {
            $top = array_values(array_filter(
                $available,
                static fn(array $candidate): bool => $candidate['score'] === $available[0]['score']
            ));
            if (count($top) === 1) {
                $selected = $top[0]['asset'];
                $selectionReason = self::reason(
                    count($available) === 1 ? 'only_compatible' : 'most_specific',
                    count($available) === 1
                        ? 'The only compatible managed asset was selected.'
                        : 'The most specific compatible managed asset was selected.'
                );
            } else {
                $selectionReason = self::reason(
                    'ambiguous',
                    'Multiple equally specific assets are available; choose one explicitly.'
                );
            }
        }

        $missingReason = null;
        if (!$available) {
            $missingReason = $context['issues'][0] ?? null;
            if ($missingReason === null && !$context['supported']) {
                $missingReason = self::reason(
                    'unsupported_for_model',
                    trim($context['vendor'] . ' ' . $context['model']) . " has no verified $kind compatibility profile."
                );
            } elseif ($missingReason === null && $observed === 0) {
                $missingReason = self::reason('no_managed_artifacts', "No managed $kind artifacts were supplied.");
            } elseif ($missingReason === null) {
                $missingReason = self::reason(
                    'no_compatible_artifacts',
                    "Managed $kind artifacts exist, but none are available and compatible."
                );
            }
        }

        return [
            'kind'=>$kind,
            'target'=>[
                'vendor'=>$context['vendor'], 'family'=>$context['family'],
                'profile'=>$context['profile'], 'model'=>$context['model'], 'role'=>$context['role'],
            ],
            'issues'=>$context['issues'],
            'selected'=>$selected,
            'selection_reason'=>$selectionReason,
            'available'=>array_column($available, 'asset'),
            'incompatible'=>$incompatible,
            'missing_reason'=>$missingReason,
            'unrecognized_count'=>$unrecognized,
        ];
    }

    /** @return array<string,mixed> */
    private static function target(array $input, string $kind): array
    {
        $vendor = self::text((string)($input['vendor'] ?? ''));
        $family = strtolower(self::text((string)($input['family'] ?? '')));
        $model = self::text((string)($input['model'] ?? ''));
        $role = strtolower(self::text((string)($input['role'] ?? '')));
        $issues = [];
        $cataloged = false;
        $profile = null;

        $row = $vendor !== '' && $model !== '' ? self::modelRow($vendor, $model) : null;
        if ($row !== null) {
            $cataloged = true;
            $vendor = (string)$row[0];
            $model = (string)$row[1];
            $catalogFamily = strtolower((string)$row[2]);
            $capabilities = ModelCatalog::capabilitiesFor($vendor, $model) ?? [];
            $catalogRole = strtolower((string)($capabilities['role'] ?? ''));
            if ($family !== '' && !self::same($family, $catalogFamily)) {
                $issues[] = self::reason('target_family_mismatch', "$vendor $model belongs to $catalogFamily, not $family.");
            }
            if ($role !== '' && $catalogRole !== '' && !self::same($role, $catalogRole)) {
                $issues[] = self::reason('target_role_mismatch', "$vendor $model has role $catalogRole, not $role.");
            }
            if ($family === '') { $family = $catalogFamily; }
            if ($role === '') { $role = $catalogRole; }
            $profile = match ($kind) {
                'firmware' => self::nullable($capabilities['firmware_profile'] ?? null),
                'background' => self::nullable(
                    ModelCatalog::backgroundProfileKey($vendor, $model)
                        ?? ModelCatalog::hostedMediaProfileKey($vendor, $model)
                ),
                'ringtone' => strcasecmp($vendor, 'Cisco') === 0
                    ? 'cisco-ringtone'
                    : self::nullable($capabilities['config_profile'] ?? $capabilities['media_profile'] ?? null),
                'locale' => strcasecmp($vendor, 'Cisco') === 0
                    ? self::nullable(ModelCatalog::ciscoTimezoneProfile($vendor, $model, $catalogFamily))
                    : self::nullable($capabilities['config_profile'] ?? null),
                'phonebook' => self::nullable($capabilities['directory_profile'] ?? null),
            };
        } elseif ($model !== '') {
            $issues[] = self::reason(
                'target_model_unknown',
                trim("$vendor $model") . ' has no supported ModelCatalog profile.'
            );
        }

        if (isset($input['profiles']) && is_array($input['profiles'])) {
            foreach ($input['profiles'] as $profileKind => $value) {
                if (self::normalizeKind((string)$profileKind) === $kind) { $profile = self::nullable($value); }
            }
        }
        if (array_key_exists('profile', $input)) { $profile = self::nullable($input['profile']); }

        return [
            'vendor'=>$vendor, 'family'=>$family, 'profile'=>$profile,
            'model'=>$model, 'role'=>$role, 'issues'=>$issues,
            // An explicit profile narrows a supported model; it cannot invent
            // support for a catalogued model whose native profile is absent.
            'supported'=>!$cataloged || match ($kind) {
                'firmware' => ModelCatalog::firmwareProfile($vendor, $model) !== null,
                'background' => ModelCatalog::backgroundProfileKey($vendor, $model) !== null
                    || ModelCatalog::hostedMediaProfileKey($vendor, $model) !== null,
                'ringtone' => !empty((ModelCatalog::capabilitiesFor($vendor, $model) ?? [])['config_profile']),
                'locale' => strcasecmp($vendor, 'Cisco') === 0
                    ? ModelCatalog::ciscoTimezoneProfile($vendor, $model, $catalogFamily) !== null
                    : !empty((ModelCatalog::capabilitiesFor($vendor, $model) ?? [])['config_profile']),
                'phonebook' => !empty((ModelCatalog::capabilitiesFor($vendor, $model) ?? [])['directory_profile']),
            },
        ];
    }

    private static function modelRow(string $vendor, string $model): ?array
    {
        foreach (ModelCatalog::CATALOG as $row) {
            if (self::same((string)$row[0], $vendor) && self::same((string)$row[1], $model)) { return $row; }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private static function expand(array $records): array
    {
        if ($records && !array_is_list($records)) { $records = [$records]; }
        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) { continue; }
            $envelope = false;
            foreach (['bundles'=>'firmware', 'items'=>null, 'entries'=>null] as $field => $defaultKind) {
                if (!isset($record[$field]) || !is_array($record[$field])) { continue; }
                $envelope = true;
                $context = array_intersect_key($record, array_flip([
                    'kind','artifact_kind','type','category','vendor','family','profile','profiles',
                    'model','models','role','roles','available','selectable','ok','status',
                ]));
                if ($defaultKind !== null
                    && !isset($context['kind']) && !isset($context['artifact_kind']) && !isset($context['type']) && !isset($context['category'])) {
                    $context['kind'] = $defaultKind;
                }
                foreach ($record[$field] as $child) {
                    if (is_array($child)) { $out[] = array_merge($context, $child); }
                }
            }
            if (!$envelope) { $out[] = $record; }
        }
        return $out;
    }

    private static function recordKind(array $record): ?string
    {
        foreach (['kind','artifact_kind','type','category'] as $field) {
            if (isset($record[$field]) && is_scalar($record[$field])) {
                $kind = self::normalizeKind((string)$record[$field]);
                if ($kind !== null) { return $kind; }
            }
        }
        if (isset($record['load_name'])) { return 'firmware'; }
        $name = basename((string)($record['name'] ?? $record['filename'] ?? $record['path'] ?? ''));
        if (in_array(strtolower($name), [
            'tzupdater.ver', 'j9-tzdata.jar', 'tzupdater.jar', 'tzdatacsv.csv', 'tzdb.dat',
        ], true)) { return 'locale'; }
        if (AssetFilename::isTimezone($name)) { return 'locale'; }
        if (preg_match('/\.(?:raw|rwb|wav|mp3|ogg)$/i', $name)) { return 'ringtone'; }
        if (preg_match('/\.(?:png|jpe?g|gif|bmp)$/i', $name)) { return 'background'; }
        return null;
    }

    /** @return array{0:array<int,array{code:string,message:string}>,1:int} */
    private static function match(array $record, string $kind, array $target): array
    {
        $reasons = [];
        $score = 0;
        $checks = [
            'vendor'=>[self::values($record['vendor'] ?? null), $target['vendor'], 2],
            'family'=>[self::values($record['family'] ?? null), $target['family'], 4],
            'profile'=>[self::profiles($record, $kind), $target['profile'], 8],
            'model'=>[self::values($record['models'] ?? $record['model'] ?? null), $target['model'], 16],
            'role'=>[self::values($record['roles'] ?? $record['role'] ?? null), $target['role'], 1],
        ];
        foreach ($checks as $field => [$allowed, $actual, $weight]) {
            if (!$allowed || $actual === null || trim((string)$actual) === '') { continue; }
            if (!self::contains($allowed, (string)$actual)) {
                $reasons[] = self::reason(
                    $field . '_mismatch',
                    ucfirst($field) . ' requires ' . implode(' or ', $allowed) . ', not ' . (string)$actual . '.'
                );
            } else {
                $score += $weight;
            }
        }

        if ($target['model'] !== '' && isset($record['compatible_models']) && is_array($record['compatible_models'])) {
            $matched = false;
            foreach ($record['compatible_models'] as $compatible) {
                if (is_array($compatible)
                    && self::same((string)($compatible['model'] ?? ''), $target['model'])
                    && ((string)($compatible['vendor'] ?? '') === ''
                        || self::same((string)$compatible['vendor'], $target['vendor']))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $reasons[] = self::reason('model_mismatch', 'The artifact does not list this model as compatible.');
            } else {
                $score += 16;
            }
        }
        return [$reasons, $score];
    }

    /** @return array<int,array{code:string,message:string}> */
    private static function availability(array $record, string $kind, array $asset): array
    {
        $reasons = [];
        if (self::falsey($record['available'] ?? true)
            || self::falsey($record['selectable'] ?? true)
            || self::falsey($record['ok'] ?? true)) {
            $reasons[] = self::reason('unavailable', 'The artifact is marked unavailable.');
        }
        if (!empty($record['dir'])) {
            $reasons[] = self::reason('directory_not_selectable', 'A directory is not a selectable artifact.');
        }
        $status = strtolower(trim((string)(
            $record['effective_status'] ?? $record['validation_status'] ?? $record['status'] ?? ''
        )));
        $managedLifecycle = isset($record['asset_id']) || isset($record['asset_key'])
            || array_key_exists('validation_status', $record)
            || array_key_exists('effective_status', $record);
        if ($managedLifecycle && $status !== 'verified') {
            $reasons[] = self::reason(
                'status_' . ($status ?: 'unknown'),
                'Managed asset requires verified status; observed ' . ($status ?: 'no status') . '.'
            );
        } elseif ($kind === 'firmware' && !in_array($status, ['complete', 'verified'], true)) {
            $reasons[] = self::reason(
                'firmware_not_complete',
                'Firmware requires validated status complete or verified; observed ' . ($status ?: 'no status') . '.'
            );
        } elseif ($kind !== 'firmware'
            && in_array($status, ['incomplete','case_mismatch','archive','missing','unavailable','error','invalid','stale'], true)) {
            $reasons[] = self::reason('status_' . $status, "Artifact status is $status.");
        }
        if ($kind === 'background' && preg_match('/-tn\.png$/i', (string)$asset['name'])) {
            $reasons[] = self::reason('thumbnail_not_selectable', 'A Cisco thumbnail is not a full wallpaper.');
        }
        if ($asset['name'] === '') { $reasons[] = self::reason('missing_name', 'The artifact has no selectable name.'); }
        return $reasons;
    }

    /** @return array<int,string> */
    private static function profiles(array $record, string $kind): array
    {
        if (isset($record['profiles']) && is_array($record['profiles'])) {
            if (array_is_list($record['profiles'])) { return self::values($record['profiles']); }
            foreach ($record['profiles'] as $profileKind => $value) {
                if (self::normalizeKind((string)$profileKind) === $kind) { return self::values($value); }
            }
        }
        $keys = match ($kind) {
            'firmware' => ['firmware_profile','profile'],
            'background' => ['background_profile','wallpaper_profile','media_profile','profile'],
            'ringtone' => ['ringtone_profile','media_profile','profile'],
            'locale' => ['locale_profile','timezone_profile','config_profile','profile'],
            'phonebook' => ['directory_profile','phonebook_profile','profile'],
        };
        foreach ($keys as $key) {
            if (array_key_exists($key, $record)) { return self::values($record[$key]); }
        }
        return [];
    }

    /** @return array<int,string> */
    private static function values($value): array
    {
        $items = is_array($value) ? $value : [$value];
        $out = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) { continue; }
            $item = trim((string)$item);
            if ($item === '') { continue; }
            if (in_array(strtolower($item), ['*','all','any'], true)) { return []; }
            $out[strtolower($item)] = self::text($item);
        }
        return array_values($out);
    }

    /** Return only fields a browser/API caller may safely receive. */
    private static function publicAsset(array $record, string $kind): array
    {
        $name = (string)($record['load_name'] ?? $record['name'] ?? $record['filename'] ?? '');
        if ($name === '' && isset($record['path'])) { $name = basename((string)$record['path']); }
        $asset = ['kind'=>$kind, 'name'=>self::text($name)];
        foreach (['id','asset_id','site_id','size','size_bytes','mtime','scanned_at','validated_at','usage_count'] as $field) {
            if (isset($record[$field]) && is_numeric($record[$field])) { $asset[$field] = (int)$record[$field]; }
        }
        foreach ([
            'asset_key','vendor','family','series_key','model','role','status',
            'effective_status','asset_version','bundle_key','bundle_role',
            'content_sha256','content_type','provenance','signature_status',
            'catalog_revision','relative_path',
        ] as $field) {
            if (isset($record[$field]) && is_scalar($record[$field])) {
                $asset[$field] = self::text((string)$record[$field]);
            }
        }
        foreach (['manifest','dependencies','issues','usage'] as $field) {
            if (isset($record[$field]) && is_array($record[$field])) {
                $asset[$field] = self::safeArray($record[$field]);
            }
        }
        foreach (['dependency_complete','selectable','available'] as $field) {
            if (array_key_exists($field, $record)) { $asset[$field] = (bool)$record[$field]; }
        }
        $profiles = self::profiles($record, $kind);
        if (count($profiles) === 1) { $asset['profile'] = $profiles[0]; }
        elseif ($profiles) { $asset['profiles'] = $profiles; }
        $url = self::url($record['url'] ?? null);
        if ($url !== null) { $asset['url'] = $url; }
        return $asset;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private static function safeArray(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            if (!is_int($key) && preg_match('/password|passwd|secret|token|credential|authorization|cookie|private.?key|api.?key/i',(string)$key)) { continue; }
            $safeKey = is_int($key) ? $key : self::text((string)$key, 64);
            if (is_array($item)) {
                $safe[$safeKey] = self::safeArray($item);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $safe[$safeKey] = $item;
            } elseif (is_scalar($item)) {
                $safe[$safeKey] = preg_match('#^https?://#i',(string)$item) ? self::url($item) : self::text((string)$item, 1024);
            }
        }
        return $safe;
    }

    /** Remove URL credentials, fragments, and every query field except the media endpoint contract. */
    private static function url($value): ?string
    {
        if (!is_scalar($value)) { return null; }
        $parts = parse_url(trim((string)$value));
        if (!is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)
            || empty($parts['host'])
            || preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', (string)$parts['host']) !== 1) {
            return null;
        }
        $url = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
        if (isset($parts['port'])) { $url .= ':' . (int)$parts['port']; }
        $url .= (string)($parts['path'] ?? '');
        parse_str((string)($parts['query'] ?? ''), $query);
        $safeQuery = [];
        foreach (['family','model','file'] as $key) {
            if (isset($query[$key]) && is_scalar($query[$key])) { $safeQuery[$key] = self::text((string)$query[$key]); }
        }
        return self::text($url . ($safeQuery ? '?' . http_build_query($safeQuery, '', '&', PHP_QUERY_RFC3986) : ''), 1024);
    }

    private static function nullable($value): ?string
    {
        return is_scalar($value) && trim((string)$value) !== '' ? self::text((string)$value) : null;
    }

    private static function contains(array $values, string $expected): bool
    {
        foreach ($values as $value) { if (self::same($value, $expected)) { return true; } }
        return false;
    }

    private static function same(string $left, string $right): bool
    {
        return strcasecmp(trim($left), trim($right)) === 0;
    }

    private static function truthy($value): bool
    {
        return in_array($value, [true,1,'1','true','yes','on'], true);
    }

    private static function falsey($value): bool
    {
        return in_array($value, [false,0,'0','false','no','off'], true);
    }

    private static function text(string $value, int $limit = 500): string
    {
        $value = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        $value = (string)preg_replace(
            '/(^|[^A-Za-z0-9])(password|passwd|secret|token|credential|authorization|cookie|api[_-]?key)["\']?\s*[:=]\s*.*/i',
            '$1$2=[redacted]',
            $value
        );
        return substr($value, 0, $limit);
    }

    /** @return array{code:string,message:string} */
    private static function reason(string $code, string $message): array
    {
        return ['code'=>$code, 'message'=>self::text($message)];
    }
}
