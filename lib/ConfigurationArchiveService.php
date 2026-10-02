<?php

namespace FreePBX\modules\Ucmprovisioner;

use PDO;

/**
 * Portable controller snapshot for module-owned configuration and assets.
 *
 * This deliberately complements (rather than duplicates) federation: an
 * archive restores one controller's stored state, while federation translates
 * selected policy between independently-owned sites.
 */
final class ConfigurationArchiveService
{
    private const SCHEMA = 'ucmprovisioner-configuration-package-v2';
    private const MAX_FILES = 20000;
    private const MAX_TOTAL_BYTES = 8589934592; // 8 GiB, including firmware.
    private const MAX_DATABASE_BYTES = 67108864;

    /** @var array<string,string[]> */
    private const TABLES = [
        'sites' => ['ucmp_sites'],
        'phones' => ['ucmp_phones', 'ucmp_phone_mac_suppressions', 'ucmp_phone_builds'],
        'templates' => ['ucmp_templates', 'ucmp_default_template_rules'],
        'groups' => ['ucmp_groups', 'ucmp_extensions', 'ucmp_identity_overrides'],
        'directory' => ['ucmp_directory_sources', 'ucmp_directory_entries', 'ucmp_directory_conflicts', 'ucmp_phonebooks'],
        'patches' => [],
        'media' => ['ucmp_media_replication_jobs'],
        'firmware' => ['ucmp_firmware_bundles', 'ucmp_timezone_bundles', 'ucmp_firmware_replication_jobs'],
        'services' => ['ucmp_phone_services'],
        'fail2ban' => [],
        'tftp' => [],
        'settings' => ['ucmp_settings', 'ucmp_configuration_sync_jobs', 'ucmp_configuration_sync_items'],
    ];

    /** @var array<string,string> */
    private const LABELS = [
        'sites' => 'Sites',
        'phones' => 'Phones',
        'templates' => 'Templates',
        'groups' => 'Groups',
        'directory' => 'Directory & AD',
        'patches' => 'Patch Manager',
        'media' => 'Media',
        'firmware' => 'Firmware',
        'services' => 'Phone Services',
        'fail2ban' => 'Fail2ban monitor',
        'tftp' => 'Unclassified TFTP Browser files',
        'settings' => 'Settings and sync jobs',
    ];

    private PDO $db;
    private object $ucm;
    private string $privateRoot;
    private bool $recoveryMode = false;

    public function __construct(PDO $db, object $ucm, string $privateRoot = '/var/lib/asterisk/ucmprovisioner')
    {
        $this->db = $db;
        $this->ucm = $ucm;
        $this->privateRoot = rtrim($privateRoot, '/\\');
    }

    /** @return array<string,string> */
    public static function scopeLabels(): array
    {
        return self::LABELS;
    }

    /** @return string[] */
    public static function normalizeScopes(array $scopes): array
    {
        $requested = array_fill_keys(array_map(static fn($value): string => strtolower(trim((string)$value)), $scopes), true);
        return array_values(array_filter(array_keys(self::TABLES), static fn(string $scope): bool => isset($requested[$scope])));
    }

    /** @return array{path:string,filename:string,manifest:array<string,mixed>} */
    public function export(array $requestedScopes, string $moduleVersion, string $mode = 'configuration', bool $includeTftp = true): array
    {
        return $this->withArchiveLocks(self::normalizeScopes($requestedScopes), $includeTftp,
            fn(): array => $this->exportLocked($requestedScopes, $moduleVersion, $mode, $includeTftp));
    }

    private function exportLocked(array $requestedScopes, string $moduleVersion, string $mode, bool $includeTftp): array
    {
        $this->setMode($mode);
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive is required to export a configuration package');
        }
        $scopes = self::normalizeScopes($requestedScopes);
        if (!$scopes) { throw new \InvalidArgumentException('Select at least one module tab to export'); }
        @set_time_limit(0);
        $snapshot = $this->snapshotTables($scopes);

        $workRoot = $this->privateRoot . '/configuration-packages';
        if (!is_dir($workRoot) && !@mkdir($workRoot, 0770, true) && !is_dir($workRoot)) {
            throw new \RuntimeException('Could not create the private configuration-package directory');
        }
        $token = bin2hex(random_bytes(8));
        $filename = 'ucmprovisioner-config-' . date('Ymd-His') . '.zip';
        $path = $workRoot . '/export-' . $token . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the configuration package');
        }

        $temporaryFiles = [];
        $files = [];
        $counters = ['files'=>0, 'bytes'=>0];
        $databaseMembers = [];
        try {
            foreach ($scopes as $scope) {
                $database = ['tables'=>[]];
                foreach (self::TABLES[$scope] as $table) {
                    $database['tables'][$table] = $this->recoveryMode ? $snapshot[$table]
                        : array_values(array_filter(array_map(fn(array $row): array=>$this->projectRow($table,$row),$snapshot[$table])));
                }
                $assetRows = array_values(array_filter(
                    $snapshot['ucmp_assets'],
                    fn(array $row): bool => $this->assetScope($row) === $scope
                ));
                if ($assetRows) { $database['tables']['ucmp_assets'] = $this->recoveryMode ? $assetRows : array_map(fn(array $row): array=>$this->projectRow('ucmp_assets',$row),$assetRows); }
                $json = json_encode($database, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (strlen($json) > self::MAX_DATABASE_BYTES) {
                    throw new \RuntimeException("The $scope database payload exceeds the safe package limit");
                }
                $member = 'database/' . $scope . '.json';
                if (!$zip->addFromString($member, $json)) { throw new \RuntimeException('Could not add database payload'); }
                $databaseMembers[$member] = ['sha256'=>hash('sha256',$json),'size'=>strlen($json)];
            }

            foreach ($scopes as $scope) {
                foreach ($this->privateRoots($scope) as $relativeRoot) {
                    $absolute = $this->privateRoot . '/' . $relativeRoot;
                    if (is_dir($absolute)) {
                        $this->addLocalTree($zip, $absolute, $relativeRoot, $scope, $files, $counters, $temporaryFiles);
                    } elseif (is_file($absolute) && !is_link($absolute)) {
                        $this->addFile($zip,$absolute,'private',$scope,$relativeRoot,$files,$counters,$temporaryFiles);
                    }
                }
            }

            if ($includeTftp && array_intersect($scopes, ['phones','directory','media','firmware','tftp'])) {
                $this->addTftpFiles($zip, $scopes, $files, $counters, $temporaryFiles, $snapshot['ucmp_assets']);
            }

            $localSiteId = (int)$this->ucm->localSiteId();
            $site = (array)($this->ucm->getSite($localSiteId) ?? []);
            $manifest = [
                'schema'=>self::SCHEMA,
                'module_version'=>$moduleVersion,
                'created_at'=>time(),
                'source'=>[
                    'site_id'=>$localSiteId,
                    'site_key'=>(string)($site['site_key'] ?? ''),
                    'site_name'=>(string)($site['name'] ?? ''),
                ],
                'scopes'=>$scopes,
                'mode'=>$mode,
                'database_members'=>$databaseMembers,
                'files'=>$files,
                'behavior'=>[
                    'database'=>'merge by stored primary key; unrelated rows are preserved',
                    'files'=>'matching files are replaced atomically; unrelated files are preserved',
                    'runtime_excluded'=>['operation logs','cached site snapshots','job run history','live registrations','Fail2ban bans'],
                    'tftp_assets_included'=>$includeTftp,
                ],
                'contains_secrets'=>$this->recoveryMode,
                'integrity'=>'SHA-256 member integrity; not a digital signature',
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (!$zip->close()) { throw new \RuntimeException('Could not finalize the configuration package'); }
            if (!chmod($path,0600)) { throw new \RuntimeException('Could not restrict the recovery archive'); }
            return ['path'=>$path, 'filename'=>$filename, 'manifest'=>$manifest];
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($path);
            throw $error;
        } finally {
            foreach ($temporaryFiles as $temporary) { @unlink($temporary); }
        }
    }

    /** @return array{ok:bool,message:string,scopes:string[],rows:int,files:int} */
    public function verifyRecoveryBackup(string $path,string $expectedSiteKey,?string $expectedKeySha256 = null): array
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)
            || (PHP_OS_FAMILY!=='Windows' && (fileperms($path)&0077)!==0)) {
            throw new \RuntimeException('Recovery backup must be a restricted readable regular file');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path,\ZipArchive::RDONLY) !== true) { throw new \RuntimeException('Recovery backup is not readable'); }
        try {
            $manifest = json_decode((string)$zip->getFromName('manifest.json',2097152),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['schema'] ?? '') !== self::SCHEMA || ($manifest['mode'] ?? '') !== 'recovery'
                || $expectedSiteKey === '' || !hash_equals($expectedSiteKey,(string)($manifest['source']['site_key'] ?? ''))) {
                throw new \RuntimeException('Recovery backup mode or controller identity does not match');
            }
            $keyHashes = [];
            $members = [];
            $scopes = self::normalizeScopes((array)($manifest['scopes'] ?? []));
            if (!in_array('settings',$scopes,true)) { throw new \RuntimeException('Recovery backup lacks settings database'); }
            foreach ($scopes as $scope) {
                $entry = 'database/' . $scope . '.json';
                $digest = $manifest['database_members'][$entry] ?? [];
                $stat = $zip->statName($entry);
                if (!$stat || (int)$stat['size'] > self::MAX_DATABASE_BYTES) { throw new \RuntimeException('Recovery database member missing or oversized'); }
                $bytes = $zip->getFromName($entry);
                if (!is_string($bytes) || strlen($bytes) !== (int)($digest['size'] ?? -1)
                    || !hash_equals((string)($digest['sha256'] ?? ''),hash('sha256',$bytes))) { throw new \RuntimeException('Recovery database member verification failed'); }
                $members[$entry] = hash('sha256',$bytes);
                $data = json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
                foreach ((array)($data['tables']['ucmp_settings'] ?? []) as $row) {
                    if (($row['skey'] ?? '') === 'crypt_key' && is_string($row['sval'] ?? null) && $row['sval'] !== '') { $keyHashes[] = hash('sha256',$row['sval']); }
                }
            }
            foreach ((array)($manifest['files'] ?? []) as $file) {
                $entry = (string)($file['archive_path'] ?? '');
                $stat = $zip->statName($entry);
                if (!$stat || (int)$stat['size'] !== (int)($file['size'] ?? -1)) { throw new \RuntimeException('Recovery file metadata verification failed'); }
                $stream = $zip->getStream($entry);
                if (!$stream) { throw new \RuntimeException('Recovery file is unreadable'); }
                $ctx = hash_init('sha256');
                hash_update_stream($ctx,$stream); fclose($stream);
                $hash = hash_final($ctx);
                if (!hash_equals((string)($file['sha256'] ?? ''),$hash)) { throw new \RuntimeException('Recovery file hash verification failed'); }
                if (($file['target'] ?? '') === 'private' && ($file['relative_path'] ?? '') === 'secrets/credentials.key') { $keyHashes[] = $hash; }
            }
            if (count(array_unique($keyHashes))!==1 || ($expectedKeySha256 !== null && !in_array($expectedKeySha256,$keyHashes,true))) { throw new \RuntimeException('Recovery backup does not contain one unambiguous matching credentials key'); }
            return ['ok'=>true,'archive_sha256'=>hash_file('sha256',$path),'database_members'=>$members,'key_sha256'=>array_values(array_unique($keyHashes)),'site_key'=>$expectedSiteKey];
        } finally { $zip->close(); }
    }

    public function import(string $uploadPath, array $requestedScopes, string $mode = 'configuration'): array
    {
        $this->setMode($mode);
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive is required to import a configuration package');
        }
        if (!is_file($uploadPath) || !is_readable($uploadPath)) {
            throw new \InvalidArgumentException('The uploaded configuration package is unavailable');
        }
        @set_time_limit(0);
        $zip = new \ZipArchive();
        if ($zip->open($uploadPath, \ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException('The uploaded file is not a readable ZIP package');
        }

        $stageRoot = $this->privateRoot . '/configuration-packages/import-' . bin2hex(random_bytes(8));
        $stagedFiles = [];
        $rollbacks = [];
        $retainRecovery = false;
        try {
            $manifestBytes = $zip->getFromName('manifest.json', 2097152, \ZipArchive::FL_UNCHANGED);
            $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR) : null;
            if (!is_array($manifest) || (string)($manifest['schema'] ?? '') !== self::SCHEMA) {
                throw new \InvalidArgumentException('This is not a supported UCM Provisioner configuration package');
            }
            if (($manifest['mode'] ?? '') !== $mode) { throw new \InvalidArgumentException('Select the explicit matching configuration or recovery import mode'); }
            $site = (array)$this->ucm->getSite((int)$this->ucm->localSiteId());
            if (empty($site['site_key']) || !hash_equals((string)$site['site_key'],(string)($manifest['source']['site_key'] ?? ''))
                || (int)($manifest['source']['site_id'] ?? 0) !== (int)$this->ucm->localSiteId()) {
                throw new \DomainException('Archive belongs to another controller; use configuration federation for cross-site policy transfer');
            }
            $availableScopes = self::normalizeScopes((array)($manifest['scopes'] ?? []));
            $wanted = self::normalizeScopes($requestedScopes);
            if ($requestedScopes && !$wanted) { throw new \InvalidArgumentException('None of the requested import scopes is supported'); }
            $scopes = $wanted ? array_values(array_intersect($availableScopes, $wanted)) : $availableScopes;
            if (!$scopes) { throw new \InvalidArgumentException('None of the selected tabs exists in this package'); }

            $this->ensureLocalParent($stageRoot . '/placeholder', $this->privateRoot);
            if (!@chmod($stageRoot,0700)) {
                throw new \RuntimeException('Could not create the private import staging directory');
            }
            $database = [];
            foreach ($scopes as $scope) {
                $entry = 'database/' . $scope . '.json';
                $stat = $zip->statName($entry, \ZipArchive::FL_UNCHANGED);
                if (!is_array($stat) || (int)($stat['size'] ?? 0) > self::MAX_DATABASE_BYTES) {
                    throw new \InvalidArgumentException("The $scope database payload is missing or too large");
                }
                // statName() already enforces the bound. Passing that bound as
                // maxLength makes libzip reserve the maximum even for a tiny
                // payload on some builds, so read the verified actual length.
                $bytes = $zip->getFromName($entry, 0, \ZipArchive::FL_UNCHANGED);
                $digest = $manifest['database_members'][$entry] ?? [];
                if (!is_string($bytes) || strlen($bytes) !== (int)($digest['size'] ?? -1) || !hash_equals((string)($digest['sha256'] ?? ''),hash('sha256',$bytes))) {
                    throw new \InvalidArgumentException('Database member integrity verification failed');
                }
                $decoded = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
                if (!is_array($decoded) || !is_array($decoded['tables'] ?? null)) {
                    throw new \InvalidArgumentException("The $scope database payload is malformed");
                }
                $allowedTables = array_fill_keys(array_merge(self::TABLES[$scope], ['ucmp_assets']), true);
                foreach ($decoded['tables'] as $table=>$rows) {
                    if (!isset($allowedTables[(string)$table]) || !is_array($rows)) {
                        throw new \InvalidArgumentException("The $scope package contains an unexpected table");
                    }
                }
                $database[$scope] = $decoded['tables'];
            }

            $totalBytes = 0;
            $targets = [];
            foreach ((array)($manifest['files'] ?? []) as $index=>$file) {
                if (!is_array($file) || !in_array((string)($file['scope'] ?? ''), $scopes, true)) { continue; }
                if (count($stagedFiles) >= self::MAX_FILES) { throw new \RuntimeException('The package contains too many files'); }
                $scope = (string)$file['scope'];
                $target = (string)($file['target'] ?? '');
                $relative = $this->safeRelative((string)($file['relative_path'] ?? ''));
                // Older packages may contain this runtime inode; never replace a held lock.
                if ($target === 'private' && $relative === 'directory/.publication.lock') { continue; }
                if (!in_array($target, ['private','tftp'], true) || !$this->targetAllowed($target, $scope, $relative)) {
                    throw new \InvalidArgumentException('The package contains an unsafe file target');
                }
                $targetKey = $target . '/' . strtolower($relative);
                if (isset($targets[$targetKey])) { throw new \InvalidArgumentException('Duplicate archive file target'); }
                $targets[$targetKey] = true;
                $entry = 'files/' . $target . '/' . $scope . '/' . $relative;
                if (!hash_equals($entry, (string)($file['archive_path'] ?? ''))) {
                    throw new \InvalidArgumentException('The package file manifest is inconsistent');
                }
                $stat = $zip->statName($entry, \ZipArchive::FL_UNCHANGED);
                $size = (int)($stat['size'] ?? -1);
                if (!is_array($stat) || $size < 0 || $size !== (int)($file['size'] ?? -2)) {
                    throw new \InvalidArgumentException("Package size metadata does not match $relative");
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_TOTAL_BYTES) { throw new \RuntimeException('The package exceeds the safe extraction limit'); }
                $staged = $stageRoot . '/file-' . $index;
                $input = $zip->getStream($entry);
                $output = @fopen($staged, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) { fclose($input); }
                    if (is_resource($output)) { fclose($output); }
                    throw new \RuntimeException("Could not stage $relative");
                }
                $copied = stream_copy_to_stream($input, $output);
                fclose($input); fclose($output);
                if ($copied !== $size || !hash_equals(strtolower((string)($file['sha256'] ?? '')), (string)hash_file('sha256', $staged))) {
                    throw new \InvalidArgumentException("Package hash verification failed for $relative");
                }
                $stagedFiles[] = ['scope'=>$scope, 'target'=>$target, 'relative'=>$relative, 'staged'=>$staged,'sha256'=>(string)$file['sha256']];
            }

            if ($this->recoveryMode) { $this->assertRecoveryKeyPair($database,$stagedFiles); }
            $operation = function () use ($scopes,$database,$stagedFiles,$stageRoot,&$rollbacks,&$retainRecovery): array {
            $rowsApplied = 0;
            $commitStarted = false;
            $before = [];
            foreach ($database as $tables) { foreach ($tables as $table=>$rows) { $before[$table] = $this->tableRows($table); } }
            $beforeJson = json_encode($before,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($beforeJson) > self::MAX_DATABASE_BYTES || file_put_contents($stageRoot . '/database-before.json',$beforeJson,LOCK_EX) !== strlen($beforeJson)
                || !chmod($stageRoot . '/database-before.json',0600)) { throw new \RuntimeException('Could not preserve database recovery snapshot'); }
            $this->writeImportReceipt($stageRoot,$rollbacks,'prepared');
            $retainRecovery = true;
            if (!$this->db->beginTransaction()) { throw new \RuntimeException('Could not begin archive database transaction'); }
            try {
                foreach ($scopes as $scope) {
                    foreach ($database[$scope] as $table=>$rows) {
                        foreach ($rows as $row) {
                            if (!is_array($row)) { throw new \InvalidArgumentException("A $table row is malformed"); }
                            if (!$this->recoveryMode) { $row = $this->projectRow((string)$table,$row); if (!$row) { continue; } }
                            $this->mergeRow((string)$table, $row);
                            $rowsApplied++;
                        }
                    }
                }
                foreach ($stagedFiles as $file) {
                    if ($file['target'] === 'private') { $this->installPrivateFile($file,$stageRoot,$rollbacks); }
                    else { $this->installTftpFile($file,$stageRoot,$rollbacks); }
                }
                $this->writeImportReceipt($stageRoot,$rollbacks,'commit_intent');
                $commitStarted = true;
                if (!$this->db->commit()) { throw new \RuntimeException('Archive database commit was not confirmed'); }
                $this->writeImportReceipt($stageRoot,$rollbacks,'committed');
            } catch (\Throwable $error) {
                // Once commit was attempted its outcome can be uncertain. Never
                // undo files independently of a possibly committed database.
                if ($commitStarted && !$this->db->inTransaction()) {
                    try { $this->writeImportReceipt($stageRoot,$rollbacks,'commit_outcome_requires_review'); } catch (\Throwable) {}
                    return ['ok'=>false,'status'=>'recovery_required','recovery_required'=>true,'recovery_id'=>basename($stageRoot),'message'=>'Database commit or final receipt could not be confirmed; retained database and file copies require review'];
                }
                try {
                    if ($this->db->inTransaction() && !$this->db->rollBack()) {
                        throw new \RuntimeException('Database rollback was not confirmed');
                    }
                } catch (\Throwable) {
                    try { $this->writeImportReceipt($stageRoot,$rollbacks,'database_recovery_required'); } catch (\Throwable) {}
                    return ['ok'=>false,'status'=>'recovery_required','recovery_required'=>true,'recovery_id'=>basename($stageRoot),
                        'message'=>'Database rollback could not be confirmed; files and paired recovery evidence were retained for review'];
                }
                $failures = $this->rollbackFiles($rollbacks,$stageRoot);
                try { $this->writeImportReceipt($stageRoot,$rollbacks,$failures ? 'recovery_required' : 'rolled_back'); }
                catch (\Throwable) { $failures[] = ['target'=>'receipt']; }
                if ($failures) {
                    return ['ok'=>false,'status'=>'recovery_required','recovery_required'=>true,'recovery_id'=>basename($stageRoot),'message'=>'Import failed and file restoration is incomplete; retained recovery copies require review','unresolved'=>$failures];
                }
                throw $error;
            }
            return [
                'ok'=>true,
                'message'=>sprintf('Imported %d database row(s) and %d managed file(s) from %d selected tab(s). Unrelated records and files were preserved.', $rowsApplied, count($stagedFiles), count($scopes)),
                'scopes'=>$scopes,
                'rows'=>$rowsApplied,
                'files'=>count($stagedFiles),
                'recovery_id'=>basename($stageRoot),
            ];
            };
            $lock = @fopen($this->privateRoot . '/configuration-packages/import.lock','c');
            if (!$lock || !flock($lock,LOCK_EX | LOCK_NB)) { if ($lock) { fclose($lock); } throw new \RuntimeException('Another archive import is running'); }
            try {
                if (array_filter($stagedFiles,static fn(array $file): bool=>$file['target'] === 'tftp')) {
                    return $this->withArchiveLocks($scopes, true, $operation);
                }
                return $this->withArchiveLocks($scopes, false, $operation);
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        } finally {
            $zip->close();
            if (!$retainRecovery) { $this->removeTree($stageRoot); }
        }
    }

    /** Keep configuration and published generations stable while capturing or replacing files. */
    private function withArchiveLocks(array $scopes, bool $includeTftp, callable $operation): array
    {
        return $this->ucm->withConfigurationSyncLock(function () use ($scopes,$includeTftp,$operation): array {
            $directoryLock = null;
            if (in_array('directory', $scopes, true)) {
                $directoryLock = ServiceFilePermissions::lock($this->privateRoot . '/directory/.publication.lock');
                if ($directoryLock === false) { throw new \RuntimeException('Directory publication is in progress; retry the archive operation after it finishes'); }
            }
            try {
                if ($includeTftp && array_intersect($scopes, ['phones','directory','media','firmware','tftp'])) {
                    return (new TftpStore($this->ucm))->withSiteLock((int)$this->ucm->localSiteId(), $operation);
                }
                return $operation();
            } finally {
                if (is_resource($directoryLock)) { flock($directoryLock, LOCK_UN); fclose($directoryLock); }
            }
        });
    }

    /** All related rows must come from the same committed database revision. */
    private function snapshotTables(array $scopes): array
    {
        if ($this->db->inTransaction()) { throw new \RuntimeException('Configuration export requires its own database snapshot'); }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        if (!$this->db->beginTransaction()) { throw new \RuntimeException('Could not begin configuration export snapshot'); }
        try {
            $tables = [];
            foreach ($scopes as $scope) {
                foreach (self::TABLES[$scope] as $table) { $tables[$table] = $this->tableRows($table); }
            }
            $tables['ucmp_assets'] = $this->tableRows('ucmp_assets');
            $this->assertReferences($tables);
            if (!$this->db->commit()) { throw new \RuntimeException('Could not finish configuration export snapshot'); }
            return $tables;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }

    /** Partial exports are valid; validate dependencies only when both tables are selected. */
    private function assertReferences(array $tables): void
    {
        foreach ([
            ['ucmp_phones','extension_id','ucmp_extensions'],
            ['ucmp_phones','group_id','ucmp_groups'],
            ['ucmp_groups','template_id','ucmp_templates'],
            ['ucmp_groups','parent_group_id','ucmp_groups'],
            ['ucmp_extensions','group_id','ucmp_groups'],
            ['ucmp_default_template_rules','template_id','ucmp_templates'],
        ] as [$table,$column,$parent]) {
            if (!isset($tables[$table], $tables[$parent])) { continue; }
            $ids = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $tables[$parent]), true);
            foreach ($tables[$table] as $row) {
                $id = (int)($row[$column] ?? 0);
                if ($id > 0 && !isset($ids[$id])) {
                    throw new \RuntimeException("Cannot export inconsistent configuration: $table.$column references missing $parent ID $id");
                }
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function tableRows(string $table): array
    {
        if (!$this->knownTable($table)) { throw new \InvalidArgumentException('Unknown archive table'); }
        $statement = $this->db->query('SELECT * FROM `' . $table . '`');
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function knownTable(string $table): bool
    {
        if ($table === 'ucmp_assets') { return true; }
        foreach (self::TABLES as $tables) {
            if (in_array($table, $tables, true)) { return true; }
        }
        return false;
    }

    private function setMode(string $mode): void
    {
        if (!in_array($mode,['configuration','recovery'],true)) { throw new \InvalidArgumentException('Choose configuration export or restricted recovery backup'); }
        $this->recoveryMode = $mode === 'recovery';
    }

    /** Merge imports must not change the key underneath retained encrypted rows. */
    private function assertRecoveryKeyPair(array $database,array $files): void
    {
        $keys=[];
        foreach ((array)($database['settings']['ucmp_settings'] ?? []) as $row) {
            if (($row['skey'] ?? '')==='crypt_key' && (string)($row['sval'] ?? '')!=='') { $keys[]=hash('sha256',(string)$row['sval']); }
        }
        foreach ($files as $file) {
            if ($file['target']==='private' && $file['relative']==='secrets/credentials.key') { $keys[]=(string)$file['sha256']; }
        }
        if (!$keys) { return; }
        $keys=array_values(array_unique($keys));
        if (count($keys)!==1) { throw new \RuntimeException('Recovery database and private credential key disagree'); }
        $current=(new LocalCredentialKey($this->db,$this->privateRoot))->read();
        if ($current==='' || !hash_equals(hash('sha256',$current),$keys[0])) {
            throw new \RuntimeException('Merge recovery cannot replace a different or missing credential key; restore the matching database/key pair through the restricted recovery procedure');
        }
    }

    /** Explicit projection; omitted secret values preserve destination-local credentials on merge. */
    private function projectRow(string $table,array $row): array
    {
        if ($table === 'ucmp_settings') {
            if (in_array(($row['skey'] ?? ''),['identity_delivery_cache','identity_delivery_releases'],true)) { return []; }
            if ($this->sensitiveName((string)($row['skey'] ?? '')) || $this->secretValue($row['sval'] ?? '')) { return []; }
        }
        $project = function (array $values) use (&$project): array {
            foreach ($values as $key=>$value) {
                if ($this->sensitiveName((string)$key) || $this->secretValue($value)) { unset($values[$key]); continue; }
                if (is_array($value)) { $values[$key] = $project($value); }
                elseif (is_string($value) && in_array(substr(ltrim($value),0,1),['{','['],true)) {
                    $decoded = json_decode($value,true);
                    if (is_array($decoded)) { $values[$key] = json_encode($project($decoded),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
                }
            }
            return $values;
        };
        return $project($row);
    }

    private function sensitiveName(string $name): bool
    {
        return preg_match('/password|passwd|secret|token|crypt_key|private_key|credential|api_key|access_key|authorization|cookie/i',$name) === 1;
    }

    private function secretValue($value): bool
    {
        return is_string($value) && (preg_match('/-----BEGIN (?:[A-Z ]*PRIVATE KEY|OPENSSH PRIVATE KEY)-----/',$value)
            || preg_match('#https?://[^/\s]+:[^@\s]+@|[?&](?:token|password|secret|api_key|access_key)=#i',$value));
    }

    private function assetScope(array $row): string
    {
        $kind = strtolower(trim((string)($row['kind'] ?? '')));
        if (in_array($kind, ['background','ringtone','media'], true)) { return 'media'; }
        if (in_array($kind, ['firmware','locale','timezone'], true)) { return 'firmware'; }
        if (in_array($kind, ['phonebook','directory'], true)) { return 'directory'; }
        $path = $this->safeRelative((string)($row['relative_path'] ?? $row['filename'] ?? ''), false);
        return $this->looksLikePhoneConfig($path) ? 'phones' : 'tftp';
    }

    /** @return string[] */
    private function privateRoots(string $scope): array
    {
        return match ($scope) {
            'media' => ['media'],
            'directory' => $this->recoveryMode ? ['directory', 'secrets/directory'] : [],
            'settings' => $this->recoveryMode ? ['secrets/credentials.key'] : [],
            'patches' => ['asterisk-bundles'],
            default => [],
        };
    }

    private function addLocalTree(\ZipArchive $zip, string $absoluteRoot, string $relativeRoot, string $scope, array &$files, array &$counters, array &$temporaryFiles): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) { continue; }
            if ($scope === 'directory' && $item->getFilename() === '.publication.lock') { continue; }
            $suffix = str_replace('\\', '/', substr($item->getPathname(), strlen($absoluteRoot) + 1));
            $relative = $this->safeRelative($relativeRoot . '/' . $suffix);
            $this->addFile($zip, $item->getPathname(), 'private', $scope, $relative, $files, $counters, $temporaryFiles);
        }
    }

    private function addTftpFiles(\ZipArchive $zip, array $scopes, array &$files, array &$counters, array &$temporaryFiles, array $assets): void
    {
        $siteId = (int)$this->ucm->localSiteId();
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim((string)$connection->tftpRoot(), '/');
        $assetMap = [];
        foreach ($assets as $asset) {
            if ((int)($asset['site_id'] ?? 0) !== $siteId) { continue; }
            $relative = $this->safeRelative((string)($asset['relative_path'] ?? ''), false);
            if ($relative !== '') { $assetMap[strtolower($relative)] = $this->assetScope($asset); }
        }
        $stack = [['relative'=>'', 'absolute'=>$root, 'depth'=>0]];
        while ($stack) {
            $current = array_pop($stack);
            if ((int)$current['depth'] > 16) { throw new \RuntimeException('TFTP export exceeded the safe directory depth'); }
            $entries = $sftp->rawlist((string)$current['absolute']);
            if (!is_array($entries)) { throw new \RuntimeException('Could not enumerate the managed TFTP root for export'); }
            foreach ($entries as $name=>$metadata) {
                if ($name === '.' || $name === '..') { continue; }
                $relative = $this->safeRelative(trim((string)$current['relative'] . '/' . $name, '/'));
                if ($this->isTransientTftpPath($relative)) { continue; }
                $absolute = rtrim((string)$current['absolute'], '/') . '/' . $name;
                $type = (int)($metadata['type'] ?? 0);
                if ($type === 2) {
                    $stack[] = ['relative'=>$relative, 'absolute'=>$absolute, 'depth'=>(int)$current['depth'] + 1];
                    continue;
                }
                if ($type !== 1) { continue; }
                $scope = $assetMap[strtolower($relative)] ?? $this->tftpPathScope($relative);
                if (!in_array($scope, $scopes, true)) { continue; }
                if (!$this->recoveryMode && in_array($scope,['phones','directory','tftp'],true)) { continue; }
                $temporary = tempnam($this->privateRoot . '/configuration-packages', 'tftp-');
                if (!is_string($temporary) || !$sftp->get($absolute, $temporary)) {
                    if (is_string($temporary)) { @unlink($temporary); }
                    throw new \RuntimeException("Could not read $relative from the managed TFTP endpoint");
                }
                $temporaryFiles[] = $temporary;
                $this->addFile($zip, $temporary, 'tftp', $scope, $relative, $files, $counters, $temporaryFiles);
            }
        }
    }

    private function isTransientTftpPath(string $relative): bool
    {
        $lower = strtolower(trim(str_replace('\\', '/', $relative), '/'));
        if ($lower === '.ucmprovisioner/current.json') { return true; }
        foreach ([
            '.ucmprovisioner/transactions/',
            '.ucmprovisioner/quarantine/',
            '.ucmprovisioner/wallpaper-sets-retired/',
            '.ucmprovisioner/timezone-stage-',
            '.ucmprovisioner/timezone-backup-',
            '.ucmprovisioner/archive-',
            '.ucmprovisioner/write-lock',
        ] as $prefix) {
            if (str_starts_with($lower, $prefix) || rtrim($prefix, '/') === $lower) { return true; }
        }
        return false;
    }

    private function tftpPathScope(string $relative): string
    {
        $lower = strtolower($relative);
        if ($this->looksLikePhoneConfig($relative)) { return 'phones'; }
        if (str_starts_with($lower, 'desktops/') || str_starts_with($lower, '.ucmprovisioner/wallpaper-sets/') || str_contains($lower, 'ringtone')) { return 'media'; }
        if (str_starts_with($lower, '.ucmprovisioner/timezone-bundles/') || str_starts_with($lower, 'firmware/')) { return 'firmware'; }
        if (str_contains($lower, 'directory') || str_contains($lower, 'phonebook')) { return 'directory'; }
        return 'tftp';
    }

    private function looksLikePhoneConfig(string $relative): bool
    {
        if (str_contains($relative, '/')) { return false; }
        return preg_match('/\A(?:SEP[0-9A-F]{12}\.cnf\.xml|SIP[0-9A-F]{12}\.cnf|[0-9A-F]{12}\.cfg|cfg[0-9A-F]{12}\.xml)\z/Di', $relative) === 1;
    }

    private function addFile(\ZipArchive $zip, string $source, string $target, string $scope, string $relative, array &$files, array &$counters, array &$temporaryFiles): void
    {
        $size = (int)filesize($source);
        $counters['files']++;
        $counters['bytes'] += $size;
        if ($counters['files'] > self::MAX_FILES || $counters['bytes'] > self::MAX_TOTAL_BYTES) {
            throw new \RuntimeException('The selected configuration package exceeds the safe file or size limit');
        }
        if ($target === 'private') {
            // ZipArchive reads addFile() inputs at close(). Hold the same private
            // bytes for both the manifest hash and that later archive read.
            $snapshot = tempnam($this->privateRoot . '/configuration-packages', 'snapshot-');
            if (!is_string($snapshot)) { throw new \RuntimeException('Could not snapshot a package file'); }
            $temporaryFiles[] = $snapshot;
            if (!copy($source,$snapshot) || !chmod($snapshot,0600)) { throw new \RuntimeException('Could not snapshot ' . $relative); }
            clearstatcache(true,$snapshot);
            if (filesize($snapshot) !== $size) { throw new \RuntimeException('Package source size changed during capture: ' . $relative); }
            $source = $snapshot;
        }
        $archivePath = 'files/' . $target . '/' . $scope . '/' . $relative;
        if (!$zip->addFile($source, $archivePath)) { throw new \RuntimeException("Could not add $relative to the package"); }
        $zip->setCompressionName($archivePath, \ZipArchive::CM_STORE);
        $files[] = [
            'archive_path'=>$archivePath,
            'target'=>$target,
            'scope'=>$scope,
            'relative_path'=>$relative,
            'size'=>$size,
            'sha256'=>(string)hash_file('sha256', $source),
        ];
    }

    private function mergeRow(string $table, array $row): void
    {
        if (!$this->knownTable($table) || !$row) { throw new \InvalidArgumentException('Unknown or empty archive row'); }
        $columns = [];
        $primary = [];
        if ((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->db->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $name = (string)$column['name'];
                $columns[$name] = true;
                if (!empty($column['pk'])) { $primary[(int)$column['pk']] = $name; }
            }
            ksort($primary);
            $primary = array_values($primary);
        } else {
            foreach ($this->db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $name = (string)$column['Field'];
                $columns[$name] = true;
                if ((string)($column['Key'] ?? '') === 'PRI') { $primary[] = $name; }
            }
        }
        $row = array_intersect_key($row, $columns);
        if (!$row || !$primary || array_diff($primary, array_keys($row))) {
            throw new \InvalidArgumentException("A $table row lacks its primary key");
        }
        $where = implode(' AND ', array_map(static fn(string $column): string => '`' . $column . '`=?', $primary));
        $check = $this->db->prepare('SELECT 1 FROM `' . $table . '` WHERE ' . $where . ' LIMIT 1');
        $check->execute(array_map(static fn(string $column) => $row[$column], $primary));
        if ($check->fetchColumn()) {
            $updates = array_values(array_diff(array_keys($row), $primary));
            if (!$updates) { return; }
            $statement = $this->db->prepare(
                'UPDATE `' . $table . '` SET ' . implode(',', array_map(static fn(string $column): string => '`' . $column . '`=?', $updates)) . ' WHERE ' . $where
            );
            $statement->execute(array_merge(array_map(static fn(string $column) => $row[$column], $updates), array_map(static fn(string $column) => $row[$column], $primary)));
            return;
        }
        $names = array_keys($row);
        $statement = $this->db->prepare(
            'INSERT INTO `' . $table . '` (' . implode(',', array_map(static fn(string $column): string => '`' . $column . '`', $names)) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')'
        );
        $statement->execute(array_values($row));
    }

    private function installPrivateFile(array $file, string $stageRoot, array &$rollbacks): void
    {
        $target = $this->privateRoot . '/' . $file['relative'];
        $this->ensureLocalParent($target, $this->privateRoot);
        $backup = null;
        if (is_link($target) || (file_exists($target) && !is_file($target))) { throw new \RuntimeException('Private import target is not a regular file'); }
        $existed = is_file($target);
        if ($existed) {
            $backup = tempnam($stageRoot, 'rollback-');
            if (!is_string($backup) || !copy($target, $backup)) { throw new \RuntimeException('Could not stage an import rollback copy'); }
        }
        $credentialKey=$file['relative']==='secrets/credentials.key';
        $mode = $credentialKey ? 0640 : (str_starts_with($file['relative'],'secrets/') ? 0600 : 0660);
        $rollbacks[] = ['target'=>'private','path'=>$target,'existed'=>$existed,'backup'=>$backup,'before_hash'=>$existed ? hash_file('sha256',$backup) : null,'after_hash'=>$file['sha256'],'mode'=>$existed ? fileperms($target) & 0777 : $mode];
        $this->writeImportReceipt($stageRoot,$rollbacks,'applying');
        $temporary = $target . '.ucmp-import-' . bin2hex(random_bytes(4));
        if (!copy((string)$file['staged'], $temporary) || !@chmod($temporary, $mode)
            || ($credentialKey && PHP_OS_FAMILY!=='Windows' && !chgrp($temporary,'asterisk')) || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not install private file ' . $file['relative']);
        }
        if (!hash_equals($file['sha256'],(string)hash_file('sha256',$target))) { throw new \RuntimeException('Private import readback failed'); }
    }

    private function installTftpFile(array $file, string $stageRoot, array &$rollbacks): void
    {
        $connection = $this->ucm->siteConnection((int)$this->ucm->localSiteId());
        $sftp = $connection->dockerSftp();
        $remote = rtrim((string)$connection->tftpRoot(), '/') . '/' . $file['relative'];
        $this->ensureRemoteParent($sftp, dirname($remote), rtrim((string)$connection->tftpRoot(), '/'));
        $existing = $sftp->lstat($remote);
        if (is_array($existing) && (int)($existing['type'] ?? 0) !== 1) { throw new \RuntimeException('TFTP import target is not a regular file'); }
        $existed = is_array($existing) && (int)($existing['type'] ?? 1) !== 2;
        $backup = null;
        if ($existed) {
            $backup = tempnam($stageRoot, 'rollback-');
            if (!is_string($backup) || !$sftp->get($remote, $backup)) { throw new \RuntimeException('Could not stage a TFTP rollback copy'); }
        }
        $temporary = rtrim((string)$connection->tftpRoot(), '/') . '/.ucmprovisioner/archive-' . basename($stageRoot) . '/' . hash('sha256',$file['relative']);
        $this->ensureRemoteParent($sftp,dirname($temporary),rtrim((string)$connection->tftpRoot(),'/'));
        $rollbacks[] = ['target'=>'tftp','path'=>$remote,'existed'=>$existed,'backup'=>$backup,'before_hash'=>$existed ? hash_file('sha256',$backup) : null,'after_hash'=>$file['sha256'],'mode'=>(int)($existing['mode'] ?? 0664) & 0777,'temporary'=>$temporary,'remote_backup'=>$temporary . '.old'];
        $this->writeImportReceipt($stageRoot,$rollbacks,'applying');
        if (!$sftp->put($temporary, (string)$file['staged'], \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)
            || !$sftp->chmod(0664,$temporary)
            || !hash_equals($file['sha256'],$this->remoteHash($sftp,$temporary,$stageRoot))) {
            throw new \RuntimeException('Could not install TFTP file ' . $file['relative']);
        }
        if ($existed && !$sftp->rename($remote,$temporary . '.old')) { throw new \RuntimeException('Could not preserve TFTP original'); }
        if (!$sftp->rename($temporary,$remote) || !hash_equals($file['sha256'],$this->remoteHash($sftp,$remote,$stageRoot))) { throw new \RuntimeException('TFTP replacement could not be verified'); }
    }

    private function rollbackFiles(array &$rollbacks,string $stageRoot): array
    {
        $connection = null;
        $failures = [];
        foreach (array_reverse(array_keys($rollbacks)) as $index) {
            $rollback = $rollbacks[$index];
            try {
                if ($rollback['target'] === 'private') {
                    $current = is_file($rollback['path']) ? hash_file('sha256',$rollback['path']) : null;
                    if ($current !== $rollback['before_hash']) {
                        if ($current !== null && $current !== $rollback['after_hash']) { throw new \RuntimeException('Private file changed after import'); }
                        if (!empty($rollback['existed'])) {
                            $temporary = $rollback['path'] . '.ucmp-restore-' . bin2hex(random_bytes(4));
                            if (!copy($rollback['backup'],$temporary) || !chmod($temporary,$rollback['mode']) || !rename($temporary,$rollback['path'])
                                || !hash_equals($rollback['before_hash'],(string)hash_file('sha256',$rollback['path']))) { throw new \RuntimeException('Private restore failed'); }
                        } elseif (is_file($rollback['path']) && !unlink($rollback['path'])) { throw new \RuntimeException('Private new-file rollback failed'); }
                    }
                } else {
                    $connection ??= $this->ucm->siteConnection((int)$this->ucm->localSiteId());
                    $sftp = $connection->dockerSftp();
                    $current = is_array($sftp->lstat($rollback['path'])) ? $this->remoteHash($sftp,$rollback['path'],$stageRoot) : null;
                    if ($current !== $rollback['before_hash']) {
                        if ($current !== null && $current !== $rollback['after_hash']) { throw new \RuntimeException('TFTP file changed after import'); }
                        if ($rollback['existed']) {
                            $temporary = $rollback['temporary'] . '.restore';
                            if (!$sftp->put($temporary,$rollback['backup'],\phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)
                                || !$sftp->chmod($rollback['mode'],$temporary)
                                || !hash_equals($rollback['before_hash'],$this->remoteHash($sftp,$temporary,$stageRoot))
                                || !$sftp->rename($temporary,$rollback['path'])
                                || !hash_equals($rollback['before_hash'],$this->remoteHash($sftp,$rollback['path'],$stageRoot))) { throw new \RuntimeException('TFTP restore failed'); }
                        } elseif ($current !== null && !$sftp->delete($rollback['path'])) { throw new \RuntimeException('TFTP new-file rollback failed'); }
                    }
                }
                $rollbacks[$index]['restored'] = true;
                $this->writeImportReceipt($stageRoot,$rollbacks,'restoring');
            } catch (\Throwable) {
                $failures[] = ['target'=>$rollback['target'],'index'=>$index];
            }
        }
        return $failures;
    }

    private function remoteHash(object $sftp,string $path,string $stageRoot): string
    {
        $temporary = tempnam($stageRoot,'verify-');
        try {
            if (!$temporary || !$sftp->get($path,$temporary)) { throw new \RuntimeException('TFTP readback failed'); }
            return (string)hash_file('sha256',$temporary);
        } finally { if ($temporary) { @unlink($temporary); } }
    }

    private function writeImportReceipt(string $stageRoot,array $rollbacks,string $status): void
    {
        $temporary = $stageRoot . '/recovery.tmp';
        $json = json_encode(['status'=>$status,'updated_at'=>time(),'files'=>$rollbacks],JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary,$json,LOCK_EX) !== strlen($json) || !chmod($temporary,0600) || !rename($temporary,$stageRoot . '/recovery.json')) { throw new \RuntimeException('Could not preserve import recovery receipt'); }
    }

    private function ensureLocalParent(string $path, string $root): void
    {
        $parent = dirname($path);
        $root = str_replace('\\','/',$root);
        $parent = str_replace('\\','/',$parent);
        if (!str_starts_with($parent . '/',rtrim($root,'/') . '/')) { throw new \RuntimeException('Import target escaped the private storage root'); }
        $cursor = $parent;
        while (strlen($cursor) >= strlen($root)) {
            if (is_link($cursor)) { throw new \RuntimeException('Refusing to import through a symbolic link'); }
            if ($cursor === $root) {
                if (!is_dir($parent) && !@mkdir($parent,0770,true) && !is_dir($parent)) { throw new \RuntimeException('Could not create an import directory'); }
                return;
            }
            $next = dirname($cursor);
            if ($next === $cursor) { break; }
            $cursor = $next;
        }
        throw new \RuntimeException('Import target escaped the private storage root');
    }

    private function ensureRemoteParent(object $sftp, string $parent, string $root): void
    {
        if (!str_starts_with($parent . '/', rtrim($root, '/') . '/')) { throw new \RuntimeException('TFTP import target escaped its managed root'); }
        $relative = trim(substr($parent, strlen(rtrim($root, '/'))), '/');
        $cursor = rtrim($root, '/');
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $cursor .= '/' . $part;
            $stat = $sftp->lstat($cursor);
            if (is_array($stat) && (int)($stat['type'] ?? 0) !== 2) { throw new \RuntimeException('Refusing non-directory or symbolic-link TFTP parent'); }
            if (!is_array($stat) && (!$sftp->mkdir($cursor) || !$sftp->chmod(0755, $cursor))) {
                throw new \RuntimeException('Could not create a managed TFTP directory');
            }
        }
    }

    private function targetAllowed(string $target, string $scope, string $relative): bool
    {
        if ($target === 'tftp') { return in_array($scope, $this->recoveryMode ? ['phones','directory','media','firmware','tftp'] : ['media','firmware'], true) && !$this->isTransientTftpPath($relative); }
        foreach ($this->privateRoots($scope) as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) { return true; }
        }
        return false;
    }

    private function safeRelative(string $path, bool $throw = true): string
    {
        $path = str_replace('\\', '/', trim($path));
        $unsafe = $path === '' || str_starts_with($path,'/') || str_contains($path, "\0") || preg_match('/(?:\A|\/)\.{1,2}(?:\/|\z)/D', $path) || preg_match('/\A[A-Za-z]:/D', $path);
        if ($unsafe) {
            if ($throw) { throw new \InvalidArgumentException('An archive path is empty or unsafe'); }
            return '';
        }
        return preg_replace('#/+#', '/', $path);
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root) || is_link($root)) { return; }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) { @rmdir($item->getPathname()); }
            else { @unlink($item->getPathname()); }
        }
        @rmdir($root);
    }
}
