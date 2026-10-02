<?php
declare(strict_types=1);
namespace {
    if (PHP_OS_FAMILY === 'Windows') { echo "Local authentication pipe assertions require Linux; run this fixture from the packaged source on the PBX.\n"; exit(0); }
    if (($argv[1] ?? '') === '--scan-child') {
        $password = rtrim((string)fgets(STDIN), "\r\n");
        if ($password !== 'synthetic-password') { fwrite(STDERR, 'su: Authentication failure'); exit(1); }
        echo "__UCMP_SCAN_AUTH__\n"; fflush(STDOUT);
        if (($argv[2] ?? '') === 'sudo' && rtrim((string)fgets(STDIN), "\r\n") !== $password) { exit(2); }
        if (($argv[3] ?? '') === 'denied') { fwrite(STDERR, 'sudo: account is not authorized'); exit(1); }
        if (($argv[3] ?? '') === 'timeout') { exit(124); }
        echo "Nmap scan report for 192.0.2.10\nHost is up.\nNmap done\n"; exit(0);
    }
}
namespace FreePBX\modules\Ucmprovisioner {
    function proc_open($command, $descriptors, &$pipes, $cwd = null, $env = null, $options = []): mixed {
        $GLOBALS['scanProcesses'][] = $command;
        $shell = $command[array_search('--command', $command, true) + 1];
        return \proc_open([PHP_BINARY, __FILE__, '--scan-child', str_contains($shell, '/usr/bin/sudo ') ? 'sudo' : 'direct', $GLOBALS['scanMode']],
            $descriptors, $pipes, null, null, $options);
    }
}
namespace phpseclib3\Net {
    class SSH2 { public function __construct(...$args) { throw new \LogicException('A scan attempted SSH'); } }
}
namespace {
    interface BMO {}
    class FreePBX_Helpers {}
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
    require_once dirname(__DIR__) . '/lib/SiteConnection.php';
    use FreePBX\modules\Ucmprovisioner\SiteConnection;
    use FreePBX\modules\Ucmprovisioner\RpcResponse;
    class ScanConnection extends SiteConnection {
        public array $calls = [], $commands = [];
        public string $failure = '';
        public ?ScanModule $receiver = null;
        public function execPbx(string $command, bool $sudo = false): string {
            if (!$this->isLocal()) { throw new LogicException('Remote shell execution attempted'); }
            $this->commands[] = $command;
            return str_contains($command, 'nmap ') ? "Nmap done\n__UCMP_SCAN_EXIT__=0\n" : '';
        }
        public function execApi(array $request): mixed {
            $this->calls[] = $request;
            if ($this->failure !== '') { throw new RuntimeException($this->failure); }
            return RpcResponse::unwrap(json_decode($this->receiver->remoteDispatch(base64_encode(json_encode($request))), true));
        }
        public function execAgent(string $payload): string { throw new LogicException('SSH recovery attempted'); }
    }
    class ScanModule extends \FreePBX\modules\Ucmprovisioner {
        public array $connections = [];
        public bool $apiReady = true;
        public function __construct(private int $local = 1) {
            parent::__construct(new class { public function Database(): PDO { return new PDO('sqlite::memory:'); } });
        }
        public function localSiteId(): int { return $this->local; }
        public function getSite(int $id, bool $withSecrets = false): ?array {
            return $id === 99 ? null : ['id'=>$id, 'is_local'=>$id === $this->local, 'pbx_host_key'=>'',
                'pbx_api_enabled'=>1, 'pbx_api_ready'=>$this->apiReady];
        }
        public function getPhones(array $filters = []): array { return []; }
        public function siteConnection(int $siteId): SiteConnection {
            return $this->connections[$siteId] ??= new ScanConnection($this->getSite($siteId));
        }
        public function remoteQuery(int $siteId, string $method, array $args = []): mixed {
            throw new LogicException('Scan used the dispatcher with SSH fallback');
        }
    }
    $expect = static function(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
    $GLOBALS['scanMode'] = ''; $GLOBALS['scanProcesses'] = [];
    $module = new ScanModule();
    foreach ([['192.0.2.0/24; id','nmap','admin','synthetic-password'], ['192.0.2.0/24','bad','admin','synthetic-password'],
        ['192.0.2.0/24','nmap','admin;id','synthetic-password'], ['192.0.2.0/24','nmap','admin',"bad\npassword"]] as $args) {
        $result = $module->networkScan(1, ...$args);
        $expect(empty($result['ok']) && $GLOBALS['scanProcesses'] === [], 'Unsafe input must not start authentication');
    }
    foreach ([['admin',true], ['root',false], ['admin',false]] as [$user,$sudo]) {
        $result = $module->networkScan(1,'192.0.2.0/24','nmap',$user,'synthetic-password',$sudo);
        $expect(!empty($result['ok']) && $result['hosts'][0]['ip'] === '192.0.2.10', 'Local scan with blank SSH pin failed: '.json_encode($result));
        $command = end($GLOBALS['scanProcesses']);
        $expect(in_array('/usr/bin/su',$command,true) && !str_contains(json_encode($command),'synthetic-password'), 'Credentials must go only to local process stdin');
        $shell = $command[array_search('--command',$command,true)+1];
        $expect(str_contains($shell,'/usr/bin/timeout --kill-after=3s 90s /usr/bin/nmap --privileged -sn -n')
            && str_contains($shell,'/usr/bin/sudo ') === ($sudo && $user !== 'root'), 'Incorrect local privilege/timeout command');
        $expect(!str_contains(json_encode($result),'synthetic-password'), 'Password appeared in scan results');
    }
    foreach (['wrong-password','synthetic-password'] as $password) {
        $GLOBALS['scanMode'] = $password === 'synthetic-password' ? 'denied' : '';
        $result = $module->networkScan(1,'192.0.2.0/24','nmap','admin',$password,true);
        $expect(empty($result['ok']) && !str_contains(json_encode($result),$password), 'Authentication/authorization failures must be explicit and secret-free');
    }
    $GLOBALS['scanMode'] = 'timeout';
    $result = $module->networkScan(1,'192.0.2.0/24','nmap','admin','synthetic-password',true);
    $expect(empty($result['ok']) && str_contains($result['message'],'timed out'), 'Timed out scans must not become empty success');
    $GLOBALS['scanMode'] = '';
    $peer = $module->siteConnection(2);
    $peer->receiver = new ScanModule(7);
    $result = $module->networkScan(2,'192.0.2.0/24','nmap','admin','synthetic-password',true);
    $expect(!empty($result['ok']) && count($peer->calls) === 1 && isset($peer->receiver->connections[7]), 'API request must execute through the receiver local path: '.json_encode($result));
    $expect(!isset($peer->calls[0]['operation_id']), 'Temporary credentials must not enter the operation journal');
    $before = count($GLOBALS['scanProcesses']);
    $peer->failure = 'API unavailable synthetic-password';
    $result = $module->networkScan(2,'192.0.2.0/24','nmap','admin','synthetic-password',true);
    $expect(empty($result['ok']) && count($peer->calls) === 2 && count($GLOBALS['scanProcesses']) === $before
        && !str_contains(json_encode($result),'synthetic-password'), 'API failures must stop without retry, SSH or credential exposure');
    $module->apiReady = false;
    $expect(empty($module->networkScan(2,'192.0.2.0/24')['ok']) && count($peer->calls) === 2, 'Disabled API must not attempt another channel');
    $expect(empty($module->networkScan(99,'192.0.2.0/24')['ok']), 'Unknown site accepted');
    $expect(!empty($module->networkScan(1,'192.0.2.0/24')['ok']), 'Unprivileged local discovery regressed');
    $expect(!empty($module->networkScan(1,'192.0.2.0/24','arp','admin','synthetic-password')['ok'])
        && count($GLOBALS['scanProcesses']) === $before, 'ARP or unprivileged discovery started administrator authentication');
    $scan = new \FreePBX\modules\Ucmprovisioner\NetworkScan($module);
    $expect(empty($scan->scan(2,'192.0.2.0/24')['ok']), 'Direct scanner call accepted a remote shell connection');
    echo "Local authentication, API parity, blank pins, no SSH fallback, secrets and failure assertions passed\n";
}
