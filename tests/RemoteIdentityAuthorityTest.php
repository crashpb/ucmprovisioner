<?php
declare(strict_types=1);
interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Ucmprovisioner.class.php';
$module=new class extends FreePBX\modules\Ucmprovisioner {
    public bool $legacyCalled=false;
    public function __construct() {}
    public function localSiteId():int { return 7; }
    public function applyDirectoryIdentitiesLocal(array $identities):array { $this->legacyCalled=true;return ['ok'=>true]; }
    public function operatorWorkflowLocal(array $request):array { return ['ok'=>true,'request'=>$request]; }
};
$dispatch=static fn(array $payload):array=>json_decode($module->remoteDispatch(base64_encode(json_encode($payload))),true,32,JSON_THROW_ON_ERROR);
$legacy=$dispatch(['method'=>'applyDirectoryIdentitiesLocal','args'=>[[['origin_site_key'=>'receiver']]]]);
if (!empty($legacy['ok']) || $module->legacyCalled) { throw new RuntimeException('Legacy remote identity apply bypassed the scoped receiver'); }
$guarded=$dispatch(['method'=>'operatorWorkflowLocal','args'=>[['op'=>'delivery_plan','site_id'=>1,'_remote_request'=>false]]]);
if (empty($guarded['request']['_remote_request']) || $guarded['request']['site_id']!==7) { throw new RuntimeException('Remote request could erase trusted transport context'); }
echo "Remote dispatcher local-authority context and legacy bypass rejection passed\n";
