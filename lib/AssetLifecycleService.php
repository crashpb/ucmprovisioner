<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Joins immutable file observations, compatibility, dependency completeness,
 * validation state, and actual manager usage into one site-scoped catalog.
 */
final class AssetLifecycleService
{
    private \PDO $db;
    private ManagedAssetRegistry $registry;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->registry = new ManagedAssetRegistry($db);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function inventory(int $siteId, array $filters = []): array
    {
        return $this->inventoryPage($siteId,$filters)['assets'];
    }

    /** Filters precede pagination; dependencies are resolved from the whole site catalog. */
    public function inventoryPage(int $siteId, array $filters = []): array
    {
        $allowed = ['kind', 'profile', 'status', 'state', 'provenance', 'used', 'limit', 'offset'];
        $unknown = array_diff(array_keys($filters), $allowed);
        if ($unknown) {
            throw new \InvalidArgumentException('Unsupported lifecycle asset filters: ' . implode(', ', $unknown));
        }
        $limit = filter_var($filters['limit'] ?? 500, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>500]]);
        if ($limit === false) {
            throw new \InvalidArgumentException('Lifecycle asset list limit must be between 1 and 500');
        }
        $state = strtolower(trim((string)($filters['state'] ?? 'all')));
        if (!in_array($state, ['current', 'stale', 'all'], true)) {
            throw new \InvalidArgumentException('Lifecycle asset state must be current, stale, or all');
        }
        $offset=filter_var($filters['offset'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);
        if ($offset===false) { throw new \InvalidArgumentException('Lifecycle asset offset is invalid'); }
        $query=['state'=>$state];
        foreach (['kind','profile','provenance'] as $key) { if (isset($filters[$key])) { $query[$key]=$filters[$key]; } }
        if (isset($query['kind'])) { $query['kind']=AssetCatalog::normalizeKind((string)$query['kind']) ?? throw new \InvalidArgumentException('Unsupported lifecycle asset kind'); }
        $rows = $this->registry->all($siteId, $query);
        $allRows = $this->registry->all($siteId,['state'=>'all']);
        $byKey = [];
        foreach ($allRows as $row) { $byKey[(string)$row['asset_key']] = $row; }
        $profileRoles = [];
        foreach ($allRows as $row) {
            if (($row['state'] ?? '') !== 'current') { continue; }
            $profileRoles[
                strtolower((string)$row['kind'] . "\0" . (string)($row['profile'] ?? '') . "\0" . (string)($row['bundle_role'] ?? ''))
            ][] = $row;
        }
        $usage = $this->usageIndex($siteId,$allRows);
        $assets = [];
        foreach ($rows as $row) {
            $asset = $this->enrich($row, $byKey, $profileRoles, $usage);
            if (isset($filters['kind'])) {
                $requested = AssetCatalog::normalizeKind((string)$filters['kind']);
                if ($requested === null) { throw new \InvalidArgumentException('Unsupported lifecycle asset kind'); }
                if ((string)$asset['kind'] !== $requested) { continue; }
            }
            if (isset($filters['profile'])
                && strcasecmp(trim((string)$filters['profile']), (string)($asset['profile'] ?? '')) !== 0) {
                continue;
            }
            if (isset($filters['status'])
                && strtolower(trim((string)$filters['status'])) !== (string)$asset['status']) {
                continue;
            }
            if (isset($filters['provenance'])
                && strcasecmp(trim((string)$filters['provenance']), (string)$asset['provenance']) !== 0) {
                continue;
            }
            if (array_key_exists('used', $filters)) {
                $used = filter_var($filters['used'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($used === null) { throw new \InvalidArgumentException('Lifecycle used filter must be boolean'); }
                if (((int)$asset['usage_count'] > 0) !== $used) { continue; }
            }
            $assets[] = $asset;
        }
        usort($assets, static fn(array $left, array $right): int =>
            strcmp((string)$left['kind'], (string)$right['kind'])
                ?: strcasecmp((string)($left['profile'] ?? ''), (string)($right['profile'] ?? ''))
                ?: strnatcasecmp((string)$left['filename'], (string)$right['filename'])
        );
        $total=count($assets);$next=(int)$offset+(int)$limit;
        return ['assets'=>array_slice($assets,(int)$offset,(int)$limit),'total'=>$total,'offset'=>(int)$offset,
            'limit'=>(int)$limit,'truncated'=>$next<$total,'next_offset'=>$next<$total?$next:null];
    }

    /** @return array<string,mixed> */
    public function get(int $siteId, int $assetId): array
    {
        $row=$this->registry->getById($siteId,$assetId);
        if ($row===null) { throw new \InvalidArgumentException('Managed asset ID was not found on this site'); }
        $rows=$this->registry->all($siteId,['state'=>'all']);$byKey=[];$roles=[];
        foreach ($rows as $candidate) {
            $byKey[$candidate['asset_key']]=$candidate;
            if ($candidate['state']==='current') { $roles[strtolower($candidate['kind']."\0".($candidate['profile']??'')."\0".($candidate['bundle_role']??''))][]=$candidate; }
        }
        return $this->enrich($row,$byKey,$roles,$this->usageIndex($siteId,$rows));
    }

    /**
     * Return compatible verified choices and preserve incompatible/stale rows
     * with explicit reasons for an existing selection.
     *
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    public function selections(int $siteId, array $target, ?int $selectedAssetId = null): array
    {
        $assets = [];
        $offset = 0;
        do {
            $page = $this->inventoryPage($siteId, ['offset'=>$offset]);
            array_push($assets, ...$page['assets']);
            $offset = $page['next_offset'];
        } while ($offset !== null);
        $result = AssetCatalog::select($assets, $target);
        $result['selected_asset_id'] = $selectedAssetId;
        $result['selected_validation'] = null;
        if ($selectedAssetId !== null) {
            try {
                $result['selected_validation'] = $this->validateSelection($siteId, $selectedAssetId, $target);
            } catch (\InvalidArgumentException $error) {
                $result['selected_validation'] = ['ok'=>false, 'message'=>$error->getMessage()];
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    public function validateSelection(int $siteId, int $assetId, array $target): array
    {
        $asset = $this->get($siteId, $assetId);
        $selection = AssetCatalog::select([$asset], $target);
        $errors = [];
        if ($asset['status'] !== 'verified') {
            $errors[] = 'Asset status is ' . $asset['status'] . ', not verified';
        }
        if (($selection['selected']['asset_id'] ?? 0) !== $assetId) {
            foreach ((array)($selection['incompatible'][0]['reasons'] ?? []) as $reason) {
                $errors[] = (string)($reason['message'] ?? 'Asset is incompatible');
            }
            if (!$errors) { $errors[] = 'Asset is not compatible with the requested profile'; }
        }
        $timezone = null;
        if (isset($target['timezone_binding']) && is_array($target['timezone_binding'])) {
            $timezone = $this->validateTimezoneBinding($siteId, $target['timezone_binding']);
            if (!$timezone['ok']) { $errors[] = (string)$timezone['message']; }
        }
        return [
            'ok'=>!$errors,
            'message'=>$errors ? implode('; ', array_values(array_unique($errors))) : 'Asset is verified and compatible',
            'errors'=>array_values(array_unique($errors)),
            'asset'=>$asset,
            'timezone_binding'=>$timezone,
        ];
    }

    /**
     * A Cisco timezone selection is one immutable binding, not four loosely
     * related text fields.
     *
     * @param array<string,mixed> $binding
     * @return array<string,mixed>
     */
    public function validateTimezoneBinding(int $siteId, array $binding): array
    {
        $required = ['olson_zone', 'timezone_profile', 'payload_asset_id', 'tzdata_version', 'sha256'];
        $missing = array_values(array_filter($required, static fn(string $field): bool =>
            !isset($binding[$field]) || trim((string)$binding[$field]) === ''
        ));
        if ($missing) {
            return ['ok'=>false, 'message'=>'Timezone binding is missing: ' . implode(', ', $missing)];
        }
        $assetId = filter_var($binding['payload_asset_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($assetId === false) {
            return ['ok'=>false, 'message'=>'Timezone payload asset ID is invalid'];
        }
        try {
            $asset = $this->get($siteId, (int)$assetId);
        } catch (\InvalidArgumentException $error) {
            return ['ok'=>false, 'message'=>$error->getMessage()];
        }
        $errors = [];
        if ($asset['kind'] !== 'locale') { $errors[] = 'Timezone payload asset has the wrong kind'; }
        if ($asset['status'] !== 'verified') { $errors[] = 'Timezone payload is not verified'; }
        if (!hash_equals(strtolower((string)$asset['content_sha256']), strtolower(trim((string)$binding['sha256'])))) {
            $errors[] = 'Timezone payload hash changed';
        }
        if (strcasecmp((string)($asset['profile'] ?? ''), trim((string)$binding['timezone_profile'])) !== 0) {
            $errors[] = 'Timezone compatibility profile changed';
        }
        if (strcasecmp((string)($asset['asset_version'] ?? ''), trim((string)$binding['tzdata_version'])) !== 0) {
            $errors[] = 'Timezone data version does not match the payload';
        }
        if (preg_match('#^(?:UTC|GMT|[A-Za-z_]+(?:/[A-Za-z0-9_+\-]+)+)$#D', trim((string)$binding['olson_zone'])) !== 1) {
            $errors[] = 'Olson timezone identifier is invalid';
        }
        return [
            'ok'=>!$errors,
            'message'=>$errors ? implode('; ', $errors) : 'Timezone payload binding is verified',
            'errors'=>$errors,
            'asset'=>$asset,
        ];
    }

    /**
     * Attach exact hashed FirmwareService manifests to their observed primary
     * files. Complete filenames without hashes remain incomplete.
     *
     * @param array<int,array<string,mixed>> $bundles
     * @return array<string,mixed>
     */
    public function reconcileFirmware(int $siteId, array $bundles): array
    {
        $observed = $this->registry->all($siteId, ['kind'=>'firmware', 'state'=>'all']);
        $byPath = [];
        foreach ($observed as $row) {
            if (($row['state'] ?? '') !== 'current') { continue; }
            $path = (string)($row['relative_path'] ?: $row['filename']);
            $byPath[$path] = $row;
        }
        $updated = 0;
        $issues = [];
        foreach ($bundles as $bundle) {
            if (!is_array($bundle)) { continue; }
            $manifest = [];
            $primary = null;
            foreach ((array)($bundle['files'] ?? []) as $file) {
                if (!is_array($file)) { continue; }
                $path = trim(str_replace('\\', '/', (string)($file['path'] ?? $file['name'] ?? '')), '/');
                if ($path === '') { continue; }
                $observation = $byPath[$path] ?? null;
                $entry = [
                    'role'=>(string)($file['role'] ?? 'dependency'),
                    'relative_path'=>$path,
                    'filename'=>basename($path),
                    'required'=>true,
                    'status'=>$observation ? 'present' : 'missing',
                ];
                if ($observation) {
                    $entry += [
                        'size_bytes'=>(int)$observation['size_bytes'],
                        'content_sha256'=>(string)$observation['content_sha256'],
                        'content_type'=>$observation['content_type'],
                        'asset_key'=>(string)$observation['asset_key'],
                    ];
                    if (($file['role'] ?? '') === 'primary') { $primary = $observation; }
                }
                $manifest[] = array_filter($entry, static fn($value): bool => $value !== null && $value !== '');
            }
            if ($primary === null) {
                $issues[] = (string)($bundle['load_name'] ?? 'Unknown firmware') . ': primary file has not been hash-observed';
                continue;
            }
            $missing = array_filter($manifest, static fn(array $file): bool => ($file['status'] ?? '') !== 'present');
            $complete = ($bundle['status'] ?? '') === 'complete' && !$missing && $manifest;
            $metadata = [
                'format'=>'firmware-manifest',
                'magic'=>(string)($primary['metadata']['magic'] ?? $primary['content_type'] ?? 'binary'),
            ];
            // A firmware package is one managed bundle, not just its entry
            // point. Apply the exact manifest to every observed member so its
            // companions are not mislabeled "Observed only".
            foreach ($manifest as $manifestFile) {
                $assetKey = (string)($manifestFile['asset_key'] ?? '');
                if ($assetKey === '') { continue; }
                $member = null;
                foreach ($byPath as $candidate) {
                    if (hash_equals($assetKey, (string)($candidate['asset_key'] ?? ''))) {
                        $member = $candidate;
                        break;
                    }
                }
                if (!is_array($member)) { continue; }
                $this->registry->recordValidation(
                    $siteId,
                    (int)$member['asset_id'],
                    $complete ? 'verified' : 'incomplete',
                    $manifest,
                    $metadata,
                    self::versionFromName((string)($bundle['load_name'] ?? $primary['filename'])),
                    'unverified'
                );
                $updated++;
            }
            if (!$complete) {
                $issues[] = (string)($bundle['load_name'] ?? $primary['filename']) . ': package files are incomplete or unhashed';
            }
        }
        return ['ok'=>true, 'updated'=>$updated, 'issues'=>$issues];
    }

    /**
     * Reconcile each observed file with its exact timezone-profile inspection.
     * A payload can be phone-ready even when its embedded IANA release is not
     * parseable. In that case a supplied tzupdater.ver is recorded as declared
     * evidence, while a generated content stamp remains fingerprint evidence.
     * Profiles are reconciled independently; metadata files never make one
     * runtime payload depend on the presence of the other architectures.
     *
     * @param array<string,mixed> $inspection CiscoTimezoneService::inspect()
     * @return array<string,mixed>
     */
    public function reconcileTimezone(int $siteId, array $inspection): array
    {
        if (empty($inspection['ok'])) {
            return ['ok'=>false, 'updated'=>0, 'issues'=>['Cisco timezone inspection did not complete']];
        }
        $observed = $this->registry->all($siteId, ['kind'=>'locale', 'state'=>'current']);
        $byPath = [];
        foreach ($observed as $row) {
            $path = (string)($row['relative_path'] ?: $row['filename']);
            $byPath[$path] = $row;
        }

        $files = [];
        $issues = [];
        foreach ((array)($inspection['files'] ?? []) as $file) {
            if (!is_array($file)) { continue; }
            $expected = (string)($file['name'] ?? '');
            if (!array_key_exists($expected, CiscoTimezoneService::FILES)) { continue; }
            $actual = (string)($file['actual_name'] ?? $expected);
            $observation = $byPath[$actual] ?? null;
            $matches = is_array($observation)
                && !empty($file['present'])
                && !empty($file['readable'])
                && isset($file['size'], $file['sha256'])
                && (int)$observation['size_bytes'] === (int)$file['size']
                && hash_equals((string)$observation['content_sha256'], strtolower((string)$file['sha256']));
            $files[$expected] = [
                'inspection'=>$file,
                'observation'=>$observation,
                'present'=>$matches,
            ];
            if (is_array($observation) && !$matches) {
                $issues[] = "$expected changed between the file scan and timezone bundle validation";
            }
        }

        $completeProfiles = [];
        $profilesByPayload = [];
        foreach ((array)($inspection['compatibility'] ?? []) as $profile => $compatibility) {
            if (!is_array($compatibility)) { continue; }
            if (($compatibility['status'] ?? '') === 'complete') { $completeProfiles[] = (string)$profile; }
            $payload = (string)($compatibility['payload'] ?? '');
            if ($payload !== '') { $profilesByPayload[$payload][] = $compatibility + ['profile'=>(string)$profile]; }
        }
        $servedVersion = trim((string)($inspection['served_version'] ?? $inspection['derived_version'] ?? ''));
        $evidenceByFile = [];
        foreach ((array)($inspection['version_evidence'] ?? []) as $evidence) {
            if (!is_array($evidence) || empty($evidence['file']) || empty($evidence['version'])) { continue; }
            $evidenceByFile[(string)$evidence['file']][] = strtolower((string)$evidence['version']);
        }
        $updated = 0;
        foreach ($files as $expected => $fileState) {
            $observation = $fileState['observation'];
            if (!is_array($observation)) { continue; }
            $profileStates = (array)($profilesByPayload[$expected] ?? []);
            $requiredNames = [$expected];
            foreach ($profileStates as $profileState) {
                $requiredNames = array_merge($requiredNames, (array)($profileState['required_files'] ?? []));
            }
            $manifest = [];
            $complete = true;
            foreach (array_values(array_unique($requiredNames)) as $requiredName) {
                $requiredName = (string)$requiredName;
                $requiredState = $files[$requiredName] ?? null;
                $requiredObservation = is_array($requiredState) ? ($requiredState['observation'] ?? null) : null;
                $present = is_array($requiredState) && !empty($requiredState['present']);
                $entry = [
                    'role'=>(string)(CiscoTimezoneService::FILES[$requiredName] ?? 'payload'),
                    'relative_path'=>$requiredName,
                    'filename'=>$requiredName,
                    'required'=>true,
                    'status'=>$present ? 'present' : 'missing',
                ];
                if (is_array($requiredObservation) && $present) {
                    $entry += [
                        'size_bytes'=>(int)$requiredObservation['size_bytes'],
                        'content_sha256'=>(string)$requiredObservation['content_sha256'],
                        'content_type'=>$requiredObservation['content_type'],
                        'parsed_version'=>null,
                        'asset_key'=>(string)$requiredObservation['asset_key'],
                    ];
                }
                $manifest[] = array_filter($entry, static fn($value): bool => $value !== null && $value !== '');
                $complete = $complete && $present;
            }
            $actualVersions = [];
            foreach ($profileStates as $profileState) {
                if (!empty($profileState['actual_version'])) { $actualVersions[] = strtolower((string)$profileState['actual_version']); }
            }
            foreach ((array)($evidenceByFile[$expected] ?? []) as $actualVersion) { $actualVersions[] = $actualVersion; }
            $actualVersions = array_values(array_unique(array_filter($actualVersions)));
            $assetVersion = count($actualVersions) === 1
                ? $actualVersions[0]
                : ($expected === 'tzupdater.ver' ? $servedVersion : null);
            $configurationUsable = (bool)array_filter($profileStates, static fn(array $state): bool => !empty($state['configuration_usable']));
            $versionEvidence = $assetVersion !== null
                ? ($expected === 'tzupdater.ver' ? 'declared_by_tzupdater.ver' : 'embedded')
                : '';
            if ($assetVersion === null && $configurationUsable && $servedVersion !== '') {
                $assetVersion = $servedVersion;
                $versionEvidence = (string)($inspection['version_source'] ?? '') === 'content fingerprint'
                    ? 'content_fingerprint'
                    : 'declared_by_tzupdater.ver';
            }
            if (empty($fileState['present'])) {
                $validation = 'incomplete';
            } elseif ($expected === 'tzupdater.ver') {
                $validation = $servedVersion !== '' ? 'verified' : 'invalid';
            } elseif ($expected === 'tzdb.dat') {
                $validation = $assetVersion !== null ? 'verified' : 'discovered';
            } elseif (array_filter($profileStates, static fn(array $state): bool => ($state['status'] ?? '') === 'mismatch')) {
                $validation = 'invalid';
            } elseif (array_filter($profileStates, static fn(array $state): bool =>
                in_array((string)($state['status'] ?? ''), ['complete','legacy_frozen'], true)
                && !empty($state['configuration_usable'])
            )) {
                $validation = $complete ? 'verified' : 'incomplete';
            } elseif ($configurationUsable) {
                $validation = $complete ? 'verified' : 'incomplete';
            } else {
                $validation = 'incomplete';
            }
            foreach ($manifest as &$manifestFile) {
                if (($manifestFile['relative_path'] ?? '') === $expected && $assetVersion !== null) {
                    $manifestFile['parsed_version'] = $assetVersion;
                }
                if (($manifestFile['parsed_version'] ?? null) === null) { unset($manifestFile['parsed_version']); }
            }
            unset($manifestFile);
            $profileNames = array_values(array_unique(array_filter(array_map(
                static fn(array $state): string => (string)($state['profile'] ?? ''),
                $profileStates
            ))));
            $metadata = [
                'format'=>'timezone-bundle',
                'magic'=>'Cisco timezone payload',
                'timezone_profile'=>$profileNames ? implode(',', $profileNames) : 'cisco-timezone-metadata',
            ];
            if ($servedVersion !== '') { $metadata['declared_tzdata_version'] = $servedVersion; }
            if ($versionEvidence !== '') { $metadata['version_evidence'] = $versionEvidence; }
            $this->registry->recordValidation(
                $siteId,
                (int)$observation['asset_id'],
                $validation,
                $manifest,
                $metadata,
                $assetVersion,
                'unverified'
            );
            $updated++;
        }
        foreach ((array)($inspection['issues'] ?? []) as $issue) {
            if (is_array($issue) && isset($issue['message'])) { $issues[] = (string)$issue['message']; }
        }
        return [
            'ok'=>true,
            'updated'=>$updated,
            'verified_profiles'=>$completeProfiles,
            'issues'=>array_values(array_unique($issues)),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $byKey
     * @param array<string,array<int,array<string,mixed>>> $profileRoles
     * @param array<string,array<int,array<string,mixed>>> $usage
     * @return array<string,mixed>
     */
    private function enrich(array $row, array $byKey, array $profileRoles, array $usage): array
    {
        $manifest = (array)($row['manifest'] ?? []);
        if (!$manifest) {
            $manifest[] = array_filter([
                'role'=>(string)($row['bundle_role'] ?: 'file'),
                'relative_path'=>(string)($row['relative_path'] ?: $row['filename']),
                'filename'=>(string)$row['filename'],
                'size_bytes'=>(int)$row['size_bytes'],
                'content_sha256'=>(string)$row['content_sha256'],
                'content_type'=>$row['content_type'],
                'parsed_version'=>$row['asset_version'],
                'asset_key'=>(string)$row['asset_key'],
                'required'=>true,
                'status'=>($row['state'] ?? '') === 'current' ? 'present' : 'stale',
            ], static fn($value): bool => $value !== null && $value !== '');
        }
        $dependencies = [];
        $issues = [];
        foreach ((array)($row['companions'] ?? []) as $companion) {
            $target = $byKey[(string)($companion['asset_key'] ?? '')] ?? null;
            $required = !empty($companion['required']);
            $present = $target !== null && ($target['state'] ?? '') === 'current';
            $dependency = $companion + [
                'present'=>$present,
                'status'=>$target === null ? 'missing' : (string)$target['state'],
            ];
            if ($target !== null) {
                $dependency['asset_id'] = (int)$target['asset_id'];
                $dependency['filename'] = (string)$target['filename'];
                $dependency['content_sha256'] = (string)$target['content_sha256'];
            }
            $dependencies[] = $dependency;
            if ($required && !$present) {
                $issues[] = [
                    'code'=>'required_companion_missing',
                    'message'=>'Required ' . (string)($companion['relation'] ?? 'companion') . ' is missing or stale.',
                ];
            }
        }
        $kind = (string)$row['kind'];
        $profile = (string)($row['profile'] ?? '');
        $role = (string)($row['bundle_role'] ?? '');
        if ($kind === 'background' && $role === 'full') {
            $catalogKey = strtolower("background\0$profile\0catalog");
            if (empty($profileRoles[$catalogKey])) {
                $issues[] = ['code'=>'wallpaper_catalog_missing', 'message'=>'Cisco wallpaper List.xml is missing for this profile.'];
            }
        }
        if ($kind === 'background'
            && preg_match('/^cisco:[^:]+:(\d+)x(\d+)x(\d+)x(\d+)$/', $profile, $dimensions)
            && in_array($role, ['full','thumbnail'], true)) {
            $expectedWidth = (int)$dimensions[$role === 'full' ? 1 : 3];
            $expectedHeight = (int)$dimensions[$role === 'full' ? 2 : 4];
            if ((int)($row['metadata']['width'] ?? 0) !== $expectedWidth
                || (int)($row['metadata']['height'] ?? 0) !== $expectedHeight) {
                $issues[] = [
                    'code'=>'media_dimensions_mismatch',
                    'message'=>"Decoded image dimensions do not match required {$expectedWidth}x{$expectedHeight}.",
                ];
            }
        }
        if ($kind === 'ringtone' && $role === 'tone') {
            $catalogKey = strtolower("ringtone\0$profile\0catalog");
            $catalogs = array_filter(
                $profileRoles[$catalogKey] ?? [],
                static fn(array $catalog): bool => (string)($catalog['bundle_key'] ?? '') === (string)($row['bundle_key'] ?? '')
            );
            if (!$catalogs) {
                $issues[] = ['code'=>'ringtone_catalog_missing', 'message'=>'The matching ringtone catalog is missing.'];
            }
            $wideband = (string)($row['bundle_key'] ?? '') === 'cisco-ringtones-wideband';
            $expectedRate = $wideband ? 16000 : 8000;
            $expectedCodec = $wideband ? 'pcm-s16' : 'mu-law';
            if ((int)($row['metadata']['sample_rate'] ?? 0) !== $expectedRate
                || (int)($row['metadata']['channel_count'] ?? 0) !== 1
                || (string)($row['metadata']['audio_codec'] ?? '') !== $expectedCodec) {
                $issues[] = [
                    'code'=>'ringtone_format_mismatch',
                    'message'=>"Decoded ringtone must be $expectedCodec, {$expectedRate} Hz, mono for this catalog.",
                ];
            }
        }
        if ($kind === 'locale'
            && ($row['validation_status'] ?? '') === 'verified'
            && ($row['metadata']['format'] ?? '') === 'timezone-bundle'
            && ($row['asset_version'] ?? '') === '') {
            $issues[] = ['code'=>'timezone_version_missing', 'message'=>'Timezone payload has no parsed or declared tzdata version.'];
        }
        foreach ($manifest as $file) {
            $dependencyKey=(string)($file['asset_key']??'');
            if (!empty($file['required']) && $dependencyKey!=='' && (!isset($byKey[$dependencyKey]) || $byKey[$dependencyKey]['state']!=='current'
                || !hash_equals((string)($file['content_sha256']??''),(string)$byKey[$dependencyKey]['content_sha256']))) {
                $issues[]=['code'=>'manifest_reference_missing','message'=>'A required manifest asset reference is missing, stale or changed.'];
            }
            if (!empty($file['required']) && (
                ($file['status'] ?? 'present') !== 'present'
                || empty($file['content_sha256'])
                || !array_key_exists('size_bytes', $file)
            )) {
                $issues[] = [
                    'code'=>'manifest_file_incomplete',
                    'message'=>'Required manifest role ' . (string)($file['role'] ?? 'file') . ' is missing size or SHA-256.',
                ];
            }
        }
        if (($row['catalog_revision'] ?? '') !== ManagedAssetRegistry::CATALOG_REVISION
            && ($row['validation_status'] ?? '') === 'verified') {
            $issues[] = ['code'=>'catalog_revision_outdated', 'message'=>'Asset must be revalidated against the current catalog revision.'];
        }

        $status = (string)($row['validation_status'] ?? 'discovered');
        if (($row['state'] ?? '') === 'stale') { $status = 'stale'; }
        elseif ($status === 'invalid') { $status = 'invalid'; }
        elseif ($issues || $status === 'incomplete') { $status = 'incomplete'; }
        elseif ($status !== 'verified') { $status = 'discovered'; }
        $assetUsage = $usage[(string)$row['asset_key']] ?? [];

        return array_merge($row, [
            'name'=>(string)$row['filename'],
            'status'=>$status,
            'effective_status'=>$status,
            'manifest'=>$manifest,
            'dependencies'=>$dependencies,
            'dependency_complete'=>!$issues,
            'issues'=>$issues,
            'usage'=>$assetUsage,
            'usage_count'=>count($assetUsage),
            'selectable'=>$status === 'verified'
                && !in_array($role, ['thumbnail','catalog','dependency'], true)
                && ($kind !== 'locale' || str_ends_with($role, 'payload')),
            'available'=>($row['state'] ?? '') === 'current',
        ]);
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public function usageIndex(int $siteId, ?array $assets = null): array
    {
        $assets ??= $this->registry->all($siteId, ['state'=>'all']);
        $index = [];
        foreach ($assets as $asset) { $index[(string)$asset['asset_key']] = []; }
        // The shared collector reads settings, templates, phones and jobs and
        // retains the same source/matching rules used by retirement protection.
        require_once __DIR__ . '/AssetReferenceIndex.php';
        $sources = (new AssetReferenceIndex($this->db))->snapshot($siteId)['sources'];
        foreach ($assets as $asset) {
            foreach ($sources as $source) {
                if (AssetReferenceIndex::matches($source, $asset)) {
                    $index[(string)$asset['asset_key']][] = AssetReferenceIndex::summary($source);
                }
            }
        }
        // Usage belongs to a managed bundle as well as the directly named
        // file. This makes dependencies and generated List.xml companions
        // visibly used when their primary/full asset is selected.
        $bundleUsage = [];
        foreach ($assets as $asset) {
            $bundle = trim((string)($asset['bundle_key'] ?? ''));
            if ($bundle === '') { continue; }
            foreach ((array)($index[(string)$asset['asset_key']] ?? []) as $usage) {
                $bundleUsage[$bundle][] = $usage;
            }
        }
        foreach ($assets as $asset) {
            $assetKey = (string)$asset['asset_key'];
            $bundle = trim((string)($asset['bundle_key'] ?? ''));
            if ($bundle !== '' && !empty($bundleUsage[$bundle])) {
                $index[$assetKey] = array_merge($index[$assetKey], $bundleUsage[$bundle]);
            }
            if (($asset['kind'] ?? '') === 'background' && ($asset['bundle_role'] ?? '') === 'catalog') {
                $profile = (string)($asset['profile'] ?? '');
                foreach ($assets as $peer) {
                    if (($peer['kind'] ?? '') !== 'background'
                        || !in_array((string)($peer['bundle_role'] ?? ''), ['full','thumbnail'], true)
                        || !hash_equals($profile, (string)($peer['profile'] ?? ''))) { continue; }
                    $index[$assetKey] = array_merge($index[$assetKey], (array)($index[(string)$peer['asset_key']] ?? []));
                }
            }
            if (($asset['kind'] ?? '') === 'phonebook'
                && $bundle === 'phonebook-cache'
                && ($asset['state'] ?? '') === 'current') {
                $index[$assetKey][] = [
                    'type'=>'directory_endpoint',
                    'id'=>$siteId,
                    'label'=>'Published phone directory',
                ];
            }
            $deduped = [];
            foreach ((array)$index[$assetKey] as $usage) {
                $usageKey = (string)($usage['type'] ?? '') . ':' . (string)($usage['id'] ?? '') . ':' . (string)($usage['label'] ?? '');
                $deduped[$usageKey] = $usage;
            }
            $index[$assetKey] = array_values($deduped);
        }
        return $index;
    }

    private function tableExists(string $table): bool
    {
        if ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $statement->execute([$table]);
            return (bool)$statement->fetchColumn();
        }
        $statement = $this->db->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        );
        $statement->execute([$table]);
        return (bool)$statement->fetchColumn();
    }

    /** @param mixed $value */
    private static function references($value, array $asset, string $key = ''): bool
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== $value) {
                return self::references($decoded, $asset, $key);
            }
            if (hash_equals((string)$asset['asset_key'], trim($value))) { return true; }
            $timezoneAssetKey = strtolower(trim((string)($asset['metadata']['timezone_asset_key'] ?? '')));
            if ($timezoneAssetKey !== '' && hash_equals($timezoneAssetKey, strtolower(trim($value)))) { return true; }
            if (preg_match('/(?:asset|payload)_id$/i', $key)
                && ctype_digit(trim($value))
                && (int)$value === (int)$asset['asset_id']) {
                return true;
            }
            return preg_match('/(?:firmware|load|wallpaper|background|ringtone|tzdata|timezone|locale|phonebook|file)/i', $key) === 1
                && hash_equals((string)$asset['filename'], basename(str_replace('\\', '/', trim($value))));
        }
        if (is_int($value) && preg_match('/(?:asset|payload)_id$/i', $key)) {
            return $value === (int)$asset['asset_id'];
        }
        if (!is_array($value)) { return false; }
        foreach ($value as $childKey => $child) {
            $nextKey = $key === '' ? (string)$childKey : $key . '.' . (string)$childKey;
            if (self::references($child, $asset, $nextKey)) { return true; }
        }
        return false;
    }

    private static function versionFromName(string $name): ?string
    {
        $stem = (string)preg_replace(
            '/\.(?:loads|sbn|sb2|bin|app|jar|dsp|rom|fw)$/i',
            '',
            basename($name)
        );
        if (preg_match('/(?:^|[-_.])(\d+(?:[-_.]\d+){1,6}(?:[-_.][A-Za-z0-9]+)*)/D', $stem, $match)) {
            return trim(str_replace('_', '.', (string)$match[1]), '.-');
        }
        return null;
    }
}
