<?php
/**
 * Best-effort discovery for phones that are not provisioned yet.
 *
 * The active nmap mode finds routed IP endpoints and may also report a MAC
 * when the PBX is on the same Layer-2 network. The passive ARP mode reads only
 * the PBX kernel's neighbor table, so it cannot discover MAC addresses across
 * a router. A bounded HTTP probe adds a vendor hint for reachable embedded web
 * interfaces. Discovery never creates a phone or changes a configuration.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class NetworkScan
{
    /** Vendor OUI prefixes used to classify likely phone endpoints. */
    private const OUI_HINTS = [
        '00:04:F2' => 'Yealink',
        '00:1A:6D' => 'Cisco',
        '00:23:5E' => 'Cisco',
        'AC:44:F2' => 'Cisco',
        '00:3C:10' => 'Cisco',
        'D0:EC:35' => 'Cisco',
        'C4:B9:CD' => 'Cisco',
        'F8:A5:C5' => 'Cisco',
        '68:99:CD' => 'Cisco',
        '68:2C:7B' => 'Cisco',
        '30:8B:B2' => 'Cisco',
        '04:5F:B9' => 'Cisco',
        'F0:78:16' => 'Cisco',
        'CC:8E:71' => 'Cisco',
        'B8:62:1F' => 'Cisco',
        'B4:A8:B9' => 'Cisco',
        '0C:85:25' => 'Cisco',
        '0C:11:67' => 'Cisco',
        '00:E7:AC' => 'Cisco',
        '00:13:19' => 'Cisco',
        '00:08:30' => 'Cisco',
    ];

    private $ucm;

    public function __construct($ucm)
    {
        $this->ucm = $ucm;
    }

    /**
     * @param int    $siteId
     * @param string $cidr   e.g. "10.20.30.0/24"
     * @param string $method 'nmap' (active ping sweep, default) or 'arp' (passive local
     *                       neighbor-table dump — only useful for L2-local subnets)
     */
    public function scan(int $siteId, string $cidr, string $method = 'nmap', string $adminUser = '', string $adminPass = '', bool $useSudo = true): array
    {
        $cidr = trim($cidr);
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#D', $cidr, $cidrParts)
            || filter_var($cidrParts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'message' => "Invalid CIDR '$cidr' (expected e.g. 10.20.30.0/24)"];
        }
        $prefix = (int)$cidrParts[2];
        if ($prefix < 0 || $prefix > 32) {
            return ['ok' => false, 'message' => 'Enter a valid IPv4 subnet with prefix /0 through /32'];
        }
        if (!in_array($method, ['nmap', 'arp'], true)) {
            return ['ok' => false, 'message' => 'Choose either the nmap or ARP discovery method'];
        }
        $site = $this->ucm->getSite($siteId);
        if (!$site) {
            return ['ok' => false, 'message' => 'The selected site does not exist'];
        }
        try {
            $conn = $this->ucm->siteConnection($siteId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!$conn->isLocal()) {
            return ['ok'=>false, 'message'=>'Discovery must execute on the selected PBX. Send remote requests through its federation API.'];
        }

        if ($method === 'arp') {
            return $this->scanArp($conn, $cidr);
        }

        $raw = $adminPass !== ''
            ? $this->runAdminScan($cidr, $adminUser, $adminPass, $useSudo)
            : $conn->execPbx('timeout 90s nmap --unprivileged -sn -n ' . escapeshellarg($cidr) . ' 2>&1; printf "\n__UCMP_SCAN_EXIT__=%s\n" "$?"');
        $exit = preg_match('/^__UCMP_SCAN_EXIT__=(\d+)[\r\n]*\z/m', $raw, $match) ? (int)$match[1] : null;
        if ($exit === 124 || $exit === 137) {
            return ['ok' => false, 'message' => 'The bounded 90-second network scan timed out'];
        }
        if ($exit === 127 || stripos($raw, 'command not found') !== false) {
            return ['ok' => false, 'message' => 'nmap is not installed on that host'];
        }
        if (preg_match('/requires (root|superuser) privileges|raw socket|operation not permitted|sudo:/i', $raw)) {
            return ['ok' => false, 'message' => 'Privileged discovery could not open raw sockets. Supply this PBX administrator account and enable Use sudo for a non-root administrator.'];
        }
        if ($exit !== 0) {
            return ['ok'=>false, 'message'=>$exit === null
                ? 'Network scan completion was not confirmed; no empty inventory was inferred'
                : 'Network scan failed with exit status ' . $exit];
        }
        $hosts = $this->parsePingSweep($raw);
        $onRecord = count(array_filter($this->ucm->getPhones(['site_id' => $siteId]), fn($p) => !empty($p['mac']) || !empty($p['extension'])));

        if (!$hosts) {
            return [
                'ok' => true,
                'hosts' => [],
                'message' => "Discovery found no host responding to ping/ARP on $cidr from "
                    . ($conn->isLocal() ? 'the local PBX' : 'the site PBX') . ". The $onRecord existing phone record(s) and their configurations were not changed. "
                    . "Phones may reject ICMP/nmap probes even while SIP works, or the selected CIDR/routing may be incorrect. "
                    . 'Use the ARP method only when this host has direct layer-2 access, and verify the entered range against the phone network.',
            ];
        }

        $existingMacs = [];
        foreach ($this->ucm->getPhones() as $p) {
            if (!empty($p['mac'])) {
                $existingMacs[strtoupper($p['mac'])] = true;
            }
        }

        // Probe in bounded parallel batches so one slow handset does not multiply the page wait
        // by the number of discovered addresses.
        $guesses = $this->httpVendorGuessBatch($conn, array_column($hosts, 'ip'));

        foreach ($hosts as &$h) {
            if ($h['mac']) {
                $ouiKey = strtoupper(substr(str_replace([':', '-'], ':', $h['mac']), 0, 8));
                $h['vendor_hint'] = $h['vendor'] ?: (self::OUI_HINTS[$ouiKey] ?? null);
                $h['already_known'] = isset($existingMacs[str_replace([':', '-'], '', strtoupper($h['mac']))]);
            } else {
                $h['vendor_hint'] = null;
                $h['already_known'] = false;
            }
            $h['http_guess'] = $guesses[$h['ip']] ?? null;
        }
        unset($h);

        $newCount = count(array_filter($hosts, fn($h) => $h['mac'] && !($existingMacs[str_replace([':', '-'], '', strtoupper($h['mac']))] ?? false)));
        $knownCount = count($hosts) - $newCount;
        $breakdown = $hosts[0]['mac'] ?? null
            ? sprintf(' %d already-known / %d new (by MAC).', $knownCount, $newCount)
            : ' No Layer-2/MAC visibility from this vantage point — see the HTTP guess column and the phone\'s own screen/web UI to identify each one, then enter the MAC manually (or use "Bind this" below).';
        return [
            'ok'      => true,
            'hosts'   => $hosts,
            'message' => sprintf('Found %d live host(s) on %s.', count($hosts), $cidr) . $breakdown,
        ];
    }

    /** Authenticate with local PAM through su; only the fixed nmap command is elevated. */
    private function runAdminScan(string $cidr, string $user, string $password, bool $useSudo): string
    {
        $user = trim($user);
        if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.@-]{0,63}\$?\z/D', $user)
            || strlen($password) > 512 || strpbrk($password, "\r\n\0") !== false) {
            throw new \InvalidArgumentException('Supply a valid local PBX administrator username and a single-line password');
        }
        $sudo = $useSudo && $user !== 'root';
        $command = 'printf "__UCMP_SCAN_AUTH__\\n"; exec '
            . ($sudo ? "/usr/bin/sudo -k -S -p '' -- " : '')
            . '/usr/bin/timeout --kill-after=3s 90s /usr/bin/nmap --privileged -sn -n ' . escapeshellarg($cidr);
        $argv = ['/usr/bin/timeout', '--kill-after=3s', '105s', '/usr/bin/su', '--shell', '/bin/sh', '--command', $command, '--', $user];
        // CLI callers running as root must still authenticate the submitted password.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $argv = array_merge(['/usr/sbin/runuser', '--user', 'asterisk', '--'], $argv);
        }
        $process = proc_open($argv, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, '/',
            ['LC_ALL'=>'C', 'PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin'], ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Could not start local administrator authentication'); }
        $stdout = $stderr = '';
        $authenticated = false;
        $exit = null;
        try {
            fwrite($pipes[0], $password . "\n");
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 15;
            while (true) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                if (strlen($stdout) + strlen($stderr) > 2097152) {
                    throw new \RuntimeException('Network scan output exceeded its limit; scan a smaller range');
                }
                if (!$authenticated && str_contains($stdout, "__UCMP_SCAN_AUTH__\n")) {
                    $authenticated = true;
                    $deadline = microtime(true) + 98;
                    // PAM may buffer stdin. Send sudo its password only after su
                    // has authenticated and started the administrator's command.
                    if ($sudo) { fwrite($pipes[0], $password . "\n"); }
                    fclose($pipes[0]);
                }
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException($authenticated
                        ? 'The bounded local network scan timed out'
                        : 'Local administrator authentication timed out');
                }
                usleep(20000);
            }
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (!str_contains($stdout, "__UCMP_SCAN_AUTH__\n")) {
                throw new \RuntimeException('Local administrator authentication failed. Check the selected PBX account, password and local login policy.');
            }
            if (stripos($stderr, 'sudo:') !== false && $exit !== 0) {
                throw new \RuntimeException('Local administrator sudo authorization failed. Use a root or sudo-capable PBX account.');
            }
            return $stdout . $stderr . "\n__UCMP_SCAN_EXIT__=" . $exit . "\n";
        } finally {
            if ($exit === null) { proc_terminate($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($process);
        }
    }

    /** Passive neighbor-table scan; routed networks normally have no target MAC entries here. */
    private function scanArp(SiteConnection $conn, string $cidr): array
    {
        $raw = $conn->execPbx('ip neigh show 2>&1');
        $hosts = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            // Example: "192.0.2.13 dev ens192 lladdr 00:50:56:86:26:d9 REACHABLE"
            if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s.*lladdr\s+([0-9A-Fa-f:]{17})/', $line, $m)) {
                continue;
            }
            if (!$this->ipInCidr($m[1], $cidr)) {
                continue;
            }
            $hosts[] = ['ip' => $m[1], 'mac' => strtoupper($m[2]), 'vendor' => null];
        }

        if (!$hosts) {
            return [
                'ok'      => true,
                'hosts'   => [],
                'message' => "No ARP/neighbor entries for $cidr. This is expected for a routed remote subnet — ARP cannot cross a router, so this box's kernel has never learned any MACs there. Use the 'nmap' method for IP-level reachability instead, or run an ARP scan from a host that actually sits on that VLAN.",
            ];
        }
        foreach ($hosts as &$h) {
            $ouiKey = strtoupper(substr($h['mac'], 0, 8));
            $h['vendor_hint'] = self::OUI_HINTS[$ouiKey] ?? null;
            $h['already_known'] = false;
            $h['http_guess'] = null;
        }
        unset($h);
        return ['ok' => true, 'hosts' => $hosts, 'message' => sprintf('Found %d entry(s) in the local ARP table for %s.', count($hosts), $cidr)];
    }

    /** Compare two packed IPv4 addresses using the exact CIDR bit length. */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $bitsText] = array_pad(explode('/', $cidr, 2), 2, '');
        $bits = (int)$bitsText;
        $addressBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($bits < 0 || $bits > 32 || strlen((string)$addressBytes) !== 4 || strlen((string)$networkBytes) !== 4) {
            return false;
        }
        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }
        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }

    /** Parse `nmap -sn` output into a list of {ip, mac, vendor}. */
    private function parsePingSweep(string $raw): array
    {
        $hosts = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/Nmap scan report for\s+(?:[\w.-]+\s+\()?(\d{1,3}(?:\.\d{1,3}){3})\)?/', $line, $m)) {
                if ($current) {
                    $hosts[] = $current;
                }
                $current = ['ip' => $m[1], 'mac' => null, 'vendor' => null];
                continue;
            }
            if ($current !== null && preg_match('/MAC Address:\s*([0-9A-Fa-f:]{17})(?:\s*\(([^)]+)\))?/', $line, $m)) {
                $current['mac'] = strtoupper($m[1]);
                $current['vendor'] = $m[2] ?? null;
            }
        }
        if ($current) {
            $hosts[] = $current;
        }
        return $hosts;
    }

    /**
     * Parallel HTTP GET across all discovered IPs in ONE shell round-trip (short timeout each,
     * capped concurrency) to guess vendor from each phone's embedded web UI. Each response is
     * written to its own temp file to avoid any output-interleaving parsing headaches.
     *
     * @param string[] $ips
     * @return array<string,?string> ip => vendor guess (or null)
     */
    private function httpVendorGuessBatch(SiteConnection $conn, array $ips): array
    {
        if (!$ips) {
            return [];
        }
        // Debian's /bin/sh does not require wait -n here: each bounded batch starts in parallel
        // and a plain wait completes that batch before the next one is launched.
        $dir = '/tmp/ucmp-scan-' . bin2hex(random_bytes(6));
        $script = 'mkdir -p ' . escapeshellarg($dir) . ';';
        foreach (array_chunk($ips, 25) as $chunk) {
            foreach ($chunk as $ip) {
                $script .= ' curl -s -m 1 -o ' . escapeshellarg("$dir/$ip.txt") . ' -D ' . escapeshellarg("$dir/$ip.txt")
                    . ' ' . escapeshellarg("http://$ip/") . ' >/dev/null 2>&1 &';
            }
            $script .= ' wait;';
        }
        $script .= ' for f in ' . escapeshellarg($dir) . '/*.txt; do echo "===IP:$(basename "$f" .txt)==="; cat "$f" 2>/dev/null; done;'
            . ' rm -rf ' . escapeshellarg($dir);

        $raw = $conn->execPbx($script);
        $guesses = [];
        // Each block: "===IP:1.2.3.4===\n<response bytes until the next marker or EOF>"
        if (preg_match_all('/===IP:([\d.]+)===\r?\n(.*?)(?====IP:[\d.]+===|\z)/s', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $guesses[$m[1]] = $this->extractVendor($m[2]);
            }
        }
        return $guesses;
    }

    private function extractVendor(string $resp): ?string
    {
        if ($resp === '') {
            return null;
        }
        foreach (['Cisco', 'Grandstream', 'Yealink'] as $vendor) {
            if (stripos($resp, $vendor) !== false) {
                return $vendor;
            }
        }
        if (preg_match('/^Server:\s*(.+)$/mi', $resp, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
