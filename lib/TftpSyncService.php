<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** API and SFTP move the same pinned bytes; only the owning PBX commits them. */
final class TftpSyncService
{
    private ?array $byteProgress = null;
    public function __construct(private object $ucm) {}

    public function copy(int $source, array $targets, array $paths, bool $overwrite): array
    {
        $this->byteProgress = null;
        $targets = array_values(array_unique(array_filter(array_map('intval', $targets), static fn(int $id): bool => $id > 0 && $id !== $source)));
        if (!$targets || count($targets) > 20) { throw new \InvalidArgumentException('Choose between one and twenty different target sites'); }
        $sourceTransport = $this->ucm->replicationTransport($source);
        $sourceStreaming = $sourceTransport !== 'api' || (method_exists($this->ucm, 'replicationTransferCapabilities')
            && !empty($this->ucm->replicationTransferCapabilities($source)['file_stream']));
        $this->progress($source, 'Reading source manifest', 'Hashing the selected files');
        $manifest = $this->call($source, ['op'=>'manifest', 'paths'=>$paths], $sourceTransport);
        $files = (array)($manifest['files'] ?? []);
        $result = ['ok'=>true, 'sites'=>[], 'source_assets'=>[], 'source_registry_warnings'=>[]];
        $snapshots = [];
        try {
        foreach ($targets as $target) {
            $this->byteProgress = null;
            $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
            $transport = null;
            $phase = 'preflight';
            $sent = 0; $totalBytes = 0; $retained = 0;
            $resumeKey = null;
            try {
                $this->progress($target, 'Checking destination', 'Selecting the transfer channel');
                $transport = $this->ucm->replicationTransport($target);
                $caps = method_exists($this->ucm, 'replicationTransferCapabilities') ? $this->ucm->replicationTransferCapabilities($target) : [];
                $streaming = $transport === 'api' && $sourceStreaming && ApiFileStream::available() && function_exists('curl_init')
                    && !empty($caps['file_stream']) && method_exists($this->ucm, 'replicationUploadFile');
                $rangeBytes = $streaming ? min(ApiFileStream::RESUME_BYTES, max(0, (int)($caps['file_resume_bytes'] ?? 0))) : 0;
                if ($rangeBytes && method_exists($this->ucm, 'getSetting') && method_exists($this->ucm, 'setSetting')) {
                    $resumeKey = 'tftp_resume_' . substr(hash('sha256', json_encode([$source,$target,$files,$overwrite,$transport], JSON_THROW_ON_ERROR)), 0, 48);
                    $saved = (string)$this->ucm->getSetting($resumeKey, $target, '');
                    if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/D', $saved)) { $id = $saved; }
                    else { $this->ucm->setSetting($resumeKey, $id, $target); }
                }
                $batchLimit = $transport === 'api' ? min(1048576, max(0, (int)($caps['upload_batch_bytes'] ?? 0))) : 0;
                $batchCount = min(64, max(0, (int)($caps['upload_batch_chunks'] ?? 0)));
                if ($batchLimit < 262144 || $batchCount < 1) { $batchLimit = 0; }
                $phase = 'prepare';
                $this->progress($target, 'Preparing destination', 'Comparing existing files and preparing private staging');
                $prepared = $this->call($target, ['op'=>'prepare', 'transfer_id'=>$id, 'files'=>$files,
                    'overwrite'=>$overwrite, 'transport'=>$transport === 'sftp' ? 'sftp' : 'api', 'file_stream'=>$streaming ? 1 : 0,
                    'file_resume'=>$rangeBytes ? 1 : 0], $transport);
                $pins = array_map(static fn(array $f): array => array_intersect_key($f, array_flip(['path','size','sha256'])), (array)($prepared['files'] ?? []));
                if ($pins !== $files || ($prepared['transfer_id'] ?? '') !== $id) { throw new \RuntimeException('Destination prepared a different manifest'); }
                foreach ($prepared['files'] as $file) { if (empty($file['skip'])) { $totalBytes += (int)$file['size']; } }
                $this->byteProgress = ['site'=>$target,'id'=>$id,'total'=>$totalBytes,'sent'=>0,
                    'unchanged'=>array_sum(array_column($prepared['files'],'size'))-$totalBytes];
                $this->progress($target, 'Package prepared', "$totalBytes bytes to transfer in this package; identical files excluded", null, 0);
                $batch = []; $batchBytes = 0;
                $flush = function () use (&$batch, &$batchBytes, &$sent, $totalBytes, $target, $id, $transport): void {
                    if (!$batch) { return; }
                    $ack = $this->call($target, ['op'=>'upload_batch','transfer_id'=>$id,'chunks'=>$batch], $transport);
                    if (($ack['transfer_id'] ?? '') !== $id || (int)($ack['chunks'] ?? -1) !== count($batch) || (int)($ack['bytes'] ?? -1) !== $batchBytes) {
                        throw new \RuntimeException('Destination batch receipt does not match the submitted bytes');
                    }
                    $sent += $batchBytes; $batch = []; $batchBytes = 0;
                    $this->progress($target, 'Transferring files', "$sent / $totalBytes bytes in this package staged via API batches", null, $sent);
                };
                $phase = 'transfer';
                foreach ((array)$prepared['files'] as $file) {
                    if (!empty($file['skip'])) { continue; }
                    $size = (int)$file['size'];
                    if (!empty($prepared['committed'])) { $sent += $size; continue; }
                    if ($streaming && $size > 1048576) {
                        $flush();
                        $pin = array_intersect_key($file, array_flip(['path','size','sha256']));
                        $offset = $rangeBytes ? (int)($prepared['offsets'][$file['path']] ?? 0) : 0;
                        if ($offset < 0 || $offset > $size) {
                            throw new \RuntimeException('Destination resume offset is invalid');
                        }
                        $retained = $sent + $offset;
                        if ($offset === $size) { $sent += $size; $this->progress($target, 'Retained file: ' . $file['path'], 'Previously confirmed bytes retained; final verification pending', null, $sent); continue; }
                        if (!isset($snapshots[$file['path']])) {
                            $this->progress($target, 'Reading source file', $file['path']);
                            $snapshots[$file['path']] = $this->ucm->replicationSourceFile($source, $pin, $sourceTransport);
                        }
                        $lastProgress = 0.0;
                        for (; $offset < $size; $offset += $length) {
                        $length = $rangeBytes ? min($rangeBytes, $size - $offset) : $size;
                        $ack = $this->ucm->replicationUploadFile($target, $id, $pin, $snapshots[$file['path']],
                            function (int $bytes) use ($target, $file, $sent, $offset, $totalBytes, &$lastProgress): void {
                                $now = microtime(true);
                                if ($now - $lastProgress < 1.0) { return; }
                                $lastProgress = $now;
                                $this->progress($target, 'Sending ' . $file['path'], ($sent + $offset + $bytes) . " / $totalBytes bytes in this package; binary API", null, $sent + $offset + $bytes);
                            }, $rangeBytes ? ['offset'=>$offset,'length'=>$length] : null);
                        if (empty($ack['ok']) || ($ack['transfer_id'] ?? '') !== $id || ($ack['path'] ?? '') !== $file['path'] || (int)($ack['bytes'] ?? -1) !== $length
                            || ($rangeBytes && (int)($ack['offset'] ?? -1) !== $offset)) {
                            throw new \RuntimeException('Binary upload receipt does not match the submitted file');
                        }
                        $retained = $sent + $offset + $length;
                        $this->progress($target, 'Range retained: ' . $file['path'], "$retained / $totalBytes bytes confirmed", null, $retained);
                        }
                        $sent += $size;
                        $this->progress($target, 'File staged: ' . $file['path'], "$sent / $totalBytes bytes in this package; destination verification pending", null, $sent);
                        continue;
                    }
                    for ($offset = 0; $offset < $size; $offset += strlen($bytes)) {
                        $length = min(262144, $size - $offset);
                        if ($sourceTransport === 'sftp') {
                            $conn = $this->ucm->siteConnection($source);
                            $bytes = $conn->dockerSftp()->get(rtrim($conn->tftpRoot(), '/') . '/' . $file['path'], false, $offset, $length);
                        } else {
                            $chunk = $this->call($source, ['op'=>'download','file'=>$file,'offset'=>$offset], $sourceTransport);
                            $bytes = base64_decode((string)($chunk['content'] ?? ''), true);
                        }
                        if (!is_string($bytes) || strlen($bytes) !== $length) { throw new \RuntimeException('Source transfer chunk is incomplete'); }
                        if ($transport === 'sftp') {
                            $conn = $this->ucm->siteConnection($target); $sftp = $conn->dockerSftp();
                            $stage = rtrim($conn->tftpRoot(), '/') . '/.ucmprovisioner/copies/' . $id . '/stage/' . $file['path'];
                            if (!$sftp->put($stage, $bytes, \phpseclib3\Net\SFTP::SOURCE_STRING, $offset)) {
                                throw new \RuntimeException('SFTP staging failed; retained transfer was not committed');
                            }
                            $sent += strlen($bytes);
                            $this->progress($target, 'Transferring ' . $file['path'], "$sent / $totalBytes bytes in this package staged via SFTP", null, $sent);
                        } elseif ($batchLimit) {
                            if ($batchBytes + strlen($bytes) > $batchLimit || count($batch) >= $batchCount) { $flush(); }
                            $batch[] = ['path'=>$file['path'],'offset'=>$offset,'content'=>base64_encode($bytes)];
                            $batchBytes += strlen($bytes);
                        } else {
                            $this->call($target, ['op'=>'upload','transfer_id'=>$id,'path'=>$file['path'],
                                'offset'=>$offset,'content'=>base64_encode($bytes)], $transport);
                            $sent += strlen($bytes);
                            $this->progress($target, 'Transferring ' . $file['path'], "$sent / $totalBytes bytes in this package staged via API; peer uses individual chunks", null, $sent);
                        }
                    }
                }
                $flush();
                $phase = 'commit';
                $this->progress($target, 'Verifying and publishing files', "$sent / $totalBytes bytes staged; checking hashes and destination revisions");
                $committed = $this->ucm->replicationTransfer($target, ['op'=>'commit','transfer_id'=>$id], $transport);
                $result['sites'][(string)$target] = $committed + ['transport'=>$transport,'recovery_id'=>$id];
                if (empty($committed['ok'])) { $result['ok'] = false; }
                elseif ($resumeKey !== null) {
                    try { $this->ucm->setSetting($resumeKey, '', $target); }
                    catch (\Throwable $cleanup) { $result['sites'][(string)$target]['message'] .= '; local resume marker retained'; }
                }
                $this->progress($target, !empty($committed['ok']) ? 'Files committed' : 'Publication needs review', (string)($committed['message'] ?? ''), $result['sites'][(string)$target]);
            } catch (\Throwable $error) {
                $sent = max($sent, $retained);
                $result['ok'] = false;
                $receipt = $error instanceof RpcOperationException ? $error->receipt : [];
                if (($receipt['state'] ?? '') === 'completed' && is_array($receipt['result'] ?? null)) {
                    if ($phase === 'transfer') { $receipt['result']['staged_bytes'] = $sent + (int)($receipt['result']['staged_bytes'] ?? 0); }
                    $result['sites'][(string)$target] = $receipt['result'] + [
                        'transport'=>$transport,'phase'=>$phase,'recovery_id'=>$id,
                        'operation_id'=>$receipt['operation_id'] ?? null,
                        'staged_bytes'=>$sent,'transfer_bytes'=>$totalBytes,'staging_incomplete'=>$phase === 'transfer',
                    ];
                    $this->progress($target, 'Transfer stopped', (string)($result['sites'][(string)$target]['message'] ?? 'Destination reported failure'), $result['sites'][(string)$target]);
                    continue;
                }
                $result['sites'][(string)$target] = ['ok'=>false,'copied'=>0,'skipped'=>0,
                    'transport'=>$transport,'phase'=>$phase,'recovery_required'=>$phase === 'commit',
                    'staged_bytes'=>$sent,'transfer_bytes'=>$totalBytes,'staging_incomplete'=>$phase === 'transfer',
                    'publication_completed'=>$phase === 'commit' ? null : false,
                    'recovery_id'=>$phase === 'preflight' ? null : $id,'message'=>ucfirst($phase) . ': ' . $error->getMessage()];
                if ($error instanceof RpcOperationException) {
                    $result['sites'][(string)$target]['operation_id'] = $error->receipt['operation_id'] ?? null;
                    $result['sites'][(string)$target]['recovery_required'] = $phase === 'commit'
                        || in_array((string)($error->receipt['state'] ?? ''), ['unresolved','running','unknown','recovery_required'], true);
                }
                $this->progress($target, 'Transfer stopped', $result['sites'][(string)$target]['message'], $result['sites'][(string)$target]);
            }
        }
        $result['message'] = $result['ok'] ? 'Assets synchronized and verified on each destination' : 'One or more destination transfers failed; inspect their retained receipts';
        return $result;
        } finally { foreach ($snapshots as $snapshot) { if (is_resource($snapshot)) { fclose($snapshot); } } }
    }

    private function progress(int $site, string $stage, string $message, ?array $result = null, ?int $sent = null): void
    {
        if ($sent !== null && $this->byteProgress !== null) { $this->byteProgress['sent'] = max($this->byteProgress['sent'],$sent); }
        if (method_exists($this->ucm, 'replicationProgress')) {
            $this->ucm->replicationProgress($site, $stage, $message, $result, ($this->byteProgress['site'] ?? null) === $site ? $this->byteProgress : null);
        }
    }

    private function call(int $site, array $request, string $transport): array
    {
        $result = (array)$this->ucm->replicationTransfer($site, $request, $transport);
        if (empty($result['ok'])) {
            throw new RpcOperationException((string)($result['message'] ?? 'Transfer operation failed'), RpcResponse::completed($result));
        }
        return $result;
    }
}
