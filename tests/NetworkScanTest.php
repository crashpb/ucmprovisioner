<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/SiteConnection.php';
require_once dirname(__DIR__) . '/lib/NetworkScan.php';

use FreePBX\modules\Ucmprovisioner\NetworkScan;
use FreePBX\modules\Ucmprovisioner\SiteConnection;

final class NetworkScanConnectionStub extends SiteConnection
{
    public string $command = '';
    public string $response = "Nmap done: 256 IP addresses (0 hosts up)\n__UCMP_SCAN_EXIT__=0\n";

    public function __construct()
    {
        parent::__construct(['is_local'=>true]);
    }

    public function execPbx(string $command, bool $sudo = false): string
    {
        $this->command = $command;
        return $this->response;
    }

    public function isLocal(): bool
    {
        return true;
    }
}

final class NetworkScanUcmStub
{
    public int $connections = 0;
    public string $suggestedRanges = '10.20.30.0/24, 10.20.31.0/25';
    public NetworkScanConnectionStub $connection;

    public function __construct()
    {
        $this->connection = new NetworkScanConnectionStub();
    }

    public function getSite(int $siteId): ?array
    {
        return $siteId === 1
            ? ['id'=>1, 'voice_subnets'=>$this->suggestedRanges]
            : null;
    }

    public function siteConnection(int $siteId): NetworkScanConnectionStub
    {
        $this->connections++;
        return $this->connection;
    }

    public function getPhones(array $filters = []): array
    {
        return [];
    }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$ucm = new NetworkScanUcmStub();
$scan = new NetworkScan($ucm);

foreach ([
    ['999.20.30.0/24', 'nmap'],
    ['10.20.99.0/33', 'nmap'],
    ['10.20.99.0/24; id', 'nmap'],
    ['10.20.30.0/24', 'nmap; id'],
] as [$cidr, $method]) {
    $result = $scan->scan(1, $cidr, $method);
    $expect(empty($result['ok']), "Unsafe discovery request was accepted: $cidr / $method");
}
$expect($ucm->connections === 0, 'Rejected discovery requests must not open a site connection');
$expect(empty($scan->scan(99, '10.20.99.0/24')['ok']), 'Unknown sites must still be rejected');

$result = $scan->scan(1, '10.20.30.0/24', 'nmap');
$expect(!empty($result['ok']), 'A configured /24 discovery range was rejected');
$expect($ucm->connections === 1, 'Configured discovery range did not use exactly one site connection');
$expect(
    str_contains($ucm->connection->command, 'timeout 90s nmap --unprivileged -sn')
        && substr_count($ucm->connection->command, '10.20.30.0/24') === 1,
    'Active discovery must be bounded and pass the configured CIDR as one shell argument'
);
foreach (['10.20.0.0/19','10.0.0.0/8','0.0.0.0/0','10.20.30.1/32'] as $range) {
    $expect(!empty($scan->scan(1,$range)['ok']), 'An administrator-entered valid IPv4 subnet must be accepted');
}
$expect(empty($scan->scan(1,'10.20.30.0/24; id','nmap','admin','synthetic-password')['ok']), 'Invalid CIDR reached administrator execution');
foreach (['10.20.30.0/24', ''] as $suggested) {
    $ucm->suggestedRanges = $suggested;
    $expect(!empty($scan->scan(1, '10.20.99.0/24')['ok']), 'An entered range must work without a matching saved discovery range');
}

foreach (["Failed to open device\n__UCMP_SCAN_EXIT__=1\n", "__UCMP_SCAN_EXIT__=127\n", "__UCMP_SCAN_EXIT__=124\n", ''] as $failure) {
    $ucm->connection->response = $failure;
    $expect(empty($scan->scan(1, '10.20.30.0/24', 'nmap')['ok']), 'A failed or unconfirmed scan must not become an empty successful inventory');
}
$ucm->connection->response = implode("\n", [
    '10.20.31.10 dev ens192 lladdr 00:04:f2:00:00:10 REACHABLE',
    '10.20.31.200 dev ens192 lladdr 00:04:f2:00:00:20 REACHABLE',
    '10.20.30.50 dev ens192 lladdr 00:04:f2:00:00:30 REACHABLE',
]);
$result = $scan->scan(1, '10.20.31.0/25', 'arp');
$expect(!empty($result['ok']), 'A configured /25 neighbor-table scan was rejected');
$expect(
    array_column((array)($result['hosts'] ?? []), 'ip') === ['10.20.31.10'],
    'ARP discovery must enforce every CIDR bit instead of accepting the surrounding /24'
);

echo "Bounded network-discovery assertions passed\n";
