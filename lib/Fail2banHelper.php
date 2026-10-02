<?php
/**
 * Root-side, monitor-and-unban-only Fail2ban bridge.
 *
 * The installed copy is root-owned. It intentionally cannot write Fail2ban
 * configuration, change service state, ban clients, or invoke action commands.
 *
 * @license GPL-3.0-or-later
 */
namespace FreePBX\modules\Ucmprovisioner;

final class Fail2banHelper
{
    private const CLIENT = '/usr/bin/fail2ban-client';
    private const REGEX = '/usr/bin/fail2ban-regex';
    private const SYSTEMCTL = '/usr/bin/systemctl';
    private const LOCK_FILE = '/run/lock/ucmp-fail2ban.lock';
    private const MAX_OUTPUT = 262144;

    public function __construct(private $runner = null, private string $lockFile = self::LOCK_FILE) {}

    public function handle(array $request): array
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            throw new \RuntimeException('The Fail2ban helper must run as root');
        }
        $op = strtolower(trim((string)($request['op'] ?? 'overview')));
        $schema = match ($op) {
            'health' => ['op'],
            'overview' => ['op'],
            'detail' => ['op','jail'],
            'unban' => ['op','jail','ip'],
            'test_filter' => ['op','filter','sample'],
            default => throw new \InvalidArgumentException('Allowed operations are health, overview, detail, unban and test_filter'),
        };
        if (array_diff(array_keys($request), $schema)) {
            throw new \InvalidArgumentException('Unknown fields are not accepted by the Fail2ban helper');
        }
        return match ($op) {
            'health' => ['ok'=>true,'enrolled'=>true,'mode'=>'monitor_unban','message'=>'Root helper and constrained sudo path are working'],
            'overview' => $this->overview(),
            'detail' => $this->detail($this->jailName($request['jail'] ?? '')),
            'unban' => $this->lockedUnban(
                $this->jailName($request['jail'] ?? ''),
                $this->ip((string)($request['ip'] ?? ''))
            ),
            'test_filter' => $this->testFilter(
                (string)($request['filter'] ?? ''),
                (string)($request['sample'] ?? '')
            ),
        };
    }

    private function overview(): array
    {
        $service = $this->serviceState();
        $jails = [];
        foreach ($service['active'] ? $this->jailNames() : [] as $name) {
            try {
                $status = $this->status($name);
                $cfg = $this->effectiveConfig($name);
                $jails[] = array_merge(['name'=>$name], $status, [
                    'filter'=>(string)($cfg['values']['filter'] ?? $name),
                    'action'=>(string)($cfg['values']['action'] ?? ''),
                    'maxretry'=>(string)($cfg['values']['maxretry'] ?? ''),
                    'findtime'=>(string)($cfg['values']['findtime'] ?? ''),
                    'bantime'=>(string)($cfg['values']['bantime'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                $jails[] = ['name'=>$name, 'error'=>$e->getMessage(), 'currently_banned'=>0, 'currently_failed'=>0];
            }
        }
        usort($jails, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
        return [
            'ok'=>true, 'mode'=>'monitor_unban', 'service'=>$service, 'jails'=>$jails,
            'totals'=>[
                'jails'=>count($jails),
                'banned'=>array_sum(array_map(static fn(array $j): int => (int)($j['currently_banned'] ?? 0), $jails)),
                'failed'=>array_sum(array_map(static fn(array $j): int => (int)($j['currently_failed'] ?? 0), $jails)),
            ],
            'filters'=>$this->definitions('/etc/fail2ban/filter.d'),
        ];
    }

    private function detail(string $jail): array
    {
        if (!in_array($jail, $this->jailNames(), true)) {
            throw new \RuntimeException("Jail $jail is not loaded");
        }
        $cfg = $this->effectiveConfig($jail);
        $status = $this->status($jail);
        $events = $this->events($jail, 120);
        $banned = [];
        foreach ($status['banned_ips'] as $ip) {
            $history = array_values(array_filter($events, static fn(array $event): bool => ($event['ip'] ?? '') === $ip));
            $lastBan = null; $found = 0;
            foreach ($history as $event) {
                if ($event['event'] === 'Found') { $found++; }
                if ($event['event'] === 'Ban') { $lastBan = $event['time']; }
            }
            $banned[] = [
                'ip'=>$ip, 'banned_at'=>$lastBan, 'recent_failures'=>$found,
                'evidence'=>$this->evidence($ip, $status['logpaths']),
            ];
        }
        return [
            'ok'=>true, 'mode'=>'monitor_unban', 'jail'=>$jail, 'status'=>$status,
            'config'=>$cfg['values'], 'sources'=>$cfg['sources'],
            'banned'=>$banned, 'events'=>$events,
        ];
    }

    private function serviceState(): array
    {
        $active = $this->run([self::SYSTEMCTL, 'is-active', 'fail2ban'], [0,3,4]);
        $enabled = $this->run([self::SYSTEMCTL, 'is-enabled', 'fail2ban'], [0,1,3,4]);
        $version = $this->run([self::CLIENT, '--version']);
        return [
            'active'=>trim($active['stdout']) === 'active',
            'state'=>trim($active['stdout']) ?: 'unknown',
            'enabled'=>trim($enabled['stdout']) === 'enabled',
            'enabled_state'=>trim($enabled['stdout']) ?: 'unknown',
            'version'=>trim((string)preg_replace('/^Fail2Ban\s+/i', '', $version['stdout'])),
        ];
    }

    private function jailNames(): array
    {
        $out = $this->run([self::CLIENT, 'status'])['stdout'];
        if (!preg_match('/Jail list:\s*(.*)$/mi', $out, $match)) { return []; }
        $names = preg_split('/\s*,\s*/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter(array_map('trim', $names), static fn(string $name): bool => (bool)preg_match('/^[A-Za-z0-9_.-]+$/', $name)));
    }

    private function status(string $jail): array
    {
        $out = $this->run([self::CLIENT, 'status', $jail])['stdout'];
        $number = static fn(string $label): int => preg_match('/' . preg_quote($label, '/') . ':\s*(\d+)/i', $out, $m) ? (int)$m[1] : 0;
        $list = static fn(string $label): array => preg_match('/' . preg_quote($label, '/') . ':\s*(.*)$/mi', $out, $m)
            ? (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
        return [
            'currently_failed'=>$number('Currently failed'), 'total_failed'=>$number('Total failed'),
            'currently_banned'=>$number('Currently banned'), 'total_banned'=>$number('Total banned'),
            'banned_ips'=>$list('Banned IP list'), 'logpaths'=>$this->getList($jail, 'logpath'),
            'ignoreip'=>$this->getList($jail, 'ignoreip'), 'raw_status'=>$this->limit($out),
        ];
    }

    private function getList(string $jail, string $key): array
    {
        $result = $this->run([self::CLIENT, 'get', $jail, $key], [0,255]);
        if ($result['code'] !== 0) { return []; }
        $values = [];
        foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
            $line = trim((string)preg_replace('/^[`|\-\s]+/', '', $line));
            if ($line !== '' && stripos($line, 'list') === false) { $values[] = $line; }
        }
        return array_values(array_unique($values));
    }

    private function lockedUnban(string $jail, string $ip): array
    {
        $lock = @fopen($this->lockFile, 'c');
        if (!$lock) { throw new \RuntimeException('Unable to open the Fail2ban operation lock'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new \RuntimeException('Another Fail2ban operation is already running'); }
        try {
            $this->run([self::CLIENT, 'set', $jail, 'unbanip', $ip]);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
        $result = ['ok'=>true, 'message'=>"$ip was unbanned from $jail"];
        try { $result['detail'] = $this->detail($jail); }
        catch (\Throwable $error) {
            $result['reporting_error'] = $error->getMessage();
            $result['message'] .= '; the follow-up jail status could not be read. Refresh it before taking further action.';
        }
        return $result;
    }

    private function testFilter(string $filter, string $sample): array
    {
        $filter = $this->definition($filter, '/etc/fail2ban/filter.d');
        if ($sample === '' || strlen($sample) > 65536) {
            throw new \InvalidArgumentException('Paste 1 to 65536 bytes of sample log text');
        }
        $path = tempnam('/tmp', 'ucmp-f2b-');
        if (!$path) { throw new \RuntimeException('Unable to create filter-test file'); }
        try {
            file_put_contents($path, $sample, LOCK_EX); chmod($path, 0600);
            $definition = is_file("/etc/fail2ban/filter.d/$filter.local")
                ? "/etc/fail2ban/filter.d/$filter.local" : "/etc/fail2ban/filter.d/$filter.conf";
            $result = $this->run([self::REGEX, $path, $definition, '--print-all-matched'], [0,1]);
            return [
                'ok'=>true, 'matched'=>$result['code'] === 0,
                'message'=>$result['code'] === 0 ? 'Filter test completed' : 'Filter ran, but no match was found',
                'output'=>$this->limit($result['stdout'] . $result['stderr']),
            ];
        } finally { @unlink($path); }
    }

    /** @return array{values:array<string,string>,sources:array<string,string>} */
    private function effectiveConfig(string $jail): array
    {
        $defaults = []; $defaultSources = []; $jailValues = []; $jailSources = [];
        $files = ['/etc/fail2ban/jail.conf'];
        $files = array_merge($files, glob('/etc/fail2ban/jail.d/*.conf') ?: []);
        $files[] = '/etc/fail2ban/jail.local';
        $files = array_merge($files, glob('/etc/fail2ban/jail.d/*.local') ?: []);
        foreach (array_values(array_unique(array_filter($files, 'is_file'))) as $file) {
            $parsed = @parse_ini_file($file, true, INI_SCANNER_RAW);
            if (!is_array($parsed)) { continue; }
            foreach ((array)($parsed['DEFAULT'] ?? []) as $key=>$value) {
                if (is_scalar($value)) { $key=strtolower((string)$key); $defaults[$key]=trim((string)$value); $defaultSources[$key]=$file; }
            }
            foreach ((array)($parsed[$jail] ?? []) as $key=>$value) {
                if (is_scalar($value)) { $key=strtolower((string)$key); $jailValues[$key]=trim((string)$value); $jailSources[$key]=$file; }
            }
        }
        return ['values'=>array_replace($defaults, $jailValues), 'sources'=>array_replace($defaultSources, $jailSources)];
    }

    private function events(string $jail, int $limit): array
    {
        $events = [];
        foreach ($this->tail('/var/log/fail2ban.log', 1048576) as $line) {
            if (stripos($line, "[$jail]") === false) { continue; }
            if (preg_match('/^(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d).*\[' . preg_quote($jail, '/') . '\]\s+(Found|Ban|Unban|Restore Ban)\s+(\S+)/i', $line, $m)) {
                $events[] = ['time'=>$m[1], 'event'=>ucwords(strtolower($m[2])), 'ip'=>rtrim($m[3], ',')];
            }
        }
        return array_slice($events, -$limit);
    }

    private function evidence(string $ip, array $paths): array
    {
        $found = [];
        foreach ($paths as $pattern) {
            foreach ((glob($pattern) ?: [$pattern]) as $path) {
                if (!is_file($path) || str_ends_with($path, '.gz')) { continue; }
                foreach ($this->tail($path, 262144) as $line) {
                    if (strpos($line, $ip) !== false) { $found[]=['log'=>$path, 'line'=>$this->limit(trim($line), 600)]; }
                    if (count($found) >= 8) { return $found; }
                }
            }
        }
        return $found;
    }

    private function tail(string $path, int $bytes): array
    {
        if (!is_file($path) || !is_readable($path)) { return []; }
        $size = filesize($path) ?: 0; $offset = max(0, $size - $bytes);
        $data = file_get_contents($path, false, null, $offset, $bytes);
        if (!is_string($data)) { return []; }
        $lines = preg_split('/\R/', $data) ?: [];
        if ($offset > 0) { array_shift($lines); }
        return $lines;
    }

    private function definitions(string $dir): array
    {
        $found = [];
        foreach (array_merge(glob($dir . '/*.conf') ?: [], glob($dir . '/*.local') ?: []) as $file) {
            $found[pathinfo($file, PATHINFO_FILENAME)] = true;
        }
        $names = array_keys($found); natcasesort($names); return array_values($names);
    }

    private function definition(string $name, string $dir): string
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name) || (!is_file("$dir/$name.conf") && !is_file("$dir/$name.local"))) {
            throw new \InvalidArgumentException('Unknown Fail2ban filter');
        }
        return $name;
    }

    private function jailName(mixed $name): string
    {
        $name = trim((string)$name);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name)) { throw new \InvalidArgumentException('Invalid jail name'); }
        return $name;
    }

    private function ip(string $ip): string
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { throw new \InvalidArgumentException('A valid IPv4 or IPv6 address is required'); }
        return $ip;
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function run(array $command, array $allowed = [0]): array
    {
        if ($this->runner !== null) { return ($this->runner)($command, $allowed); }
        $stdoutPath = tempnam('/tmp', 'ucmp-f2b-o-');
        $stderrPath = tempnam('/tmp', 'ucmp-f2b-e-');
        if (!$stdoutPath || !$stderrPath) { throw new \RuntimeException('Unable to allocate command output files'); }
        try {
            $pipes = [];
            $proc = proc_open(['/usr/bin/timeout','--signal=KILL','15',...$command], [['file','/dev/null','r'],['file',$stdoutPath,'w'],['file',$stderrPath,'w']], $pipes, null, ['LC_ALL'=>'C','PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin'], ['bypass_shell'=>true]);
            if (!is_resource($proc)) { throw new \RuntimeException('Unable to start required system command'); }
            $code = proc_close($proc);
            $stdout = (string)file_get_contents($stdoutPath, false, null, 0, self::MAX_OUTPUT + 1);
            $stderr = (string)file_get_contents($stderrPath, false, null, 0, self::MAX_OUTPUT + 1);
        } finally { @unlink($stdoutPath); @unlink($stderrPath); }
        $result = ['code'=>$code, 'stdout'=>$this->limit($stdout), 'stderr'=>$this->limit($stderr)];
        if (!in_array($code, $allowed, true)) {
            $detail = trim($result['stderr'] . "\n" . $result['stdout']);
            throw new \RuntimeException($detail !== '' ? $detail : "System command failed with exit code $code");
        }
        return $result;
    }

    private function limit(string $value, int $max = self::MAX_OUTPUT): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) . "\n[output truncated]" : $value;
    }
}
