<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;
require_once __DIR__ . '/ApiFileStream.php';

/** Destination operations share TftpStore's checked storage, inventory and rollback boundary. */
trait TftpTransferOperations
{
    public function transfer(int $siteId, array $request): array
    {
        $op = (string)($request['op'] ?? '');
        if ($op === 'capabilities') { return ['ok'=>true,'protocol'=>1,'file_stream'=>ApiFileStream::available() ? 1 : 0,'file_resume_bytes'=>ApiFileStream::available() ? ApiFileStream::RESUME_BYTES : 0,'chunk_bytes'=>262144,'upload_batch_bytes'=>1048576,'upload_batch_chunks'=>64,'max_file_bytes'=>self::MAX_SCAN_FILE_BYTES]; }
        if (!in_array($op, ['manifest','download','prepare','upload','upload_batch','upload_file','commit','status','indexes'], true)) {
            throw new \InvalidArgumentException('Unknown TFTP transfer operation');
        }
        $conn = $this->ucm->siteConnection($siteId); $sftp = $conn->dockerSftp();
        $root = rtrim($conn->tftpRoot(), '/');
        if ($root === '' || $root === '/') { throw new \RuntimeException('TFTP transfer root is unsafe'); }
        if ($op === 'manifest') {
            $paths = $request['paths'] ?? [];
            if (!is_array($paths) || !$paths || count($paths) > 500 || array_filter($paths, static fn($path): bool => !is_string($path))) {
                throw new \InvalidArgumentException('Choose between one and five hundred managed file paths');
            }
            $paths = array_values(array_unique($paths));
            $files = []; $total = 0;
            foreach ($paths as $path) {
                $path = $this->transferPath((string)$path);
                $before = $this->transferStat($sftp, $root, $path);
                if ($before === null) { throw new \RuntimeException('Source managed file is missing'); }
                $hash = $this->remotePayloadHash($sftp, $root . '/' . $path);
                if ($this->transferStat($sftp, $root, $path) !== $before) { throw new \RuntimeException('Source changed while preparing its manifest'); }
                $total += $before['size'];
                if ($total > 2147483648) { throw new \InvalidArgumentException('Transfer exceeds the 2 GiB batch limit'); }
                $files[] = ['path'=>$path,'size'=>$before['size'],'sha256'=>$hash];
            }
            return ['ok'=>true,'files'=>$files];
        }
        if ($op === 'download') {
            $file = $this->transferManifest([(array)($request['file'] ?? [])])[0];
            $offset = filter_var($request['offset'] ?? null, FILTER_VALIDATE_INT);
            $stat = $this->transferStat($sftp, $root, $file['path']);
            if ($offset === false || $offset < 0 || $offset >= $file['size'] || ($stat['size'] ?? -1) !== $file['size']) {
                throw new \RuntimeException('Source file or chunk bounds changed');
            }
            $length = min(262144, $file['size'] - $offset);
            $bytes = $sftp->get($root . '/' . $file['path'], false, $offset, $length);
            if (!is_string($bytes) || strlen($bytes) !== $length) { throw new \RuntimeException('Cannot read complete source chunk'); }
            return ['ok'=>true,'content'=>base64_encode($bytes),'bytes'=>$length];
        }
        if ($op === 'indexes') {
            return $this->withSiteLock($siteId, function () use ($siteId, $sftp, $root, $request): array {
                $directories = $request['directories'] ?? []; $ringtones = $request['ringtones'] ?? [];
                if (!is_array($directories) || count($directories) > 100 || !is_array($ringtones) || count($ringtones) > 2) {
                    throw new \InvalidArgumentException('Too many or invalid destination indexes');
                }
                foreach ($directories as $directory) {
                    if (!in_array($directory, array_values(ModelCatalog::BACKGROUND_DIRS), true)) { throw new \InvalidArgumentException('Unknown wallpaper directory'); }
                }
                foreach ($ringtones as $wideband) {
                    if (!is_bool($wideband)) { throw new \InvalidArgumentException('Invalid ringtone index'); }
                }
                $labels = $request['ringtone_labels'] ?? [];
                if (!is_array($labels) || count($labels) > 200) { throw new \InvalidArgumentException('Invalid ringtone labels'); }
                foreach ($labels as $file=>$label) {
                    if (!is_string($file) || !preg_match('/^[A-Za-z0-9_.-]{1,25}\.(?:raw|rwb)$/iD', $file)
                        || !is_string($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') > 25
                        || preg_match('/[\x00-\x1F\x7F]/', $label)) { throw new \InvalidArgumentException('Invalid ringtone label'); }
                }
                $results = [];
                foreach (array_unique($directories) as $directory) {
                    $results[$directory] = $this->regenerateImageList($siteId, $sftp, $root . '/Desktops/' . $directory, 'Desktops/' . $directory);
                }
                foreach (array_unique($ringtones, SORT_REGULAR) as $wideband) {
                    $results[$wideband ? 'Ringlist-wb.xml' : 'Ringlist.xml'] = $this->regenerateRingList($siteId, $sftp, $root, $wideband, $labels);
                }
                return ['ok'=>!array_filter($results, static fn(array $r): bool => empty($r['ok'])),'indexes'=>$results,'message'=>'Destination indexes evaluated'];
            });
        }
        $id = (string)($request['transfer_id'] ?? '');
        if (!preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/D', $id)) { throw new \InvalidArgumentException('Invalid transfer identity'); }
        $relative = '.ucmprovisioner/copies/' . $id;
        $directory = $root . '/' . $relative; $receiptPath = $directory . '/receipt.json';
        $this->transferStat($sftp, $root, $relative . '/receipt.json');
        if ($op === 'status') {
            $receipt = $this->readRemoteJson($sftp, $receiptPath, false);
            return ['ok'=>true,'transfer_id'=>$id,'state'=>$receipt['state'] ?? 'unknown','result'=>$receipt['result'] ?? null];
        }
        if ($op === 'upload_batch') {
            $chunks = $request['chunks'] ?? null;
            if (!is_array($chunks) || !array_is_list($chunks) || !$chunks || count($chunks) > 64) {
                throw new \InvalidArgumentException('Choose one to sixty-four transfer chunks');
            }
            $total = 0;
            foreach ($chunks as $chunk) {
                if (!is_array($chunk) || !is_string($chunk['content'] ?? null) || strlen($chunk['content']) > 349528) {
                    throw new \InvalidArgumentException('Invalid batch chunk');
                }
                $bytes = base64_decode($chunk['content'], true);
                if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 262144) { throw new \InvalidArgumentException('Invalid batch content'); }
                $this->transferPath((string)($chunk['path'] ?? ''));
                $total += strlen($bytes);
            }
            if ($total > 1048576) { throw new \InvalidArgumentException('Transfer batch exceeds 1 MiB'); }
            return $this->withSiteLock($siteId, function () use ($siteId, $id, $chunks): array {
                $done = 0; $bytes = 0;
                try {
                    foreach ($chunks as $chunk) {
                        $result = $this->transfer($siteId, ['op'=>'upload','transfer_id'=>$id,
                            'path'=>$chunk['path'],'offset'=>$chunk['offset'] ?? null,'content'=>$chunk['content']]);
                        $done++; $bytes += (int)$result['bytes'];
                    }
                    return ['ok'=>true,'transfer_id'=>$id,'chunks'=>$done,'bytes'=>$bytes];
                } catch (\Throwable $error) {
                    return ['ok'=>false,'status'=>'staging_incomplete','transfer_id'=>$id,'staged_chunks'=>$done,
                        'staged_bytes'=>$bytes,'publication_completed'=>false,'message'=>'Private staging is incomplete: ' . $error->getMessage()];
                }
            });
        }
        return $this->withSiteLock($siteId, function () use ($siteId, $request, $op, $sftp, $root, $id, $directory, $receiptPath): array {
            if ($op === 'prepare') {
                $files = $this->transferManifest((array)($request['files'] ?? []));
                $transport = (string)($request['transport'] ?? '');
                if (!in_array($transport, ['api','sftp'], true)) { throw new \InvalidArgumentException('Invalid staged transport'); }
                $identity = ['transfer_id'=>$id,'files'=>$files,'overwrite'=>!empty($request['overwrite']),'transport'=>$transport];
                if (!empty($request['file_stream'])) {
                    if ($transport !== 'api' || !ApiFileStream::available()) { throw new \RuntimeException('Binary API transfers are unavailable'); }
                    $identity['file_stream'] = 1;
                }
                if (!empty($request['file_resume'])) {
                    if (empty($identity['file_stream'])) { throw new \RuntimeException('Resuming requires binary API transport'); }
                    $identity['file_resume'] = 1;
                }
                $identityHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
                $old = $this->readRemoteJson($sftp, $receiptPath, false);
                if ($old) {
                    if (!hash_equals((string)($old['identity_hash'] ?? ''), $identityHash)) { throw new \RuntimeException('Transfer identity belongs to another manifest'); }
                    if (!in_array($old['state'] ?? '', ['receiving','committed'], true)) {
                        return ['ok'=>false,'status'=>'recovery_required','recovery_required'=>true,'recovery_id'=>$id,'publication_completed'=>null,
                            'message'=>'Prior publication requires receipt recovery; no transfer was restarted'];
                    }
                    return ['ok'=>true,'transfer_id'=>$id,'files'=>$old['manifest'],'offsets'=>$old['offsets'] ?? [],'committed'=>($old['state'] ?? '') === 'committed'];
                }
                foreach ($files as &$file) {
                    $state = $this->transferStat($sftp, $root, $file['path']);
                    $file['previous_sha256'] = $state === null ? null : $this->remotePayloadHash($sftp, $root . '/' . $file['path']);
                    $file['skip'] = $file['previous_sha256'] === $file['sha256'];
                    if ($file['previous_sha256'] !== null) {
                        AssetFilename::assertSameContent(basename($file['path']), $file['sha256'], $file['previous_sha256']);
                    }
                    if (!$identity['overwrite'] && $state !== null && !$file['skip']) { throw new \RuntimeException('Target file differs from source; enable overwrite to replace it'); }
                }
                unset($file);
                $this->transferDirectory($sftp, $root, $directory . '/stage');
                $this->transferDirectory($sftp, $root, $directory . '/backup');
                foreach ($files as $file) {
                    if ($file['skip']) { continue; }
                    $stage = $directory . '/stage/' . $file['path'];
                    $this->transferDirectory($sftp, $root, dirname($stage));
                    $this->transferDirectory($sftp, $root, dirname($directory . '/backup/' . $file['path']));
                    $this->transferStat($sftp, $root, substr($stage, strlen($root) + 1));
                    if (!$sftp->put($stage, '') || !$sftp->chmod(0660, $stage)) { throw new \RuntimeException('Cannot prepare private staged payload'); }
                }
                $receipt = ['transaction_id'=>$id,'kind'=>'asset_copy','state'=>'receiving','identity_hash'=>$identityHash,
                    'transport'=>$transport,'manifest'=>$files,'files'=>[],'created'=>time()];
                if (!empty($identity['file_stream'])) { $receipt['file_stream'] = 1; }
                if (!empty($identity['file_resume'])) { $receipt['file_resume'] = 1; }
                $this->writeRemoteJson($sftp, $receiptPath, $receipt);
                return ['ok'=>true,'transfer_id'=>$id,'files'=>$files];
            }
            $receipt = $this->readRemoteJson($sftp, $receiptPath);
            if (($receipt['transaction_id'] ?? '') !== $id || !isset($receipt['manifest'])) { throw new \RuntimeException('Transfer receipt is incompatible'); }
            if ($op === 'commit' && ($receipt['state'] ?? '') === 'committed') {
                foreach ($receipt['manifest'] as $file) {
                    $stat = $this->transferStat($sftp, $root, $file['path']);
                    if (($stat['size'] ?? -1) !== $file['size'] || !hash_equals($file['sha256'], $this->remotePayloadHash($sftp, $root . '/' . $file['path']))) {
                        throw new \RuntimeException('Committed destination changed; refusing replay');
                    }
                }
                return $receipt['result'];
            }
            if (($receipt['state'] ?? '') !== 'receiving') { throw new \RuntimeException('Transfer requires receipt recovery before retrying'); }
            if ($op === 'upload_file') {
                $stream = $request['stream'] ?? null;
                if (empty($receipt['file_stream']) || $receipt['transport'] !== 'api' || !is_resource($stream) || get_resource_type($stream) !== 'stream') {
                    throw new \RuntimeException('Binary upload requires its authenticated private input stream');
                }
                $path = $this->transferPath((string)($request['path'] ?? ''));
                $matches = array_values(array_filter($receipt['manifest'], static fn(array $f): bool => $f['path'] === $path && !$f['skip']));
                if (isset($request['offset']) || isset($request['length'])) {
                    $offset = filter_var($request['offset'] ?? null, FILTER_VALIDATE_INT);
                    $length = filter_var($request['length'] ?? null, FILTER_VALIDATE_INT);
                    if (empty($receipt['file_resume']) || count($matches) !== 1 || $offset === false || $length === false
                        || $offset < 0 || $length < 1 || $length > ApiFileStream::RESUME_BYTES || $offset + $length > (int)$matches[0]['size']
                        || (int)fstat($stream)['size'] !== $length || fseek($stream, 0) !== 0) { throw new \RuntimeException('Binary range is outside its prepared manifest'); }
                    $digest = hash_init('sha256'); hash_update_stream($digest, $stream); $hash = hash_final($digest); rewind($stream);
                    $stage = $directory . '/stage/' . $path;
                    $stored = $this->transferStat($sftp, $root, substr($stage, strlen($root) + 1))['size'] ?? -1;
                    $confirmed = (int)($receipt['offsets'][$path] ?? 0);
                    $previous = $receipt['segments'][$path][(string)$offset] ?? null;
                    if ($previous !== null) {
                        if ($previous !== ['bytes'=>$length,'sha256'=>$hash] || $confirmed < $offset + $length || $stored < $confirmed) {
                            throw new \RuntimeException('Repeated binary range differs from its retained receipt');
                        }
                    } else {
                        if ($confirmed !== $offset || $stored < $offset || $stored > $offset + $length) { throw new \RuntimeException('Binary ranges arrived out of order'); }
                        // This exact private range may be overwritten after an interrupted write; published files are never touched.
                        if (!$sftp->put($stage, $stream, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE, $offset)
                            || ($this->transferStat($sftp, $root, substr($stage, strlen($root) + 1))['size'] ?? -1) !== $offset + $length) {
                            return ['ok'=>false,'status'=>'staging_incomplete','publication_completed'=>false,'transfer_id'=>$id,'message'=>'Range staging interrupted; confirmed ranges retained'];
                        }
                        $receipt['offsets'][$path] = $offset + $length;
                        $receipt['segments'][$path][(string)$offset] = ['bytes'=>$length,'sha256'=>$hash];
                        $this->writeRemoteJson($sftp, $receiptPath, $receipt);
                    }
                    return ['ok'=>true,'transfer_id'=>$id,'path'=>$path,'bytes'=>$length,'offset'=>$offset,'message'=>'Binary range staged; final file hash verification pending'];
                }
                if (count($matches) !== 1 || (int)fstat($stream)['size'] !== (int)$matches[0]['size']) { throw new \RuntimeException('Binary file is outside its prepared manifest'); }
                $stage = $directory . '/stage/' . $path;
                if (($this->transferStat($sftp, $root, substr($stage, strlen($root) + 1))['size'] ?? -1) !== 0) {
                    throw new \RuntimeException('Private staging already contains bytes; reconcile the original upload receipt');
                }
                if (fseek($stream, 0) !== 0 || !$sftp->put($stage, $stream, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)
                    || ($this->transferStat($sftp, $root, substr($stage, strlen($root) + 1))['size'] ?? -1) !== (int)$matches[0]['size']) {
                    return ['ok'=>false,'status'=>'staging_incomplete','publication_completed'=>false,'transfer_id'=>$id,'message'=>'Binary staging did not complete; no files were published'];
                }
                return ['ok'=>true,'transfer_id'=>$id,'path'=>$path,'bytes'=>(int)$matches[0]['size'],'message'=>'File staged; destination hash verification is pending'];
            }
            if ($op === 'upload') {
                if ($receipt['transport'] !== 'api') { throw new \RuntimeException('Cannot change the transport of an active transfer'); }
                $path = $this->transferPath((string)($request['path'] ?? ''));
                $matches = array_values(array_filter($receipt['manifest'], static fn(array $f): bool => $f['path'] === $path));
                if (count($matches) !== 1 || $matches[0]['skip']) { throw new \RuntimeException('Chunk is outside the prepared manifest'); }
                $file = $matches[0]; $offset = filter_var($request['offset'] ?? null, FILTER_VALIDATE_INT);
                $bytes = base64_decode((string)($request['content'] ?? ''), true);
                if ($offset === false || $offset < 0 || !is_string($bytes) || $bytes === '' || strlen($bytes) > 262144 || $offset + strlen($bytes) > $file['size']) {
                    throw new \InvalidArgumentException('Invalid transfer chunk');
                }
                $stage = $directory . '/stage/' . $path;
                $stat = $this->transferStat($sftp, $root, substr($stage, strlen($root) + 1));
                $stored = $stat['size'] ?? -1;
                if ($offset < $stored && $offset + strlen($bytes) <= $stored) {
                    if ($sftp->get($stage, false, $offset, strlen($bytes)) !== $bytes) { throw new \RuntimeException('Replayed chunk differs from retained bytes'); }
                } elseif ($offset === $stored) {
                    if (!$sftp->put($stage, $bytes, \phpseclib3\Net\SFTP::SOURCE_STRING, $offset)) { throw new \RuntimeException('Cannot stage transfer chunk'); }
                    if ($sftp->get($stage, false, $offset, strlen($bytes)) !== $bytes) { throw new \RuntimeException('Staged chunk verification failed'); }
                } else { throw new \RuntimeException('Transfer chunks arrived out of order'); }
                return ['ok'=>true,'transfer_id'=>$id,'bytes'=>strlen($bytes),'offset'=>$offset];
            }
            return $this->commitTransferredAssets($siteId, $sftp, $root, $directory, $receipt);
        });
    }

    /** Read a bounded source snapshot once through the existing storage session. */
    public function transferSourceFile(int $siteId, array $file)
    {
        $file = $this->transferManifest([$file])[0];
        $conn = $this->ucm->siteConnection($siteId); $sftp = $conn->dockerSftp(); $root = rtrim($conn->tftpRoot(), '/');
        $before = $this->transferStat($sftp, $root, $file['path']);
        if (($before['size'] ?? -1) !== $file['size']) { throw new \RuntimeException('Source file changed size'); }
        $temporary = tmpfile();
        if ($temporary === false) { throw new \RuntimeException('Cannot allocate a private source snapshot'); }
        try {
            if (!$sftp->get($root . '/' . $file['path'], $temporary, 0, $file['size'] + 1)
                || (int)fstat($temporary)['size'] !== $file['size'] || $this->transferStat($sftp, $root, $file['path']) !== $before
                || fseek($temporary, 0) !== 0) { throw new \RuntimeException('Source changed while reading its snapshot'); }
            return $temporary;
        } catch (\Throwable $error) { fclose($temporary); throw $error; }
    }

    private function transferPath(string $path): string
    {
        if ($path === '' || strlen($path) > 512 || $this->sanitizeRel($path) !== $path) { throw new \InvalidArgumentException('Invalid managed transfer path'); }
        if (str_starts_with($path, '.ucmprovisioner/')) {
            if (!preg_match('#\A\.ucmprovisioner/(?:timezone-bundles/[a-f0-9]{64}/(?:manifest\.json|[a-f0-9]{64}\.blob)|wallpaper-sets/[A-Za-z0-9_-]{1,80}/[A-Za-z0-9_.-]+)\z#D', $path)) {
                throw new \InvalidArgumentException('Private transaction/configuration files cannot be replicated');
            }
        } elseif (preg_match('#(?:^|/)\.#', $path) || self::managedAssetMetadata($path) === null) {
            throw new \InvalidArgumentException('Only recognized managed assets may be replicated');
        }
        return $path;
    }

    private function transferManifest(array $files): array
    {
        if (!$files || count($files) > 500) { throw new \InvalidArgumentException('Transfer manifest must contain one to five hundred files'); }
        $clean = []; $total = 0;
        foreach ($files as $file) {
            if (!is_array($file)) { throw new \InvalidArgumentException('Invalid manifest entry'); }
            $path = $this->transferPath((string)($file['path'] ?? ''));
            $size = filter_var($file['size'] ?? null, FILTER_VALIDATE_INT); $hash = (string)($file['sha256'] ?? '');
            if ($size === false || $size < 0 || $size > self::MAX_SCAN_FILE_BYTES || !preg_match('/\A[a-f0-9]{64}\z/D', $hash) || isset($clean[$path])) {
                throw new \InvalidArgumentException('Invalid or duplicate transfer file identity');
            }
            $total += $size;
            if ($total > 2147483648) { throw new \InvalidArgumentException('Transfer exceeds the 2 GiB batch limit'); }
            $clean[$path] = ['path'=>$path,'size'=>$size,'sha256'=>$hash];
        }
        return array_values($clean);
    }

    /** Reject links in every ancestor; reading a bounded chunk must never escape the managed root. */
    private function transferStat($sftp, string $root, string $relative): ?array
    {
        $stat = $this->remotePathMetadata($sftp, $root, $relative, [1]);
        if ($stat === null) { return null; }
        $size = (int)($stat['size'] ?? -1);
        if ($size < 0 || $size > self::MAX_SCAN_FILE_BYTES) { throw new \RuntimeException('Transfer file size is outside the supported bound'); }
        return ['size'=>$size,'mtime'=>(int)($stat['mtime'] ?? 0)];
    }

    private function transferDirectory($sftp, string $root, string $directory): void
    {
        if ($directory === $root) { return; }
        if (!str_starts_with($directory, $root . '/')) { throw new \RuntimeException('Transfer directory escaped its root'); }
        $path = $root;
        foreach (explode('/', substr($directory, strlen($root) + 1)) as $part) {
            $path .= '/' . $part;
            $stat = $sftp->lstat($path);
            if ($stat !== false) {
                if ((int)($stat['type'] ?? 0) !== 2) { throw new \RuntimeException('Transfer directory contains a link or file'); }
                continue;
            }
            $mode = $this->isPrivateTftpPath($path) ? self::PRIVATE_DIRECTORY_MODE : self::PUBLIC_DIRECTORY_MODE;
            if (!$sftp->mkdir($path, $mode, false) || !$sftp->chmod($mode, $path)) { throw new \RuntimeException('Cannot prepare managed transfer directory'); }
        }
    }

    private function commitTransferredAssets(int $siteId, $sftp, string $root, string $directory, array $receipt): array
    {
        $files = $receipt['manifest']; $receiptPath = $directory . '/receipt.json';
        // Validate the entire package and destination revisions before the first live rename.
        foreach ($files as $file) {
            $stat = $this->transferStat($sftp, $root, $file['path']);
            $current = $stat === null ? null : $this->remotePayloadHash($sftp, $root . '/' . $file['path']);
            if ($current !== null) { AssetFilename::assertSameContent(basename($file['path']), $file['sha256'], $current); }
            if ($current !== $file['previous_sha256']) { throw new \RuntimeException('Destination changed after transfer preparation; publication refused'); }
            if ($file['skip']) { continue; }
            $stage = $directory . '/stage/' . $file['path'];
            $staged = $this->transferStat($sftp, $root, substr($stage, strlen($root) + 1));
            if (($staged['size'] ?? -1) !== $file['size'] || !hash_equals($file['sha256'], $this->remotePayloadHash($sftp, $stage))) {
                throw new \RuntimeException('Staged file differs from the source manifest; publication refused');
            }
        }
        $receipt['files'] = array_values(array_map(static fn(array $f): array => ['filename'=>$f['path'],'sha256'=>$f['sha256'],
            'previous_sha256'=>$f['previous_sha256'],'intent'=>'staged'], array_filter($files, static fn(array $f): bool => !$f['skip'])));
        $receipt['state'] = 'committing'; $this->writeRemoteJson($sftp, $receiptPath, $receipt);
        try {
            foreach ($receipt['files'] as $i=>$file) {
                $path = $file['filename']; $live = $root . '/' . $path;
                $this->transferDirectory($sftp, $root, dirname($live));
                $receipt['files'][$i]['intent'] = 'activate'; $this->writeRemoteJson($sftp, $receiptPath, $receipt);
                if ($file['previous_sha256'] !== null && !$sftp->rename($live, $directory . '/backup/' . $path)) { throw new \RuntimeException('Could not preserve previous managed asset'); }
                if (!$sftp->rename($directory . '/stage/' . $path, $live)
                    || !$sftp->chmod($this->isPrivateTftpPath($live) ? 0660 : 0664, $live)
                    || (empty($receipt['file_stream']) && !hash_equals($file['sha256'], $this->remotePayloadHash($sftp, $live)))) { throw new \RuntimeException('Managed asset activation failed verification'); }
            }
        } catch (\Throwable $error) {
            try { $rollback = $this->rollbackRemoteReceipt($sftp, $root, $directory, $receipt); }
            catch (\Throwable $recovery) { $rollback = ['ok'=>false,'message'=>$recovery->getMessage()]; }
            return ['ok'=>false,'copied'=>0,'recovery_id'=>$receipt['transaction_id'],'recovery_required'=>empty($rollback['ok']),
                'message'=>$error->getMessage() . (empty($rollback['ok']) ? '; recovery required: ' . $rollback['message'] : '; previous files restored')];
        }
        // Registration runs on the owning PBX under this same local lock, never through a second controller.
        $assets = []; $warnings = [];
        try {
            foreach ($files as $file) {
                if (str_starts_with($file['path'], '.ucmprovisioner/')) { continue; }
                $registered = $this->registerManagedPayload($siteId, $file['path'], $file['sha256'], $file['size']);
                if (isset($registered['asset'])) { $assets[] = $registered['asset']; }
                if (isset($registered['warning'])) { throw new \RuntimeException('Destination inventory registration failed: ' . $registered['warning']); }
            }
            $result = ['ok'=>true,'copied'=>count($receipt['files']),'skipped'=>count($files)-count($receipt['files']),
                'assets'=>$assets,'registry_warnings'=>$warnings,'recovery_id'=>$receipt['transaction_id'],
                'message'=>'Managed files committed and verified by the destination PBX'];
            $receipt['state'] = 'committed'; $receipt['result'] = $result;
            $this->writeRemoteJson($sftp, $receiptPath, $receipt);
            return $result;
        } catch (\Throwable $error) {
            return ['ok'=>false,'recovery_required'=>true,'recovery_id'=>$receipt['transaction_id'],
                'message'=>'Files were published but finalization is unverified: ' . $error->getMessage()];
        }
    }
}
