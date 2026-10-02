<?php
declare(strict_types=1);
interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Ucmprovisioner.class.php';
use FreePBX\modules\Ucmprovisioner;
use FreePBX\modules\Ucmprovisioner\IdentityDeliveryService;

final class AssignmentCommitPDO extends PDO {
    public bool $failCommit=false;
    public bool $loseCommit=false;
    public ?Closure $atCommit=null;
    public function commit(): bool { if($this->atCommit){($this->atCommit)();}if ($this->failCommit) { return false; } $result=parent::commit();if($this->loseCommit){throw new RuntimeException('Injected lost commit acknowledgement');}return $result; }
}
$expect=static function(bool $ok,string $message):void { if (!$ok) { throw new RuntimeException($message); } };
$fixture=static function():array {
    $db=new AssignmentCommitPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $xml=simplexml_load_file(dirname(__DIR__).'/module.xml');
    foreach (['ucmp_phones','ucmp_extensions','ucmp_models','ucmp_phone_mac_suppressions'] as $name) {
        $fields=[];
        foreach ($xml->xpath('//table[@name="'.$name.'"]/field') as $field) {
            $column='"'.(string)$field['name'].'" ';
            $column.=in_array((string)$field['type'],['integer','smallint','boolean'],true)?'INTEGER':'TEXT';
            if ((string)$field['primaryKey']==='true') { $column.=' PRIMARY KEY'; }
            if (isset($field['default'])) { $column.=' DEFAULT '.$db->quote((string)$field['default']); }
            $fields[]=$column;
        }
        $db->exec('CREATE TABLE '.$name.' ('.implode(',',$fields).')');
    }
    $db->exec('CREATE UNIQUE INDEX logical_slot ON ucmp_extensions(site_id,extension)');
    $db->exec('CREATE TABLE devices (id TEXT PRIMARY KEY,tech TEXT)');
    $db->exec("INSERT INTO devices VALUES ('100','pjsip'),('200','pjsip'),('300','pjsip')");
    $db->exec("INSERT INTO ucmp_models (id,vendor,model,family,role,sip_accounts) VALUES (1,'Yealink','Fixture','yealink','desk',2)");
    $db->exec("INSERT INTO ucmp_extensions (id,site_id,extension,group_id,revision) VALUES (1,1,'100',10,3),(2,1,'200',20,4)");
    $db->exec("INSERT INTO ucmp_phones (id,site_id,mac,vendor,model_id,family,extension,extension_id,label) VALUES (1,1,'001122334455','Yealink',1,'yealink','100',1,'First'),(2,1,'001122334466','Yealink',1,'yealink','200',2,'Second')");
    $module=new class extends Ucmprovisioner {
        public function __construct() {}
        public function localSiteId():int { return 1; }
        public function isSiteLocal(int $id):bool { return $id===1; }
        public function getAllSipExtensionsLocal():array { return array_map(static fn(string $n):array=>['id'=>$n,'driver'=>'pjsip'],['100','200','300']); }
        public function getSiteSettingsLocal(int $siteId):array { return ['sip_driver'=>'pjsip','sip_transport'=>'udp']; }
    };
    (new ReflectionProperty(Ucmprovisioner::class,'db'))->setValue($module,$db);
    $request=['id'=>1,'site_id'=>1,'mac'=>'001122334455','extension'=>'200','collision_action'=>'swap',
        'expected_target_hash'=>IdentityDeliveryService::hash($module->getPhoneLocal(1)),
        'expected_other_id'=>2,'expected_other_hash'=>IdentityDeliveryService::hash($module->getPhoneLocal(2)),
        'expected_identity_revisions'=>['100'=>3,'200'=>4]];
    return [$db,$module,$request];
};
$snapshot=static fn(PDO $db):array=>[$db->query('SELECT * FROM ucmp_phones ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
foreach (['aabbccddeeff', 'AaBbCcDdEeFf', 'AA:BB:CC:DD:EE:FF'] as $mac) {
    [$db,$module]=$fixture();
    $saved=$module->savePhoneAssignmentLocal(['id'=>1,'site_id'=>1,'mac'=>$mac,'extension'=>'100','model_id'=>1,'collision_action'=>'swap']);
    $expect(!empty($saved['ok']) && $module->getPhoneLocal(1)['mac']==='AABBCCDDEEFF',
        'Phone saves must store lowercase, mixed-case and formatted MAC input as the same uppercase identity');
}
foreach (["UPDATE ucmp_phones SET label='changed' WHERE id=1","UPDATE ucmp_phones SET label='changed' WHERE id=2",
    "UPDATE ucmp_phones SET extension=NULL WHERE id=2","UPDATE ucmp_extensions SET revision=revision+1 WHERE extension='200'"] as $mutation) {
    [$db,$module,$request]=$fixture();$db->exec($mutation);$before=$snapshot($db);
    try { $module->savePhoneAssignmentLocal($request);throw new LogicException('Stale reviewed assignment accepted'); }
    catch (DomainException $expected) {}
    $expect($before===$snapshot($db),'Stale target, collision or identity rejection must not mutate state');
}
[$db,$module,$request]=$fixture();$db->failCommit=true;$before=$snapshot($db);
try { $module->savePhoneAssignmentLocal($request);throw new LogicException('Unconfirmed commit accepted'); }
catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'commit'),'Commit failure must remain explicit'); }
$expect($before===$snapshot($db),'Failed commit must roll back assignment and logical links');
[$db,$module,$request]=$fixture();$result=$module->savePhoneAssignmentLocal($request);
$expect(!empty($result['ok']),'Current fixed replacement should complete module-only assignment');
$one=$module->getPhoneLocal(1);$two=$module->getPhoneLocal(2);
$expect($one['extension']==='200' && $two['extension']==='100' && (int)$one['group_id']===20 && (int)$two['group_id']===10,'Person policy follows logical extension, not physical device');
$expect($one['mac']==='001122334455' && $two['mac']==='001122334466','Replacement must preserve physical identities');
echo "Real assignment reviewed-target, identity-revision and commit-failure assertions passed\n";
[$db,$module,$request]=$fixture();$db->loseCommit=true;
try { $module->savePhoneAssignmentLocal($request);throw new LogicException('Lost assignment acknowledgement was accepted'); }
catch (FreePBX\modules\Ucmprovisioner\RpcOperationException $error) {
    $expect(($error->receipt['status']??'')==='unknown'&&!empty($error->receipt['recovery_required']),'Lost commit must retain an unknown receipt');
}
$expect($module->getPhoneLocal(1)['extension']==='200','Lost commit reply must not undo the committed assignment');
