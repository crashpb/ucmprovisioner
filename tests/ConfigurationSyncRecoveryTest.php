<?php
declare(strict_types=1);

interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

use FreePBX\modules\Ucmprovisioner;
use FreePBX\modules\Ucmprovisioner\ConfigurationSyncService;
use FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager;
use FreePBX\modules\Ucmprovisioner\FirmwareService;

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
// The optional MariaDB run must use a disposable empty database, never FreePBX.
$dsn = getenv('UCMP_RECOVERY_TEST_DSN') ?: 'sqlite::memory:';
if ($dsn !== 'sqlite::memory:' && !preg_match('/^mysql:unix_socket=[^;]+;dbname=ucmp_test_[a-z0-9_]+$/D',$dsn)) {
    throw new RuntimeException('Only an explicitly named disposable test database is accepted');
}
$db = new PDO($dsn, $dsn === 'sqlite::memory:' ? null : 'root', null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if ($mysql && $db->query('SHOW TABLES')->fetchColumn() !== false) { throw new RuntimeException('Test database must be empty'); }
$schema = simplexml_load_file(dirname(__DIR__) . '/module.xml');
foreach ($schema->database->table as $table) {
    $columns = [];
    foreach ($table->field as $field) {
        $key = (string)$field['name'];
        $type = $key === 'id' ? ($mysql ? 'INT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY') : ((string)$field['type'] === 'integer' ? 'INTEGER' : 'TEXT');
        $columns[] = '`' . $key . '` ' . $type . (isset($field['default']) ? ' DEFAULT ' . $db->quote((string)$field['default']) : '');
    }
    $db->exec('CREATE TABLE `' . (string)$table['name'] . '` (' . implode(',',$columns) . ')');
}
$db->exec('CREATE UNIQUE INDEX fixture_setting ON ucmp_settings (site_id,skey' . ($mysql ? '(128)' : '') . ')');
$module = new class($db) extends Ucmprovisioner {
    public array $firmware = [];
    public array $calls = [];
    public bool $unavailable = false;
    public string $timezone = '';
    public function __construct(PDO $db) { parent::__construct(new class($db) { public function __construct(private PDO $db) {} public function Database(): PDO { return $this->db; } }); }
    public function localSiteId(): int { return 1; }
    public function getAllSipExtensionsLocal(): array { return [['id'=>'800'],['id'=>'900']]; }
    public function getSites(): array { return [['id'=>1,'site_key'=>'destination','name'=>'Destination','is_local'=>1,'enabled'=>1]]; }
    public function getSite(int $id,bool $withSecrets=false): ?array { return $this->getSites()[0]; }
    public function tftpOp(array $request): array {
        if ($this->getDb()->inTransaction()) { throw new LogicException('Inventory ran inside object transaction'); }
        $this->calls[] = $request['op'];
        if ($this->unavailable) { return ['ok'=>false,'message'=>'Fixture inventory offline']; }
        if ($request['op'] === 'firmware_rescan') {
            // Real inventory persistence, including its own transaction boundary.
            (new ReflectionMethod(FirmwareService::class,'persist'))->invoke(new FirmwareService($this),1,$this->firmware);
            return ['ok'=>true,'bundles'=>$this->firmware];
        }
        if ($request['op'] === 'timezone_versions') {
            $manager = new CiscoTimezoneBundleManager($this->getDb());
            $manager->list(1);
            return ['ok'=>true,'assets'=>$this->timezone === '' ? [] : [['profile'=>'cisco-78xx-88xx','asset_key'=>$this->timezone,'selectable'=>true]],'bundles'=>[]];
        }
        return ['ok'=>true,'sets'=>[],'profiles'=>[]];
    }
    public function mediaAssetOpLocal(array $request): array { return ['ok'=>true,'items'=>[]]; }
};
ConfigurationSyncService::ensureSchema($db);
CiscoTimezoneBundleManager::ensureSchema($db);
$db->exec("INSERT INTO ucmp_sites (id,name,site_key,is_local,enabled) VALUES (1,'Destination','destination',1,1)");
$db->exec("INSERT INTO ucmp_models (vendor,model,family,series_key,role) VALUES ('Cisco','7841','cisco_xml','cisco_78xx','desk')");
$context = ['job_key'=>'cfgc:fixture-controller:1','job_id'=>1,'soft_lock'=>true];
$base = ['schema'=>1,'source_site'=>['site_key'=>'source','name'=>'Source']];
$template = ['_object_key'=>'template:1','_source_revision'=>'1','name'=>'Simple','target_vendor'=>'Cisco','target_family'=>'cisco_xml','setting_overrides'=>[]];
$applyTemplate = static fn(array $t): array => $module->configurationSyncApplyLocal($base + ['scopes'=>['templates'],'templates'=>[$t]],$context);
$state = static fn(string $key): array => $db->query('SELECT * FROM ucmp_configuration_sync_items WHERE object_key=' . $db->quote($key))->fetch(PDO::FETCH_ASSOC) ?: [];
$result = $applyTemplate($template);
$expect($result['ok'] && !$module->calls && $state('template:1')['status'] === 'active','A simple template must commit without any asset scan');

$module->timezone = str_repeat('a',64);
$template['name'] = 'Dependent'; $template['_object_key']='template:2';
$template['setting_overrides'] = ['firmware_by_profile'=>['cisco:sip78xx'=>'late.loads'],'cisco_timezone_by_profile'=>['cisco-78xx-88xx'=>$module->timezone]];
$result = $applyTemplate($template);
$expect($result['ok'] && $state('template:2')['status'] === 'missing_dependency','Missing firmware must preserve intended policy as waiting');
$id = (int)$state('template:2')['destination_id'];
$revision = $module->getTemplateLocal($id)['revision'];
$module->reconcileConfigurationSyncDependenciesLocal();
$expect($module->getTemplateLocal($id)['revision'] === $revision,'Repeated unresolved recovery must not rewrite the template');

$defaults = $base + ['scopes'=>['settings_assets'],'settings'=>['settings_assets'=>['firmware_defaults'=>['cisco:sip78xx'=>'late.loads']]]];
$result = $module->configurationSyncApplyLocal($defaults,$context);
$expect($result['ok'] && $state('firmware_defaults:cisco:sip78xx')['status'] === 'missing_dependency','Settings must wait for the same absent firmware');
$module->firmware = [['vendor'=>'Cisco','profile'=>'cisco:sip78xx','load_name'=>'late.loads','files'=>[],'missing'=>[],'status'=>'complete']];
$recovered = $module->reconcileConfigurationSyncDependenciesLocal();
$expect($recovered['ok'] && $state('template:2')['status'] === 'active' && $state('firmware_defaults:cisco:sip78xx')['status'] === 'active','Later firmware arrival must heal both template and Settings without a source reseed');
$settings = json_decode($module->getTemplateLocal($id)['setting_overrides'],true);
$expect($settings['firmware_by_profile']['cisco:sip78xx'] === 'late.loads','Recovered template must restore the intended firmware selector');
$revision = $module->getTemplateLocal($id)['revision'];
$module->reconcileConfigurationSyncDependenciesLocal();
$expect($module->getTemplateLocal($id)['revision'] === $revision,'Completed recovery must be idempotent');

$groupKey = 'group:' . str_repeat('b',64);
$localGroupId = $module->saveGroupLocal(['name'=>'Local group','site_id'=>1,'members_submitted'=>1,'member_extensions'=>['800']]);
$group = ['_object_key'=>$groupKey,'_source_revision'=>'1','name'=>'Later group','group_path'=>'Later group','template_map'=>[['template_key'=>'template:3','template_name'=>'Later template']],
    'member_extensions'=>['800','999'],'member_exceptions'=>[['template_key'=>'template:999','template_name'=>'Source-only exception','extensions'=>['999']]]];
$result = $module->configurationSyncApplyLocal($base + ['scopes'=>['groups'],'groups'=>[$group]],$context);
$expect($result['ok'] && $state($groupKey)['status'] === 'missing_dependency','A group must wait for an absent template');
$targetKey=hash('sha256','cisco|cisco_xml|cisco_78xx||');
$rule=['template_key'=>'template:3','template_name'=>'Later template','target_key'=>$targetKey,'target_vendor'=>'Cisco','target_family'=>'cisco_xml','target_series'=>'cisco_78xx','target_model'=>'','target_role'=>'','enabled'=>1];
$result=$module->configurationSyncApplyLocal($base + ['scopes'=>['settings_default_template_selectors'],'settings'=>['settings_default_template_selectors'=>['default_templates'=>[$rule]]]],$context);
$expect($result['ok'] && $state('rule:'.$targetKey)['status']==='missing_dependency','A default-template rule must wait for its missing template');
$later = ['_object_key'=>'template:3','_source_revision'=>'1','name'=>'Later template','target_vendor'=>'Cisco','target_family'=>'cisco_xml','setting_overrides'=>[]];
$result = $module->configurationSyncApplyLocal($base + ['scopes'=>['templates'],'templates'=>[$later]],array_replace($context,['job_key'=>'cfgc:fixture-controller:2','job_id'=>2]));
$expect($result['ok'] && $state($groupKey)['status'] === 'active','A template arriving through a different job must heal the existing group');
$savedGroup = $module->getGroupLocal((int)$state($groupKey)['destination_id']);
$expect((int)$savedGroup['template_id'] === (int)$state('template:3')['destination_id'],'Recovered group must use the destination template ID');
$expect(!$savedGroup['member_extensions'] && $module->getGroupLocal($localGroupId)['member_extensions'] === ['800'], 'Source membership must neither populate new groups nor move an existing local extension');
$expect($state('rule:'.$targetKey)['status']==='active','Later template arrival must also restore its default selection rule');

// Upgrade an rc20 receipt and edit only local membership without pausing shared policy.
$legacyRevision = (new ReflectionMethod(Ucmprovisioner::class,'configurationSyncGroupRevision'))->invoke($module,$savedGroup);
$db->prepare('UPDATE ucmp_configuration_sync_items SET destination_revision=? WHERE object_key=?')->execute([$legacyRevision,$groupKey]);
$savedGroup['members_submitted']=1; $savedGroup['member_extensions']=['900'];
$savedGroup['member_exceptions']=[['template_id'=>(int)$state('template:1')['destination_id'],'extensions'=>['900']]];
$module->saveGroupLocal($savedGroup);
$expect($state($groupKey)['status']==='active' && str_starts_with($state($groupKey)['destination_revision'],'p:'),'A local member/exception edit must migrate the matching receipt without pausing synchronization');
$localState=$module->getGroupLocal((int)$savedGroup['id']);
$db->prepare("UPDATE ucmp_configuration_sync_items SET status='missing_dependency',missing_json=? WHERE object_key=?")
    ->execute([json_encode(['member:999'=>'destination extension']),$groupKey]);
$recovered=$module->reconcileConfigurationSyncDependenciesLocal();
$after=$module->getGroupLocal((int)$savedGroup['id']);
$expect($recovered['ok'] && $state($groupKey)['status']==='active' && json_decode($state($groupKey)['missing_json'],true)===[], 'Retained old missing-extension intent must heal without a reseed');
$expect($after['member_extensions']===['900'] && $after['member_exceptions']===$localState['member_exceptions'],'Automatic recovery must retain local members and exceptions');
$group['_source_revision']='2'; $group['name']='Updated shared group';
$result=$module->configurationSyncApplyLocal($base + ['scopes'=>['groups'],'groups'=>[$group]],$context);
$after=$module->getGroupLocal((int)$savedGroup['id']);
$expect($result['ok'] && $after['name']==='Updated shared group' && $after['member_extensions']===['900'] && $after['member_exceptions']===$localState['member_exceptions'],'Later shared-policy updates must preserve destination membership and exceptions');
$after['name']='Unauthorized shared-policy edit';
try { $module->saveGroupLocal($after); throw new LogicException('Shared group policy bypassed its soft lock'); }
catch (RuntimeException $e) { $expect(str_contains($e->getMessage(),'Confirm the synchronized-object edit'),'Shared-policy edits must still require explicit confirmation'); }

// A new revision that cannot be inspected must leave the existing object and ownership intact.
$module->unavailable = true;
$template['_source_revision']='2';
$before = $module->getTemplateLocal($id);
$result = $applyTemplate($template);
$expect(!$result['ok'] && str_contains($result['message'],'inventory unavailable') && $module->getTemplateLocal($id) === $before,'Inventory failure must not be interpreted as missing files or overwrite a valid template');
$module->unavailable = false;

// Explicit local edit protection survives automatic reconciliation.
$template['_object_key']='template:4'; $template['_source_revision']='1'; $template['name']='Paused template';
$template['setting_overrides']=['firmware_by_profile'=>['cisco:sip78xx'=>'second.loads']];
$applyTemplate($template);
$pausedId=(int)$state('template:4')['destination_id'];
$edit=$module->getTemplateLocal($pausedId); $edit['name']='Locally edited'; $edit['sync_edit_confirmed']=true;
$module->saveTemplateLocal($edit);
$module->firmware[]=['vendor'=>'Cisco','profile'=>'cisco:sip78xx','load_name'=>'second.loads','files'=>[],'missing'=>[],'status'=>'complete'];
$module->reconcileConfigurationSyncDependenciesLocal();
$expect($state('template:4')['status']==='paused' && $module->getTemplateLocal($pausedId)['name']==='Locally edited','Automatic recovery must preserve a deliberately paused local edit');

// Ownership persistence and template mutation must remain one transaction.
$trigger=$mysql
    ? "CREATE TRIGGER fixture_tracking_failure BEFORE INSERT ON ucmp_configuration_sync_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture ownership write failed'"
    : "CREATE TRIGGER fixture_tracking_failure BEFORE INSERT ON ucmp_configuration_sync_items BEGIN SELECT RAISE(ABORT,'Fixture ownership write failed'); END";
$db->exec($trigger);
$newTemplate=['_object_key'=>'template:99','_source_revision'=>'1','name'=>'Must not survive','target_vendor'=>'Cisco','target_family'=>'cisco_xml','setting_overrides'=>['cisco_timezone_by_profile'=>['cisco-78xx-88xx'=>$module->timezone]]];
$result=$applyTemplate($newTemplate);
$db->exec('DROP TRIGGER fixture_tracking_failure');
$expect(!$result['ok'] && (int)$db->query("SELECT COUNT(*) FROM ucmp_templates WHERE name='Must not survive'")->fetchColumn()===0,'A tracking failure must undo the template write even after real timezone schema initialization');

// Exercise the original production-specific implicit-commit boundary directly.
$db->beginTransaction();
$db->exec("UPDATE ucmp_templates SET name='Uncommitted fixture' WHERE id=".$id);
new CiscoTimezoneBundleManager($db);
$expect($db->inTransaction(),'Timezone manager construction must not implicitly commit a caller transaction');
$db->rollBack();
$expect($module->getTemplateLocal($id)['name']==='Dependent','Caller rollback must still cover its template write');
echo 'Configuration dependency recovery and transaction assertions passed (' . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . ")\n";
