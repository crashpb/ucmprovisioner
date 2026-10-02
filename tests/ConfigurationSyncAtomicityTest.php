<?php
declare(strict_types=1);

interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

use FreePBX\modules\Ucmprovisioner;
use FreePBX\modules\Ucmprovisioner\ConfigurationSyncService;
use FreePBX\modules\Ucmprovisioner\RpcOperationException;

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$db = new class extends PDO {
    public bool $loseCommit = false;
    public function __construct() { parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    public function commit(): bool { $ok = parent::commit(); if ($this->loseCommit) { throw new RuntimeException('Fixture lost commit response'); } return $ok; }
};
$schema = simplexml_load_file(dirname(__DIR__) . '/module.xml');
foreach ($schema->database->table as $table) {
    $name = (string)$table['name'];
    if (!in_array($name, ['ucmp_settings','ucmp_phonebooks','ucmp_phones','ucmp_templates','ucmp_groups','ucmp_models','ucmp_extensions','ucmp_default_template_rules'], true)) { continue; }
    $columns = [];
    foreach ($table->field as $field) {
        $key = (string)$field['name'];
        $type = $key === 'id' ? 'INTEGER PRIMARY KEY' : ((string)$field['type'] === 'integer' ? 'INTEGER' : 'TEXT');
        $columns[] = '"' . $key . '" ' . $type . (isset($field['default']) ? ' DEFAULT ' . $db->quote((string)$field['default']) : '');
    }
    $db->exec('CREATE TABLE ' . $name . ' (' . implode(',', $columns) . ')');
}
$db->exec('CREATE UNIQUE INDEX fixture_setting ON ucmp_settings(site_id,skey)');
$module = new class($db) extends Ucmprovisioner {
    public array $saves = [];
    public function __construct(PDO $db) { parent::__construct(new class($db) { public function __construct(private PDO $db) {} public function Database(): PDO { return $this->db; } }); }
    public function localSiteId(): int { return 1; }
    public function getSites(): array { return [['id'=>1,'site_key'=>'destination','name'=>'Fixture','is_local'=>1,'enabled'=>1]]; }
    public function getSite(int $id, bool $withSecrets = false): ?array { return $this->getSites()[0]; }
    public function saveSettingsLocal(array $data): void { $this->saves[] = $data; parent::saveSettingsLocal($data); }
    public function tftpOp(array $request): array { return ['ok'=>true,'bundles'=>[],'sets'=>[]]; }
    public function mediaAssetOpLocal(array $request): array { return ['ok'=>true,'items'=>[]]; }
};
ConfigurationSyncService::ensureSchema($db);
ConfigurationSyncService::ensureSchema($db);
$context = ['job_key'=>'cfgc:controller-a:7','job_id'=>7,'source_site_key'=>'source-a','source_site_name'=>'Source A','force'=>true];
$snapshot = ['schema'=>1,'source_site'=>['site_key'=>'source-a','name'=>'Source A'],'scopes'=>['settings_clock_date'],
    'settings'=>['settings_clock_date'=>['ntp_server'=>'192.0.2.12','date_template'=>'D/M/Y']]];
$db->exec("CREATE TRIGGER fail_tracking BEFORE INSERT ON ucmp_configuration_sync_items WHEN NEW.object_key='setting:date_template' BEGIN SELECT RAISE(ABORT,'Fixture tracking failed'); END");
$result = $module->configurationSyncApplyLocal($snapshot, $context);
$expect(!$result['ok'] && str_contains($result['message'], 'Fixture tracking failed'), 'Late tracking failure must be reported');
$expect($module->getSiteSettingsLocal(1) === [] && (int)$db->query('SELECT COUNT(*) FROM ucmp_configuration_sync_items')->fetchColumn() === 0,
    'A late ownership write must leave neither partially saved settings nor tracking rows');
$expect(count($module->saves) === 1 && count($module->saves[0]['settings']) === 2, 'A category must save all eligible keys in a single batch');
$db->exec('DROP TRIGGER fail_tracking');
$result = $module->configurationSyncApplyLocal($snapshot, $context);
$expect($result['ok'] && count($result['items']) === 2 && $module->getSiteSettingsLocal(1)['ntp_server'] === '192.0.2.12', 'Settings and tracking must commit together');

$module->setSetting('cisco_transport','tcp',1);
$module->setSetting('non_cisco_sip_port','5199',1);
$reset = $module->configurationSyncApplyLocal(['schema'=>1,'source_site'=>['site_key'=>'source-a','name'=>'Source A'],
    'scopes'=>['settings_sip_service'],'object_filter'=>['setting_keys'=>['cisco_transport','non_cisco_sip_port']],
    'settings'=>['settings_sip_service'=>['cisco_transport'=>null,'non_cisco_sip_port'=>null]]],$context);
$expect(!empty($reset['ok']) && !array_key_exists('cisco_transport',$module->getSiteSettingsLocal(1))
    && !array_key_exists('non_cisco_sip_port',$module->getSiteSettingsLocal(1)),
    'Explicit absent source settings must reset real saved overrides without failing enum/port validation');
// Exercise schedule normalization without touching the production scheduler lock path.
$applying = new ReflectionProperty(Ucmprovisioner::class,'configurationSyncApplying');
$applying->setValue($module,true);
try {
    (new ReflectionMethod(Ucmprovisioner::class,'saveSettingsLocalLocked'))->invoke($module,
        ['site_id'=>1,'settings'=>['blf_mesh_interval_minutes'=>'35','blf_mesh_schedule_json'=>null]]);
} finally { $applying->setValue($module,false); }
$expect($module->getSiteSettingsLocal(1)['blf_mesh_interval_minutes']==='35',
    'Resetting a missing schedule document must preserve its selected legacy interval');

$directory = new class($db) extends \FreePBX\modules\Ucmprovisioner\DirectoryService {
    public int $publications = 0;
    public bool $fail = true;
    public function __construct(private PDO $db) {}
    public function setPublishedDefaultPhonebook(string $key): array {
        if ($this->db->inTransaction()) { throw new LogicException('Published before commit'); }
        $this->publications++;
        if ($this->fail) { throw new RuntimeException('Fixture publication unavailable'); }
        return ['ok'=>true];
    }
};
$engines = new ReflectionProperty(Ucmprovisioner::class, 'engines');
$engines->setValue($module, ['DirectoryService'=>$directory]);
$defaults = ['schema'=>1,'source_site'=>$snapshot['source_site'],'scopes'=>['settings_assets'],'settings'=>['settings_assets'=>['default_phonebook_key'=>'']]];
$result = $module->configurationSyncApplyLocal($defaults, $context);
$expect($result['ok'] && $directory->publications === 1 && str_contains(implode('; ', $result['warnings']), 'Fixture publication unavailable')
    && !isset($module->getSiteSettingsLocal(1)['default_phonebook_key']), 'Late publication failure must retain the committed default reset and ownership');
$directory->fail = false;
$context['force'] = false;
$result = $module->configurationSyncApplyLocal($defaults, $context);
$expect($result['ok'] && !$result['warnings'] && $directory->publications === 2, 'An unchanged retry must repair publication');
$db->loseCommit = true;
try { $module->configurationSyncApplyLocal($defaults, $context); throw new LogicException('Unknown commit accepted'); }
catch (RpcOperationException $error) { $expect($error->receipt['status'] === 'unknown', 'Lost commit must retain uncertainty'); }
$expect($directory->publications === 2 && !$db->inTransaction(), 'Unconfirmed commit must not start publication or leave a transaction active');
$db->loseCommit = false;

$engines->setValue($module, []);
$books = ['schema'=>1,'source_site'=>$snapshot['source_site'],'scopes'=>['phonebooks'],'phonebooks'=>[
    ['book_key'=>'fixture','name'=>'Fixture book','filename'=>'fixture.xml','title'=>'Fixture book','_source_revision'=>'1']]];
$db->exec("CREATE TRIGGER fail_book_tracking BEFORE INSERT ON ucmp_configuration_sync_items WHEN NEW.object_type='phonebook' BEGIN SELECT RAISE(ABORT,'Fixture book tracking failed'); END");
$result = $module->configurationSyncApplyLocal($books, $context);
$expect(!$result['ok'] && str_contains($result['message'], 'Fixture book tracking failed') && (int)$db->query('SELECT COUNT(*) FROM ucmp_phonebooks')->fetchColumn() === 0,
    'Phonebook import must join the ownership transaction and defer publication');
$db->exec('DROP TRIGGER fail_book_tracking');
foreach (['configurationSyncReleaseLocal'=>['cfg:1:7'], 'configurationSyncResumeLocal'=>['cfg:1:7','template','template:1']] as $method=>$args) {
    try { $module->$method(...$args); throw new LogicException('Ambiguous legacy owner was controllable'); }
    catch (RuntimeException $error) { $expect(str_contains($error->getMessage(), 'ambiguous'), 'Only the legacy ownership guard should reject this request'); }
}
$db->exec("INSERT INTO ucmp_configuration_sync_items (job_key,job_id,source_site_key,source_site_name,target_site_id,object_type,object_key,destination_id,status,updated)
    VALUES ('cfg:1:8',8,'source-a','Source A',1,'template','template:8',88,'paused',1)");
$migration = ['legacy_key'=>'cfg:1:8','job_key'=>'cfgc:controller-a:8','source_site_key'=>'source-a'];
$preview = $module->configurationSyncLegacyOwnershipLocal($migration);
$expect(count($preview['items']) === 1 && !isset($preview['items'][0]['intended']), 'Legacy review must show affected identities without configuration payloads');
try {
    $module->configurationSyncLegacyOwnershipLocal(array_replace($migration,['job_key'=>'cfgc:controller-b:8','apply'=>true,'review_hash'=>$preview['review_hash']]));
    throw new LogicException('Another controller reused the reviewed ownership grant');
} catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'changed after review'), 'Adoption preview must bind the exact new controller'); }
$db->exec("UPDATE ucmp_configuration_sync_items SET pause_reason='new edit' WHERE job_key='cfg:1:8'");
try {
    $module->configurationSyncLegacyOwnershipLocal($migration + ['apply'=>true,'review_hash'=>$preview['review_hash']]);
    throw new LogicException('Stale adoption preview accepted');
} catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'changed after review'), 'Even a same-second legacy edit must invalidate adoption review'); }
$preview = $module->configurationSyncLegacyOwnershipLocal($migration);
$adopted = $module->configurationSyncLegacyOwnershipLocal($migration + ['apply'=>true,'review_hash'=>$preview['review_hash']]);
$row = $db->query("SELECT * FROM ucmp_configuration_sync_items WHERE object_key='template:8'")->fetch(PDO::FETCH_ASSOC);
$expect($adopted['ok'] && $row['job_key']==='cfgc:controller-a:8' && $row['status']==='paused' && $row['pause_reason']==='new edit' && (int)$row['destination_id']===88,
    'Reviewed legacy adoption must preserve content identity, pause and revision evidence');
$other = $module->configurationSyncLegacyOwnershipLocal(array_replace($migration,['job_key'=>'cfgc:controller-b:8']));
$expect(!$other['items'], 'A second controller cannot adopt legacy ownership already assigned to another controller');
$winner = str_repeat('b',64);
$db->exec("CREATE TRIGGER competing_master BEFORE INSERT ON ucmp_settings WHEN NEW.skey='directory_master_token'
    BEGIN INSERT INTO ucmp_settings (site_id,skey,sval) VALUES (1,'directory_master_token','$winner'); SELECT RAISE(IGNORE); END");
$token = $module->directoryTokenForSite(['site_key'=>'fixture-destination']);
$expect($token === substr(hash_hmac('sha256','ucmp-directory-source\\0fixture-destination',$winner),0,16),
    'A competing first token writer must use the retained database identity, never its discarded candidate');
$db->exec('DROP TRIGGER competing_master');
$expect($module->directoryTokenForSite(['site_key'=>'fixture-destination']) === $token,'Repeated token reads must preserve the installed identity');
$module->setSetting('directory_master_token','invalid',1);
try { $module->directoryTokenForSite(['site_key'=>'fixture-destination']); throw new LogicException('Invalid saved token silently rotated'); }
catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'identity is invalid'),'Invalid saved token must require explicit repair'); }
$expect($module->getSetting('directory_master_token',1,'') === 'invalid','Token repair checks must not overwrite saved credentials');
echo "Configuration sync object, ownership, batch, publication and uncertain-commit assertions passed\n";
