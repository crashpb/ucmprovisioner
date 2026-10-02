<?php
/** Authenticated, read-only TFTP file/directory download endpoint. */
require_once '/etc/freepbx.conf';
require_once __DIR__ . '/Ucmprovisioner.class.php';
require_once __DIR__ . '/lib/TftpStore.php';

use FreePBX\modules\Ucmprovisioner\TftpStore;

$download = null;
try {
    @set_time_limit(300);
    $ucm = new \FreePBX\modules\Ucmprovisioner(\FreePBX::create());
    $ucm->assertTftpDownloadRequest();
    $siteId = filter_var($_POST['site_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $paths = json_decode((string)($_POST['paths'] ?? '[]'), true, 8, JSON_THROW_ON_ERROR);
    if ($siteId === false || !is_array($paths) || !array_is_list($paths)) {
        throw new \InvalidArgumentException('Invalid TFTP download request');
    }
    $download = (new TftpStore($ucm))->prepareDownload((int)$siteId, $paths);
    $path = (string)$download['path'];
    $size = (int)$download['size'];
    if (!is_file($path) || !is_readable($path) || $size < 0) {
        throw new \RuntimeException('The prepared download is unavailable');
    }
    $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)$download['filename']) ?: 'tftp-download.bin';
    header('Content-Type: ' . (string)$download['content_type']);
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode((string)$download['filename']));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    $stream = fopen($path, 'rb');
    if ($stream === false) { throw new \RuntimeException('Could not open the prepared download'); }
    fpassthru($stream);
    fclose($stream);
} catch (\JsonException|\InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
} catch (\Throwable $e) {
    http_response_code(str_contains($e->getMessage(), 'authenticated') || str_contains($e->getMessage(), 'token') ? 403 : 503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'TFTP download failed: ' . $e->getMessage();
} finally {
    if (is_array($download) && is_string($download['path'] ?? null)) { @unlink($download['path']); }
}
