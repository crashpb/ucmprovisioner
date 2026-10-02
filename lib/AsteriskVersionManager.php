<?php
/**
 * Small, FreePBX 17/Debian Asterisk source-build version manager.
 *
 * It intentionally manages only the Asterisk executable, its matching module
 * directory and Asterisk-built shared libraries. FreePBX configuration,
 * voicemail, sounds and recordings remain untouched.
 */
namespace FreePBX\modules\Ucmprovisioner;

if (!class_exists(__NAMESPACE__ . '\\PatchCatalog', false)) {
    require_once __DIR__ . '/PatchCatalog.php';
}

final class AsteriskVersionManager
{
    private const STATE_ROOT = '/var/lib/ucmp-patch-manager';
    private const BUNDLE_ROOT = '/var/lib/asterisk/ucmprovisioner/asterisk-bundles';
    private const INCOMING_ROOT = '/var/lib/asterisk/ucmprovisioner/asterisk-incoming';
    private const STATE_FILE = self::STATE_ROOT . '/state.json';
    private const INVENTORY_FILE = self::BUNDLE_ROOT . '/inventory.json';
    private const MAX_UPLOAD_BYTES = 536870912;
    private const HISTORY_LIMIT = 3;

    /** @var null|callable */
    private $runner;

    public function __construct(
        ?callable $runner = null,
        private string $stateRoot = self::STATE_ROOT,
        private string $bundleRoot = self::BUNDLE_ROOT,
        private string $incomingRoot = self::INCOMING_ROOT,
        private string $runtimeRoot = ''
    ) {
        if ($runtimeRoot !== '' && $runner === null) { throw new \InvalidArgumentException('Alternate runtime roots require an isolated command runner'); }
        $this->runner = $runner;
    }

    /** @return array<string,mixed> */
    public function handle(array $request): array
    {
        $operation = strtolower(trim((string)($request['op'] ?? 'status')));
        return match ($operation) {
            'health' => ['ok'=>true, 'mode'=>'freepbx17_source_bundles'],
            'status' => $this->status(),
            'preview' => $this->preview($this->catalog($request), $request),
            'download' => $this->download($this->catalog($request)),
            'stage' => $this->stage($this->catalog($request), $request),
            'build' => $this->build($this->catalog($request), $request),
            'capture' => $this->captureCurrent('detected-current'),
            'import' => $this->importIncoming($request),
            'install', 'activate' => $this->activate($this->bundleId($request), false),
            'protect', 'pin' => $this->pin($this->bundleId($request)),
            'unprotect', 'unpin' => $this->unpin(),
            'rollback' => $this->rollback(),
            'delete' => $this->deleteBundle($this->bundleId($request)),
            default => throw new \InvalidArgumentException('Unknown Asterisk version-manager operation'),
        };
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $this->ensureDirectories();
        $state = $this->readState();
        $runtime = $this->runtime();
        $bundles = $this->bundles();
        $active = '';
        foreach ($bundles as &$bundle) {
            $bundle['matches_runtime'] = !empty($bundle['archive_valid']) && !empty($bundle['runtime_sha256'])
                && hash_equals((string)$bundle['runtime_sha256'], $runtime['runtime_sha256']);
            $bundle['pinned'] = hash_equals((string)($state['pinned_bundle_id'] ?? ''), (string)$bundle['bundle_id']);
            if ($bundle['matches_runtime'] && ($active === '' || $bundle['bundle_id'] === ($state['active_bundle_id'] ?? ''))) { $active = (string)$bundle['bundle_id']; }
        }
        unset($bundle);
        foreach ($bundles as &$bundle) {
            $bundle['active'] = $bundle['bundle_id'] === $active;
            $bundle['duplicate_candidate'] = !$bundle['active'] && $active !== ''
                && hash_equals((string)($bundle['binary_sha256'] ?? ''),(string)$runtime['installed_binary_sha256']);
            $bundle['can_delete'] = !$bundle['active'] && (!$bundle['pinned'] || $bundle['duplicate_candidate']);
        }
        unset($bundle);
        $holds = $this->heldPackages();
        $switchHeld = in_array('asterisk-version-switch', $holds, true);
        $pinnedBundle = (string)($state['pinned_bundle_id'] ?? '');
        $pinDrift = $pinnedBundle !== '' && !hash_equals($pinnedBundle, $active);
        $result = [
            'ok'=>true,
            'mode'=>'freepbx17_source_bundles',
            'runtime'=>$runtime,
            'catalog'=>PatchCatalog::entries(),
            'builds'=>$this->buildStates($bundles),
            'bundles'=>$bundles,
            'incoming'=>$this->incoming(),
            'active_bundle_id'=>$active,
            'pinned_bundle_id'=>$pinnedBundle,
            'switch_package_held'=>$switchHeld,
            'pin_protected'=>$pinnedBundle !== '' && $switchHeld && !$pinDrift,
            'pin_drift'=>$pinDrift,
            'history'=>array_slice((array)($state['history'] ?? []), 0, self::HISTORY_LIMIT),
            'inspected_update_path'=>'FreePBX framework uses asterisk-version-switch; source-built /usr/sbin/asterisk is not owned by dpkg.',
            'captured_at'=>time(),
        ];
        $this->publishInventory($result);
        return $result;
    }

    /** @return array<string,mixed> */
    private function preview(array $entry, array $request): array
    {
        $options = $this->options($request);
        return [
            'ok'=>true,
            'catalog_id'=>$entry['catalog_id'],
            'asterisk_version'=>$entry['asterisk_version'],
            'commands'=>$this->commands($entry, $options),
            'options'=>$options,
            'note'=>'Commands use a root-owned workspace and stage make install into a bundle; they do not replace the running PBX until Activate is confirmed.',
        ];
    }

    /** @return array<string,mixed> */
    private function download(array $entry): array
    {
        $this->ensureDirectories();
        $workspace = $this->workspace($entry);
        $this->ensureDirectory($workspace, 0700);
        $source = $workspace . '/' . $entry['source_filename'];
        $patch = $workspace . '/' . $entry['patch_filename'];
        $this->downloadOne((string)$entry['source_url'], $source, (string)$entry['source_sha256']);
        $this->downloadOne((string)$entry['patch_url'], $patch, (string)$entry['patch_sha256']);
        $this->atomicJson($workspace . '/download.json', [
            'catalog_id'=>$entry['catalog_id'],
            'source_sha256'=>$entry['source_sha256'],
            'patch_sha256'=>$entry['patch_sha256'],
            'completed_at'=>time(),
        ], 0600);
        return $this->complete([
            'ok'=>true,
            'message'=>"Downloaded Asterisk {$entry['asterisk_version']} and its matching USECALLMANAGER patch.",
            'source_sha256'=>$entry['source_sha256'],
            'patch_sha256'=>$entry['patch_sha256'],
        ]);
    }

    /** @return array<string,mixed> */
    private function stage(array $entry, array $request): array
    {
        $this->ensureDirectories();
        $workspace = $this->workspace($entry);
        $sourceArchive = $workspace . '/' . $entry['source_filename'];
        $patchFile = $workspace . '/' . $entry['patch_filename'];
        $this->assertHash($sourceArchive, (string)$entry['source_sha256'], 'Asterisk source');
        $this->assertHash($patchFile, (string)$entry['patch_sha256'], 'USECALLMANAGER patch');
        $sourceRoot = $workspace . '/source';
        $this->removeTree($sourceRoot, $workspace);
        $this->ensureDirectory($sourceRoot, 0700);
        $this->mustRun(['/usr/bin/tar','--extract','--gzip','--file',$sourceArchive,'--directory',$sourceRoot,'--strip-components=1']);
        if (!is_file($sourceRoot . '/configure')) {
            throw new \RuntimeException('The Asterisk archive did not contain configure');
        }
        $this->mustRun([
            '/usr/bin/patch','--batch','--forward','--strip=1','--input',$patchFile,
        ], $sourceRoot, $workspace . '/stage.log');
        $options = $this->options($request);
        $this->atomicJson($workspace . '/stage.json', [
            'catalog_id'=>$entry['catalog_id'],
            'asterisk_version'=>$entry['asterisk_version'],
            'patch_sha256'=>$entry['patch_sha256'],
            'options'=>$options,
            'completed_at'=>time(),
        ], 0600);
        return $this->complete([
            'ok'=>true,
            'message'=>"Patched the Asterisk {$entry['asterisk_version']} source tree. Review the command preview before Build.",
            'commands'=>$this->commands($entry, $options),
        ]);
    }

    /** @return array<string,mixed> */
    private function build(array $entry, array $request): array
    {
        $this->ensureDirectories();
        $workspace = $this->workspace($entry);
        $sourceRoot = $workspace . '/source';
        $stageMeta = $this->readJson($workspace . '/stage.json');
        if (!is_file($sourceRoot . '/configure') || ($stageMeta['patch_sha256'] ?? '') !== $entry['patch_sha256']) {
            throw new \RuntimeException('Download and patch this exact Asterisk version before building it');
        }
        $options = $this->options($request + (array)($stageMeta['options'] ?? []));
        $log = $workspace . '/build.log';
        $configure = array_merge([$sourceRoot . '/configure'], (array)$entry['configure_args']);
        $this->mustRun($configure, $sourceRoot, $log);
        if ($options['high_strength_srtp']) {
            $this->mustRun(['/usr/bin/make','menuselect.makeopts'], $sourceRoot, $log);
            $this->mustRun([
                $sourceRoot . '/menuselect/menuselect','--enable','ENABLE_SRTP_AES_256','menuselect.makeopts',
            ], $sourceRoot, $log);
            $this->mustRun([
                $sourceRoot . '/menuselect/menuselect','--enable','ENABLE_SRTP_AES_GCM','menuselect.makeopts',
            ], $sourceRoot, $log);
        }
        $this->mustRun(['/usr/bin/make','-j' . $options['jobs'],'all'], $sourceRoot, $log);
        $stageRoot = $workspace . '/install-root';
        $this->removeTree($stageRoot, $workspace);
        $this->ensureDirectory($stageRoot, 0700);
        $this->mustRun(['/usr/bin/make','install','DESTDIR=' . $stageRoot], $sourceRoot, $log);
        $bundle = $this->bundleFromStage($entry, $stageRoot, $options);
        return $this->complete([
            'ok'=>true,
            'message'=>"Built Asterisk {$entry['asterisk_version']} with USECALLMANAGER and added it to Available versions.",
            'bundle'=>$bundle,
            'log_tail'=>$this->tail($log),
        ]);
    }

    /** @return array<string,mixed> */
    private function captureCurrent(string $origin): array
    {
        $this->ensureDirectories();
        $runtime = $this->runtime();
        if ($runtime['installed_version'] === '' || !is_file($this->runtimeRoot . '/usr/sbin/asterisk')) {
            throw new \RuntimeException('No installed Asterisk executable was detected');
        }
        $moduleDir = (string)$runtime['module_directory'];
        if (!is_dir($moduleDir)) {
            throw new \RuntimeException('The running Asterisk module directory was not found');
        }
        foreach ($this->bundles() as $existing) {
            if (!empty($existing['archive_valid']) && !empty($existing['runtime_sha256'])
                && hash_equals((string)$existing['runtime_sha256'],(string)$runtime['runtime_sha256'])) {
                $state = $this->readState();
                $state['active_bundle_id'] = $existing['bundle_id'];
                $state['last_capture_at'] = time();
                $this->writeState($state);
                return $this->complete(['ok'=>true,'reused'=>true,'bundle'=>$existing,
                    'message'=>'The current installation is already captured; its unchanged bundle was verified and reused.']);
            }
        }
        $tmp = $this->temporaryDirectory('capture');
        try {
            $runtimeRoot = $tmp . '/runtime';
            $this->copyFile($this->runtimeRoot . '/usr/sbin/asterisk', $runtimeRoot . '/usr/sbin/asterisk', 0755);
            $this->copyTree($moduleDir, $runtimeRoot . '/usr/lib/asterisk/modules');
            foreach ($this->runtimeLibraries($this->runtimeRoot) as $library) {
                if (is_file($library) && !is_link($library)) {
                    $this->copyFile($library, $runtimeRoot . '/usr/lib/' . basename($library), 0644);
                }
            }
            $capturedHash = $this->manifestHash($this->runtimeManifest($runtimeRoot,$runtimeRoot . '/usr/lib/asterisk/modules'));
            $currentHash = $this->manifestHash($this->runtimeManifest($this->runtimeRoot,$moduleDir));
            if (!hash_equals((string)$runtime['runtime_sha256'],$capturedHash) || !hash_equals($capturedHash,$currentHash)) {
                throw new \RuntimeException('Asterisk files changed during capture; no bundle was declared active. Retry after the installation finishes.');
            }
            $manifest = [
                'schema_version'=>1,
                'version'=>$runtime['installed_version'],
                'catalog_id'=>PatchCatalog::forVersion($runtime['installed_version'])['catalog_id'] ?? '',
                'origin'=>$origin,
                'patch_status'=>$runtime['usecallmanager_detected'] ? 'detected' : 'unknown',
                'patch_sha256'=>'',
                'binary_sha256'=>$runtime['installed_binary_sha256'],
                'created_at'=>time(),
            ];
            $bundle = $this->storeBundle($tmp, $manifest);
            $state = $this->readState();
            $state['active_bundle_id'] = $bundle['bundle_id'];
            $this->writeState($state);
            return $this->complete([
                'ok'=>true,
                'message'=>"Captured the installed Asterisk {$runtime['installed_version']} executable and matching module set.",
                'bundle'=>$bundle,
            ]);
        } finally {
            $this->removeTree($tmp, $this->stateRoot);
        }
    }

    /** @return array<string,mixed> */
    private function importIncoming(array $request): array
    {
        $this->ensureDirectories();
        $incomingId = trim((string)($request['incoming_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/D', $incomingId)) {
            throw new \InvalidArgumentException('Choose a valid staged upload');
        }
        $path = $this->incomingRoot . '/' . $incomingId . '.upload';
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path) || (int)$stat['size'] < 4 || (int)$stat['size'] > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('The staged upload is missing or invalid');
        }
        $declaredVersion = trim((string)($request['declared_version'] ?? ''));
        if ($declaredVersion !== '' && !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+~][A-Za-z0-9._-]+)?$/D', $declaredVersion)) {
            throw new \InvalidArgumentException('Declared Asterisk version is invalid');
        }
        $tmp = $this->temporaryDirectory('import');
        try {
            // The incoming area is writable by the uploader. Inspect and extract
            // only this private copy so the checked archive cannot be replaced.
            $input = $tmp . '/incoming/payload';
            $this->copyFile($path,$input,0600);
            if (filesize($input) > self::MAX_UPLOAD_BYTES) { throw new \RuntimeException('Staged upload exceeds the import bound'); }
            $head = (string)file_get_contents($input, false, null, 0, 4);
            if ($head === "\x7fELF") {
                if ($declaredVersion === '') {
                    throw new \InvalidArgumentException('A raw executable upload requires its Asterisk version');
                }
                $file = $this->mustRun(['/usr/bin/file','--brief',$input]);
                if (!str_contains($file['stdout'], 'ELF 64-bit') || !preg_match('/x86-64|x86_64/i', $file['stdout'])) {
                    throw new \RuntimeException('The upload is not a 64-bit x86 Asterisk executable');
                }
                $runtime = $this->runtime();
                $moduleDir = (string)$runtime['module_directory'];
                if (!is_dir($moduleDir)) {
                    throw new \RuntimeException('Binary-only import needs the currently installed matching module directory');
                }
                $this->copyFile($input, $tmp . '/runtime/usr/sbin/asterisk', 0755);
                $this->copyTree($moduleDir, $tmp . '/runtime/usr/lib/asterisk/modules');
                foreach ($this->runtimeLibraries($this->runtimeRoot) as $library) {
                    if (!is_link($library)) {
                        $this->copyFile($library, $tmp . '/runtime/usr/lib/' . basename($library), 0644);
                    }
                }
                $manifest = [
                    'schema_version'=>1,
                    'version'=>$declaredVersion,
                    'catalog_id'=>PatchCatalog::forVersion($declaredVersion)['catalog_id'] ?? '',
                    'origin'=>'uploaded-binary',
                    'patch_status'=>!empty($request['declared_patched']) ? 'user-declared' : 'unknown',
                    'patch_sha256'=>'',
                    'binary_sha256'=>hash_file('sha256', $input),
                    'created_at'=>time(),
                    'warning'=>'Binary-only upload uses the currently installed modules and runtime libraries; ABI compatibility is the administrator\'s responsibility.',
                ];
            } else {
                $listing = $this->mustRun(['/usr/bin/tar','--list','--verbose','--numeric-owner','--quoting-style=escape','--gzip','--file',$input]);
                $this->assertSafeArchive($listing['stdout']);
                $this->mustRun([
                    '/usr/bin/tar','--extract','--gzip','--file',$input,'--directory',$tmp,
                    '--no-same-owner','--no-same-permissions',
                ]);
                $this->assertNoLinks($tmp);
                $manifest = $this->readJson($tmp . '/manifest.json');
                if (!is_file($tmp . '/runtime/usr/sbin/asterisk') || !is_dir($tmp . '/runtime/usr/lib/asterisk/modules')) {
                    throw new \RuntimeException('Uploaded bundle lacks the Asterisk executable or module directory');
                }
                $version = (string)($manifest['version'] ?? '');
                if (!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+~][A-Za-z0-9._-]+)?$/D', $version)) {
                    throw new \RuntimeException('Uploaded bundle has an invalid Asterisk version');
                }
                if ($declaredVersion !== '' && !hash_equals($declaredVersion, $version)) {
                    throw new \RuntimeException('Declared version does not match the bundle manifest');
                }
                $manifest['origin'] = str_starts_with((string)($manifest['origin'] ?? ''), 'module-')
                    ? 'synced-module-bundle'
                    : 'uploaded-bundle';
                $manifest['created_at'] = time();
                $manifest['binary_sha256'] = hash_file('sha256', $tmp . '/runtime/usr/sbin/asterisk');
            }
            $bundle = $this->storeBundle($tmp, $manifest);
            @unlink($path);
            @unlink($this->incomingRoot . '/' . $incomingId . '.json');
            return $this->complete([
                'ok'=>true,
                'message'=>"Imported Asterisk {$bundle['version']} into Available versions; nothing was activated.",
                'bundle'=>$bundle,
            ]);
        } finally {
            $this->removeTree($tmp, $this->stateRoot);
        }
    }

    /** @return array<string,mixed> */
    private function activate(string $bundleId, bool $rollback): array
    {
        $this->ensureDirectories();
        $bundle = $this->bundle($bundleId);
        $state = $this->readState();
        $pinned = (string)($state['pinned_bundle_id'] ?? '');
        if (!$rollback && $pinned !== '' && !hash_equals($pinned, $bundleId)) {
            throw new \RuntimeException('Unpin the active Asterisk version before switching to another bundle');
        }
        $runtimeBefore = $this->runtime();
        $previous = '';
        if ($runtimeBefore['installed_binary_sha256'] !== '') {
            foreach ($this->bundles() as $known) {
                if (!empty($known['runtime_sha256']) && hash_equals((string)$known['runtime_sha256'], $runtimeBefore['runtime_sha256'])) {
                    $previous = (string)$known['bundle_id'];
                    break;
                }
            }
        }
        if ($previous === '') {
            $capture = $this->captureCurrent('automatic-pre-activation');
            $previous = (string)($capture['bundle']['bundle_id'] ?? '');
            $state = $this->readState();
        }
        $originalState=$state;
        $tmp = $this->temporaryDirectory('activate');
        $binaryPath = $this->runtimeRoot . '/usr/sbin/asterisk';
        $binaryNew = $binaryPath . '.ucmp-new-' . bin2hex(random_bytes(6));
        $binaryBackup = $binaryPath . '.ucmp-old-' . bin2hex(random_bytes(6));
        $moduleDir = (string)$runtimeBefore['module_directory'];
        if (!is_dir($moduleDir)) { $moduleDir = $this->runtimeRoot . '/usr/lib/asterisk/modules'; }
        $moduleBackup = dirname($moduleDir) . '/.' . basename($moduleDir) . '.ucmp-old-' . bin2hex(random_bytes(6));
        $moduleNew = dirname($moduleDir) . '/.' . basename($moduleDir) . '.ucmp-new-' . bin2hex(random_bytes(6));
        $stopped = false;
        $switched = false;
        $libraryBackups = [];
        $binaryChanged = false;
        $modulesChanged = false;
        $receipt = ['status'=>'preparing','bundle_id'=>$bundleId,'previous_bundle_id'=>$previous,'binary_path'=>$binaryPath,'binary_backup'=>$binaryBackup,'module_directory'=>$moduleDir,'module_backup'=>$moduleBackup,'library_backups'=>[]];
        $receiptPath = $tmp . '/recovery.json';
        $keepRecovery = false;
        $verifiedActivation = null;
        try {
            $listing = $this->mustRun(['/usr/bin/tar','--list','--verbose','--numeric-owner','--quoting-style=escape','--gzip','--file',$bundle['archive_path']]);
            $this->assertSafeArchive($listing['stdout']);
            $this->mustRun(['/usr/bin/tar','--extract','--gzip','--file',$bundle['archive_path'],'--directory',$tmp]);
            $this->assertNoLinks($tmp);
            $newBinary = $tmp . '/runtime/usr/sbin/asterisk';
            $newModules = $tmp . '/runtime/usr/lib/asterisk/modules';
            if (!is_file($newBinary) || !is_dir($newModules)) {
                throw new \RuntimeException('Stored Asterisk bundle is incomplete');
            }
            $payload = $this->runtimeManifest($tmp . '/runtime',$newModules);
            if (empty($bundle['runtime_sha256']) || !hash_equals((string)$bundle['runtime_sha256'],$this->manifestHash($payload))) { throw new \RuntimeException('Asterisk runtime payload integrity mismatch; recapture legacy bundles before activation'); }
            $this->copyTree($newModules, $moduleNew);
            $this->copyFile($newBinary, $binaryNew, 0755);
            // Back up every resource before stop or any replacement. An early
            // failure must never remove the untouched original installation.
            $this->copyFile($binaryPath,$binaryBackup,0755);
            $this->copyTree($moduleDir,$moduleBackup);
            if (!hash_equals($runtimeBefore['runtime_sha256'],$this->manifestHash($this->runtimeManifest($this->runtimeRoot,$moduleBackup)))) { throw new \RuntimeException('Pre-activation module backup failed integrity verification'); }
            $libraryNames = [];
            foreach (array_merge($this->runtimeLibraries($tmp . '/runtime'),$this->runtimeLibraries($this->runtimeRoot)) as $library) { $libraryNames[basename($library)] = true; }
            foreach (array_keys($libraryNames) as $libraryName) {
                $destination = $this->runtimeRoot . '/usr/lib/' . $libraryName;
                if (is_link($destination)) { throw new \RuntimeException('Asterisk library target is a symbolic link'); }
                $backup = '';
                if (is_file($destination)) { $backup = $destination . '.ucmp-old-' . bin2hex(random_bytes(6)); $this->copyFile($destination,$backup,0644); }
                $libraryBackups[$destination] = $backup;
            }
            $receipt['library_backups'] = $libraryBackups;
            $receipt['status'] = 'prepared';
            $receipt['before_runtime_sha256'] = $runtimeBefore['runtime_sha256'];
            $this->atomicJson($receiptPath,$receipt,0600);
            $keepRecovery = true;
            $receipt['status'] = 'stop_intent'; $this->atomicJson($receiptPath,$receipt,0600);
            $stopped = true;
            $this->mustRun(['/usr/bin/timeout','180','/usr/sbin/fwconsole','stop']);
            $receipt['status'] = 'replace_intent'; $this->atomicJson($receiptPath,$receipt,0600);
            if (!@rename($binaryNew, $binaryPath)) {
                throw new \RuntimeException('Could not atomically replace the Asterisk executable');
            }
            $binaryChanged = true;
            $modulesChanged = true;
            $this->removeTree($moduleDir,dirname($moduleDir));
            if (!@rename($moduleNew, $moduleDir)) {
                throw new \RuntimeException('Could not activate the selected Asterisk module directory');
            }
            foreach ($this->runtimeLibraries($tmp . '/runtime') as $library) {
                $destination = $this->runtimeRoot . '/usr/lib/' . basename($library);
                $this->copyFile($library, $destination, 0644);
            }
            foreach ($libraryBackups as $destination=>$backup) {
                if (!is_file($tmp . '/runtime/usr/lib/' . basename($destination)) && !unlink($destination)) { throw new \RuntimeException('Could not retire an obsolete Asterisk runtime library'); }
            }
            $switched = true;
            if (is_executable('/usr/sbin/ldconfig')) { $this->mustRun(['/usr/sbin/ldconfig']); }
            $this->mustRun(['/usr/bin/timeout','180','/usr/sbin/fwconsole','start']);
            $stopped = false;
            $after = $this->waitForRuntime((string)$bundle['version']);
            if (!$after['healthy']) {
                throw new \RuntimeException((string)$after['message']);
            }
            if (!hash_equals((string)$bundle['runtime_sha256'],(string)$after['runtime']['runtime_sha256'])) { throw new \RuntimeException('Activated runtime payload does not match the selected bundle'); }
            $verifiedActivation = ['ok'=>true,'active_bundle_id'=>$bundleId,'runtime'=>$after['runtime'],
                'message'=>"Activated Asterisk {$bundle['version']} and confirmed that the runtime started."];
            $history = array_values(array_filter(
                (array)($state['history'] ?? []),
                static fn($item): bool => is_array($item) && ($item['bundle_id'] ?? '') !== $previous
            ));
            if ($previous !== '' && !hash_equals($previous, $bundleId)) {
                array_unshift($history, ['bundle_id'=>$previous,'replaced_at'=>time()]);
            }
            $state['history'] = array_slice($history, 0, self::HISTORY_LIMIT);
            $state['active_bundle_id'] = $bundleId;
            if ($rollback && $pinned !== '') { $state['pinned_bundle_id'] = $bundleId; }
            $this->writeState($state);
            $receipt['status'] = 'verified'; $this->atomicJson($receiptPath,$receipt,0600);
            return $this->complete($verifiedActivation);
        } catch (\Throwable $failure) {
            if ($verifiedActivation !== null) {
                $verifiedActivation['reporting_error'] = $failure->getMessage();
                $verifiedActivation['message'] .= ' Manager bookkeeping needs attention; verified runtime remains active. Recovery evidence: ' . basename($tmp);
                return $verifiedActivation;
            }
            if ($stopped || $binaryChanged || $modulesChanged) {
                try {
                    if (!$stopped) { $this->mustRun(['/usr/bin/timeout','180','/usr/sbin/fwconsole','stop']); }
                    if ($binaryChanged) { $this->copyFile($binaryBackup,$binaryPath,0755); }
                    if ($modulesChanged) { $this->removeTree($moduleDir,dirname($moduleDir)); $this->copyTree($moduleBackup,$moduleDir); }
                    foreach ($libraryBackups as $destination=>$backup) {
                        if ($backup !== '') { $this->copyFile($backup,$destination,0644); }
                        elseif (is_file($destination) && !unlink($destination)) { throw new \RuntimeException('Could not remove newly installed Asterisk library'); }
                    }
                    if (is_executable('/usr/sbin/ldconfig')) { $this->mustRun(['/usr/sbin/ldconfig']); }
                    $this->mustRun(['/usr/bin/timeout','180','/usr/sbin/fwconsole','start']);
                    $restored = $this->waitForRuntime((string)$runtimeBefore['running_version']);
                    if (!$restored['healthy'] || !hash_equals($runtimeBefore['runtime_sha256'],$restored['runtime']['runtime_sha256'])) { throw new \RuntimeException('Original Asterisk runtime restoration could not be verified'); }
                    $this->writeState($originalState);
                    $receipt['status'] = 'rolled_back';
                    $this->atomicJson($receiptPath,$receipt,0600);
                } catch (\Throwable) {
                    $receipt['status'] = 'recovery_required';
                    try { $this->atomicJson($receiptPath,$receipt,0600); } catch (\Throwable) {}
                    throw new \RuntimeException('Asterisk activation failed and restoration needs review; recovery evidence: ' . basename($tmp));
                }
            }
            throw $failure;
        } finally {
            if (!$keepRecovery) { $this->removeTree($tmp, $this->stateRoot); }
            @unlink($binaryNew);
            $this->removeTree($moduleNew, dirname($moduleDir));
            // Confirmed backups are retained on both success and failure; a
            // later operator-controlled cleanup must not erase recovery here.
        }
    }

    /** @return array<string,mixed> */
    private function pin(string $bundleId): array
    {
        $status = $this->status();
        if (!hash_equals((string)$status['active_bundle_id'], $bundleId)) {
            throw new \RuntimeException('Only the currently active managed Asterisk version can be pinned');
        }
        $this->mustRun(['/usr/bin/apt-mark','hold','asterisk-version-switch']);
        $state = $this->readState();
        $state['pinned_bundle_id'] = $bundleId;
        $state['pinned_at'] = time();
        $this->writeState($state);
        return $this->complete([
            'ok'=>true,
            'message'=>'Pinned the active bundle and held FreePBX asterisk-version-switch.',
        ]);
    }

    /** @return array<string,mixed> */
    private function unpin(): array
    {
        $this->mustRun(['/usr/bin/apt-mark','unhold','asterisk-version-switch']);
        $state = $this->readState();
        $state['pinned_bundle_id'] = '';
        $state['unpinned_at'] = time();
        $this->writeState($state);
        return $this->complete([
            'ok'=>true,
            'message'=>'Removed the module pin and released asterisk-version-switch.',
        ]);
    }

    /** @return array<string,mixed> */
    private function rollback(): array
    {
        $state = $this->readState();
        foreach ((array)($state['history'] ?? []) as $history) {
            $bundleId = is_array($history) ? (string)($history['bundle_id'] ?? '') : '';
            if ($bundleId !== '' && is_file($this->bundleRoot . '/' . $bundleId . '.json')) {
                return $this->activate($bundleId, true);
            }
        }
        throw new \RuntimeException('No retained previous Asterisk bundle is available');
    }

    /** @return array<string,mixed> */
    private function deleteBundle(string $bundleId): array
    {
        $state = $this->readState();
        $observedActive = (string)$this->status()['active_bundle_id'];
        if ($bundleId === $observedActive) { throw new \RuntimeException('The active Asterisk bundle cannot be deleted'); }
        $bundle = $this->bundle($bundleId);
        if ($bundleId === ($state['pinned_bundle_id'] ?? '') || $bundleId === ($state['active_bundle_id'] ?? '')) {
            if ($observedActive === '') { throw new \RuntimeException('No verified active bundle can retain this protected capture; capture the current installation first'); }
            $keeper = $this->bundle($observedActive);
            $hash = $this->verifiedBundleRuntimeHash($bundle);
            if (!hash_equals($hash,$this->verifiedBundleRuntimeHash($keeper))
                || !hash_equals($hash,(string)$this->runtime()['runtime_sha256'])) {
                throw new \RuntimeException('This protected bundle is not an exact runtime duplicate; keep it or explicitly change its pin');
            }
            if (($state['pinned_bundle_id'] ?? '') === $bundleId) { $state['pinned_bundle_id'] = $observedActive; }
            $state['active_bundle_id'] = $observedActive;
            // Preserve the same runtime pin before deleting only its duplicate archive.
            $this->writeState($state);
        }
        if (!@unlink((string)$bundle['archive_path']) || !@unlink($this->bundleRoot . '/' . $bundleId . '.json')) {
            throw new \RuntimeException('Could not remove the selected Asterisk bundle');
        }
        $state['history'] = array_values(array_filter(
            (array)($state['history'] ?? []),
            static fn($item): bool => !is_array($item) || ($item['bundle_id'] ?? '') !== $bundleId
        ));
        $this->writeState($state);
        return $this->complete(['ok'=>true,'message'=>'Removed the inactive Asterisk bundle.']);
    }

    /** Inventory refresh cannot undo or erase a completed operation. */
    private function complete(array $result): array
    {
        try { $result['manager'] = $this->status(); }
        catch (\Throwable $error) {
            $result['reporting_error'] = $error->getMessage();
            $result['message'] .= ' Inventory refresh failed; refresh status before another operation.';
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function bundleFromStage(array $entry, string $stageRoot, array $options): array
    {
        $binary = $stageRoot . '/usr/sbin/asterisk';
        if (!is_file($binary)) {
            throw new \RuntimeException('The staged build did not contain /usr/sbin/asterisk');
        }
        $chanSip = $this->findFile($stageRoot, 'chan_sip.so');
        if ($chanSip === '') {
            throw new \RuntimeException('The staged build did not contain chan_sip.so');
        }
        $moduleDir = dirname($chanSip);
        $tmp = $this->temporaryDirectory('bundle');
        try {
            $this->copyFile($binary, $tmp . '/runtime/usr/sbin/asterisk', 0755);
            $this->copyTree($moduleDir, $tmp . '/runtime/usr/lib/asterisk/modules');
            $currentModuleDir = (string)$this->runtime()['module_directory'];
            if (is_dir($currentModuleDir)) {
                foreach (glob($currentModuleDir . '/*.so') ?: [] as $module) {
                    $destination = $tmp . '/runtime/usr/lib/asterisk/modules/' . basename($module);
                    if (!file_exists($destination)) { $this->copyFile($module, $destination, 0755); }
                }
            }
            foreach ($this->findLibraries($stageRoot) as $library) {
                $this->copyFile($library, $tmp . '/runtime/usr/lib/' . basename($library), 0644);
            }
            $manifest = [
                'schema_version'=>1,
                'version'=>$entry['asterisk_version'],
                'catalog_id'=>$entry['catalog_id'],
                'origin'=>'module-build',
                'patch_status'=>'verified-during-build',
                'patch_sha256'=>$entry['patch_sha256'],
                'binary_sha256'=>hash_file('sha256', $binary),
                'high_strength_srtp'=>$options['high_strength_srtp'],
                'configure_args'=>$entry['configure_args'],
                'created_at'=>time(),
            ];
            return $this->storeBundle($tmp, $manifest);
        } finally {
            $this->removeTree($tmp, $this->stateRoot);
        }
    }

    /** @return array<string,mixed> */
    private function storeBundle(string $tree, array $manifest): array
    {
        $version = (string)($manifest['version'] ?? '');
        $binary = $tree . '/runtime/usr/sbin/asterisk';
        if (!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+~][A-Za-z0-9._-]+)?$/D', $version) || !is_file($binary)) {
            throw new \RuntimeException('Cannot store an incomplete Asterisk bundle');
        }
        $binaryHash = hash_file('sha256', $binary);
        $payload = $this->runtimeManifest($tree . '/runtime',$tree . '/runtime/usr/lib/asterisk/modules');
        $runtimeHash = $this->manifestHash($payload);
        $bundleId = 'asterisk-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $version)
            . '-' . substr($runtimeHash, 0, 24);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{8,95}$/D', $bundleId)) {
            throw new \RuntimeException('Generated Asterisk bundle ID is invalid');
        }
        $manifest['bundle_id'] = $bundleId;
        $manifest['binary_sha256'] = $binaryHash;
        $manifest['runtime_sha256'] = $runtimeHash;
        $manifest['runtime_manifest'] = $payload;
        if (is_file($this->bundleRoot . '/' . $bundleId . '.json')) {
            $existing = $this->bundle($bundleId);
            if (!hash_equals((string)($existing['runtime_sha256'] ?? ''),$runtimeHash)) { throw new \RuntimeException('Existing immutable bundle payload differs'); }
            $this->assertHash($existing['archive_path'],(string)($existing['archive_sha256'] ?? ''),'Existing bundle archive');
            return $existing;
        }
        if (file_exists($this->bundleRoot . '/' . $bundleId . '.tar.gz')) { throw new \RuntimeException('Unindexed bundle archive requires recovery review'); }
        $manifest['module_count'] = count(glob($tree . '/runtime/usr/lib/asterisk/modules/*.so') ?: []);
        $this->atomicJson($tree . '/manifest.json', $manifest, 0600);
        $archive = $this->bundleRoot . '/' . $bundleId . '.tar.gz';
        $tmpArchive = $archive . '.tmp-' . bin2hex(random_bytes(6));
        $this->mustRun([
            '/usr/bin/tar','--create','--gzip','--file',$tmpArchive,
            '--directory',$tree,'manifest.json','runtime',
        ]);
        if (!@chmod($tmpArchive, 0640) || !@chown($tmpArchive, 'root') || !@chgrp($tmpArchive, 'asterisk')) {
            @unlink($tmpArchive);
            throw new \RuntimeException('Could not secure the Asterisk bundle archive');
        }
        if (!@rename($tmpArchive, $archive)) {
            @unlink($tmpArchive);
            throw new \RuntimeException('Could not commit the Asterisk bundle archive');
        }
        $public = array_merge($manifest, [
            'archive_sha256'=>hash_file('sha256', $archive),
            'size_bytes'=>filesize($archive),
            'archive_path'=>$archive,
        ]);
        $this->atomicJson($this->bundleRoot . '/' . $bundleId . '.json', $public, 0640, 'asterisk');
        return $public;
    }

    /** @return array<int,array<string,mixed>> */
    private function bundles(): array
    {
        $bundles = [];
        foreach (glob($this->bundleRoot . '/asterisk-*.json') ?: [] as $path) {
            try {
                $bundle = $this->readJson($path);
                $id = (string)($bundle['bundle_id'] ?? '');
                $archive = $this->bundleRoot . '/' . $id . '.tar.gz';
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{8,95}$/D', $id) || !is_file($archive)) { continue; }
                $bundle['archive_path'] = $archive;
                $bundle['archive_valid'] = hash_equals((string)($bundle['archive_sha256'] ?? ''),(string)hash_file('sha256', $archive));
                $bundle['size_bytes'] = filesize($archive);
                $bundles[] = $bundle;
            } catch (\Throwable) {
                continue;
            }
        }
        usort($bundles, static fn(array $a, array $b): int =>
            version_compare((string)$b['version'], (string)$a['version'])
                ?: ((int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0))
        );
        return $bundles;
    }

    /** @return array<string,mixed> */
    private function bundle(string $bundleId): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{8,95}$/D', $bundleId)) {
            throw new \InvalidArgumentException('Choose a valid managed Asterisk bundle');
        }
        $bundle = $this->readJson($this->bundleRoot . '/' . $bundleId . '.json');
        $archive = $this->bundleRoot . '/' . $bundleId . '.tar.gz';
        if (($bundle['bundle_id'] ?? '') !== $bundleId || !is_file($archive)) {
            throw new \RuntimeException('Managed Asterisk bundle is missing');
        }
        $bundle['archive_path'] = $archive;
        $this->assertHash($archive,(string)($bundle['archive_sha256'] ?? ''),'Stored Asterisk archive');
        return $bundle;
    }

    /** Legacy captures must prove their complete payload before duplicate deletion. */
    private function verifiedBundleRuntimeHash(array $bundle): string
    {
        $this->assertHash((string)$bundle['archive_path'],(string)$bundle['archive_sha256'],'Stored Asterisk archive');
        $tmp = $this->temporaryDirectory('verify-duplicate');
        try {
            $listing = $this->mustRun(['/usr/bin/tar','--list','--verbose','--numeric-owner','--quoting-style=escape','--gzip','--file',$bundle['archive_path']]);
            $this->assertSafeArchive($listing['stdout']);
            $this->mustRun(['/usr/bin/tar','--extract','--gzip','--file',$bundle['archive_path'],'--directory',$tmp]);
            $this->assertNoLinks($tmp);
            $root = $tmp . '/runtime';
            if (!is_file($root . '/usr/sbin/asterisk') || !is_dir($root . '/usr/lib/asterisk/modules')) {
                throw new \RuntimeException('Duplicate capture has an incomplete runtime');
            }
            return $this->manifestHash($this->runtimeManifest($root,$root . '/usr/lib/asterisk/modules'));
        } finally { $this->removeTree($tmp,$this->stateRoot); }
    }

    /** @return array<int,array<string,mixed>> */
    private function incoming(): array
    {
        $items = [];
        foreach (glob($this->incomingRoot . '/*.upload') ?: [] as $path) {
            $id = basename($path, '.upload');
            if (!preg_match('/^[a-f0-9]{32}$/D', $id) || is_link($path) || !is_file($path)) { continue; }
            $meta = $this->readJson($this->incomingRoot . '/' . $id . '.json', false);
            $items[] = [
                'incoming_id'=>$id,
                'filename'=>(string)($meta['filename'] ?? 'staged-upload'),
                'declared_version'=>(string)($meta['declared_version'] ?? ''),
                'source_site'=>(string)($meta['source_site'] ?? ''),
                'size_bytes'=>filesize($path),
                'sha256'=>hash_file('sha256', $path),
                'received_at'=>(int)($meta['received_at'] ?? filemtime($path)),
            ];
        }
        usort($items, static fn(array $a, array $b): int => $b['received_at'] <=> $a['received_at']);
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function buildStates(array $bundles): array
    {
        $states = [];
        foreach (PatchCatalog::entries() as $entry) {
            $workspace = $this->workspace($entry);
            $built = false;
            foreach ($bundles as $bundle) {
                if (($bundle['catalog_id'] ?? '') === $entry['catalog_id']) { $built = true; break; }
            }
            $states[] = [
                'catalog_id'=>$entry['catalog_id'],
                'asterisk_version'=>$entry['asterisk_version'],
                'downloaded'=>is_file($workspace . '/download.json'),
                'patched'=>is_file($workspace . '/stage.json'),
                'built'=>$built,
            ];
        }
        return $states;
    }

    /** @return array<string,mixed> */
    private function runtime(): array
    {
        $running = $this->asteriskCli('core show version');
        $installed = $this->run(['/usr/sbin/asterisk','-V']);
        $patch = $this->asteriskCli('core show application SIPCiscoPage');
        $sipSettings = $this->asteriskCli('sip show settings');
        $settings = $this->asteriskCli('core show settings');
        $runningVersion = $this->parseVersion($running['stdout'] . "\n" . $running['stderr']);
        $installedVersion = $this->parseVersion($installed['stdout'] . "\n" . $installed['stderr']);
        $moduleDir = '';
        if (preg_match('/^\s*Module directory:\s*(\S.*?)\s*$/mi', $settings['stdout'], $match)) {
            $moduleDir = trim($match[1]);
        }
        if ($moduleDir === '' || !is_dir($moduleDir)) { $moduleDir = $this->runtimeRoot . '/usr/lib/asterisk/modules'; }
        $binaryPath = $this->runtimeRoot . '/usr/sbin/asterisk';
        return [
            'running_version'=>$runningVersion,
            'installed_version'=>$installedVersion,
            'running'=>$runningVersion !== '',
            'binary_path'=>$binaryPath,
            'installed_binary_sha256'=>is_file($binaryPath) ? hash_file('sha256', $binaryPath) : '',
            'runtime_sha256'=>$this->manifestHash($this->runtimeManifest($this->runtimeRoot,$moduleDir)),
            'module_directory'=>$moduleDir,
            'chan_sip_loaded'=>$sipSettings['code'] === 0
                && stripos($sipSettings['stdout'], 'Global Settings:') !== false
                && !preg_match('/not registered|not found|no such|unknown command|unable to connect|connection refused/i', $sipSettings['stdout']),
            'usecallmanager_detected'=>str_contains($patch['stdout'], 'SIPCiscoPage') && $patch['code'] === 0,
        ];
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function runtimeManifest(string $root,string $moduleDir): array
    {
        $files = [];
        $binary = $root . '/usr/sbin/asterisk';
        if (is_file($binary)) { $files['usr/sbin/asterisk'] = ['sha256'=>hash_file('sha256',$binary),'size'=>filesize($binary)]; }
        if (is_dir($moduleDir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleDir,\FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile()) { continue; }
                $relative = str_replace('\\','/',substr($file->getPathname(),strlen($moduleDir) + 1));
                $files['usr/lib/asterisk/modules/' . $relative] = ['sha256'=>hash_file('sha256',$file->getPathname()),'size'=>$file->getSize()];
            }
        }
        foreach ($this->runtimeLibraries($root) as $path) {
            if (is_file($path)) { $files['usr/lib/' . basename($path)] = ['sha256'=>hash_file('sha256',$path),'size'=>filesize($path)]; }
        }
        ksort($files,SORT_STRING);
        return $files;
    }

    private function manifestHash(array $manifest): string
    {
        return hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function runtimeLibraries(string $root): array
    {
        $files = [];
        $directory = realpath($root . '/usr/lib');
        foreach (glob($root . '/usr/lib/libasterisk*.so*') ?: [] as $path) {
            if (!preg_match('/\Alibasterisk[A-Za-z0-9_-]*\.so(?:\.[0-9]+)*\z/D',basename($path))) { continue; }
            if (is_link($path)) {
                $target = realpath($path);
                if ($target === false || dirname($target) !== $directory || !is_file($target)
                    || !preg_match('/\Alibasterisk[A-Za-z0-9_-]*\.so(?:\.[0-9]+)*\z/D',basename($target))) {
                    throw new \RuntimeException('Asterisk library alias points outside the managed library set');
                }
                // SONAME aliases are generated links; hash/copy each real library once.
                continue;
            }
            if (is_file($path)) { $files[] = $path; }
        }
        return $files;
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function asteriskCli(string $command): array
    {
        return $this->run([
            '/usr/bin/timeout', '--signal=KILL', '5',
            '/usr/sbin/asterisk', '-rx', $command,
        ]);
    }

    /** @return array{healthy:bool,message:string,runtime:array<string,mixed>} */
    private function waitForRuntime(string $expectedVersion): array
    {
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $runtime = $this->runtime();
            if (
                $runtime['running']
                && hash_equals($expectedVersion, (string)$runtime['running_version'])
                && $runtime['chan_sip_loaded']
            ) {
                return ['healthy'=>true,'message'=>'Asterisk is running','runtime'=>$runtime];
            }
            usleep(1000000);
        }
        return [
            'healthy'=>false,
            'message'=>"Asterisk did not start as expected version $expectedVersion with chan_sip loaded",
            'runtime'=>$this->runtime(),
        ];
    }

    /** @return array<int,string> */
    private function heldPackages(): array
    {
        $result = $this->run(['/usr/bin/apt-mark','showhold']);
        $holds = array_values(array_filter(array_map('trim', preg_split('/\R/', $result['stdout']) ?: [])));
        sort($holds, SORT_STRING);
        return $holds;
    }

    /** @return array<string,mixed> */
    private function catalog(array $request): array
    {
        $catalogId = trim((string)($request['catalog_id'] ?? ''));
        $entry = PatchCatalog::find($catalogId);
        if (!$entry || empty($entry['module_compatible'])) {
            throw new \InvalidArgumentException('Choose one of the listed USECALLMANAGER-compatible Asterisk releases');
        }
        return $entry;
    }

    private function bundleId(array $request): string
    {
        return trim((string)($request['bundle_id'] ?? ''));
    }

    /** @return array{high_strength_srtp:bool,jobs:int} */
    private function options(array $request): array
    {
        $jobs = filter_var($request['jobs'] ?? 0, FILTER_VALIDATE_INT);
        if (!is_int($jobs) || $jobs < 1 || $jobs > 16) {
            $processors = (int)trim($this->run(['/usr/bin/nproc'])['stdout']);
            $jobs = max(1, min(4, $processors ?: 2));
        }
        return [
            'high_strength_srtp'=>filter_var(
                $request['high_strength_srtp'] ?? false,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            ) === true,
            'jobs'=>$jobs,
        ];
    }

    /** @return array<int,string> */
    private function commands(array $entry, array $options): array
    {
        $version = (string)$entry['asterisk_version'];
        $commands = [
            "tar --extract --gzip --file {$entry['source_filename']}",
            "cd asterisk-$version",
            "patch --strip=1 < ../{$entry['patch_filename']}",
            $this->displayCommand(array_merge(['./configure'], (array)$entry['configure_args'])),
        ];
        if ($options['high_strength_srtp']) {
            $commands[] = 'make menuselect.makeopts';
            $commands[] = './menuselect/menuselect --enable ENABLE_SRTP_AES_256 menuselect.makeopts';
            $commands[] = './menuselect/menuselect --enable ENABLE_SRTP_AES_GCM menuselect.makeopts';
        }
        $commands[] = 'make -j' . $options['jobs'] . ' all';
        $commands[] = 'make install DESTDIR=<version-bundle-staging>';
        return $commands;
    }

    private function downloadOne(string $url, string $destination, string $sha256): void
    {
        if (is_file($destination) && hash_equals($sha256, hash_file('sha256', $destination))) { return; }
        $tmp = $destination . '.tmp-' . bin2hex(random_bytes(6));
        try {
            $this->mustRun([
                '/usr/bin/curl','--fail','--location','--proto','=https',
                '--connect-timeout','15','--max-time','900',
                '--output',$tmp,$url,
            ]);
            $this->assertHash($tmp, $sha256, basename($destination));
            if (!@chmod($tmp, 0600) || !@rename($tmp, $destination)) {
                throw new \RuntimeException('Could not commit downloaded artifact');
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function assertHash(string $path, string $expected, string $label): void
    {
        if (!is_file($path) || !hash_equals($expected, hash_file('sha256', $path))) {
            throw new \RuntimeException("$label SHA-256 does not match its catalog entry");
        }
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function mustRun(array $command, ?string $cwd = null, string $log = ''): array
    {
        $result = $this->run($command, $cwd, $log);
        if ($result['code'] !== 0) {
            $detail = trim($result['stderr'] . "\n" . $result['stdout']);
            throw new \RuntimeException(
                'Command failed: ' . $this->displayCommand($command)
                . ($detail !== '' ? "\n" . substr($detail, -12000) : '')
            );
        }
        return $result;
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function run(array $command, ?string $cwd = null, string $log = ''): array
    {
        if ($this->runner) {
            $result = ($this->runner)($command, $cwd, $log);
            return is_array($result)
                ? ['code'=>(int)($result['code'] ?? 0),'stdout'=>(string)($result['stdout'] ?? ''),'stderr'=>(string)($result['stderr'] ?? '')]
                : ['code'=>0,'stdout'=>(string)$result,'stderr'=>''];
        }
        $pipes = [];
        $output = $log === '' ? tmpfile() : null;
        $errors = $log === '' ? tmpfile() : null;
        if ($log === '' && (!is_resource($output) || !is_resource($errors))) {
            if (is_resource($output)) { fclose($output); }
            if (is_resource($errors)) { fclose($errors); }
            return ['code'=>127,'stdout'=>'','stderr'=>'Could not create private command output streams'];
        }
        $spec = $log !== ''
            ? [['file','/dev/null','r'],['file',$log,'a'],['file',$log,'a']]
            : [['file','/dev/null','r'],$output,$errors];
        $process = @proc_open(
            $command,
            $spec,
            $pipes,
            $cwd,
            ['LC_ALL'=>'C','PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'],
            ['bypass_shell'=>true]
        );
        if (!is_resource($process)) {
            if (is_resource($output)) { fclose($output); fclose($errors); }
            return ['code'=>127,'stdout'=>'','stderr'=>'Could not start command'];
        }
        // Separate private files let both output streams drain concurrently.
        // Reading stdout before a full stderr pipe could otherwise deadlock.
        $code = proc_close($process);
        $stdout = ''; $stderr = '';
        if ($log !== '') { $stdout = $this->tail($log); }
        else {
            rewind($output); rewind($errors);
            $stdout = (string)stream_get_contents($output,4194305);
            $stderr = (string)stream_get_contents($errors,65537);
            fclose($output); fclose($errors);
            if (strlen($stdout)>4194304 || strlen($stderr)>65536) {
                return ['code'=>$code ?: 75,'stdout'=>substr($stdout,0,4194304),'stderr'=>'Command output exceeded its safe response bound'];
            }
        }
        return ['code'=>$code,'stdout'=>$stdout,'stderr'=>$stderr];
    }

    private function displayCommand(array $command): string
    {
        return implode(' ', array_map(static function ($argument): string {
            $argument = (string)$argument;
            return preg_match('/^[A-Za-z0-9_.,:\/=+-]+$/D', $argument)
                ? $argument
                : "'" . str_replace("'", "'\\''", $argument) . "'";
        }, $command));
    }

    private function ensureDirectories(): void
    {
        $this->ensureDirectory($this->stateRoot, 0700);
        $this->ensureDirectory($this->stateRoot . '/workspaces', 0700);
        $this->ensureDirectory($this->bundleRoot, 0750, 'asterisk');
        $this->ensureDirectory($this->incomingRoot, 0770, 'asterisk', 'asterisk');
    }

    private function ensureDirectory(string $path, int $mode, string $group = 'root', string $owner = 'root'): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("Could not create $path");
        }
        @chown($path, $owner);
        @chgrp($path, $group);
        @chmod($path, $mode);
    }

    private function workspace(array $entry): string
    {
        return $this->stateRoot . '/workspaces/' . $entry['catalog_id'];
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = $this->stateRoot . '/.' . $prefix . '-' . bin2hex(random_bytes(8));
        $this->ensureDirectory($path, 0700);
        return $path;
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $path = $this->stateRoot . '/state.json';
        return $this->readJson($path, file_exists($path) || is_link($path)) + [
            'active_bundle_id'=>'',
            'pinned_bundle_id'=>'',
            'history'=>[],
        ];
    }

    private function writeState(array $state): void
    {
        $state['schema_version'] = 1;
        $state['updated_at'] = time();
        $this->atomicJson($this->stateRoot . '/state.json', $state, 0600);
    }

    private function publishInventory(array $status): void
    {
        $public = $status;
        foreach ($public['bundles'] as &$bundle) { unset($bundle['archive_path']); }
        unset($bundle);
        $this->atomicJson($this->bundleRoot . '/inventory.json', $public, 0640, 'asterisk');
    }

    /** @return array<string,mixed> */
    private function readJson(string $path, bool $required = true): array
    {
        if (!is_file($path) || is_link($path)) {
            if (!$required) { return []; }
            throw new \RuntimeException('Required manager metadata is missing');
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || array_is_list($decoded)) {
            if (!$required) { return []; }
            throw new \RuntimeException('Manager metadata is invalid');
        }
        return $decoded;
    }

    private function atomicJson(string $path, array $value, int $mode, string $group = 'root'): void
    {
        $directory = dirname($path);
        $directoryMode = str_starts_with($directory, rtrim($this->stateRoot, '/') . '/')
            || $directory === $this->stateRoot
            ? 0700
            : 0750;
        $this->ensureDirectory($directory, $directoryMode, $group);
        $tmp = tempnam($directory, '.ucmp-json-');
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (!$tmp || file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
            if ($tmp) { @unlink($tmp); }
            throw new \RuntimeException('Could not stage manager metadata');
        }
        @chown($tmp, 'root');
        @chgrp($tmp, $group);
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not commit manager metadata');
        }
    }

    private function copyFile(string $source, string $destination, int $mode): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new \RuntimeException('Bundle source file is missing or unsafe');
        }
        $this->ensureDirectory(dirname($destination), 0755);
        if (!@copy($source, $destination) || !@chmod($destination, $mode)
            || !hash_equals((string)hash_file('sha256',$source),(string)hash_file('sha256',$destination))) {
            throw new \RuntimeException('Could not copy an Asterisk runtime file');
        }
        @chown($destination, 'root');
        @chgrp($destination, 'root');
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source) || is_link($source)) {
            throw new \RuntimeException('Bundle source directory is missing or unsafe');
        }
        $this->ensureDirectory($destination, 0755);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) { throw new \RuntimeException('Symlinks are not accepted in Asterisk bundles'); }
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . '/' . str_replace('\\', '/', $relative);
            if ($item->isDir()) {
                $this->ensureDirectory($target, 0755);
            } elseif ($item->isFile()) {
                $this->copyFile($item->getPathname(), $target, $item->isExecutable() ? 0755 : 0644);
            }
        }
    }

    private function removeTree(string $path, string $requiredParent): void
    {
        if ($path === '' || !str_starts_with($path, rtrim($requiredParent, '/') . '/')) {
            throw new \RuntimeException('Refusing an unsafe manager cleanup path');
        }
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) { return; }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) { @unlink($item->getPathname()); }
            elseif ($item->isDir()) { @rmdir($item->getPathname()); }
        }
        @rmdir($path);
    }

    private function assertSafeArchive(string $listing): void
    {
        $entries = array_values(array_filter(preg_split('/\R/', trim($listing)) ?: []));
        if (!$entries || count($entries) > 2000 || strlen($listing) > 1048576) {
            throw new \RuntimeException('Uploaded Asterisk bundle has an invalid file list');
        }
        $seen=[];$bytes=0;
        foreach ($entries as $line) {
            // GNU tar, LC_ALL=C, numeric owners. Reject links, devices, FIFOs,
            // escaped names and oversized expansion before root extracts anything.
            if (!preg_match('/^([-d])[rwxStTs-]{9}\s+\d+\/\d+\s+(\d+)\s+\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}\s+([A-Za-z0-9_.\/-]+)$/D',$line,$match)) {
                throw new \RuntimeException('Asterisk bundle contains a link, special file or unsupported archive entry');
            }
            $entry=rtrim($match[3],'/');$size=(int)$match[2];
            if ($entry === '' || str_starts_with($entry, '/') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $entry)
                || str_contains($entry,'//') || isset($seen[$entry])) {
                throw new \RuntimeException('Uploaded Asterisk bundle contains an unsafe path');
            }
            $seen[$entry]=true;
            if ($entry!=='manifest.json' && $entry!=='runtime' && !str_starts_with($entry, 'runtime/')) {
                throw new \RuntimeException('Uploaded archive is not a UCM Provisioner Asterisk bundle');
            }
            if (($entry==='manifest.json'&&($match[1]!=='-'||$size>1048576))
                || ($entry==='runtime'&&$match[1]!=='d') || $size>self::MAX_UPLOAD_BYTES || ($bytes+=$size)>8589934592) {
                throw new \RuntimeException('Asterisk bundle exceeds safe extraction bounds');
            }
        }
    }

    private function assertNoLinks(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || (!$item->isFile() && !$item->isDir())) { throw new \RuntimeException('Asterisk bundle links and special files are not accepted'); }
        }
    }

    private function findFile(string $root, string $name): string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink() && $item->getFilename() === $name) {
                return $item->getPathname();
            }
        }
        return '';
    }

    /** @return array<int,string> */
    private function findLibraries(string $root): array
    {
        $libraries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink() && str_starts_with($item->getFilename(), 'libasterisk')) {
                $libraries[] = $item->getPathname();
            }
        }
        return $libraries;
    }

    private function tail(string $path, int $bytes = 24000): string
    {
        if (!is_file($path)) { return ''; }
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if (!$handle) { return ''; }
        if ($size > $bytes) { fseek($handle, -$bytes, SEEK_END); }
        $content = stream_get_contents($handle);
        fclose($handle);
        return (string)$content;
    }

    private function parseVersion(string $output): string
    {
        return preg_match('/\bAsterisk\s+([0-9]+(?:\.[0-9]+){1,3}(?:[-+~][A-Za-z0-9._-]+)?)/i', $output, $match)
            ? $match[1]
            : '';
    }
}
