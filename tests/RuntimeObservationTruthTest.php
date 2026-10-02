<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\LiveStatus;
use FreePBX\modules\Ucmprovisioner\PhoneCommand;
$parse=new ReflectionMethod(LiveStatus::class,'parsePjsipContacts');
$parser=new LiveStatus(new stdClass());
$live='Contact: 4308/sip:4308@192.0.2.1:5060 abc Avail 10.1';
$dead='Contact: 4308/sip:4308@192.0.2.2:5060 def Unavail nan';
$one=$parse->invoke($parser,$live . "\n" . $dead);
$two=$parse->invoke($parser,$dead . "\n" . $live);
unset($one[4308]['observed_at'],$two[4308]['observed_at']);
if ($one!==$two || !$one[4308]['registered'] || $one[4308]['ip']!=='192.0.2.1' || $one[4308]['contact_count']!==2) {
    throw new RuntimeException('Registration aggregation depends on CLI contact order');
}
$unknown=$parse->invoke($parser,'Contact: 4308/sip:4308@192.0.2.3:5060 ghi Unknown nan');
if ($unknown[4308]['registered']) { throw new RuntimeException('Unknown contact status was called registered'); }
$ipv6=$parse->invoke($parser,'Contact: 4309/sip:4309@[2001:db8::1]:5060 abc Avail 1');
$invalid=$parse->invoke($parser,'Contact: 4309/sip:4309@192.0.2... abc Avail 1');
if (($ipv6[4309]['ip']??'')!=='2001:db8::1' || $invalid) { throw new RuntimeException('Live status must retain valid IPv6 but never display truncated contact addresses as IPs'); }
$sipParse=new ReflectionMethod(LiveStatus::class,'parseSipShowPeers');
$unmonitored=$sipParse->invoke($parser,'4309/4309 192.0.2.9 D Yes Yes 5060 Unmonitored');
if (($unmonitored[4309]['ip']??'')!=='192.0.2.9' || $unmonitored[4309]['registered']) { throw new RuntimeException('Unmonitored SIP addresses must remain visible without inventing registration evidence'); }
foreach (['','No such command','Unable to find notify type','Endpoint not found','Failed to send notify',
    'No sip_notify.conf file found, or no types listed there', 'Usage: sip notify <type> <peer>',
    "Sending NOTIFY of type 'cisco-restart' to '4308'\nUnable to retrieve endpoint 4308"] as $response) {
    try { PhoneCommand::assertCommandResponse($response); throw new LogicException('Command failure reported as success'); }
    catch (RuntimeException $e) { if ($e instanceof LogicException) { throw $e; } }
}
PhoneCommand::assertCommandResponse('Sending NOTIFY to 4308');
try {
    PhoneCommand::assertCommandResponse("Could not create address for '4308'");
    throw new LogicException('Unroutable peer must fail');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'no routable address')) { throw $error; }
}
PhoneCommand::assertCommandResponse("Sending NOTIFY of type 'cisco-restart' to '4308'");

// Execute the real command dispatcher with only transport/database boundaries replaced.
$commandConnection = new class([]) extends FreePBX\modules\Ucmprovisioner\SiteConnection {
    public int $syncAttempts = 0;
    public array $sent = [];
    public function dockerSftp(): phpseclib3\Net\SFTP {
        $this->syncAttempts++;
        throw new RuntimeException('Fixture TFTP backend unavailable');
    }
    public function asterisk(string $cli): string {
        $this->sent[] = $cli;
        return 'Sending NOTIFY to fixture';
    }
};
$commandModule = new class($commandConnection) {
    public array $reads = [];
    public bool $failSecondRead = false;
    public function __construct(public $connection) {}
    public function localSiteId(): int { return 1; }
    public function getPhone(int $id): array {
        $this->reads[$id] = ($this->reads[$id] ?? 0) + 1;
        if ($id === 2 && $this->failSecondRead && $this->reads[$id] > 1) {
            throw new RuntimeException('Fixture second phone lookup failed');
        }
        return ['id'=>$id, 'site_id'=>1, 'extension'=>(string)(700+$id),
            'extension_driver'=>'pjsip', 'family'=>$id === 3 ? 'cisco_cnf' : 'cisco_xml'];
    }
    public function siteConnection(int $id) { return $this->connection; }
    public function getDb() { throw new RuntimeException('Fixture reporting unavailable'); }
};
$commands = new PhoneCommand($commandModule);
$commandModule->failSecondRead = true;
$batch = $commands->sendBatch([1,2,1], 'restart');
if ($batch['succeeded'] !== 1 || $batch['failed'] !== 1 || !$batch['results'][1]['ok']
    || !str_contains($batch['results'][2]['message'], 'lookup failed') || count($commandConnection->sent) !== 1) {
    throw new RuntimeException('Late phone preparation failure erased an earlier command or repeated a duplicate');
}
$single = $commands->send(2, 'restart');
if ($single['ok'] || !str_contains($single['message'], 'lookup failed')) {
    throw new RuntimeException('Single-phone preparation errors escaped the staged build result');
}
$commandModule->failSecondRead = false;
$batch = $commands->sendBatch([3,1,2], 'reboot');
if ($batch['succeeded'] !== 2 || $batch['failed'] !== 1 || $batch['results'][3]['stage'] !== 'legacy_sync'
    || $commandConnection->syncAttempts !== 1 || count($commandConnection->sent) !== 3) {
    throw new RuntimeException('Legacy sync failure stopped independent XML phones or sent an unsafe legacy command');
}
$commands->sendBatch([3], 'factory');
if ($commandConnection->syncAttempts !== 1) {
    throw new RuntimeException('An unsupported factory-reset command modified legacy sync');
}
$discoveryConnection = new class([]) extends FreePBX\modules\Ucmprovisioner\SiteConnection {
    public array $queries = [];
    public function asterisk(string $cli): string {
        $this->queries[] = $cli;
        return match ($cli) {
            'pjsip show endpoint 700' => "Contact: 700/sip:700@192.0.2.2:5060 abc Unavail nan\nContact: 700/sip:700@192.0.2.1:5060;trans def Avail 10\n aors : 700\n",
            'pjsip show aor 700' => "Contact: 700/sip:700@192.0.2.1:5060;trans def Avail 10\n contact : 700/sip:700@192.0.2.2:5060,700/sip:700@192.0.2.1:5060;transport=TCP\n",
            'pjsip show contact 700/sip:700@192.0.2.1:5060;transport=TCP' => " uri : sip:700@192.0.2.1:5060;transport=TCP\n user_agent : Cisco-CP7841 SEP001122334455\n via_addr : \n via_port : 5060\n",
            'pjsip show endpoint 701' => "Contact: 701/sip:701@[2001:db8::1]:5060 abc NonQual nan\n",
            'pjsip show contact 701/sip:701@[2001:db8::1]:5060' => "URI: sip:701@[2001:db8::1]:5060\nUserAgent: Yealink fixture\nViaAddr: 2001:db8::2\n",
            default => throw new RuntimeException('Unexpected or truncated contact identifier: ' . $cli),
        };
    }
};
$discovery = new FreePBX\modules\Ucmprovisioner\Discovery(new stdClass());
$contactMethod = new ReflectionMethod($discovery, 'pjsipEndpointDetail');
$observed = $contactMethod->invoke($discovery, $discoveryConnection, '700');
if ($observed['ip'] !== '192.0.2.1' || $observed['mac'] !== '001122334455' || $observed['useragent'] === '') {
    throw new RuntimeException('Discovery did not preserve the live CLI contact, full URI or snake-case fields');
}
if ($contactMethod->invoke($discovery, $discoveryConnection, '701')['ip'] !== '2001:db8::2') {
    throw new RuntimeException('Discovery lost compatible IPv6 Via-address handling');
}
echo "Order-independent registration and command-result truth fixtures passed\n";
