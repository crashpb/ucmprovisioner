<?php
/**
 * Transactional storage boundary for phone-visible files.
 *
 * This class reads and writes each site's SFTP-backed TFTP volume, publishes
 * configuration generations, manages wallpaper/media/timezone companions,
 * enforces safe paths and permissions, and prepares bounded browser downloads.
 * It stores resolved bytes; template selection and device policy belong to the
 * services that call it.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/TftpTransferOperations.php';
require_once __DIR__ . '/ReplicationActivityService.php';
require_once __DIR__ . '/RpcOperationException.php';
require_once __DIR__ . '/AssetFilename.php';
require_once __DIR__ . '/FirmwareService.php';

class TftpStore
{
    use TftpTransferOperations;
    private const PUBLIC_DIRECTORY_MODE = 0755;
    private const PRIVATE_DIRECTORY_MODE = 0770;
    private const MAX_TEXT_READ_BYTES = 2 * 1024 * 1024;
    private const MAX_BASE64_READ_BYTES = 8 * 1024 * 1024;
    private const MAX_SCAN_DIRECTORIES = 2048;
    private const MAX_SCAN_ENTRIES_PER_DIRECTORY = 10000;
    private const MAX_SCAN_DEPTH = 12;
    private const MAX_SCAN_FILE_BYTES = 512 * 1024 * 1024;
    private const MAX_SCAN_SECONDS = 8;
    private const MAX_TIMEZONE_FILE_BYTES = 16 * 1024 * 1024;
    private const MAX_GENERATION_FILES = 5000;
    private const MAX_GENERATION_BYTES = 64 * 1024 * 1024;
    private const MAX_PERMISSION_AUDIT_DIRECTORIES = 2048;
    private const MAX_PERMISSION_AUDIT_ENTRIES = 50000;
    private const MAX_DOWNLOAD_SELECTIONS = 100;
    private const MAX_DOWNLOAD_FILES = 5000;
    private const MAX_DOWNLOAD_BYTES = 1073741824;
    private const MAX_DOWNLOAD_DEPTH = 12;
    private const WALLPAPER_SET_ROOT = '.ucmprovisioner/wallpaper-sets';

    private $ucm;
    private static array $siteLocks = [];

    /** Destination-side exclusion also covers writers on another controller. */
    public function withSiteLock(int $siteId, callable $operation, ?string $mode = null, array $context = [])
    {
        $conn = $this->ucm->siteConnection($siteId);
        $sftp = $mode === null ? $conn->dockerSftp() : $conn->tftpSftp($mode);
        $root = rtrim($mode === null ? $conn->tftpRoot() : $conn->tftpRoot($mode), '/');
        // The same site/root may refer to two backends during a cutover.
        $key = $siteId . ':' . spl_object_id($sftp) . ':' . $root;
        if (isset(self::$siteLocks[$key])) { return $operation(); }
        $this->ensureRemoteDirectory($sftp, $root . '/.ucmprovisioner');
        $lock = $root . '/.ucmprovisioner/write-lock';
        $occupied = $this->remoteExists($sftp, $lock);
        if (!$occupied && !$sftp->mkdir($lock, 0770, false)) {
            $occupied = $this->remoteExists($sftp, $lock);
            if (!$occupied) { throw new \RuntimeException('Cannot create destination storage lock; verify storage access and permissions'); }
        }
        if ($occupied) {
            throw new RpcOperationException('Destination storage is busy; no writes started. If it remains busy, inspect its retained lock and receipts.',
                ['ok'=>false, 'status'=>'busy', 'mutation_started'=>false]);
        }
        $owner = ['nonce'=>bin2hex(random_bytes(16)), 'started'=>time(), 'pid'=>getmypid(), 'controller'=>hash('sha256', (string)gethostname())];
        if ($context) { $owner['context'] = array_intersect_key($context, array_flip(['operation','source_mode','target_mode','plan_hash'])); }
        $ownerBytes = json_encode($owner, JSON_THROW_ON_ERROR);
        self::$siteLocks[$key] = ['nonce'=>$owner['nonce'], 'sftp_id'=>spl_object_id($sftp), 'root'=>$root, 'path'=>$lock, 'recovery'=>[]];
        $retainLock = false;
        $result = null;
        $failure = null;
        try {
            if (!$sftp->put($lock . '/owner.json', $ownerBytes)
                || !$sftp->chmod(0600, $lock . '/owner.json')
                || !hash_equals($ownerBytes, (string)$sftp->get($lock . '/owner.json'))) {
                throw new \RuntimeException('Destination lock ownership could not be recorded; inspect recovery status before retrying');
            }
            $result = $operation();
            if (is_array($result) && (!empty($result['recovery_required']) || ($result['status'] ?? '') === 'recovery_required')) {
                // Retain the exact owner receipt until unresolved publication is reconciled.
                $retainLock = true;
            }
        } catch (\Throwable $error) {
            $failure = $error;
            if ($error instanceof RpcOperationException && !empty($error->receipt['recovery_required'])) {
                $retainLock = true;
            }
        } finally {
            $recovery = self::$siteLocks[$key]['recovery'];
            if ($recovery) {
                $retainLock = true;
                $result = is_array($result) ? $result : ['ok'=>false, 'message'=>$failure?->getMessage() ?? 'File replacement requires reconciliation'];
                $result['operation_ok'] ??= !empty($result['ok']);
                $result['ok'] = false;
                $result['status'] = 'recovery_required';
                $result['recovery_required'] = true;
                $result['file_replacements'] = array_values($recovery);
                $result['message'] = trim((string)($result['message'] ?? '') . ' File replacement receipts and the writer lock were retained; reconcile before retrying.');
            }
            unset(self::$siteLocks[$key]);
            try {
                $observed = $retainLock ? null : $sftp->get($lock . '/owner.json');
                if (!$retainLock && (!is_string($observed) || !hash_equals($ownerBytes, $observed)
                    || !$sftp->delete($lock . '/owner.json') || !$sftp->rmdir($lock))) {
                    throw new \RuntimeException('Destination lock release is unverified');
                }
            } catch (\Throwable $error) {
                $message = 'Destination lock release is unverified; inspect the operation receipt and lock status before retrying';
                if (!is_array($result)) { throw new \RuntimeException($message, 0, $error); }
                $result['operation_ok'] ??= !empty($result['ok']);
                $result['ok'] = false;
                $result['recovery_required'] = true;
                $result['lock_release_error'] = $message;
                $result['message'] = trim((string)($result['message'] ?? '') . ' ' . $message);
            }
        }
        if ($failure !== null) {
            if ($recovery) { throw new RpcOperationException((string)$result['message'], $result); }
            throw $failure;
        }
        return $result;
    }

    /** Keep destination exclusion even if an intermediate caller catches the failure. */
    private function retainReplacementFailure($sftp, array $receipt, \Throwable $error): never
    {
        foreach (self::$siteLocks as &$lock) {
            if ($lock['sftp_id'] !== spl_object_id($sftp)) { continue; }
            foreach (array_keys($receipt['files']) as $path) {
                if (str_starts_with($path, $lock['root'] . '/')) { $lock['recovery'][$receipt['transaction_id']] = $receipt; break; }
            }
        }
        unset($lock);
        throw new RpcOperationException($error->getMessage() . '; replacement state was retained for reconciliation',
            ['ok'=>false, 'status'=>'recovery_required', 'recovery_required'=>true, 'message'=>$error->getMessage(), 'file_replacements'=>[$receipt]]);
    }

    /** A consistent order protects both copy sources and destinations. */
    public function withSiteLocks(array $siteIds, callable $operation)
    {
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        if (!$siteIds || min($siteIds) < 1 || count($siteIds) > 21) { throw new \InvalidArgumentException('Choose one to twenty-one valid lock sites'); }
        sort($siteIds, SORT_NUMERIC);
        $next = function (int $index) use (&$next, $siteIds, $operation) {
            return $index >= count($siteIds) ? $operation() : $this->withSiteLock($siteIds[$index], fn() => $next($index + 1));
        };
        return $next(0);
    }

    /** Read-only: elapsed time never grants permission to remove a lock. */
    public function siteLockStatus(int $siteId): array
    {
        $conn = $this->ucm->siteConnection($siteId);
        $sftp = $conn->dockerSftp();
        $lock = rtrim($conn->tftpRoot(), '/') . '/.ucmprovisioner/write-lock';
        if (!$this->remoteExists($sftp, $lock)) { return ['ok'=>true, 'state'=>'unlocked', 'observed_at'=>time()]; }
        $owner = $this->readBounded($sftp, $lock . '/owner.json', 4096, 'destination lock owner');
        $decoded = !empty($owner['ok']) ? json_decode((string)($owner['content'] ?? ''), true) : null;
        return ['ok'=>true, 'state'=>'locked', 'observed_at'=>time(),
            'owner'=>is_array($decoded) ? array_intersect_key($decoded, array_flip(['nonce','started','pid','controller','context'])) : null,
            'message'=>'A writer may still be active. Stop and verify every controller writer, reconcile retained transaction receipts, then have an administrator remove only this exact lock. It is never cleared automatically.'];
    }

    public function __construct($ucm)
    {
        $this->ucm = $ucm;
    }

    /** Push a generated config file to a site's TFTP root. */
    public function putConfig(int $siteId, string $filename, string $content): array
    {
        if ($filename === '' || basename($filename) !== $filename
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $filename)) {
            return ['ok'=>false, 'filename'=>$filename, 'message'=>'Generated TFTP filename must be one safe basename'];
        }
        try { return $this->withSiteLock($siteId, fn() => $this->putConfigLocked($siteId, $filename, $content)); }
        catch (\Throwable $e) { return ['ok'=>false, 'filename'=>$filename, 'message'=>$e->getMessage()]; }
    }

    private function putConfigLocked(int $siteId, string $filename, string $content): array
    {
        try {
            if ($filename === '' || basename($filename) !== $filename
                || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $filename)) {
                throw new \InvalidArgumentException('Generated TFTP filename must be one safe basename');
            }
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $path = $conn->tftpRoot() . '/' . ltrim($filename, '/');
            $this->remotePathMetadata($sftp, rtrim($conn->tftpRoot(), '/'), $filename, [1]);
            // Stage and verify before replacing a phone-visible file.
            $this->writeRemoteFile($sftp, $path, $content, 0664);
            return [
                'ok'       => true,
                'path'     => $path,
                'filename' => $filename,
                'bytes'    => strlen($content),
                'message'  => "Wrote and verified $filename",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'filename' => $filename, 'message' => $e->getMessage()];
        }
    }

    /** Delete one generated per-phone config without weakening TFTP Browser ownership guards. */
    public function deletePhoneConfig(int $siteId, string $filename): array
    {
        if (preg_match('/\A(?:SEP[0-9A-F]{12}\.cnf\.xml|SIP[0-9A-F]{12}\.cnf|[0-9A-F]{12}\.cfg|cfg[0-9A-F]{12}\.xml)\z/Di', $filename) !== 1) {
            return ['ok'=>false, 'filename'=>$filename, 'message'=>'The generated phone-config filename is invalid'];
        }
        try { return $this->withSiteLock($siteId, fn() => $this->deletePhoneConfigLocked($siteId, $filename)); }
        catch (\Throwable $e) { return ['ok'=>false, 'filename'=>$filename, 'message'=>$e->getMessage()]; }
    }

    private function deletePhoneConfigLocked(int $siteId, string $filename): array
    {
        if (preg_match('/\A(?:SEP[0-9A-F]{12}\.cnf\.xml|SIP[0-9A-F]{12}\.cnf|[0-9A-F]{12}\.cfg|cfg[0-9A-F]{12}\.xml)\z/Di', $filename) !== 1) {
            return ['ok'=>false, 'filename'=>$filename, 'message'=>'The generated phone-config filename is invalid'];
        }
        try {
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $path = rtrim($conn->tftpRoot(), '/') . '/' . $filename;
            if (!$this->remoteExists($sftp, $path)) {
                $retired = $this->retireManagedPath($siteId, $filename);
                return [
                    'ok'=>true,
                    'filename'=>$filename,
                    'missing'=>true,
                    'message'=>'Phone config was already absent',
                    'registry_warning'=>$retired['warning'] ?? null,
                ];
            }
            if ($sftp->is_dir($path)) {
                return ['ok'=>false, 'filename'=>$filename, 'message'=>'Refusing to delete a directory as a phone config'];
            }
            if (!$sftp->delete($path)) {
                return ['ok'=>false, 'filename'=>$filename, 'message'=>"Could not delete $filename from the site TFTP volume"];
            }
            $retired = $this->retireManagedPath($siteId, $filename);
            return [
                'ok'=>true,
                'filename'=>$filename,
                'message'=>'Phone config deleted',
                'registry_warning'=>$retired['warning'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'filename'=>$filename, 'message'=>$e->getMessage()];
        }
    }

    /**
     * Recover legacy Cisco MAC candidates from deterministic SIP<MAC>.cnf filenames.
     *
     * Some registrations omit a device name, while the stored CNF filename and line1_name still
     * identify the physical phone and extension. More than one historical file may name the same
     * extension, so callers may auto-select only a single unambiguous candidate.
     *
     * @return array<string,string[]>
     */
    public function scanLegacyMacsByExtension(int $siteId): array
    {
        $conn = $this->ucm->siteConnection($siteId);
        $sftp = $conn->dockerSftp();
        $root = $conn->tftpRoot();
        $out = [];
        $add = static function (array &$map, string $ext, string $mac): void {
            if ($ext === '') { return; }
            $map[$ext] = $map[$ext] ?? [];
            if (!in_array($mac, $map[$ext], true)) { $map[$ext][] = $mac; }
        };
        foreach ((array) $sftp->nlist($root) as $name) {
            $family = '';
            if (preg_match('/^SIP([0-9A-Fa-f]{12})\.cnf$/i', $name, $m)) {
                $family = 'cisco_cnf';
            } elseif (preg_match('/^SEP([0-9A-Fa-f]{12})\.cnf\.xml$/i', $name, $m)) {
                $family = 'cisco_xml';
            } elseif (preg_match('/^([0-9A-Fa-f]{12})\.cfg$/i', $name, $m)) {
                $family = 'yealink';
            } elseif (preg_match('/^cfg([0-9A-Fa-f]{12})\.xml$/i', $name, $m)) {
                $family = 'grandstream';
            } else {
                continue;
            }
            $mac = strtoupper($m[1]);
            $content = $sftp->get($root . '/' . $name);
            if ($content === false) {
                continue;
            }
            $text = (string)$content;
            $ext = '';
            if ($family === 'cisco_cnf' && preg_match('/^\s*line1_name\s*:\s*"?(\d{2,20})"?/mi', $text, $line)) {
                $ext = $line[1];
            } elseif ($family === 'cisco_xml') {
                if (preg_match('#<line\b[^>]*>.*?<featureID>\s*9\s*</featureID>.*?<name>\s*(\d{2,20})\s*</name>#is', $text, $line)
                    || preg_match('#<authName>\s*(\d{2,20})\s*</authName>#i', $text, $line)) {
                    $ext = $line[1];
                }
            } elseif ($family === 'yealink' && preg_match('/^\s*account\.1\.user_name\s*=\s*(\d{2,20})\s*$/mi', $text, $line)) {
                $ext = $line[1];
            } elseif ($family === 'grandstream'
                && (preg_match('#<P35>\s*(\d{2,20})\s*</P35>#i', $text, $line)
                    || preg_match('#<P4060>\s*(\d{2,20})\s*</P4060>#i', $text, $line))) {
                $ext = $line[1];
            }
            $add($out, $ext, $mac);
        }
        return $out;
    }

    /** Push SIPDefault.cnf + syncinfo.xml for legacy Cisco fleet maintenance. */
    public function putLegacyCiscoGlobals(int $siteId, string $sipDefault, string $syncToken): array
    {
        return $this->withSiteLock($siteId, fn() => $this->putLegacyCiscoGlobalsLocked($siteId, $sipDefault, $syncToken));
    }

    private function putLegacyCiscoGlobalsLocked(int $siteId, string $sipDefault, string $syncToken): array
    {
        $cnf = $this->putConfig($siteId, 'SIPDefault.cnf', $sipDefault);
        $sync = $this->putConfig(
            $siteId,
            'syncinfo.xml',
            CiscoCnfGenerator::syncInfoXml($syncToken)
        );
        return ['sip_default' => $cnf, 'syncinfo' => $sync];
    }

    /** Bump legacy Cisco sync token (forces reboot on next check-sync NOTIFY). */
    public function bumpLegacySync(int $siteId): string
    {
        return $this->withSiteLock($siteId, fn() => $this->bumpLegacySyncLocked($siteId));
    }

    private function bumpLegacySyncLocked(int $siteId): string
    {
        $token = (string) time();
        $published=$this->putConfig($siteId, 'syncinfo.xml', CiscoCnfGenerator::syncInfoXml($token));
        if (empty($published['ok'])) { throw new \RuntimeException((string)($published['message'] ?? 'Legacy sync publication failed')); }
        try {
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $root = $conn->tftpRoot();
            // Best effort: update the sync token in SIPDefault.cnf when the
            // existing shared file is readable.
            $defaultPath = $root . '/SIPDefault.cnf';
            $cur = $sftp->get($defaultPath);
            if (is_string($cur) && $cur !== '') {
                $new = preg_replace('/^sync:\s*"?[^"\n]*"?/m', 'sync: "' . $token . '"', $cur, 1, $count);
                if ($count === 0) {
                    $new = rtrim($cur) . "\nsync: \"{$token}\"\n";
                }
                if ($sftp->put($defaultPath, $new)) {
                    $sftp->chmod(0664, $defaultPath);
                }
            }
        } catch (\Throwable $e) {
            // The required syncinfo.xml was verified; the shared-file token is optional.
        }
        return $token;
    }

    /**
     * AJAX handler for the TFTP browser tab.
     * ops: list, get, put, delete, mkdir
     */
    public function handle(array $req): array
    {
        $siteId = (int)($req['site_id'] ?? $this->ucm->localSiteId());
        $op = (string)($req['op'] ?? 'list');
        try {
            $this->sanitizeRel((string)($req['path'] ?? ''));
            if ($op === 'reset_except_phone_configs' && !empty($req['apply']) && ($req['confirm'] ?? '') !== 'PRESERVE_PHONE_CONFIGS') {
                throw new \InvalidArgumentException('Storage reset confirmation is invalid');
            }
            if ($op === 'lock_status') { return $this->siteLockStatus($siteId); }
            if (in_array($op, ['sync_cisco_media','sync_wallpaper_media'], true)) {
                // Payloads, retained sources and manifests must describe the same catalog.
                // Only the source is held here; each receiving PBX owns its commit lock.
                return $this->withSiteLock($siteId, fn() => $this->handleUnlocked($req));
            }
            if (in_array($op, ['sync_firmware_bundle','sync_cisco_locale_bundle','sync_timezone_version',
                'sync_timezone_bundle'], true)) {
                // Each receiving PBX owns publication; never hold its storage lock across an RPC.
                return $this->handleUnlocked($req);
            }
            $readOnly = in_array($op, ['list','get','get_base64','upload_limits','permission_audit','timezone_inventory','timezone_versions',
                'cisco_locale_inventory','firmware_inventory','managed_asset_inventory','asset_catalog','asset_selections','asset_validate_selection',
                'asset_validate_timezone','asset_retirement_preview','wallpaper_set_list','wallpaper_set_get',
                'wallpaper_profile_source_get','wallpaper_choices','scan_legacy'], true)
                || ($op === 'reset_except_phone_configs' && empty($req['apply']));
            if ($readOnly) { return $this->handleUnlocked($req); }
            if (in_array($op,['wallpaper_set_source','wallpaper_profile_source_save','wallpaper_profile_source_reset','put_media_pairs',
                'wallpaper_set_rename','wallpaper_set_delete','wallpaper_set_repair_encoding','wallpaper_set_repair_hosted_names'],true)) {
                return $this->withSiteLock($siteId,fn(): array => $this->handleWallpaperMutation($siteId,$req));
            }
            return $this->withSiteLocks(array_merge([$siteId], (array)($req['target_site_ids'] ?? [])), fn() => $this->handleUnlocked($req));
        } catch (RpcOperationException $e) { return $e->receipt + ['ok'=>false, 'site_id'=>$siteId, 'message'=>$e->getMessage()]; }
        catch (\Throwable $e) { return ['ok'=>false, 'site_id'=>$siteId, 'message'=>$e->getMessage()]; }
    }

    private function handleWallpaperMutation(int $siteId, array $req): array
    {
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(),'/');
        $id = self::wallpaperSetId((string)($req['set_id'] ?? ''));
        if (($req['op'] ?? '') !== 'wallpaper_set_source' || ($req['intent'] ?? '') !== 'create') {
            $manifest = $this->readWallpaperSetManifest($sftp,$root,$id);
            if (!hash_equals(hash('sha256',self::remoteJsonContent($manifest)),(string)($req['expected_revision'] ?? ''))) {
                return ['ok'=>false,'conflict'=>true,'message'=>'Wallpaper catalog changed or its editor revision is missing; reopen it before saving'];
            }
        }
        $result = $this->handleUnlocked($req);
        if (!empty($result['ok']) && ($req['op'] ?? '') !== 'wallpaper_set_delete') {
            try {
                $savedId = (string)($result['set_id'] ?? $id);
                $manifest = $this->readWallpaperSetManifest($sftp,$root,$savedId);
                $result['revision'] = hash('sha256',self::remoteJsonContent($manifest));
            } catch (\Throwable $error) {
                $result['ok']=false;$result['publication_completed']=true;$result['status']='partial';$result['recovery_required']=true;
                $result['message']='Catalog write completed, but its saved revision could not be read; reconcile before retrying: ' . $error->getMessage();
            }
        }
        return $result;
    }

    private function handleUnlocked(array $req): array
    {
        $op     = (string) ($req['op'] ?? 'list');
        $siteId = (int) ($req['site_id'] ?? $this->ucm->localSiteId());
        $path   = $this->sanitizeRel((string) ($req['path'] ?? ''));
        if ($op==='asset_restore') {
            try {
                return $this->withSiteLock($siteId,function () use ($req,$siteId): array {
                    $id=(string)($req['recovery_id']??'');
                    if (!preg_match('/^[0-9]{14}-[a-f0-9]{16}$/D',$id)) { throw new \InvalidArgumentException('Recovery ID is invalid'); }
                    $connection=$this->ucm->siteConnection($siteId);$sftp=$connection->dockerSftp();$root=rtrim($connection->tftpRoot(),'/');
                    $recovery=$root.'/.ucmprovisioner/retired-assets/'.$id;
                    $read=$this->readBounded($sftp,$recovery.'/receipt.json',8192,'asset recovery receipt');
                    $receipt=json_decode((string)($read['content']??''),true);
                    if (empty($read['ok'])||!is_array($receipt)||(int)($receipt['site_id']??0)!==$siteId) { throw new \RuntimeException('Recovery receipt does not belong to this site'); }
                    $asset=(new ManagedAssetRegistry($this->ucm->getDb()))->getById($siteId,(int)($receipt['asset_id']??0));
                    if (!$asset||($receipt['sha256']??'')!==$asset['content_sha256']||($receipt['relative_path']??'')!==$asset['relative_path']) { throw new \RuntimeException('Recovery receipt differs from the immutable registered asset'); }
                    $relative=$this->sanitizeRel($asset['relative_path']);$destination=$root.'/'.$relative;
                    if ($relative===''||str_starts_with($relative,'.ucmprovisioner/')||$sftp->file_exists($destination)) { throw new \RuntimeException('Recovery cannot overwrite an existing path'); }
                    $cursor=$root;
                    foreach (array_slice(explode('/',$relative),0,-1) as $part) {
                        $cursor.='/'.$part;$stat=$sftp->lstat($cursor);
                        if (!is_array($stat)||(int)($stat['type']??0)!==2) { throw new \RuntimeException('Recovery destination directory is missing or is a link'); }
                    }
                    if (!hash_equals($asset['content_sha256'],$this->retirementPayloadHash($sftp,$recovery.'/payload',(int)$asset['size_bytes']))) { throw new \RuntimeException('Retained recovery payload failed hash verification'); }
                    if (!$sftp->rename($recovery.'/payload',$destination)) { throw new \RuntimeException('Recovery move failed; retained payload was preserved'); }
                    $verified=false;
                    try { $verified=hash_equals($asset['content_sha256'],$this->retirementPayloadHash($sftp,$destination,(int)$asset['size_bytes'])); } catch (\Throwable) {}
                    if (!$verified) {
                        $retained=$sftp->rename($destination,$recovery.'/payload');
                        return ['ok'=>false,'recovery_id'=>$id,'message'=>$retained?'Restored-path verification failed; payload returned to recovery storage':'Restored-path verification failed; inspect the recovery receipt before retrying'];
                    }
                    $result=$this->registerManagedPayload($siteId,$relative,$asset['content_sha256'],(int)$asset['size_bytes'],$asset);
                    return ['ok'=>true,'message'=>'Exact retained asset restored without overwriting another file; recovery receipt retained.']+$result;
                });
            } catch (\Throwable $error) { return ['ok'=>false,'message'=>$error->getMessage()]; }
        }
        if (in_array($op,['asset_retirement_preview','asset_reassign','asset_retire'],true)) {
            try {
                $action=function () use ($req,$siteId,$op): array {
                    require_once __DIR__.'/AssetRetirementService.php';
                    $assetId=(int)($req['asset_id']??0);$replacement=(int)($req['replacement_asset_id']??0);
                    $storage=$this->assetReferenceSnapshot($siteId,$assetId);
                    $service=new AssetRetirementService($this->ucm->getDb());
                    $plan=$service->preview($siteId,$assetId,$storage,$replacement>0?$replacement:null);
                    if ($op==='asset_retirement_preview') { return ['ok'=>true,'preview'=>$plan]; }
                    $revision=(string)($req['expected_revision']??'');
                    if ($revision===''||!hash_equals($plan['revision'],$revision)) { throw new \RuntimeException('Asset or references changed; preview again'); }
                    if ($op==='asset_reassign') { return $service->reassign($siteId,$assetId,$replacement,$revision,$storage); }
                    if (!$plan['can_retire']) { throw new \RuntimeException('Asset is protected by references or incomplete verification'); }
                    $asset=(new ManagedAssetRegistry($this->ucm->getDb()))->getById($siteId,$assetId);
                    $connection=$this->ucm->siteConnection($siteId);$sftp=$connection->dockerSftp();$root=rtrim($connection->tftpRoot(),'/');
                    $relative=$this->sanitizeRel((string)$asset['relative_path']);
                    if ($relative===''||str_starts_with($relative,'.ucmprovisioner/')) { throw new \RuntimeException('Asset is not an eligible public TFTP file'); }
                    $source=$root.'/'.$relative;
                    if (!hash_equals($asset['content_sha256'],$this->retirementPayloadHash($sftp,$source,(int)$asset['size_bytes']))) { throw new \RuntimeException('Asset bytes changed; verify and preview again'); }
                    $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(8));$recovery=$root.'/.ucmprovisioner/retired-assets/'.$id;
                    $this->ensureTimezoneDirectory($sftp,$root,'.ucmprovisioner/retired-assets/'.$id);
                    if (!$sftp->chmod(0700,$recovery)) { throw new \RuntimeException('Private recovery directory permissions could not be verified'); }
                    $receipt=['schema'=>1,'site_id'=>$siteId,'asset_id'=>$assetId,'relative_path'=>$relative,'sha256'=>$asset['content_sha256'],'revision'=>$revision,'created'=>time()];
                    $receiptJson=json_encode($receipt,JSON_THROW_ON_ERROR);
                    if (!$sftp->put($recovery.'/receipt.json',$receiptJson)||!$sftp->chmod(0600,$recovery.'/receipt.json')) { throw new \RuntimeException('Recovery receipt could not be preserved; asset was not moved'); }
                    $receiptRead=$this->readBounded($sftp,$recovery.'/receipt.json',8192,'asset recovery receipt verification');
                    if (empty($receiptRead['ok'])||($receiptRead['content']??null)!==$receiptJson) { throw new \RuntimeException('Recovery receipt readback failed; asset was not moved'); }
                    if (!$sftp->rename($source,$recovery.'/payload')) { throw new \RuntimeException('Asset quarantine move failed; source was retained'); }
                    $verified=false;
                    try { $verified=hash_equals($asset['content_sha256'],$this->retirementPayloadHash($sftp,$recovery.'/payload',(int)$asset['size_bytes'])); } catch (\Throwable) {}
                    if (!$verified) {
                        $restored=$sftp->rename($recovery.'/payload',$source);
                        return ['ok'=>false,'recovery_id'=>$id,'message'=>$restored?'Quarantine verification failed; source restored':'Quarantine verification failed; retained payload requires recovery'];
                    }
                    $result=[];
                    try {
                        (new ManagedAssetRegistry($this->ucm->getDb()))->markSlotStale($siteId,$asset['kind'],$asset['filename'],$asset['profile'],$asset['bundle_key']);
                    } catch (\Throwable) { $result=['warning'=>'Payload is retained in quarantine, but its registry state could not be updated; verify inventory before further changes']; }
                    return ['ok'=>true,'recovery_id'=>$id,'quarantined_bytes'=>(int)$asset['size_bytes'],'reclaimed_bytes'=>0,'message'=>'Asset removed from phone-facing storage; exact bytes and recovery receipt retained privately.']+$result;
                };
                return $op==='asset_retirement_preview'?$action():$this->withSiteLock($siteId,$action);
            } catch (\Throwable $error) { return ['ok'=>false,'message'=>$error->getMessage()]; }
        }
        if ($op === 'register_copied_assets') {
            try { return $this->registerCopiedAssets($siteId, (array)($req['assets'] ?? [])); }
            catch (\Throwable $e) { return ['ok'=>false,'site_id'=>$siteId,'message'=>$e->getMessage()]; }
        }
        if ($path === '.ucmprovisioner' || str_starts_with($path, '.ucmprovisioner/')) {
            return ['ok'=>false, 'message'=>'The transaction ledger is internal and cannot be browsed or changed'];
        }

        if ($op === 'upload_limits') {
            $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
            $post = self::iniBytes((string) ini_get('post_max_size'));
            $limits = array_values(array_filter([$upload, $post], static fn($value) => $value > 0));
            return [
                'ok' => true,
                'upload_max_bytes' => $upload,
                'post_max_bytes' => $post,
                'effective_max_bytes' => $limits ? min($limits) : 0,
                'upload_max' => (string) ini_get('upload_max_filesize'),
                'post_max' => (string) ini_get('post_max_size'),
            ];
        }

        if ($op === 'reset_except_phone_configs') {
            try {
                return $this->resetExceptPhoneConfigs(
                    $siteId,
                    !empty($req['apply']),
                    (string)($req['confirm'] ?? '')
                );
            } catch (RpcOperationException $e) { throw $e;
            } catch (\Throwable $e) {
                return ['ok'=>false,'site_id'=>$siteId,'message'=>$e->getMessage()];
            }
        }

        if ($op === 'permission_audit' || $op === 'permission_repair') {
            try {
                return $this->auditPublishedPermissions($siteId, $op === 'permission_repair');
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'message'=>$e->getMessage(), 'issues'=>[]];
            }
        }

        if ($op === 'timezone_inventory' || $op === 'timezone_versions') {
            try {
                return $op === 'timezone_versions'
                    ? $this->timezoneVersionInventory($siteId)
                    : ['site_id'=>$siteId] + $this->timezoneInventory($siteId);
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'status'=>'unreachable', 'message'=>$e->getMessage()];
            }
        }

        if ($op === 'timezone_stage_payload') {
            try {
                return $this->stageTimezonePayloadCandidate(
                    $siteId,
                    (string)($req['profile'] ?? '')
                );
            } catch (RpcOperationException $e) { throw $e;
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'message'=>$e->getMessage()];
            }
        }

        if ($op === 'cisco_locale_inventory') {
            try {
                return $this->ciscoLocaleInventory(
                    $siteId,
                    (string)($req['user_locale'] ?? ''),
                    (string)($req['network_locale'] ?? '')
                );
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'status'=>'unreachable', 'profiles'=>[], 'message'=>$e->getMessage()];
            }
        }

        if ($op === 'sync_cisco_locale_bundle') {
            try {
                return $this->syncCiscoLocaleBundle(
                    $siteId,
                    (string)($req['user_locale'] ?? ''),
                    (string)($req['network_locale'] ?? ''),
                    (string)($req['profile'] ?? ''),
                    (array)($req['target_site_ids'] ?? []),
                    !empty($req['overwrite'])
                );
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'sites'=>[], 'message'=>$e->getMessage()];
            }
        }

        if (in_array($op, ['timezone_capture', 'timezone_quarantine', 'timezone_activate'], true)) {
            try {
                if ($op === 'timezone_capture') {
                    return $this->captureTimezoneBundle($siteId);
                }
                if ($op === 'timezone_quarantine') {
                    return $this->captureTimezoneBundle($siteId, [], true);
                }
                return $this->activateTimezoneBundle($siteId, (int)($req['bundle_id'] ?? 0));
            } catch (RpcOperationException $e) { throw $e;
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'message'=>$e->getMessage()];
            }
        }

        if ($op === 'timezone_adopt') {
            try {
                return $this->adoptTimezoneArchive($siteId, (string)($req['bundle_key'] ?? ''));
            } catch (RpcOperationException $e) { throw $e;
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'message'=>$e->getMessage()];
            }
        }

        if ($op === 'sync_timezone_version') {
            try {
                return $this->syncTimezoneVersion(
                    $siteId,
                    (int)($req['bundle_id'] ?? 0),
                    (array)($req['target_site_ids'] ?? [])
                );
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'sites'=>[], 'message'=>$e->getMessage()];
            }
        }

        if ($op === 'firmware_inventory' || $op === 'firmware_rescan') {
            try {
                $firmware = new FirmwareService($this->ucm);
                if ($op === 'firmware_rescan') {
                    $result = $firmware->rescan($siteId);
                    $result['asset_reconciliation'] = (new AssetLifecycleService($this->ucm->getDb()))
                        ->reconcileFirmware($siteId, (array)($result['bundles'] ?? []));
                    return $result;
                }
                $modelId = (int)($req['model_id'] ?? 0);
                $profile = trim((string)($req['profile'] ?? ''));
                $filter = $modelId > 0 ? $modelId : ($profile !== '' ? $profile : null);
                return ['ok'=>true, 'site_id'=>$siteId, 'bundles'=>$firmware->inventory($siteId, $filter)];
            } catch (\Throwable $e) {
                return ['ok'=>false, 'message'=>$e->getMessage()];
            }
        }

        if (in_array($op, ['managed_asset_inventory','managed_asset_rescan','managed_asset_scan_reset'], true)) {
            try {
                if ($op === 'managed_asset_scan_reset') {
                    $removed = (new ManagedAssetScanJob($this->ucm->getDb()))->reset($siteId);
                    return [
                        'ok'=>true,
                        'site_id'=>$siteId,
                        'reset'=>$removed,
                        'message'=>$removed
                            ? 'Saved managed-asset scan cursor cleared; current inventory was preserved'
                            : 'No saved managed-asset scan cursor existed; current inventory was preserved',
                    ];
                }
                if ($op === 'managed_asset_rescan') {
                    $scanKinds = $req['kinds'] ?? [];
                    if (is_string($scanKinds)) {
                        $scanKinds = preg_split('/[\s,]+/', $scanKinds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    }
                    return $this->rescanManagedAssets(
                        $siteId,
                        trim((string)($req['scan_token'] ?? '')),
                        is_array($scanKinds) ? $scanKinds : []
                    );
                }
                $filters = [];
                foreach (['kind', 'state', 'profile', 'bundle_key'] as $filter) {
                    $value = trim((string)($req[$filter] ?? ''));
                    if ($value !== '') { $filters[$filter] = $value; }
                }
                if (isset($req['limit'])) { $filters['limit'] = (int)$req['limit']; }
                $registry = new ManagedAssetRegistry($this->ucm->getDb());
                $catalog = new AssetLifecycleService($this->ucm->getDb());
                $catalogPage = $catalog->inventoryPage($siteId, [
                    'kind'=>$filters['kind'] ?? null,
                    'profile'=>$filters['profile'] ?? null,
                    'state'=>$filters['state'] ?? 'current',
                    'limit'=>$filters['limit'] ?? 500,
                    'offset'=>max(0, (int)($req['offset'] ?? 0)),
                ]);
                $catalogAssets = $catalogPage['assets'];
                $hosted = ['ok'=>false, 'items'=>[], 'issues'=>[], 'message'=>'HTTP media inventory is unavailable'];
                if (method_exists($this->ucm, 'mediaAssetOp')) {
                    try {
                        $hosted = (int)($req['offset'] ?? 0) > 0 ? ['ok'=>true,'items'=>[]] : (array)$this->ucm->mediaAssetOp([
                            'op'=>'inventory', 'site_id'=>$siteId,
                        ]);
                    } catch (\Throwable $hostedError) {
                        $hosted['message'] = $hostedError->getMessage();
                    }
                }
                $catalogAssets = self::mergeHostedMediaAssets(
                    $catalogAssets,
                    !empty($hosted['ok']) ? (array)($hosted['items'] ?? []) : [],
                    array_merge($filters, ['limit'=>5000])
                );
                return [
                    'ok'=>true,
                    'site_id'=>$siteId,
                    'assets'=>$registry->list($siteId, $filters),
                    'catalog_assets'=>$catalogAssets,
                    'total'=>$catalogPage['total'],
                    'truncated'=>$catalogPage['truncated'],
                    'next_offset'=>$catalogPage['next_offset'],
                    'http_inventory_truncated'=>count((array)($hosted['items'] ?? [])) + count($catalogPage['assets']) > 5000,
                    'sources'=>['tftp_registry', 'http_media_library'],
                    'http_media_ok'=>!empty($hosted['ok']),
                    'http_media_issues'=>(array)($hosted['issues'] ?? []),
                    'observed_only'=>true,
                    'message'=>!empty($hosted['ok'])
                        ? 'Unified TFTP and phone-facing HTTP media inventory loaded; observed files remain distinct from retained catalogs.'
                        : 'TFTP inventory loaded, but phone-facing HTTP media could not be read: ' . (string)($hosted['message'] ?? 'unknown error'),
                ];
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'assets'=>[], 'message'=>$e->getMessage()];
            }
        }

        if (in_array($op, ['asset_catalog', 'asset_selections', 'asset_validate_selection', 'asset_validate_timezone'], true)) {
            try {
                $catalog = new AssetLifecycleService($this->ucm->getDb());
                if ($op === 'asset_catalog') {
                    $filters = [];
                    foreach (['kind','profile','status','provenance'] as $filter) {
                        if (isset($req[$filter]) && trim((string)$req[$filter]) !== '') {
                            $filters[$filter] = trim((string)$req[$filter]);
                        }
                    }
                    if (isset($req['used'])) { $filters['used'] = $req['used']; }
                    if (isset($req['limit'])) { $filters['limit'] = (int)$req['limit']; }
                    if (isset($req['offset'])) { $filters['offset'] = (int)$req['offset']; }
                    return ['ok'=>true, 'site_id'=>$siteId] + $catalog->inventoryPage($siteId, $filters);
                }
                if ($op === 'asset_validate_timezone') {
                    $binding = self::jsonObject($req['binding'] ?? []);
                    return ['site_id'=>$siteId] + $catalog->validateTimezoneBinding($siteId, $binding);
                }
                $target = self::assetTarget($req);
                $selected = filter_var($req['asset_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($op === 'asset_validate_selection') {
                    if ($selected === false || $selected === null) {
                        throw new \InvalidArgumentException('Choose a stable managed asset ID');
                    }
                    return ['site_id'=>$siteId] + $catalog->validateSelection($siteId, (int)$selected, $target);
                }
                return ['ok'=>true, 'site_id'=>$siteId] + $catalog->selections(
                    $siteId,
                    $target,
                    $selected === false || $selected === null ? null : (int)$selected
                );
            } catch (\Throwable $e) {
                return ['ok'=>false, 'site_id'=>$siteId, 'assets'=>[], 'message'=>$e->getMessage()];
            }
        }

        if ($op === 'sync_firmware_bundle') {
            try {
                $profile = trim((string)($req['profile'] ?? ''));
                $load = trim((string)($req['load_name'] ?? ''));
                $bundle = null;
                $inventory = $this->ucm->tftpOp(['op'=>'firmware_inventory','site_id'=>$siteId,'profile'=>$profile]);
                if (empty($inventory['ok'])) { throw new \RuntimeException((string)($inventory['message'] ?? 'Source firmware inventory failed')); }
                foreach ((array)($inventory['bundles'] ?? []) as $candidate) {
                    if (($candidate['status'] ?? '') === 'complete' && hash_equals((string)$candidate['load_name'], $load)) { $bundle = $candidate; break; }
                }
                if (!$bundle) { throw new \RuntimeException('Choose a complete firmware bundle from the source site'); }
                $paths = array_values(array_unique(array_filter(array_map(static fn(array $file): string => (string)($file['path'] ?? ''), (array)$bundle['files']))));
                $result = $this->copyPaths($siteId, (array)($req['target_site_ids'] ?? []), $paths, !empty($req['overwrite']));
                foreach ($result['sites'] as $targetId=>&$siteResult) {
                    if (empty($siteResult['ok'])) { continue; }
                    try {
                        $siteResult['inventory'] = $this->ucm->tftpOp(['op'=>'firmware_rescan','site_id'=>(int)$targetId]);
                        if (empty($siteResult['inventory']['ok'])) { throw new \RuntimeException((string)($siteResult['inventory']['message'] ?? 'Destination firmware rescan failed')); }
                        $complete = false;
                        foreach ((array)($siteResult['inventory']['bundles'] ?? []) as $candidate) {
                            if (($candidate['status'] ?? '') === 'complete'
                                && (string)($candidate['profile'] ?? '') === $profile
                                && (string)($candidate['load_name'] ?? '') === $load) { $complete = true; break; }
                        }
                        if (!$complete) { throw new \RuntimeException('Destination rescan did not find the requested complete firmware bundle'); }
                    }
                    catch (\Throwable $e) { $siteResult['ok'] = false; $siteResult['message'] .= '; inventory rescan failed: ' . $e->getMessage(); }
                }
                unset($siteResult);
                $result['ok'] = !array_filter($result['sites'], static fn(array $row): bool => empty($row['ok']));
                if (!$result['ok']) { $result['message'] = 'One or more destinations failed firmware copy or verification; per-site results retain completed work'; }
                return $result;
            } catch (\Throwable $e) {
                return ['ok'=>false,'message'=>$e->getMessage(),'sites'=>[]];
            }
        }

        if ($op === 'sync_timezone_bundle') {
            try {
                $inventory = $this->timezoneInventory($siteId);
                if (empty($inventory['retainable'])) {
                    throw new \RuntimeException('The source site does not have a retainable Cisco timezone set with at least one usable phone-family payload');
                }
                // Canonical root files must never be copied one by one: a
                // mid-transfer failure would leave the target serving a mixed
                // version. Capture the immutable source archive, synchronize
                // that archive, then let each target's transactional activator
                // stage, verify and roll back its own canonical set.
                $captured = $this->withSiteLock($siteId, fn() => $this->captureTimezoneBundle($siteId));
                $sourceBundleId = (int)($captured['bundle']['id'] ?? 0);
                if ($sourceBundleId < 1) {
                    throw new \RuntimeException('The active source timezone version could not be retained for synchronization');
                }
                $result = $this->syncTimezoneVersion(
                    $siteId,
                    $sourceBundleId,
                    (array)($req['target_site_ids'] ?? [])
                );
                foreach ($result['sites'] as $targetId => &$siteResult) {
                    if (empty($siteResult['ok'])) { continue; }
                    try {
                        $targetBundleId = (int)($siteResult['timezone_bundle']['bundle']['id'] ?? 0);
                        if ($targetBundleId < 1) {
                            throw new \RuntimeException('Target retained the archive without returning its local version ID');
                        }
                        $siteResult['activation'] = $this->ucm->activateTimezoneVersion(
                            (int)$targetId,
                            $targetBundleId
                        );
                        $siteResult['timezone_inventory'] = (array)($siteResult['activation']['current'] ?? []);
                        if (empty($siteResult['activation']['ok'])
                            || empty($siteResult['timezone_inventory']['retainable'])
                            || !hash_equals(
                                (string)($captured['bundle']['bundle_key'] ?? ''),
                                (string)($siteResult['timezone_inventory']['fingerprint'] ?? '')
                            )) {
                            $siteResult['ok'] = false;
                            $siteResult['message'] .= '; transactional timezone activation did not reproduce the retainable source set';
                        } else {
                            $siteResult['message'] .= '; activated and verified with rollback retained';
                        }
                    } catch (\Throwable $e) {
                        $siteResult['ok'] = false;
                        $siteResult['message'] .= '; transactional timezone activation failed: ' . $e->getMessage();
                    }
                }
                unset($siteResult);
                $result['ok'] = !array_filter($result['sites'], static fn(array $row): bool => empty($row['ok']));
                $result['message'] = $result['ok']
                    ? 'Cisco timezone set synchronized, transactionally activated and verified per phone family'
                    : 'One or more target sites failed transactional timezone activation';
                return $result;
            } catch (\Throwable $e) {
                return ['ok'=>false, 'message'=>$e->getMessage(), 'sites'=>[]];
            }
        }

        if (in_array($op, ['sync_cisco_media','sync_wallpaper_media'], true)) {
            try {
                if ($op === 'sync_wallpaper_media' && array_key_exists('set_ids', $req)) {
                    return $this->syncSelectedWallpaperSets($siteId, $req);
                }
                if ($op === 'sync_wallpaper_media' && array_key_exists('names', $req)) {
                    return $this->syncSelectedRingtones($siteId, $req);
                }
                $connection = $this->ucm->siteConnection($siteId); $sftp = $connection->dockerSftp(); $root = rtrim($connection->tftpRoot(), '/');
                $scope = (string)($req['scope'] ?? 'all'); $paths = []; $catalogDirs = []; $hostedLibraries = [];
                if (in_array($scope, ['all','wallpapers'], true)) {
                    foreach (array_unique(ModelCatalog::BACKGROUND_DIRS) as $dir) {
                        $relative = 'Desktops/' . $dir; $entries = $sftp->nlist($root . '/' . $relative);
                        foreach ((array)$entries as $name) {
                            if (preg_match('/^[A-Za-z0-9_.-]+\.png$/iD', (string)$name)) {
                                $paths[] = $relative . '/' . $name;
                                $catalogDirs[$dir] = true;
                            }
                        }
                    }
                    $sets = $this->wallpaperSetList($siteId, $sftp, $root);
                    foreach ((array)($sets['sets'] ?? []) as $set) {
                        if (empty($set['source_current'])) { continue; }
                        $base = self::WALLPAPER_SET_ROOT . '/' . $set['id'];
                        $paths[] = $base . '/' . $set['source'];
                        $paths[] = $base . '/manifest.json';
                        foreach ((array)$set['variants'] as $variant) {
                            $variantSource = (string)($variant['source'] ?? $set['source']);
                            if ($variantSource !== '') { $paths[] = $base . '/' . $variantSource; }
                            if (empty($variant['enabled']) || ($variant['delivery'] ?? '') !== 'hosted_http') { continue; }
                            foreach ((array)($variant['models'] ?? []) as $model) {
                                $hostedLibraries[(string)$variant['family'] . '|' . strtolower((string)$model)] = [
                                    'family'=>(string)$variant['family'],
                                    'model'=>strtolower((string)$model),
                                ];
                            }
                        }
                    }
                }
                if (in_array($scope, ['all','ringtones'], true)) {
                    foreach ((array)$sftp->nlist($root) as $name) { if (preg_match('/^(?:Ringlist(?:-wb)?\.xml|[A-Za-z0-9_.-]+\.(?:raw|rwb))$/i', (string)$name)) { $paths[] = (string)$name; } }
                }
                if (!$paths && !$hostedLibraries) { throw new \RuntimeException('No managed wallpaper or ringtone files were found on the source site'); }
                $targets = (array)($req['target_site_ids'] ?? []);
                $result = $paths
                    ? $this->copyPaths($siteId, $targets, array_values(array_unique($paths)), !empty($req['overwrite']))
                    : ['ok'=>true, 'sites'=>[], 'message'=>'No TFTP wallpaper files required copying'];
                foreach ($hostedLibraries as $library) {
                    $eligible = array_values(array_filter($targets, static fn($id): bool => !isset($result['sites'][$id]) || !empty($result['sites'][$id]['ok'])));
                    if (!$eligible) { break; }
                    try {
                        $hosted = $this->ucm->mediaAssetOp([
                            'op'=>'sync', 'site_id'=>$siteId,
                            'family'=>$library['family'], 'model'=>$library['model'],
                            'target_site_ids'=>$eligible, 'overwrite'=>!empty($req['overwrite']),
                        ]);
                    } catch (\Throwable $error) {
                        $hosted = ReplicationActivityService::failedOperation($error, $eligible, 'Hosted wallpapers');
                    }
                    foreach ($eligible as $targetId) {
                        $siteResult = (array)($hosted['sites'][$targetId] ?? ['ok'=>false,'status'=>'unknown','message'=>'Hosted wallpaper result is missing for this destination']);
                        if (!isset($result['sites'][$targetId])) {
                            $result['sites'][$targetId] = ['ok'=>true, 'message'=>''];
                        }
                        $result['sites'][$targetId]['hosted_media'][] = $siteResult;
                        if (empty($siteResult['ok'])) {
                            $result['sites'][$targetId]['ok'] = false;
                            $result['ok'] = false;
                        }
                    }
                }
                if ($catalogDirs) {
                    foreach ($result['sites'] as $targetId=>&$siteResult) {
                        if (empty($siteResult['ok'])) { continue; }
                        try {
                            if (method_exists($this->ucm, 'replicationTransfer')) {
                                $catalogs = $this->ucm->replicationTransfer((int)$targetId,
                                    ['op'=>'indexes','directories'=>array_keys($catalogDirs)],
                                    $siteResult['transport'] ?? $this->ucm->replicationTransport((int)$targetId));
                                if (empty($catalogs['ok'])) { throw new \RuntimeException('Destination wallpaper indexes failed'); }
                                $siteResult['catalogs'] = $catalogs['indexes'];
                                continue;
                            }
                            $target = $this->ucm->siteConnection((int)$targetId);
                            $targetSftp = $target->dockerSftp();
                            $targetRoot = rtrim($target->tftpRoot(), '/');
                            $siteResult['catalogs'] = [];
                            foreach (array_keys($catalogDirs) as $dir) {
                                $catalog = $this->regenerateImageList(
                                    (int)$targetId,
                                    $targetSftp,
                                    $targetRoot . '/Desktops/' . $dir,
                                    'Desktops/' . $dir
                                );
                                $siteResult['catalogs'][$dir] = $catalog;
                                if (empty($catalog['ok'])) {
                                    throw new \RuntimeException((string)($catalog['message'] ?? "Could not rebuild $dir/List.xml"));
                                }
                            }
                        } catch (\Throwable $error) {
                            $siteResult['ok'] = false;
                            $siteResult['message'] .= '; catalog rebuild failed: ' . $error->getMessage();
                            $result['ok'] = false;
                        }
                    }
                    unset($siteResult);
                    $result['message'] = $result['ok']
                        ? 'Wallpaper media synchronized and Cisco target catalogs rebuilt'
                        : 'One or more target sites failed wallpaper media synchronization';
                }
                return $result;
            } catch (\Throwable $e) {
                return ['ok'=>false,'message'=>$e->getMessage(),'sites'=>[]];
            }
        }

        try {
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $root = $conn->tftpRoot();
            $full = $root . ($path !== '' ? '/' . $path : '');
            if (in_array($op, ['list','get','get_base64','put','upload','firmware_upload','mkdir','ensure_dir','delete','regen_list','regen_ringlist'], true)) {
                $types = in_array($op, ['list','mkdir','ensure_dir','regen_list','regen_ringlist'], true) ? [2] : [1];
                $this->remotePathMetadata($sftp, rtrim($root, '/'), $path, $types);
            }
            $activeFiles = [];
            $phoneFiles = [];
            $manualFiles = [];
            $manifestError = '';
            $overrideStateError = '';
            try {
                $activeFiles = $this->activeGenerationFiles($sftp, rtrim($root, '/'));
            } catch (\Throwable $e) {
                $manifestError = $e->getMessage();
            }
            if (method_exists($this->ucm, 'phoneConfigFiles')
                || method_exists($this->ucm, 'phoneConfigFilesLocal')
                || method_exists($this->ucm, 'phoneConfigOverridesLocal')) {
                try {
                    $phoneFiles = method_exists($this->ucm, 'phoneConfigFiles')
                        ? (array)$this->ucm->phoneConfigFiles($siteId)
                        : (method_exists($this->ucm, 'phoneConfigFilesLocal')
                            ? (array)$this->ucm->phoneConfigFilesLocal($siteId)
                            : (array)$this->ucm->phoneConfigOverridesLocal($siteId));
                    $manualFiles = array_filter(
                        $phoneFiles,
                        static fn(array $row): bool =>
                            (string)($row['config_mode'] ?? 'manual') === 'manual'
                    );
                } catch (\Throwable $e) {
                    $overrideStateError = $e->getMessage();
                }
            }
            $isRootFile = $path !== '' && !str_contains($path, '/');
            $activeTarget = $isRootFile ? ($activeFiles[$path] ?? null) : null;
            $persistedPhoneTarget = $isRootFile ? ($phoneFiles[$path] ?? null) : null;
            $manualTarget = $isRootFile ? ($manualFiles[$path] ?? null) : null;
            $activePhoneTarget = is_array($activeTarget)
                && (string)($activeTarget['role'] ?? '') === 'phone_config'
                && (int)($activeTarget['phone_id'] ?? 0) > 0
                ? $activeTarget
                : null;
            $phoneOverrideTarget = $activeTarget !== null
                ? $activePhoneTarget
                : (is_array($persistedPhoneTarget) ? $persistedPhoneTarget : null);
            $browserMutations = ['put','upload','firmware_upload','delete','delete_batch','mkdir','ensure_dir','regen_list','regen_ringlist'];
            if (in_array($op, $browserMutations, true)) {
                if ($manifestError !== '') {
                    throw new \RuntimeException(
                        'TFTP mutation blocked because the active generation manifest could not be verified: '
                        . $manifestError
                    );
                }
                if ($overrideStateError !== '') {
                    throw new \RuntimeException(
                        'TFTP mutation blocked because phone config override state could not be verified: '
                        . $overrideStateError
                    );
                }
                if ($op !== 'delete_batch' && ($activeTarget !== null || $persistedPhoneTarget !== null)) {
                    $explicitPhoneOverride = in_array($op, ['put','upload'], true)
                        && !empty($req['override_managed'])
                        && $phoneOverrideTarget !== null;
                    if (!$explicitPhoneOverride) {
                        throw new \RuntimeException(
                            $phoneOverrideTarget !== null
                                ? "$path is a managed phone config; use its explicit Override action or Replace config on Phones"
                                : "$path belongs to the active provisioning generation; use Preview and Apply instead"
                        );
                    }
                    if (!method_exists($this->ucm, 'recordPhoneConfigOverride')
                        && !method_exists($this->ucm, 'recordPhoneConfigOverrideLocal')) {
                        throw new \RuntimeException('Phone config override tracking is unavailable on this site');
                    }
                }
            }

            switch ($op) {
                case 'wallpaper_set_list':
                    return $this->wallpaperSetList($siteId, $sftp, rtrim($root, '/'));

                case 'wallpaper_set_get':
                    return $this->wallpaperSetGet(
                        $sftp,
                        rtrim($root, '/'),
                        (string)($req['set_id'] ?? '')
                    );

                case 'wallpaper_set_repair_encoding':
                    return $this->synchronizedCatalogMutation(
                        $siteId,
                        'wallpaper_catalog',
                        'wallpaper:' . (string)($req['set_id'] ?? ''),
                        $req,
                        fn(): array => $this->repairWallpaperSetEncoding(
                            $siteId,
                            $sftp,
                            rtrim($root, '/'),
                            (string)($req['set_id'] ?? '')
                        )
                    );

                case 'wallpaper_set_repair_hosted_names':
                    return $this->synchronizedCatalogMutation(
                        $siteId, 'wallpaper_catalog', 'wallpaper:' . (string)($req['set_id'] ?? ''), $req,
                        fn(): array => $this->repairWallpaperHostedNames($siteId, $sftp, rtrim($root, '/'), (string)($req['set_id'] ?? ''))
                    );

                case 'wallpaper_set_source':
                    return $this->synchronizedCatalogMutation(
                        $siteId,
                        'wallpaper_catalog',
                        'wallpaper:' . (string)($req['set_id'] ?? ''),
                        $req,
                        fn(): array => $this->wallpaperSetSource(
                            $sftp,
                            rtrim($root, '/'),
                            (string)($req['set_id'] ?? ''),
                            (string)($req['set_name'] ?? ''),
                            (string)($req['source'] ?? ''),
                            (string)($req['mime'] ?? ''),
                            (string)($req['intent'] ?? ''),
                            (string)($req['scope'] ?? '')
                        )
                    );

                case 'wallpaper_profile_source_save':
                    return $this->synchronizedCatalogMutation(
                        $siteId,
                        'wallpaper_catalog',
                        'wallpaper:' . (string)($req['set_id'] ?? ''),
                        $req,
                        fn(): array => $this->wallpaperProfileSourceSave(
                            $sftp,
                            rtrim($root, '/'),
                            (string)($req['set_id'] ?? ''),
                            (string)($req['profile'] ?? ''),
                            (string)($req['source'] ?? ''),
                            (string)($req['mime'] ?? '')
                        )
                    );

                case 'wallpaper_profile_source_get':
                    return $this->wallpaperProfileSourceGet(
                        $sftp,
                        rtrim($root, '/'),
                        (string)($req['set_id'] ?? ''),
                        (string)($req['profile'] ?? '')
                    );

                case 'wallpaper_profile_source_reset':
                    return $this->synchronizedCatalogMutation(
                        $siteId,
                        'wallpaper_catalog',
                        'wallpaper:' . (string)($req['set_id'] ?? ''),
                        $req,
                        fn(): array => $this->wallpaperProfileSourceReset(
                            $sftp,
                            rtrim($root, '/'),
                            (string)($req['set_id'] ?? ''),
                            (string)($req['profile'] ?? '')
                        )
                    );

                case 'wallpaper_set_delete':
                    return $this->synchronizedCatalogMutation(
                        $siteId,
                        'wallpaper_catalog',
                        'wallpaper:' . (string)($req['set_id'] ?? ''),
                        $req + ['sync_edit_reason'=>'Deleted in Media Manager'],
                        fn(): array => $this->wallpaperSetDelete(
                            $siteId,
                            $sftp,
                            rtrim($root, '/'),
                            (string)($req['set_id'] ?? '')
                        )
                    );

                case 'wallpaper_set_rename':
                    return $this->synchronizedCatalogMutation(
                        $siteId,
                        'wallpaper_catalog',
                        'wallpaper:' . (string)($req['set_id'] ?? ''),
                        $req + ['sync_edit_reason'=>'Renamed in Media Manager'],
                        fn(): array => $this->wallpaperSetRename(
                            $siteId,
                            $sftp,
                            rtrim($root, '/'),
                            (string)($req['set_id'] ?? ''),
                            (string)($req['new_set_id'] ?? ''),
                            (string)($req['set_name'] ?? '')
                        )
                    );

                case 'wallpaper_choices':
                    return $this->wallpaperChoices(
                        $siteId,
                        $sftp,
                        rtrim($root, '/'),
                        (string)($req['profile'] ?? '')
                    );

                case 'put_media_pairs':
                    return $this->synchronizedCatalogMutation(
                        $siteId,
                        'wallpaper_catalog',
                        'wallpaper:' . (string)($req['set_id'] ?? ''),
                        $req,
                        fn(): array => $this->saveWallpaperVariants($siteId, $sftp, rtrim($root, '/'), $req)
                    );

                case 'scan_legacy':
                    return ['ok' => true, 'candidates' => $this->scanLegacyMacsByExtension($siteId)];

                case 'bump_legacy':
                    return ['ok' => true, 'token' => $this->bumpLegacySync($siteId)];

                case 'list':
                    $entries = $sftp->rawlist($full);
                    if ($entries === false) {
                        return ['ok' => false, 'message' => "Cannot list $full"];
                    }
                    $items = [];
                    foreach ($entries as $name => $metadata) {
                        if ($name === '.' || $name === '..' || $name === '.ucmprovisioner') {
                            continue;
                        }
                        $meta = (array)$metadata;
                        $dir = (int)($meta['type'] ?? 0) === 2;
                        $active = $path === '' && !$dir ? ($activeFiles[$name] ?? null) : null;
                        $persistedPhone = $path === '' && !$dir ? ($phoneFiles[$name] ?? null) : null;
                        $manual = $path === '' && !$dir ? ($manualFiles[$name] ?? null) : null;
                        $activePhone = is_array($active)
                            && (string)($active['role'] ?? '') === 'phone_config'
                            && (int)($active['phone_id'] ?? 0) > 0;
                        $managedPhone = $active !== null
                            ? $activePhone
                            : is_array($persistedPhone);
                        $declarations = [];
                        if (!$dir && preg_match('/^[A-Za-z0-9_.-]+\.(?:rom|bin|fw)$/iD', (string)$name)) {
                            foreach ([FirmwareService::declarationFilename($name), $name . FirmwareService::DECLARATION_SUFFIX] as $sidecar) {
                                if ((int)($entries[$sidecar]['type'] ?? 0) !== 1) { continue; }
                                $declarationPath = trim(($path !== '' ? $path . '/' : '') . $sidecar, '/');
                                try { $declaration = $this->readRemoteJson($sftp, rtrim($root, '/') . '/' . $declarationPath, true); }
                                catch (\Throwable $ignored) { continue; }
                                if (($declaration['file'] ?? '') === $name) { $declarations[] = $declarationPath; }
                            }
                        }
                        $items[] = [
                            'name'  => $name,
                            'declaration_paths' => $declarations,
                            'path'  => trim(($path !== '' ? $path . '/' : '') . $name, '/'),
                            'dir'   => $dir,
                            'size'  => $dir ? null : (isset($meta['size']) ? (int)$meta['size'] : null),
                            'mtime' => isset($meta['mtime']) ? (int)$meta['mtime'] : null,
                            'managed' => $active !== null || is_array($persistedPhone),
                            'managed_role' => is_array($active)
                                ? (string)($active['role'] ?? '')
                                : (is_array($persistedPhone) ? 'phone_config' : ''),
                            'managed_phone_config' => $managedPhone,
                            'manual_override' => is_array($manual),
                            'phone_id' => $activePhone
                                ? (int)$active['phone_id']
                                : (is_array($persistedPhone) ? (int)($persistedPhone['id'] ?? 0) : 0),
                            'extension' => $activePhone
                                ? (string)($active['extension'] ?? '')
                                : (is_array($persistedPhone) ? (string)($persistedPhone['extension'] ?? '') : ''),
                            'config_overridden_at' => is_array($manual)
                                ? (int)($manual['config_overridden_at'] ?? 0)
                                : 0,
                        ];
                    }
                    usort($items, fn($a, $b) => ($b['dir'] <=> $a['dir']) ?: strcasecmp($a['name'], $b['name']));
                    return [
                        'ok' => true,
                        'path' => $path,
                        'root' => $root,
                        'entries' => $items,
                        'manifest_warning' => $manifestError,
                        'mutations_blocked' => $manifestError !== '',
                    ];

                case 'get':
                    $read = $this->readBounded($sftp, $full, self::MAX_TEXT_READ_BYTES, 'text editor');
                    if (empty($read['ok'])) { return $read; }
                    $data = (string)$read['content'];
                    return ['ok' => true, 'path' => $path, 'content' => $data, 'size' => strlen($data)];

                case 'get_base64':
                    $read = $this->readBounded($sftp, $full, self::MAX_BASE64_READ_BYTES, 'media preview');
                    if (empty($read['ok'])) { return $read; }
                    $data = (string)$read['content'];
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','bmp'=>'image/bmp','gif'=>'image/gif'][$ext] ?? 'application/octet-stream';
                    return ['ok'=>true, 'path'=>$path, 'mime'=>$mime, 'content'=>base64_encode((string)$data), 'size'=>strlen((string)$data)];

                case 'put':
                    $content = (string) ($req['content'] ?? '');
                    if ($content === '' && empty($req['allow_empty'])) {
                        return ['ok' => false, 'message' => 'Empty content rejected'];
                    }
                    $overrideSnapshot = $phoneOverrideTarget !== null
                        ? $this->phoneConfigOverrideSnapshot($sftp, $full)
                        : null;
                    if (is_array($overrideSnapshot) && empty($overrideSnapshot['ok'])) {
                        return $overrideSnapshot;
                    }
                    $this->writeRemoteFile($sftp, $full, $content, 0664);
                    $ok = true;
                    $override = $ok && $phoneOverrideTarget !== null
                        ? $this->recordPhoneConfigOverride(
                            $siteId,
                            $phoneOverrideTarget,
                            $path,
                            hash('sha256', $content)
                        )
                        : [];
                    $overrideWarning = (string)($override['warning'] ?? '');
                    if ($ok && $overrideWarning !== '' && is_array($overrideSnapshot)) {
                        return $this->restoreUntrackedPhoneOverride(
                            $sftp,
                            $full,
                            $overrideSnapshot,
                            $overrideWarning
                        );
                    }
                    $registered = $ok
                        ? $this->registerManagedPayload($siteId, $path, hash('sha256', $content), strlen($content))
                        : [];
                    return [
                        'ok' => (bool) $ok,
                        'path' => $path,
                        'message' => $ok
                            ? ($phoneOverrideTarget !== null
                                ? ('Saved as a manual phone config override'
                                    . ($overrideWarning !== '' ? '; WARNING: ' . $overrideWarning : ''))
                                : 'Saved')
                            : 'Write failed',
                        'asset' => $registered['asset'] ?? null,
                        'registry_warning' => $registered['warning'] ?? null,
                        'phone_override' => $override['phone'] ?? null,
                        'override_warning' => $overrideWarning !== '' ? $overrideWarning : null,
                    ];

                case 'firmware_upload':
                case 'upload':
                    $uploadName = basename($path);
                    if ($uploadName === ''
                        || strlen($uploadName) > 128
                        || preg_match('/[\x00-\x1f\x7f\/\\\\]/D', $uploadName)) {
                        return ['ok'=>false, 'message'=>'Upload filename is empty, unsafe, or longer than 128 characters'];
                    }
                    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                        return ['ok' => false, 'message' => 'No file received'];
                    }
                    $uploadPath = (string)$_FILES['file']['tmp_name'];
                    // hash_file() streams the upload; large firmware is never copied
                    // into a PHP string merely to populate inventory metadata.
                    $assetMetadata = self::managedAssetMetadata($path);
                    $inspection = $assetMetadata === null
                        ? null
                        : AssetFileInspector::inspect($uploadPath, basename($path), (string)$assetMetadata['kind']);
                    $contentHash = $inspection['content_sha256'] ?? hash_file('sha256', $uploadPath);
                    $displayName = null;
                    if (!empty($req['compact_name'])) {
                        if ($op !== 'upload' || str_contains($path, '/') || ($assetMetadata['kind'] ?? '') !== 'ringtone'
                            || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['raw','rwb'], true)) {
                            throw new \InvalidArgumentException('Compact audio upload requires a root Cisco ringtone');
                        }
                        $displayName = ucwords(str_replace(['-','_'], ' ', pathinfo($uploadName, PATHINFO_FILENAME)));
                        $path = AssetFilename::fromHash('r', (string)$contentHash, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
                        $full = rtrim($root, '/') . '/' . $path;
                    }
                    $overrideSnapshot = $phoneOverrideTarget !== null
                        ? $this->phoneConfigOverrideSnapshot($sftp, $full)
                        : null;
                    if (is_array($overrideSnapshot) && empty($overrideSnapshot['ok'])) {
                        return $overrideSnapshot;
                    }
                    if ($op === 'firmware_upload') {
                        $ok = $this->installDeclaredFirmware(
                            $sftp,
                            $full,
                            $uploadPath,
                            $this->validateFirmwareDeclaration($path, (string)($req['declaration'] ?? ''))
                        );
                    } else {
                        $this->replaceRemoteFiles($sftp, [$full=>['local_path'=>$uploadPath,'bytes'=>(int)filesize($uploadPath),'sha256'=>(string)$contentHash,'mode'=>0664]]);
                        $ok = true;
                    }
                    $size = $ok ? (int) @filesize($uploadPath) : 0;
                    $inspectionFields = $inspection === null ? [] : [
                        'content_type'=>$inspection['content_type'],
                        'validation_status'=>$inspection['validation_status'],
                        'signature_status'=>$inspection['signature_status'],
                        'metadata'=>$inspection['metadata'],
                        'provenance'=>'operator_upload',
                    ];
                    if ($inspection !== null && $inspection['parsed_version'] !== null) {
                        $inspectionFields['asset_version'] = $inspection['parsed_version'];
                    }
                    if ($displayName !== null) { $inspectionFields['metadata']['display_name'] = mb_substr($displayName, 0, 25, 'UTF-8'); }
                    $override = $ok && $phoneOverrideTarget !== null && is_string($contentHash)
                        ? $this->recordPhoneConfigOverride(
                            $siteId,
                            $phoneOverrideTarget,
                            $path,
                            $contentHash
                        )
                        : [];
                    $overrideWarning = (string)($override['warning'] ?? '');
                    if ($ok && $overrideWarning !== '' && is_array($overrideSnapshot)) {
                        return $this->restoreUntrackedPhoneOverride(
                            $sftp,
                            $full,
                            $overrideSnapshot,
                            $overrideWarning
                        );
                    }
                    $registered = $ok && is_string($contentHash)
                        ? $this->registerManagedPayload($siteId, $path, $contentHash, $size, $inspectionFields)
                        : [];
                    return [
                        'ok' => (bool) $ok,
                        'path' => $path,
                        'bytes' => $size,
                        'message' => $ok
                            ? ($phoneOverrideTarget !== null
                                ? ("Uploaded as a manual phone config override ($size bytes)"
                                    . ($overrideWarning !== '' ? '; WARNING: ' . $overrideWarning : ''))
                                : "Uploaded ($size bytes)")
                            : 'Upload failed',
                        'asset' => $registered['asset'] ?? null,
                        'registry_warning' => $registered['warning'] ?? null,
                        'phone_override' => $override['phone'] ?? null,
                        'override_warning' => $overrideWarning !== '' ? $overrideWarning : null,
                    ];

                case 'delete_batch':
                    $requestedPaths = $req['paths'] ?? [];
                    if (is_string($requestedPaths)) {
                        $decodedPaths = json_decode($requestedPaths, true);
                        $requestedPaths = is_array($decodedPaths) ? $decodedPaths : [];
                    }
                    if (!is_array($requestedPaths) || count($requestedPaths) < 1) {
                        return ['ok'=>false, 'message'=>'Select at least one file to delete'];
                    }
                    if (count($requestedPaths) > 100) {
                        return ['ok'=>false, 'message'=>'A TFTP delete batch is limited to 100 files'];
                    }
                    $batch = [];
                    foreach ($requestedPaths as $requestedPath) {
                        $candidate = $this->sanitizeRel((string)$requestedPath);
                        if ($candidate === ''
                            || $candidate === '.ucmprovisioner'
                            || str_starts_with($candidate, '.ucmprovisioner/')) {
                            throw new \RuntimeException('A delete batch may not include the TFTP root or internal transaction ledger');
                        }
                        if (!str_contains($candidate, '/')
                            && (isset($activeFiles[$candidate]) || isset($phoneFiles[$candidate]))) {
                            throw new \RuntimeException(
                                "$candidate is a managed phone/generation file and cannot be deleted in TFTP Browser"
                            );
                        }
                        $candidateFull = rtrim($root, '/') . '/' . $candidate;
                        if (self::managedAssetMetadata($candidate)!==null) { throw new \RuntimeException('Managed assets require the revision-bound Asset retirement preview'); }
                        $metadata = $this->remotePathMetadata($sftp, rtrim($root, '/'), $candidate);
                        if (!is_array($metadata)) {
                            throw new \RuntimeException("$candidate no longer exists; nothing was deleted");
                        }
                        if ((int)($metadata['type'] ?? 0) === 2 || $sftp->is_dir($candidateFull)) {
                            throw new \RuntimeException("$candidate is a directory; batch delete accepts files only");
                        }
                        $batch[$candidate] = $candidateFull;
                    }
                    $deleted = [];
                    $registryWarnings = [];
                    foreach ($batch as $candidate => $candidateFull) {
                        if (!$sftp->delete($candidateFull)) {
                            return [
                                'ok'=>false,
                                'deleted'=>array_values($deleted),
                                'message'=>"Delete failed for $candidate after "
                                    . count($deleted) . ' earlier file(s) were deleted',
                                'registry_warnings'=>$registryWarnings,
                            ];
                        }
                        $deleted[] = $candidate;
                        $retired = $this->retireManagedPath($siteId, $candidate);
                        if (!empty($retired['warning'])) {
                            $registryWarnings[] = (string)$retired['warning'];
                        }
                    }
                    return [
                        'ok'=>true,
                        'deleted'=>array_values($deleted),
                        'message'=>count($deleted) . ' file(s) deleted',
                        'registry_warnings'=>$registryWarnings,
                    ];

                case 'delete':
                    if (self::managedAssetMetadata($path)!==null || $sftp->is_dir($full)) { throw new \RuntimeException('Managed files and asset directories require reference-aware retirement; direct deletion is blocked'); }
                    if ($path === '' || $path === '.') {
                        return ['ok' => false, 'message' => 'Refusing to delete TFTP root'];
                    }
                    $ok = $sftp->is_dir($full) ? $sftp->rmdir($full) : $sftp->delete($full);
                    $retired = $ok ? $this->retireManagedPath($siteId, $path) : [];
                    return [
                        'ok' => (bool) $ok,
                        'message' => $ok ? 'Deleted' : 'Delete failed',
                        'registry_warning' => $retired['warning'] ?? null,
                    ];

                case 'mkdir':
                    $ok = $sftp->mkdir($full);
                    if ($ok) { $sftp->chmod(self::PUBLIC_DIRECTORY_MODE, $full); }
                    return ['ok' => (bool) $ok, 'message' => $ok ? 'Created' : 'Mkdir failed'];

                case 'ensure_dir':
                    if ($path === '') { return ['ok'=>true, 'message'=>'Root already exists']; }
                    $cursor = rtrim($root, '/');
                    foreach (explode('/', $path) as $part) {
                        $cursor .= '/' . $part;
                        if (!$sftp->is_dir($cursor) && !$sftp->mkdir($cursor)) {
                            return ['ok'=>false, 'message'=>"Cannot create $cursor"];
                        }
                        $sftp->chmod(self::PUBLIC_DIRECTORY_MODE, $cursor);
                    }
                    return ['ok'=>true, 'message'=>'Directory ready'];

                case 'regen_list':
                    return $this->regenerateImageList($siteId, $sftp, $full, $path);

                case 'regen_ringlist':
                    return $this->regenerateRingList($siteId, $sftp, $root, !empty($req['wideband']));

                default:
                    return ['ok' => false, 'message' => "Unknown op '$op'"];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Recoverably empty one site's managed TFTP store while retaining only
     * MAC-specific phone configs. This is intentionally CLI-only at the BMO
     * boundary; ordinary browser deletes keep their existing ownership guards.
     */
    private function resetExceptPhoneConfigs(int $siteId, bool $apply, string $confirm): array
    {
        if ($apply && !hash_equals('PRESERVE_PHONE_CONFIGS', $confirm)) {
            throw new \InvalidArgumentException('Storage reset confirmation is invalid');
        }
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        if ($root === '' || $root === '/' || basename($root) !== 'tftpboot') {
            throw new \RuntimeException('Refusing to reset an unexpected TFTP root');
        }
        $entries = $sftp->rawlist($root);
        if (!is_array($entries)) { throw new \RuntimeException('Cannot inspect the site TFTP root'); }
        $managedPhoneConfigs = array_fill_keys(
            array_keys((array)$this->ucm->phoneConfigFiles($siteId)),
            true
        );
        $preserved = [];
        $public = [];
        foreach ($entries as $name=>$metadata) {
            $name = (string)$name;
            if ($name === '.' || $name === '..') { continue; }
            if ($name === '.ucmprovisioner') { continue; }
            if (isset($managedPhoneConfigs[$name]) && (int)($metadata['type'] ?? 0) !== 2) {
                $preserved[] = $name;
            } else {
                $public[] = ['name'=>$name,'type'=>(int)($metadata['type'] ?? 0) === 2 ? 'directory' : 'file','size'=>(int)($metadata['size'] ?? 0)];
            }
        }
        $ledger = $root . '/.ucmprovisioner';
        $ledgerEntries = $sftp->is_dir($ledger) ? $sftp->rawlist($ledger) : [];
        if (!is_array($ledgerEntries)) { throw new \RuntimeException('Cannot inspect private TFTP state; storage reset was not started'); }
        $private = [];
        foreach ((array)$ledgerEntries as $name=>$metadata) {
            $name = (string)$name;
            if ($name === '.' || $name === '..' || $name === 'quarantine' || $name === 'write-lock') { continue; }
            $private[] = ['name'=>$name,'type'=>(int)($metadata['type'] ?? 0) === 2 ? 'directory' : 'file','size'=>(int)($metadata['size'] ?? 0)];
        }
        sort($preserved, SORT_NATURAL | SORT_FLAG_CASE);
        usort($public, static fn(array $a,array $b): int => strnatcasecmp($a['name'],$b['name']));
        usort($private, static fn(array $a,array $b): int => strnatcasecmp($a['name'],$b['name']));
        if (!$apply) {
            return [
                'ok'=>true,'site_id'=>$siteId,'mode'=>'dry-run','preserved_phone_configs'=>$preserved,
                'tftp_entries_to_quarantine'=>$public,'ledger_entries_to_quarantine'=>$private,
                'message'=>count($preserved) . ' phone config(s) would be preserved; '
                    . (count($public)+count($private)) . ' other top-level item(s) would be quarantined',
            ];
        }

        if (!$sftp->is_dir($ledger) && (!$sftp->mkdir($ledger) || !$sftp->chmod(self::PRIVATE_DIRECTORY_MODE, $ledger))) {
            throw new \RuntimeException('Could not create the private TFTP transaction directory');
        }
        $quarantine = $ledger . '/quarantine';
        if (!$sftp->is_dir($quarantine) && (!$sftp->mkdir($quarantine) || !$sftp->chmod(self::PRIVATE_DIRECTORY_MODE, $quarantine))) {
            throw new \RuntimeException('Could not create the private TFTP quarantine directory');
        }
        $token = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $run = $quarantine . '/' . $token;
        foreach ([$run,$run . '/tftp',$run . '/ledger'] as $directory) {
            if (!$sftp->mkdir($directory) || !$sftp->chmod(self::PRIVATE_DIRECTORY_MODE, $directory)) {
                throw new \RuntimeException('Could not create the recoverable TFTP quarantine set');
            }
        }
        $dbBackup = [];
        foreach (['ucmp_assets','ucmp_firmware_bundles','ucmp_timezone_bundles'] as $table) {
            try {
                $stmt = $this->ucm->getDb()->prepare("SELECT * FROM `$table` WHERE site_id=?");
                $stmt->execute([$siteId]);
                $dbBackup[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $error) { throw new \RuntimeException('Cannot preserve inventory before storage reset: ' . $table, 0, $error); }
        }
        $manifest = [
            'schema'=>1,'site_id'=>$siteId,'created'=>time(),'state'=>'in_progress',
            'preserved_phone_configs'=>$preserved,'planned'=>['tftp'=>$public,'ledger'=>$private],
            'moved'=>[],'database'=>$dbBackup,
        ];
        $writeManifest = function () use ($sftp,$run,&$manifest): void {
            $this->writeRemoteJson($sftp,$run . '/current.json',$manifest);
        };
        $writeManifest();
        try {
        foreach ([['rows'=>$public,'from'=>$root,'to'=>$run . '/tftp'],['rows'=>$private,'from'=>$ledger,'to'=>$run . '/ledger']] as $batch) {
            foreach ($batch['rows'] as $row) {
                $source = $batch['from'] . '/' . $row['name'];
                $destination = $batch['to'] . '/' . $row['name'];
                $manifest['pending_move'] = ['source'=>$source,'destination'=>$destination];
                $writeManifest();
                try {
                    if (!$sftp->rename($source, $destination)) { throw new \RuntimeException('Move completion was not confirmed for ' . $row['name']); }
                    $manifest['moved'][] = $manifest['pending_move'];
                    $manifest['pending_move'] = null;
                    $writeManifest();
                } catch (\Throwable $error) {
                    throw new RpcOperationException('Storage reset stopped; reconcile the retained move receipt before retrying: ' . $error->getMessage(),
                        ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'site_id'=>$siteId,'quarantine'=>$run,'receipt'=>$run . '/current.json',
                            'moved_count'=>count($manifest['moved']),'pending_move'=>$manifest['pending_move']]);
                }
            }
        }
        $db = $this->ucm->getDb();
        $db->beginTransaction();
        $commitAttempted = false;
        try {
            $db->prepare("UPDATE ucmp_assets SET state='stale',updated=? WHERE site_id=? AND state='current'")->execute([time(),$siteId]);
            $db->prepare('DELETE FROM ucmp_firmware_bundles WHERE site_id=?')->execute([$siteId]);
            $db->prepare('DELETE FROM ucmp_timezone_bundles WHERE site_id=?')->execute([$siteId]);
            $db->prepare('DELETE FROM ucmp_asset_scan_jobs WHERE site_id=?')->execute([$siteId]);
            $commitAttempted = true;
            if (!$db->commit()) { throw new \RuntimeException('Inventory reset commit was not confirmed'); }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            $manifest['state'] = $commitAttempted ? 'files_quarantined_database_unknown' : 'files_quarantined_database_not_cleared';
            $manifest['error'] = $e->getMessage();
            $writeManifest();
            return ['ok'=>false,'status'=>$commitAttempted?'unknown':'partial','recovery_required'=>true,'site_id'=>$siteId,'mode'=>'apply','quarantine'=>$run,'manifest'=>$manifest,'message'=>'Files were quarantined; inventory reset needs reconciliation: '.$e->getMessage()];
        }
        $manifest['state'] = 'complete';
        $manifest['completed'] = time();
        $writeManifest();
        return [
            'ok'=>true,'site_id'=>$siteId,'mode'=>'apply','quarantine'=>$run,
            'preserved_phone_configs'=>$preserved,'moved_count'=>count($manifest['moved']),
            'message'=>count($preserved) . ' phone config(s) preserved; ' . count($manifest['moved']) . ' other item(s) moved to recoverable quarantine',
        ];
        } catch (RpcOperationException $error) { throw $error; }
        catch (\Throwable $error) {
            throw new RpcOperationException('Storage reset stopped; retained files and receipt require reconciliation: ' . $error->getMessage(),
                ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'site_id'=>$siteId,'quarantine'=>$run,'receipt'=>$run . '/current.json',
                    'moved_count'=>count($manifest['moved']),'pending_move'=>$manifest['pending_move'] ?? null,'stage'=>$manifest['state']]);
        }
    }

    /** @param object $sftp */
    private function saveWallpaperVariants(int $siteId, $sftp, string $root, array $request): array
    {
        $pairs = is_array($request['pairs'] ?? null)
            ? $request['pairs']
            : json_decode((string)($request['pairs'] ?? '[]'), true);
        if (!is_array($pairs) || !$pairs || count($pairs) > 20) {
            return ['ok'=>false, 'message'=>'Choose between one and twenty rendered wallpaper profiles'];
        }
        if (($request['intent'] ?? '') !== 'update') {
            return ['ok'=>false, 'message'=>'Linked wallpaper writes require an explicit update intent'];
        }
        $setId = self::wallpaperSetId((string)($request['set_id'] ?? ''));
        $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
        $manifest['version'] = 3;
        $manifest['name'] = self::wallpaperSetName((string)($request['set_name'] ?? $manifest['name'] ?? $setId));
        if (trim((string)($request['scope'] ?? '')) !== '') {
            $manifest['original_scope'] = self::wallpaperScope((string)$request['scope']);
        }
        $validated = [];
        $seen = [];
        $decodedBytes = 0;
        $catalogs = [];
        $sourceCache = [];
        $replacements = [];

        foreach ($pairs as $pair) {
            $profile = ModelCatalog::wallpaperProfileByKey((string)($pair['profile'] ?? ''));
            if ($profile === null) { return ['ok'=>false, 'message'=>'A rendered wallpaper uses an unsupported profile']; }
            $key = (string)$profile['key'];
            if (isset($seen[$key])) { return ['ok'=>false, 'message'=>'A wallpaper batch cannot contain the same profile twice']; }
            $seen[$key] = true;
            $dir = preg_replace('/[^A-Za-z0-9x_-]/', '', (string)($pair['dir'] ?? ''));
            if (!hash_equals((string)$profile['dir'], $dir)) {
                return ['ok'=>false, 'message'=>'Rendered wallpaper directory does not match its declared profile'];
            }
            $owned = (array)($manifest['variants'][$key] ?? self::wallpaperVariantSkeleton($setId, $profile, $manifest));
            $enabled = !array_key_exists('enabled', $pair)
                || in_array($pair['enabled'], [true, 1, '1', 'true', 'on'], true);
            $invert = array_key_exists('invert', $pair)
                ? in_array($pair['invert'], [true, 1, '1', 'true', 'on'], true)
                : !empty($owned['invert']);
            if ($invert && (int)($profile['grayscale_tones'] ?? 0) < 2) {
                return ['ok'=>false, 'message'=>'Color wallpaper profiles cannot use grayscale inversion'];
            }
            $models = array_values(array_unique(array_map(
                static fn($model): string => strtoupper(trim((string)$model)),
                (array)($pair['models'] ?? $owned['models'] ?? $profile['models'])
            )));
            if (!$models || array_diff($models, (array)$profile['models'])) {
                return ['ok'=>false, 'message'=>'Wallpaper target models are outside the selected profile'];
            }
            $format = strtolower((string)$profile['format']);
            $sourceName = self::wallpaperSourceName((string)($owned['source'] ?? $manifest['source']));
            $sourceHash = (string)($owned['source_sha256'] ?? $manifest['source_sha256']);
            if (!isset($sourceCache[$sourceName])) {
                $sourceRead = $this->readBounded(
                    $sftp,
                    self::wallpaperSetPath($root, $setId) . '/' . $sourceName,
                    self::MAX_BASE64_READ_BYTES,
                    'wallpaper profile source'
                );
                $sourceCache[$sourceName] = !empty($sourceRead['ok'])
                    ? hash('sha256', (string)$sourceRead['content'])
                    : '';
            }
            if ($sourceCache[$sourceName] === '' || !hash_equals($sourceHash, $sourceCache[$sourceName])) {
                return ['ok'=>false, 'message'=>"Retained source for $key is missing or does not match its manifest"];
            }
            $fullData = '';
            $thumbData = '';
            $fullSize = null;
            $thumbSize = null;
            if ($enabled) {
                $fullData = base64_decode((string)($pair['full'] ?? ''), true);
                if ($fullData === false) {
                    return ['ok'=>false, 'message'=>"Rendered $key wallpaper is not valid base64 image data"];
                }
                try {
                    $fullData = self::normalizeWallpaperPayload($fullData, $profile, false);
                } catch (\Throwable $error) {
                    return ['ok'=>false, 'message'=>"Rendered $key wallpaper is invalid: " . $error->getMessage()];
                }
                $fullSize = self::imageDimensions($fullData, $format);
                if ($fullSize !== [(int)$profile['width'], (int)$profile['height']]
                    || strlen($fullData) > (int)$profile['max_bytes']) {
                    return ['ok'=>false, 'message'=>"Rendered $key wallpaper is invalid, oversized, or not exactly {$profile['width']}x{$profile['height']}"];
                }
                if ((int)$profile['thumbnail_width'] > 0) {
                    $thumbData = base64_decode((string)($pair['thumb'] ?? ''), true);
                    if ($thumbData === false) {
                        return ['ok'=>false, 'message'=>"Rendered $key thumbnail is not valid base64 image data"];
                    }
                    try {
                        $thumbData = self::normalizeWallpaperPayload($thumbData, $profile, true);
                    } catch (\Throwable $error) {
                        return ['ok'=>false, 'message'=>"Rendered $key thumbnail is invalid: " . $error->getMessage()];
                    }
                    $thumbSize = self::imageDimensions($thumbData, 'png');
                    if ($thumbSize !== [(int)$profile['thumbnail_width'], (int)$profile['thumbnail_height']]) {
                        return ['ok'=>false, 'message'=>"Rendered $key thumbnail has invalid data or dimensions"];
                    }
                }
                $decodedBytes += strlen($fullData) + strlen($thumbData);
                if ($decodedBytes > 32 * 1024 * 1024) {
                    return ['ok'=>false, 'message'=>'Rendered wallpaper batch exceeds 32 MiB'];
                }
            }
            $fullHash = $enabled ? hash('sha256', $fullData) : (string)($owned['full_sha256'] ?? '');
            $thumbnailHash = $enabled && $thumbData !== ''
                ? hash('sha256', $thumbData)
                : (string)($owned['thumbnail_sha256'] ?? '');
            $names = $enabled
                ? self::wallpaperOutputNames($setId, $profile, $fullHash, $thumbnailHash)
                : [
                    'full'=>(string)($owned['full'] ?? self::wallpaperOutputNames($setId, $profile)['full']),
                    'thumbnail'=>(string)($owned['thumbnail'] ?? self::wallpaperOutputNames($setId, $profile)['thumbnail']),
                ];
            $fullName = $names['full'];
            $thumbName = $names['thumbnail'];
            $oldFullName = (string)($owned['full'] ?? '');
            $oldThumbName = (string)($owned['thumbnail'] ?? '');
            if ($profile['delivery'] === 'cisco_list') {
                if ($enabled) {
                    foreach (['full'=>$fullName, 'thumbnail'=>$thumbName] as $role=>$filename) {
                        if ($filename === '') { continue; }
                        $target = $root . '/Desktops/' . $dir . '/' . $filename;
                        if ($this->remoteExists($sftp, $target)
                            && (!isset($owned[$role]) || !hash_equals((string)$owned[$role], $filename))
                            && !self::wallpaperAliasOwns($manifest, $setId, $key, $filename, $role)) {
                            return ['ok'=>false, 'message'=>"Wallpaper set ID $setId would overwrite unowned file $filename"];
                        }
                    }
                }
                $catalogs[$dir] = true;
            } elseif ($enabled) {
                $ownedModels = array_map('strtoupper', (array)($owned['models'] ?? []));
                foreach ($models as $model) {
                    $existing = $this->hostedWallpaperAsset($siteId, $profile, $model, $fullName, false);
                    if ($existing !== null
                        && (!hash_equals((string)($owned['full'] ?? ''), $fullName)
                            && !self::wallpaperAliasOwns($manifest, $setId, $key, $fullName, 'full')
                            || !in_array($model, $ownedModels, true))) {
                        return ['ok'=>false, 'message'=>"Wallpaper set ID $setId would overwrite unowned {$profile['vendor']} $model file $fullName"];
                    }
                }
            }
            $transform = self::wallpaperTransform(
                $pair['transform'] ?? [
                    'full'=>$owned['full_transform'] ?? [],
                    'thumbnail'=>$owned['thumbnail_transform'] ?? [],
                ],
                2
            );
            if ($enabled && $oldFullName !== '' && !hash_equals($oldFullName, $fullName)
                && (string)($owned['full_sha256'] ?? '') !== '') {
                $manifest['aliases'][] = [
                    'set_id'=>$setId,
                    'profile'=>$key,
                    'models'=>array_values((array)($owned['models'] ?? $models)),
                    'dir'=>$dir,
                    'full'=>$oldFullName,
                    'thumbnail'=>$oldThumbName,
                    'full_sha256'=>(string)($owned['full_sha256'] ?? ''),
                    'thumbnail_sha256'=>(string)($owned['thumbnail_sha256'] ?? ''),
                ];
                $replacements[] = ['old'=>$oldFullName, 'new'=>$fullName];
            }
            $manifest['variants'][$key] = [
                'profile'=>$key,
                'vendor'=>(string)$profile['vendor'],
                'family'=>(string)$profile['family'],
                'series_key'=>(string)$profile['series_key'],
                'delivery'=>(string)$profile['delivery'],
                'models'=>$models,
                'dir'=>$dir,
                'enabled'=>$enabled,
                'invert'=>$invert,
                'source'=>$sourceName,
                'source_sha256'=>$sourceHash,
                'rendered_source_sha256'=>$enabled
                    ? $sourceHash
                    : (string)($owned['rendered_source_sha256'] ?? ''),
                'full'=>$fullName,
                'thumbnail'=>$thumbName,
                'full_sha256'=>$fullHash,
                'thumbnail_sha256'=>$thumbnailHash,
                'full_transform'=>$transform['full'],
                'thumbnail_transform'=>$transform['thumbnail'],
            ];
            $ownedModels = array_values((array)($owned['models'] ?? []));
            $validated[] = compact('profile','models','ownedModels','enabled','dir','fullName','thumbName','oldFullName','oldThumbName','fullData','thumbData','fullSize','thumbSize');
        }

        $manifest['aliases'] = self::uniqueWallpaperAliases((array)($manifest['aliases'] ?? []));
        if (count($manifest['aliases']) > 400) {
            return ['ok'=>false, 'message'=>'This wallpaper catalog has retained too many compatibility filenames; rename or replace the catalog before another render'];
        }

        $fileWrites = [];
        foreach (array_keys($catalogs) as $dir) {
            $this->ensureRemoteDirectories($sftp, [$root . '/Desktops', $root . '/Desktops/' . $dir]);
        }
        foreach ($validated as $item) {
            if (!$item['enabled'] || $item['profile']['delivery'] !== 'cisco_list') { continue; }
            $fileWrites[$root . '/Desktops/' . $item['dir'] . '/' . $item['fullName']] = [
                'content'=>$item['fullData'], 'mode'=>0664,
            ];
            $fileWrites[$root . '/Desktops/' . $item['dir'] . '/' . $item['thumbName']] = [
                'content'=>$item['thumbData'], 'mode'=>0664,
            ];
        }
        $manifest['updated'] = time();
        $fileWrites[self::wallpaperSetPath($root, $setId) . '/manifest.json'] = [
            'content'=>self::remoteJsonContent($manifest), 'mode'=>0660,
        ];

        $hostedResults = [];
        $publishStage = 'hosted_files';
        $hostedReferenceReplacements = [];
        try {
            foreach ($validated as $item) {
                if (!$item['enabled'] || $item['profile']['delivery'] !== 'hosted_http') { continue; }
                foreach ($item['models'] as $model) {
                    $prior = $item['oldFullName'] !== ''
                        ? $this->hostedWallpaperAsset($siteId, $item['profile'], $model, $item['oldFullName'], false)
                        : null;
                    $hostedResults[] = ['family'=>$item['profile']['family'], 'model'=>$model, 'name'=>$item['fullName'], 'status'=>'unknown'];
                    $hostedIndex = array_key_last($hostedResults);
                    $uploaded = $this->ucm->mediaAssetOp([
                        'op'=>'upload', 'site_id'=>$siteId,
                        'family'=>$item['profile']['family'], 'model'=>strtolower($model),
                        'name'=>$item['fullName'], 'content'=>base64_encode($item['fullData']),
                    ]);
                    $hostedResults[$hostedIndex]['status'] = !empty($uploaded['ok']) ? 'published' : 'failed';
                    $hostedResults[$hostedIndex]['result'] = $uploaded;
                    if (empty($uploaded['ok'])) {
                        throw new \RuntimeException((string)($uploaded['message'] ?? "Could not publish {$item['fullName']}"));
                    }
                    if (!hash_equals($item['fullName'], (string)($uploaded['name'] ?? ''))) {
                        throw new \RuntimeException('The media receiver changed the catalog filename; update the receiver before publishing this catalog');
                    }
                    if ($item['oldFullName'] !== ''
                        && !hash_equals($item['oldFullName'], $item['fullName'])
                        && !empty($prior['url'])
                        && !empty($uploaded['url'])) {
                        $hostedReferenceReplacements[] = [
                            'old'=>(string)$prior['url'],
                            'new'=>(string)$uploaded['url'],
                        ];
                    }
                }
            }
            $publishStage = 'tftp_files';
            $this->replaceRemoteFiles($sftp, $fileWrites);
        } catch (\Throwable $error) {
            $recovery = $hostedResults !== [] || ($error instanceof RpcOperationException && !empty($error->receipt['recovery_required']));
            return ['ok'=>false, 'status'=>$recovery ? 'recovery_required' : 'failed', 'recovery_required'=>$recovery,
                'failed_stage'=>$publishStage, 'hosted_results'=>$hostedResults,
                'file_replacements'=>$error instanceof RpcOperationException ? ($error->receipt['file_replacements'] ?? []) : [],
                'message'=>'Wallpaper publication stopped at ' . $publishStage . ': ' . $error->getMessage()
                    . ($recovery ? '. Completed and uncertain stages were retained; reconcile before retrying.' : '')];
        }

        $registeredAssets = [];
        $registryWarnings = [];
        $disableErrors = [];
        $referenceResult = ['ok'=>true, 'message'=>'No existing wallpaper reference needed an update'];
        $allReplacements = self::uniqueWallpaperReplacements(array_merge($replacements, $hostedReferenceReplacements));
        if ($allReplacements) {
            try {
                if (!method_exists($this->ucm, 'replaceWallpaperReferences')) {
                    throw new \RuntimeException('Wallpaper reference propagation is unavailable');
                }
                $referenceResult = $this->ucm->replaceWallpaperReferences($siteId, $allReplacements);
                if (empty($referenceResult['ok'])) {
                    throw new \RuntimeException((string)($referenceResult['message'] ?? 'Wallpaper references were not updated'));
                }
            } catch (\Throwable $referenceError) {
                // Compatibility aliases remain published, so existing phones and
                // configs still work. Surface the deferred DB update explicitly.
                $referenceResult = ['ok'=>false, 'message'=>$referenceError->getMessage()];
                $registryWarnings[] = 'New cache-safe wallpaper files were published, but saved references still use retained compatibility filenames: '
                    . $referenceError->getMessage();
            }
        }
        // Disabling hides this profile from selection. Existing phones and
        // retained generations may still request its content-addressed URLs.
        // Keep those owned bytes until explicit reference-checked retirement.

        foreach ($validated as $item) {
            if (!$item['enabled'] || $item['profile']['delivery'] !== 'cisco_list') { continue; }
            foreach ([
                ['name'=>$item['fullName'], 'data'=>$item['fullData'], 'size'=>$item['fullSize'], 'role'=>'full', 'companion'=>$item['thumbName'], 'companion_hash'=>hash('sha256', $item['thumbData'])],
                ['name'=>$item['thumbName'], 'data'=>$item['thumbData'], 'size'=>$item['thumbSize'], 'role'=>'thumbnail', 'companion'=>$item['fullName'], 'companion_hash'=>hash('sha256', $item['fullData'])],
            ] as $asset) {
                $registered = $this->registerManagedPayload(
                    $siteId,
                    'Desktops/' . $item['dir'] . '/' . $asset['name'],
                    hash('sha256', $asset['data']),
                    strlen($asset['data']),
                    [
                        'profile'=>$item['profile']['key'],
                        'bundle_key'=>'wallpaper:' . strtolower($setId),
                        'bundle_role'=>$asset['role'],
                        'validation_status'=>'verified',
                        'provenance'=>'generated',
                        'signature_status'=>'not_applicable',
                        'metadata'=>[
                            'width'=>$asset['size'][0], 'height'=>$asset['size'][1],
                            'format'=>'png', 'magic'=>'PNG',
                        ],
                        'companions'=>[[
                            'kind'=>'background', 'content_sha256'=>$asset['companion_hash'],
                            'filename'=>$asset['companion'],
                            'relation'=>$asset['role'] === 'full' ? 'thumbnail' : 'full',
                            'required'=>true,
                        ]],
                    ]
                );
                if (isset($registered['asset'])) { $registeredAssets[] = $registered['asset']; }
                if (isset($registered['warning'])) { $registryWarnings[] = $registered['warning']; }
            }
        }
        $catalogResults = [];
        foreach (array_keys($catalogs) as $dir) {
            $catalogResults[$dir] = $this->regenerateImageList(
                $siteId, $sftp, $root . '/Desktops/' . $dir, 'Desktops/' . $dir
            );
            if (isset($catalogResults[$dir]['asset'])) { $registeredAssets[] = $catalogResults[$dir]['asset']; }
            if (!empty($catalogResults[$dir]['registry_warning'])) { $registryWarnings[] = $catalogResults[$dir]['registry_warning']; }
            if (empty($catalogResults[$dir]['ok'])) {
                return ['ok'=>false,'status'=>'partial','publication_completed'=>true,'recovery_required'=>true,
                    'hosted_results'=>$hostedResults,'catalogs'=>$catalogResults,
                    'message'=>'Wallpaper files and manifest were published; Cisco catalog indexing needs recovery: '.(string)$catalogResults[$dir]['message']];
            }
        }
        $written = array_sum(array_map(
            static fn(array $item): int => !$item['enabled']
                ? 0
                : ($item['profile']['delivery'] === 'cisco_list' ? 2 : count($item['models'])),
            $validated
        ));
        if ($disableErrors) {
            return [
                'ok'=>false,
                'written'=>$written,
                'variants'=>count($validated),
                'catalogs'=>$catalogResults,
                'errors'=>array_values(array_unique($disableErrors)),
                'message'=>'Profile state was retained, but one or more published files could not be retired',
            ];
        }
        return [
            'ok'=>true,
            'written'=>$written,
            'variants'=>count($validated),
            'catalogs'=>$catalogResults,
            'assets'=>$registeredAssets,
            'references'=>$referenceResult,
            'registry_warnings'=>array_values(array_unique($registryWarnings)),
            'disabled'=>count(array_filter($validated, static fn(array $item): bool => !$item['enabled'])),
            'message'=>'Saved '.count($validated).' wallpaper profile(s); published '.$written.' device-facing file(s)'
                . ($catalogResults ? ' and rebuilt '.count($catalogResults).' Cisco catalog(s)' : ''),
        ];
    }

    /** Restore only the exact bytes renamed by the former generic HTTP uploader. */
    private function repairWallpaperHostedNames(int $siteId, $sftp, string $root, string $setId): array
    {
        $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
        $pending = [];
        foreach ($manifest['variants'] as $variant) {
            $profile = ModelCatalog::wallpaperProfileByKey((string)$variant['profile']);
            if (empty($variant['enabled']) || ($profile['delivery'] ?? '') !== 'hosted_http') { continue; }
            $name = (string)$variant['full'];
            $hash = (string)$variant['full_sha256'];
            if (preg_match('/^w[a-f0-9]{12}\.(?:png|jpg)$/D', $name) !== 1) { continue; }
            foreach ($variant['models'] as $model) {
                $existing = $this->hostedWallpaperAsset($siteId, $profile, (string)$model, $name, true);
                $fallback = AssetFilename::fromHash('h', $hash, (string)$profile['format']);
                $asset = $existing ?? $this->hostedWallpaperAsset($siteId, $profile, (string)$model, $fallback, true);
                if (!isset($asset['content']) || !hash_equals($hash, hash('sha256', (string)$asset['content']))
                    || self::imageDimensions((string)$asset['content'], (string)$profile['format']) !== [(int)$profile['width'], (int)$profile['height']]) {
                    return ['ok'=>false, 'repaired'=>0, 'message'=>"Cannot verify {$profile['vendor']} $model $name; no filenames were repaired"];
                }
                if ($existing !== null) { continue; }
                // Validate every source before the first write. Reads never repair.
                $pending[] = ['profile'=>$profile, 'model'=>(string)$model, 'name'=>$name, 'hash'=>$hash];
            }
        }
        $results = [];
        foreach ($pending as $item) {
            $results[] = ['model'=>$item['model'], 'name'=>$item['name'], 'status'=>'unknown'];
            $index = array_key_last($results);
            try {
                $fallback = AssetFilename::fromHash('h', $item['hash'], (string)$item['profile']['format']);
                $source = $this->hostedWallpaperAsset($siteId, $item['profile'], $item['model'], $fallback, true);
                if (!isset($source['content']) || !hash_equals($item['hash'], hash('sha256', (string)$source['content']))) {
                    throw new \RuntimeException('The verified source changed before publication');
                }
                $uploaded = $this->ucm->mediaAssetOp([
                    'op'=>'upload', 'site_id'=>$siteId, 'family'=>$item['profile']['family'],
                    'model'=>strtolower($item['model']), 'name'=>$item['name'], 'content'=>base64_encode($source['content']),
                ]);
                if (empty($uploaded['ok']) || !hash_equals($item['name'], (string)($uploaded['name'] ?? ''))) {
                    throw new \RuntimeException('The receiver did not confirm the catalog filename; update it before retrying');
                }
                $published = $this->hostedWallpaperAsset($siteId, $item['profile'], $item['model'], $item['name'], true);
                if (!isset($published['content']) || !hash_equals($item['hash'], hash('sha256', (string)$published['content']))) {
                    throw new \RuntimeException('Published wallpaper verification failed');
                }
                $results[$index]['status'] = 'verified';
            } catch (\Throwable $error) {
                // A lost reply is not a reason to replay a write. Reconcile the
                // immutable destination before continuing to another model.
                try {
                    $confirmed = $this->hostedWallpaperAsset($siteId, $item['profile'], $item['model'], $item['name'], true);
                    if (isset($confirmed['content']) && hash_equals($item['hash'], hash('sha256', (string)$confirmed['content']))) {
                        $results[$index]['status'] = 'verified';
                        continue;
                    }
                } catch (\Throwable) {}
                return ['ok'=>false, 'recovery_required'=>true, 'repaired'=>$index, 'results'=>$results,
                    'message'=>'Hosted filename repair stopped: ' . $error->getMessage() . '. Publication could not be reconciled; inspect the retained operation and storage lock before retrying.'];
            }
        }
        return ['ok'=>true, 'repaired'=>count($results), 'results'=>$results,
            'message'=>count($results) . ' hosted wallpaper filename(s) repaired; existing images and catalog settings retained'];
    }

    /** Re-encode already-rendered Cisco outputs without changing their crop or retained source. */
    private function repairWallpaperSetEncoding(int $siteId, $sftp, string $root, string $setId): array
    {
        $setId = self::wallpaperSetId($setId);
        $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
        $pairs = [];
        foreach ((array)($manifest['variants'] ?? []) as $variant) {
            if (empty($variant['enabled'])) { continue; }
            $profile = ModelCatalog::wallpaperProfileByKey((string)($variant['profile'] ?? ''));
            if ($profile === null || (string)($profile['delivery'] ?? '') !== 'cisco_list') {
                continue;
            }
            $full = $this->readBounded(
                $sftp,
                $root . '/Desktops/' . $profile['dir'] . '/' . (string)$variant['full'],
                self::MAX_BASE64_READ_BYTES,
                'Cisco wallpaper'
            );
            $thumbnail = $this->readBounded(
                $sftp,
                $root . '/Desktops/' . $profile['dir'] . '/' . (string)$variant['thumbnail'],
                self::MAX_BASE64_READ_BYTES,
                'Cisco wallpaper thumbnail'
            );
            if (empty($full['ok']) || empty($thumbnail['ok'])) {
                return [
                    'ok'=>false,
                    'message'=>(string)($full['message'] ?? $thumbnail['message'] ?? 'Cisco wallpaper pair is unreadable'),
                ];
            }
            $fullIssue = self::wallpaperEncodingIssue((string)$full['content'], $profile, false);
            $thumbnailIssue = self::wallpaperEncodingIssue((string)$thumbnail['content'], $profile, true);
            if ($fullIssue === null && $thumbnailIssue === null) { continue; }
            $pairs[] = [
                'profile'=>(string)$profile['key'],
                'dir'=>(string)$profile['dir'],
                'models'=>array_values((array)($variant['models'] ?? $profile['models'])),
                'enabled'=>1,
                'full'=>base64_encode((string)$full['content']),
                'thumb'=>base64_encode((string)$thumbnail['content']),
                'transform'=>[
                    'full'=>(array)($variant['full_transform'] ?? []),
                    'thumbnail'=>(array)($variant['thumbnail_transform'] ?? []),
                ],
            ];
        }
        if (!$pairs) {
            return ['ok'=>true, 'repaired'=>0, 'message'=>'All enabled Cisco wallpaper outputs are already device-safe'];
        }

        $repaired = 0;
        $warnings = [];
        foreach (array_chunk($pairs, 20) as $batch) {
            $result = $this->saveWallpaperVariants($siteId, $sftp, $root, [
                'intent'=>'update',
                'set_id'=>$setId,
                'set_name'=>(string)($manifest['name'] ?? $setId),
                'pairs'=>$batch,
            ]);
            if (empty($result['ok'])) { return $result + ['repaired'=>$repaired]; }
            $repaired += count($batch);
            $warnings = array_merge($warnings, (array)($result['registry_warnings'] ?? []));
        }
        return [
            'ok'=>true,
            'repaired'=>$repaired,
            'registry_warnings'=>array_values(array_unique($warnings)),
            'message'=>"Re-encoded $repaired Cisco wallpaper profile(s) without changing their crop or retained source",
        ];
    }

    /** @param object $sftp */
    private function wallpaperSetList(int $siteId, $sftp, string $root): array
    {
        $directory = $root . '/' . self::WALLPAPER_SET_ROOT;
        if (!$sftp->is_dir($directory)) {
            return ['ok'=>true, 'sets'=>[], 'issues'=>[]];
        }
        $entries = $sftp->nlist($directory);
        if ($entries === false) { return ['ok'=>false, 'message'=>'Cannot list managed wallpaper sets']; }
        $sets = [];
        $issues = [];
        foreach ((array)$entries as $candidate) {
            if ($candidate === '.' || $candidate === '..') { continue; }
            try {
                $id = self::wallpaperSetId((string)$candidate);
                $manifest = $this->readWallpaperSetManifest($sftp, $root, $id);
                $variants = array_values((array)$manifest['variants']);
                $setIssues = [];
                $sourcePath = self::wallpaperSetPath($root, $id) . '/' . $manifest['source'];
                $sourceRead = $this->readBounded($sftp, $sourcePath, self::MAX_BASE64_READ_BYTES, 'wallpaper source');
                $sourceCurrent = !empty($sourceRead['ok'])
                    && hash_equals((string)$manifest['source_sha256'], hash('sha256', (string)$sourceRead['content']));
                if (!$sourceCurrent) {
                    $setIssues[] = empty($sourceRead['ok'])
                        ? (string)($sourceRead['message'] ?? 'Retained source is missing')
                        : 'Retained source hash does not match its manifest';
                }
                $current = 0;
                $enabledCount = 0;
                $disabledCount = 0;
                $sourceHashes = [(string)$manifest['source'] => $sourceCurrent ? (string)$manifest['source_sha256'] : ''];
                foreach ($variants as &$variant) {
                    $variantIssues = [];
                    $profile = ModelCatalog::wallpaperProfileByKey((string)$variant['profile']);
                    $enabled = !empty($variant['enabled']);
                    $enabled ? $enabledCount++ : $disabledCount++;
                    $variantSource = (string)$variant['source'];
                    if (!array_key_exists($variantSource, $sourceHashes)) {
                        $variantSourceRead = $this->readBounded(
                            $sftp,
                            self::wallpaperSetPath($root, $id) . '/' . $variantSource,
                            self::MAX_BASE64_READ_BYTES,
                            'wallpaper profile source'
                        );
                        $sourceHashes[$variantSource] = !empty($variantSourceRead['ok'])
                            ? hash('sha256', (string)$variantSourceRead['content'])
                            : '';
                    }
                    $variantSourceCurrent = $sourceHashes[$variantSource] !== ''
                        && hash_equals((string)$variant['source_sha256'], $sourceHashes[$variantSource]);
                    if ($profile === null) {
                        $variantIssues[] = 'Wallpaper profile is no longer supported';
                    } elseif (!$variantSourceCurrent) {
                        $variantIssues[] = 'Retained profile source is missing or changed';
                    } elseif ($enabled
                        && !hash_equals(
                            (string)$variant['source_sha256'],
                            (string)($variant['rendered_source_sha256'] ?? '')
                        )) {
                        $variantIssues[] = 'Enabled output has not been regenerated from its selected source';
                    } elseif (!$enabled) {
                        // Disabled profiles are complete when their source and
                        // settings remain recoverable; published files are absent.
                    } elseif ($profile['delivery'] === 'hosted_http') {
                        foreach ((array)$variant['models'] as $model) {
                            $asset = $this->hostedWallpaperAsset(
                                $siteId, $profile, (string)$model, (string)$variant['full'], true
                            );
                            if ($asset === null || !isset($asset['content'])) {
                                $variantIssues[] = "{$profile['vendor']} $model wallpaper is missing or unreadable";
                                continue;
                            }
                            $dimensions = self::imageDimensions((string)$asset['content'], (string)$profile['format']);
                            if ($dimensions !== [(int)$profile['width'], (int)$profile['height']]) {
                                $variantIssues[] = "{$profile['vendor']} $model wallpaper dimensions do not match the profile";
                            }
                            if (($variant['full_sha256'] ?? '') !== ''
                                && !hash_equals((string)$variant['full_sha256'], hash('sha256', (string)$asset['content']))) {
                                $variantIssues[] = "{$profile['vendor']} $model wallpaper content differs from the managed set";
                            }
                            if (!isset($variant['url']) && isset($asset['url'])) { $variant['url'] = $asset['url']; }
                        }
                    } else {
                        foreach (['full'=>[0,1], 'thumbnail'=>[2,3]] as $role=>$indexes) {
                            $path = $root . '/Desktops/' . $profile['dir'] . '/' . $variant[$role];
                            $output = $this->readBounded($sftp,$path,self::MAX_BASE64_READ_BYTES,'wallpaper output');
                            $header = !empty($output['ok']) ? (string)$output['content'] : false;
                            $dimensions = is_string($header) ? self::pngDimensions($header) : null;
                            $expected = $role === 'full'
                                ? [(int)$profile['width'], (int)$profile['height']]
                                : [(int)$profile['thumbnail_width'], (int)$profile['thumbnail_height']];
                            if ($dimensions === null) {
                                $variantIssues[] = ucfirst($role) . ' PNG is missing or unreadable';
                            } elseif ((string)($variant[$role . '_sha256'] ?? '') !== ''
                                && !hash_equals((string)$variant[$role . '_sha256'],hash('sha256',(string)$header))) {
                                $variantIssues[] = ucfirst($role) . ' PNG content differs from its managed manifest';
                            } elseif ($dimensions !== $expected) {
                                $variantIssues[] = ucfirst($role) . ' PNG dimensions do not match the exact profile';
                            } else {
                                $encodingIssue = self::wallpaperEncodingIssue(
                                    (string)$header,
                                    $profile,
                                    $role === 'thumbnail'
                                );
                                if ($encodingIssue !== null) {
                                    $variantIssues[] = ucfirst($role) . ' ' . $encodingIssue;
                                }
                            }
                        }
                    }
                    $variant['enabled'] = $enabled;
                    $variant['source_mode'] = hash_equals((string)$manifest['source'], $variantSource)
                        ? 'master'
                        : 'custom';
                    $variant['generated'] = (string)($variant['full_sha256'] ?? '') !== '';
                    $variant['current'] = $variantSourceCurrent && !$variantIssues;
                    $variant['issues'] = $variantIssues;
                    if ($variant['current']) { $current++; }
                    foreach ($variantIssues as $message) {
                        $setIssues[] = $variant['profile'] . ': ' . $message;
                    }
                }
                unset($variant);
                foreach ($setIssues as $message) {
                    $issues[] = ['set_id'=>$id, 'message'=>$message];
                }
                $scope = (array)($manifest['original_scope'] ?? []);
                $scopeLabel = trim((string)($scope['label'] ?? ''));
                if ($scopeLabel === '') {
                    $vendors = array_values(array_unique(array_map(
                        static fn(array $variant): string => (string)($variant['vendor'] ?? ''),
                        $variants
                    )));
                    $scopeLabel = count($vendors) === 1 && $vendors[0] !== ''
                        ? $vendors[0] . ' catalog'
                        : 'Supported fleet';
                }
                $modifiedScopes = [];
                foreach ($variants as $variant) {
                    $profile = ModelCatalog::wallpaperProfileByKey((string)$variant['profile']);
                    $default = self::wallpaperTransform([], 2);
                    $tuned = $variant['full_transform'] !== $default['full']
                        || $variant['thumbnail_transform'] !== $default['thumbnail'];
                    $narrow = $profile !== null
                        && count((array)$variant['models']) < count((array)$profile['models']);
                    $customSource = ($variant['source_mode'] ?? 'master') === 'custom';
                    $disabled = empty($variant['enabled']);
                    $inverted = !empty($variant['invert']);
                    if ($tuned || $narrow || $customSource || $disabled || $inverted) {
                        $modifiedScopes[] = (string)$variant['profile']
                            . ($disabled
                                ? ' (disabled)'
                                : ($customSource
                                    ? ' (alternate source)'
                                    : ($narrow
                                        ? ' (' . implode(', ', (array)$variant['models']) . ')'
                                        : ($inverted ? ' (inverted grayscale)' : ''))));
                    }
                }
                $sets[] = [
                    'id'=>$id,
                    'revision'=>hash('sha256',self::remoteJsonContent($manifest)),
                    'name'=>(string)$manifest['name'],
                    'source'=>(string)$manifest['source'],
                    'source_sha256'=>(string)$manifest['source_sha256'],
                    'source_current'=>$sourceCurrent,
                    'variant_count'=>count($variants),
                    'enabled_variant_count'=>$enabledCount,
                    'disabled_variant_count'=>$disabledCount,
                    'current_variant_count'=>$current,
                    'complete'=>$sourceCurrent && count($variants) > 0 && $current === count($variants),
                    'issues'=>$setIssues,
                    'variants'=>$variants,
                    'original_scope'=>$scope,
                    'scope_label'=>$scopeLabel,
                    'modified_scopes'=>array_values(array_unique($modifiedScopes)),
                    'compatibility_alias_count'=>count((array)($manifest['aliases'] ?? [])),
                    'updated'=>(int)($manifest['updated'] ?? 0),
                ];
            } catch (\Throwable $error) {
                $issues[] = ['set_id'=>(string)$candidate, 'message'=>$error->getMessage()];
            }
        }
        usort($sets, static fn(array $left, array $right): int =>
            strnatcasecmp((string)$left['name'], (string)$right['name'])
        );
        return ['ok'=>true, 'sets'=>$sets, 'issues'=>$issues];
    }

    /** @param object $sftp */
    private function wallpaperSetGet($sftp, string $root, string $setId): array
    {
        $setId = self::wallpaperSetId($setId);
        $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
        $sourcePath = self::wallpaperSetPath($root, $setId) . '/' . $manifest['source'];
        $read = $this->readBounded($sftp, $sourcePath, self::MAX_BASE64_READ_BYTES, 'wallpaper source');
        if (empty($read['ok'])) { return $read; }
        if (!hash_equals((string)$manifest['source_sha256'],hash('sha256',(string)$read['content']))) {
            return ['ok'=>false,'message'=>'Retained master source no longer matches its manifest'];
        }
        return [
            'ok'=>true,
            'manifest'=>$manifest + ['editor_revision'=>hash('sha256',self::remoteJsonContent($manifest))],
            'source'=>base64_encode((string)$read['content']),
            'mime'=>str_ends_with(strtolower((string)$manifest['source']), '.png')
                ? 'image/png'
                : 'image/jpeg',
        ];
    }

    /** @param object $sftp */
    private function wallpaperSetSource(
        $sftp,
        string $root,
        string $setId,
        string $setName,
        string $encoded,
        string $mime,
        string $intent,
        string $scopeEncoded = ''
    ): array {
        $setId = self::wallpaperSetId($setId);
        $setName = self::wallpaperSetName($setName !== '' ? $setName : $setId);
        if (!in_array($intent, ['create','update'], true)) {
            return ['ok'=>false, 'message'=>'Wallpaper source save requires an explicit create or update intent'];
        }
        try {
            $source = self::wallpaperSourcePayload($encoded, $mime);
        } catch (\Throwable $error) {
            return ['ok'=>false, 'message'=>$error->getMessage()];
        }
        $content = $source['content'];
        $sourceHash = $source['sha256'];
        $sourceName = $source['name'];
        $setPath = self::wallpaperSetPath($root, $setId);
        $retiredPath = '';
        try {
            $existing = $this->readWallpaperSetManifest($sftp, $root, $setId, false);
        } catch (\Throwable $error) {
            if ($intent !== 'create') {
                return ['ok'=>false, 'message'=>$error->getMessage()];
            }
            try {
                $retiredPath = $this->retireIncompatibleWallpaperSet($sftp, $root, $setId);
                $existing = [];
            } catch (\Throwable $retireError) {
                return ['ok'=>false, 'message'=>'The incompatible retained catalog could not be retired safely: ' . $retireError->getMessage()];
            }
        }
        if ($intent === 'create' && $existing) {
            return ['ok'=>false, 'message'=>"Wallpaper set ID $setId already belongs to {$existing['name']}; choose another name"];
        }
        if ($intent === 'update' && !$existing) {
            return ['ok'=>false, 'message'=>"Wallpaper set $setId no longer exists; refresh the catalog before saving"];
        }
        if ($intent === 'create') {
            foreach (array_unique(ModelCatalog::BACKGROUND_DIRS) as $directory) {
                foreach (ModelCatalog::backgroundProfilesForDirectory($directory) as $profile) {
                    $base = self::wallpaperOutputBase($setId, $profile);
                    foreach (["$base.png", "$base-tn.png"] as $filename) {
                        if ($this->remoteExists($sftp, $root . '/Desktops/' . $directory . '/' . $filename)) {
                            return ['ok'=>false, 'message'=>"Wallpaper set ID $setId conflicts with existing unowned file $filename"];
                        }
                    }
                }
            }
        }
        $scope = $existing['original_scope'] ?? [];
        if (!$scope && $scopeEncoded !== '') {
            $scope = self::wallpaperScope($scopeEncoded);
        }
        $variants = (array)($existing['variants'] ?? []);
        $oldMaster = (string)($existing['source'] ?? '');
        foreach ($variants as &$variant) {
            $selectedSource = (string)($variant['source'] ?? $oldMaster);
            if ($oldMaster !== '' && hash_equals($oldMaster, $selectedSource)) {
                $variant['source'] = $sourceName;
                $variant['source_sha256'] = $sourceHash;
            }
        }
        unset($variant);
        $manifest = [
            'version'=>3,
            'id'=>$setId,
            'name'=>$setName,
            'source'=>$sourceName,
            'source_sha256'=>$sourceHash,
            'variants'=>$variants,
            'aliases'=>(array)($existing['aliases'] ?? []),
            'original_scope'=>$scope,
            'updated'=>time(),
        ];
        $this->ensureRemoteDirectories($sftp, [
            $root . '/.ucmprovisioner',
            $root . '/' . self::WALLPAPER_SET_ROOT,
            $setPath,
        ]);
        $this->replaceRemoteFiles($sftp, [
            $setPath . '/' . $sourceName => ['content'=>$content, 'mode'=>0660],
            $setPath . '/manifest.json' => ['content'=>self::remoteJsonContent($manifest), 'mode'=>0660],
        ]);
        $oldSource = (string)($existing['source'] ?? '');
        if ($oldSource !== '' && $oldSource !== $sourceName
            && !self::wallpaperSourceReferenced($manifest, $oldSource)
            && $this->remoteExists($sftp, $setPath . '/' . $oldSource)) {
            $sftp->delete($setPath . '/' . $oldSource);
        }
        return [
            'ok'=>true,
            'manifest'=>$manifest,
            'retired_path'=>$retiredPath,
            'message'=>$retiredPath !== ''
                ? "Incompatible retained catalog moved to $retiredPath; the new wallpaper source was saved"
                : 'Wallpaper source retained for later profile edits',
        ];
    }

    /** Move an unreadable retained set out of the active namespace before an explicit create. */
    private function retireIncompatibleWallpaperSet($sftp, string $root, string $setId): string
    {
        $setPath = self::wallpaperSetPath($root, $setId);
        if (!$sftp->is_dir($setPath)) {
            throw new \RuntimeException('The retained catalog directory no longer exists');
        }
        $retiredRoot = rtrim($root, '/') . '/.ucmprovisioner/wallpaper-sets-retired';
        $this->ensureRemoteDirectories($sftp, [
            rtrim($root, '/') . '/.ucmprovisioner',
            $retiredRoot,
        ]);
        $stamp = gmdate('YmdHis');
        $retiredPath = '';
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = $attempt === 0 ? '' : '-' . $attempt;
            $candidate = "$retiredRoot/$setId-$stamp$suffix";
            if (!$this->remoteExists($sftp, $candidate)) {
                $retiredPath = $candidate;
                break;
            }
        }
        if ($retiredPath === '' || !$sftp->rename($setPath, $retiredPath)) {
            throw new \RuntimeException('SFTP could not move the retained catalog into recoverable retirement storage');
        }
        return ltrim(substr($retiredPath, strlen(rtrim($root, '/'))), '/');
    }

    /** Retain one alternate source without publishing a device-facing output. */
    private function wallpaperProfileSourceSave(
        $sftp,
        string $root,
        string $setId,
        string $profileKey,
        string $encoded,
        string $mime
    ): array {
        $setId = self::wallpaperSetId($setId);
        $profile = ModelCatalog::wallpaperProfileByKey($profileKey);
        if ($profile === null) { return ['ok'=>false, 'message'=>'Choose a supported wallpaper profile']; }
        $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
        try {
            $source = self::wallpaperSourcePayload($encoded, $mime);
        } catch (\Throwable $error) {
            return ['ok'=>false, 'message'=>$error->getMessage()];
        }
        $key = (string)$profile['key'];
        $variant = (array)($manifest['variants'][$key] ?? self::wallpaperVariantSkeleton($setId, $profile, $manifest));
        $oldSource = (string)($variant['source'] ?? $manifest['source']);
        $variant['source'] = $source['name'];
        $variant['source_sha256'] = $source['sha256'];
        $manifest['version'] = 3;
        $manifest['variants'][$key] = $variant;
        $manifest['updated'] = time();
        $setPath = self::wallpaperSetPath($root, $setId);
        $this->ensureRemoteDirectories($sftp, [
            $root . '/.ucmprovisioner',
            $root . '/' . self::WALLPAPER_SET_ROOT,
            $setPath,
        ]);
        $this->replaceRemoteFiles($sftp, [
            $setPath . '/' . $source['name'] => ['content'=>$source['content'], 'mode'=>0660],
            $setPath . '/manifest.json' => ['content'=>self::remoteJsonContent($manifest), 'mode'=>0660],
        ]);
        $this->deleteUnreferencedWallpaperSource($sftp, $setPath, $manifest, $oldSource);
        return [
            'ok'=>true,
            'profile'=>$key,
            'source'=>$source['name'],
            'source_sha256'=>$source['sha256'],
            'source_mode'=>hash_equals((string)$manifest['source'], $source['name']) ? 'master' : 'custom',
            'message'=>'Profile source retained; save the profile render to publish it',
        ];
    }

    /** Return only the selected profile source so catalogs with many overrides stay bounded. */
    private function wallpaperProfileSourceGet($sftp, string $root, string $setId, string $profileKey): array
    {
        $setId = self::wallpaperSetId($setId);
        $profile = ModelCatalog::wallpaperProfileByKey($profileKey);
        if ($profile === null) { return ['ok'=>false, 'message'=>'Choose a supported wallpaper profile']; }
        $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
        $variant = (array)($manifest['variants'][(string)$profile['key']] ?? []);
        $sourceName = self::wallpaperSourceName((string)($variant['source'] ?? $manifest['source']));
        $sourceHash = (string)($variant['source_sha256'] ?? $manifest['source_sha256']);
        $read = $this->readBounded(
            $sftp,
            self::wallpaperSetPath($root, $setId) . '/' . $sourceName,
            self::MAX_BASE64_READ_BYTES,
            'wallpaper profile source'
        );
        if (empty($read['ok'])) { return $read; }
        if (!hash_equals($sourceHash, hash('sha256', (string)$read['content']))) {
            return ['ok'=>false, 'message'=>'The retained profile source hash does not match its manifest'];
        }
        return [
            'ok'=>true,
            'profile'=>(string)$profile['key'],
            'source_name'=>$sourceName,
            'source_sha256'=>$sourceHash,
            'source_mode'=>hash_equals((string)$manifest['source'], $sourceName) ? 'master' : 'custom',
            'source'=>base64_encode((string)$read['content']),
            'mime'=>str_ends_with(strtolower($sourceName), '.png') ? 'image/png' : 'image/jpeg',
        ];
    }

    /** Remove an alternate source while preserving that profile's placement and enabled state. */
    private function wallpaperProfileSourceReset($sftp, string $root, string $setId, string $profileKey): array
    {
        $setId = self::wallpaperSetId($setId);
        $profile = ModelCatalog::wallpaperProfileByKey($profileKey);
        if ($profile === null) { return ['ok'=>false, 'message'=>'Choose a supported wallpaper profile']; }
        $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
        $key = (string)$profile['key'];
        if (!isset($manifest['variants'][$key])) {
            return ['ok'=>true, 'profile'=>$key, 'source_mode'=>'master', 'message'=>'Profile already inherits the master source'];
        }
        $oldSource = (string)($manifest['variants'][$key]['source'] ?? $manifest['source']);
        $manifest['version'] = 3;
        $manifest['variants'][$key]['source'] = (string)$manifest['source'];
        $manifest['variants'][$key]['source_sha256'] = (string)$manifest['source_sha256'];
        $manifest['updated'] = time();
        $setPath = self::wallpaperSetPath($root, $setId);
        $this->writeRemoteFile(
            $sftp,
            $setPath . '/manifest.json',
            self::remoteJsonContent($manifest),
            0660
        );
        $this->deleteUnreferencedWallpaperSource($sftp, $setPath, $manifest, $oldSource);
        return [
            'ok'=>true,
            'profile'=>$key,
            'source'=>(string)$manifest['source'],
            'source_sha256'=>(string)$manifest['source_sha256'],
            'source_mode'=>'master',
            'message'=>'Profile now inherits the master source; save its render to publish the change',
        ];
    }

    /**
     * Rename the catalog and every generated filename. Published old filenames
     * remain as manifest-owned compatibility aliases until the catalog is
     * deleted, so phones using an already-generated config do not break.
     *
     * @param object $sftp
     */
    private function wallpaperSetRename(
        int $siteId,
        $sftp,
        string $root,
        string $oldId,
        string $newId,
        string $newName
    ): array {
        $oldId = self::wallpaperSetId($oldId);
        $newId = self::wallpaperSetId($newId);
        $newName = self::wallpaperSetName($newName !== '' ? $newName : $newId);
        $oldManifest = $this->readWallpaperSetManifest($sftp, $root, $oldId);
        if (hash_equals($oldId, $newId)) {
            $oldManifest['name'] = $newName;
            $oldManifest['updated'] = time();
            $this->writeRemoteFile(
                $sftp,
                self::wallpaperSetPath($root, $oldId) . '/manifest.json',
                self::remoteJsonContent($oldManifest),
                0660
            );
            return ['ok'=>true, 'manifest'=>$oldManifest, 'message'=>'Wallpaper catalog display name updated'];
        }
        if ($this->readWallpaperSetManifest($sftp, $root, $newId, false)) {
            return ['ok'=>false, 'message'=>"Wallpaper catalog ID $newId already exists"];
        }

        $oldSetPath = self::wallpaperSetPath($root, $oldId);
        $newSetPath = self::wallpaperSetPath($root, $newId);
        $sourceRead = $this->readBounded(
            $sftp,
            $oldSetPath . '/' . $oldManifest['source'],
            self::MAX_BASE64_READ_BYTES,
            'wallpaper source'
        );
        if (empty($sourceRead['ok'])
            || !hash_equals((string)$oldManifest['source_sha256'], hash('sha256', (string)($sourceRead['content'] ?? '')))) {
            return ['ok'=>false, 'message'=>'The retained wallpaper source is missing or changed; repair it before renaming'];
        }
        $retainedSources = [(string)$oldManifest['source'] => (string)$sourceRead['content']];
        foreach ((array)$oldManifest['variants'] as $variant) {
            $variantSource = (string)($variant['source'] ?? $oldManifest['source']);
            if (isset($retainedSources[$variantSource])) { continue; }
            $read = $this->readBounded(
                $sftp,
                $oldSetPath . '/' . self::wallpaperSourceName($variantSource),
                self::MAX_BASE64_READ_BYTES,
                'wallpaper profile source'
            );
            if (empty($read['ok'])
                || !hash_equals((string)$variant['source_sha256'], hash('sha256', (string)($read['content'] ?? '')))) {
                return ['ok'=>false, 'message'=>'A retained profile source is missing or changed; repair it before renaming'];
            }
            $retainedSources[$variantSource] = (string)$read['content'];
        }

        $newManifest = $oldManifest;
        $newManifest['version'] = 3;
        $newManifest['id'] = $newId;
        $newManifest['name'] = $newName;
        $newManifest['updated'] = time();
        $newManifest['aliases'] = array_values((array)($oldManifest['aliases'] ?? []));
        $newManifest['variants'] = [];
        $writes = [];
        $hostedUploads = [];
        $hostedResults = [];
        $registryPayloads = [];
        $affectedCatalogs = [];
        $replacements = [];

        foreach ((array)$oldManifest['variants'] as $key=>$variant) {
            $profile = ModelCatalog::wallpaperProfileByKey((string)$key);
            if ($profile === null) { throw new \RuntimeException("Unsupported wallpaper profile $key"); }
            $newNames = self::wallpaperOutputNames(
                $newId,
                $profile,
                (string)($variant['full_sha256'] ?? ''),
                (string)($variant['thumbnail_sha256'] ?? '')
            );
            $newFull = $newNames['full'];
            $newThumb = $newNames['thumbnail'];
            $newVariant = $variant;
            $newVariant['full'] = $newFull;
            $newVariant['thumbnail'] = $newThumb;
            $newManifest['variants'][$key] = $newVariant;
            $newManifest['aliases'][] = [
                'set_id'=>$oldId,
                'profile'=>(string)$key,
                'models'=>array_values((array)$variant['models']),
                'dir'=>(string)$profile['dir'],
                'full'=>(string)$variant['full'],
                'thumbnail'=>(string)$variant['thumbnail'],
                'full_sha256'=>(string)($variant['full_sha256'] ?? ''),
                'thumbnail_sha256'=>(string)($variant['thumbnail_sha256'] ?? ''),
            ];
            if (empty($variant['enabled'])) {
                if ($profile['delivery'] === 'cisco_list') {
                    $affectedCatalogs[(string)$profile['dir']] = true;
                }
                continue;
            }
            $replacements[] = ['old'=>(string)$variant['full'], 'new'=>$newFull];

            if ($profile['delivery'] === 'cisco_list') {
                $ciscoContents = [];
                foreach (['full'=>$newFull, 'thumbnail'=>$newThumb] as $role=>$newFilename) {
                    $oldFilename = (string)$variant[$role];
                    $oldPath = $root . '/Desktops/' . $profile['dir'] . '/' . $oldFilename;
                    $newPath = $root . '/Desktops/' . $profile['dir'] . '/' . $newFilename;
                    if ($this->remoteExists($sftp, $newPath)) {
                        return ['ok'=>false, 'message'=>"Rename would overwrite unowned file $newFilename"];
                    }
                    $read = $this->readBounded($sftp, $oldPath, self::MAX_BASE64_READ_BYTES, 'wallpaper rename');
                    if (empty($read['ok'])) { return ['ok'=>false, 'message'=>(string)$read['message']]; }
                    $writes[$newPath] = ['content'=>(string)$read['content'], 'mode'=>0664];
                    $ciscoContents[$role] = (string)$read['content'];
                }
                foreach (['full'=>$newFull, 'thumbnail'=>$newThumb] as $role=>$newFilename) {
                    $companionRole = $role === 'full' ? 'thumbnail' : 'full';
                    $companionName = $role === 'full' ? $newThumb : $newFull;
                    $registryPayloads[] = [
                        'relative'=>'Desktops/' . $profile['dir'] . '/' . $newFilename,
                        'filename'=>$newFilename,
                        'profile'=>(string)$profile['key'],
                        'role'=>$role,
                        'content'=>$ciscoContents[$role],
                        'width'=>(int)($role === 'full' ? $profile['width'] : $profile['thumbnail_width']),
                        'height'=>(int)($role === 'full' ? $profile['height'] : $profile['thumbnail_height']),
                        'companion_name'=>$companionName,
                        'companion_hash'=>hash('sha256', $ciscoContents[$companionRole]),
                    ];
                }
                $affectedCatalogs[(string)$profile['dir']] = true;
                continue;
            }

            foreach ((array)$variant['models'] as $model) {
                $oldAsset = $this->hostedWallpaperAsset(
                    $siteId, $profile, (string)$model, (string)$variant['full'], true
                );
                if ($oldAsset === null || !isset($oldAsset['content'])) {
                    return ['ok'=>false, 'message'=>"Cannot read {$profile['vendor']} $model {$variant['full']}"];
                }
                if ($this->hostedWallpaperAsset($siteId, $profile, (string)$model, $newFull, false) !== null) {
                    return ['ok'=>false, 'message'=>"Rename would overwrite unowned {$profile['vendor']} $model file $newFull"];
                }
                $hostedUploads[] = [
                    'profile'=>$profile,
                    'model'=>(string)$model,
                    'old_url'=>(string)($oldAsset['url'] ?? ''),
                    'name'=>$newFull,
                    'content'=>(string)$oldAsset['content'],
                ];
            }
        }
        $newManifest['aliases'] = self::uniqueWallpaperAliases($newManifest['aliases']);
        $this->ensureRemoteDirectories($sftp, [
            $root . '/.ucmprovisioner',
            $root . '/' . self::WALLPAPER_SET_ROOT,
            $newSetPath,
        ]);
        foreach ($retainedSources as $sourceName=>$sourceContent) {
            $writes[$newSetPath . '/' . $sourceName] = ['content'=>$sourceContent, 'mode'=>0660];
        }
        $writes[$newSetPath . '/manifest.json'] = [
            'content'=>self::remoteJsonContent($newManifest), 'mode'=>0660,
        ];

        $referencesUpdated = false;
        $filesPublished = false;
        $renameStage = 'hosted_files';
        try {
            foreach ($hostedUploads as $upload) {
                $hostedResults[] = ['family'=>$upload['profile']['family'], 'model'=>$upload['model'], 'name'=>$upload['name'], 'status'=>'unknown'];
                $hostedIndex = array_key_last($hostedResults);
                $published = $this->ucm->mediaAssetOp([
                    'op'=>'upload', 'site_id'=>$siteId,
                    'family'=>$upload['profile']['family'],
                    'model'=>strtolower((string)$upload['model']),
                    'name'=>$upload['name'],
                    'content'=>base64_encode((string)$upload['content']),
                ]);
                $hostedResults[$hostedIndex]['status'] = !empty($published['ok']) ? 'published' : 'failed';
                $hostedResults[$hostedIndex]['result'] = $published;
                if (empty($published['ok'])) {
                    throw new \RuntimeException((string)($published['message'] ?? "Could not publish {$upload['name']}"));
                }
                if (!hash_equals($upload['name'], (string)($published['name'] ?? ''))) {
                    throw new \RuntimeException('The media receiver changed the catalog filename; update the receiver before renaming this catalog');
                }
                if ($upload['old_url'] !== '' && !empty($published['url'])) {
                    $replacements[] = ['old'=>$upload['old_url'], 'new'=>(string)$published['url']];
                }
            }
            $renameStage = 'tftp_files';
            $this->replaceRemoteFiles($sftp, $writes);
            $filesPublished = true;
            $renameStage = 'references';
            if (!method_exists($this->ucm, 'replaceWallpaperReferences')) {
                throw new \RuntimeException('Wallpaper reference propagation is unavailable');
            }
            $referenceResult = $this->ucm->replaceWallpaperReferences($siteId, self::uniqueWallpaperReplacements($replacements));
            if (empty($referenceResult['ok'])) {
                throw new \RuntimeException((string)($referenceResult['message'] ?? 'Could not update wallpaper references'));
            }
            $referencesUpdated = true;
            $renameStage = 'catalog_indexes';
            foreach (array_keys($affectedCatalogs) as $directory) {
                $rebuilt = $this->regenerateImageList(
                    $siteId, $sftp, $root . '/Desktops/' . $directory, 'Desktops/' . $directory
                );
                if (empty($rebuilt['ok'])) {
                    throw new \RuntimeException((string)($rebuilt['message'] ?? "Could not rebuild $directory/List.xml"));
                }
            }
            $renameStage = 'old_catalog_retirement';
            $this->removeWallpaperSetDirectory($sftp, $oldSetPath);
            $renameStage = 'asset_registry';
            foreach ($registryPayloads as $payload) {
                $this->registerManagedPayload(
                    $siteId,
                    (string)$payload['relative'],
                    hash('sha256', (string)$payload['content']),
                    strlen((string)$payload['content']),
                    [
                        'profile'=>(string)$payload['profile'],
                        'bundle_key'=>'wallpaper:' . strtolower($newId),
                        'bundle_role'=>(string)$payload['role'],
                        'validation_status'=>'verified',
                        'provenance'=>'generated',
                        'signature_status'=>'not_applicable',
                        'metadata'=>[
                            'width'=>(int)$payload['width'],
                            'height'=>(int)$payload['height'],
                            'format'=>'png',
                            'magic'=>'PNG',
                        ],
                        'companions'=>[[
                            'kind'=>'background',
                            'content_sha256'=>(string)$payload['companion_hash'],
                            'filename'=>(string)$payload['companion_name'],
                            'relation'=>$payload['role'] === 'full' ? 'thumbnail' : 'full',
                            'required'=>true,
                        ]],
                    ]
                );
            }
        } catch (\Throwable $error) {
            $fileReceipts = $error instanceof RpcOperationException ? ($error->receipt['file_replacements'] ?? []) : [];
            return [
                'ok'=>false, 'status'=>'recovery_required', 'recovery_required'=>true,
                'old_set_id'=>$oldId, 'set_id'=>$newId, 'manifest'=>$newManifest,
                'written'=>$filesPublished ? array_keys($writes) : [],
                'file_replacements'=>$fileReceipts, 'hosted_results'=>$hostedResults, 'failed_stage'=>$renameStage,
                'references_state'=>$referencesUpdated ? 'updated' : (in_array($renameStage, ['hosted_files','tftp_files'], true) ? 'not_attempted' : 'unknown'),
                'message'=>'Wallpaper catalog rename stopped at ' . $renameStage . ': ' . $error->getMessage()
                    . '. Completed and uncertain stages were retained; reconcile the catalog and references before retrying.',
            ];
        }
        return [
            'ok'=>true,
            'old_set_id'=>$oldId,
            'set_id'=>$newId,
            'manifest'=>$newManifest,
            'aliases_retained'=>count($newManifest['aliases']),
            'references'=>$referenceResult,
            'message'=>"Renamed wallpaper catalog to $newName; old filenames remain managed compatibility aliases until its next delete",
        ];
    }

    /** @param object $sftp */
    private function wallpaperSetDelete(int $siteId, $sftp, string $root, string $setId): array
    {
        $setId=self::wallpaperSetId($setId);
        $manifest=$this->readWallpaperSetManifest($sftp,$root,$setId);
        $setPath=self::wallpaperSetPath($root,$setId);
        $assets=[];$files=[];$hosted=[];$catalogs=[];
        foreach (array_merge((array)$manifest['variants'],(array)($manifest['aliases']??[])) as $variant) {
            $profile=ModelCatalog::wallpaperProfileByKey((string)$variant['profile']);
            $owner=(string)($variant['set_id']??$setId);
            if (!$profile||$profile['dir']!==$variant['dir']) { throw new \RuntimeException('Wallpaper retirement profile is invalid'); }
            foreach (['full','thumbnail'] as $role) {
                $name=(string)($variant[$role]??'');if ($name==='') { continue; }
                if (!self::wallpaperOutputNameIsSafe($name,$owner,$profile,$role,(string)($variant['full_sha256']??''),(string)($variant['thumbnail_sha256']??''))) { throw new \RuntimeException('Wallpaper retirement path is outside its owner'); }
                if ($profile['delivery']==='hosted_http') {
                    foreach ((array)$variant['models'] as $model) {
                        if ($this->hostedWallpaperAsset($siteId,$profile,$model,$name,false)===null) { continue; }
                        $service=$this->ucm->wallpaperHostedRetirementLocal($siteId,$profile['family'],strtolower($model));
                        $asset=$service->asset($name);$key=$asset['asset_key'];
                        $expected=(string)($variant[$role.'_sha256']??'');
                        if ($expected!==''&&!hash_equals($expected,$asset['content_sha256'])) { throw new \RuntimeException('Hosted wallpaper bytes changed since publication'); }
                        $hosted[$key]=['service'=>$service,'name'=>$name,'asset'=>$asset];
                        $assets[$key]=$asset;
                    }
                } else {
                    $relative='Desktops/'.$profile['dir'].'/'.$name;$path=$root.'/'.$relative;
                    $catalogs['Desktops/'.$profile['dir'].'/List.xml']=true;
                    if (!$this->remoteExists($sftp,$path)) { continue; }
                    $stat=$sftp->lstat($path);
                    if (!is_array($stat)||(int)($stat['type']??0)!==1) { throw new \RuntimeException('Wallpaper retirement requires a regular file'); }
                    $hash=$this->retirementPayloadHash($sftp,$path,(int)$stat['size']);
                    $expected=(string)($variant[$role.'_sha256']??'');
                    if ($expected!==''&&!hash_equals($expected,$hash)) { throw new \RuntimeException('Wallpaper bytes changed since publication'); }
                    $key=hash('sha256',$relative."\0".$hash);
                    $asset=['asset_key'=>$key,'filename'=>$name,'kind'=>'background','content_sha256'=>$hash,'size_bytes'=>(int)$stat['size'],'metadata'=>[]];
                    $assets[$key]=$asset;$files[$relative]=$asset;
                }
            }
        }
        $graph=(new AssetReferenceIndex($this->ucm->getDb()))->snapshot($siteId);
        $external=$this->assetReferenceSnapshot($siteId,null,array_values($assets));
        if (!$graph['complete']||empty($external['complete'])||!empty($external['active_scan'])) {
            return ['ok'=>false,'message'=>'Catalog retirement requires a complete reference inventory and no active scan'];
        }
        // These exact files are this catalog's own bookkeeping. Other catalogs,
        // phone configurations and retained generations remain consumers.
        $ownManifest=self::WALLPAPER_SET_ROOT.'/'.$setId.'/manifest.json';
        $references=[];
        foreach ($assets as $key=>$asset) {
            $external['references'][$key]=array_values(array_filter((array)($external['references'][$key]??[]),
                static fn(array $ref):bool=>($ref['label']??'')!==$ownManifest&&!isset($catalogs[$ref['label']??''])));
            foreach ($graph['sources'] as $source) {
                if (AssetReferenceIndex::matches($source,$asset)) { $references[]=AssetReferenceIndex::summary($source); }
            }
            array_push($references,...$external['references'][$key]);
        }
        foreach ($hosted as $key=>&$item) {
            $item['external']=$external+['subject_asset_key'=>$key];
            $item['preview']=$item['service']->preview($siteId,$item['name'],$item['external']);
            array_push($references,...$item['preview']['references']);
            if (!$item['preview']['can_retire'] && !$item['preview']['references']) {
                return ['ok'=>false,'message'=>'Hosted catalog retirement inventory is incomplete','blockers'=>$item['preview']['blockers']];
            }
        }
        unset($item);
        if ($references) { return ['ok'=>false,'protected'=>true,'references'=>$references,'message'=>'This catalog is still used by saved settings, phones, jobs or retained files; reassign those references before deleting it']; }

        // Preserve the complete source tree, including custom crops and aliases.
        $sourceFiles=$sftp->rawlist($setPath);
        if (!is_array($sourceFiles)) { throw new \RuntimeException('Wallpaper source inventory is unavailable'); }
        $tree=[];$bytes=0;
        foreach ($sourceFiles as $name=>$stat) {
            if (in_array($name,['.','..'],true)) { continue; }
            if (basename($name)!==$name||(int)($stat['type']??0)!==1||count($tree)>=500
                ||($bytes+=(int)($stat['size']??0))>33554432) { throw new \RuntimeException('Wallpaper source tree cannot be safely retained'); }
            $tree[$name]=['size'=>(int)$stat['size'],'sha256'=>$this->retirementPayloadHash($sftp,$setPath.'/'.$name,(int)$stat['size'])];
        }
        $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(8));
        $quarantine=$root.'/.ucmprovisioner/retired-assets/'.$id;
        $this->ensureRemoteDirectories($sftp,[$root.'/.ucmprovisioner/retired-assets',$quarantine]);
        foreach ([$root.'/.ucmprovisioner/retired-assets',$quarantine] as $directory) {
            if (!$sftp->chmod(self::PRIVATE_DIRECTORY_MODE,$directory)) { throw new \RuntimeException('Catalog recovery permissions could not be applied'); }
        }
        $receipt=['schema'=>1,'site_id'=>$siteId,'set_id'=>$setId,'state'=>'prepared','files'=>$files,'source_tree'=>$tree,'completed'=>[],'pending'=>null];
        $write=function() use($sftp,$quarantine,&$receipt):void { $this->writeRemoteJson($sftp,$quarantine.'/receipt.json',$receipt); };
        $write();
        try {
            foreach ($hosted as $key=>$item) {
                $receipt['pending']=['store'=>'http','asset_key'=>$key,'name'=>$item['name']];$write();
                $result=$item['service']->retire($siteId,$item['name'],$item['external'],$item['preview']['revision']);
                $receipt['completed'][]=$receipt['pending']+['result'=>$result];$write();
                if (empty($result['ok'])) { throw new \RuntimeException((string)($result['message']??'Hosted retirement failed')); }
            }
            foreach ($files as $relative=>$asset) {
                $target=$quarantine.'/'.hash('sha256',$relative).'.payload';
                $receipt['pending']=['store'=>'tftp','source'=>$root.'/'.$relative,'destination'=>$target];$write();
                if (!hash_equals($asset['content_sha256'],$this->retirementPayloadHash($sftp,$root.'/'.$relative,$asset['size_bytes']))
                    ||!$sftp->rename($root.'/'.$relative,$target)
                    ||!hash_equals($asset['content_sha256'],$this->retirementPayloadHash($sftp,$target,$asset['size_bytes']))) {
                    throw new \RuntimeException('Catalog payload quarantine was not confirmed');
                }
                $receipt['completed'][]=$receipt['pending'];$receipt['pending']=null;$write();
            }
            $receipt['pending']=['store'=>'tftp','source'=>$setPath,'destination'=>$quarantine.'/catalog'];$write();
            if (!$sftp->rename($setPath,$quarantine.'/catalog')) { throw new \RuntimeException('Catalog source quarantine was not confirmed'); }
            foreach ($tree as $name=>$file) {
                if (!hash_equals($file['sha256'],$this->retirementPayloadHash($sftp,$quarantine.'/catalog/'.$name,$file['size']))) {
                    throw new \RuntimeException('Retained catalog source readback differs');
                }
            }
            $receipt['completed'][]=$receipt['pending'];$receipt['pending']=null;$write();
            foreach (array_keys($catalogs) as $path) {
                $result=$this->regenerateImageList($siteId,$sftp,$root.'/'.dirname($path),dirname($path));
                if (empty($result['ok'])) { throw new \RuntimeException((string)$result['message']); }
            }
            $warnings=[];
            foreach ($files as $relative=>$asset) { $warning=$this->retireManagedPath($siteId,$relative);if ($warning) { $warnings[]=$warning; } }
            $receipt['state']='complete';$receipt['warnings']=$warnings;$write();
            return ['ok'=>true,'deleted'=>count($files)+count($hosted),'recovery_id'=>$id,'warnings'=>$warnings,
                'message'=>'Catalog retired after reference checks; published files and editable sources are retained in private recovery storage'];
        } catch (\Throwable $error) {
            return ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'recovery_id'=>$id,'receipt'=>$quarantine.'/receipt.json',
                'completed'=>$receipt['completed'],'pending'=>$receipt['pending'],'message'=>'Catalog retirement needs reconciliation: '.$error->getMessage()];
        }
    }

    /** @param object $sftp */
    private function wallpaperChoices(int $siteId, $sftp, string $root, string $profileKey): array
    {
        $profile = ModelCatalog::backgroundProfileByKey($profileKey);
        if ($profile === null) { return ['ok'=>false, 'message'=>'Choose an exact supported Cisco wallpaper profile']; }
        $path = $root . '/Desktops/' . $profile['dir'];
        $entries = $sftp->nlist($path);
        if ($entries === false) { return ['ok'=>false, 'message'=>"Cannot list $path"]; }
        $pngs = array_fill_keys(array_values(array_filter(
            (array)$entries,
            static fn($name): bool => preg_match('/^[A-Za-z0-9_.-]+\.png$/iD', (string)$name) === 1
        )), true);
        $linked = [];
        $hiddenAliases = [];
        $sets = $this->wallpaperSetList($siteId, $sftp, $root);
        foreach ((array)($sets['sets'] ?? []) as $set) {
            foreach ((array)$set['variants'] as $variant) {
                if (!hash_equals($profileKey, (string)$variant['profile'])) { continue; }
                if (empty($variant['enabled'])) {
                    $hiddenAliases[(string)$variant['full']] = true;
                    continue;
                }
                $linked[(string)$variant['full']] = [
                    'set_id'=>(string)$set['id'], 'set_name'=>(string)$set['name'],
                    'current'=>!empty($variant['current']),
                ];
            }
            try {
                $manifest = $this->readWallpaperSetManifest($sftp, $root, (string)$set['id']);
                foreach ((array)($manifest['aliases'] ?? []) as $alias) {
                    if (hash_equals($profileKey, (string)$alias['profile'])) {
                        $hiddenAliases[(string)$alias['full']] = true;
                    }
                }
            } catch (\Throwable $ignored) {
                // A damaged set manifest must not hide otherwise usable image pairs.
            }
        }
        $choices = [];
        foreach (array_keys($pngs) as $full) {
            if (preg_match('/-tn\.png$/i', $full)) { continue; }
            if (isset($hiddenAliases[$full]) && !isset($linked[$full])) { continue; }
            $base = preg_replace('/\.png$/i', '', $full);
            $thumbnail = "$base-tn.png";
            if (!isset($pngs[$thumbnail])) { continue; }
            $fullHeader = $sftp->get($path . '/' . $full, false, 0, 24);
            $thumbHeader = $sftp->get($path . '/' . $thumbnail, false, 0, 24);
            $resolved = is_string($fullHeader) && is_string($thumbHeader)
                ? ModelCatalog::backgroundProfileForDimensions(
                    (string)$profile['dir'],
                    self::pngDimensions($fullHeader) ?? [],
                    self::pngDimensions($thumbHeader) ?? []
                )
                : null;
            if ($resolved === null || !hash_equals($profileKey, (string)$resolved['key'])) { continue; }
            $choices[] = [
                'filename'=>$full,
                'thumbnail'=>$thumbnail,
                'linked'=>isset($linked[$full]),
                'set_id'=>$linked[$full]['set_id'] ?? null,
                'set_name'=>$linked[$full]['set_name'] ?? null,
                'current'=>$linked[$full]['current'] ?? false,
                'selectable'=>!empty($linked[$full]['current']),
            ];
        }
        usort($choices, static fn(array $left, array $right): int =>
            strnatcasecmp((string)($left['set_name'] ?? $left['filename']), (string)($right['set_name'] ?? $right['filename']))
        );
        return ['ok'=>true, 'profile'=>$profileKey, 'choices'=>$choices];
    }

    private static function wallpaperSetId(string $setId): string
    {
        $setId = trim($setId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $setId) !== 1) {
            throw new \InvalidArgumentException('Wallpaper set ID must use 1-40 letters, numbers, underscores or hyphens');
        }
        return $setId;
    }

    private static function wallpaperSetName(string $name): string
    {
        $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name));
        if ($name === '') { throw new \InvalidArgumentException('Wallpaper set name is required'); }
        return function_exists('mb_substr') ? mb_substr($name, 0, 64) : substr($name, 0, 64);
    }

    /** @return array{brand:string,series:string,model:string,label:string} */
    private static function wallpaperScope(string $encoded): array
    {
        $value = json_decode($encoded, true);
        if (!is_array($value)) { throw new \InvalidArgumentException('Wallpaper scope is invalid'); }
        $clean = static fn($item): string => substr(
            trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)$item)),
            0,
            96
        );
        return [
            'brand'=>$clean($value['brand'] ?? ''),
            'series'=>$clean($value['series'] ?? ''),
            'model'=>$clean($value['model'] ?? ''),
            'label'=>$clean($value['label'] ?? 'All supported models'),
        ];
    }

    /** @param array<int,array<string,mixed>> $aliases */
    private static function uniqueWallpaperAliases(array $aliases): array
    {
        $unique = [];
        foreach ($aliases as $alias) {
            if (!is_array($alias)) { continue; }
            $key = implode('|', [
                (string)($alias['set_id'] ?? ''),
                (string)($alias['profile'] ?? ''),
                (string)($alias['full'] ?? ''),
                implode(',', (array)($alias['models'] ?? [])),
            ]);
            $unique[$key] = $alias;
        }
        return array_values($unique);
    }

    /** @param array<int,array{old:string,new:string}> $replacements */
    private static function uniqueWallpaperReplacements(array $replacements): array
    {
        $unique = [];
        foreach ($replacements as $replacement) {
            $old = (string)($replacement['old'] ?? '');
            $new = (string)($replacement['new'] ?? '');
            if ($old !== '' && $new !== '' && !hash_equals($old, $new)) {
                $unique[$old] = ['old'=>$old, 'new'=>$new];
            }
        }
        return array_values($unique);
    }

    /** @param object $sftp */
    private function removeWallpaperSetDirectory($sftp, string $path): void
    {
        if (!$sftp->is_dir($path)) { return; }
        $entries = $sftp->nlist($path);
        if ($entries === false) { throw new \RuntimeException("Cannot inspect $path"); }
        foreach ((array)$entries as $entry) {
            if (in_array($entry, ['.','..'], true)) { continue; }
            if (!$sftp->delete($path . '/' . $entry)) {
                throw new \RuntimeException("Could not remove retained wallpaper file $entry");
            }
        }
        if (!$sftp->rmdir($path)) { throw new \RuntimeException("Could not remove retained wallpaper directory $path"); }
    }

    private static function wallpaperSetPath(string $root, string $setId): string
    {
        return rtrim($root, '/') . '/' . self::WALLPAPER_SET_ROOT . '/' . self::wallpaperSetId($setId);
    }

    /** @param array<string,mixed> $profile */
    private static function wallpaperOutputBase(string $setId, array $profile): string
    {
        $setId = self::wallpaperSetId($setId);
        $width = (int)($profile['width'] ?? $profile['dimensions'][0] ?? 0);
        $height = (int)($profile['height'] ?? $profile['dimensions'][1] ?? 0);
        return $setId . '-' . $width . 'x' . $height;
    }

    /**
     * Device-facing wallpaper names are content-addressed so Cisco firmware
     * cannot reuse a cached image after an operator changes the rendered bytes.
     * A pair shares one token, keeping List.xml full/thumbnail discovery simple.
     * Catalog identity and profile are included so separate owners stay separate.
     *
     * @return array{full:string,thumbnail:string}
     */
    private static function wallpaperOutputNames(
        string $setId,
        array $profile,
        string $fullHash = '',
        string $thumbnailHash = ''
    ): array {
        $base = self::wallpaperOutputBase($setId, $profile);
        $extension = (string)($profile['format'] ?? '') === 'jpg' ? 'jpg' : 'png';
        foreach ([$fullHash, $thumbnailHash] as $hash) {
            if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Wallpaper output hash is invalid');
            }
        }
        $identity = hash('sha256', $base . '|' . (string)($profile['key'] ?? '') . '|' . $fullHash . '|' . $thumbnailHash);
        return [
            'full'=>AssetFilename::fromHash('w', $identity, $extension),
            'thumbnail'=>(int)($profile['thumbnail_width'] ?? 0) > 0
                ? AssetFilename::fromHash('w', $identity, 'png', '-tn')
                : '',
        ];
    }

    private static function wallpaperOutputNameIsSafe(
        string $filename,
        string $setId,
        array $profile,
        string $role,
        string $fullHash = '',
        string $thumbnailHash = ''
    ): bool {
        if ($filename === '' && $role === 'thumbnail' && (int)($profile['thumbnail_width'] ?? 0) < 1) {
            return true;
        }
        if (AssetFilename::isCompact($filename)) {
            return hash_equals(self::wallpaperOutputNames($setId, $profile, $fullHash, $thumbnailHash)[$role] ?? '', $filename);
        }
        $base = preg_quote(self::wallpaperOutputBase($setId, $profile), '/');
        $token = '(?:-[a-f0-9]{12})?';
        if ($role === 'full') {
            $extension = (string)($profile['format'] ?? '') === 'jpg' ? 'jpg' : 'png';
            return preg_match('/^' . $base . $token . '\\.' . $extension . '$/D', $filename) === 1;
        }
        return (int)($profile['thumbnail_width'] ?? 0) > 0
            && preg_match('/^' . $base . $token . '-tn\\.png$/D', $filename) === 1;
    }

    private static function wallpaperAliasOwns(
        array $manifest,
        string $setId,
        string $profileKey,
        string $filename,
        string $role
    ): bool {
        foreach ((array)($manifest['aliases'] ?? []) as $alias) {
            if (hash_equals($setId, (string)($alias['set_id'] ?? ''))
                && hash_equals($profileKey, (string)($alias['profile'] ?? ''))
                && hash_equals($filename, (string)($alias[$role] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private static function wallpaperSourceName(string $name): string
    {
        $name = basename(trim($name));
        if (preg_match('/^(?:o[a-f0-9]{12}|source(?:-[a-f0-9]{12})?)\.(?:png|jpg)$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Wallpaper source reference is invalid');
        }
        return $name;
    }

    /** @return array{content:string,sha256:string,name:string,mime:string} */
    private static function wallpaperSourcePayload(string $encoded, string $mime): array
    {
        $content = base64_decode($encoded, true);
        if ($content === false || $content === '' || strlen($content) > self::MAX_BASE64_READ_BYTES) {
            throw new \InvalidArgumentException('Normalized wallpaper source must be a non-empty image no larger than 8 MiB');
        }
        $png = str_starts_with($content, "\x89PNG\r\n\x1a\n");
        $jpeg = str_starts_with($content, "\xff\xd8\xff");
        if (($mime === 'image/png' && !$png) || ($mime === 'image/jpeg' && !$jpeg) || (!$png && !$jpeg)) {
            throw new \InvalidArgumentException('Wallpaper source is not valid PNG or JPEG data');
        }
        $sha256 = hash('sha256', $content);
        return [
            'content'=>$content,
            'sha256'=>$sha256,
            'name'=>AssetFilename::fromHash('o', $sha256, $png ? 'png' : 'jpg'),
            'mime'=>$png ? 'image/png' : 'image/jpeg',
        ];
    }

    /** @return array<string,mixed> */
    private static function wallpaperVariantSkeleton(string $setId, array $profile, array $manifest): array
    {
        $names = self::wallpaperOutputNames($setId, $profile);
        return [
            'profile'=>(string)$profile['key'],
            'vendor'=>(string)$profile['vendor'],
            'family'=>(string)$profile['family'],
            'series_key'=>(string)$profile['series_key'],
            'delivery'=>(string)$profile['delivery'],
            'models'=>array_values((array)$profile['models']),
            'dir'=>(string)$profile['dir'],
            'enabled'=>true,
            'invert'=>false,
            'source'=>(string)$manifest['source'],
            'source_sha256'=>(string)$manifest['source_sha256'],
            'rendered_source_sha256'=>'',
            'full'=>$names['full'],
            'thumbnail'=>$names['thumbnail'],
            'full_sha256'=>'',
            'thumbnail_sha256'=>'',
            'full_transform'=>self::wallpaperTransform([], 2)['full'],
            'thumbnail_transform'=>self::wallpaperTransform([], 2)['thumbnail'],
        ];
    }

    private static function wallpaperSourceReferenced(array $manifest, string $sourceName): bool
    {
        if ($sourceName === '') { return false; }
        if (hash_equals((string)($manifest['source'] ?? ''), $sourceName)) { return true; }
        foreach ((array)($manifest['variants'] ?? []) as $variant) {
            if (hash_equals((string)($variant['source'] ?? ''), $sourceName)) { return true; }
        }
        return false;
    }

    /** @param object $sftp */
    private function deleteUnreferencedWallpaperSource($sftp, string $setPath, array $manifest, string $sourceName): void
    {
        if ($sourceName === '' || self::wallpaperSourceReferenced($manifest, $sourceName)) { return; }
        try { $sourceName = self::wallpaperSourceName($sourceName); }
        catch (\Throwable $ignored) { return; }
        $path = rtrim($setPath, '/') . '/' . $sourceName;
        if ($this->remoteExists($sftp, $path)) { $sftp->delete($path); }
    }

    /** @param mixed $value @return array{full:array{zoom:int,x:int,y:int},thumbnail:array{zoom:int,x:int,y:int}} */
    private static function wallpaperTransform($value, int $manifestVersion = 2): array
    {
        $value = is_array($value) ? $value : [];
        $minimumZoom = $manifestVersion >= 2 ? 60 : 100;
        $normalize = static function ($input) use ($minimumZoom): array {
            $input = is_array($input) ? $input : [];
            return [
                'zoom'=>max($minimumZoom, min(220, (int)($input['zoom'] ?? 100))),
                'x'=>max(-100, min(100, (int)($input['x'] ?? 0))),
                'y'=>max(-100, min(100, (int)($input['y'] ?? 0))),
            ];
        };
        return [
            'full'=>$normalize($value['full'] ?? []),
            'thumbnail'=>$normalize($value['thumbnail'] ?? $value['thumb'] ?? []),
        ];
    }

    /** @return array{0:int,1:int}|null */
    private static function imageDimensions(string $content, string $format): ?array
    {
        $format = strtolower($format);
        if ($format === 'png') { return self::pngDimensions($content); }
        if ($format !== 'jpg' || !str_starts_with($content, "\xff\xd8")) { return null; }
        $length = strlen($content);
        $offset = 2;
        while ($offset + 8 < $length) {
            if (ord($content[$offset]) !== 0xff) { $offset++; continue; }
            while ($offset < $length && ord($content[$offset]) === 0xff) { $offset++; }
            if ($offset >= $length) { break; }
            $marker = ord($content[$offset++]);
            if ($marker === 0xd8 || $marker === 0xd9 || ($marker >= 0xd0 && $marker <= 0xd7)) { continue; }
            if ($offset + 2 > $length) { break; }
            $segmentLength = (int)(unpack('nlength', substr($content, $offset, 2))['length'] ?? 0);
            if ($segmentLength < 2 || $offset + $segmentLength > $length) { return null; }
            if (in_array($marker, [0xc0,0xc1,0xc2,0xc3,0xc5,0xc6,0xc7,0xc9,0xca,0xcb,0xcd,0xce,0xcf], true)
                && $segmentLength >= 7) {
                $height = (int)(unpack('nheight', substr($content, $offset + 3, 2))['height'] ?? 0);
                $width = (int)(unpack('nwidth', substr($content, $offset + 5, 2))['width'] ?? 0);
                return $width > 0 && $height > 0 ? [$width, $height] : null;
            }
            $offset += $segmentLength;
        }
        return null;
    }

    /**
     * Final server-owned wallpaper encoding. Browser canvas output is RGBA,
     * while Cisco's List.xml pipeline expects device-facing opaque PNGs. The
     * grayscale branches additionally require a bounded 2- or 16-tone palette.
     *
     * @param array<string,mixed> $profile
     */
    private static function normalizeWallpaperPayload(string $content, array $profile, bool $thumbnail): string
    {
        $format = $thumbnail ? 'png' : strtolower((string)($profile['format'] ?? 'png'));
        $width = (int)($thumbnail ? ($profile['thumbnail_width'] ?? 0) : ($profile['width'] ?? 0));
        $height = (int)($thumbnail ? ($profile['thumbnail_height'] ?? 0) : ($profile['height'] ?? 0));
        if ($width < 1 || $height < 1 || self::imageDimensions($content, $format) !== [$width, $height]) {
            throw new \InvalidArgumentException("expected exactly {$width}x{$height} " . strtoupper($format));
        }

        $tones = (int)($profile['grayscale_tones'] ?? 0);
        $isCiscoListPng = $format === 'png'
            && (string)($profile['delivery'] ?? '') === 'cisco_list';
        $details = $format === 'png' ? self::pngPaletteDetails($content) : null;
        if ($isCiscoListPng && $details === null) {
            throw new \InvalidArgumentException('PNG encoding metadata is unreadable');
        }
        if ($tones <= 0 && (!$isCiscoListPng || empty($details['alpha']))) {
            return $content;
        }
        if ($tones <= 0) {
            return self::opaqueTrueColorPng($content, $width, $height);
        }
        if (!in_array($tones, [2,16], true)) {
            throw new \InvalidArgumentException('unsupported grayscale palette size');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreate')) {
            throw new \RuntimeException('PHP GD is required to publish Cisco grayscale wallpaper profiles');
        }
        $source = @imagecreatefromstring($content);
        $target = @imagecreate($width, $height);
        if ($source === false || $target === false) {
            if ($source !== false) { imagedestroy($source); }
            if ($target !== false) { imagedestroy($target); }
            throw new \InvalidArgumentException('image decoder could not create the grayscale canvas');
        }
        try {
            $palette = [];
            for ($index = 0; $index < $tones; $index++) {
                $level = (int)round(($index * 255) / ($tones - 1));
                $palette[$index] = imagecolorallocate($target, $level, $level, $level);
            }
            $trueColor = imageistruecolor($source);
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixel = imagecolorat($source, $x, $y);
                    if ($trueColor) {
                        $rgba = [
                            'red'=>($pixel >> 16) & 0xff,
                            'green'=>($pixel >> 8) & 0xff,
                            'blue'=>$pixel & 0xff,
                            'alpha'=>($pixel >> 24) & 0x7f,
                        ];
                    } else {
                        $rgba = imagecolorsforindex($source, $pixel);
                    }
                    // Flatten transparency onto black. Device payloads are
                    // opaque; transparent pixels must never leak into PLTE/tRNS.
                    $opacity = (127 - (int)($rgba['alpha'] ?? 0)) / 127;
                    $luma = (0.2126 * (int)$rgba['red'])
                        + (0.7152 * (int)$rgba['green'])
                        + (0.0722 * (int)$rgba['blue']);
                    $slot = (int)round((($luma * $opacity) / 255) * ($tones - 1));
                    imagesetpixel($target, $x, $y, $palette[max(0, min($tones - 1, $slot))]);
                }
            }
            ob_start();
            $written = imagepng($target, null, 9);
            $normalized = (string)ob_get_clean();
            if (!$written || $normalized === '') {
                throw new \RuntimeException('PNG encoder failed');
            }
        } finally {
            imagedestroy($source);
            imagedestroy($target);
        }
        $details = self::pngPaletteDetails($normalized);
        if ($details === null
            || !empty($details['alpha'])
            || (int)$details['colors'] < 1
            || (int)$details['colors'] > $tones) {
            throw new \RuntimeException("server encoder did not produce an opaque {$tones}-tone PNG");
        }
        return $normalized;
    }

    private static function opaqueTrueColorPng(string $content, int $width, int $height): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('PHP GD is required to publish opaque Cisco wallpapers');
        }
        $source = @imagecreatefromstring($content);
        $target = @imagecreatetruecolor($width, $height);
        if ($source === false || $target === false) {
            if ($source !== false) { imagedestroy($source); }
            if ($target !== false) { imagedestroy($target); }
            throw new \InvalidArgumentException('image decoder could not create an opaque canvas');
        }
        try {
            imagealphablending($target, true);
            imagesavealpha($target, false);
            $black = imagecolorallocate($target, 0, 0, 0);
            imagefilledrectangle($target, 0, 0, $width - 1, $height - 1, $black);
            if (!imagecopy($target, $source, 0, 0, 0, 0, $width, $height)) {
                throw new \RuntimeException('image compositor failed');
            }
            ob_start();
            $written = imagepng($target, null, 9);
            $normalized = (string)ob_get_clean();
            if (!$written || $normalized === '') {
                throw new \RuntimeException('PNG encoder failed');
            }
        } finally {
            imagedestroy($source);
            imagedestroy($target);
        }
        $details = self::pngPaletteDetails($normalized);
        if ($details === null || !empty($details['alpha'])) {
            throw new \RuntimeException('server encoder did not produce an opaque PNG');
        }
        return $normalized;
    }

    /** @param array<string,mixed> $profile */
    private static function wallpaperEncodingIssue(string $content, array $profile, bool $thumbnail): ?string
    {
        $format = $thumbnail ? 'png' : strtolower((string)($profile['format'] ?? 'png'));
        if ($format !== 'png' || (string)($profile['delivery'] ?? '') !== 'cisco_list') {
            return null;
        }
        $details = self::pngPaletteDetails($content);
        if ($details === null) {
            return 'PNG encoding metadata is unreadable';
        }
        if (!empty($details['alpha'])) {
            return 'PNG contains an alpha channel; repair this catalog for Cisco delivery';
        }
        $tones = (int)($profile['grayscale_tones'] ?? 0);
        if ($tones > 0 && ((int)$details['colors'] < 1 || (int)$details['colors'] > $tones)) {
            return "PNG is not encoded as an opaque {$tones}-tone palette";
        }
        return null;
    }

    /** @return array{colors:int,alpha:bool,color_type:int,bit_depth:int}|null */
    private static function pngPaletteDetails(string $content): ?array
    {
        if (!str_starts_with($content, "\x89PNG\r\n\x1a\n") || strlen($content) < 33) {
            return null;
        }
        $bitDepth = ord($content[24]);
        $colorType = ord($content[25]);
        $colors = 0;
        $alpha = in_array($colorType, [4,6], true);
        $offset = 8;
        $length = strlen($content);
        while ($offset + 12 <= $length) {
            $chunkLength = (int)(unpack('Nlength', substr($content, $offset, 4))['length'] ?? -1);
            $type = substr($content, $offset + 4, 4);
            // All colour/alpha metadata must precede the first IDAT chunk, so
            // bounded inventory reads need not download each complete image.
            if ($type === 'IDAT') { break; }
            if ($chunkLength < 0 || $offset + 12 + $chunkLength > $length) { return null; }
            if ($type === 'PLTE') { $colors = intdiv($chunkLength, 3); }
            if ($type === 'tRNS') { $alpha = true; }
            $offset += 12 + $chunkLength;
            if ($type === 'IEND') { break; }
        }
        if ($colorType === 0 && $colors === 0) { $colors = 1 << min(8, $bitDepth); }
        return ['colors'=>$colors, 'alpha'=>$alpha, 'color_type'=>$colorType, 'bit_depth'=>$bitDepth];
    }

    /** @return array<string,mixed>|null */
    private function hostedWallpaperAsset(
        int $siteId,
        array $profile,
        string $model,
        string $filename,
        bool $withContent
    ): ?array {
        $request = [
            'op'=>'list', 'site_id'=>$siteId,
            'family'=>(string)$profile['family'], 'model'=>strtolower($model),
        ];
        $listed = $this->ucm->mediaAssetOp($request);
        if (empty($listed['ok'])) {
            throw new \RuntimeException((string)($listed['message'] ?? "Could not inspect {$profile['vendor']} $model media"));
        }
        $item = null;
        foreach ((array)($listed['items'] ?? []) as $candidate) {
            if (hash_equals($filename, (string)($candidate['name'] ?? ''))) { $item = $candidate; break; }
        }
        if ($item === null || !$withContent) { return $item; }
        $download = $this->ucm->mediaAssetOp(array_merge($request, ['op'=>'download', 'name'=>$filename]));
        $content = !empty($download['ok'])
            ? base64_decode((string)($download['content'] ?? ''), true)
            : false;
        if ($content === false) { return null; }
        $item['content'] = $content;
        return $item;
    }

    /** @param object $sftp @return array<string,mixed> */
    private function readWallpaperSetManifest($sftp, string $root, string $setId, bool $required = true): array
    {
        $setId = self::wallpaperSetId($setId);
        $manifest = $this->readRemoteJson(
            $sftp,
            self::wallpaperSetPath($root, $setId) . '/manifest.json',
            $required
        );
        if (!$manifest) { return []; }
        $manifestVersion = (int)($manifest['version'] ?? 0);
        if (!in_array($manifestVersion, [1,2,3], true)
            || !hash_equals($setId, self::wallpaperSetId((string)($manifest['id'] ?? '')))
            || !preg_match('/^(?:o[a-f0-9]{12}|source(?:-[a-f0-9]{12})?)\.(?:png|jpg)$/D', (string)($manifest['source'] ?? ''))
            || preg_match('/^[a-f0-9]{64}$/D', (string)($manifest['source_sha256'] ?? '')) !== 1
            || !is_array($manifest['variants'] ?? null)
            || count($manifest['variants']) > 20) {
            throw new \RuntimeException("Invalid managed wallpaper manifest for $setId");
        }
        $manifest['version'] = $manifestVersion === 1 ? 2 : $manifestVersion;
        $manifest['name'] = self::wallpaperSetName((string)($manifest['name'] ?? $setId));
        $scope = is_array($manifest['original_scope'] ?? null) ? $manifest['original_scope'] : [];
        $manifest['original_scope'] = [
            'brand'=>substr(trim((string)($scope['brand'] ?? '')), 0, 96),
            'series'=>substr(trim((string)($scope['series'] ?? '')), 0, 96),
            'model'=>substr(trim((string)($scope['model'] ?? '')), 0, 96),
            'label'=>substr(trim((string)($scope['label'] ?? '')), 0, 96),
        ];
        $variants = [];
        foreach ($manifest['variants'] as $key=>$variant) {
            if (!is_array($variant)) { throw new \RuntimeException("Invalid wallpaper variant in $setId"); }
            $profileKey = (string)($variant['profile'] ?? $key);
            $profile = ModelCatalog::wallpaperProfileByKey($profileKey);
            if ($profile === null || !hash_equals($profile['dir'], (string)($variant['dir'] ?? ''))) {
                throw new \RuntimeException("Unsupported wallpaper variant in $setId");
            }
            $fullName = (string)($variant['full'] ?? '');
            $thumbnailName = (string)($variant['thumbnail'] ?? '');
            $sourceName = self::wallpaperSourceName((string)($variant['source'] ?? $manifest['source']));
            $sourceHash = (string)($variant['source_sha256'] ?? $manifest['source_sha256']);
            $renderedSourceHash = (string)($variant['rendered_source_sha256'] ?? $sourceHash);
            if (!self::wallpaperOutputNameIsSafe($fullName, $setId, $profile, 'full', (string)($variant['full_sha256'] ?? ''), (string)($variant['thumbnail_sha256'] ?? ''))
                || !self::wallpaperOutputNameIsSafe($thumbnailName, $setId, $profile, 'thumbnail', (string)($variant['full_sha256'] ?? ''), (string)($variant['thumbnail_sha256'] ?? ''))
                || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
                || ($renderedSourceHash !== '' && preg_match('/^[a-f0-9]{64}$/D', $renderedSourceHash) !== 1)) {
                throw new \RuntimeException("Unsafe wallpaper variant path in $setId");
            }
            foreach (['full_sha256','thumbnail_sha256'] as $hashField) {
                $hash = (string)($variant[$hashField] ?? '');
                if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new \RuntimeException("Invalid wallpaper variant hash in $setId");
                }
            }
            $models = array_values(array_unique(array_map(
                static fn($model): string => strtoupper(trim((string)$model)),
                (array)($variant['models'] ?? $profile['models'])
            )));
            if (!$models || array_diff($models, (array)$profile['models'])) {
                throw new \RuntimeException("Invalid wallpaper target models in $setId");
            }
            $transform = self::wallpaperTransform([
                'full'=>$variant['full_transform'] ?? [],
                'thumbnail'=>$variant['thumbnail_transform'] ?? [],
            ], $manifestVersion);
            $variants[$profileKey] = [
                'profile'=>$profileKey,
                'vendor'=>$profile['vendor'],
                'family'=>$profile['family'],
                'series_key'=>$profile['series_key'],
                'delivery'=>$profile['delivery'],
                'models'=>$models,
                'dir'=>$profile['dir'],
                'enabled'=>array_key_exists('enabled', $variant) ? (bool)$variant['enabled'] : true,
                'invert'=>(int)($profile['grayscale_tones'] ?? 0) >= 2 && !empty($variant['invert']),
                'source'=>$sourceName,
                'source_sha256'=>$sourceHash,
                'rendered_source_sha256'=>$renderedSourceHash,
                'full'=>$fullName,
                'thumbnail'=>$thumbnailName,
                'full_sha256'=>(string)($variant['full_sha256'] ?? ''),
                'thumbnail_sha256'=>(string)($variant['thumbnail_sha256'] ?? ''),
                'full_transform'=>$transform['full'],
                'thumbnail_transform'=>$transform['thumbnail'],
            ];
        }
        $manifest['variants'] = $variants;
        $aliases = [];
        if (!is_array($manifest['aliases'] ?? [] ) || count((array)($manifest['aliases'] ?? [])) > 400) {
            throw new \RuntimeException("Invalid wallpaper compatibility aliases in $setId");
        }
        foreach ((array)($manifest['aliases'] ?? []) as $alias) {
            if (!is_array($alias)) { throw new \RuntimeException("Invalid wallpaper compatibility alias in $setId"); }
            $aliasSetId = self::wallpaperSetId((string)($alias['set_id'] ?? ''));
            $profileKey = (string)($alias['profile'] ?? '');
            $profile = ModelCatalog::wallpaperProfileByKey($profileKey);
            if ($profile === null || !hash_equals((string)$profile['dir'], (string)($alias['dir'] ?? ''))) {
                throw new \RuntimeException("Unsupported wallpaper compatibility alias in $setId");
            }
            $aliasFull = (string)($alias['full'] ?? '');
            $aliasThumbnail = (string)($alias['thumbnail'] ?? '');
            if (!self::wallpaperOutputNameIsSafe($aliasFull, $aliasSetId, $profile, 'full', (string)($alias['full_sha256'] ?? ''), (string)($alias['thumbnail_sha256'] ?? ''))
                || !self::wallpaperOutputNameIsSafe($aliasThumbnail, $aliasSetId, $profile, 'thumbnail', (string)($alias['full_sha256'] ?? ''), (string)($alias['thumbnail_sha256'] ?? ''))) {
                throw new \RuntimeException("Unsafe wallpaper compatibility alias in $setId");
            }
            $models = array_values(array_unique(array_map(
                static fn($model): string => strtoupper(trim((string)$model)),
                (array)($alias['models'] ?? $profile['models'])
            )));
            if (!$models || array_diff($models, (array)$profile['models'])) {
                throw new \RuntimeException("Invalid wallpaper compatibility alias models in $setId");
            }
            $aliases[] = [
                'set_id'=>$aliasSetId,
                'profile'=>$profileKey,
                'models'=>$models,
                'dir'=>(string)$profile['dir'],
                'full'=>$aliasFull,
                'thumbnail'=>$aliasThumbnail,
                'full_sha256'=>(string)($alias['full_sha256'] ?? ''),
                'thumbnail_sha256'=>(string)($alias['thumbnail_sha256'] ?? ''),
            ];
        }
        $manifest['aliases'] = self::uniqueWallpaperAliases($aliases);
        return $manifest;
    }

    /** @param object $sftp @param string[] $directories */
    private function ensureRemoteDirectories($sftp, array $directories): void
    {
        foreach ($directories as $directory) {
            if (!$sftp->is_dir($directory) && !$sftp->mkdir($directory)) {
                throw new \RuntimeException("Could not create $directory");
            }
            $sftp->chmod($this->isPrivateTftpPath($directory)
                ? self::PRIVATE_DIRECTORY_MODE
                : self::PUBLIC_DIRECTORY_MODE, $directory);
        }
    }

    /**
     * Inspect the phone-facing TFTP tree without entering the private transaction ledger.
     * Repair only adds the read/traverse bits required by an anonymous TFTP daemon; it never
     * changes ownership or removes existing owner/group permissions.
     */
    public function auditPublishedPermissions(int $siteId, bool $repair = false): array
    {
        return $repair ? $this->withSiteLock($siteId, fn() => $this->auditPublishedPermissionsLocked($siteId, true))
            : $this->auditPublishedPermissionsLocked($siteId, false);
    }

    private function auditPublishedPermissionsLocked(int $siteId, bool $repair): array
    {
        $conn = $this->ucm->siteConnection($siteId);
        $sftp = $conn->dockerSftp();
        $root = rtrim($conn->tftpRoot(), '/');
        $queue = [$root];
        $directories = 0;
        $entries = 0;
        $fixed = 0;
        $issues = [];
        $truncated = false;

        $rootMetadata = method_exists($sftp, 'lstat') ? $sftp->lstat($root) : $sftp->stat($root);
        if (!is_array($rootMetadata)) {
            throw new \RuntimeException('Cannot inspect the TFTP root directory');
        }
        $rootModeValue = $rootMetadata['mode'] ?? $rootMetadata['permissions'] ?? null;
        $rootMode = is_numeric($rootModeValue) ? ((int)$rootModeValue & 0777) : null;
        if ($rootMode !== null && ($rootMode & 0005) !== 0005) {
            $desired = $rootMode | 0005;
            $repaired = $repair && (bool)$sftp->chmod($desired, $root);
            if ($repaired) { $fixed++; }
            $issues[] = [
                'path'=>'[TFTP root]',
                'kind'=>'directory',
                'mode'=>sprintf('%04o', $rootMode),
                'required'=>sprintf('%04o', $desired),
                'repaired'=>$repaired,
                'message'=>$repaired
                    ? 'Phone-facing root permissions repaired'
                    : ($repair ? 'Root permission repair failed' : 'TFTP root is not readable/traversable by the TFTP service'),
            ];
        }

        while ($queue) {
            if (++$directories > self::MAX_PERMISSION_AUDIT_DIRECTORIES) {
                $truncated = true;
                break;
            }
            $directory = array_shift($queue);
            $listed = $sftp->rawlist($directory);
            if (!is_array($listed)) {
                throw new \RuntimeException("Cannot inspect TFTP directory $directory");
            }
            foreach ($listed as $name=>$metadata) {
                $name = (string)$name;
                if ($name === '.' || $name === '..') { continue; }
                if (++$entries > self::MAX_PERMISSION_AUDIT_ENTRIES) {
                    $truncated = true;
                    break 2;
                }
                $path = $directory . '/' . $name;
                $relative = ltrim(substr($path, strlen($root)), '/');
                if ($relative === '.ucmprovisioner' || str_starts_with($relative, '.ucmprovisioner/')) {
                    continue;
                }
                $type = (int)($metadata['type'] ?? 0);
                if ($type === 3) {
                    $issues[] = ['path'=>$relative, 'kind'=>'symlink', 'message'=>'Symlink skipped; published TFTP paths must be real files or directories'];
                    continue;
                }
                $isDirectory = $type === 2 || $sftp->is_dir($path);
                $modeValue = $metadata['mode'] ?? $metadata['permissions'] ?? null;
                $mode = is_numeric($modeValue) ? ((int)$modeValue & 0777) : null;
                if ($mode !== null) {
                    $required = $isDirectory ? 0005 : 0004;
                    if (($mode & $required) !== $required) {
                        $desired = $mode | $required;
                        $repaired = false;
                        if ($repair) {
                            $repaired = (bool)$sftp->chmod($desired, $path);
                            if ($repaired) { $fixed++; }
                        }
                        $issues[] = [
                            'path'=>$relative,
                            'kind'=>$isDirectory ? 'directory' : 'file',
                            'mode'=>sprintf('%04o', $mode),
                            'required'=>sprintf('%04o', $desired),
                            'repaired'=>$repaired,
                            'message'=>$repaired
                                ? 'Phone-facing permissions repaired'
                                : ($repair ? 'Permission repair failed' : 'Not readable/traversable by the TFTP service'),
                        ];
                    }
                }
                if ($isDirectory) { $queue[] = $path; }
            }
        }

        $remaining = count(array_filter($issues, static fn(array $issue): bool => empty($issue['repaired'])));
        return [
            'ok'=>!$truncated && (!$repair || $remaining === 0),
            'site_id'=>$siteId,
            'repair'=>$repair,
            'directories_scanned'=>min($directories, self::MAX_PERMISSION_AUDIT_DIRECTORIES),
            'entries_scanned'=>min($entries, self::MAX_PERMISSION_AUDIT_ENTRIES),
            'issues'=>$issues,
            'fixed'=>$fixed,
            'remaining'=>$remaining,
            'truncated'=>$truncated,
            'message'=>$truncated
                ? 'Permission audit reached its safety limit; nothing beyond that point was changed'
                : ($remaining === 0
                    ? ($repair && $fixed ? "Repaired $fixed phone-facing permission issue(s)" : 'Phone-facing TFTP permissions are usable')
                    : "$remaining phone-facing permission issue(s) require attention"),
        ];
    }

    /**
     * Stage one file or a bounded ZIP of files/directories for an authenticated GUI download.
     * The caller owns and must unlink the returned temporary file.
     *
     * @param string[] $selectedPaths
     * @return array{path:string,filename:string,content_type:string,size:int,file_count:int}
     */
    public function prepareDownload(int $siteId, array $selectedPaths): array
    {
        $paths = [];
        foreach ($selectedPaths as $selectedPath) {
            $path = $this->sanitizeRel((string)$selectedPath);
            if ($path === '' || $this->isPrivateTftpPath($path)) {
                throw new \InvalidArgumentException('Choose only published files or directories below the TFTP root');
            }
            $paths[$path] = true;
        }
        $paths = array_keys($paths);
        if (!$paths || count($paths) > self::MAX_DOWNLOAD_SELECTIONS) {
            throw new \InvalidArgumentException('Choose between one and ' . self::MAX_DOWNLOAD_SELECTIONS . ' files or directories');
        }

        $conn = $this->ucm->siteConnection($siteId);
        $sftp = $conn->dockerSftp();
        $root = rtrim($conn->tftpRoot(), '/');
        $files = [];
        $directories = [];
        $total = 0;
        foreach ($paths as $path) {
            $this->collectDownloadPaths($sftp, $root, $path, $files, $directories, $total);
        }
        if (!$files && !$directories) {
            throw new \RuntimeException('The selected paths contain no downloadable content');
        }

        if (count($paths) === 1 && count($files) === 1 && !$directories) {
            $relative = array_key_first($files);
            $temporary = tempnam(sys_get_temp_dir(), 'ucmp-download-');
            if ($temporary === false) { throw new \RuntimeException('Could not allocate the download file'); }
            try {
                $this->remotePathMetadata($sftp, $root, $relative, [1]);
                if (!$sftp->get($root . '/' . $relative, $temporary, 0, $files[$relative] + 1)) {
                    throw new \RuntimeException("Could not download $relative from the site TFTP volume");
                }
                $size = (int)(filesize($temporary) ?: 0);
                if ($size > $files[$relative] || $size > self::MAX_DOWNLOAD_BYTES) { throw new \RuntimeException('The selected file grew beyond the declared download size'); }
                @chmod($temporary, 0600);
                return [
                    'path'=>$temporary,
                    'filename'=>basename($relative),
                    'content_type'=>'application/octet-stream',
                    'size'=>$size,
                    'file_count'=>1,
                ];
            } catch (\Throwable $e) {
                @unlink($temporary);
                throw $e;
            }
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive is required for multi-file and directory downloads');
        }
        $archive = tempnam(sys_get_temp_dir(), 'ucmp-tftp-');
        if ($archive === false) { throw new \RuntimeException('Could not allocate the download archive'); }
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($archive);
            throw new \RuntimeException('Could not create the download archive');
        }
        $staged = [];
        $actualTotal = 0;
        try {
            foreach (array_keys($directories) as $directory) {
                $zip->addEmptyDir($directory);
            }
            foreach ($files as $relative=>$declaredSize) {
                $temporary = tempnam(sys_get_temp_dir(), 'ucmp-download-item-');
                if ($temporary === false) { throw new \RuntimeException('Could not allocate an archive staging file'); }
                $staged[] = $temporary;
                $this->remotePathMetadata($sftp, $root, $relative, [1]);
                if (!$sftp->get($root . '/' . $relative, $temporary, 0, $declaredSize + 1)) {
                    throw new \RuntimeException("Could not download $relative from the site TFTP volume");
                }
                $actualSize = (int)(filesize($temporary) ?: 0);
                if ($actualSize > self::MAX_DOWNLOAD_BYTES || $actualSize > $declaredSize) {
                    throw new \RuntimeException("$relative changed unexpectedly during download");
                }
                $actualTotal += $actualSize;
                if ($actualTotal > self::MAX_DOWNLOAD_BYTES) {
                    throw new \RuntimeException('The downloaded files exceed the 1 GiB safety limit');
                }
                if (!$zip->addFile($temporary, $relative)) {
                    throw new \RuntimeException("Could not add $relative to the download archive");
                }
            }
            if (!$zip->close()) { throw new \RuntimeException('Could not finalize the download archive'); }
            $zip = null;
            foreach ($staged as $temporary) { @unlink($temporary); }
            $staged = [];
            @chmod($archive, 0600);
            $archiveSize = (int)(filesize($archive) ?: 0);
            return [
                'path'=>$archive,
                'filename'=>'site-' . $siteId . '-tftp-' . gmdate('Ymd-His') . '.zip',
                'content_type'=>'application/zip',
                'size'=>$archiveSize,
                'file_count'=>count($files),
            ];
        } catch (\Throwable $e) {
            if ($zip instanceof \ZipArchive) { $zip->close(); }
            foreach ($staged as $temporary) { @unlink($temporary); }
            @unlink($archive);
            throw $e;
        }
    }

    /** @param object $sftp @param array<string,int> $files @param array<string,bool> $directories */
    private function collectDownloadPaths($sftp, string $root, string $selected, array &$files, array &$directories, int &$total): void
    {
        $queue = [[$selected, 0]];
        while ($queue) {
            [$relative, $depth] = array_shift($queue);
            if ($depth > self::MAX_DOWNLOAD_DEPTH) {
                throw new \RuntimeException("Download directory depth exceeds the limit at $relative");
            }
            $full = $root . '/' . $relative;
            $metadata = $this->remotePathMetadata($sftp, $root, $relative);
            if (!is_array($metadata)) { throw new \RuntimeException("Selected TFTP path is missing: $relative"); }
            if ((int)($metadata['type'] ?? 0) === 3) {
                throw new \RuntimeException("Symlink downloads are not allowed: $relative");
            }
            $isDirectory = (int)($metadata['type'] ?? 0) === 2 || $sftp->is_dir($full);
            if (!$isDirectory) {
                $size = max(0, (int)($metadata['size'] ?? 0));
                if (!isset($files[$relative])) {
                    if (count($files) >= self::MAX_DOWNLOAD_FILES) { throw new \RuntimeException('The selection contains too many files'); }
                    if ($total + $size > self::MAX_DOWNLOAD_BYTES) { throw new \RuntimeException('The selection exceeds the 1 GiB download safety limit'); }
                    $files[$relative] = $size;
                    $total += $size;
                }
                continue;
            }

            $directories[$relative] = true;
            $listed = $sftp->rawlist($full);
            if (!is_array($listed)) { throw new \RuntimeException("Cannot list selected TFTP directory: $relative"); }
            foreach ($listed as $name=>$childMetadata) {
                $name = (string)$name;
                if ($name === '.' || $name === '..') { continue; }
                if ($name === '' || preg_match('#[\\/\x00-\x1f\x7f]#', $name)) {
                    throw new \RuntimeException("Unsafe entry name found below $relative");
                }
                $child = $this->sanitizeRel($relative . '/' . $name);
                if ($this->isPrivateTftpPath($child) || (int)($childMetadata['type'] ?? 0) === 3) {
                    throw new \RuntimeException("Private or symlinked entry cannot be downloaded: $child");
                }
                $queue[] = [$child, $depth + 1];
            }
        }
    }

    private function isPrivateTftpPath(string $path): bool
    {
        return preg_match('#(?:^|/)\.ucmprovisioner(?:/|$)#D', str_replace('\\', '/', $path)) === 1;
    }

    /** Inspect the complete path so a regular file beneath a linked directory is rejected too. */
    private function remotePathMetadata($sftp, string $root, string $relative, array $types = [1,2]): ?array
    {
        if (method_exists($sftp, 'clearStatCache')) { $sftp->clearStatCache(); }
        $stat = static fn(string $path) => method_exists($sftp, 'lstat') ? $sftp->lstat($path) : $sftp->stat($path);
        $root = rtrim($root, '/');
        $metadata = $stat($root === '' ? '/' : $root);
        if (!is_array($metadata) || (int)($metadata['type'] ?? 0) !== 2) { throw new \RuntimeException('TFTP root is not a real directory'); }
        if ($relative === '') { return $metadata; }
        $parts = explode('/', $relative); $path = $root;
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.' || $part === '..') { throw new \InvalidArgumentException('Invalid TFTP path component'); }
            $path .= '/' . $part; $metadata = $stat($path);
            if ($metadata === false) { return null; }
            $allowed = $index === count($parts) - 1 ? $types : [2];
            if (!is_array($metadata) || !in_array((int)($metadata['type'] ?? 0), $allowed, true)) {
                throw new \RuntimeException('TFTP path contains a link or unexpected file type');
            }
        }
        return $metadata;
    }

    /** Compact immutable aliases must never replace different bytes after a hash-prefix collision. */
    private function assertCompactTarget($sftp, string $path, string $sha256): void
    {
        $name = basename($path);
        if (!AssetFilename::isImmutable($name) || !$this->remoteExists($sftp, $path)) { return; }
        $existing = $this->readBounded($sftp, $path, self::MAX_TIMEZONE_FILE_BYTES, 'compact asset conflict check');
        if (empty($existing['ok'])) { throw new \RuntimeException("Cannot verify compact asset $name before publication"); }
        AssetFilename::assertSameContent($name, $sha256, hash('sha256', (string)$existing['content']));
    }

    /** @param object $sftp */
    private function writeRemoteFile($sftp, string $path, string $content, int $mode): void
    {
        $this->replaceRemoteFiles($sftp, [$path=>['content'=>$content, 'mode'=>$mode]]);
    }

    /** @param object $sftp @param array<string,array{content?:string,local_path?:string,bytes?:int,sha256?:string,mode:int}> $files */
    private function replaceRemoteFiles($sftp, array $files): void
    {
        if (!$files) { return; }
        $token = bin2hex(random_bytes(16));
        $staged = []; $backups = []; $activated = []; $verified = [];
        $liveAttempted = false; $intentPath = null;
        $receipt = ['transaction_id'=>$token, 'stage'=>'staging', 'files'=>[], 'activated'=>[], 'verified'=>[]];
        try {
            foreach ($files as $path=>$file) {
                $tmp = $path . '.tmp-' . $token;
                $backup = $path . '.bak-' . $token;
                $existing = method_exists($sftp, 'lstat') ? $sftp->lstat($path) : $sftp->stat($path);
                if (is_array($existing) && (int)($existing['type'] ?? 0) !== 1) { throw new \RuntimeException('Replacement target must be a regular file'); }
                if ($this->remoteExists($sftp, $tmp) || $this->remoteExists($sftp, $backup)) { throw new \RuntimeException('Replacement staging or backup name already exists'); }
                $local = isset($file['local_path']);
                $expected = $local
                    ? ['bytes'=>(int)($file['bytes'] ?? -1),'sha256'=>(string)($file['sha256'] ?? '')]
                    : ['bytes'=>strlen($file['content']),'sha256'=>hash('sha256',$file['content'])];
                if ($expected['bytes'] < 0 || $expected['bytes'] > self::MAX_SCAN_FILE_BYTES || !preg_match('/^[a-f0-9]{64}$/D', $expected['sha256'])) {
                    throw new \RuntimeException('Replacement payload has no valid bounded size and SHA-256');
                }
                $expected['mode'] = (int)$file['mode'];
                $this->assertCompactTarget($sftp, $path, $expected['sha256']);
                $receipt['files'][$path] = ['staged'=>$tmp, 'backup'=>is_array($existing) ? $backup : null, 'expected'=>$expected];
                $staged[$path] = $tmp;
                $written = $local ? $sftp->put($tmp, $file['local_path'], \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE) : $sftp->put($tmp, $file['content']);
                if (!$written) { throw new \RuntimeException("Could not stage $path"); }
                if (!$sftp->chmod((int)$file['mode'], $tmp)) { throw new \RuntimeException("Could not set staged permissions for $path"); }
                $this->verifyRemotePayload($sftp, $tmp, $expected);
            }
            // The immutable intent gives recovery every candidate path and expected hash,
            // even if the reply to the very first rename is lost.
            foreach (self::$siteLocks as $lock) {
                if ($lock['sftp_id'] !== spl_object_id($sftp) || !str_starts_with((string)array_key_first($files), $lock['root'] . '/')) { continue; }
                $intentPath = $lock['path'] . '/replacement-' . $token . '.json';
                $receipt['intent_path'] = $intentPath;
                $intent = self::remoteJsonContent($receipt);
                if (strlen($intent) > self::MAX_TEXT_READ_BYTES || !$sftp->put($intentPath, $intent) || !$sftp->chmod(0600, $intentPath)) {
                    throw new \RuntimeException('Could not preserve file replacement intent');
                }
                $this->verifyRemotePayload($sftp, $intentPath, ['bytes'=>strlen($intent),'sha256'=>hash('sha256',$intent),'mode'=>0600]);
                break;
            }
            foreach ($receipt['files'] as $path=>$file) {
                $receipt['path'] = $path;
                if ($file['backup'] !== null) {
                    $receipt['stage'] = 'preserve';
                    $liveAttempted = true;
                    if (!$sftp->rename($path, $file['backup'])) { throw new \RuntimeException("Could not confirm preservation of $path"); }
                    $backups[$path] = $file['backup'];
                }
                $receipt['stage'] = 'activate';
                $liveAttempted = true;
                if (!$sftp->rename($staged[$path], $path)) { throw new \RuntimeException("Could not confirm activation of $path"); }
                $activated[] = $path;
                unset($staged[$path]);
                $receipt['stage'] = 'verify';
                if (method_exists($sftp, 'clearStatCache')) { $sftp->clearStatCache(); }
                $this->verifyRemotePayload($sftp, $path, $file['expected']);
                $verified[] = $path;
            }
            $receipt['stage'] = 'cleanup';
            foreach ($backups as $backup) {
                if (!$sftp->delete($backup)) { throw new \RuntimeException('Published files verified, but retained before-state cleanup is unconfirmed'); }
            }
            if ($intentPath !== null && !$sftp->delete($intentPath)) { throw new \RuntimeException('Published files verified, but intent cleanup is unconfirmed'); }
        } catch (\Throwable $error) {
            $receipt['activated'] = $activated;
            $receipt['verified'] = $verified;
            $receipt['publication_complete'] = count($verified) === count($files);
            if ($liveAttempted) { $this->retainReplacementFailure($sftp, $receipt, $error); }
            $cleanupErrors = [];
            foreach (array_merge(array_values($staged), $intentPath === null ? [] : [$intentPath]) as $tmp) {
                try {
                    if ($this->remoteExists($sftp, $tmp) && !$sftp->delete($tmp)) { throw new \RuntimeException('Staging cleanup was rejected'); }
                } catch (\Throwable $cleanupError) { $cleanupErrors[$tmp] = $cleanupError->getMessage(); }
            }
            if ($cleanupErrors) {
                $receipt['cleanup_errors'] = $cleanupErrors;
                $this->retainReplacementFailure($sftp, $receipt, $error);
            }
            throw $error;
        }
    }

    /**
     * Rebuild a Desktops/<resolution>/List.xml from the PNG files actually present
     * in that directory — schema per https://usecallmanager.nz/background-images.html.
     * Each full-size image "Name.png" needs a companion thumbnail "Name-tn.png";
     * images without one are skipped (reported) rather than emitting a
     * non-compliant entry. Non-PNG clutter (stray files) is ignored.
     */
    private function regenerateImageList(int $siteId, $sftp, string $dirPath, string $relDir): array
    {
        $entries = $sftp->nlist($dirPath);
        if ($entries === false) {
            return ['ok' => false, 'message' => "Cannot list $dirPath"];
        }
        $aliases = [];
        if (preg_match('#^(.*)/Desktops/([^/]+)$#D', rtrim($dirPath, '/'), $match)) {
            $setRoot = $match[1] . '/' . self::WALLPAPER_SET_ROOT;
            $setEntries = $sftp->is_dir($setRoot) ? $sftp->nlist($setRoot) : [];
            foreach ((array)$setEntries as $setId) {
                if (in_array($setId, ['.','..'], true)) { continue; }
                try {
                    $manifest = $this->readWallpaperSetManifest($sftp, $match[1], (string)$setId);
                    foreach ((array)($manifest['variants'] ?? []) as $variant) {
                        if (!empty($variant['enabled'])
                            || !hash_equals((string)$match[2], (string)$variant['dir'])) {
                            continue;
                        }
                        $aliases[(string)$variant['full']] = true;
                        if ((string)$variant['thumbnail'] !== '') {
                            $aliases[(string)$variant['thumbnail']] = true;
                        }
                    }
                    foreach ((array)($manifest['aliases'] ?? []) as $alias) {
                        if (!hash_equals((string)$match[2], (string)$alias['dir'])) { continue; }
                        $aliases[(string)$alias['full']] = true;
                        if ((string)$alias['thumbnail'] !== '') {
                            $aliases[(string)$alias['thumbnail']] = true;
                        }
                    }
                } catch (\Throwable $ignored) {
                    // A broken set is reported by the catalog UI; never let it
                    // prevent rebuilding unrelated valid List.xml entries.
                }
            }
        }
        $pngs = array_values(array_filter(
            $entries,
            static fn($name): bool => preg_match('/\.png$/i', (string)$name) === 1
                && !isset($aliases[(string)$name])
        ));
        $thumbs = array_flip(array_values(array_filter($pngs, fn($n) => preg_match('/-tn\.png$/i', $n))));
        $fulls = array_values(array_filter($pngs, fn($n) => !preg_match('/-tn\.png$/i', $n)));

        $items = [];
        $skipped = [];
        foreach ($fulls as $full) {
            $base = preg_replace('/\.png$/i', '', $full);
            $tn = "$base-tn.png";
            if (!isset($thumbs[$tn])) {
                $skipped[] = "$full (no matching $tn thumbnail — Cisco requires both)";
                continue;
            }
            $name = ucwords(str_replace(['-', '_'], ' ', $base));
            $items[] = ['name' => $name, 'image' => "TFTP:$relDir/$tn", 'url' => "TFTP:$relDir/$full"];
        }

        if (count($items) > 50) {
            $items = array_slice($items, 0, 50);
            $skipped[] = 'More than 50 images found — Cisco caps ImageItem entries at 50; extras were dropped.';
        }

        $listRelative = trim($relDir, '/') . '/List.xml';
        $listPath = rtrim($dirPath, '/') . '/List.xml';
        if (!$items) {
            if ($this->remoteExists($sftp, $listPath) && !$sftp->delete($listPath)) {
                return ['ok'=>false, 'count'=>0, 'skipped'=>$skipped, 'message'=>"Could not remove empty $listRelative"];
            }
            $warning = null;
            try {
                $metadata = self::managedAssetMetadata($listRelative) ?? [];
                (new ManagedAssetRegistry($this->ucm->getDb()))->markSlotStale(
                    $siteId,
                    'background',
                    'List.xml',
                    isset($metadata['profile']) ? (string)$metadata['profile'] : null,
                    isset($metadata['bundle_key']) ? (string)$metadata['bundle_key'] : null
                );
            } catch (\Throwable $error) {
                $warning = 'List.xml was removed, but its managed-asset row could not be retired: ' . $error->getMessage();
            }
            return [
                'ok'=>true,
                'count'=>0,
                'skipped'=>$skipped,
                'asset'=>null,
                'registry_warning'=>$warning,
                'message'=>'No complete wallpaper pairs remain; List.xml was removed.',
            ];
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('CiscoIPPhoneImageList');
        $doc->appendChild($root);
        foreach ($items as $it) {
            $el = $doc->createElement('ImageItem');
            $el->setAttribute('Image', $it['image']);
            $el->setAttribute('URL', $it['url']);
            $root->appendChild($el);
        }
        $xml = (string)$doc->saveXML();

        $this->writeRemoteFile($sftp, $listPath, $xml, 0664);
        $ok = true;
        $registered = $ok
            ? $this->registerManagedPayload(
                $siteId,
                $listRelative,
                hash('sha256', $xml),
                strlen($xml),
                [
                    'validation_status'=>'verified',
                    'provenance'=>'generated',
                    'signature_status'=>'not_applicable',
                    'metadata'=>['format'=>'xml', 'magic'=>'generated XML'],
                ]
            )
            : [];
        return [
            'ok'      => (bool) $ok,
            'count'   => count($items),
            'skipped' => $skipped,
            'asset'   => $registered['asset'] ?? null,
            'registry_warning' => $registered['warning'] ?? null,
            'message' => $ok
                ? sprintf('List.xml regenerated with %d image(s)%s', count($items), $skipped ? (' — ' . count($skipped) . ' skipped, see details') : '')
                : 'Failed to write List.xml',
        ];
    }

    /**
     * Upload and hash-verify a complete build without exposing any staged file
     * at the TFTP root.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<string,mixed> $manifest
     */
    public function stageGeneration(
        int $siteId,
        string $transactionId,
        array $files,
        array $manifest
    ): array {
        try { return $this->withSiteLock($siteId, fn() => $this->stageGenerationLocked($siteId, $transactionId, $files, $manifest)); }
        catch (\Throwable $e) { return ['ok'=>false, 'transaction_id'=>$transactionId, 'message'=>$e->getMessage()]; }
    }

    private function stageGenerationLocked(int $siteId, string $transactionId, array $files, array $manifest): array
    {
        try {
            BuildPlanner::assertTransactionId($transactionId);
            if ((int)($manifest['site_id'] ?? 0) !== $siteId
                || !hash_equals($transactionId, (string)($manifest['transaction_id'] ?? ''))) {
                throw new \InvalidArgumentException('Build manifest does not match the requested site/transaction');
            }
            if (count($files) < 1 || count($files) > self::MAX_GENERATION_FILES) {
                throw new \InvalidArgumentException('A generation must contain between 1 and ' . self::MAX_GENERATION_FILES . ' files');
            }

            $manifestFiles = [];
            foreach ((array)($manifest['files'] ?? []) as $row) {
                $name = (string)($row['filename'] ?? '');
                BuildPlanner::assertFilename($name);
                $manifestFiles[$name] = $row;
            }
            $payloads = [];
            $total = 0;
            foreach ($files as $file) {
                $name = (string)($file['filename'] ?? '');
                BuildPlanner::assertFilename($name);
                $content = (string)($file['content'] ?? '');
                $total += strlen($content);
                if ($total > self::MAX_GENERATION_BYTES) {
                    throw new \InvalidArgumentException('Generated configuration payload exceeds the transaction limit');
                }
                $expected = $manifestFiles[$name] ?? null;
                if (!$expected
                    || (int)($expected['bytes'] ?? -1) !== strlen($content)
                    || !hash_equals((string)($expected['sha256'] ?? ''), hash('sha256', $content))) {
                    throw new \RuntimeException("Generated content no longer matches the manifest for $name");
                }
                if (isset($payloads[$name])
                    && !hash_equals(hash('sha256', $payloads[$name]), hash('sha256', $content))) {
                    throw new \RuntimeException("Generation contains conflicting content for $name");
                }
                $payloads[$name] = $content;
            }
            if (count($payloads) !== count($manifestFiles)) {
                throw new \RuntimeException('Generation payload and manifest file sets differ');
            }

            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $root = rtrim($conn->tftpRoot(), '/');
            $txRoot = $root . '/.ucmprovisioner/transactions/' . $transactionId;
            $stage = $txRoot . '/stage';
            $backup = $txRoot . '/backup';
            $this->ensureRemoteDirectory($sftp, $stage);
            $this->ensureRemoteDirectory($sftp, $backup);

            $existing = $this->readRemoteJson($sftp, $txRoot . '/manifest.json', false);
            if ($existing) {
                BuildPlanner::verifyManifest($existing);
                if (!hash_equals((string)($existing['plan_hash'] ?? ''), (string)($manifest['plan_hash'] ?? ''))) {
                    throw new \RuntimeException('Transaction id already belongs to a different build plan');
                }
                $existingReceipt = $this->readRemoteJson($sftp, $txRoot . '/receipt.json', false);
                if ($existingReceipt && ($existingReceipt['state'] ?? '') !== 'staged') {
                    throw new \RuntimeException('Transaction has already advanced beyond staging');
                }
                foreach ($manifestFiles as $name=>$row) { $this->verifyRemotePayload($sftp, $stage . '/' . $name, $row); }
                if (!$existingReceipt) {
                    $this->writeRemoteJson($sftp, $txRoot . '/receipt.json', [
                        'schema_version'=>1, 'transaction_id'=>$transactionId, 'site_id'=>$siteId,
                        'state'=>'staged', 'created_at'=>time(), 'files'=>[],
                    ]);
                }
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'transaction_id' => $transactionId,
                    'staged' => count($manifestFiles),
                    'bytes' => array_sum(array_column($manifestFiles, 'bytes')),
                    'message' => 'The same verified build generation was already staged',
                ];
            }

            foreach ($payloads as $name => $content) {
                $path = $stage . '/' . $name;
                if (!$sftp->put($path, $content)) {
                    throw new \RuntimeException("Could not stage $name");
                }
                $sftp->chmod(0664, $path);
                $readBack = $sftp->get($path);
                if (!is_string($readBack)
                    || !hash_equals((string)$manifestFiles[$name]['sha256'], hash('sha256', $readBack))) {
                    throw new \RuntimeException("Hash verification failed after staging $name");
                }
            }
            $this->writeRemoteJson($sftp, $txRoot . '/manifest.json', $manifest);
            $this->writeRemoteJson($sftp, $txRoot . '/receipt.json', [
                'schema_version' => 1,
                'transaction_id' => $transactionId,
                'site_id' => $siteId,
                'state' => 'staged',
                'created_at' => time(),
                'files' => [],
            ]);

            return [
                'ok' => true,
                'transaction_id' => $transactionId,
                'staged' => count($payloads),
                'bytes' => $total,
                'message' => 'Complete generation staged and hash-verified',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Promote staged files with atomic renames and durable per-file intent.
     * An uncertain move retains completed files and before-state for recovery.
     */
    public function commitGeneration(int $siteId, string $transactionId): array
    {
        try { return $this->withSiteLock($siteId, fn() => $this->commitGenerationLocked($siteId, $transactionId)); }
        catch (\Throwable $e) { return ['ok'=>false, 'transaction_id'=>$transactionId, 'message'=>$e->getMessage()]; }
    }

    private function commitGenerationLocked(int $siteId, string $transactionId): array
    {
        try {
            BuildPlanner::assertTransactionId($transactionId);
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $root = rtrim($conn->tftpRoot(), '/');
            $txRoot = $root . '/.ucmprovisioner/transactions/' . $transactionId;
            $manifest = $this->readRemoteJson($sftp, $txRoot . '/manifest.json');
            if ((int)($manifest['site_id'] ?? 0) !== $siteId
                || !hash_equals($transactionId, (string)($manifest['transaction_id'] ?? ''))) {
                throw new \RuntimeException('Staged manifest identity mismatch');
            }
            $receiptPath = $txRoot . '/receipt.json';
            $receipt = $this->readRemoteJson($sftp, $receiptPath);
            if ((int)($receipt['site_id'] ?? 0) !== $siteId || ($receipt['transaction_id'] ?? '') !== $transactionId) {
                throw new \RuntimeException('Generation receipt identity mismatch');
            }
            $current = $this->readRemoteJson($sftp, $root . '/.ucmprovisioner/current.json', false);
            if (!empty($current['recovery_transaction'])) { throw new \RuntimeException('Destination has an unfinished recovery'); }
            BuildPlanner::verifyManifest($manifest);
            if (($receipt['state'] ?? '') === 'committed') {
                if (($current['generation_id'] ?? '') !== $transactionId) {
                    throw new \RuntimeException('The requested generation is no longer active');
                }
                foreach ($manifest['files'] as $file) { $this->verifyRemotePayload($sftp, $root . '/' . $file['filename'], $file); }
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'transaction_id' => $transactionId,
                    'generation_id' => $transactionId,
                    'files' => (array)($receipt['files'] ?? []),
                    'message' => 'The requested generation is already active',
                ];
            }
            if (($receipt['state'] ?? '') !== 'staged') {
                throw new \RuntimeException('Generation is not in a committable staged state');
            }
            if (($manifest['previous_generation_id'] ?? '') !== ($current['generation_id'] ?? '')) {
                throw new \RuntimeException('Current generation changed since preview; create a new plan');
            }
            foreach ($manifest['files'] as $file) {
                BuildPlanner::assertFilename((string)$file['filename']);
                $this->verifyRemotePayload($sftp, $txRoot . '/stage/' . $file['filename'], $file);
                $this->assertCompactTarget($sftp, $root . '/' . $file['filename'], (string)$file['sha256']);
            }

            $receipt['state'] = 'committing';
            $receipt['files'] = [];
            $receipt['previous_pointer'] = $this->readRemoteJson(
                $sftp,
                $root . '/.ucmprovisioner/current.json',
                false
            );
            $this->writeRemoteJson($sftp, $receiptPath, $receipt);

            try {
                foreach ((array)$manifest['files'] as $file) {
                    $name = (string)($file['filename'] ?? '');
                    BuildPlanner::assertFilename($name);
                    $target = $root . '/' . $name;
                    $staged = $txRoot . '/stage/' . $name;
                    $backup = $txRoot . '/backup/' . $name;
                    if (!$this->remoteExists($sftp, $staged)) {
                        throw new \RuntimeException("Staged file disappeared before commit: $name");
                    }
                    $hadPrevious = $this->remoteExists($sftp, $target);
                    $entry = [
                        'filename' => $name,
                        'sha256' => (string)($file['sha256'] ?? ''),
                        'bytes' => (int)($file['bytes'] ?? 0),
                        'phone_id' => (int)($file['phone_id'] ?? 0),
                        'extension' => (string)($file['extension'] ?? ''),
                        'had_previous' => $hadPrevious,
                        'backup_moved' => false,
                        'committed' => false,
                        'previous_sha256' => $hadPrevious ? $this->remotePayloadHash($sftp, $target) : null,
                        'intent' => 'preserve',
                    ];
                    $receipt['files'][] = $entry;
                    $entryIndex = array_key_last($receipt['files']);
                    $this->writeRemoteJson($sftp, $receiptPath, $receipt);
                    if ($hadPrevious) {
                        if ($this->remoteExists($sftp, $backup) || !$sftp->rename($target, $backup)) {
                            throw new \RuntimeException("Could not preserve the previous $name");
                        }
                        $receipt['files'][$entryIndex]['backup_moved'] = true;
                    }
                    $receipt['files'][$entryIndex]['intent'] = 'activate';
                    $this->writeRemoteJson($sftp, $receiptPath, $receipt);
                    $this->verifyRemotePayload($sftp, $staged, $file);
                    if (!$sftp->rename($staged, $target)) {
                        throw new \RuntimeException("Could not activate staged $name");
                    }
                    if (!$sftp->chmod(0664, $target)) { throw new \RuntimeException("Published permissions were not confirmed for $name"); }
                    $this->verifyRemotePayload($sftp, $target, $file);
                    $receipt['files'][$entryIndex]['committed'] = true;
                    $this->writeRemoteJson($sftp, $receiptPath, $receipt);
                }

                $pointer = [
                    'schema_version' => 1,
                    'site_id' => $siteId,
                    'generation_id' => $transactionId,
                    'plan_hash' => (string)($manifest['plan_hash'] ?? ''),
                    'activated_at' => time(),
                ];
                $this->writeRemoteJson($sftp, $root . '/.ucmprovisioner/current.json', $pointer);
                $receipt['state'] = 'committed';
                $receipt['committed_at'] = time();
                $this->writeRemoteJson($sftp, $receiptPath, $receipt);
            } catch (\Throwable $e) {
                return ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'transaction_id'=>$transactionId,
                    'receipt'=>$receiptPath,'files'=>$receipt['files'],
                    'message'=>'Generation publication needs reconciliation; completed files and before-state were retained: '.$e->getMessage()];
            }

            return [
                'ok' => true,
                'transaction_id' => $transactionId,
                'generation_id' => $transactionId,
                'files' => $receipt['files'],
                'message' => 'Generation activated; every target has an exact rollback copy',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function rollbackGeneration(int $siteId, string $transactionId): array
    {
        try { return $this->withSiteLock($siteId, fn() => $this->rollbackGenerationLocked($siteId, $transactionId)); }
        catch (\Throwable $e) { return ['ok'=>false, 'transaction_id'=>$transactionId, 'message'=>$e->getMessage()]; }
    }

    private function rollbackGenerationLocked(int $siteId, string $transactionId): array
    {
        try {
            BuildPlanner::assertTransactionId($transactionId);
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $root = rtrim($conn->tftpRoot(), '/');
            $txRoot = $root . '/.ucmprovisioner/transactions/' . $transactionId;
            $receipt = $this->readRemoteJson($sftp, $txRoot . '/receipt.json');
            if ((int)($receipt['site_id'] ?? 0) !== $siteId || ($receipt['transaction_id'] ?? '') !== $transactionId) {
                throw new \RuntimeException('Generation recovery receipt identity mismatch');
            }
            if (($receipt['state'] ?? '') === 'rolled_back') {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'transaction_id' => $transactionId,
                    'message' => 'Generation was already rolled back',
                ];
            }
            return $this->rollbackRemoteReceipt($sftp, $root, $txRoot, $receipt);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function currentGeneration(int $siteId): string
    {
        try {
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $root = rtrim($conn->tftpRoot(), '/');
            $pointer = $this->readRemoteJson(
                $sftp,
                $root . '/.ucmprovisioner/current.json',
                false
            );
            $generation = trim((string)($pointer['generation_id'] ?? ''));
            if ($generation !== '') {
                BuildPlanner::assertTransactionId($generation);
            }
            return $generation;
        } catch (\Throwable $e) {
            // No pointer exists on pre-transaction installations. Each target
            // file is still backed up before its first generation commit.
            return '';
        }
    }

    /** Resume an interrupted asset-copy recovery using retained verified copies. */
    public function rollbackAssetCopy(int $siteId, string $transactionId): array
    {
        try {
            return $this->withSiteLock($siteId, function () use ($siteId, $transactionId): array {
                BuildPlanner::assertTransactionId($transactionId);
                $conn = $this->ucm->siteConnection($siteId);
                $sftp = $conn->dockerSftp();
                $root = rtrim($conn->tftpRoot(), '/');
                $txRoot = $root . '/.ucmprovisioner/copies/' . $transactionId;
                $receipt = $this->readRemoteJson($sftp, $txRoot . '/receipt.json');
                if (($receipt['kind'] ?? '') !== 'asset_copy' || ($receipt['transaction_id'] ?? '') !== $transactionId) {
                    throw new \RuntimeException('Asset-copy recovery identity mismatch');
                }
                return $this->rollbackRemoteReceipt($sftp, $root, $txRoot, $receipt);
            });
        } catch (\Throwable $error) { return ['ok'=>false, 'message'=>$error->getMessage()]; }
    }

    /**
     * Move obsolete stack-keyed Cisco companion files out of the TFTP root.
     *
     * This is intentionally called only after a committed Rebuild All.  It
     * protects references from the current and immediately prior generation,
     * every live SEP config (including manual/bulk-excluded phones), and the
     * just-committed transaction's SEP backups.  Only the two exact generated
     * filename families are eligible; SEP configs and human-named XML files can
     * never match.
     *
     * @return array<string,mixed>
     */
    public function retireObsoleteCiscoCompanions(int $siteId, string $transactionId): array
    {
        try { return $this->withSiteLock($siteId, fn() => $this->retireObsoleteCiscoCompanionsLocked($siteId, $transactionId)); }
        catch (\Throwable $e) { return ['ok'=>false, 'message'=>$e->getMessage()]; }
    }

    private function retireObsoleteCiscoCompanionsLocked(int $siteId, string $transactionId): array
    {
        $retired=[];$pending=null;$receiptPath='';$attempted=false;
        try {
            BuildPlanner::assertTransactionId($transactionId);
            $conn = $this->ucm->siteConnection($siteId);
            $sftp = $conn->dockerSftp();
            $root = rtrim($conn->tftpRoot(), '/');
            $txRoot = $root . '/.ucmprovisioner/transactions/' . $transactionId;
            $pointer = $this->readRemoteJson($sftp, $root . '/.ucmprovisioner/current.json');
            if (!hash_equals($transactionId, (string)($pointer['generation_id'] ?? ''))) {
                throw new \RuntimeException('Cisco companion cleanup requires the active committed generation');
            }
            $manifest = $this->readRemoteJson($sftp, $txRoot . '/manifest.json');
            $receiptPath = $txRoot . '/receipt.json';
            $receipt = $this->readRemoteJson($sftp, $receiptPath);
            if (!hash_equals($transactionId, (string)($manifest['transaction_id'] ?? ''))
                || !hash_equals($transactionId, (string)($receipt['transaction_id'] ?? ''))
                || (string)($receipt['state'] ?? '') !== 'committed') {
                throw new \RuntimeException('Cisco companion cleanup requires a verified committed receipt');
            }

            $protected = [];
            $protectManifest = static function (array $record) use (&$protected): void {
                foreach ((array)($record['files'] ?? []) as $file) {
                    $name = basename((string)($file['filename'] ?? ''));
                    if (self::isGeneratedCiscoCompanion($name)) {
                        $protected[$name] = true;
                    }
                }
            };
            $protectManifest($manifest);

            $previousId = trim((string)($receipt['previous_pointer']['generation_id'] ?? ''));
            if ($previousId !== '') {
                BuildPlanner::assertTransactionId($previousId);
                $previous = $this->readRemoteJson(
                    $sftp,
                    $root . '/.ucmprovisioner/transactions/' . $previousId . '/manifest.json'
                );
                if (!hash_equals($previousId, (string)($previous['transaction_id'] ?? ''))) {
                    throw new \RuntimeException('Previous generation manifest identity mismatch');
                }
                $protectManifest($previous);
            }

            $rootRows = $sftp->rawlist($root);
            if (!is_array($rootRows)) {
                throw new \RuntimeException('Could not inspect the TFTP root for Cisco companion cleanup');
            }
            foreach (array_keys($rootRows) as $name) {
                $name = (string)$name;
                if (!preg_match('/\ASEP[0-9A-F]{12}\.cnf\.xml\z/Di', $name)) {
                    continue;
                }
                $this->protectCiscoCompanionReferences(
                    $sftp,
                    $root . '/' . $name,
                    $protected
                );
            }
            foreach ((array)($receipt['files'] ?? []) as $file) {
                $name = (string)($file['filename'] ?? '');
                if (empty($file['backup_moved'])
                    || !preg_match('/\ASEP[0-9A-F]{12}\.cnf\.xml\z/Di', $name)) {
                    continue;
                }
                $this->protectCiscoCompanionReferences(
                    $sftp,
                    $txRoot . '/backup/' . $name,
                    $protected
                );
            }

            $retire = [];
            foreach (array_keys($rootRows) as $name) {
                $name = (string)$name;
                if (self::isGeneratedCiscoCompanion($name) && empty($protected[$name])) {
                    $retire[] = $name;
                }
            }
            sort($retire, SORT_STRING);
            $retired = [];
            $errors = [];
            if ($retire) {
                $retiredRoot = $txRoot . '/retired';
                $this->ensureRemoteDirectory($sftp, $retiredRoot);
                foreach ($retire as $name) {
                    $stat=$sftp->lstat($root.'/'.$name);
                    if (!is_array($stat)||(int)($stat['type']??0)!==1) { throw new \RuntimeException('Obsolete companion is not a regular file'); }
                    $hash=$this->retirementPayloadHash($sftp,$root.'/'.$name,(int)$stat['size']);
                    $pending=['source'=>$root.'/'.$name,'destination'=>$retiredRoot.'/'.$name,'sha256'=>$hash,'size'=>(int)$stat['size']];
                    $receipt['companion_gc']=['state'=>'in_progress','retired'=>$retired,'pending'=>$pending];
                    $this->writeRemoteJson($sftp,$receiptPath,$receipt);
                    $attempted=true;
                    if (!$sftp->rename($pending['source'],$pending['destination'])
                        ||!hash_equals($hash,$this->retirementPayloadHash($sftp,$pending['destination'],(int)$stat['size']))) {
                        throw new \RuntimeException("Obsolete companion move was not confirmed: $name");
                    }
                    $retired[] = $name;
                    $pending=null;
                    $receipt['companion_gc']=['state'=>'in_progress','retired'=>$retired,'pending'=>null];
                    $this->writeRemoteJson($sftp,$receiptPath,$receipt);
                }
            }
            $receipt['companion_gc'] = [
                'state' => $errors ? 'partial' : 'committed',
                'retired_at' => time(),
                'retired' => $retired,
                'protected' => array_values(array_intersect(
                    array_keys($protected),
                    array_values(array_map('strval', array_keys($rootRows)))
                )),
                'errors' => $errors,
            ];
            sort($receipt['companion_gc']['protected'], SORT_STRING);
            $this->writeRemoteJson($sftp, $receiptPath, $receipt);

            return [
                'ok' => !$errors,
                'retired' => $retired,
                'retained' => $receipt['companion_gc']['protected'],
                'errors' => $errors,
                'message' => $errors
                    ? 'Some obsolete Cisco companions could not be retired; active and rollback references were preserved'
                    : sprintf(
                        'Retired %d obsolete Cisco companion file(s); current and immediately prior references were retained',
                        count($retired)
                    ),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'retired' => $retired,
                'pending' => $pending,
                'status' => $attempted?'unknown':'failed',
                'recovery_required' => $attempted,
                'receipt' => $receiptPath,
                'message' => $e->getMessage(),
            ];
        }
    }

    private static function isGeneratedCiscoCompanion(string $name): bool
    {
        return AssetFilename::isCompanion($name);
    }

    /** @param object $sftp @param array<string,bool> $protected */
    private function protectCiscoCompanionReferences($sftp, string $path, array &$protected): void
    {
        $read = $this->readBounded($sftp, $path, self::MAX_TEXT_READ_BYTES, 'Cisco companion references');
        if (empty($read['ok'])) {
            throw new \RuntimeException("Could not verify Cisco companion references in $path");
        }
        $xml = (string)$read['content'];
        if (!preg_match_all(
            '/<(?:dialTemplate|softKeyFile|featurePolicyFile)>\s*([^<]{1,128})\s*<\/(?:dialTemplate|softKeyFile|featurePolicyFile)>/i',
            $xml,
            $matches
        )) {
            return;
        }
        foreach ((array)($matches[1] ?? []) as $reference) {
            $name = basename(trim((string)$reference));
            if (self::isGeneratedCiscoCompanion($name)) {
                $protected[$name] = true;
            }
        }
    }

    /** Preserve human labels when compact payload names are copied or indexes rebuilt. */
    private function ringtoneLabels($sftp, string $root): array
    {
        $labels = [];
        foreach (['Ringlist.xml', 'Ringlist-wb.xml'] as $index) {
            $path = $root . '/' . $index;
            if (!$sftp->file_exists($path)) { continue; }
            $read = $this->readBounded($sftp, $path, 65536, 'Ringtone labels');
            $xml = (string)($read['content'] ?? '');
            if (empty($read['ok']) || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) { continue; }
            $previous = libxml_use_internal_errors(true);
            try {
                $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
                if ($doc === false || $doc->getName() !== 'CiscoIPPhoneRingList') { continue; }
                foreach ($doc->Ring as $ring) {
                    $file = (string)$ring->FileName;
                    $label = trim((string)$ring->DisplayName);
                    if (preg_match('/^[A-Za-z0-9_.-]{1,25}\.(?:raw|rwb)$/iD', $file) && $label !== '') {
                        $labels[$file] = mb_substr($label, 0, 25, 'UTF-8');
                    }
                }
            } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        }
        return $labels;
    }

    /** Rebuild Cisco standard or wideband Ringlist from validated root-level raw files. */
    private function regenerateRingList(int $siteId, $sftp, string $root, bool $wideband, array $incomingLabels = []): array
    {
        $ext = $wideband ? 'rwb' : 'raw';
        $filename = $wideband ? 'Ringlist-wb.xml' : 'Ringlist.xml';
        $entries = $sftp->nlist($root);
        if ($entries === false) { return ['ok'=>false, 'message'=>'Cannot list TFTP root']; }
        $files = array_values(array_filter($entries, static function($name) use ($ext) {
            return preg_match('/^[A-Za-z0-9_.-]{1,25}\.' . $ext . '$/i', (string)$name) === 1;
        }));
        natcasesort($files); $files = array_slice(array_values($files), 0, 50);
        $labels = $this->ringtoneLabels($sftp, $root);
        try {
            foreach ((new ManagedAssetRegistry($this->ucm->getDb()))->list($siteId, ['kind'=>'ringtone', 'limit'=>1000]) as $asset) {
                $label = trim((string)($asset['metadata']['display_name'] ?? ''));
                if ($label !== '') { $labels[(string)$asset['filename']] = $label; }
            }
        } catch (\Throwable $ignored) { /* Older inventories retain their filename-derived labels. */ }
        $labels = array_replace($labels, $incomingLabels);
        $doc = new \DOMDocument('1.0', 'UTF-8'); $doc->formatOutput = true;
        $list = $doc->createElement('CiscoIPPhoneRingList'); $doc->appendChild($list);
        foreach ($files as $file) {
            $ring = $doc->createElement('Ring');
            $display = $labels[$file] ?? ucwords(str_replace(['-','_'], ' ', pathinfo($file, PATHINFO_FILENAME)));
            $displayNode = $doc->createElement('DisplayName');
            $displayNode->appendChild($doc->createTextNode(mb_substr($display, 0, 25, 'UTF-8')));
            $ring->appendChild($displayNode);
            $ring->appendChild($doc->createElement('FileName', $file));
            $list->appendChild($ring);
        }
        $xml = (string)$doc->saveXML();
        $ok = $sftp->put(rtrim($root,'/') . '/' . $filename, $xml);
        if ($ok) { $sftp->chmod(0664, rtrim($root,'/') . '/' . $filename); }
        $registered = $ok
            ? $this->registerManagedPayload(
                $siteId,
                $filename,
                hash('sha256', $xml),
                strlen($xml),
                [
                    'validation_status'=>'verified',
                    'provenance'=>'generated',
                    'signature_status'=>'not_applicable',
                    'metadata'=>['format'=>'xml', 'magic'=>'generated XML'],
                ]
            )
            : [];
        return [
            'ok'=>(bool)$ok,
            'count'=>count($files),
            'asset'=>$registered['asset'] ?? null,
            'registry_warning'=>$registered['warning'] ?? null,
            'message'=>$ok?"Generated $filename with ".count($files).' tone(s)':"Could not write $filename",
        ];
    }

    /** Synchronize only explicitly selected Cisco ringtone payloads, then publish their indexes. */
    private function syncSelectedRingtones(int $sourceSiteId, array $request): array
    {
        if ((string)($request['scope'] ?? '') !== 'ringtones') {
            throw new \InvalidArgumentException('Selected ringtone files require the ringtones scope');
        }
        $rawNames = $request['names'];
        if (is_string($rawNames)) {
            $decoded = json_decode($rawNames, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Selected ringtone filenames must be a JSON list or form array');
            }
            $rawNames = $decoded;
        }
        if (!is_array($rawNames) || !$rawNames || count($rawNames) > 200) {
            throw new \InvalidArgumentException('Choose between one and two hundred ringtone files');
        }
        $names = [];
        foreach ($rawNames as $rawName) {
            if (!is_scalar($rawName)) {
                throw new \InvalidArgumentException('A selected ringtone filename is invalid');
            }
            $name = (string)$rawName;
            if (basename($name) !== $name || preg_match('/\A[A-Za-z0-9_.-]{1,25}\.(?:raw|rwb)\z/iD', $name) !== 1) {
                throw new \InvalidArgumentException("Unsupported Cisco ringtone filename: $name");
            }
            $names[] = $name;
        }
        $names = array_values(array_unique($names));

        $source = $this->ucm->siteConnection($sourceSiteId);
        $sftp = $source->dockerSftp();
        $root = rtrim($source->tftpRoot(), '/');
        $listed = $sftp->nlist($root);
        if ($listed === false) {
            throw new \RuntimeException('Cannot inspect the source TFTP ringtone library');
        }
        $available = array_fill_keys(array_map('strval', (array)$listed), true);
        $unknown = array_values(array_filter($names, static fn(string $name): bool => !isset($available[$name])));
        if ($unknown) {
            throw new \InvalidArgumentException('Unknown ringtone filename(s): ' . implode(', ', $unknown));
        }

        $indexes = [];
        $labels = array_intersect_key($this->ringtoneLabels($sftp, $root), array_flip($names));
        foreach ($names as $name) {
            $indexes[str_ends_with(strtolower($name), '.rwb') ? 'Ringlist-wb.xml' : 'Ringlist.xml'] =
                str_ends_with(strtolower($name), '.rwb');
        }

        $targets = (array)($request['target_site_ids'] ?? []);
        $overwrite = !empty($request['overwrite']);
        $result = $this->copyPaths($sourceSiteId, $targets, $names, $overwrite);
        $result['names'] = $names;
        $result['index_names'] = array_keys($indexes);
        foreach ($result['sites'] as $targetId=>&$siteResult) {
            if (empty($siteResult['ok'])) { continue; }
            try {
                if (method_exists($this->ucm, 'replicationTransfer')) {
                    $activation = $this->ucm->replicationTransfer((int)$targetId,
                        ['op'=>'indexes','ringtones'=>array_values($indexes),'ringtone_labels'=>$labels],
                        $siteResult['transport'] ?? $this->ucm->replicationTransport((int)$targetId));
                    if (empty($activation['ok'])) { throw new \RuntimeException('Destination ringtone indexes failed'); }
                    $siteResult['index_activation'] = $activation['indexes'];
                    $siteResult['copied'] += count($indexes);
                    continue;
                }
                $target = $this->ucm->siteConnection((int)$targetId);
                $targetSftp = $target->dockerSftp();
                $targetRoot = rtrim($target->tftpRoot(), '/');
                $siteResult['index_activation'] = [];
                foreach ($indexes as $filename=>$wideband) {
                    $activation = $this->regenerateRingList((int)$targetId, $targetSftp, $targetRoot, $wideband, $labels);
                    $siteResult['index_activation'][$filename] = $activation;
                    if (empty($activation['ok'])) {
                        throw new \RuntimeException((string)($activation['message'] ?? "Could not rebuild $filename"));
                    }
                    $siteResult['copied']++;
                    if (isset($activation['asset'])) { $siteResult['assets'][] = $activation['asset']; }
                    if (!empty($activation['registry_warning'])) {
                        $siteResult['registry_warnings'][] = $activation['registry_warning'];
                    }
                }
                $siteResult['registry_warnings'] = array_values(array_unique($siteResult['registry_warnings']));
            } catch (\Throwable $error) {
                $siteResult['ok'] = false;
                $siteResult['message'] .= '; ringtone index activation failed: ' . $error->getMessage();
            }
        }
        unset($siteResult);
        $result['ok'] = !array_filter($result['sites'], static fn(array $site): bool => empty($site['ok']));
        $result['message'] = $result['ok']
            ? 'Synchronized ' . count($names) . ' selected ringtone(s) and published their Cisco indexes last'
            : 'One or more target sites failed selected ringtone synchronization';
        return $result;
    }

    /** Synchronize only explicitly selected, currently complete wallpaper catalogs. */
    private function syncSelectedWallpaperSets(int $sourceSiteId, array $request): array
    {
        $scope = (string)($request['scope'] ?? 'wallpapers');
        if ($scope !== 'wallpapers') {
            throw new \InvalidArgumentException('Selected wallpaper catalogs require the wallpapers scope');
        }

        $rawIds = $request['set_ids'];
        if (is_string($rawIds)) {
            $decoded = json_decode($rawIds, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Selected wallpaper catalog IDs must be a JSON list or form array');
            }
            $rawIds = $decoded;
        }
        if (!is_array($rawIds) || !$rawIds || count($rawIds) > 50) {
            throw new \InvalidArgumentException('Choose between one and fifty wallpaper catalogs');
        }
        $setIds = [];
        foreach ($rawIds as $rawId) {
            if (!is_scalar($rawId)) { throw new \InvalidArgumentException('A selected wallpaper catalog ID is invalid'); }
            $setIds[] = self::wallpaperSetId((string)$rawId);
        }
        $setIds = array_values(array_unique($setIds));

        $targetIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($request['target_site_ids'] ?? [])),
            static fn(int $id): bool => $id > 0 && $id !== $sourceSiteId
        )));
        if (!$targetIds || count($targetIds) > 20) {
            throw new \InvalidArgumentException('Choose between one and twenty different target sites');
        }

        $source = $this->ucm->siteConnection($sourceSiteId);
        $sftp = $source->dockerSftp();
        $root = rtrim($source->tftpRoot(), '/');
        $listed = $this->wallpaperSetList($sourceSiteId, $sftp, $root);
        if (empty($listed['ok'])) {
            throw new \RuntimeException((string)($listed['message'] ?? 'Cannot inspect managed wallpaper catalogs'));
        }
        $available = [];
        foreach ((array)($listed['sets'] ?? []) as $set) { $available[(string)$set['id']] = $set; }
        $unknown = array_values(array_diff($setIds, array_keys($available)));
        if ($unknown) {
            throw new \InvalidArgumentException('Unknown wallpaper catalog ID(s): ' . implode(', ', $unknown));
        }
        $incomplete = array_values(array_filter(
            $setIds,
            static fn(string $id): bool => empty($available[$id]['complete'])
        ));
        if ($incomplete) {
            throw new \RuntimeException('Incomplete wallpaper catalog(s) cannot be synchronized: ' . implode(', ', $incomplete));
        }

        $payloadPaths = [];
        $manifestPaths = [];
        $catalogDirs = [];
        $hostedLibraries = [];
        foreach ($setIds as $setId) {
            $manifest = $this->readWallpaperSetManifest($sftp, $root, $setId);
            $base = self::WALLPAPER_SET_ROOT . '/' . $setId;
            $payloadPaths[$base . '/' . (string)$manifest['source']] = true;
            $manifestPaths[$base . '/manifest.json'] = true;
            foreach ((array)$manifest['variants'] as $variant) {
                $payloadPaths[$base . '/' . (string)$variant['source']] = true;
                if (empty($variant['enabled'])) { continue; }
                if (($variant['delivery'] ?? '') === 'cisco_list') {
                    $relative = 'Desktops/' . (string)$variant['dir'] . '/';
                    $payloadPaths[$relative . (string)$variant['full']] = true;
                    if ((string)$variant['thumbnail'] !== '') {
                        $payloadPaths[$relative . (string)$variant['thumbnail']] = true;
                    }
                    $catalogDirs[(string)$variant['dir']] = true;
                    continue;
                }
                foreach ((array)$variant['models'] as $model) {
                    $key = (string)$variant['family'] . '|' . strtolower((string)$model);
                    $hostedLibraries[$key] ??= [
                        'family'=>(string)$variant['family'],
                        'model'=>strtolower((string)$model),
                        'required'=>[],
                        'optional'=>[],
                    ];
                    $hostedLibraries[$key]['required'][(string)$variant['full']] = true;
                }
            }
            // Renames intentionally retain old generated filenames as compatibility
            // aliases. Copy aliases that still exist, without making a disabled
            // profile's deliberately absent alias a catalog-completeness failure.
            foreach ((array)($manifest['aliases'] ?? []) as $alias) {
                $profile = ModelCatalog::wallpaperProfileByKey((string)$alias['profile']);
                if ($profile === null) { continue; }
                if ($profile['delivery'] === 'cisco_list') {
                    $relative = 'Desktops/' . (string)$alias['dir'] . '/';
                    foreach ([(string)$alias['full'], (string)$alias['thumbnail']] as $filename) {
                        if ($filename !== '' && $this->remoteExists($sftp, $root . '/' . $relative . $filename)) {
                            $payloadPaths[$relative . $filename] = true;
                            $catalogDirs[(string)$alias['dir']] = true;
                        }
                    }
                    continue;
                }
                foreach ((array)$alias['models'] as $model) {
                    $key = (string)$profile['family'] . '|' . strtolower((string)$model);
                    $hostedLibraries[$key] ??= [
                        'family'=>(string)$profile['family'],
                        'model'=>strtolower((string)$model),
                        'required'=>[],
                        'optional'=>[],
                    ];
                    $hostedLibraries[$key]['optional'][(string)$alias['full']] = true;
                }
            }
        }

        foreach (array_keys($payloadPaths) as $relative) {
            if (!$this->remoteExists($sftp, $root . '/' . $relative)) {
                throw new \RuntimeException("Selected wallpaper catalog file is missing: $relative");
            }
        }
        foreach ($hostedLibraries as &$library) {
            $sourceItems = $this->ucm->mediaAssetOp([
                'op'=>'list', 'site_id'=>$sourceSiteId,
                'family'=>$library['family'], 'model'=>$library['model'],
            ]);
            if (empty($sourceItems['ok'])) {
                throw new \RuntimeException((string)($sourceItems['message'] ?? 'Cannot inspect a hosted wallpaper library'));
            }
            $names = array_fill_keys(array_map(
                static fn(array $item): string => (string)($item['name'] ?? ''),
                (array)($sourceItems['items'] ?? [])
            ), true);
            $missing = array_values(array_diff(array_keys($library['required']), array_keys($names)));
            if ($missing) {
                throw new \RuntimeException('Selected hosted wallpaper output is missing: ' . implode(', ', $missing));
            }
            $library['names'] = array_values(array_unique(array_merge(
                array_keys($library['required']),
                array_values(array_intersect(array_keys($library['optional']), array_keys($names)))
            )));
        }
        unset($library);

        // Generated payloads and hosted libraries are copied first. Manifests are
        // activated only on targets where every prerequisite succeeded; Cisco's
        // phone-facing List.xml is rebuilt last from the target's actual files.
        $result = $this->copyPaths(
            $sourceSiteId,
            $targetIds,
            array_keys($payloadPaths),
            !empty($request['overwrite'])
        );
        $result['set_ids'] = $setIds;

        foreach ($hostedLibraries as $library) {
            $eligible = array_values(array_filter(
                $targetIds,
                static fn(int $id): bool => !empty($result['sites'][(string)$id]['ok'])
            ));
            if (!$eligible) { break; }
            try {
                $hosted = $this->ucm->mediaAssetOp([
                    'op'=>'sync', 'site_id'=>$sourceSiteId,
                    'family'=>$library['family'], 'model'=>$library['model'],
                    'names'=>$library['names'], 'target_site_ids'=>$eligible,
                    'overwrite'=>!empty($request['overwrite']),
                ]);
            } catch (\Throwable $error) {
                $hosted = ReplicationActivityService::failedOperation($error, $eligible, 'Hosted wallpapers');
            }
            foreach ($eligible as $targetId) {
                $key = (string)$targetId;
                $siteResult = (array)($hosted['sites'][$key] ?? $hosted['sites'][$targetId] ?? []);
                if (!$siteResult) {
                    $siteResult = ['ok'=>false, 'status'=>'unknown', 'message'=>(string)($hosted['message'] ?? 'Hosted wallpaper synchronization result is missing')];
                }
                $result['sites'][$key]['hosted_media'][] = $siteResult;
                if (empty($siteResult['ok'])) {
                    $result['sites'][$key]['ok'] = false;
                    $result['sites'][$key]['message'] .= '; ' . (string)($siteResult['message'] ?? 'hosted wallpaper synchronization failed');
                }
            }
        }

        $eligible = array_values(array_filter(
            $targetIds,
            static fn(int $id): bool => !empty($result['sites'][(string)$id]['ok'])
        ));
        if ($eligible) {
            try {
                $activation = $this->copyPaths(
                    $sourceSiteId,
                    $eligible,
                    array_keys($manifestPaths),
                    !empty($request['overwrite'])
                );
            } catch (\Throwable $error) {
                $activation = ReplicationActivityService::failedOperation($error, $eligible, 'Wallpaper manifest activation');
            }
            $result['source_assets'] = array_merge(
                (array)($result['source_assets'] ?? []),
                (array)($activation['source_assets'] ?? [])
            );
            $result['source_registry_warnings'] = array_values(array_unique(array_merge(
                (array)($result['source_registry_warnings'] ?? []),
                (array)($activation['source_registry_warnings'] ?? [])
            )));
            foreach ($eligible as $targetId) {
                $key = (string)$targetId;
                $siteActivation = (array)($activation['sites'][$key] ?? $activation['sites'][$targetId] ?? []);
                $result['sites'][$key]['activation'] = $siteActivation;
                $result['sites'][$key]['copied'] += (int)($siteActivation['copied'] ?? 0);
                $result['sites'][$key]['skipped'] += (int)($siteActivation['skipped'] ?? 0);
                $result['sites'][$key]['assets'] = array_merge(
                    (array)($result['sites'][$key]['assets'] ?? []),
                    (array)($siteActivation['assets'] ?? [])
                );
                $result['sites'][$key]['registry_warnings'] = array_values(array_unique(array_merge(
                    (array)($result['sites'][$key]['registry_warnings'] ?? []),
                    (array)($siteActivation['registry_warnings'] ?? [])
                )));
                if (empty($siteActivation['ok'])) {
                    $result['sites'][$key]['ok'] = false;
                    $result['sites'][$key]['message'] .= '; manifest activation failed: '
                        . (string)($siteActivation['message'] ?? 'unknown error');
                }
            }
        }

        foreach ($result['sites'] as $targetId=>&$siteResult) {
            if (empty($siteResult['ok'])) { continue; }
            try {
                if (method_exists($this->ucm, 'replicationTransfer')) {
                    $catalogs = $this->ucm->replicationTransfer((int)$targetId,
                        ['op'=>'indexes','directories'=>array_keys($catalogDirs)],
                        $siteResult['transport'] ?? $this->ucm->replicationTransport((int)$targetId));
                    if (empty($catalogs['ok'])) { throw new \RuntimeException('Destination wallpaper indexes failed'); }
                    $siteResult['catalogs'] = $catalogs['indexes'];
                    continue;
                }
                $target = $this->ucm->siteConnection((int)$targetId);
                $targetSftp = $target->dockerSftp();
                $targetRoot = rtrim($target->tftpRoot(), '/');
                $siteResult['catalogs'] = [];
                foreach (array_keys($catalogDirs) as $dir) {
                    $catalog = $this->regenerateImageList(
                        (int)$targetId,
                        $targetSftp,
                        $targetRoot . '/Desktops/' . $dir,
                        'Desktops/' . $dir
                    );
                    $siteResult['catalogs'][$dir] = $catalog;
                    if (empty($catalog['ok'])) {
                        throw new \RuntimeException((string)($catalog['message'] ?? "Could not rebuild $dir/List.xml"));
                    }
                }
            } catch (\Throwable $error) {
                $siteResult['ok'] = false;
                $siteResult['message'] .= '; vendor catalog rebuild failed: ' . $error->getMessage();
            }
        }
        unset($siteResult);
        $result['ok'] = !array_filter($result['sites'], static fn(array $row): bool => empty($row['ok']));
        $result['message'] = $result['ok']
            ? 'Synchronized ' . count($setIds) . ' selected wallpaper catalog(s) to ' . count($result['sites']) . ' site(s)'
            : 'One or more target sites failed selected wallpaper catalog synchronization';
        return $result;
    }

    /** Copy a server-selected asset set between site TFTP volumes without loading large firmware into PHP memory. */
    private function copyPaths(int $sourceSiteId, array $targetSiteIds, array $paths, bool $overwrite, bool $locked = false): array
    {
        if (method_exists($this->ucm, 'replicateTftpPaths')) {
            return $this->ucm->replicateTftpPaths($sourceSiteId, $targetSiteIds, $paths, $overwrite);
        }
        $targetSiteIds = array_values(array_unique(array_filter(array_map('intval', $targetSiteIds), static fn(int $id): bool => $id > 0 && $id !== $sourceSiteId)));
        if (!$targetSiteIds || count($targetSiteIds) > 20) { throw new \InvalidArgumentException('Choose between one and twenty different target sites'); }
        if (!$locked) {
            $combined = ['ok'=>true, 'sites'=>[], 'source_assets'=>[], 'source_registry_warnings'=>[]];
            foreach ($targetSiteIds as $id) {
                try { $part = $this->withSiteLocks([$sourceSiteId, $id], fn() => $this->copyPaths($sourceSiteId, [$id], $paths, $overwrite, true)); }
                catch (\Throwable $e) { $part = ['ok'=>false, 'sites'=>[(string)$id=>['ok'=>false, 'message'=>$e->getMessage()]]]; }
                $combined['ok'] = $combined['ok'] && !empty($part['ok']);
                foreach (['sites','source_assets','source_registry_warnings'] as $field) {
                    $combined[$field] = array_replace($combined[$field], (array)($part[$field] ?? []));
                }
            }
            $combined['message'] = $combined['ok'] ? 'Assets synchronized' : 'One or more target sites failed';
            return $combined;
        }
        $source = $this->ucm->siteConnection($sourceSiteId); $sourceSftp = $source->dockerSftp(); $sourceRoot = rtrim($source->tftpRoot(), '/');
        $safePaths = [];
        foreach ($paths as $path) { $path = $this->sanitizeRel((string)$path); if ($path !== '') { $safePaths[] = $path; } }
        if (!$safePaths) { throw new \InvalidArgumentException('No files were selected for synchronization'); }
        $result = ['ok'=>true,'message'=>'Asset synchronization complete','source_assets'=>[],'source_registry_warnings'=>[],'sites'=>[]];
        $sourceRegistered = [];
        foreach ($targetSiteIds as $targetSiteId) {
            $copied = 0; $skipped = 0; $errors = []; $assets = []; $registryWarnings = []; $registrations = [];
            $copyReceipt = null;
            try {
                $target = $this->ucm->siteConnection($targetSiteId); $targetSftp = $target->dockerSftp(); $targetRoot = rtrim($target->tftpRoot(), '/');
                $copyId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
                $copyRoot = $targetRoot . '/.ucmprovisioner/copies/' . $copyId;
                $copyReceipt = ['transaction_id'=>$copyId, 'kind'=>'asset_copy', 'state'=>'staging', 'files'=>[]];
                $this->ensureRemoteDirectory($targetSftp, $copyRoot . '/stage');
                $this->ensureRemoteDirectory($targetSftp, $copyRoot . '/backup');
                foreach ($safePaths as $path) {
                    $sourcePath = $sourceRoot . '/' . $path; $targetPath = $targetRoot . '/' . $path;
                    if ((!$overwrite || AssetFilename::isImmutable(basename($path))) && $targetSftp->file_exists($targetPath)) {
                        $match = $this->remoteFileMatchInfo($sourceSftp, $sourcePath, $targetSftp, $targetPath, $path);
                        if (empty($match['match'])) {
                            if (AssetFilename::isImmutable(basename($path))) { throw new \RuntimeException("Compact asset filename conflict: $path; existing content was preserved"); }
                            throw new \RuntimeException("Target file differs from source for $path; enable overwrite to replace it");
                        }
                        if (!str_starts_with($path, '.ucmprovisioner/')) {
                            $registrations[] = ['path'=>$path,'sha256'=>(string)$match['sha256'],'size'=>(int)$match['size']];
                        }
                        if (!str_starts_with($path, '.ucmprovisioner/') && empty($sourceRegistered[$path])) {
                            $sourceRegistration = $this->registerManagedPayload($sourceSiteId, $path, (string)$match['sha256'], (int)$match['size']);
                            if (isset($sourceRegistration['asset'])) { $result['source_assets'][] = $sourceRegistration['asset']; }
                            if (isset($sourceRegistration['warning'])) { $result['source_registry_warnings'][] = $sourceRegistration['warning']; }
                            $sourceRegistered[$path] = true;
                        }
                        $skipped++;
                        continue;
                    }
                    $parent = dirname($targetPath); $cursor = $targetRoot;
                    $relativeParent = trim(substr($parent, strlen($targetRoot)), '/');
                    foreach ($relativeParent === '' ? [] : explode('/', $relativeParent) as $part) {
                        $cursor .= '/' . $part;
                        if (!$targetSftp->is_dir($cursor) && !$targetSftp->mkdir($cursor)) { throw new \RuntimeException("Cannot create $cursor"); }
                        $targetSftp->chmod($this->isPrivateTftpPath($cursor)
                            ? self::PRIVATE_DIRECTORY_MODE
                            : self::PUBLIC_DIRECTORY_MODE, $cursor);
                    }
                    $tmp = tempnam(sys_get_temp_dir(), 'ucmp-sync-');
                    if ($tmp === false) { throw new \RuntimeException('Could not allocate a temporary transfer file'); }
                    try {
                        $stagePath = $copyRoot . '/stage/' . $path;
                        $this->ensureRemoteDirectory($targetSftp, dirname($stagePath));
                        $this->ensureRemoteDirectory($targetSftp, dirname($copyRoot . '/backup/' . $path));
                        if (!$sourceSftp->get($sourcePath, $tmp) || !$targetSftp->put($stagePath, $tmp, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) { throw new \RuntimeException("Transfer failed for $path"); }
                        $contentHash = hash_file('sha256', $tmp);
                        if (!is_string($contentHash) || !hash_equals($contentHash, $this->remotePayloadHash($targetSftp, $stagePath))) {
                            throw new \RuntimeException("Staged transfer verification failed for $path");
                        }
                        $copyReceipt['files'][] = ['filename'=>$path, 'sha256'=>$contentHash,
                            'previous_sha256'=>$this->remoteExists($targetSftp, $targetPath) ? $this->remotePayloadHash($targetSftp, $targetPath) : null,
                            'intent'=>'staged'];
                        if (!is_string($contentHash)) {
                            $registryWarnings[] = "Copied $path, but could not calculate its content hash";
                        } else {
                            $payloadSize = (int)(@filesize($tmp) ?: 0);
                            if (!str_starts_with($path, '.ucmprovisioner/') && empty($sourceRegistered[$path])) {
                                $sourceRegistration = $this->registerManagedPayload($sourceSiteId, $path, $contentHash, $payloadSize);
                                if (isset($sourceRegistration['asset'])) { $result['source_assets'][] = $sourceRegistration['asset']; }
                                if (isset($sourceRegistration['warning'])) { $result['source_registry_warnings'][] = $sourceRegistration['warning']; }
                                $sourceRegistered[$path] = true;
                            }
                            if (!str_starts_with($path, '.ucmprovisioner/')) {
                                $registrations[] = ['path'=>$path,'sha256'=>$contentHash,'size'=>$payloadSize];
                            }
                        }
                    } finally { @unlink($tmp); }
                }
                $copyReceipt['state'] = 'committing';
                $this->writeRemoteJson($targetSftp, $copyRoot . '/receipt.json', $copyReceipt);
                foreach ($copyReceipt['files'] as $index=>$row) {
                    $path = $row['filename']; $targetPath = $targetRoot . '/' . $path;
                    $copyReceipt['files'][$index]['intent'] = 'activate';
                    $this->writeRemoteJson($targetSftp, $copyRoot . '/receipt.json', $copyReceipt);
                    if ($row['previous_sha256'] !== null && !$targetSftp->rename($targetPath, $copyRoot . '/backup/' . $path)) {
                        throw new \RuntimeException("Could not preserve $path");
                    }
                    if (!hash_equals($row['sha256'], $this->remotePayloadHash($targetSftp, $copyRoot . '/stage/' . $path))
                        || !$targetSftp->rename($copyRoot . '/stage/' . $path, $targetPath)) {
                        throw new \RuntimeException("Could not activate $path");
                    }
                    $targetSftp->chmod(0664, $targetPath);
                    if (!hash_equals($row['sha256'], $this->remotePayloadHash($targetSftp, $targetPath))) { throw new \RuntimeException("Activated hash mismatch for $path"); }
                    $copied++;
                }
                if ($registrations && method_exists($this->ucm, 'registerManagedTftpCopies')) {
                    $registered = (array)$this->ucm->registerManagedTftpCopies($targetSiteId, $registrations);
                    if (empty($registered['ok'])) {
                        throw new \RuntimeException('Destination inventory registration failed: ' . (string)($registered['message'] ?? 'unknown error'));
                    }
                    $assets = (array)($registered['assets'] ?? []);
                    $registryWarnings = array_merge($registryWarnings, (array)($registered['warnings'] ?? []));
                } elseif ($registrations) {
                    // Lightweight test/dry-run adapters predate the managed-registry
                    // callback. Production Ucmprovisioner always provides it.
                    $registryWarnings[] = 'Destination inventory adapter does not expose managed-file registration';
                }
                $copyReceipt['state'] = 'committed';
                $this->writeRemoteJson($targetSftp, $copyRoot . '/receipt.json', $copyReceipt);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                if ($copyReceipt !== null) {
                    try {
                        $recovery = $this->rollbackRemoteReceipt($targetSftp, $targetRoot, $copyRoot, $copyReceipt);
                        if (empty($recovery['ok'])) { $errors[] = $recovery['message']; }
                        else { $copied = 0; }
                    } catch (\Throwable $recoveryError) { $errors[] = $recoveryError->getMessage(); }
                }
            }
            $ok = !$errors; $result['sites'][(string)$targetSiteId] = [
                'ok'=>$ok,'copied'=>$copied,'skipped'=>$skipped,
                'recovery_id'=>$copyReceipt['transaction_id'] ?? null,
                'assets'=>$assets,'registry_warnings'=>array_values(array_unique($registryWarnings)),
                'message'=>$ok?"Copied $copied file(s); skipped $skipped existing override(s)":implode('; ',$errors),
            ];
            if (!$ok) { $result['ok'] = false; }
        }
        $result['source_registry_warnings'] = array_values(array_unique($result['source_registry_warnings']));
        $result['message'] = $result['ok'] ? 'Assets synchronized to ' . count($result['sites']) . ' site(s)' : 'One or more target sites failed';
        return $result;
    }

    /** Compare an existing remote target with its source without loading either payload into PHP memory. */
    private function remoteFileMatchInfo($sourceSftp, string $sourcePath, $targetSftp, string $targetPath, string $relativePath): array
    {
        $sourceTmp = tempnam(sys_get_temp_dir(), 'ucmp-source-');
        $targetTmp = tempnam(sys_get_temp_dir(), 'ucmp-target-');
        if ($sourceTmp === false || $targetTmp === false) {
            if (is_string($sourceTmp)) { @unlink($sourceTmp); }
            if (is_string($targetTmp)) { @unlink($targetTmp); }
            throw new \RuntimeException('Could not allocate temporary files to verify an existing target');
        }
        try {
            if (!$sourceSftp->get($sourcePath, $sourceTmp) || !$targetSftp->get($targetPath, $targetTmp)) {
                throw new \RuntimeException("Could not verify existing target file $relativePath");
            }
            $sourceHash = hash_file('sha256', $sourceTmp);
            $targetHash = hash_file('sha256', $targetTmp);
            if (!is_string($sourceHash) || !is_string($targetHash)) {
                throw new \RuntimeException("Could not hash existing target file $relativePath");
            }
            return [
                'match'=>hash_equals($sourceHash, $targetHash),
                'sha256'=>$sourceHash,
                'size'=>(int)(@filesize($sourceTmp) ?: 0),
            ];
        } finally {
            @unlink($sourceTmp);
            @unlink($targetTmp);
        }
    }

    /** Persist controller-verified copy metadata in the destination PBX inventory. */
    private function registerCopiedAssets(int $siteId, array $files): array
    {
        if (!$files || count($files) > 500) {
            throw new \InvalidArgumentException('Choose between one and five hundred copied files to register');
        }
        $assets = [];
        $warnings = [];
        foreach ($files as $file) {
            if (!is_array($file)) { throw new \InvalidArgumentException('Copied-file metadata is invalid'); }
            $path = $this->sanitizeRel((string)($file['path'] ?? ''));
            $sha = strtolower(trim((string)($file['sha256'] ?? '')));
            $size = max(0, (int)($file['size'] ?? 0));
            if ($path === '' || str_starts_with($path, '.ucmprovisioner/')
                || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1 || $size > self::MAX_SCAN_FILE_BYTES) {
                throw new \InvalidArgumentException('Copied-file metadata is unsafe');
            }
            $registered = $this->registerManagedPayload($siteId, $path, $sha, $size);
            if (isset($registered['asset'])) { $assets[] = $registered['asset']; }
            if (isset($registered['warning'])) { $warnings[] = $registered['warning']; }
        }
        return [
            'ok'=>true,'site_id'=>$siteId,'assets'=>$assets,
            'warnings'=>array_values(array_unique($warnings)),
            'message'=>'Registered ' . count($assets) . ' recognized copied asset(s) in the destination inventory',
        ];
    }

    /** Inspect every documented Cisco load-family locale path. */
    private function ciscoLocaleInventory(int $siteId, string $userLocale, string $networkLocale = ''): array
    {
        $userLocale = trim($userLocale) ?: 'English_United_States';
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $userLocale) !== 1) {
            throw new \InvalidArgumentException('Cisco user-locale directory may contain only letters, numbers, underscore and hyphen');
        }
        $networkLocale = trim($networkLocale);
        if ($networkLocale !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $networkLocale) !== 1) {
            throw new \InvalidArgumentException('Cisco network-locale directory may contain only letters, numbers, underscore and hyphen');
        }
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $rootEntries = $sftp->rawlist($root);
        if ($rootEntries === false) { throw new \RuntimeException('Cannot list the TFTP root'); }

        $lookup = static function (array $entries, string $wanted): ?array {
            foreach ($entries as $name=>$metadata) {
                if ($name === '.' || $name === '..') { continue; }
                if (strcasecmp((string)$name, $wanted) === 0) {
                    return ['name'=>(string)$name, 'metadata'=>(array)$metadata];
                }
            }
            return null;
        };
        $directoryEntries = [];
        $directoryNames = [];
        foreach (array_values(array_unique(array_filter([$userLocale, $networkLocale]))) as $directory) {
            $match = $lookup((array)$rootEntries, $directory);
            if ($match === null || (int)($match['metadata']['type'] ?? 0) !== 2) { continue; }
            $actual = (string)$match['name'];
            $listed = $sftp->rawlist($root . '/' . $actual);
            if (!is_array($listed)) { continue; }
            $directoryEntries[strtolower($directory)] = $listed;
            $directoryNames[strtolower($directory)] = $actual;
        }

        $profiles = [];
        foreach (ModelCatalog::CISCO_LOCALE_PROFILES as $profile=>$definition) {
            $files = [];
            foreach ((array)($definition['files'] ?? []) as $required) {
                $expectedPath = str_replace(
                    ['{user_locale}', '{tone_path}'],
                    [$userLocale, $networkLocale],
                    (string)($required['path'] ?? '')
                );
                $expectedPath = trim(preg_replace('#/+#', '/', $expectedPath) ?? $expectedPath, '/');
                $parent = str_contains($expectedPath, '/') ? dirname($expectedPath) : '';
                $expectedName = basename($expectedPath);
                $parentKey = strtolower($parent);
                $entries = $parent === '' ? (array)$rootEntries : (array)($directoryEntries[$parentKey] ?? []);
                $match = $lookup($entries, $expectedName);
                $parentExact = $parent === '' || (($directoryNames[$parentKey] ?? '') === $parent);
                $present = $match !== null && (int)($match['metadata']['type'] ?? 0) !== 2;
                $exactCase = $present && $parentExact && (string)$match['name'] === $expectedName;
                $size = $present && isset($match['metadata']['size']) ? (int)$match['metadata']['size'] : null;
                $usable = $present && $exactCase && ($size === null || $size > 0);
                $actualPath = $present
                    ? (($parent === '' ? '' : (string)($directoryNames[$parentKey] ?? $parent) . '/') . (string)$match['name'])
                    : '';
                $files[] = [
                    'path'=>$expectedPath,
                    'actual_path'=>$actualPath,
                    'role'=>(string)($required['role'] ?? 'Locale companion'),
                    'present'=>$present,
                    'exact_case'=>$exactCase,
                    'size'=>$size,
                    'usable'=>$usable,
                ];
            }
            $complete = $files !== [] && !array_filter($files, static fn(array $file): bool => empty($file['usable']));
            $profiles[$profile] = [
                'profile'=>$profile,
                'label'=>(string)($definition['label'] ?? $profile),
                'firmware_profile'=>(string)($definition['firmware_profile'] ?? ''),
                'models'=>(array)($definition['models'] ?? []),
                'user_locale'=>$userLocale,
                'network_locale'=>$networkLocale,
                'status'=>$complete ? 'complete' : 'incomplete',
                'files'=>$files,
            ];
        }
        return [
            'ok'=>true,
            'site_id'=>$siteId,
            'user_locale'=>$userLocale,
            'network_locale'=>$networkLocale,
            'status'=>!array_filter($profiles, static fn(array $profile): bool => ($profile['status'] ?? '') !== 'complete') ? 'complete' : 'partial',
            'profiles'=>$profiles,
            'message'=>'Cisco locale companion paths inspected directly on the selected TFTP root',
        ];
    }

    /** Copy one verified load-family locale bundle without guessing paths. */
    private function syncCiscoLocaleBundle(
        int $siteId,
        string $userLocale,
        string $networkLocale,
        string $profileId,
        array $targetSiteIds,
        bool $overwrite
    ): array {
        $profileId = trim($profileId) ?: 'cisco:sip41-locale';
        if (!isset(ModelCatalog::CISCO_LOCALE_PROFILES[$profileId])) {
            throw new \InvalidArgumentException('Choose a documented Cisco locale profile');
        }
        $inventory = $this->ciscoLocaleInventory($siteId, $userLocale, $networkLocale);
        $profile = (array)($inventory['profiles'][$profileId] ?? []);
        if (($profile['status'] ?? '') !== 'complete') {
            throw new \RuntimeException('The source ' . $profileId . ' locale bundle is incomplete and was not synchronized');
        }
        $paths = array_values(array_map(
            static fn(array $file): string => (string)$file['path'],
            (array)($profile['files'] ?? [])
        ));
        $result = $this->copyPaths($siteId, $targetSiteIds, $paths, $overwrite);
        $result['profile'] = $profileId;
        $result['user_locale'] = (string)$inventory['user_locale'];
        $result['network_locale'] = (string)$inventory['network_locale'];
        return $result;
    }

    /** Read and validate Cisco's canonical five-file phone timezone bundle. */
    private function timezoneInventory(int $siteId): array
    {
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $entries = $sftp->nlist($root);
        if ($entries === false) {
            throw new \RuntimeException('Cannot list the TFTP root');
        }
        return CiscoTimezoneService::inspect((array)$entries, function (string $name) use ($sftp, $root) {
            $read = $this->readBounded(
                $sftp,
                $root . '/' . $name,
                self::MAX_TIMEZONE_FILE_BYTES,
                'Cisco timezone inventory'
            );
            return !empty($read['ok']) ? (string)$read['content'] : false;
        });
    }

    /** List the active canonical set beside every immutable retained version. */
    private function timezoneVersionInventory(int $siteId): array
    {
        $current = $this->timezoneInventory($siteId);
        $manager = new CiscoTimezoneBundleManager($this->ucm->getDb());
        $bundles = $manager->list($siteId);
        $currentFingerprint = (string)($current['fingerprint'] ?? '');
        if ($currentFingerprint !== '' && !empty($current['retainable'])) {
            foreach ($bundles as $known) {
                if ((string)($known['bundle_key'] ?? '') !== $currentFingerprint
                    || (string)($known['status'] ?? '') === 'quarantined') {
                    continue;
                }
                // The inspector evolves as additional Cisco payload formats are
                // understood. Refresh evidence for identical bytes without
                // changing the retained ID, active flag, or archive contents.
                $manager->store(
                    $siteId,
                    $current,
                    (array)($known['files'] ?? []),
                    (string)($known['storage_path'] ?? '')
                );
                $bundles = $manager->list($siteId);
                break;
            }
        }
        $detectedId = null;
        foreach ($bundles as &$bundle) {
            $bundle['detected_active'] = (string)($current['fingerprint'] ?? '') !== ''
                && hash_equals((string)$bundle['bundle_key'], (string)$current['fingerprint']);
            if ($bundle['detected_active']) { $detectedId = (int)$bundle['id']; }
        }
        unset($bundle);
        $recordedActiveIds = array_values(array_map(
            static fn(array $bundle): int => (int)$bundle['id'],
            array_filter($bundles, static fn(array $bundle): bool => !empty($bundle['active']))
        ));
        $assets = $manager->assets($siteId);
        $assets = $this->ensureTimezoneAssetAliases($siteId, $assets);
        $canonicalHashes = [];
        foreach ((array)($current['files'] ?? []) as $file) {
            $name = (string)($file['name'] ?? '');
            $sha256 = strtolower((string)($file['sha256'] ?? ''));
            if ($name !== '' && preg_match('/^[a-f0-9]{64}$/D', $sha256) === 1) { $canonicalHashes[$name] = $sha256; }
        }
        foreach ($assets as &$asset) {
            $asset['canonical_active'] = isset($canonicalHashes[(string)$asset['payload']])
                && hash_equals($canonicalHashes[(string)$asset['payload']], (string)$asset['sha256']);
            $asset['selectable'] = !empty($asset['published']);
        }
        unset($asset);
        return [
            'ok'=>true,
            'site_id'=>$siteId,
            'current'=>$current,
            'bundles'=>$bundles,
            'assets'=>$assets,
            'profiles'=>ModelCatalog::CISCO_TIMEZONE_PROFILES,
            'detected_active_bundle_id'=>$detectedId,
            'active_state_consistent'=>$detectedId === null
                ? $recordedActiveIds === []
                : $recordedActiveIds === [$detectedId],
        ];
    }

    /** Copy one retained version to target libraries without activating it. */
    private function syncTimezoneVersion(int $siteId, int $bundleId, array $targetSiteIds): array
    {
        $inventory = $this->ucm->tftpOp(['op'=>'timezone_versions','site_id'=>$siteId]);
        $bundle = null;
        foreach ((array)($inventory['bundles'] ?? []) as $candidate) {
            if ((int)($candidate['id'] ?? 0) === $bundleId) { $bundle = $candidate; break; }
        }
        if (!$bundle) { throw new \InvalidArgumentException('Choose a retained timezone version from the source site'); }
        $storage = trim((string)($bundle['storage_path'] ?? ''), '/');
        if (preg_match('#^\.ucmprovisioner/timezone-bundles/[a-f0-9]{64}$#D', $storage) !== 1) {
            throw new \RuntimeException('Retained timezone version has an unsafe storage path');
        }
        $paths = [$storage . '/manifest.json'];
        foreach ((array)($bundle['files'] ?? []) as $file) {
            $storedName = (string)($file['stored_name'] ?? '');
            if (preg_match('/^[a-f0-9]{64}\.blob$/D', $storedName) !== 1) {
                throw new \RuntimeException('Retained timezone version has an unsafe file manifest');
            }
            $paths[] = $storage . '/' . $storedName;
        }
        // The destination is content-addressed and verified during adoption.
        // Overwrite makes an interrupted earlier copy repairable; it cannot
        // replace a different version because the fingerprint owns the path.
        $result = $this->copyPaths($siteId, $targetSiteIds, array_values(array_unique($paths)), true);
        foreach ($result['sites'] as $targetId => &$siteResult) {
            if (empty($siteResult['ok'])) { continue; }
            try {
                $siteResult['timezone_bundle'] = $this->ucm->adoptTimezoneVersion(
                    (int)$targetId,
                    (string)$bundle['bundle_key']
                );
                if (empty($siteResult['timezone_bundle']['ok'])) {
                    $siteResult['ok'] = false;
                    $siteResult['message'] .= '; target inventory rejected the retained timezone version: '
                        . (string)($siteResult['timezone_bundle']['message'] ?? 'unknown error');
                }
            } catch (\Throwable $e) {
                $siteResult['ok'] = false;
                $siteResult['message'] .= '; target inventory update failed: ' . $e->getMessage();
            }
        }
        unset($siteResult);
        $result['ok'] = !array_filter($result['sites'], static fn(array $site): bool => empty($site['ok']));
        $result['message'] = $result['ok']
            ? 'Retained Cisco timezone version synchronized to target libraries'
            : 'One or more target timezone libraries rejected the synchronized version';
        return $result;
    }

    /** Register an already-copied content-addressed archive on its owning PBX. */
    private function adoptTimezoneArchive(int $siteId, string $bundleKey): array
    {
        $bundleKey = strtolower(trim($bundleKey));
        if (preg_match('/^[a-f0-9]{64}$/D', $bundleKey) !== 1) {
            throw new \InvalidArgumentException('Timezone bundle fingerprint is invalid');
        }
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $relative = '.ucmprovisioner/timezone-bundles/' . $bundleKey;
        $manifestRead = $this->readBounded(
            $sftp,
            $root . '/' . $relative . '/manifest.json',
            1024 * 1024,
            'Cisco timezone archive manifest'
        );
        if (empty($manifestRead['ok'])) {
            throw new \RuntimeException((string)($manifestRead['message'] ?? 'Cannot read timezone archive manifest'));
        }
        $manifest = json_decode((string)$manifestRead['content'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)
            || (int)($manifest['schema'] ?? 0) !== 1
            || !hash_equals($bundleKey, strtolower((string)($manifest['bundle_key'] ?? '')))) {
            throw new \RuntimeException('Timezone archive manifest identity is invalid');
        }
        $contents = [];
        $storedFiles = [];
        foreach ((array)($manifest['files'] ?? []) as $file) {
            $name = (string)($file['name'] ?? '');
            $storedName = (string)($file['stored_name'] ?? '');
            $sha256 = strtolower((string)($file['sha256'] ?? ''));
            if (!array_key_exists($name, CiscoTimezoneService::FILES)
                || preg_match('/^[a-f0-9]{64}\.blob$/D', $storedName) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                throw new \RuntimeException('Timezone archive file manifest is invalid');
            }
            $read = $this->readBounded(
                $sftp,
                $root . '/' . $relative . '/' . $storedName,
                self::MAX_TIMEZONE_FILE_BYTES,
                'Cisco timezone archive adoption'
            );
            if (empty($read['ok']) || !hash_equals($sha256, hash('sha256', (string)($read['content'] ?? '')))) {
                throw new \RuntimeException("Timezone archive payload $name failed hash verification");
            }
            $contents[$name] = (string)$read['content'];
            $storedFiles[] = [
                'name'=>$name,
                'stored_name'=>$storedName,
                'size'=>strlen((string)$read['content']),
                'sha256'=>$sha256,
            ];
        }
        $inspection = CiscoTimezoneService::inspect(
            array_keys($contents),
            static fn(string $name) => $contents[$name] ?? false
        );
        $manifestStatus = (string)($manifest['status'] ?? '');
        if (!hash_equals($bundleKey, (string)($inspection['fingerprint'] ?? ''))) {
            throw new \RuntimeException('Timezone archive does not reproduce its recorded fingerprint');
        }
        if ($manifestStatus === 'quarantined') {
            if (empty($inspection['captureable'])) {
                throw new \RuntimeException('Quarantined timezone archive is no longer inspectable');
            }
            $inspection['status'] = 'quarantined';
        } elseif (empty($inspection['retainable'])) {
            throw new \RuntimeException('Timezone archive does not reproduce one usable retained version');
        }
        $manager = new CiscoTimezoneBundleManager($this->ucm->getDb());
        $bundle = $manager->store($siteId, $inspection, $storedFiles, $relative);
        foreach ($manager->prune($siteId, 3, $this->protectedTimezoneBundles($siteId, $manager)) as $removed) {
            $this->removeTimezoneArchive($sftp, $root, $removed);
        }
        // Alias cleanup must see the complete retained inventory. Publishing
        // only the newly adopted archive would delete valid aliases belonging
        // to other runtime profiles or older selected revisions.
        $published = $this->ensureTimezoneAssetAliases($siteId, $manager->assets($siteId));
        foreach ($published as $asset) {
            if ((int)($asset['source_bundle_id'] ?? 0) === (int)$bundle['id']
                && empty($asset['published'])) {
                throw new \RuntimeException(
                    'Timezone payload was retained but its phone-facing managed copy failed: '
                    . (string)($asset['publish_error'] ?? $asset['published_filename'] ?? 'unknown asset')
                );
            }
        }
        return [
            'ok'=>true,
            'site_id'=>$siteId,
            'bundle'=>$bundle,
            'message'=>'Retained Cisco timezone ' . (string)$bundle['served_version'] . ' in this site library',
        ];
    }

    /**
     * Retain one architecture payload independently.  Other runtime files at
     * TFTP root are deliberately not copied into this revision.
     */
    private function stageTimezonePayloadCandidate(int $siteId, string $profile): array
    {
        $profile = trim($profile);
        if (!isset(CiscoTimezoneService::PROFILES[$profile])) {
            throw new \InvalidArgumentException('Choose a documented Cisco timezone payload profile');
        }
        $expectedPayload = (string)CiscoTimezoneService::PROFILES[$profile]['payload'];
        $payloadBytes = $this->uploadedTimezoneFile('file', $expectedPayload, true);
        $versionBytes = $this->uploadedTimezoneFile('version_file', 'tzupdater.ver', false);

        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $contents = [$expectedPayload=>(string)$payloadBytes];
        if ($versionBytes !== null) { $contents['tzupdater.ver'] = $versionBytes; }

        $inspection = CiscoTimezoneService::inspect(
            array_keys($contents),
            static fn(string $name) => $contents[$name] ?? false
        );
        $compatibility = (array)($inspection['compatibility'] ?? []);
        $selected = (array)($compatibility[$profile] ?? []);
        if (empty($selected['configuration_usable'])) {
            $status = (string)($selected['status'] ?? 'incomplete');
            $actual = (string)($selected['actual_version'] ?? 'not detectable');
            $advertised = (string)($selected['advertised_version'] ?? 'missing');
            throw new \RuntimeException(
                "$expectedPayload is not usable for " . (string)CiscoTimezoneService::PROFILES[$profile]['label']
                . " (status $status; payload $actual; advertised $advertised)"
            );
        }
        if (empty($inspection['retainable'])) {
            throw new \RuntimeException('The proposed timezone set has no retainable Cisco phone-family profile');
        }
        $fingerprint = strtolower((string)($inspection['fingerprint'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \RuntimeException('The proposed timezone set has no stable fingerprint');
        }

        $relative = '.ucmprovisioner/timezone-bundles/' . $fingerprint;
        $directory = $root . '/' . $relative;
        $existed = $sftp->is_dir($directory);
        $this->ensureTimezoneDirectory($sftp, $root, $relative);
        $storedFiles = [];
        $adoptionStarted = false;
        try {
            foreach ($contents as $name=>$bytes) {
                if (!array_key_exists($name, CiscoTimezoneService::FILES)) { continue; }
                $sha256 = hash('sha256', $bytes);
                $storedName = $sha256 . '.blob';
                $storedPath = $directory . '/' . $storedName;
                if ($sftp->file_exists($storedPath)) {
                    $existing = $this->readBounded(
                        $sftp,
                        $storedPath,
                        self::MAX_TIMEZONE_FILE_BYTES,
                        'Cisco timezone candidate verification'
                    );
                    if (empty($existing['ok'])
                        || !hash_equals($sha256, hash('sha256', (string)($existing['content'] ?? '')))) {
                        throw new \RuntimeException("Immutable timezone candidate collision for $name");
                    }
                } elseif (!$sftp->put($storedPath, $bytes)) {
                    throw new \RuntimeException("Could not retain timezone candidate file $name");
                }
                $sftp->chmod(0640, $storedPath);
                $storedFiles[] = [
                    'name'=>$name,
                    'stored_name'=>$storedName,
                    'size'=>strlen($bytes),
                    'sha256'=>$sha256,
                ];
            }
            $manifest = json_encode([
                'schema'=>1,
                'bundle_key'=>$fingerprint,
                'served_version'=>(string)$inspection['served_version'],
                'iana_version'=>(string)($inspection['iana_version'] ?? ''),
                'status'=>(string)$inspection['status'],
                'compatibility'=>$compatibility,
                'files'=>$storedFiles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (!$sftp->put($directory . '/manifest.json', $manifest)) {
                throw new \RuntimeException('Timezone candidate files were retained but their manifest could not be written');
            }
            $sftp->chmod(0640, $directory . '/manifest.json');
            $adoptionStarted = true;
            $result = $this->adoptTimezoneArchive($siteId, $fingerprint);
        } catch (\Throwable $failure) {
            if ($adoptionStarted) {
                throw new RpcOperationException('Timezone candidate was retained; adoption or alias publication needs reconciliation: ' . $failure->getMessage(),
                    ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'site_id'=>$siteId,'retained_bundle_key'=>$fingerprint,'retained_path'=>$relative]);
            }
            if (!$existed) {
                foreach ($storedFiles as $file) {
                    $path = $directory . '/' . (string)$file['stored_name'];
                    if ($sftp->file_exists($path)) { $sftp->delete($path); }
                }
                if ($sftp->file_exists($directory . '/manifest.json')) { $sftp->delete($directory . '/manifest.json'); }
                if ($sftp->is_dir($directory)) { $sftp->rmdir($directory); }
            }
            throw $failure;
        }
        $result['profile'] = $profile;
        $result['payload'] = $expectedPayload;
        $result['current_unchanged'] = true;
        $result['message'] = 'Retained ' . (string)CiscoTimezoneService::PROFILES[$profile]['label']
            . ' payload ' . (string)$inspection['served_version']
            . '; choose it as a site default or template override';
        return $result;
    }

    /** Return one exact canonical upload, or null for an omitted optional field. */
    private function uploadedTimezoneFile(string $field, string $expectedName, bool $required): ?string
    {
        $file = $_FILES[$field] ?? null;
        $error = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE && !$required) { return null; }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($required ? "Upload $expectedName" : "$expectedName upload failed with code $error");
        }
        $name = is_array($file) ? basename((string)($file['name'] ?? '')) : '';
        $temporary = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';
        if (strcasecmp($name, $expectedName) !== 0) {
            throw new \InvalidArgumentException("This profile requires the filename $expectedName");
        }
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw new \RuntimeException("No authenticated $expectedName upload was received");
        }
        $size = @filesize($temporary);
        if (!is_int($size) || $size < 1 || $size > self::MAX_TIMEZONE_FILE_BYTES) {
            throw new \InvalidArgumentException("$expectedName must be between 1 byte and " . self::MAX_TIMEZONE_FILE_BYTES . ' bytes');
        }
        $bytes = @file_get_contents($temporary);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new \RuntimeException("Could not read the complete $expectedName upload");
        }
        return $bytes;
    }

    /**
     * Capture the phone-visible canonical files under a content-addressed,
     * immutable directory.  A missing tzupdater.ver is generated only when one
     * unambiguous IANA release can be derived from authoritative payload data.
     */
    private function captureTimezoneBundle(
        int $siteId,
        array $protectedBundleIds = [],
        bool $quarantine = false
    ): array
    {
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $inspection = $this->timezoneInventory($siteId);
        if ($quarantine) {
            if (empty($inspection['captureable'])) {
                throw new \RuntimeException('The canonical timezone files do not contain a stable, phone-facing set that can be quarantined');
            }
            if (in_array((string)($inspection['status'] ?? ''), ['complete','partial'], true)
                && !empty($inspection['selectable'])) {
                throw new \RuntimeException('This timezone set is usable; capture it as a normal retained version instead');
            }
        } elseif (empty($inspection['capture_allowed'])) {
            throw new \RuntimeException('The canonical timezone files do not contain one retainable Cisco model-family payload');
        }
        if (!empty($inspection['needs_version_file']) && !empty($inspection['can_generate_version'])) {
            $derived = trim((string)($inspection['derived_version'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $derived) !== 1) {
                throw new \RuntimeException('The timezone version cannot be derived safely');
            }
            $versionPath = $root . '/tzupdater.ver';
            if (!$sftp->put($versionPath, $derived . "\n")) {
                throw new \RuntimeException('Could not generate tzupdater.ver at the TFTP root');
            }
            $sftp->chmod(0664, $versionPath);
            $inspection = $this->timezoneInventory($siteId);
        }
        if (!$quarantine && empty($inspection['retainable'])) {
            throw new \RuntimeException('The canonical timezone set has no usable phone-family payload and cannot be retained');
        }
        $fingerprint = (string)($inspection['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \RuntimeException('The canonical timezone set has no stable fingerprint');
        }

        $relativeDirectory = '.ucmprovisioner/timezone-bundles/' . $fingerprint;
        $directory = $root . '/' . $relativeDirectory;
        $this->ensureTimezoneDirectory($sftp, $root, $relativeDirectory);
        $storedFiles = [];
        foreach ((array)$inspection['files'] as $file) {
            if (empty($file['present']) || empty($file['readable'])) { continue; }
            $canonicalName = (string)($file['name'] ?? '');
            $actualName = (string)($file['actual_name'] ?? $canonicalName);
            if (!array_key_exists($canonicalName, CiscoTimezoneService::FILES)) { continue; }
            $read = $this->readBounded(
                $sftp,
                $root . '/' . $actualName,
                self::MAX_TIMEZONE_FILE_BYTES,
                'Cisco timezone capture'
            );
            if (empty($read['ok'])) {
                throw new \RuntimeException((string)($read['message'] ?? "Cannot capture $canonicalName"));
            }
            $bytes = (string)$read['content'];
            $sha256 = hash('sha256', $bytes);
            if (!hash_equals((string)($file['sha256'] ?? ''), $sha256)) {
                throw new \RuntimeException("$canonicalName changed while the timezone bundle was being captured");
            }
            $storedName = $sha256 . '.blob';
            $storedPath = $directory . '/' . $storedName;
            if ($sftp->file_exists($storedPath)) {
                $existing = $this->readBounded(
                    $sftp,
                    $storedPath,
                    self::MAX_TIMEZONE_FILE_BYTES,
                    'Cisco timezone archive verification'
                );
                if (empty($existing['ok']) || !hash_equals($sha256, hash('sha256', (string)$existing['content']))) {
                    throw new \RuntimeException("Immutable timezone archive collision for $canonicalName");
                }
            } elseif (!$sftp->put($storedPath, $bytes)) {
                throw new \RuntimeException("Could not retain $canonicalName in the immutable timezone archive");
            }
            $sftp->chmod(0640, $storedPath);
            $storedFiles[] = [
                'name'=>$canonicalName,
                'stored_name'=>$storedName,
                'size'=>strlen($bytes),
                'sha256'=>$sha256,
            ];
        }

        $retainedInspection = $inspection;
        if ($quarantine) { $retainedInspection['status'] = 'quarantined'; }
        $manager = new CiscoTimezoneBundleManager($this->ucm->getDb());
        $bundle = $manager->store($siteId, $retainedInspection, $storedFiles, $relativeDirectory);
        $manifest = json_encode([
            'schema'=>1,
            'bundle_key'=>$fingerprint,
            'served_version'=>(string)$inspection['served_version'],
            'iana_version'=>(string)($inspection['iana_version'] ?? ''),
            'status'=>(string)$retainedInspection['status'],
            'compatibility'=>(array)$inspection['compatibility'],
            'files'=>$bundle['files'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (!$sftp->put($directory . '/manifest.json', $manifest)) {
            throw new \RuntimeException('Timezone files were retained but their recovery manifest could not be written');
        }
        $sftp->chmod(0640, $directory . '/manifest.json');
        if (!$quarantine) {
            $bundle = $manager->activate($siteId, (int)$bundle['id']);
        }
        foreach ($manager->prune($siteId, 3, array_merge($protectedBundleIds, $this->protectedTimezoneBundles($siteId, $manager))) as $removed) {
            $this->removeTimezoneArchive($sftp, $root, $removed);
        }

        return [
            'ok'=>true,
            'site_id'=>$siteId,
            'bundle'=>$bundle,
            'current'=>$inspection,
            'message'=>$quarantine
                ? 'Quarantined the current mixed Cisco timezone files as non-activatable snapshot #' . (int)$bundle['id']
                : 'Captured Cisco timezone ' . (string)$inspection['served_version']
                    . ' as immutable bundle #' . (int)$bundle['id'],
        ];
    }

    /** Activate a retained bundle; uncertain file or database stages retain recovery evidence. */
    private function activateTimezoneBundle(int $siteId, int $bundleId): array
    {
        $manager = new CiscoTimezoneBundleManager($this->ucm->getDb());
        $bundle = $manager->get($siteId, $bundleId);
        if (!$bundle) {
            throw new \InvalidArgumentException('Choose a retained timezone version from this site');
        }
        if (!in_array((string)($bundle['status'] ?? ''), ['complete','partial'], true)) {
            throw new \InvalidArgumentException('A quarantined timezone snapshot cannot be activated');
        }
        foreach (array_keys(ModelCatalog::CISCO_TIMEZONE_PROFILES) as $profile) {
            if (empty($bundle['compatibility'][$profile]['configuration_usable'])) {
                throw new \InvalidArgumentException(
                    'Only a complete canonical-root capture containing every Cisco timezone runtime can be published. '
                    . 'Select individual retained runtime revisions through Settings or a template instead.'
                );
            }
        }
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $contents = [];
        foreach ((array)$bundle['files'] as $file) {
            $name = (string)($file['name'] ?? '');
            $storedName = (string)($file['stored_name'] ?? '');
            if (!array_key_exists($name, CiscoTimezoneService::FILES)
                || preg_match('/^[a-f0-9]{64}\.blob$/D', $storedName) !== 1) {
                throw new \RuntimeException('Retained timezone manifest contains an unsafe filename');
            }
            $read = $this->readBounded(
                $sftp,
                $root . '/' . (string)$bundle['storage_path'] . '/' . $storedName,
                self::MAX_TIMEZONE_FILE_BYTES,
                'Cisco timezone activation'
            );
            if (empty($read['ok'])) {
                throw new \RuntimeException((string)($read['message'] ?? "Cannot read retained $name"));
            }
            $bytes = (string)$read['content'];
            if (!hash_equals((string)$file['sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException("Retained timezone payload $name no longer matches its recorded hash");
            }
            $contents[$name] = $bytes;
        }
        $target = CiscoTimezoneService::inspect(
            array_keys($contents),
            static fn(string $name) => $contents[$name] ?? false
        );
        if (empty($target['retainable'])
            || !hash_equals((string)$bundle['bundle_key'], (string)($target['fingerprint'] ?? ''))) {
            throw new \RuntimeException('Retained timezone bundle failed compatibility or fingerprint verification');
        }

        // Ensure the currently served version is itself a selectable rollback
        // entry before changing it. An unusable/mixed set is still protected by
        // the short-lived transaction backup below.
        $current = $this->timezoneInventory($siteId);
        if (!empty($current['retainable'])) {
            $this->captureTimezoneBundle($siteId, [$bundleId]);
        }

        $token = bin2hex(random_bytes(8));
        $stageRelative = '.ucmprovisioner/timezone-stage-' . $token;
        $backupRelative = '.ucmprovisioner/timezone-backup-' . $token;
        $stage = $root . '/' . $stageRelative;
        $backup = $root . '/' . $backupRelative;
        $this->ensureTimezoneDirectory($sftp, $root, $stageRelative);
        $this->ensureTimezoneDirectory($sftp, $root, $backupRelative);
        $movedOld = [];
        $installed = [];
        $activationAttempted = false;
        $filesVerified = false;
        $receipt = ['schema'=>1,'site_id'=>$siteId,'bundle_id'=>$bundleId,'bundle_key'=>$bundle['bundle_key'],
            'stage'=>$stageRelative,'backup'=>$backupRelative,'expected'=>[]];
        try {
            foreach ($contents as $name => $bytes) {
                $expected = ['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'mode'=>0664];
                if (!$sftp->put($stage . '/' . $name,$bytes) || !$sftp->chmod(0664,$stage . '/' . $name)) {
                    throw new \RuntimeException("Could not stage retained timezone payload $name");
                }
                $this->verifyRemotePayload($sftp,$stage . '/' . $name,$expected);
                $receipt['expected'][$name]=$expected;
            }
            // Preserve intent before the first rename: a lost reply is not evidence
            // that a move failed, and must never trigger reverse file operations.
            $this->writeRemoteJson($sftp,$backup . '/receipt.json',$receipt);
            foreach (array_keys(CiscoTimezoneService::FILES) as $name) {
                $canonical = $root . '/' . $name;
                if ($sftp->file_exists($canonical)) {
                    $activationAttempted = true;
                    if (!$sftp->rename($canonical,$backup . '/' . $name)) {
                        throw new \RuntimeException("Could not confirm preservation of $name");
                    }
                    $movedOld[]=$name;
                }
            }
            foreach ($receipt['expected'] as $name=>$expected) {
                $activationAttempted = true;
                if (!$sftp->rename($stage . '/' . $name,$root . '/' . $name)) {
                    throw new \RuntimeException("Could not confirm activation of $name");
                }
                $installed[]=$name;
                $this->verifyRemotePayload($sftp,$root . '/' . $name,$expected);
            }
            $verified=$this->timezoneInventory($siteId);
            if (!hash_equals((string)$bundle['bundle_key'],(string)($verified['fingerprint'] ?? ''))) {
                throw new \RuntimeException('Activated timezone root files did not reproduce the retained fingerprint');
            }
            $filesVerified=true;
            $bundle=$manager->activate($siteId,$bundleId);
        } catch (\Throwable $failure) {
            if ($activationAttempted) {
                throw new RpcOperationException('Timezone activation requires reconciliation: ' . $failure->getMessage(),
                    ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'site_id'=>$siteId,
                     'bundle_id'=>$bundleId,'files_verified'=>$filesVerified,'preserved_files'=>$movedOld,'installed_files'=>$installed,
                     'stage_path'=>$stageRelative,'backup_path'=>$backupRelative,'receipt'=>$backupRelative . '/receipt.json']);
            }
            $this->removeTimezoneWorkDirectory($sftp,$stage,array_keys($contents));
            throw $failure;
        }
        // Metadata is acknowledged before any before-state is discarded.
        $warnings=[];
        try {
            $this->removeTimezoneWorkDirectory($sftp,$stage,array_keys($contents));
            $this->removeTimezoneWorkDirectory($sftp,$backup,array_merge(array_keys(CiscoTimezoneService::FILES),['receipt.json']));
            if ($sftp->is_dir($stage)||$sftp->is_dir($backup)) {
                $warnings[]='Activation completed; temporary before-state cleanup remains pending at ' . $backupRelative;
            }
        } catch (\Throwable $error) {
            $warnings[]='Activation completed; temporary before-state cleanup failed: ' . $error->getMessage();
        }
        return ['ok'=>true,'site_id'=>$siteId,'bundle'=>$bundle,'current'=>$verified,'warnings'=>$warnings,
            'message'=>'Activated Cisco timezone ' . (string)$bundle['served_version']
                . ($warnings ? '; temporary cleanup remains pending' : '')];
    }

    /** Create one sanitized internal directory path without relying on recursive mkdir semantics. */
    private function ensureTimezoneDirectory($sftp, string $root, string $relative): void
    {
        $cursor = rtrim($root, '/');
        foreach (explode('/', trim($relative, '/')) as $part) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,96}$/D', $part) !== 1) {
                throw new \InvalidArgumentException('Internal timezone storage path is unsafe');
            }
            $cursor .= '/' . $part;
            if (!$sftp->is_dir($cursor) && !$sftp->mkdir($cursor)) {
                throw new \RuntimeException("Cannot create internal timezone directory $cursor");
            }
            $sftp->chmod(self::PRIVATE_DIRECTORY_MODE, $cursor);
        }
    }

    /**
     * Publish immutable, hash-named copies so different templates may select
     * different revisions of the same Cisco runtime without racing one shared
     * canonical root filename.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private function ensureTimezoneAssetAliases(int $siteId, array $assets): array
    {
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/');
        $keep = [];
        foreach ($assets as &$asset) {
            $asset['published'] = false;
            try {
                $storage = trim((string)($asset['storage_path'] ?? ''), '/');
                $storedName = (string)($asset['stored_name'] ?? '');
                $publishedName = (string)($asset['published_filename'] ?? '');
                $sha256 = strtolower((string)($asset['sha256'] ?? ''));
                if (preg_match('#^\.ucmprovisioner/timezone-bundles/[a-f0-9]{64}$#D', $storage) !== 1
                    || preg_match('/^[a-f0-9]{64}\.blob$/D', $storedName) !== 1
                    || !AssetFilename::isTimezone($publishedName)
                    || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                    throw new \RuntimeException('Retained timezone asset has an unsafe manifest');
                }
                // Preserve the previously published copy during a transient
                // archive/read failure; only aliases absent from the retained
                // database inventory are lifecycle-cleaned below.
                $keep[$publishedName] = true;
                $read = $this->readBounded(
                    $sftp,
                    $root . '/' . $storage . '/' . $storedName,
                    self::MAX_TIMEZONE_FILE_BYTES,
                    'Cisco timezone asset publication'
                );
                $bytes = (string)($read['content'] ?? '');
                if (empty($read['ok']) || !hash_equals($sha256, hash('sha256', $bytes))) {
                    throw new \RuntimeException('Retained timezone payload failed SHA-256 verification');
                }
                $target = $root . '/' . $publishedName;
                $replace = true;
                if ($sftp->file_exists($target)) {
                    $existing = $this->readBounded($sftp, $target, self::MAX_TIMEZONE_FILE_BYTES, 'Cisco timezone published copy');
                    $replace = empty($existing['ok']) || !hash_equals($sha256, hash('sha256', (string)($existing['content'] ?? '')));
                }
                if ($replace) { $this->replaceRemoteFiles($sftp,[$target=>['content'=>$bytes,'mode'=>0664]]); }
                elseif (!$sftp->chmod(0664,$target)) { throw new \RuntimeException("Could not verify permissions for $publishedName"); }
                $role = (string)(CiscoTimezoneService::FILES[(string)$asset['payload']] ?? 'payload');
                $registered = $this->registerManagedPayload(
                    $siteId,
                    $publishedName,
                    $sha256,
                    strlen($bytes),
                    [
                        'profile'=>(string)$asset['profile'],
                        'bundle_key'=>'cisco-timezone:' . (string)$asset['profile'] . ':' . (string)$asset['asset_key'],
                        'bundle_role'=>$role,
                        'asset_version'=>(string)$asset['version'],
                        'validation_status'=>'verified',
                        'provenance'=>'generated',
                        'validated_at'=>time(),
                        'signature_status'=>'unverified',
                        'manifest'=>[array_filter([
                            'role'=>$role,
                            'relative_path'=>$publishedName,
                            'filename'=>$publishedName,
                            'required'=>true,
                            'status'=>'present',
                            'size_bytes'=>strlen($bytes),
                            'content_sha256'=>$sha256,
                            'parsed_version'=>(string)$asset['version'],
                        ], static fn($value): bool => $value !== '')],
                        'metadata'=>[
                            'format'=>'timezone-asset',
                            'magic'=>'Cisco timezone payload',
                            'timezone_profile'=>(string)$asset['profile'],
                            'timezone_asset_key'=>(string)$asset['asset_key'],
                            'architecture'=>(string)$asset['architecture'],
                            'canonical_payload'=>(string)$asset['payload'],
                        ],
                    ]
                );
                if (!empty($registered['warning'])) { $asset['registry_warning'] = (string)$registered['warning']; }
                $asset['published'] = true;
            } catch (\Throwable $error) {
                $asset['publish_error'] = $error->getMessage();
            }
        }
        unset($asset);
        $entries = $sftp->nlist($root);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $name = basename((string)$entry);
                if (AssetFilename::isTimezone($name)
                    && empty($keep[$name])) {
                    // Orphan aliases remain recoverable until the explicit asset
                    // retirement workflow proves the live and rollback graph.
                    // Retention rotation alone is not deletion authority.
                }
            }
        }
        return $assets;
    }

    /** Best-effort cleanup for exact files in a generated work directory. */
    private function removeTimezoneWorkDirectory($sftp, string $directory, array $names): void
    {
        foreach ($names as $name) {
            if (array_key_exists((string)$name, CiscoTimezoneService::FILES)
                && $sftp->file_exists($directory . '/' . $name)) {
                $sftp->delete($directory . '/' . $name);
            }
        }
        if ($sftp->is_dir($directory)) { $sftp->rmdir($directory); }
    }

    /** Remove a pruned immutable bundle after its database row is gone. */
    private function removeTimezoneArchive($sftp, string $root, array $bundle): void
    {
        $relative = trim((string)($bundle['storage_path'] ?? ''), '/');
        if (preg_match('#^\.ucmprovisioner/timezone-bundles/[a-f0-9]{64}$#D', $relative) !== 1) { return; }
        $directory = rtrim($root, '/') . '/' . $relative;
        foreach ((array)($bundle['files'] ?? []) as $file) {
            $name = (string)($file['stored_name'] ?? '');
            if (preg_match('/^[a-f0-9]{64}\.blob$/D', $name) === 1 && $sftp->file_exists($directory . '/' . $name)) {
                $sftp->delete($directory . '/' . $name);
            }
        }
        if ($sftp->file_exists($directory . '/manifest.json')) { $sftp->delete($directory . '/manifest.json'); }
        if ($sftp->is_dir($directory)) { $sftp->rmdir($directory); }
    }

    /** Read a remote file without allowing the editor or preview to drain PHP memory. */
    private function readBounded($sftp, string $fullPath, int $limit, string $purpose): array
    {
        $metadata = $sftp->stat($fullPath);
        if (!is_array($metadata) || !array_key_exists('size', $metadata)) {
            return ['ok'=>false, 'message'=>"Cannot inspect $fullPath before $purpose read"];
        }
        if ((int)($metadata['type'] ?? 0) === 2) {
            return ['ok'=>false, 'message'=>'Directories cannot be opened as files'];
        }
        $declaredSize = (int)$metadata['size'];
        if ($declaredSize < 0 || $declaredSize > $limit) {
            return [
                'ok'=>false,
                'message'=>sprintf(
                    '%s is %d bytes; the %s limit is %d bytes',
                    basename($fullPath),
                    max(0, $declaredSize),
                    $purpose,
                    $limit
                ),
                'size'=>max(0, $declaredSize),
                'max_bytes'=>$limit,
            ];
        }
        // phpseclib's length argument enforces the bound even if the file grows
        // after stat() and before the SFTP transfer begins.
        $data = $sftp->get($fullPath, false, 0, $limit + 1);
        if ($data === false) {
            return ['ok'=>false, 'message'=>"Cannot read $fullPath"];
        }
        $data = (string)$data;
        if (strlen($data) > $limit) {
            return [
                'ok'=>false,
                'message'=>sprintf('%s grew beyond the %s limit while it was being read', basename($fullPath), $purpose),
                'size'=>strlen($data),
                'max_bytes'=>$limit,
            ];
        }
        return ['ok'=>true, 'content'=>$data, 'size'=>strlen($data), 'max_bytes'=>$limit];
    }

    /** @return array{ok:bool,content?:string,message?:string} */
    private function phoneConfigOverrideSnapshot($sftp, string $fullPath): array
    {
        $snapshot = $this->readBounded(
            $sftp,
            $fullPath,
            self::MAX_TEXT_READ_BYTES,
            'managed phone-config override backup'
        );
        if (empty($snapshot['ok'])) {
            return [
                'ok'=>false,
                'message'=>'The managed config could not be backed up, so it was not overridden: '
                    . (string)($snapshot['message'] ?? 'read failed'),
            ];
        }
        return ['ok'=>true, 'content'=>(string)$snapshot['content']];
    }

    /**
     * A manual override is successful only when both the physical file and its
     * owning-phone state are saved. Restore the prior managed file if tracking
     * fails, so a later rebuild can never unknowingly overwrite a manual file.
     *
     * @param array{ok:bool,content?:string} $snapshot
     * @return array{ok:false,message:string,override_warning:string}
     */
    private function restoreUntrackedPhoneOverride(
        $sftp,
        string $fullPath,
        array $snapshot,
        string $trackingError
    ): array {
        $restored = $sftp->put($fullPath, (string)($snapshot['content'] ?? ''));
        if ($restored) {
            $sftp->chmod(0664, $fullPath);
        }
        return [
            'ok'=>false,
            'message'=>$restored
                ? 'Override was not saved because its phone tracking failed; the previous managed config was restored'
                : 'CRITICAL: override tracking and automatic file restoration both failed; inspect this phone config before rebuilding',
            'override_warning'=>$trackingError,
        ];
    }

    /**
     * @param array<string,mixed> $target active-manifest row or persisted manual row
     * @return array{phone?:array<string,mixed>,warning?:string}
     */
    private function recordPhoneConfigOverride(
        int $siteId,
        array $target,
        string $filename,
        string $sha256
    ): array {
        $phoneId = (int)($target['phone_id'] ?? $target['id'] ?? 0);
        if ($phoneId < 1
            || (!method_exists($this->ucm, 'recordPhoneConfigOverride')
                && !method_exists($this->ucm, 'recordPhoneConfigOverrideLocal'))) {
            return ['warning'=>'The file changed, but its phone override state could not be recorded'];
        }
        try {
            $phone = method_exists($this->ucm, 'recordPhoneConfigOverride')
                ? $this->ucm->recordPhoneConfigOverride(
                    $siteId,
                    $phoneId,
                    $filename,
                    $sha256
                )
                : $this->ucm->recordPhoneConfigOverrideLocal(
                    $siteId,
                    $phoneId,
                    $filename,
                    $sha256
                );
            return [
                'phone'=>$phone,
            ];
        } catch (\Throwable $e) {
            return [
                'warning'=>'The file changed, but its phone override state could not be recorded: '
                    . $e->getMessage(),
            ];
        }
    }

    /**
     * Mark the registry slot stale only after the corresponding remote delete
     * has succeeded. Unrecognized paths have no managed registry lifecycle.
     */
    public function assetReferenceSnapshot(int $siteId, ?int $assetId = null, array $additionalAssets = []): array
    {
        require_once __DIR__.'/AssetStorageReferences.php';
        $registry=new ManagedAssetRegistry($this->ucm->getDb());
        $assets=$registry->all($siteId,['state'=>'all']);
        array_push($assets,...$additionalAssets);
        $connection=$this->ucm->siteConnection($siteId);
        $snapshot=AssetStorageReferences::scan($connection->dockerSftp(),$connection->tftpRoot(),$assets);
        $snapshot['scan_complete']=false;
        $active=$this->ucm->getDb()->prepare('SELECT 1 FROM ucmp_asset_scan_jobs WHERE site_id=?');
        $active->execute([$siteId]);$snapshot['active_scan']=(bool)$active->fetchColumn();
        if ($assetId!==null) {
            $asset=$registry->getById($siteId,$assetId);
            $snapshot['scan_complete']=$asset && (new ManagedAssetScanJob($this->ucm->getDb()))->verified($siteId,$asset['kind']);
        }
        return $snapshot;
    }

    private function retirementPayloadHash($sftp,string $path,int $expectedBytes): string
    {
        $metadata=$sftp->lstat($path);
        if ($expectedBytes<0||$expectedBytes>self::MAX_SCAN_FILE_BYTES||!is_array($metadata)||(int)($metadata['type']??0)!==1||(int)($metadata['size']??-1)!==$expectedBytes) { throw new \RuntimeException('Asset path, type or size changed; retained bytes were not discarded'); }
        $temporary=tempnam(sys_get_temp_dir(),'ucmp-retire-hash-');
        if ($temporary===false) { throw new \RuntimeException('Cannot allocate bounded verification file'); }
        try {
            if (!$sftp->get($path,$temporary,0,$expectedBytes+1)||filesize($temporary)!==$expectedBytes) { throw new \RuntimeException('Asset verification transfer failed or changed size'); }
            return (string)hash_file('sha256',$temporary);
        } finally { @unlink($temporary); }
    }

    private function protectedTimezoneBundles(int $siteId, CiscoTimezoneBundleManager $manager): array
    {
        require_once __DIR__.'/AssetReferenceIndex.php';
        require_once __DIR__.'/AssetStorageReferences.php';
        $bundles=$manager->list($siteId);$all=array_map(static fn(array $row): int=>(int)$row['id'],$bundles);
        try {
            $graph=(new AssetReferenceIndex($this->ucm->getDb()))->snapshot($siteId);
            if (!$graph['complete']) { return $all; }
            $assets=[];
            foreach ($bundles as $bundle) {
                foreach (CiscoTimezoneBundleManager::assetsFromBundles([$bundle]) as $asset) {
                    $assets[]=['asset_id'=>0,'asset_key'=>$asset['asset_key'],'kind'=>'locale','filename'=>$asset['published_filename'],
                        'bundle_key'=>$bundle['bundle_key'],'source_bundle_id'=>(int)$bundle['id'],'metadata'=>['timezone_asset_key'=>$asset['asset_key']]];
                }
            }
            $connection=$this->ucm->siteConnection($siteId);
            $storage=AssetStorageReferences::scan($connection->dockerSftp(),$connection->tftpRoot(),$assets);
            if (!$storage['complete']) { return $all; }
            $protected=[];
            foreach ($assets as $asset) {
                if (!empty($storage['references'][$asset['asset_key']])) { $protected[]=$asset['source_bundle_id'];continue; }
                foreach ($graph['sources'] as $source) {
                    if (AssetReferenceIndex::matches($source,$asset)) { $protected[]=$asset['source_bundle_id'];break; }
                }
            }
            return array_values(array_unique($protected));
        } catch (\Throwable) { return $all; }
    }

    private function retireManagedPath(int $siteId, string $relativePath): array
    {
        try {
            $metadata = self::managedAssetMetadata($relativePath);
            if ($metadata === null) { return []; }
            (new ManagedAssetRegistry($this->ucm->getDb()))->markSlotStale(
                $siteId,
                (string)$metadata['kind'],
                (string)$metadata['filename'],
                $metadata['profile'] !== null ? (string)$metadata['profile'] : null,
                $metadata['bundle_key'] !== null ? (string)$metadata['bundle_key'] : null
            );
            return [];
        } catch (\Throwable $e) {
            return ['warning'=>'TFTP delete succeeded, but managed asset inventory was not retired: ' . $e->getMessage()];
        }
    }

    /**
     * Merge live HTTP-served files into the lifecycle projection used by the
     * Media Asset Index. Registry receipts win when the same bytes already
     * have one; otherwise the physical HTTP row remains visible as observed.
     *
     * @param array<int,array<string,mixed>> $registered
     * @param array<int,array<string,mixed>> $hosted
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private static function mergeHostedMediaAssets(array $registered, array $hosted, array $filters): array
    {
        $key = static fn(array $asset): string => implode('|', [
            strtolower((string)($asset['kind'] ?? '')),
            strtolower((string)($asset['filename'] ?? $asset['name'] ?? '')),
            strtolower((string)($asset['content_sha256'] ?? '')),
            strtolower((string)($asset['profile'] ?? '')),
        ]);
        $positions = [];
        foreach ($registered as $index=>$asset) { $positions[$key((array)$asset)] = $index; }
        foreach ($hosted as $asset) {
            $asset = (array)$asset;
            if (isset($filters['kind']) && (string)($asset['kind'] ?? '') !== (string)$filters['kind']) { continue; }
            if (isset($filters['profile']) && (string)($asset['profile'] ?? '') !== (string)$filters['profile']) { continue; }
            if (isset($filters['state']) && $filters['state'] !== 'all'
                && (string)($asset['state'] ?? 'current') !== (string)$filters['state']) { continue; }
            if (isset($filters['bundle_key']) && (string)($asset['bundle_key'] ?? '') !== (string)$filters['bundle_key']) { continue; }
            $assetKey = $key($asset);
            if (isset($positions[$assetKey])) {
                $index = $positions[$assetKey];
                $metadata = (array)($registered[$index]['metadata'] ?? []);
                $locations = array_values((array)($metadata['http_locations'] ?? []));
                $location = (string)($asset['relative_path'] ?? '');
                if ($location !== '' && !in_array($location, $locations, true)) { $locations[] = $location; }
                $registered[$index]['metadata'] = array_merge($metadata, ['http_locations'=>$locations]);
                $registered[$index]['url'] = $asset['url'] ?? null;
                $registered[$index]['preview_url'] = $asset['preview_url'] ?? null;
                continue;
            }
            $positions[$assetKey] = count($registered);
            $registered[] = $asset;
        }
        usort($registered, static fn(array $left, array $right): int => strnatcasecmp(
            (string)($left['kind'] ?? '') . '/' . (string)($left['filename'] ?? ''),
            (string)($right['kind'] ?? '') . '/' . (string)($right['filename'] ?? '')
        ));
        $limit = max(1, min(5000, (int)($filters['limit'] ?? 500)));
        return array_slice($registered, 0, $limit);
    }

    /**
     * Hash recognized TFTP assets with explicit traversal, transfer and time
     * ceilings. Missing rows are retired only after every queued directory and
     * recognized file completed successfully.
     */
    private function rescanManagedAssets(int $siteId, string $scanToken = '', array $kinds = []): array
    {
        $connection = $this->ucm->siteConnection($siteId);
        $sftp = $connection->dockerSftp();
        $root = rtrim($connection->tftpRoot(), '/') ?: '/';
        $registry = new ManagedAssetRegistry($this->ucm->getDb());
        $jobs = new ManagedAssetScanJob($this->ucm->getDb());
        $job = $jobs->resumeOrStart($siteId, $scanToken, $kinds);
        $token = (string)$job['token'];
        $state = (array)$job['state'];
        $scanKinds = array_values((array)($state['kinds'] ?? []));
        if (!isset($state['observations'])) {
            if (!empty($state['entries'])) { throw new \RuntimeException('This scan started before observation tracking was upgraded; reset its cursor and start a fresh scan'); }
            $state['observations']=$registry->scanCandidates($siteId,$scanKinds);
            $jobs->save($siteId,$token,$state);
        }
        $deadline = microtime(true) + self::MAX_SCAN_SECONDS;

        try {
            while (!empty($state['queue']) && microtime(true) < $deadline) {
                $task = (array)$state['queue'][0];
                $type = (string)($task['type'] ?? '');
                if ($type === 'directory') {
                    $relativeDirectory = trim((string)($task['path'] ?? ''), '/');
                    $depth = max(0, (int)($task['depth'] ?? 0));
                    $remoteDirectory = $root . ($relativeDirectory !== '' ? '/' . $relativeDirectory : '');
                    $entries = $sftp->rawlist($remoteDirectory);
                    if (!is_array($entries)) {
                        throw new \RuntimeException("Cannot list $remoteDirectory; no missing assets were retired");
                    }
                    if (count($entries) > self::MAX_SCAN_ENTRIES_PER_DIRECTORY) {
                        throw new \RuntimeException('A TFTP directory exceeds the bounded rescan entry limit; no missing assets were retired');
                    }
                    ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);
                    $newTasks = [];
                    $newEntries = 0;
                    $newDirectories = 0;
                    foreach ($entries as $entryName => $entryMetadata) {
                        $metadata = (array)$entryMetadata;
                        $name = is_string($entryName) ? $entryName : (string)($metadata['filename'] ?? '');
                        if ($name === '' || $name === '.' || $name === '..') { continue; }
                        $newEntries++;
                        $relativePath = trim($relativeDirectory . '/' . $name, '/');
                        if ((int)($metadata['type'] ?? 0) === 2) {
                            // Internal transaction/version storage is self-verifying and
                            // must not be reclassified as phone-facing firmware.
                            if ($relativePath === '.ucmprovisioner') { continue; }
                            if ($depth >= self::MAX_SCAN_DEPTH) {
                                throw new \RuntimeException('TFTP traversal exceeds the bounded rescan depth; no missing assets were retired');
                            }
                            $newDirectories++;
                            $newTasks[] = ['type'=>'directory','path'=>$relativePath,'depth'=>$depth + 1];
                            continue;
                        }
                        $asset = self::managedAssetMetadata($relativePath);
                        if ($asset === null) { continue; }
                        if ($scanKinds && !in_array((string)$asset['kind'], $scanKinds, true)) {
                            continue;
                        }
                        $size = (int)($metadata['size'] ?? -1);
                        if ($size < 0 || $size > self::MAX_SCAN_FILE_BYTES) {
                            throw new \RuntimeException("$relativePath has no safe bounded size; no missing assets were retired");
                        }
                        $newTasks[] = ['type'=>'file','path'=>$relativePath,'name'=>$name,'size'=>$size];
                    }
                    if ((int)($state['entries'] ?? 0) + $newEntries
                        > self::MAX_SCAN_ENTRIES_PER_DIRECTORY * self::MAX_SCAN_DIRECTORIES) {
                        throw new \RuntimeException('TFTP traversal exceeds the bounded rescan entry limit; no missing assets were retired');
                    }
                    if ((int)($state['directories'] ?? 0) + 1 + $newDirectories > self::MAX_SCAN_DIRECTORIES) {
                        throw new \RuntimeException('TFTP traversal exceeds the bounded rescan directory limit; no missing assets were retired');
                    }
                    array_shift($state['queue']);
                    array_push($state['queue'], ...$newTasks);
                    $state['entries'] = (int)($state['entries'] ?? 0) + $newEntries;
                    $state['directories'] = (int)($state['directories'] ?? 0) + 1;
                    continue;
                }
                if ($type !== 'file') {
                    throw new \RuntimeException('Managed asset scan cursor contains an unknown task');
                }

                $relativePath = trim((string)($task['path'] ?? ''), '/');
                $name = (string)($task['name'] ?? basename($relativePath));
                $declaredSize = (int)($task['size'] ?? -1);
                $assetMetadata = self::managedAssetMetadata($relativePath);
                if ($assetMetadata === null) {
                    array_shift($state['queue']);
                    continue;
                }
                if ($declaredSize < 0 || $declaredSize > self::MAX_SCAN_FILE_BYTES) {
                    throw new \RuntimeException("$relativePath exceeds the bounded per-file scan limit; no missing assets were retired");
                }
                $temporary = tempnam(sys_get_temp_dir(), 'ucmp-asset-scan-');
                if ($temporary === false) { throw new \RuntimeException('Could not allocate a managed asset rescan file'); }
                try {
                    if (!$sftp->get($root . '/' . $relativePath, $temporary, 0, self::MAX_SCAN_FILE_BYTES + 1)) {
                        throw new \RuntimeException("Cannot stream $relativePath; no missing assets were retired");
                    }
                    $actualSize = @filesize($temporary);
                    if (!is_int($actualSize) || $actualSize < 0 || $actualSize > self::MAX_SCAN_FILE_BYTES) {
                        throw new \RuntimeException("$relativePath exceeded the bounded file limit; no missing assets were retired");
                    }
                    $inspection = AssetFileInspector::inspect($temporary, $name, (string)$assetMetadata['kind']);
                    $assetMetadata = self::managedAssetMetadata($relativePath, (string)$inspection['content_sha256']);
                    $inspectionFields = [
                        'site_id'=>$siteId,
                        'content_sha256'=>$inspection['content_sha256'],
                        'size_bytes'=>$actualSize,
                        'content_type'=>$inspection['content_type'],
                        'metadata'=>array_replace((array)($assetMetadata['metadata'] ?? []), (array)$inspection['metadata']),
                    ];
                    $assetKey = ManagedAssetRegistry::address(
                        (string)$assetMetadata['kind'],
                        (string)$inspection['content_sha256'],
                        (string)$assetMetadata['filename'],
                        isset($assetMetadata['profile']) ? (string)$assetMetadata['profile'] : null
                    );
                    $previous = $registry->get($siteId, $assetKey);
                    $generatedCatalog = is_array($previous)
                        && (string)($previous['provenance'] ?? '') === 'generated'
                        && (string)($previous['bundle_role'] ?? '') === 'catalog';
                    if (($inspection['validation_status'] ?? '') !== 'discovered') {
                        $inspectionFields['validation_status'] = $inspection['validation_status'];
                    } elseif ($generatedCatalog) {
                        // The immutable hash is still the exact module-generated
                        // catalog. A generic XML scan must not erase that receipt.
                        $inspectionFields['validation_status'] = 'verified';
                    }
                    if (($inspection['signature_status'] ?? '') !== 'unverified') {
                        $inspectionFields['signature_status'] = $inspection['signature_status'];
                    } elseif ($generatedCatalog) {
                        $inspectionFields['signature_status'] = 'not_applicable';
                    }
                    if ($inspection['parsed_version'] !== null) {
                        $inspectionFields['asset_version'] = $inspection['parsed_version'];
                    }
                    $registry->upsert($inspectionFields + $assetMetadata);
                } finally {
                    if (is_file($temporary)) { @unlink($temporary); }
                }
                array_shift($state['queue']);
                $state['files'] = (int)($state['files'] ?? 0) + 1;
                $state['bytes'] = (int)($state['bytes'] ?? 0) + $actualSize;
                $kind = (string)$assetMetadata['kind'];
                $state['by_kind'][$kind] = (int)($state['by_kind'][$kind] ?? 0) + 1;
            }
        } catch (\Throwable $error) {
            $jobs->save($siteId, $token, $state, $error->getMessage());
            throw $error;
        }

        if (!empty($state['queue'])) {
            $jobs->save($siteId, $token, $state);
            return [
                'ok'=>true,
                'complete'=>false,
                'site_id'=>$siteId,
                'scan_token'=>$token,
                'files'=>(int)$state['files'],
                'directories'=>(int)$state['directories'],
                'bytes'=>(int)$state['bytes'],
                'remaining_tasks'=>count($state['queue']),
                'by_kind'=>(array)$state['by_kind'],
                'retired'=>0,
                'message'=>'Managed asset scan slice complete; continuing from the saved cursor.',
            ];
        }

        $retired = $registry->retireUnobserved($siteId,$state['observations']);
        $lifecycle = new AssetLifecycleService($this->ucm->getDb());
        $reconciliation = ['ok'=>true, 'updated'=>0, 'issues'=>[], 'skipped'=>true];
        if (!$scanKinds || in_array('firmware', $scanKinds, true)) {
            try {
                $firmwareBundles = (new FirmwareService($this->ucm))->inventory($siteId);
                $reconciliation = $lifecycle->reconcileFirmware($siteId, $firmwareBundles);
            } catch (\Throwable $error) {
                $reconciliation = ['ok'=>false, 'updated'=>0, 'issues'=>[$error->getMessage()]];
            }
        }
        $timezoneReconciliation = ['ok'=>true, 'updated'=>0, 'issues'=>[], 'skipped'=>true];
        if (!$scanKinds || in_array('locale', $scanKinds, true)) {
            try {
                $timezoneReconciliation = $lifecycle->reconcileTimezone(
                    $siteId,
                    $this->timezoneInventory($siteId)
                );
            } catch (\Throwable $error) {
                $timezoneReconciliation = ['ok'=>false, 'updated'=>0, 'issues'=>[$error->getMessage()]];
            }
        }
        $jobs->save($siteId, $token, $state);
        $jobs->finish($siteId, $token);
        $reconciled = !empty($reconciliation['ok']) && !empty($timezoneReconciliation['ok']);
        return [
            'ok'=>$reconciled,
            'status'=>$reconciled ? 'success' : 'partial',
            'complete'=>true,
            'site_id'=>$siteId,
            'scan_token'=>$token,
            'files'=>(int)$state['files'],
            'directories'=>(int)$state['directories'],
            'bytes'=>(int)$state['bytes'],
            'remaining_tasks'=>0,
            'by_kind'=>(array)$state['by_kind'],
            'retired'=>$retired,
            'assets'=>$registry->list($siteId, ['state'=>'current', 'limit'=>500]),
            'catalog_assets'=>$lifecycle->inventory($siteId),
            'firmware_reconciliation'=>$reconciliation,
            'timezone_reconciliation'=>$timezoneReconciliation,
            'observed_only'=>true,
            'message'=>'Managed asset scan completed for '
                . implode(', ', $scanKinds ?: ['all recognized kinds'])
                . ' across '
                . (int)$state['files'] . ' file(s); missing assets were retired only after full traversal.'
                . ($reconciled ? '' : ' File scanning completed, but catalog reconciliation failed; inspect the reported firmware/timezone issues.'),
        ];
    }

    /**
     * Record only recognized managed payloads. The relative TFTP path is used
     * for classification and is persisted only as a sanitized relative path;
     * the registry receives an actual content hash supplied by the completed
     * write path.
     *
     * @param array<string,mixed> $overrides
     * @return array{asset?:array<string,mixed>,warning?:string}
     */
    private function registerManagedPayload(
        int $siteId,
        string $relativePath,
        string $contentSha256,
        int $sizeBytes,
        array $overrides = []
    ): array {
        try {
            $metadata = self::managedAssetMetadata($relativePath, $contentSha256);
            if ($metadata === null) { return []; }
            foreach ([
                'profile', 'bundle_key', 'bundle_role', 'companions',
                'asset_version', 'validation_status', 'provenance',
                'manifest', 'validated_at', 'catalog_revision',
                'signature_status', 'metadata', 'content_type',
            ] as $field) {
                if (array_key_exists($field, $overrides)) {
                    $metadata[$field] = $field === 'metadata'
                        ? array_replace((array)($metadata[$field] ?? []), (array)$overrides[$field])
                        : $overrides[$field];
                }
            }
            if (!array_key_exists('provenance', $overrides)) { $metadata['provenance'] = 'managed_write'; }
            $registry = new ManagedAssetRegistry($this->ucm->getDb());
            $asset = $registry->upsert([
                'site_id'=>$siteId,
                'content_sha256'=>$contentSha256,
                'size_bytes'=>$sizeBytes,
            ] + $metadata);
            $result = ['asset'=>[
                'asset_key'=>$asset['asset_key'],
                'asset_id'=>$asset['asset_id'],
                'kind'=>$asset['kind'],
                'filename'=>$asset['filename'],
                'content_sha256'=>$asset['content_sha256'],
                'size_bytes'=>$asset['size_bytes'],
                'profile'=>$asset['profile'],
                'bundle_key'=>$asset['bundle_key'],
                'bundle_role'=>$asset['bundle_role'],
                'validation_status'=>$asset['validation_status'],
                'provenance'=>$asset['provenance'],
                'state'=>$asset['state'],
            ]];
            try {
                $registry->markSlotStale(
                    $siteId,
                    (string)$asset['kind'],
                    (string)$asset['filename'],
                    $asset['profile'] !== null ? (string)$asset['profile'] : null,
                    $asset['bundle_key'] !== null ? (string)$asset['bundle_key'] : null,
                    (string)$asset['asset_key']
                );
            } catch (\Throwable $e) {
                $result['warning'] = 'TFTP write succeeded, but the previous asset version could not be retired: '
                    . $e->getMessage();
            }
            return $result;
        } catch (\Throwable $e) {
            return ['warning'=>'TFTP write succeeded, but managed asset inventory failed: ' . $e->getMessage()];
        }
    }

    /** @return array<string,mixed>|null */
    private static function managedAssetMetadata(string $relativePath, string $sha256 = ''): ?array
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $filename = basename($relativePath);
        if ($relativePath === ''
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $relativePath) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/D', $filename) !== 1) {
            return null;
        }
        $contentType = self::managedContentType($filename);
        $common = ['relative_path'=>$relativePath];
        if (preg_match('#^Desktops/([0-9]+x[0-9]+x[0-9]+)/([A-Za-z0-9][A-Za-z0-9_.-]*\.(?:png|xml))$#iD', $relativePath, $match)) {
            $directory = $match[1];
            $base = (string)preg_replace('/(?:-tn)?\.(?:png|xml)$/i', '', $filename);
            $role = strcasecmp($filename, 'List.xml') === 0
                ? 'catalog'
                : (preg_match('/-tn\.png$/i', $filename) ? 'thumbnail' : 'full');
            $bundleKey = 'cisco-bg:' . $directory . ':' . substr(hash('sha256', strtolower($base)), 0, 16);
            if ($role !== 'catalog'
                && preg_match('/^([A-Za-z0-9][A-Za-z0-9_-]{0,39})-\d+x\d+$/D', $base, $setMatch)) {
                $bundleKey = 'cisco-wallpaper:' . strtolower((string)$setMatch[1]);
            }
            return [
                'kind'=>'background',
                'filename'=>$filename,
                'content_type'=>$contentType,
                'vendor'=>'Cisco',
                'family'=>'cisco_xml',
                'profile'=>self::backgroundProfileKeyForDirectory($directory, $filename),
                'bundle_key'=>$bundleKey,
                'bundle_role'=>$role,
            ] + $common;
        }
        if (!str_contains($relativePath, '/') && preg_match('/^(?:Ringlist(?:-wb)?\.xml|[A-Za-z0-9_.-]+\.(?:raw|rwb))$/iD', $filename)) {
            return [
                'kind'=>'ringtone',
                'filename'=>$filename,
                'content_type'=>$contentType,
                'vendor'=>'Cisco',
                'family'=>'cisco_xml',
                'profile'=>'cisco-ringtone',
                'bundle_key'=>preg_match('/(?:-wb\.xml|\.rwb)$/i', $filename) ? 'cisco-ringtones-wideband' : 'cisco-ringtones-standard',
                'bundle_role'=>preg_match('/^Ringlist/i', $filename) ? 'catalog' : 'tone',
            ] + $common;
        }
        $localeProfiles = [];
        $localeRoles = [];
        foreach (ModelCatalog::CISCO_LOCALE_PROFILES as $profileId => $definition) {
            foreach ((array)($definition['files'] ?? []) as $file) {
                $template = trim(str_replace('\\', '/', (string)($file['path'] ?? '')), '/');
                $expected = basename($template);
                if ($expected === '' || strcasecmp($expected, $filename) !== 0) { continue; }
                $matches = str_contains($template, '{user_locale}')
                    ? preg_match('#^[A-Za-z][A-Za-z0-9_-]{0,63}/' . preg_quote($expected, '#') . '$#iD', $relativePath) === 1
                    : (str_contains($template, '{tone_path}')
                        && preg_match('#^(?:[A-Za-z][A-Za-z0-9_-]{0,63}/)?' . preg_quote($expected, '#') . '$#iD', $relativePath) === 1);
                if (!$matches) { continue; }
                $localeProfiles[] = (string)$profileId;
                $localeRoles[] = str_contains($template, '{user_locale}') ? 'user-locale-jar' : 'network-tones';
            }
        }
        $localeProfiles = array_values(array_unique($localeProfiles));
        sort($localeProfiles, SORT_NATURAL | SORT_FLAG_CASE);
        if ($localeProfiles) {
            $localeRole = count(array_unique($localeRoles)) === 1 ? (string)$localeRoles[0] : 'locale-companion';
            return [
                'kind'=>'locale',
                'filename'=>$filename,
                'content_type'=>$contentType,
                'vendor'=>'Cisco',
                'family'=>'cisco_xml',
                'profile'=>count($localeProfiles) === 1
                    ? $localeProfiles[0]
                    : 'cisco:shared-locale',
                'bundle_key'=>'cisco:locale:' . substr(hash('sha256', strtolower($relativePath)), 0, 24),
                'bundle_role'=>$localeRole,
                'metadata'=>['locale_profiles'=>implode(',', $localeProfiles)],
            ] + $common;
        }
        $timezoneRoles = [
            'tzupdater.ver'=>'version',
            'j9-tzdata.jar'=>'j9-payload',
            'tzupdater.jar'=>'java-payload',
            'TzDataCSV.csv'=>'csv-payload',
            'tzdb.dat'=>'database',
        ];
        foreach (ModelCatalog::CISCO_TIMEZONE_PROFILES as $profile=>$definition) {
            $payload = (string)$definition['payload'];
            if (str_contains($relativePath, '/') || AssetFilename::timezonePayload($filename) !== $payload) {
                continue;
            }
            $key = $sha256 === '' ? '' : hash('sha256', $profile . "\0" . $sha256);
            if ($key === '' && preg_match('/-([a-f0-9]{64})\./', $filename, $timezoneMatch)) { $key = $timezoneMatch[1]; }
            if ($sha256 !== '' && AssetFilename::isCompact($filename)
                && !hash_equals(CiscoTimezoneBundleManager::publishedFilename($profile, $key), $filename)) {
                throw new \RuntimeException('Compact timezone filename does not match its full payload identity');
            }
            return [
                'kind'=>'locale',
                'filename'=>$filename,
                'content_type'=>$contentType,
                'vendor'=>'Cisco',
                'family'=>'cisco_xml',
                'profile'=>$profile,
                'bundle_key'=>'cisco-timezone:' . $profile . ':' . ($key ?: $filename),
                'bundle_role'=>(string)(CiscoTimezoneService::FILES[$payload] ?? 'payload'),
                'metadata'=>[
                    'format'=>'timezone-asset',
                    'timezone_profile'=>$profile,
                    'timezone_asset_key'=>$key,
                    'architecture'=>(string)($definition['architecture'] ?? ''),
                    'canonical_payload'=>$payload,
                ],
            ] + $common;
        }
        $timezoneName = null;
        foreach ($timezoneRoles as $candidate => $role) {
            if (strcasecmp($candidate, $filename) === 0) { $timezoneName = $candidate; break; }
        }
        if (!str_contains($relativePath, '/') && $timezoneName !== null) {
            $timezoneProfile = match ($timezoneName) {
                'tzupdater.jar' => 'cisco-79xx-8961-99x1',
                'j9-tzdata.jar' => 'cisco-78xx-88xx',
                'TzDataCSV.csv' => 'cisco-39xx-69xx-894x',
                default => 'cisco-timezone-metadata',
            };
            return [
                'kind'=>'locale',
                'filename'=>$filename,
                'content_type'=>$contentType,
                'vendor'=>'Cisco',
                'family'=>'cisco_xml',
                'profile'=>$timezoneProfile,
                'bundle_key'=>'cisco-timezone:' . $timezoneProfile,
                'bundle_role'=>$timezoneRoles[$timezoneName],
            ] + $common;
        }
        $firmware = FirmwareService::classifyFilename($relativePath);
        if ($firmware !== null) {
            $profile = (string)$firmware['profile'];
            return [
                'kind'=>'firmware',
                'filename'=>$filename,
                'content_type'=>$contentType,
                'vendor'=>(string)$firmware['vendor'],
                'profile'=>$profile,
                'bundle_key'=>'firmware:' . substr(hash('sha256', strtolower($profile . "\0" . dirname($relativePath))), 0, 24),
                'bundle_role'=>(string)$firmware['kind'],
            ] + $common;
        }
        if (preg_match('/\.(?:loads|sbn|sb2|bin|app|jar|dsp|rom|fw)$/iD', $filename)) {
            return [
                'kind'=>'firmware',
                'filename'=>$filename,
                'content_type'=>$contentType,
                'profile'=>null,
                'bundle_key'=>null,
                'bundle_role'=>'dependency',
            ] + $common;
        }
        return null;
    }

    private static function managedContentType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'ver' => 'text/plain',
            'jar' => 'application/java-archive',
            'raw' => 'audio/basic',
            default => 'application/octet-stream',
        };
    }

    private static function backgroundProfileKeyForDirectory(string $directory, string $filename = ''): ?string
    {
        $profiles = ModelCatalog::backgroundProfilesForDirectory($directory);
        if (count($profiles) === 1) { return (string)$profiles[0]['key']; }
        if (preg_match('/-(\d+)x(\d+)(?:-tn)?\.png$/iD', $filename, $match)) {
            foreach ($profiles as $profile) {
                if ((int)$profile['dimensions'][0] === (int)$match[1]
                    && (int)$profile['dimensions'][1] === (int)$match[2]) {
                    return (string)$profile['key'];
                }
            }
        }
        return null;
    }

    /** @return array{0:int,1:int}|null */
    private static function pngDimensions(string $content): ?array
    {
        if (strlen($content) < 24 || !str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $dimensions = unpack('Nwidth/Nheight', substr($content, 16, 8));
        if (!is_array($dimensions) || (int)$dimensions['width'] < 1 || (int)$dimensions['height'] < 1) {
            return null;
        }
        return [(int)$dimensions['width'], (int)$dimensions['height']];
    }

    /** @param mixed $value @return array<string,mixed> */
    private static function jsonObject($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Expected a JSON object');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function assetTarget(array $request): array
    {
        $target = [];
        foreach (['kind','vendor','family','profile','model','role'] as $field) {
            if (isset($request[$field]) && trim((string)$request[$field]) !== '') {
                $target[$field] = trim((string)$request[$field]);
            }
        }
        if (!isset($target['kind'])) {
            throw new \InvalidArgumentException('Choose an asset kind');
        }
        if (isset($request['timezone_binding']) && $request['timezone_binding'] !== '') {
            $target['timezone_binding'] = self::jsonObject($request['timezone_binding']);
        }
        return $target;
    }

    /** @param object $sftp */
    private function ensureRemoteDirectory($sftp, string $path): void
    {
        if (!$this->remoteExists($sftp, $path)
            && !$sftp->mkdir($path, -1, true)
            && !$this->remoteExists($sftp, $path)) {
            throw new \RuntimeException("Could not create transaction directory $path");
        }
        $sftp->chmod($this->isPrivateTftpPath($path)
            ? self::PRIVATE_DIRECTORY_MODE
            : self::PUBLIC_DIRECTORY_MODE, $path);
    }

    /** @param object $sftp */
    private function remoteExists($sftp, string $path): bool
    {
        if (method_exists($sftp, 'file_exists')) {
            return (bool)$sftp->file_exists($path);
        }
        return $sftp->stat($path) !== false;
    }

    /** @param object $sftp @param array<string,mixed> $payload */
    private function writeRemoteJson($sftp, string $path, array $payload): void
    {
        if (str_ends_with($path, '/receipt.json') || str_ends_with($path, '/current.json')) {
            $content = self::remoteJsonContent($payload);
            $tmp = $path . '.intent-' . bin2hex(random_bytes(8));
            try {
                if (!$sftp->put($tmp, $content) || !$sftp->chmod(0660, $tmp)
                    || !hash_equals(hash('sha256', $content), $this->remotePayloadHash($sftp, $tmp))) {
                    throw new \RuntimeException('Could not durably stage transaction metadata');
                }
                $exists = $this->remoteExists($sftp, $path);
                if ($exists && !method_exists($sftp, 'posix_rename')) {
                    throw new \RuntimeException('Transaction recovery requires atomic SFTP metadata replacement');
                }
                if (!($exists ? $sftp->posix_rename($tmp, $path) : $sftp->rename($tmp, $path))) {
                    throw new \RuntimeException('Could not atomically publish transaction metadata');
                }
            } finally { if ($this->remoteExists($sftp, $tmp)) { $sftp->delete($tmp); } }
            return;
        }
        $this->writeRemoteFile($sftp, $path, self::remoteJsonContent($payload), 0660);
    }

    /** @param array<string,mixed> $payload */
    private static function remoteJsonContent(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * @param object $sftp
     * @return array<string,mixed>
     */
    private function readRemoteJson($sftp, string $path, bool $required = true): array
    {
        if (!$this->remoteExists($sftp, $path)) {
            if ($required) {
                throw new \RuntimeException("Missing transaction record $path");
            }
            return [];
        }
        $read = $this->readBounded($sftp, $path, self::MAX_TEXT_READ_BYTES, 'transaction record');
        if (empty($read['ok'])) {
            throw new \RuntimeException("Invalid transaction record $path");
        }
        $raw = (string)$read['content'];
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Invalid JSON transaction record $path", 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid transaction record $path");
        }
        return $decoded;
    }

    /**
     * Root-level generated files owned by the active build manifest.
     *
     * @param object $sftp
     * @return array<string,array<string,mixed>>
     */
    private function activeGenerationFiles($sftp, string $root): array
    {
        $pointer = $this->readRemoteJson(
            $sftp,
            $root . '/.ucmprovisioner/current.json',
            false
        );
        if (!$pointer) {
            return [];
        }
        $generation = trim((string)($pointer['generation_id'] ?? ''));
        BuildPlanner::assertTransactionId($generation);
        $manifest = $this->readRemoteJson(
            $sftp,
            $root . '/.ucmprovisioner/transactions/' . $generation . '/manifest.json'
        );
        if (!hash_equals($generation, (string)($manifest['transaction_id'] ?? ''))) {
            throw new \RuntimeException('Active generation pointer and manifest do not match');
        }
        $files = [];
        foreach ((array)($manifest['files'] ?? []) as $row) {
            $filename = (string)($row['filename'] ?? '');
            BuildPlanner::assertFilename($filename);
            $files[$filename] = (array)$row;
        }
        return $files;
    }

    /**
     * @param object $sftp
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function rollbackRemoteReceipt(
        $sftp,
        string $root,
        string $txRoot,
        array $receipt
    ): array {
        $pointerPath = $root . '/.ucmprovisioner/current.json';
        $pointer = $this->readRemoteJson($sftp, $pointerPath, false);
        $currentId = (string)($pointer['generation_id'] ?? '');
        $previousId = (string)($receipt['previous_pointer']['generation_id'] ?? '');
        $assetCopy = ($receipt['kind'] ?? '') === 'asset_copy';
        if (!$assetCopy && $currentId !== (string)$receipt['transaction_id'] && $currentId !== $previousId) {
            throw new \RuntimeException('Rollback refused: another generation owns the destination');
        }
        $receipt['state'] = 'rolling_back';
        $receiptPath = $txRoot . '/receipt.json';
        $this->writeRemoteJson($sftp, $receiptPath, $receipt);
        $errors = [];
        $rows = array_reverse((array)($receipt['files'] ?? []), true);
        foreach ($rows as $index => $row) {
            $name = (string)($row['filename'] ?? '');
            try {
                if ($assetCopy) {
                    if ($name === '' || $this->sanitizeRel($name) !== $name) { throw new \RuntimeException('Invalid copy receipt path'); }
                } else { BuildPlanner::assertFilename($name); }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            try {
                $target = $root . '/' . $name;
                $backup = $txRoot . '/backup/' . $name;
                $liveHash = $this->remoteExists($sftp, $target) ? $this->remotePayloadHash($sftp, $target) : null;
                $oldHash = $row['previous_sha256'] ?? null;
                if (!array_key_exists('previous_sha256', $row)) {
                    throw new \RuntimeException("Legacy receipt lacks verified recovery ownership for $name");
                }
                // A consumed backup is safe on retry only when the restored bytes match.
                if ($liveHash !== $oldHash) {
                    if ($liveHash !== null && !hash_equals((string)$row['sha256'], $liveHash)) {
                        throw new \RuntimeException("Rollback refused: $name was changed outside this transaction");
                    }
                    if ($oldHash !== null && (!$this->remoteExists($sftp, $backup)
                        || !hash_equals($oldHash, $this->remotePayloadHash($sftp, $backup)))) {
                        throw new \RuntimeException("Verified previous copy is missing for $name");
                    }
                    $receipt['files'][$index]['intent'] = 'restore';
                    $this->writeRemoteJson($sftp, $receiptPath, $receipt);
                    if ($liveHash !== null && !$sftp->delete($target)) {
                        throw new \RuntimeException("Could not remove activated $name during rollback");
                    }
                    if ($oldHash !== null && !$sftp->rename($backup, $target)) {
                        throw new \RuntimeException("Could not restore previous $name");
                    }
                }
                $restoredHash = $this->remoteExists($sftp, $target) ? $this->remotePayloadHash($sftp, $target) : null;
                if ($restoredHash !== $oldHash) { throw new \RuntimeException("Restored hash mismatch for $name"); }
                $receipt['files'][$index]['restored'] = true;
                $receipt['files'][$index]['intent'] = 'restored';
                $this->writeRemoteJson($sftp, $receiptPath, $receipt);
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }

        try {
            if ($assetCopy) {
                // Asset copies do not own the phone-generation pointer.
            } elseif ($errors) {
                // Keep the active pointer until every prior file has been verified.
                $pointer['recovery_transaction'] = $receipt['transaction_id'];
                $this->writeRemoteJson($sftp, $pointerPath, $pointer);
            } elseif (!empty($receipt['previous_pointer']) && is_array($receipt['previous_pointer'])) {
                $this->writeRemoteJson($sftp, $pointerPath, $receipt['previous_pointer']);
            } elseif ($this->remoteExists($sftp, $pointerPath)) {
                if (!$sftp->delete($pointerPath)) { throw new \RuntimeException('Could not remove generation pointer'); }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Could not restore the previous generation pointer: ' . $e->getMessage();
        }

        $receipt['state'] = $errors ? 'rollback_failed' : 'rolled_back';
        $receipt['rolled_back_at'] = time();
        $receipt['rollback_errors'] = $errors;
        try {
            $this->writeRemoteJson($sftp, $receiptPath, $receipt);
        } catch (\Throwable $e) {
            $errors[] = 'Could not finalize rollback receipt: ' . $e->getMessage();
        }
        return [
            'ok' => !$errors,
            'transaction_id' => (string)($receipt['transaction_id'] ?? ''),
            'restored' => count($rows) - count($errors),
            'message' => $errors
                ? implode('; ', $errors)
                : 'Previous TFTP generation restored',
        ];
    }

    private function remotePayloadHash($sftp, string $path): string
    {
        if (method_exists($sftp, 'clearStatCache')) { $sftp->clearStatCache(); }
        $metadata = method_exists($sftp, 'lstat') ? $sftp->lstat($path) : $sftp->stat($path);
        $bytes = (int)($metadata['size'] ?? -1);
        if (!is_array($metadata) || (int)($metadata['type'] ?? 0) !== 1 || $bytes < 0 || $bytes > self::MAX_SCAN_FILE_BYTES) {
            throw new \RuntimeException('Hash verification requires a bounded regular payload');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ucmp-hash-');
        if ($tmp === false) { throw new \RuntimeException('Could not allocate hash verification file'); }
        try {
            if (!$sftp->get($path, $tmp, 0, $bytes + 1)) { throw new \RuntimeException("Could not read payload $path"); }
            clearstatcache(true, $tmp);
            if (filesize($tmp) !== $bytes) { throw new \RuntimeException('Payload changed size during hash verification'); }
            $hash = hash_file('sha256', $tmp);
            if (!is_string($hash)) { throw new \RuntimeException('Could not hash payload'); }
            return $hash;
        } finally { @unlink($tmp); }
    }

    private function verifyRemotePayload($sftp, string $path, array $file): void
    {
        $expected = (int)($file['bytes'] ?? -1);
        $metadata = method_exists($sftp, 'lstat') ? $sftp->lstat($path) : $sftp->stat($path);
        if ($expected < 0 || $expected > self::MAX_SCAN_FILE_BYTES || !is_array($metadata)
            || (int)($metadata['type'] ?? 0) !== 1 || (int)($metadata['size'] ?? -1) !== $expected
            || (isset($file['mode']) && (!isset($metadata['mode']) || ((int)$metadata['mode'] & 0777) !== ((int)$file['mode'] & 0777)))) {
            throw new \RuntimeException('Payload size/type verification failed for ' . basename($path));
        }
        $hash = hash_init('sha256');
        for ($offset = 0; $offset < $expected;) {
            $length = min(65536, $expected - $offset);
            $chunk = $sftp->get($path, false, $offset, $length);
            if (!is_string($chunk) || strlen($chunk) !== $length) { throw new \RuntimeException('Payload read verification failed for ' . basename($path)); }
            hash_update($hash, $chunk); $offset += $length;
        }
        if ($sftp->get($path, false, $expected, 1) !== ''
            || !hash_equals((string)($file['sha256'] ?? ''), hash_final($hash))) {
            throw new \RuntimeException('Payload verification failed for ' . basename($path));
        }
    }

    /** Validate and normalize the small operator-declared vendor compatibility sidecar. */
    private function validateFirmwareDeclaration(string $path, string $json): string
    {
        if ($json === '' || strlen($json) > 16384) {
            throw new \InvalidArgumentException('Firmware compatibility metadata is missing or too large');
        }
        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw new \InvalidArgumentException('Firmware compatibility metadata is not valid JSON');
        }
        $vendor = strtolower(trim((string)($manifest['vendor'] ?? '')));
        if (!in_array($vendor, ['yealink', 'grandstream'], true)
            || !str_starts_with(strtolower($path), 'firmware/' . $vendor . '/')) {
            throw new \InvalidArgumentException('Firmware compatibility vendor does not match its upload path');
        }
        if (!hash_equals(basename($path), basename((string)($manifest['file'] ?? '')))) {
            throw new \InvalidArgumentException('Firmware compatibility filename does not match the uploaded binary');
        }
        $models = [];
        foreach ((array)($manifest['models'] ?? []) as $model) {
            $model = trim((string)$model);
            if ($model === '' || strlen($model) > 80 || preg_match('/[\x00-\x1F\x7F]/', $model)) {
                throw new \InvalidArgumentException('Firmware compatibility contains an invalid model name');
            }
            $models[$model] = true;
        }
        if (!$models || count($models) > 100) {
            throw new \InvalidArgumentException('Firmware compatibility must name between 1 and 100 models');
        }
        return (string)json_encode([
            'version'=>1,
            'vendor'=>ucfirst($vendor),
            'file'=>basename($path),
            'models'=>array_keys($models),
            'declared_at'=>time(),
            'compatibility'=>'user_declared',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Replace a vendor binary and its declaration as one recoverable pair.
     * Old files are moved, never deleted, until both staged replacements are active.
     */
    private function installDeclaredFirmware(
        $sftp,
        string $firmwarePath,
        string $localUpload,
        string $declaration
    ): bool {
        $declarationPath = dirname($firmwarePath) . '/' . FirmwareService::declarationFilename(basename($firmwarePath));
        if ($sftp->file_exists($declarationPath)) {
            $current = $this->readRemoteJson($sftp, $declarationPath, true);
            if (($current['file'] ?? '') !== basename($firmwarePath)) {
                throw new \RuntimeException('Compact firmware declaration belongs to different firmware');
            }
        }
        $this->replaceRemoteFiles($sftp, [
            $firmwarePath=>['local_path'=>$localUpload, 'bytes'=>(int)filesize($localUpload),
                'sha256'=>(string)hash_file('sha256',$localUpload), 'mode'=>0664],
            $declarationPath=>['content'=>$declaration, 'mode'=>0664],
        ]);
        return true;
    }

    /**
     * Protect a destination-edited synchronized catalog without coupling the
     * TFTP store to the scheduler implementation. New unmanaged catalogs have
     * no matching ownership row and therefore pass straight through.
     */
    private function synchronizedCatalogMutation(
        int $siteId,
        string $objectType,
        string $objectKey,
        array $request,
        callable $operation
    ): array {
        $items = [];
        if ($siteId === $this->ucm->localSiteId()
            && method_exists($this->ucm, 'configurationSyncCatalogEditGuard')) {
            $guard = (array)$this->ucm->configurationSyncCatalogEditGuard($objectType, $objectKey, $request);
            if (empty($guard['ok'])) { return $guard; }
            $items = (array)($guard['items'] ?? []);
        }
        $result = (array)$operation();
        $changed = !empty($result['ok']) || !empty($result['written']) || !empty($result['deleted']);
        if ($changed && $items && method_exists($this->ucm, 'configurationSyncCatalogEditPause')) {
            try {
                $this->ucm->configurationSyncCatalogEditPause($items, $request);
                $result['sync_paused'] = true;
                $result['sync_message'] = 'Automatic synchronization for this catalog was paused after the confirmed destination edit';
            } catch (\Throwable $error) {
                $result['operation_ok'] = !empty($result['ok']);
                $result['ok'] = false;
                $result['recovery_required'] = true;
                $result['sync_paused'] = false;
                $result['sync_pause_error'] = $error->getMessage();
                $result['message'] = trim((string)($result['message'] ?? '') . ' Catalog changes were retained, but pausing automatic synchronization failed; reconcile its ownership record before another write.');
            }
        }
        return $result;
    }

    /** Prevent path traversal outside the TFTP root. */
    private function sanitizeRel(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path, '/');
        if (strpos($path, '..') !== false || preg_match('/[\x00-\x1f\x7f:]/', $path)) {
            throw new \InvalidArgumentException('Invalid path');
        }
        return implode('/', array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== '' && $part !== '.')));
    }

    /** Convert PHP shorthand sizes such as 256M to bytes. Zero means unlimited/unknown. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') { return 0; }
        $bytes = (float) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g': $bytes *= 1024;
            case 'm': $bytes *= 1024;
            case 'k': $bytes *= 1024;
        }
        return max(0, (int) $bytes);
    }
}
