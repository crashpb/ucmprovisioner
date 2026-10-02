<?php
/**
 * LiveStatus — on-demand fleet health check for the Phones list: which phones
 * are actually registered with Asterisk right now, and which respond to a
 * ping (helps tell "phone is fine, config/registration is wrong" apart from
 * "phone/network is actually down").
 *
 * Deliberately NOT run automatically on every page load (100+ phones would
 * make the list slow) — triggered by a "Refresh Status" button, one batched
 * `sip show peers` and `pjsip show contacts` calls plus
 * one parallel ping sweep of all known IPs.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class LiveStatus
{
    private $ucm;

    public function __construct($ucm)
    {
        $this->ucm = $ucm;
    }

    /** @return array<string,array{registered:bool,reachable:?bool,ip:?string,status_raw:string}> keyed by extension */
    public function check(int $siteId): array
    {
        $conn = $this->ucm->siteConnection($siteId);
        $raw = $conn->asterisk('sip show peers');
        $sip = $this->parseSipShowPeers($raw);
        $pjsip = [];
        foreach ($this->ucm->getPhones(['site_id'=>$siteId]) as $phone) {
            $ext = trim((string)($phone['extension'] ?? ''));
            $driver = strtolower((string)($phone['extension_driver'] ?? $phone['driver'] ?? ''));
            if ($ext !== '' && $driver === 'pjsip') {
                $pjsip[$ext] = ['registered'=>false, 'ip'=>null, 'status_raw'=>'Unregistered', 'driver'=>'pjsip'];
            }
        }
        foreach ($this->parsePjsipContacts($conn->asterisk('pjsip show contacts')) as $ext=>$contact) {
            $pjsip[$ext] = $contact;
        }
        $sip = array_replace($sip, $pjsip);

        $ips = array_values(array_filter(array_unique(array_column($sip, 'ip'))));
        $pingable = $this->pingBatch($conn, $ips);

        foreach ($sip as $ext => &$row) {
            $row['reachable'] = $row['ip'] ? ($pingable[$row['ip']] ?? false) : null;
            if ($row['registered']) {
                $this->touchLastSeen($ext, $siteId, $row['ip']);
            }
        }
        unset($row);

        return $sip;
    }

    /**
     * Confirms a phone as live under its CURRENT (db) extension: bumps last_seen, opportunistically
     * corrects a stale/blank IP to what it's actually registered from, and — importantly — clears
     * prev_extension once we've proven it (the fallback in PhoneCommand is no longer needed, and a
     * stale prev_extension would otherwise keep pointing restarts at a now-reused old extension).
     */
    private function touchLastSeen(string $ext, int $siteId, ?string $liveIp): void
    {
        try {
            $this->ucm->getDb()->prepare(
                'UPDATE ucmp_phones SET last_seen = ?, prev_extension = NULL' . ($liveIp ? ', ip = ?' : '') . ' WHERE extension = ? AND (site_id = ? OR site_id IS NULL)'
            )->execute($liveIp ? [time(), $liveIp, $ext, $siteId] : [time(), $ext, $siteId]);
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /** @return array<string,array{registered:bool,ip:?string,status_raw:string}> */
    private function parseSipShowPeers(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'Name/username') === 0) {
                continue;
            }
            if (!preg_match('/^(\S+)\s+(\S+)\s.*?(OK\s*\([\d.]+\s*ms\)|UNREACHABLE!?|UNKNOWN|Unmonitored|LAGGED\s*\([\d.]+\s*ms\))/', $line, $m)) {
                continue;
            }
            $ext = explode('/', $m[1])[0];
            if (!ctype_digit($ext)) {
                continue;
            }
            $host = $m[2];
            $status = trim($m[3]);
            $out[$ext] = [
                'registered' => (stripos($status, 'OK') === 0 || stripos($status, 'LAGGED') === 0),
                'ip'         => filter_var($host, FILTER_VALIDATE_IP) ? $host : null,
                'status_raw' => $status,
                'driver'     => 'sip',
            ];
        }
        return $out;
    }

    /** @return array<string,array{registered:bool,ip:?string,status_raw:string,driver:string}> */
    private function parsePjsipContacts(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (!preg_match('#^\s*Contact:\s+([^/\s]+)/sip:[^@\s]+@(\[[0-9a-f:]+\]|[^:;\s]+)(?::\d+)?[^\s]*\s+\S+\s+(\S+)#i', $line, $match)) {
                continue;
            }
            $ext = trim((string)$match[1]);
            if ($ext === '') { continue; }
            $ip = trim((string)$match[2], '[]');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) { continue; }
            $status = trim((string)$match[3]);
            $contact = [
                'registered'=>in_array(strtolower($status), ['avail','nonqual'], true),
                'ip'=>$ip,
                'status_raw'=>$status,
                'driver'=>'pjsip',
            ];
            $out[$ext]['contacts'][]=$contact;
        }
        foreach ($out as &$row) {
            usort($row['contacts'],static fn(array $a,array $b): int=>
                ((int)$b['registered']<=>(int)$a['registered']) ?: strcmp($a['ip'] . ':' . $a['status_raw'],$b['ip'] . ':' . $b['status_raw']));
            $row=array_merge($row,$row['contacts'][0]);
            $row['contact_count']=count($row['contacts']);
            $row['observed_at']=time();
        }
        unset($row);
        return $out;
    }

    /** Parallel ping sweep (single batched shell round-trip, portable dash/bash chunking). */
    private function pingBatch(SiteConnection $conn, array $ips): array
    {
        if (!$ips) {
            return [];
        }
        $script = '';
        foreach (array_chunk($ips, 25) as $chunk) {
            foreach ($chunk as $ip) {
                $script .= ' ping -c1 -W1 ' . escapeshellarg($ip) . ' >/dev/null 2>&1 && echo ' . escapeshellarg("UP:$ip") . ' || echo ' . escapeshellarg("DOWN:$ip") . ' &';
            }
            $script .= ' wait;';
        }
        $raw = $conn->execPbx($script);
        $result = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (preg_match('/^(UP|DOWN):(.+)$/', trim($line), $m)) {
                $result[$m[2]] = ($m[1] === 'UP');
            }
        }
        return $result;
    }
}
