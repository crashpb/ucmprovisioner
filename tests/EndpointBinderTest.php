<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/EndpointProfile.php';
require_once dirname(__DIR__) . '/lib/ExtensionBinder.php';

use FreePBX\modules\Ucmprovisioner\ExtensionBinder;

foreach ([['sip','udp,tcp,tls','udp',true],['sip','UDP, TCP','tcp',true],['sip','tcp','udp',false],
    ['sip','','udp',false],['pjsip','0.0.0.0-udp','0.0.0.0-udp',true],['pjsip','0.0.0.0-udp,0.0.0.0-tcp','0.0.0.0-udp',false]] as [$driver,$configured,$desired,$expected]) {
    if (ExtensionBinder::deviceAcceptsTransport(['tech'=>$driver,'transport'=>$configured],$driver,$desired)!==$expected) {throw new RuntimeException('Identity transport compatibility failed');}
}
if (ExtensionBinder::deviceAcceptsTransport(['tech'=>'pjsip','transport'=>'udp'],'sip','udp')) {throw new RuntimeException('Driver mismatch accepted');}

final class EndpointBinderFakeCore
{
    public array $device = [
        'id'=>'700', 'tech'=>'pjsip', 'secret'=>'old-secret',
        'transport'=>'0.0.0.0-tcp', 'description'=>'Test', 'user'=>'700',
    ];
    public int $writes = 0;
    public function getDevice(string $ext): array { return $ext === '700' ? $this->device : []; }
    public function delDevice(string $ext, bool $editMode): bool { return true; }
    public function addDevice(string $ext, string $tech, array $settings, bool $editMode): bool
    {
        $this->writes++;
        $this->device = ['id'=>$ext, 'tech'=>$tech];
        foreach ($settings as $key=>$row) { $this->device[$key] = $row['value']; }
        return true;
    }
}

if (!class_exists('FreePBX', false)) {
    final class FreePBX
    {
        public static EndpointBinderFakeCore $core;
        public static function Core(): EndpointBinderFakeCore { return self::$core; }
    }
}
FreePBX::$core = new EndpointBinderFakeCore();

$ucm = new class {
    public function localSiteId(): int { return 1; }
    public function siteConnection(int $siteId): object {
        return new class {
            public function asterisk(string $command): string {
                return "Transport:  0.0.0.0-udp udp 3 96 0.0.0.0:5160\n"
                    . "Transport:  0.0.0.0-tcp tcp 3 96 0.0.0.0:5160\n";
            }
        };
    }
};

$binder = new ExtensionBinder($ucm, static function (array $receipt): void {});
if (empty($binder->preflightEndpoint('700', 'udp', 'pjsip', 5160)['ok'])
    || !empty($binder->preflightEndpoint('700', 'udp', 'pjsip', 5060)['ok'])
    || !empty($binder->preflightEndpoint('700', 'udp', 'sip', 5060)['ok'])
    || !empty($binder->preflightEndpoint('999', 'udp', 'pjsip', 5160)['ok'])
    || FreePBX::$core->writes !== 0) {
    throw new RuntimeException('Endpoint preflight must verify every existing account and listener without writes');
}
$result = $binder->updateSecretTransport('700', null, 'udp', 256, 'test phone', 'pjsip', 5160);
if (empty($result['ok']) || empty($result['changed'])
    || FreePBX::$core->device['transport'] !== '0.0.0.0-udp'
    || FreePBX::$core->writes !== 1) {
    throw new RuntimeException('PJSIP binding must map protocol/port to the exact FreePBX transport object');
}

$mismatch = $binder->updateSecretTransport('700', null, 'udp', 256, 'test phone', 'sip', 5060);
if (!empty($mismatch['ok']) || FreePBX::$core->writes !== 1
    || !str_contains((string)($mismatch['message'] ?? ''), 'requested phone profile')) {
    throw new RuntimeException('The module must reject, not convert, a FreePBX endpoint driver mismatch');
}

$provisioner = (string)file_get_contents(dirname(__DIR__) . '/lib/Provisioner.php');
$notifyAt = strpos($provisioner, '$phoneCommand->send');
$reloadAt = strpos($provisioner, '\\FreePBX::Framework()->doReload');
if ($notifyAt === false || $reloadAt === false || $notifyAt >= $reloadAt
    || !str_contains($provisioner, '!$endpointReceiptKnown')) {
    throw new RuntimeException('Phone reload must use the old live route before FreePBX activates the new endpoint, including the first receipt migration');
}

echo "PJSIP binding and endpoint-transition ordering assertions passed\n";
