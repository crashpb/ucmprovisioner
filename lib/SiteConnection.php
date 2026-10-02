<?php
/**
 * Authenticated transport boundary for one managed site.
 *
 * The class opens pinned-host-key SSH/SFTP connections to the PBX and the site's
 * active TFTP backend, and sends encrypted federation requests to the remote
 * module. TFTP may live on a separate Docker host or on the PBX itself; callers
 * use one interface and do not need architecture-specific branches.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class SiteConnection
{
    private array $site;
    private ?SSH2 $pbx = null;
    private ?bool $agentStreamSupported = null;
    private ?\CurlHandle $apiCurl = null;
    /** @var array<string,SFTP> One request-local SFTP session per TFTP architecture. */
    private array $tftp = [];

    /** Key location used by the normal asterisk process and the non-root agent upgrade path. */
    private function provisionerKeyPath(): ?string
    {
        foreach (['/etc/asterisk/keys/ucmp_id_rsa', '/var/lib/ucmp-pbx/keys/ucmp_id_rsa'] as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @param array $site decrypted site row (pbx_ssh_secret/docker_ssh_secret in clear) */
    public function __construct(array $site)
    {
        $this->site = $site;
    }

    /* ---------------- PBX (SSH) ---------------- */
    public function pbx(): SSH2
    {
        if ($this->pbx instanceof SSH2) {
            return $this->pbx;
        }
        $host = $this->site['pbx_host'] ?: $this->site['pbx_addr'];
        $port = (int)($this->site['pbx_ssh_port'] ?: 22);
        if (!$host) {
            throw new \Exception('No PBX management host configured for this site');
        }
        $ssh = new SSH2($host, $port);
        $this->verifyHostKey($ssh, $this->site['pbx_host_key'] ?? '', "$host:$port");
        $user = !empty($this->site['pbx_agent_ready'])
            ? ($this->site['pbx_agent_user'] ?: 'ucmp-pbx')
            : ($this->site['pbx_ssh_user'] ?? '');
        $credential = $this->site['pbx_ssh_secret'] ?? '';
        if (!empty($this->site['pbx_agent_ready'])) {
            $keyPath = $this->provisionerKeyPath();
            if ($keyPath === null) {
                throw new \Exception('Local provisioner SSH key is missing; re-run PBX bootstrap');
            }
            $credential = PublicKeyLoader::load((string)file_get_contents($keyPath));
        }
        if ($user === '' || !$ssh->login($user, $credential)) {
            throw new \Exception("PBX SSH login failed for $user@$host:$port");
        }
        return $this->pbx = $ssh;
    }

    /** True when this connection targets the PBX this module is actually running on. */
    public function isLocal(): bool
    {
        return !empty($this->site['is_local']);
    }

    /**
     * Run a command on the PBX. When $sudo is true and the user is not root, the
     * command is run via `sudo -S` with the password fed on stdin.
     *
     * For the LOCAL site, this runs the command directly on this box (the module
     * already runs as the `asterisk` user via the web server, which has permission
     * to talk to the Asterisk control socket) instead of SSHing to itself — SSH
     * credentials are never required/collected for the local site.
     */
    public function execPbx(string $cmd, bool $sudo = false): string
    {
        if ($this->isLocal()) {
            return (string) shell_exec($cmd . ' 2>&1');
        }
        $ssh = $this->pbx();
        if ($sudo && ($this->site['pbx_ssh_user'] ?? '') !== 'root') {
            $cmd = 'echo ' . escapeshellarg($this->site['pbx_ssh_secret']) . " | sudo -S -p '' " . $cmd;
        }
        return (string)$ssh->exec($cmd);
    }

    /** Invoke the remote forced-command provisioner RPC account. */
    public function execAgent(string $payload): string
    {
        if ($this->isLocal()) {
            throw new \LogicException('Agent RPC is only for remote sites');
        }
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $payload) !== 1 || strlen($payload) > 1048576) {
            throw new \InvalidArgumentException('Invalid RPC payload');
        }
        $ssh = $this->pbx();
        // A remote rebuild can write many TFTP files without producing output. Keep the
        // transport open for the bounded server operation instead of treating silence as EOF.
        $ssh->setTimeout(300);
        if (strlen($payload) <= 65536) {
            $response = $ssh->exec('ucmp-rpc ' . $payload);
        } else {
            // Linux limits each argv/environment string, including the forced
            // command environment. Large requests belong on the SSH data stream.
            if ($this->agentStreamSupported === null) {
                $capabilities = $ssh->exec('ucmp-rpc-capabilities');
                $decoded = is_string($capabilities) && strlen($capabilities) <= 4096 ? json_decode($capabilities, true) : null;
                $this->agentStreamSupported = !$ssh->isTimeout() && $ssh->getExitStatus() === 0 && is_array($decoded) && ($decoded['rpc_stdin'] ?? 0) === 1;
            }
            if (!$this->agentStreamSupported) { throw new \RuntimeException('This PBX SSH agent cannot receive large requests. Refresh its agent enrollment before using large SSH fallback transfers; no payload was sent'); }
            $response = '';
            try {
                if ($ssh->exec('ucmp-rpc-stdin-v1', false) !== true) { throw new \RuntimeException('Could not open the SSH RPC input stream'); }
                $ssh->write($payload, SSH2::CHANNEL_EXEC);
                $ssh->sendEOF(SSH2::CHANNEL_EXEC);
                $deadline = microtime(true) + 300;
                while (true) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) { throw new \RuntimeException('SSH RPC response timed out; reconcile its operation receipt'); }
                    $ssh->setTimeout($remaining);
                    $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
                    if ($ssh->isTimeout()) { throw new \RuntimeException('SSH RPC response timed out; reconcile its operation receipt'); }
                    if ($chunk === true) { break; }
                    if (!is_string($chunk)) { throw new \RuntimeException('SSH RPC response was lost; reconcile its operation receipt'); }
                    if (strlen($response) + strlen($chunk) > 16777216) { throw new \RuntimeException('SSH RPC response exceeded its 16 MiB limit; reconcile its operation receipt'); }
                    $response .= $chunk;
                }
            } finally {
                try { $ssh->reset(SSH2::CHANNEL_EXEC); } catch (\Throwable $ignored) {}
                $ssh->setTimeout(300);
            }
        }
        if (is_string($response) && strlen($response) > 16777216) { throw new \RuntimeException('SSH RPC response exceeded its 16 MiB limit; reconcile its operation receipt'); }
        if ($ssh->isTimeout() || $ssh->getExitStatus() !== 0) {
            // FreePBX can emit the finalized RPC receipt and then exit nonzero
            // during CLI shutdown. Preserve that authenticated result; a shell
            // exit alone must not erase completion or cause mutation replay.
            $request = json_decode((string)base64_decode($payload, true), true);
            $receipt = is_string($response) ? json_decode($response, true) : null;
            $operationId = ($request['method'] ?? '') === 'rpcOperationStatusLocal'
                ? ($request['args'][0] ?? null) : ($request['operation_id'] ?? '');
            $terminalReceipt = is_array($request) && ($request['rpc_version'] ?? null) === 2
                && is_array($receipt) && ($receipt['rpc_version'] ?? null) === 2
                && is_string($operationId) && ($operationId === '' || preg_match('/\A[a-f0-9]{32}\z/D', $operationId) === 1)
                && ($receipt['operation_id'] ?? null) === $operationId
                && ((($receipt['state'] ?? '') === 'completed' && array_key_exists('result', $receipt))
                    || (($receipt['state'] ?? '') === 'failed' && $operationId !== '' && is_string($receipt['message'] ?? null)));
            if ($ssh->isTimeout() || !$terminalReceipt) {
                $exit = $ssh->getExitStatus();
                throw new \RuntimeException('SSH RPC completion was not confirmed (exit ' . (is_int($exit) ? $exit : 'unknown') . '); reconcile its operation receipt');
            }
        }
        if ($response === false || $response === '') {
            $stderr = trim((string)$ssh->getStdError());
            throw new \RuntimeException('Remote PBX agent returned no response after waiting for the operation'
                . ($stderr !== '' ? ': ' . substr($stderr, 0, 500) : ' (the agent may be outdated or the SSH operation timed out)'));
        }
        return (string)$response;
    }

    /** Invoke the encrypted HTTP(S) federation API. SSH remains the bootstrap/recovery path. */
    public function execApi(array $payload)
    {
        [$url, $secret, $verifyTls, $scheme] = $this->apiTarget();
        $nonce = bin2hex(random_bytes(16));
        $body = ApiCodec::seal(['timestamp'=>time(),'nonce'=>$nonce,'payload'=>$payload], $secret);

        if (function_exists('curl_init')) {
            $received = '';
            $curl = $this->apiCurl ??= curl_init();
            curl_reset($curl);
            curl_setopt_array($curl, [
                CURLOPT_URL=>$url,
                CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>300,
                CURLOPT_SSL_VERIFYPEER=>$scheme === 'https' && $verifyTls,
                CURLOPT_SSL_VERIFYHOST=>$scheme === 'https' && $verifyTls ? 2 : 0,
                CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$received): int {
                    if (strlen($received) + strlen($chunk) > ApiCodec::MAX_ENVELOPE_BYTES) { return 0; }
                    $received .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $response = curl_exec($curl) === false ? false : $received;
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($curl);
            $error = curl_error($curl);
            if ($response === false || $status < 200 || $status >= 300) {
                if (in_array($errno, [6,7,28,52,55,56], true) || in_array($status, [502,503,504], true)) {
                    throw new ApiUnavailableException('Federation API is unavailable' . ($status ? " (HTTP $status)" : '') . ($error !== '' ? ': ' . $error : ''));
                }
                throw new \RuntimeException('Federation API transport failed' . ($status ? " (HTTP $status)" : '') . ($error !== '' ? ': ' . $error : ''));
            }
        } else {
            $context = stream_context_create(['http'=>[
                'method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'=>$body,'timeout'=>300,'ignore_errors'=>true,'follow_location'=>0,
            ],'ssl'=>['verify_peer'=>$verifyTls,'verify_peer_name'=>$verifyTls]]);
            $response = @file_get_contents($url, false, $context, 0, ApiCodec::MAX_ENVELOPE_BYTES + 1);
            $responseHeaders = $http_response_header ?? [];
            $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($responseHeaders[0] ?? ''), $statusMatch) ? (int)$statusMatch[1] : 0;
            if (in_array($status, [502,503,504], true)) { throw new ApiUnavailableException('Federation API service is unavailable'); }
            if ($response === false) {
                $reason = (string)(error_get_last()['message'] ?? '');
                if (preg_match('/connection refused|connection timed out|network is unreachable|no route to host|php_network_getaddresses/i', $reason)) {
                    throw new ApiUnavailableException('Federation API connection is unavailable');
                }
                throw new \RuntimeException('Federation API transport failed');
            }
        }
        $opened = ApiCodec::open((string)$response, $secret);
        if (!hash_equals($nonce, (string)($opened['nonce'] ?? '')) || !array_key_exists('result', $opened)) {
            throw new \RuntimeException('Federation API returned an invalid response');
        }
        return RpcResponse::unwrap($opened['result']);
    }

    /** Same endpoint identity and TLS policy for RPC and binary file requests. */
    private function apiTarget(): array
    {
        if ($this->isLocal()) { throw new \LogicException('Federation API is only for remote sites'); }
        $secret = trim((string)($this->site['pbx_api_secret'] ?? ''));
        $scheme = strtolower((string)($this->site['pbx_api_scheme'] ?? 'http'));
        $host = trim((string)($this->site['pbx_api_host'] ?? '')) ?: trim((string)($this->site['pbx_host'] ?? $this->site['pbx_addr'] ?? ''));
        $port = (int)($this->site['pbx_api_port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($scheme, ['http','https'], true) || $host === '' || $port < 1 || $port > 65535) {
            throw new \RuntimeException('The site API address is incomplete');
        }
        $authority = str_contains($host, ':') && $host[0] !== '[' ? '[' . $host . ']' : $host;
        if (!(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) { $authority .= ':' . $port; }
        $url = $scheme . '://' . $authority . '/ucmprovisioner-api.php';
        $verifyTls = !empty($this->site['pbx_api_verify_tls']);
        return [$url, $secret, $verifyTls, $scheme];
    }

    /** One continuous request per file. The caller reconciles an uncertain upload receipt. */
    public function execApiFile(array $payload, $file, bool $upload, ?callable $progress = null): array
    {
        if (!function_exists('curl_init') || !ApiFileStream::available()) { throw new \RuntimeException('Binary API transfers require PHP curl and sodium'); }
        [$url, $secret, $verifyTls, $scheme] = $this->apiTarget();
        $range = $upload && isset($payload['offset']);
        $size = (int)($range ? ($payload['length'] ?? -1) : ($payload['file']['size'] ?? -1));
        if ($range && ($size < 1 || $size > ApiFileStream::RESUME_BYTES || (int)$payload['offset'] < 0
            || (int)$payload['offset'] + $size > (int)($payload['file']['size'] ?? -1))) { throw new \InvalidArgumentException('Invalid binary file range'); }
        $wireBytes = ApiFileStream::wireBytes($size);
        $nonce = bin2hex(random_bytes(16));
        $metadata = ApiCodec::seal(['timestamp'=>time(),'nonce'=>$nonce,'payload'=>$payload], $secret);
        if (strlen($metadata) > 7000) { throw new \InvalidArgumentException('Binary transfer metadata is too large'); }
        $reader = $upload ? ApiFileStream::reader($file, $size, $secret, $nonce, 'upload', $range ? (int)$payload['offset'] : null) : null;
        $decoder = $upload ? null : new ApiFileStream($file, $size, $secret, $nonce, 'download');
        $response = ''; $binary = false;
        $curl = $this->apiCurl ??= curl_init(); curl_reset($curl);
        curl_setopt_array($curl, [
            CURLOPT_URL=>$url, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>$range ? 180 : 3600,
            CURLOPT_LOW_SPEED_LIMIT=>1024, CURLOPT_LOW_SPEED_TIME=>60,
            CURLOPT_SSL_VERIFYPEER=>$scheme === 'https' && $verifyTls,
            CURLOPT_SSL_VERIFYHOST=>$scheme === 'https' && $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER=>['Content-Type: application/octet-stream','Accept: application/octet-stream, application/json','Expect:','X-UCMP-File: ' . $metadata],
            CURLOPT_HEADERFUNCTION=>static function ($handle, string $line) use (&$binary): int {
                if (stripos($line, 'HTTP/') === 0) { $binary = false; }
                if (stripos($line, 'Content-Type:') === 0) { $binary = str_contains(strtolower($line), 'application/octet-stream'); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $bytes) use (&$response, &$binary, $decoder): int {
                if ($binary && $decoder) { return $decoder->write($bytes); }
                if (strlen($response) + strlen($bytes) > ApiCodec::MAX_ENVELOPE_BYTES) { return 0; }
                $response .= $bytes; return strlen($bytes);
            },
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($upload, $size, $wireBytes, $progress): int {
                if ($progress) { $progress(min($size, (int)(($upload ? $uploaded : $downloaded) * $size / $wireBytes))); }
                return 0;
            },
        ]);
        if ($upload) {
            curl_setopt_array($curl, [CURLOPT_UPLOAD=>true, CURLOPT_INFILESIZE=>$wireBytes,
                CURLOPT_READFUNCTION=>static fn($handle, $stream, int $length): string => $reader($length)]);
        } else { curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>'']); }
        $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $status < 200 || $status >= 300) { throw new \RuntimeException('Binary API transfer did not complete: HTTP ' . $status . ' ' . curl_error($curl)); }
        if (!$upload && $binary) { $decoder->finish(); return ['ok'=>true,'bytes'=>$size]; }
        $opened = ApiCodec::open($response, $secret);
        if (!hash_equals($nonce, (string)($opened['nonce'] ?? '')) || !array_key_exists('result', $opened)) { throw new \RuntimeException('Binary API response identity mismatch'); }
        $result = (array)RpcResponse::unwrap($opened['result']);
        if (!$upload) { throw new \RuntimeException((string)($result['message'] ?? 'Binary download was refused')); }
        return $result;
    }

    /** Convenience: run an Asterisk CLI command on the PBX (full binary path). */
    public function asterisk(string $cli): string
    {
        return $this->execPbx(
            '/usr/bin/timeout --signal=KILL 15 /usr/sbin/asterisk -rx ' . escapeshellarg($cli)
        );
    }

    /* ---------------- TFTP storage (SFTP) ---------------- */
    public static function activeTftpMode(array $site): string
    {
        return in_array((string)($site['tftp_mode'] ?? ''), ['docker', 'pbx'], true)
            ? (string)$site['tftp_mode']
            : 'docker';
    }

    /** Phone-facing address used by generated configuration, never the SFTP jail path. */
    public static function resolvedTftpHost(array $site, ?string $mode = null): string
    {
        $mode = $mode ?? self::activeTftpMode($site);
        if ($mode === 'pbx') {
            return trim((string)($site['pbx_tftp_service_host'] ?? ''))
                ?: trim((string)($site['pbx_addr'] ?? ''))
                ?: trim((string)($site['pbx_host'] ?? ''));
        }
        return trim((string)($site['tftp_service_host'] ?? ''))
            ?: trim((string)($site['docker_host'] ?? ''));
    }

    /**
     * Open the requested TFTP data plane. Passing no mode resolves the active
     * site architecture. An explicit mode is used only by staged cutover.
     */
    public function tftpSftp(?string $mode = null): SFTP
    {
        $mode = $mode ?? self::activeTftpMode($this->site);
        if (!in_array($mode, ['docker', 'pbx'], true)) {
            throw new \InvalidArgumentException('Unknown TFTP architecture');
        }
        if (($this->tftp[$mode] ?? null) instanceof SFTP) {
            return $this->tftp[$mode];
        }

        if ($mode === 'pbx') {
            $host = trim((string)($this->site['pbx_host'] ?: ($this->site['pbx_addr'] ?? '')));
            $port = (int)($this->site['pbx_ssh_port'] ?: 22);
            $hostKey = (string)($this->site['pbx_host_key'] ?? '');
            $fallbackSecret = '';
            $label = 'PBX-hosted TFTP';
        } else {
            $host = trim((string)($this->site['docker_host'] ?? ''));
            $port = (int)($this->site['docker_ssh_port'] ?: 22);
            $hostKey = (string)($this->site['docker_host_key'] ?? '');
            $fallbackSecret = (string)($this->site['docker_ssh_secret'] ?? '');
            $label = 'Docker-hosted TFTP';
        }
        if ($host === '') {
            throw new \Exception("No $label host configured for this site");
        }

        $sftp = new SFTP($host, $port);
        $this->verifyHostKey($sftp, $hostKey, "$host:$port");
        $loggedIn = false;
        $loginFailure = 'No readable provisioner private key is available';
        $keyPath = $this->provisionerKeyPath();
        if ($keyPath !== null) {
            try {
                $key = PublicKeyLoader::load((string)file_get_contents($keyPath));
                $loggedIn = $sftp->login('ucmp-tftp', $key);
                $loginFailure = 'The server rejected provisioner public-key authentication';
            } catch (\Throwable $e) {
                $loggedIn = false;
                $loginFailure = 'Provisioner key loading or SSH public-key authentication did not complete';
            }
        }
        // Password fallback exists only for a pre-bootstrap Docker deployment.
        // PBX-hosted storage is always key-only after bootstrap.
        if (!$loggedIn && $mode === 'docker' && $fallbackSecret !== '') {
            $loggedIn = $sftp->login((string)($this->site['docker_ssh_user'] ?: 'ucmp-tftp'), $fallbackSecret);
        }
        if (!$loggedIn) {
            throw new \Exception("$label SFTP login failed for ucmp-tftp@$host:$port: $loginFailure");
        }
        return $this->tftp[$mode] = $sftp;
    }

    /**
     * Compatibility name retained for existing storage engines and third-party
     * tests. It now means "the active site's TFTP SFTP channel".
     */
    public function dockerSftp(): SFTP
    {
        return $this->tftpSftp();
    }

    public function tftpRoot(?string $mode = null): string
    {
        $mode = $mode ?? self::activeTftpMode($this->site);
        if (!in_array($mode, ['docker', 'pbx'], true)) {
            throw new \InvalidArgumentException('Unknown TFTP architecture');
        }
        // Both managed accounts are jailed and expose the writable service root
        // as /tftpboot, regardless of the host-side directory.
        $field = $mode === 'pbx' ? 'pbx_tftp_root_path' : 'tftp_root_path';
        $path = rtrim((string)($this->site[$field] ?? '/tftpboot'), '/');
        return $path !== '' ? $path : '/';
    }

    /* ---------------- Health check ---------------- */
    public function test(): array
    {
        $res = [
            'ok'=>true,
            'api'=>null, 'api_ok'=>null,
            'pbx'=>null, 'pbx_ok'=>null,
            'tftp'=>null, 'tftp_ok'=>null,
            // Compatibility aliases for callers written before selectable storage.
            'docker'=>null, 'docker_ok'=>null,
        ];
        if (!$this->isLocal() && !empty($this->site['pbx_api_enabled']) && !empty($this->site['pbx_api_ready'])) {
            try {
                $probe = $this->execApi(['method'=>'moduleStatus','args'=>[]]);
                if (empty($probe['ok'])) { throw new \RuntimeException((string)($probe['message'] ?? 'API probe failed')); }
                $extensions = $this->execApi(['method'=>'getAllSipExtensionsLocal','args'=>[]]);
                if (!array_is_list($extensions)) { throw new \RuntimeException('Management RPC returned an invalid extension catalog'); }
                $res['api'] = 'encrypted ' . strtoupper((string)($this->site['pbx_api_scheme'] ?? 'http'))
                    . ' API connected; module ' . (string)($probe['version'] ?? 'unknown')
                    . '; management RPC ready (' . count($extensions) . ' extension(s))';
                $res['api_ok'] = true;
            } catch (\Throwable $e) {
                $res['ok'] = false; $res['api_ok'] = false; $res['api'] = 'ERROR: ' . $e->getMessage();
            }
        } elseif ($this->isLocal()) {
            $res['api'] = 'local runtime'; $res['api_ok'] = true;
        } else {
            $res['api'] = 'not enabled; SSH agent is the control path';
        }
        try {
            if (!$this->isLocal() && !empty($this->site['pbx_agent_ready'])) {
                $payload = base64_encode((string)json_encode(['method' => 'listPjsipTrunks', 'args' => [0]]));
                $probe = json_decode($this->execAgent($payload), true);
                if (!is_array($probe) || empty($probe['ok'])) {
                    throw new \Exception((string)($probe['message'] ?? 'PBX agent probe failed'));
                }
                $res['pbx'] = 'ucmp-pbx agent connected (' . count($probe['trunks'] ?? []) . ' PJSIP trunk(s))';
            } else {
                $res['pbx'] = trim($this->execPbx('hostname')) ?: 'connected';
            }
            if ($this->isLocal()) {
                $res['pbx'] .= ' (local — no SSH needed)';
            }
            $res['pbx_ok'] = true;
        } catch (\Throwable $e) {
            $res['ok'] = false;
            $res['pbx_ok'] = false;
            $res['pbx'] = 'ERROR: ' . $e->getMessage();
        }
        try {
            $mode = self::activeTftpMode($this->site);
            $sftp = $this->tftpSftp($mode);
            $root = $this->tftpRoot();
            $list = $sftp->nlist($root);
            $res['tftp'] = is_array($list)
                ? (strtoupper($mode) . ': ' . count($list) . ' entries at ' . $root)
                : ('cannot list ' . $root);
            if ($list === false) {
                $res['ok'] = false;
                $res['tftp_ok'] = false;
            } else {
                $res['tftp_ok'] = true;
            }
        } catch (\Throwable $e) {
            $res['ok'] = false;
            $res['tftp_ok'] = false;
            $res['tftp'] = 'ERROR: ' . $e->getMessage();
        }
        $res['docker'] = $res['tftp'];
        $res['docker_ok'] = $res['tftp_ok'];
        $res['message'] = 'API: ' . (string)$res['api']
            . ' | PBX control: ' . (string)$res['pbx']
            . ' | TFTP: ' . (string)$res['tftp'];
        return $res;
    }

    /* ---------------- Host-key verification ---------------- */
    private function verifyHostKey($conn, string $expectedFp, string $label): void
    {
        $key = $conn->getServerPublicHostKey();
        if ($key === false) {
            throw new \Exception("Could not read host key for $label");
        }
        // phpseclib returns "<type> <base64>"; hash the raw key blob to SHA256.
        $parts = explode(' ', $key);
        $blob = base64_decode($parts[1] ?? '');
        $fp = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        
        if ($expectedFp === '') {
            throw new \Exception("No pinned SSH host key for $label; run the appropriate bootstrap action first (observed $fp)");
        }
        
        $want = (strpos($expectedFp, 'SHA256:') === 0) ? $expectedFp : ('SHA256:' . $expectedFp);
        if (!hash_equals($want, $fp)) {
            throw new \Exception("Host key mismatch for $label (got $fp, expected $want)");
        }
    }

    /** Read the current host-key fingerprint (for storing on first connect). */
    public static function fingerprint($conn): string
    {
        $key = $conn->getServerPublicHostKey();
        if ($key === false) { return ''; }
        $parts = explode(' ', $key);
        $blob = base64_decode($parts[1] ?? '');
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
