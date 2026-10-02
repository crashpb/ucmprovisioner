<?php
/**
 * Coordinates a site TFTP architecture change without teaching every
 * provisioning feature about storage topology.
 *
 * The source remains active while this service inventories, copies and hashes
 * every file. Only a fully verified target is activated. Source files are never
 * deleted, so a failed or interrupted transfer is safe to retry.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

use phpseclib3\Net\SFTP;

final class TftpArchitectureService
{
    private const MAX_ENTRIES = 50000;
    private const MAX_DEPTH = 64;

    private object $ucm;

    public function __construct(object $ucm)
    {
        $this->ucm = $ucm;
    }

    /** Show the exact source and target trees that a cutover would use. */
    public function preview(int $siteId): array
    {
        [$site, $sourceMode, $targetMode, $connection] = $this->context($siteId);
        foreach ([$sourceMode,$targetMode] as $mode) {
            if ($connection->tftpSftp($mode)->stat($this->join($connection->tftpRoot($mode), '.ucmprovisioner/write-lock')) !== false) {
                throw new \RuntimeException('TFTP cutover requires both backends to be unlocked; reconcile active or interrupted writers first');
            }
        }
        $source = $this->inventory($connection->tftpSftp($sourceMode), $connection->tftpRoot($sourceMode));
        $target = $this->inventory($connection->tftpSftp($targetMode), $connection->tftpRoot($targetMode));
        $sourcePaths = array_column($source['entries'], 'path');
        $targetPaths = array_column($target['entries'], 'path');
        $targetOnly = array_values(array_diff($targetPaths, $sourcePaths));
        $planHash = $this->planHash($siteId, $sourceMode, $targetMode, $source['entries']);

        return [
            'ok'=>true,
            'site_id'=>$siteId,
            'site_name'=>(string)($site['name'] ?? ('site #' . $siteId)),
            'source_mode'=>$sourceMode,
            'target_mode'=>$targetMode,
            'files'=>$source['files'],
            'directories'=>$source['directories'],
            'bytes'=>$source['bytes'],
            'target_files'=>$target['files'],
            'target_only_count'=>count($targetOnly),
            'target_only_sample'=>array_slice($targetOnly, 0, 12),
            'plan_hash'=>$planHash,
            'message'=>sprintf(
                'Ready to copy %d file(s), %d director%s and %s from %s to %s. The source remains active until every copied file passes SHA-256 verification.',
                $source['files'],
                $source['directories'],
                $source['directories'] === 1 ? 'y' : 'ies',
                $this->formatBytes($source['bytes']),
                strtoupper($sourceMode),
                strtoupper($targetMode)
            ),
        ];
    }

    /** Copy and verify the previewed tree, then activate the target architecture. */
    public function apply(int $siteId, string $expectedPlanHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedPlanHash)) {
            throw new \InvalidArgumentException('Preview the TFTP cutover before applying it');
        }
        [, $sourceMode, $targetMode] = $this->context($siteId);
        $context = ['operation'=>'tftp-cutover','source_mode'=>$sourceMode,'target_mode'=>$targetMode,'plan_hash'=>$expectedPlanHash];
        $store = new TftpStore($this->ucm);
        // Fixed backend order; both captured connections stay locked through activation.
        return $store->withSiteLock($siteId, fn() => $store->withSiteLock(
            $siteId, fn() => $this->applyLocked($siteId, $expectedPlanHash), 'pbx', $context
        ), 'docker', $context);
    }

    private function applyLocked(int $siteId, string $expectedPlanHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedPlanHash)) {
            throw new \InvalidArgumentException('Preview the TFTP cutover before applying it');
        }
        [$site, $sourceMode, $targetMode, $connection] = $this->context($siteId);
        $sourceSftp = $connection->tftpSftp($sourceMode);
        $targetSftp = $connection->tftpSftp($targetMode);
        $sourceRoot = $connection->tftpRoot($sourceMode);
        $targetRoot = $connection->tftpRoot($targetMode);
        $source = $this->inventory($sourceSftp, $sourceRoot);
        $actualPlanHash = $this->planHash($siteId, $sourceMode, $targetMode, $source['entries']);
        if (!hash_equals($expectedPlanHash, $actualPlanHash)) {
            throw new \RuntimeException('The active TFTP tree changed after preview; review the refreshed cutover plan before applying it');
        }

        $copied = 0;
        $skipped = 0;
        $verifiedBytes = 0;
        foreach ($source['entries'] as $entry) {
            $relative = (string)$entry['path'];
            $sourcePath = $this->join($sourceRoot, $relative);
            $targetPath = $this->join($targetRoot, $relative);
            if ($entry['kind'] === 'directory') {
                $this->ensureDirectory($targetSftp, $targetPath, $this->isPrivatePath($relative) ? 0770 : 0775);
                continue;
            }
            $this->ensureDirectory(
                $targetSftp,
                dirname($targetPath),
                $this->isPrivatePath(dirname($relative)) ? 0770 : 0775
            );
            [$changed, $bytes] = $this->copyVerified($sourceSftp, $sourcePath, $targetSftp, $targetPath, (string)$entry['sha256']);
            $changed ? $copied++ : $skipped++;
            $verifiedBytes += $bytes;
            if (!$targetSftp->chmod($this->isPrivatePath($relative) ? 0660 : 0664, $targetPath)) {
                throw new \RuntimeException("Copied $relative but could not apply its managed permissions");
            }
        }

        // A phone rebuild or asset publication may have started while a large
        // tree was being copied. Never activate a mixed-generation target.
        $finalSource = $this->inventory($sourceSftp, $sourceRoot);
        $finalPlanHash = $this->planHash($siteId, $sourceMode, $targetMode, $finalSource['entries']);
        if (!hash_equals($actualPlanHash, $finalPlanHash)) {
            throw new \RuntimeException('The active TFTP tree changed during copy; the original backend remains active. Preview and retry after the current build or replication finishes');
        }

        // Keep both backend locks if activation is uncertain; never reverse or replay it.
        try {
            if (!$this->ucm->isSiteLocal($siteId)) {
                $result = $this->ucm->remoteQuery($siteId, 'activateTftpArchitectureLocal', [$targetMode, $sourceMode]);
                if (empty($result['ok'])) {
                    throw new \RuntimeException('Files verified, but remote TFTP activation was not confirmed: ' . (string)($result['message'] ?? 'unknown error'));
                }
            }
            $this->activateControllerRow($siteId, $targetMode, $sourceMode);
        } catch (\Throwable $error) {
            return ['ok'=>false,'status'=>'recovery_required','recovery_required'=>true,
                'site_id'=>$siteId,'source_mode'=>$sourceMode,'target_mode'=>$targetMode,'plan_hash'=>$expectedPlanHash,
                'operation_id'=>$error instanceof RpcOperationException ? ($error->receipt['operation_id'] ?? null) : null,
                'message'=>'Cutover activation requires reconciliation; both backend locks were retained. ' . $error->getMessage()];
        }

        $warnings = [];
        try {
            $this->ucm->resetSiteConnection($siteId);
            $this->ucm->logOperation(
            $siteId,
            'tftp-cutover',
            'success',
            sprintf('%s activated after %d copied and %d already-matching file(s)', strtoupper($targetMode), $copied, $skipped)
            );
        } catch (\Throwable $error) {
            $warnings[] = 'TFTP activation completed, but refreshing or logging its result failed: ' . $error->getMessage();
        }
        return [
            'ok'=>true,
            'status'=>$warnings ? 'warning' : 'success',
            'warnings'=>$warnings,
            'active_mode'=>$targetMode,
            'copied'=>$copied,
            'skipped'=>$skipped,
            'verified_bytes'=>$verifiedBytes,
            'message'=>sprintf(
                '%s TFTP is now active. %d file(s) were copied, %d already matched, and %s passed SHA-256 verification. The %s source tree was retained.',
                strtoupper($targetMode),
                $copied,
                $skipped,
                $this->formatBytes($verifiedBytes),
                strtoupper($sourceMode)
            ),
        ];
    }

    /** @return array{0:array,1:string,2:string,3:object} */
    private function context(int $siteId): array
    {
        $site = $this->ucm->getSite($siteId, true);
        if (!$site) { throw new \InvalidArgumentException('Choose a saved site'); }
        $sourceMode = SiteConnection::activeTftpMode($site);
        $targetMode = (string)($site['tftp_pending_mode'] ?? '');
        if (!in_array($targetMode, ['docker', 'pbx'], true) || $targetMode === $sourceMode) {
            throw new \RuntimeException('Choose and save a different TFTP architecture before previewing cutover');
        }
        $readyField = $targetMode === 'pbx' ? 'pbx_tftp_bootstrapped_at' : 'docker_bootstrapped_at';
        if (empty($site[$readyField])) {
            throw new \RuntimeException('Bootstrap or repair the target TFTP architecture before cutover');
        }
        return [$site, $sourceMode, $targetMode, $this->ucm->siteConnection($siteId)];
    }

    /** @return array{entries:array<int,array{path:string,kind:string,size:int,sha256:string}>,files:int,directories:int,bytes:int} */
    private function inventory(object $sftp, string $root): array
    {
        if (!$sftp->is_dir($root)) {
            throw new \RuntimeException("Managed TFTP root $root is not accessible");
        }
        $entries = [];
        $this->walk($sftp, $root, '', 0, $entries);
        usort($entries, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $files = 0;
        $directories = 0;
        $bytes = 0;
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'directory') { $directories++; }
            else { $files++; $bytes += (int)$entry['size']; }
        }
        return compact('entries', 'files', 'directories', 'bytes');
    }

    private function walk(object $sftp, string $root, string $relative, int $depth, array &$entries): void
    {
        if ($depth > self::MAX_DEPTH) { throw new \RuntimeException('TFTP tree exceeds the supported directory depth'); }
        $directory = $relative === '' ? $root : $this->join($root, $relative);
        $names = $sftp->nlist($directory);
        if (!is_array($names)) { throw new \RuntimeException("Cannot list TFTP directory $directory"); }
        foreach ($names as $name) {
            $name = basename(str_replace('\\', '/', (string)$name));
            if ($name === '' || $name === '.' || $name === '..') { continue; }
            if (str_contains($name, '/') || str_contains($name, "\0")) {
                throw new \RuntimeException('TFTP inventory returned an unsafe path component');
            }
            $childRelative = ltrim($relative . '/' . $name, '/');
            if ($childRelative === '.ucmprovisioner/write-lock') { continue; }
            $child = $this->join($root, $childRelative);
            if (method_exists($sftp, 'is_link') && $sftp->is_link($child)) {
                throw new \RuntimeException("Refusing symbolic link in managed TFTP tree: $childRelative");
            }
            $isDirectory = (bool)$sftp->is_dir($child);
            $stat = $sftp->stat($child);
            if (!is_array($stat)) { throw new \RuntimeException("TFTP inventory entry disappeared: $childRelative"); }
            // Acquiring a lock may create this parent; it is not portable content.
            if ($childRelative === '.ucmprovisioner' && $isDirectory) {
                $this->walk($sftp, $root, $childRelative, $depth + 1, $entries);
                continue;
            }
            $entries[] = [
                'path'=>$childRelative,
                'kind'=>$isDirectory ? 'directory' : 'file',
                'size'=>$isDirectory ? 0 : max(0, (int)($stat['size'] ?? 0)),
                'sha256'=>$isDirectory ? '' : $this->fileHash($sftp, $child),
            ];
            if (count($entries) > self::MAX_ENTRIES) {
                throw new \RuntimeException('TFTP tree exceeds the 50,000-entry cutover safety limit');
            }
            if ($isDirectory) { $this->walk($sftp, $root, $childRelative, $depth + 1, $entries); }
        }
    }

    private function copyVerified(object $source, string $sourcePath, object $target, string $targetPath, string $expectedHash): array
    {
        $sourceTemp = tempnam(sys_get_temp_dir(), 'ucmp-tftp-source-');
        $targetTemp = tempnam(sys_get_temp_dir(), 'ucmp-tftp-target-');
        if ($sourceTemp === false || $targetTemp === false) {
            throw new \RuntimeException('Could not allocate a temporary verification file');
        }
        try {
            if (!$source->get($sourcePath, $sourceTemp)) {
                throw new \RuntimeException("Could not read $sourcePath from the active TFTP backend");
            }
            $sourceHash = hash_file('sha256', $sourceTemp);
            if (!hash_equals($expectedHash, $sourceHash)) { throw new \RuntimeException('Source content changed after cutover preview: ' . $sourcePath); }
            $bytes = (int)filesize($sourceTemp);
            $changed = true;
            if ($target->file_exists($targetPath) && $target->get($targetPath, $targetTemp)) {
                $changed = !hash_equals($sourceHash, hash_file('sha256', $targetTemp));
            }
            if ($changed && !$target->put($targetPath, $sourceTemp, SFTP::SOURCE_LOCAL_FILE)) {
                throw new \RuntimeException("Could not write $targetPath to the target TFTP backend");
            }
            if (!$target->get($targetPath, $targetTemp)
                || !hash_equals($sourceHash, hash_file('sha256', $targetTemp))) {
                throw new \RuntimeException("SHA-256 verification failed after copying $targetPath");
            }
            return [$changed, $bytes];
        } finally {
            @unlink($sourceTemp);
            @unlink($targetTemp);
        }
    }

    private function ensureDirectory(object $sftp, string $path, int $mode): void
    {
        if ($path === '/' || $path === '.' || $sftp->is_dir($path)) { return; }
        if (!$sftp->mkdir($path, $mode, true) && !$sftp->is_dir($path)) {
            throw new \RuntimeException("Could not create target TFTP directory $path");
        }
        if (!$sftp->chmod($mode, $path)) {
            throw new \RuntimeException("Could not apply managed permissions to target directory $path");
        }
    }

    private function activateControllerRow(int $siteId, string $targetMode, string $expectedSource): void
    {
        $stmt = $this->ucm->getDb()->prepare(
            'UPDATE ucmp_sites SET tftp_mode=?, tftp_pending_mode=NULL WHERE id=? AND tftp_mode=? AND tftp_pending_mode=?'
        );
        $stmt->execute([$targetMode, $siteId, $expectedSource, $targetMode]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('The site TFTP architecture changed during cutover; activation was cancelled');
        }
    }

    private function planHash(int $siteId, string $source, string $target, array $entries): string
    {
        $site = $this->ucm->getSite($siteId, true) ?: [];
        $endpoints = array_intersect_key($site, array_flip([
            'pbx_host','pbx_addr','pbx_ssh_port','pbx_host_key','pbx_tftp_root_path',
            'docker_host','docker_ssh_port','docker_host_key','tftp_root_path','tftp_mode','tftp_pending_mode',
        ]));
        ksort($endpoints);
        return hash('sha256', json_encode(
            ['schema'=>2, 'site_id'=>$siteId, 'source'=>$source, 'target'=>$target, 'endpoints'=>$endpoints, 'entries'=>$entries],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function fileHash(object $sftp, string $path): string
    {
        $context = hash_init('sha256');
        if (!$sftp->get($path, static function (string $chunk) use ($context): void { hash_update($context, $chunk); })) {
            throw new \RuntimeException('Could not hash TFTP cutover source: ' . $path);
        }
        return hash_final($context);
    }

    private function join(string $root, string $relative): string
    {
        $root = rtrim($root, '/');
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        return ($root === '' ? '' : $root) . ($relative === '' ? '' : '/' . $relative);
    }

    private function isPrivatePath(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        return $relative === '.ucmprovisioner' || str_starts_with($relative, '.ucmprovisioner/');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) { return $bytes . ' B'; }
        if ($bytes < 1048576) { return number_format($bytes / 1024, 1) . ' KiB'; }
        if ($bytes < 1073741824) { return number_format($bytes / 1048576, 1) . ' MiB'; }
        return number_format($bytes / 1073741824, 2) . ' GiB';
    }
}
