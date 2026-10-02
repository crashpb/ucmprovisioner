<?php
/**
 * Model-aware firmware inventory for site TFTP roots.
 *
 * Cisco .loads files are manifests, not complete firmware by themselves. This
 * service records a selectable load only after checking its embedded filenames
 * against the loose files actually present beside it, with exact-case checks.
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/AssetFilename.php';

class FirmwareService
{
    public const DECLARATION_SUFFIX = '.ucmp-firmware.json';
    private const SCAN_MAX_DEPTH = 6;
    private const SCAN_MAX_DIRECTORIES = 128;
    private const SCAN_MAX_ENTRIES = 20000;

    private $ucm;
    private \PDO $db;

    public function __construct($ucm)
    {
        $this->ucm = $ucm;
        $this->db = $ucm->getDb();
    }

    /** @return array<int,array<string,mixed>> */
    public function inventory(int $siteId, $modelOrProfile = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT id,site_id,vendor,profile,load_name,files_json,missing_json,status,scanned_at
               FROM ucmp_firmware_bundles WHERE site_id=? ORDER BY vendor,profile,load_name'
        );
        $stmt->execute([$siteId]);
        $rows = array_map([self::class, 'decodeRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);

        $profile = null;
        if (is_array($modelOrProfile)) {
            $profile = self::modelProfile((string)($modelOrProfile['vendor'] ?? ''), (string)($modelOrProfile['model'] ?? ''));
        } elseif (is_int($modelOrProfile) || (is_string($modelOrProfile) && ctype_digit($modelOrProfile))) {
            $model = $this->model((int)$modelOrProfile);
            $profile = $model ? self::modelProfile((string)$model['vendor'], (string)$model['model']) : '__missing_model__';
        } elseif (is_string($modelOrProfile) && trim($modelOrProfile) !== '') {
            $profile = trim($modelOrProfile);
        }
        if ($profile !== null) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['profile'] === $profile));
        }
        return $rows;
    }

    /** Scan a site's real TFTP files and atomically replace its cached inventory. */
    public function rescan(int $siteId): array
    {
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $root = $root === '' ? '/' : $root;
        $files = $this->scanFiles($sftp, $root);
        $bundles = self::analyzeFiles($files, static function (string $relativePath) use ($sftp, $root) {
            return $sftp->get(($root === '/' ? '' : $root) . '/' . ltrim($relativePath, '/'));
        });
        $this->persist($siteId, $bundles);
        return ['ok'=>true, 'site_id'=>$siteId, 'count'=>count($bundles), 'bundles'=>$this->inventory($siteId)];
    }

    public function bundle(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id,site_id,vendor,profile,load_name,files_json,missing_json,status,scanned_at
               FROM ucmp_firmware_bundles WHERE id=?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? self::decodeRow($row) : null;
    }

    public function validateBundle(int $id, $model): array
    {
        $bundle = $this->bundle($id);
        if (!$bundle) {
            return ['ok'=>false, 'message'=>'Firmware bundle not found'];
        }
        if (!is_array($model)) {
            $model = $this->model((int)$model);
        }
        if (!$model) {
            return ['ok'=>false, 'message'=>'Phone model not found', 'bundle'=>$bundle];
        }
        return self::validateRecord($bundle, $model);
    }

    /** Validate site ownership as well as bundle completeness/model compatibility. */
    public function validateSelection(int $siteId, array $modelRow, int $bundleId): array
    {
        $bundle = $this->bundle($bundleId);
        if (!$bundle) {
            return ['ok'=>false, 'message'=>'Firmware bundle not found'];
        }
        if ((int)$bundle['site_id'] !== $siteId) {
            return ['ok'=>false, 'message'=>'Firmware bundle belongs to a different site', 'bundle'=>$bundle];
        }
        return self::validateRecord($bundle, $modelRow);
    }

    /**
     * Validate the exact load name stored by a template. Bundle database IDs are
     * controller-local, so federated templates intentionally persist the portable
     * load name and re-resolve it against the executing site's own inventory.
     */
    public function validateLoadSelection(int $siteId, array $modelRow, string $loadName, bool $rescanIfMissing = false): array
    {
        $loadName = trim(str_replace('\\', '/', $loadName));
        $find = function () use ($siteId, $modelRow, $loadName): ?array {
            foreach ($this->inventory($siteId, $modelRow) as $bundle) {
                if (hash_equals((string)$bundle['load_name'], $loadName)) {
                    return $bundle;
                }
            }
            return null;
        };

        $bundle = $find();
        // Template saves are infrequent and must reflect the physical TFTP tree,
        // not a controller/PBX cache that may predate an upload or deletion.
        if ($rescanIfMissing) {
            $this->rescan($siteId);
            $bundle = $find();
        }
        if (!$bundle) {
            return [
                'ok'=>false,
                'message'=>sprintf('Firmware load "%s" is not present in this site\'s validated %s inventory', $loadName, self::modelProfile((string)($modelRow['vendor'] ?? ''), (string)($modelRow['model'] ?? '')) ?? 'model'),
                'bundle'=>null,
                'model'=>$modelRow,
            ];
        }
        return self::validateRecord($bundle, $modelRow);
    }

    /** Validate the standalone load used by legacy 7914/7915/7916 modules. */
    public function validateAccessoryLoad(int $siteId, string $type, string $loadName, bool $rescan = false): array
    {
        $profile = self::accessoryProfile($type);
        if ($profile === null) {
            return ['ok'=>false, 'message'=>"$type does not use a separately selectable expansion-module load"];
        }
        $find = function () use ($siteId, $profile, $loadName): ?array {
            foreach ($this->inventory($siteId, $profile) as $bundle) {
                if (hash_equals((string)$bundle['load_name'], trim($loadName))) { return $bundle; }
            }
            return null;
        };
        if ($rescan) { $this->rescan($siteId); }
        $bundle = $find();
        if (!$bundle) {
            return ['ok'=>false, 'message'=>sprintf('Expansion-module load "%s" is not present in this site\'s validated %s inventory', $loadName, $type)];
        }
        $ok = ($bundle['status'] ?? '') === 'complete' && hash_equals($profile, (string)($bundle['profile'] ?? ''));
        return ['ok'=>$ok, 'message'=>$ok?'Expansion-module firmware file is present with the exact name':'Expansion-module firmware is incomplete or incompatible', 'bundle'=>$bundle];
    }

    public static function accessoryProfile(string $type): ?string
    {
        return ['7914'=>'cisco:kem7914', '7915'=>'cisco:kem7915', '7916'=>'cisco:kem7916'][strtoupper(trim($type))] ?? null;
    }

    public static function validateRecord(array $bundle, array $model): array
    {
        $vendor = (string)($model['vendor'] ?? '');
        $name = (string)($model['model'] ?? '');
        $errors = [];
        if (($bundle['status'] ?? '') !== 'complete') {
            $errors[] = 'Bundle status is ' . (string)($bundle['status'] ?? 'unknown');
        }
        if (!self::compatible((string)($bundle['profile'] ?? ''), $vendor, $name)) {
            $errors[] = sprintf('%s %s requires %s, not %s', $vendor, $name, self::modelProfile($vendor, $name) ?? 'no direct firmware profile', (string)($bundle['profile'] ?? 'unknown'));
        }
        return [
            'ok'=>!$errors,
            'message'=>$errors ? implode('; ', $errors) : 'Firmware bundle is complete and compatible',
            'errors'=>$errors,
            'bundle'=>$bundle,
            'model'=>$model,
        ];
    }

    public static function modelProfile(string $vendor, string $model): ?string
    {
        return ModelCatalog::firmwareProfile($vendor, $model);
    }

    public static function compatible(string $profile, string $vendor, string $model): bool
    {
        $required = self::modelProfile($vendor, $model);
        return $required !== null && hash_equals($required, $profile);
    }

    public static function declarationFilename(string $firmwareFilename): string
    {
        $firmwareFilename = basename(trim(str_replace('\\', '/', $firmwareFilename)));
        if ($firmwareFilename === '' || preg_match('/^[A-Za-z0-9_.-]+$/D', $firmwareFilename) !== 1) {
            throw new \InvalidArgumentException('Firmware declaration requires one safe filename');
        }
        return AssetFilename::content('v', $firmwareFilename, 'json');
    }

    private static function isDeclaration(string $filename): bool
    {
        return preg_match('/^v[a-f0-9]{12}\.json$/D', $filename) === 1
            || str_ends_with(strtolower($filename), self::DECLARATION_SUFFIX);
    }

    /** Build the small sidecar persisted beside a user-assigned vendor binary. */
    public static function compatibilityManifest(string $vendor, string $filename, array $models, ?int $declaredAt = null): array
    {
        $vendor = ucfirst(strtolower(trim($vendor)));
        if (!in_array($vendor, ['Yealink','Grandstream'], true)) {
            throw new \InvalidArgumentException('Compatibility declarations are for Yealink or Grandstream firmware');
        }
        $filename = basename(trim(str_replace('\\', '/', $filename)));
        $allowedExtension = $vendor === 'Yealink' ? '/\.rom$/iD' : '/\.(?:bin|fw)$/iD';
        if ($filename === '' || preg_match($allowedExtension, $filename) !== 1) {
            throw new \InvalidArgumentException(
                $vendor === 'Yealink'
                    ? 'Yealink firmware declarations require a .rom file'
                    : 'Grandstream firmware declarations require a .bin or .fw file'
            );
        }
        $cleanModels = [];
        foreach ($models as $model) {
            $model = strtoupper(trim((string)$model));
            if ($model === '' || self::modelProfile($vendor, $model) === null) {
                throw new \InvalidArgumentException("$vendor $model is not a supported direct firmware target");
            }
            $cleanModels[$model] = true;
        }
        if (!$cleanModels || count($cleanModels) > 100) {
            throw new \InvalidArgumentException('Choose between one and one hundred compatible models');
        }
        return [
            'version'=>1,
            'vendor'=>$vendor,
            'file'=>$filename,
            'models'=>array_keys($cleanModels),
            'declared_at'=>$declaredAt ?? time(),
            'compatibility'=>'user_declared',
        ];
    }

    /**
     * Classify a selectable primary file. Companion files return null.
     *
     * @return array{vendor:string,profile:string,kind:string}|null
     */
    public static function classifyFilename(string $filename): ?array
    {
        $portablePath = str_replace('\\', '/', $filename);
        $name = basename($portablePath);
        if (self::isDeclaration($name)) {
            $vendor = str_contains(strtolower($portablePath), '/yealink/') ? 'Yealink' :
                (str_contains(strtolower($portablePath), '/grandstream/') ? 'Grandstream' : 'Declared');
            return ['vendor'=>$vendor, 'profile'=>'firmware:declaration', 'kind'=>'compatibility_manifest'];
        }
        if (preg_match('/\.(?:zip|7z|rar|tgz|tar|tar\.gz|gz|cop|cop\.sgn)$/i', $name)) {
            return ['vendor'=>'Archive', 'profile'=>'inert:archive', 'kind'=>'archive'];
        }
        if (preg_match('/\.loads$/i', $name)) {
            foreach (ModelCatalog::FIRMWARE_PROFILES as $profile => $definition) {
                if (preg_match((string)$definition['pattern'], $name)) {
                    return ['vendor'=>'Cisco', 'profile'=>$profile, 'kind'=>'manifest'];
                }
            }
            if (preg_match('/^(?:sip|p0s3)/i', $name)) {
                return ['vendor'=>'Cisco', 'profile'=>'cisco:unclassified', 'kind'=>'manifest'];
            }
            return null;
        }
        if (preg_match('/^S001[A-Za-z0-9.-]*\.sbn$/i', $name)) {
            return ['vendor'=>'Cisco', 'profile'=>'cisco:kem7914', 'kind'=>'accessory'];
        }
        if (preg_match('/^B015-[A-Za-z0-9.-]+\.sbn$/i', $name)) {
            return ['vendor'=>'Cisco', 'profile'=>'cisco:kem7915', 'kind'=>'accessory'];
        }
        if (preg_match('/^B016-[A-Za-z0-9.-]+\.sbn$/i', $name)) {
            return ['vendor'=>'Cisco', 'profile'=>'cisco:kem7916', 'kind'=>'accessory'];
        }
        if (preg_match('/\.rom$/i', $name)) {
            // Some vendor packages use one generic/shared filename. A model-specific
            // directory selected by the manager is therefore also authoritative.
            $profile = self::vendorProfileInFilename('Yealink', $portablePath);
            return ['vendor'=>'Yealink', 'profile'=>$profile ?? 'yealink:unclassified', 'kind'=>'standalone'];
        }
        if (preg_match('/\.(?:bin|fw)$/i', $name)) {
            $profile = self::vendorProfileInFilename('Grandstream', $portablePath);
            if ($profile !== null) {
                return ['vendor'=>'Grandstream', 'profile'=>$profile, 'kind'=>'standalone'];
            }
            if (preg_match('/fw\.(?:bin|fw)$/i', $name)) {
                return ['vendor'=>'Grandstream', 'profile'=>'grandstream:unclassified', 'kind'=>'standalone'];
            }
        }
        return null;
    }

    /** @return string[] exact filenames found in a binary signed .loads manifest */
    public static function manifestDependencies(string $content): array
    {
        preg_match_all(
            '/[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\.(?:loads|sbn|sb2|bin|app|jar|dsp|fw|rom)/i',
            $content,
            $matches
        );
        $seen = [];
        $out = [];
        foreach ($matches[0] ?? [] as $filename) {
            $filename = basename((string)$filename);
            if (!isset($seen[$filename])) {
                $seen[$filename] = true;
                $out[] = $filename;
            }
        }
        return $out;
    }

    /** @return string[] the four loose files required by a 7940/7960 P0S3 load */
    public static function legacyP0s3Files(string $loadFilename): array
    {
        if (!preg_match('/^P0S3-(.+)\.loads$/i', basename($loadFilename), $match)) {
            return [];
        }
        $suffix = $match[1];
        return [
            'P0S3-' . $suffix . '.loads',
            'P0S3-' . $suffix . '.sb2',
            'P003-' . $suffix . '.sbn',
            'P003-' . $suffix . '.bin',
        ];
    }

    /**
     * Analyze already-listed files. Public so the parser has a dependency-free
     * fixture; production callers should normally use rescan().
     *
     * @param array<int,array{path:string,name?:string,size?:int,mtime?:int}> $entries
     * @param callable(string):(string|false) $read
     * @return array<int,array<string,mixed>>
     */
    public static function analyzeFiles(array $entries, callable $read): array
    {
        $files = [];
        $exact = [];
        $folded = [];
        foreach ($entries as $entry) {
            $path = trim(str_replace('\\', '/', (string)($entry['path'] ?? '')), '/');
            if ($path === '') { continue; }
            $name = (string)($entry['name'] ?? basename($path));
            $entry = ['path'=>$path, 'name'=>$name, 'size'=>(int)($entry['size'] ?? 0), 'mtime'=>(int)($entry['mtime'] ?? 0)];
            $files[] = $entry;
            $key = self::fileKey($path, $name, false);
            $exact[$key] = $entry;
            $folded[self::fileKey($path, $name, true)] = $entry;
        }

        $declarations = [];
        // The compact declaration supersedes an older sidecar for the same binary.
        usort($files, static fn(array $a, array $b): int =>
            (int)(bool)preg_match('/^v[a-f0-9]{12}\.json$/D', $a['name']) <=>
            (int)(bool)preg_match('/^v[a-f0-9]{12}\.json$/D', $b['name']));
        foreach ($files as $entry) {
            if (!self::isDeclaration($entry['name'])) { continue; }
            $compact = !str_ends_with(strtolower($entry['name']), self::DECLARATION_SUFFIX);
            $targetName = $compact ? '' : substr($entry['name'], 0, -strlen(self::DECLARATION_SUFFIX));
            $targetKey = self::fileKey($entry['path'], $targetName, false);
            $decoded = null;
            $content = $read($entry['path']);
            try {
                if (!is_string($content)) { throw new \RuntimeException('Compatibility declaration is unreadable'); }
                $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
                if ($compact && is_array($decoded)) {
                    $targetName = (string)($decoded['file'] ?? '');
                    $targetKey = self::fileKey($entry['path'], $targetName, false);
                    if ($targetName !== basename($targetName) || !hash_equals(self::declarationFilename($targetName), $entry['name'])) {
                        throw new \RuntimeException('Compact declaration identity does not match its firmware filename');
                    }
                }
                if (!is_array($decoded)
                    || (int)($decoded['version'] ?? 0) !== 1
                    || !hash_equals($targetName, (string)($decoded['file'] ?? ''))) {
                    throw new \RuntimeException('Compatibility declaration does not match its firmware filename');
                }
                $manifest = self::compatibilityManifest(
                    (string)($decoded['vendor'] ?? ''),
                    $targetName,
                    (array)($decoded['models'] ?? []),
                    max(0, (int)($decoded['declared_at'] ?? 0))
                );
                $declarations[$targetKey] = ['ok'=>true, 'manifest'=>$manifest, 'file'=>$entry];
            } catch (\Throwable $error) {
                $declarations[$targetKey] = [
                    'ok'=>false,
                    'message'=>$error->getMessage(),
                    'vendor'=>is_array($decoded ?? null) ? (string)($decoded['vendor'] ?? 'Declared') : 'Declared',
                    'file'=>$entry,
                ];
            }
        }

        $bundles = [];
        foreach ($files as $primary) {
            if (self::isDeclaration($primary['name'])) { continue; }
            $declarationKey = self::fileKey($primary['path'], $primary['name'], false);
            if (isset($declarations[$declarationKey])) {
                $declared = $declarations[$declarationKey];
                $sidecar = self::withRole($declared['file'], 'compatibility_manifest');
                if (empty($declared['ok'])) {
                    $bundles[] = [
                        'vendor'=>(string)($declared['vendor'] ?? 'Declared'),
                        'profile'=>strtolower((string)($declared['vendor'] ?? 'declared')) . ':unclassified',
                        'load_name'=>$primary['path'],
                        'files'=>[self::withRole($primary, 'primary'), $sidecar],
                        'missing'=>[['expected'=>'Valid user-declared compatibility metadata', 'reason'=>'invalid_declaration', 'observed'=>(string)$declared['message']]],
                        'status'=>'incomplete',
                    ];
                    continue;
                }
                $manifest = $declared['manifest'];
                $sidecar['declared_models'] = $manifest['models'];
                $sidecar['compatibility'] = 'user_declared';
                $profiles = [];
                foreach ($manifest['models'] as $model) {
                    $profile = self::modelProfile((string)$manifest['vendor'], (string)$model);
                    if ($profile !== null) { $profiles[$profile] = true; }
                }
                foreach (array_keys($profiles) as $profile) {
                    $bundles[] = [
                        'vendor'=>$manifest['vendor'], 'profile'=>$profile, 'load_name'=>$primary['path'],
                        'files'=>[self::withRole($primary, 'primary'), $sidecar],
                        'missing'=>[], 'status'=>'complete',
                    ];
                }
                continue;
            }
            $classification = self::classifyFilename($primary['path']);
            if (!$classification) { continue; }
            if ($classification['kind'] === 'archive') {
                $bundles[] = [
                    'vendor'=>'Archive', 'profile'=>'inert:archive', 'load_name'=>$primary['path'],
                    'files'=>[self::withRole($primary, 'archive')],
                    'missing'=>[['expected'=>'Loose vendor firmware files', 'reason'=>'archive_inert']],
                    'status'=>'archive',
                ];
                continue;
            }
            if ($classification['kind'] === 'standalone') {
                $unclassified = str_ends_with($classification['profile'], ':unclassified');
                $bundles[] = [
                    'vendor'=>$classification['vendor'], 'profile'=>$classification['profile'], 'load_name'=>$primary['path'],
                    'files'=>[self::withRole($primary, 'primary')],
                    'missing'=>$unclassified ? [['expected'=>'Recognized model name in firmware filename', 'reason'=>'unclassified']] : [],
                    'status'=>$unclassified ? 'incomplete' : 'complete',
                ];
                continue;
            }
            if ($classification['kind'] === 'accessory') {
                $bundles[] = [
                    'vendor'=>'Cisco', 'profile'=>$classification['profile'],
                    'load_name'=>preg_replace('/\.sbn$/i', '', $primary['path']),
                    'files'=>[self::withRole($primary, 'primary')], 'missing'=>[], 'status'=>'complete',
                ];
                continue;
            }

            $content = $read($primary['path']);
            $expected = is_string($content) ? self::manifestDependencies($content) : [];
            if ($classification['profile'] === 'cisco:p0s3') {
                $expected = array_values(array_unique(array_merge($expected, self::legacyP0s3Files($primary['name']))));
            }
            if (!in_array($primary['name'], $expected, true)) {
                array_unshift($expected, $primary['name']);
            }
            $bundleFiles = [];
            $missing = [];
            $caseMismatch = false;
            foreach ($expected as $expectedName) {
                $exactKey = self::fileKey($primary['path'], $expectedName, false);
                $foldedKey = self::fileKey($primary['path'], $expectedName, true);
                if (isset($exact[$exactKey])) {
                    $bundleFiles[] = self::withRole($exact[$exactKey], $expectedName === $primary['name'] ? 'primary' : 'dependency');
                } elseif (isset($folded[$foldedKey])) {
                    $caseMismatch = true;
                    $bundleFiles[] = self::withRole($folded[$foldedKey], 'dependency');
                    $missing[] = ['expected'=>$expectedName, 'observed'=>$folded[$foldedKey]['name'], 'reason'=>'case_mismatch'];
                } else {
                    $missing[] = ['expected'=>$expectedName, 'reason'=>'missing'];
                }
            }
            if (!is_string($content)) {
                $missing[] = ['expected'=>$primary['name'] . ' manifest contents', 'reason'=>'unreadable'];
            } elseif (count($expected) <= 1 && $classification['profile'] !== 'cisco:p0s3') {
                $missing[] = ['expected'=>'Embedded manifest dependency filenames', 'reason'=>'manifest_has_no_dependencies'];
            }
            if ($classification['profile'] === 'cisco:unclassified') {
                $missing[] = ['expected'=>'Known Cisco model firmware prefix', 'reason'=>'unclassified'];
            }
            $hasMissing = (bool)array_filter($missing, static fn(array $item): bool => $item['reason'] !== 'case_mismatch');
            $status = $hasMissing ? 'incomplete' : ($caseMismatch ? 'case_mismatch' : 'complete');
            $loadPath = preg_replace('/\.loads$/i', '', $primary['path']);
            $bundles[] = [
                'vendor'=>$classification['vendor'], 'profile'=>$classification['profile'], 'load_name'=>$loadPath,
                'files'=>self::uniqueFiles($bundleFiles), 'missing'=>$missing, 'status'=>$status,
            ];
        }
        foreach ($bundles as &$bundle) {
            if ($bundle['status'] === 'archive') { continue; }
            foreach ($bundle['files'] as $file) {
                if ((int)($file['size'] ?? 0) > 0) { continue; }
                $bundle['missing'][] = ['expected'=>$file['path'], 'reason'=>'empty_file'];
                $bundle['status'] = 'incomplete';
            }
        }
        unset($bundle);
        usort($bundles, static fn(array $a, array $b): int => strcasecmp($a['vendor'] . '|' . $a['profile'] . '|' . $a['load_name'], $b['vendor'] . '|' . $b['profile'] . '|' . $b['load_name']));
        return $bundles;
    }

    /** @return array<int,array{path:string,name:string,size:int,mtime:int}> */
    private function scanFiles($sftp, string $root): array
    {
        $queue = [['path'=>$root, 'relative'=>'', 'depth'=>0]];
        $files = [];
        $directoryCount = 0;
        $entryCount = 0;
        while ($queue) {
            $directory = array_shift($queue);
            if (++$directoryCount > self::SCAN_MAX_DIRECTORIES) {
                throw new \RuntimeException('Firmware inventory exceeds its bounded directory limit; cached inventory was preserved');
            }
            $raw = $sftp->rawlist($directory['path']);
            if ($raw === false || !is_array($raw)) {
                throw new \RuntimeException('Cannot inventory TFTP directory ' . $directory['path']);
            }
            $entryCount += count($raw);
            if ($entryCount > self::SCAN_MAX_ENTRIES) {
                throw new \RuntimeException('Firmware inventory exceeds its bounded entry limit; cached inventory was preserved');
            }
            foreach ($raw as $key => $metadata) {
                $meta = (array)$metadata;
                $name = is_string($key) ? $key : (string)($meta['filename'] ?? '');
                if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, '/\\') !== false) { continue; }
                $relative = trim($directory['relative'] . '/' . $name, '/');
                if ((int)($meta['type'] ?? 0) === 2) {
                    if (self::excludedFirmwareDirectory($name)
                        || ($directory['depth'] === 0 && !self::firmwareRootDirectory($name))) {
                        continue;
                    }
                    $depth = (int)$directory['depth'] + 1;
                    if ($depth > self::SCAN_MAX_DEPTH) {
                        throw new \RuntimeException('Firmware inventory exceeds its bounded depth; cached inventory was preserved');
                    }
                    $queue[] = [
                        'path'=>($directory['path'] === '/' ? '' : rtrim($directory['path'], '/')) . '/' . $name,
                        'relative'=>$relative,
                        'depth'=>$depth,
                    ];
                    continue;
                }
                if ((int)($meta['type'] ?? 0) === 1 && self::inventoryFilename($name)) {
                    $files[] = self::metadata($relative, $name, $meta);
                }
            }
        }
        return $files;
    }

    private function persist(int $siteId, array $bundles): void
    {
        $existingStmt = $this->db->prepare('SELECT id,vendor,profile,load_name FROM ucmp_firmware_bundles WHERE site_id=?');
        $existingStmt->execute([$siteId]);
        $existing = [];
        foreach ($existingStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $existing[self::bundleKey($row)] = (int)$row['id'];
        }
        $update = $this->db->prepare(
            'UPDATE ucmp_firmware_bundles SET vendor=?,profile=?,load_name=?,files_json=?,missing_json=?,status=?,scanned_at=? WHERE id=?'
        );
        $insert = $this->db->prepare(
            'INSERT INTO ucmp_firmware_bundles (site_id,vendor,profile,load_name,files_json,missing_json,status,scanned_at) VALUES (?,?,?,?,?,?,?,?)'
        );
        $seen = [];
        $now = time();
        $this->db->beginTransaction();
        try {
            foreach ($bundles as $bundle) {
                $key = self::bundleKey($bundle);
                $values = [
                    (string)$bundle['vendor'], (string)$bundle['profile'], (string)$bundle['load_name'],
                    self::json($bundle['files'] ?? []), self::json($bundle['missing'] ?? []), (string)$bundle['status'], $now,
                ];
                if (isset($existing[$key])) {
                    $update->execute(array_merge($values, [$existing[$key]]));
                    $seen[] = $existing[$key];
                } else {
                    $insert->execute(array_merge([$siteId], $values));
                    $seen[] = (int)$this->db->lastInsertId();
                }
            }
            if ($seen) {
                $marks = implode(',', array_fill(0, count($seen), '?'));
                $delete = $this->db->prepare("DELETE FROM ucmp_firmware_bundles WHERE site_id=? AND id NOT IN ($marks)");
                $delete->execute(array_merge([$siteId], $seen));
            } else {
                $this->db->prepare('DELETE FROM ucmp_firmware_bundles WHERE site_id=?')->execute([$siteId]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }

    private function model(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_models WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private static function decodeRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['site_id'] = (int)$row['site_id'];
        $row['scanned_at'] = (int)$row['scanned_at'];
        $row['files'] = json_decode((string)$row['files_json'], true) ?: [];
        $row['missing'] = json_decode((string)$row['missing_json'], true) ?: [];
        $row['compatible_models'] = ModelCatalog::modelsForFirmwareProfile((string)$row['profile']);
        if (!$row['compatible_models'] && preg_match('/^cisco:kem(7914|7915|7916)$/', (string)$row['profile'], $match)) {
            foreach (ModelCatalog::CATALOG as $catalogRow) {
                $capabilities = ModelCatalog::capabilitiesFor((string)$catalogRow[0], (string)$catalogRow[1]);
                if ($capabilities && in_array($match[1], (array)($capabilities['expansion_types'] ?? []), true)) {
                    $row['compatible_models'][] = ['vendor'=>(string)$catalogRow[0], 'model'=>(string)$catalogRow[1]];
                }
            }
        }
        return $row;
    }

    private static function vendorProfileInFilename(string $vendor, string $filename): ?string
    {
        $haystack = ModelCatalog::firmwareModelToken((string)preg_replace('/\.[A-Za-z0-9]+$/', '', str_replace('\\', '/', $filename)));
        $candidates = [];
        foreach (ModelCatalog::CATALOG as $row) {
            if (strcasecmp((string)$row[0], $vendor) !== 0) { continue; }
            $profile = ModelCatalog::firmwareProfile((string)$row[0], (string)$row[1]);
            if ($profile === null) { continue; }
            $token = ModelCatalog::firmwareModelToken((string)$row[1]);
            $candidates[] = ['token'=>$token, 'profile'=>$profile];
        }
        usort($candidates, static fn(array $a, array $b): int => strlen($b['token']) <=> strlen($a['token']));
        foreach ($candidates as $candidate) {
            if ($candidate['token'] !== '' && strpos($haystack, $candidate['token']) !== false) {
                return $candidate['profile'];
            }
        }
        return null;
    }

    private static function inventoryFilename(string $filename): bool
    {
        return self::isDeclaration($filename)
            || preg_match('/\.(?:loads|sbn|sb2|bin|app|jar|dsp|rom|fw|zip|7z|rar|tgz|tar|gz|cop|sgn)$/i', $filename) === 1;
    }

    private static function firmwareRootDirectory(string $name): bool
    {
        $key = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $name));
        return in_array($key, [
            'firmware', 'firmwares', 'cisco', 'ciscofirmware',
            'yealink', 'yealinkfirmware', 'grandstream', 'grandstreamfirmware',
        ], true);
    }

    private static function excludedFirmwareDirectory(string $name): bool
    {
        if (str_starts_with($name, '.')) { return true; }
        $key = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $name));
        return in_array($key, [
            'archive', 'archives', 'backup', 'backups', 'history',
            'transactions', 'wallpaper', 'wallpapers', 'background',
            'backgrounds', 'desktop', 'desktops', 'ringtone', 'ringtones',
            'locale', 'locales', 'phonebook', 'phonebooks',
        ], true);
    }

    private static function metadata(string $path, string $name, array $meta): array
    {
        return ['path'=>$path, 'name'=>$name, 'size'=>(int)($meta['size'] ?? 0), 'mtime'=>(int)($meta['mtime'] ?? 0)];
    }

    private static function fileKey(string $primaryPath, string $filename, bool $fold): string
    {
        $directory = dirname(str_replace('\\', '/', $primaryPath));
        if ($directory === '.') { $directory = ''; }
        $key = trim($directory, '/') . "\0" . $filename;
        return $fold ? strtolower($key) : $key;
    }

    private static function withRole(array $file, string $role): array
    {
        $file['role'] = $role;
        return $file;
    }

    private static function uniqueFiles(array $files): array
    {
        $seen = [];
        return array_values(array_filter($files, static function (array $file) use (&$seen): bool {
            $key = (string)$file['path'];
            if (isset($seen[$key])) { return false; }
            $seen[$key] = true;
            return true;
        }));
    }

    private static function bundleKey(array $bundle): string
    {
        return strtolower((string)($bundle['vendor'] ?? '') . "\0" . (string)($bundle['profile'] ?? '') . "\0" . (string)($bundle['load_name'] ?? ''));
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) { throw new \RuntimeException('Could not encode firmware inventory'); }
        return $json;
    }
}
