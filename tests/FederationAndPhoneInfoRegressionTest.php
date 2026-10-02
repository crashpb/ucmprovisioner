<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/api.php');
$connection = (string)file_get_contents($root . '/lib/SiteConnection.php');
$rpcResponse = (string)file_get_contents($root . '/lib/RpcResponse.php');
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$services = (string)file_get_contents($root . '/lib/PhoneServiceService.php');
$phones = (string)file_get_contents($root . '/views/phones.php');
$templates = (string)file_get_contents($root . '/views/templates.php');
$groups = (string)file_get_contents($root . '/views/groups.php');
$modelIdentity = (string)file_get_contents($root . '/lib/PhoneModelIdentity.php');

interface BMO {}
class FreePBX_Helpers {}
require_once $root . '/Ucmprovisioner.class.php';
$deviceReader = new class extends \FreePBX\modules\Ucmprovisioner {
    public mixed $response = null;
    public function __construct() {}
    public function localSiteId(): int { return 1; }
    public function isSiteLocal(int $siteId): bool { return $siteId === 1; }
    public function remoteQuery(int $siteId, string $method, array $args = []) {
        if ($siteId !== 2 || $method !== 'getBoundDeviceLocal' || $args !== ['4100']) { throw new LogicException('Unexpected remote device request'); }
        return $this->response;
    }
};
$deviceReader->response = ['id'=>'4100','tech'=>'pjsip','transport'=>'tcp'];
if ($deviceReader->getBoundDevice('4100',2) !== $deviceReader->response) { throw new RuntimeException('An unwrapped Core device row must survive the remote reader'); }
foreach ([null,['ok'=>false,'message'=>'Unavailable']] as $failedDevice) {
    $deviceReader->response = $failedDevice;
    if ($deviceReader->getBoundDevice('4100',2) !== null) { throw new RuntimeException('A failed device read must not be accepted as a device'); }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($api, '$ucm = \\FreePBX::Ucmprovisioner();')
    && !str_contains($api, 'new \\FreePBX\\modules\\Ucmprovisioner(\\FreePBX::create())'),
    'The HTTP endpoint must share FreePBX BMO state with static Core lookups'
);
$bootstrapPosition = strpos($api, "require_once '/etc/freepbx.conf';");
$authPosition = strpos($api, "\$bootstrap_settings['freepbx_auth'] = false;");
$restrictPosition = strpos($api, "\$restrict_mods = ['core'=>true, 'ucmprovisioner'=>true];");
$expect(
    $bootstrapPosition !== false
    && $authPosition !== false && $authPosition < $bootstrapPosition
    && $restrictPosition !== false && $restrictPosition < $bootstrapPosition,
    'The public federation endpoint must load Core and Ucmprovisioner without requiring a GUI session'
);
$expect(
    str_contains($connection, "RpcResponse::unwrap(\$opened['result'])")
    && str_contains($rpcResponse, 'throw new RpcOperationException('),
    'Authenticated API operational failures must remain distinct from transport failures and cannot trigger replay'
);
$expect(
    str_contains($connection, "'getAllSipExtensionsLocal'")
    && str_contains($connection, 'management RPC ready'),
    'Site API readiness must exercise a Core-dependent management RPC, not moduleStatus alone'
);

$start = strpos($module, '$simpleReads = [');
$end = $start === false ? false : strpos($module, '];', $start);
$snapshotReads = $start === false || $end === false ? '' : substr($module, $start, $end - $start);
$expect(
    $snapshotReads !== '' && !str_contains($snapshotReads, "'liveStatus'"),
    'Live registration/ping state must never be replayed from a last-known inventory snapshot'
);
$expect(
    str_contains($module, 'RpcResponse::unwrap($decoded)'),
    'SSH recovery error envelopes must not be cached or returned as successful inventory either'
);

$expect(
    str_contains($services, 'templateAssignmentTrace($p)')
    && str_contains($services, "\$source === 'default'")
    && str_contains($services, "? 'Default'"),
    'Phone Information must resolve the effective default/group template trace used by generation'
);
$expect(
    !str_contains($services, 'LEFT JOIN ucmp_templates t ON t.id=g.template_id'),
    'Phone Information must not regress to the obsolete single direct-group-template join'
);
$expect(
    str_contains($phones, 'name="model_key"')
    && str_contains($phones, 'data-id="<?php echo (int)$m[\'id\']; ?>"')
    && str_contains($module, 'savePhoneAssignmentByIdentityLocal')
    && str_contains($modelIdentity, 'UPPER(vendor)=UPPER(?) AND UPPER(model)=UPPER(?)')
    && str_contains($module, "'savePhoneAssignmentByIdentityLocal'"),
    'Federated phone saves must resolve a stable vendor/model identity on the owning PBX instead of reusing controller database IDs'
);
$expect(
    str_contains($phones, 'name="view" value="phone"')
    && str_contains($phones, 'name="site_filter"')
    && str_contains($module, "\$_REQUEST['site_filter'] = (string)\$siteId;"),
    'A phone save must keep the owning remote-site editor context without making fresh tabs globally sticky'
);
$templateSaveStart = strpos($module, "case 'savetemplate':");
$templateSaveEnd = $templateSaveStart === false ? false : strpos($module, "case 'deltemplate':", $templateSaveStart);
$templateSaveFlow = $templateSaveStart === false || $templateSaveEnd === false
    ? ''
    : substr($module, $templateSaveStart, $templateSaveEnd - $templateSaveStart);
$expect(
    str_contains($templates, 'name="site_filter"')
    && str_contains($templateSaveFlow, "unset(\$_REQUEST['view'], \$_REQUEST['id'])")
    && str_contains($templateSaveFlow, "\$_REQUEST['site_filter'] = (string)\$templateSiteId;"),
    'A template save must return to the inventory while retaining the selected local or remote site'
);
$groupSaveStart = strpos($module, "case 'savegroup':");
$groupSaveEnd = $groupSaveStart === false ? false : strpos($module, "case 'delgroup':", $groupSaveStart);
$groupSaveFlow = $groupSaveStart === false || $groupSaveEnd === false
    ? ''
    : substr($module, $groupSaveStart, $groupSaveEnd - $groupSaveStart);
$expect(
    str_contains($groups, 'name="site_filter"')
    && str_contains($groupSaveFlow, "unset(\$_REQUEST['view'], \$_REQUEST['id'])")
    && str_contains($groupSaveFlow, "\$_REQUEST['site_filter'] = (string)\$groupSiteId;"),
    'A group save must return to the group list while retaining the selected local or remote site'
);

$public = (string)file_get_contents($root . '/phone_services.php');
$expect(str_contains($public, '$ucm=\\FreePBX::Ucmprovisioner();')
    && strpos($public, '$bootstrap_settings[\'freepbx_auth\'] = false;') < strpos($public, "require_once '/etc/freepbx.conf';"),
    'Public phone services must initialize and reuse the installed registry independently of GUI authentication');
$runPhp = static function (string $code): array {
    $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run entrypoint fixture'); }
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    return [$stdout,$stderr,$exit];
};
$serviceFixture = <<<'PHP'
namespace FreePBX\modules\Ucmprovisioner {
    class PhoneServiceService {
        public function __construct(object $module) {}
        public function render(string $format,string $mac,array $route): array {
            return ['content_type'=>'text/plain','body'=>'rendered-'.$format.'-'.$mac];
        }
    }
}
namespace {
class FreePBX {
    public static function Ucmprovisioner(): object { return new class {
        public function phoneServicesToken(bool $create): string { return str_repeat('a',32); }
    }; }
    public static function create(): never { throw new RuntimeException('Unexpected second registry'); }
}
ob_start();
register_shutdown_function(static function (): void {
    $body=ob_get_clean();echo json_encode(['status'=>http_response_code() ?: 200,'body'=>$body]);
});
PHP;
$entry=str_replace("require_once '/etc/freepbx.conf';",'',substr($public,5));
foreach ([['GET',str_repeat('a',32),200],['HEAD',str_repeat('a',32),200],['GET',str_repeat('b',32),403],['POST',str_repeat('a',32),405]] as [$method,$token,$status]) {
    $server=['REQUEST_METHOD'=>$method,'PATH_INFO'=>'/'.$token.'/cisco/001122334455'];
    [$output,$error,$exit]=$runPhp($serviceFixture . '$_SERVER=' . var_export($server,true) . ';' . $entry . '}');
    $response=json_decode($output,true);
    $expect($exit===0 && ($response['status']??0)===$status, 'Public service route failed its method/token boundary: '.$error);
    $expect(($response['body']??'') === ($method==='GET' && $status===200 ? 'rendered-cisco-001122334455' : ''), 'Unauthenticated or HEAD request rendered a phone-service body');
}
$rebuild = (string)file_get_contents($root . '/cli_rebuild.php');
$rebuildFixture = <<<'PHP'
class FreePBX {
    public static function Ucmprovisioner(): object { return new class {
        public function rebuild($selection,bool $apply=false,?int $site=null,bool $restart=false,bool $mesh=true,?string $hash=null): array {
            return ['ok'=>true,'plan_hash'=>str_repeat('a',64),'applied'=>$apply,'selection'=>$selection,'site'=>$site,'accepted_hash'=>$hash];
        }
    }; }
    public static function create(): never { throw new RuntimeException('Unexpected second registry'); }
}
PHP;
foreach (['apply','0'] as $mode) {
    [$output,$error,$exit]=$runPhp($rebuildFixture . '$argv=' . var_export(['cli_rebuild.php','7',$mode,'2'],true) . ';' . substr($rebuild,5));
    $result=json_decode($output,true);
    $expect($exit===0 && ($result['applied']??null)===($mode==='apply') && ($result['site']??0)===2
        && ($mode!=='apply' || ($result['accepted_hash']??'')===str_repeat('a',64)), 'Documented CLI apply must preserve selection/site and use the preview hash: '.$error);
}
echo "Federation fallback and Phone Information regression contracts passed\n";

$meshModule = new class extends FreePBX\modules\Ucmprovisioner {
    public function __construct() {}
    public function localSiteId(): int { return 1; }
    public function getSite(int $id, bool $withSecrets = false): ?array { return ['pbx_addr'=>'192.0.2.1','pbx_sip_port'=>5160]; }
    public function listPjsipTrunks(int $siteId = 0): array { return ['ok'=>true,'trunks'=>[['endpoint'=>'peer','trunkid'=>1]]]; }
};
$meshDb = new PDO('sqlite::memory:');
$meshDb->exec('CREATE TABLE pjsip (id INTEGER,keyword TEXT,data TEXT)');
(new ReflectionProperty(FreePBX\modules\Ucmprovisioner::class,'db'))->setValue($meshModule,$meshDb);
$bound = new ReflectionMethod($meshModule,'boundedMeshPeer');
foreach ([
    ['192.0.2.2','192.0.2.2'],['sip:192.0.2.2:5160;lr','192.0.2.2'],
    ['sips:peer@pbx.example.test:5061;transport=tls','PBX.example.test'],
    ['sip:[2001:db8::2]:5160;lr','2001:0db8:0:0:0:0:0:2'],['2001:db8::2','2001:db8::2'],
] as [$server,$address]) {
    $meshDb->exec('DELETE FROM pjsip');
    $meshDb->prepare("INSERT INTO pjsip VALUES (1,'outbound_proxy',?)")->execute([$server]);
    $peer = $bound->invoke($meshModule,['endpoint'=>'peer','peer_addr'=>$address,'peer_resource'=>'remote-peer']);
    $expect($peer['peer_addr'] === $address,'Explicit SIP URI ports and equivalent IPv6 spellings must identify the same enrolled trunk host');
    $rejected = false;
    try { $bound->invoke($meshModule,['endpoint'=>'peer','peer_addr'=>'203.0.113.99']); } catch (InvalidArgumentException $expected) { $rejected = true; }
    $expect($rejected,'Trunk parsing must still reject an unrelated peer host');
}
echo "Mesh trunk host and port parsing assertions passed\n";
