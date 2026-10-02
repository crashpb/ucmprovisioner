<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/EndpointProfile.php';
require_once dirname(__DIR__) . '/lib/ExtensionBinder.php';

use FreePBX\modules\Ucmprovisioner\ExtensionBinder;

$reloadMarkerFails = false;
function needreload(): void {
    if ($GLOBALS['reloadMarkerFails']) { throw new RuntimeException('Synthetic reload marker failure'); }
}

final class RecoveryFakeCore
{
    public array $user = ['extension'=>'700','name'=>'Before','outboundcid'=>'"External" <+12025550100>','emergency_cid'=>'+12025550911'];
    public array $device = ['id'=>'700','tech'=>'sip','user'=>'700','secret'=>'fixture-only','transport'=>'udp','description'=>'Before','callerid'=>'"Custom" <+12025550101>','emergency_cid'=>'+12025550912'];
    public array $fail = [];
    public array $calls = [];
    private function allowed(string $method): bool
    {
        $this->calls[] = $method;
        $fault = array_shift($this->fail[$method]);
        if ($fault === 'throw') { throw new RuntimeException('Injected Core failure'); }
        return $fault !== 'false';
    }
    public function getUser(string $ext): array { return $this->user; }
    public function getDevice(string $ext): array { return $this->device; }
    public function delUser(string $ext,bool $edit): bool { if (!$this->allowed(__FUNCTION__)) { return false; } $this->user=[]; return true; }
    public function delDevice(string $ext,bool $edit): bool { if (!$this->allowed(__FUNCTION__)) { return false; } $this->device=[]; return true; }
    public function addUser(string $ext,array $user,bool $edit): bool { if (!$this->allowed(__FUNCTION__)) { return false; } $this->user=$user; return true; }
    public function addDevice(string $ext,string $tech,array $settings,bool $edit): bool {
        if (!$this->allowed(__FUNCTION__)) { return false; }
        $this->device=['id'=>$ext,'tech'=>$tech];
        foreach ($settings as $key=>$row) { $this->device[$key]=$row['value']; }
        return true;
    }
    public function __construct() { foreach (['delUser','delDevice','addUser','addDevice'] as $method) { $this->fail[$method]=[]; } }
}
final class FreePBX { public static RecoveryFakeCore $core; public static function Core(): RecoveryFakeCore { return self::$core; } }
$expect = static function(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$create = static function() use (&$receipts): ExtensionBinder {
    FreePBX::$core = new RecoveryFakeCore(); $receipts=[];
    return new ExtensionBinder(null,static function(array $receipt) use (&$receipts): void { $receipts[]=$receipt; });
};
$binder=$create(); $original=FreePBX::$core->device; $originalUser=FreePBX::$core->user;
$result=$binder->updateDisplayName('700','Managed','managed');
$expect($result['ok'] && $result['status']==='verified','Managed caller name was not verified');
$expect(FreePBX::$core->device['callerid']==='"Managed" <+12025550101>' && FreePBX::$core->device['emergency_cid']===$original['emergency_cid'],'Managed caller name changed an explicit number');
$expect(FreePBX::$core->user['outboundcid']===$originalUser['outboundcid'] && FreePBX::$core->user['emergency_cid']===$originalUser['emergency_cid'],'Managed user name changed outgoing/emergency identity');

foreach (['delUser','addUser','delDevice','addDevice'] as $method) {
    foreach (['false','throw'] as $fault) {
        $binder=$create(); $user=FreePBX::$core->user; $device=FreePBX::$core->device;
        FreePBX::$core->fail[$method]=[$fault];
        $result=$binder->updateDisplayName('700','After','managed');
        $expect(!$result['ok'] && $result['status']==='rolled_back',"$method $fault did not report verified rollback");
        $expect(FreePBX::$core->user===$user && !array_diff_assoc($device,FreePBX::$core->device),"$method $fault did not restore original records");
        $expect(end($receipts)['status']==='rolled_back','Recovery receipt was not finalized');
    }
}
$binder=$create(); FreePBX::$core->fail['addDevice']=['false','false'];
$result=$binder->updateDisplayName('700','After','managed');
$expect(!$result['ok'] && $result['status']==='recovery_required' && in_array('device',$result['unresolved'],true),'Failed compensation lost recovery-required status');
$expect(end($receipts)['original_device']['secret']==='fixture-only','Recovery did not retain the original endpoint record');

$binder=$create(); FreePBX::$core->fail['addDevice']=['false'];
$result=$binder->updateSecretTransport('700','new-fixture',null);
$expect(!$result['ok'] && $result['status']==='rolled_back' && FreePBX::$core->device['secret']==='fixture-only','Secret update failed to compensate');
$binder=new ExtensionBinder(null,static function(): void { throw new RuntimeException('Storage unavailable'); });
FreePBX::$core = new RecoveryFakeCore();
$result=$binder->updateDisplayName('700','After','managed');
$expect($result['status']==='preflight_failed' && FreePBX::$core->calls===[],'Missing durable preflight receipt did not prevent Core writes');
foreach (['identity','endpoint'] as $operation) {
    FreePBX::$core=new RecoveryFakeCore();$prepared=[];
    $binder=new ExtensionBinder(null,static function(array $receipt) use (&$prepared):void {
        if ($receipt['status']==='verified') { throw new RuntimeException('Synthetic final receipt failure'); }
        $prepared[]=$receipt;
    });
    $result=$operation==='identity' ? $binder->updateDisplayName('700','Verified name','managed')
        : $binder->updateSecretTransport('700','verified-secret',null);
    $expect(!empty($result['ok']) && !empty($result['changed']) && isset($result['reporting_error']) && $result['status']==='verified',
        'A late receipt failure must preserve the verified Core operation');
    $expect(count(array_filter(FreePBX::$core->calls,static fn(string $call):bool=>$call==='addDevice'))===1
        && count($prepared)===1 && $prepared[0]['status']==='prepared','Receipt failure caused a second Core write or lost the original evidence');
    $expect($operation==='identity' ? FreePBX::$core->user['name']==='Verified name' : FreePBX::$core->device['secret']==='verified-secret',
        'Receipt failure reversed an already verified edit');
}
echo "Core checked-write, managed-number preservation and compensation fault assertions passed\n";

foreach (['identity','endpoint'] as $operation) {
    $binder=$create();$reloadMarkerFails=true;
    $result=$operation==='identity' ? $binder->updateDisplayName('700','Verified name','managed')
        : $binder->updateSecretTransport('700','verified-secret',null);
    $reloadMarkerFails=false;
    $expect(!empty($result['ok']) && !empty($result['changed']) && isset($result['reporting_error'])
        && count(array_filter(FreePBX::$core->calls,static fn(string $call):bool=>$call==='addDevice'))===1,
        'Reload marker failure must retain the verified edit without a second Core write');
    $expect($operation==='identity' ? FreePBX::$core->user['name']==='Verified name' : FreePBX::$core->device['secret']==='verified-secret',
        'Reload marker failure reversed the endpoint edit');
    $expect(end($receipts)['status']==='verified','Reload marker failure must not prevent completion receipt storage');
}
