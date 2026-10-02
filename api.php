<?php
/** Public authenticated federation API: AES-GCM metadata and sodium binary streams. */
$httpMethod = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($httpMethod !== 'POST' && !($httpMethod === 'PUT' && !empty($_SERVER['HTTP_X_UCMP_FILE']))) { http_response_code(405); header('Allow: POST, PUT'); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$authenticated = false; $nonce = ''; $secret = ''; $ucm = null; $binaryResponse = false;
try {
    // This is a public, body-authenticated endpoint.  FreePBX's ordinary web
    // bootstrap otherwise expects a GUI session and does not register either
    // Core or this module for an unauthenticated request.
    $bootstrap_settings['freepbx_auth'] = false;
    $restrict_mods = ['core'=>true, 'ucmprovisioner'=>true];
    require_once '/etc/freepbx.conf';
    // Use the same installed BMO instance as the GUI and constrained SSH agent.
    // Creating a second FreePBX container here leaves static module lookups such
    // as FreePBX::Core() attached to a different, incomplete registry; basic RPCs
    // then work while extension-dependent management calls falsely report that
    // Core is missing.
    $ucm = \FreePBX::Ucmprovisioner();
    if (!$ucm instanceof \FreePBX\modules\Ucmprovisioner) {
        throw new \RuntimeException('UCM Provisioner BMO is unavailable');
    }
    $localId = $ucm->localSiteId();
    if ((string)$ucm->getSetting('federation_api_enabled', $localId, '0') !== '1') { throw new \RuntimeException('disabled'); }
    $secret = $ucm->dec((string)$ucm->getSetting('federation_api_secret', $localId, ''));
    $fileHeader = (string)($_SERVER['HTTP_X_UCMP_FILE'] ?? '');
    if (strlen($fileHeader) > 7000) { throw new \RuntimeException('invalid file metadata'); }
    $body = $fileHeader !== '' ? $fileHeader : (string)file_get_contents('php://input', false, null, 0,
        \FreePBX\modules\Ucmprovisioner\ApiCodec::MAX_ENVELOPE_BYTES + 1);
    $request = \FreePBX\modules\Ucmprovisioner\ApiCodec::open($body, $secret);
    $authenticated = true;
    $timestamp = (int)($request['timestamp'] ?? 0);
    $nonce = strtolower(trim((string)($request['nonce'] ?? '')));
    $payload = $request['payload'] ?? null;
    if (abs(time() - $timestamp) > 300 || !preg_match('/^[a-f0-9]{32}$/', $nonce) || !is_array($payload)) {
        throw new \RuntimeException('invalid request');
    }

    $nonceDir = '/var/lib/asterisk/ucmprovisioner/api-nonces';
    if (!is_dir($nonceDir) && !@mkdir($nonceDir, 0770, true) && !is_dir($nonceDir)) { throw new \RuntimeException('nonce store unavailable'); }
    @chgrp($nonceDir, 'asterisk'); @chmod($nonceDir, 02770);
    $nonceFile = $nonceDir . '/' . $nonce;
    $claim = @fopen($nonceFile, 'x');
    if ($claim === false) { throw new \RuntimeException('replayed request'); }
    fwrite($claim, (string)$timestamp); fclose($claim); @chmod($nonceFile, 0640);
    if (random_int(1, 20) === 1) {
        foreach ((array)glob($nonceDir . '/*') as $old) { if (is_file($old) && filemtime($old) < time() - 600) { @unlink($old); } }
    }

    if ($fileHeader !== '') {
        $method = (string)($payload['method'] ?? '');
        if (!in_array($method, ['tftp_file_upload','tftp_file_download'], true) || !is_array($payload['file'] ?? null)) { throw new \RuntimeException('invalid file request'); }
        if ($httpMethod !== ($method === 'tftp_file_upload' ? 'PUT' : 'POST')) { throw new \RuntimeException('invalid file request method'); }
        $size = filter_var($payload['file']['size'] ?? null, FILTER_VALIDATE_INT);
        if ($size === false) { throw new \RuntimeException('invalid file size'); }
        if (isset($payload['offset']) || isset($payload['length'])) {
            $offset = filter_var($payload['offset'] ?? null, FILTER_VALIDATE_INT);
            $length = filter_var($payload['length'] ?? null, FILTER_VALIDATE_INT);
            if ($method !== 'tftp_file_upload' || $offset === false || $length === false || $offset < 0 || $length < 1
                || $length > \FreePBX\modules\Ucmprovisioner\ApiFileStream::RESUME_BYTES || $offset + $length > $size) {
                throw new \RuntimeException('invalid file range');
            }
            $size = $length;
        }
        $wireBytes = \FreePBX\modules\Ucmprovisioner\ApiFileStream::wireBytes($size);
        if ((string)($_SERVER['CONTENT_LENGTH'] ?? '') !== (string)($method === 'tftp_file_upload' ? $wireBytes : 0)) { throw new \RuntimeException('invalid binary body length'); }
        @set_time_limit(0);
        if ($method === 'tftp_file_upload') {
            $temporary = tmpfile(); $input = fopen('php://input', 'rb');
            if ($temporary === false || $input === false) { throw new \RuntimeException('private input stream unavailable'); }
            try {
                $decoder = new \FreePBX\modules\Ucmprovisioner\ApiFileStream($temporary, $size, $secret, $nonce, 'upload');
                while (!feof($input)) {
                    $bytes = fread($input, 65536);
                    if ($bytes === false || ($bytes === '' && !feof($input))) { throw new \RuntimeException('binary body read failed'); }
                    $decoder->write($bytes);
                }
                $decoder->finish();
                // Network reception holds no TFTP writer lock. The owning PBX
                // performs one local storage write, then normal commit verifies it.
                $result = $ucm->receiveTftpFileLocal($payload, $temporary);
            } finally { fclose($input); fclose($temporary); }
        } else {
            $temporary = $ucm->openTftpSourceFileLocal($payload['file']);
            try {
                $reader = \FreePBX\modules\Ucmprovisioner\ApiFileStream::reader($temporary, $size, $secret, $nonce, 'download');
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: application/octet-stream'); header('Content-Length: ' . $wireBytes);
                $binaryResponse = true;
                while (($bytes = $reader(65536)) !== '') { echo $bytes; flush(); }
            } finally { fclose($temporary); }
            exit;
        }
    } else {
        $rpc = base64_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $result = json_decode($ucm->remoteDispatch($rpc), true, 32, JSON_THROW_ON_ERROR);
    }
    echo \FreePBX\modules\Ucmprovisioner\ApiCodec::seal(['nonce'=>$nonce,'result'=>$result], $secret);
} catch (\Throwable $e) {
    error_log('UCM Provisioner federation API: ' . $e->getMessage());
    if ($binaryResponse) { exit; } // A truncated stream fails authentication; never append a JSON error to it.
    // Once AES-GCM authentication succeeds, operational errors can safely be returned inside
    // an encrypted response. Before that point, disclose nothing about endpoint/key state.
    if ($authenticated && preg_match('/^[a-f0-9]{32}$/', $nonce) && $secret !== '') {
        try {
            if ($ucm) { $ucm->logOperation($ucm->localSiteId(), 'api-request', 'warning', 'Authenticated federation request failed', $e->getMessage()); }
            echo \FreePBX\modules\Ucmprovisioner\ApiCodec::seal([
                'nonce'=>$nonce, 'result'=>['ok'=>false,'message'=>'Federation endpoint failed: ' . $e->getMessage()],
            ], $secret);
            exit;
        } catch (\Throwable $ignored) {
            // If logging or response sealing fails, fall through to the same
            // unauthenticated-looking 403 response and disclose no key state.
        }
    }
    http_response_code(403);
    echo '{"ok":false,"message":"federation API request rejected"}';
}
