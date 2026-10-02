<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$templates = (string)file_get_contents($root . '/views/templates.php');
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(json_encode((object)[]) === '{}', 'An empty settings map must be represented as a JSON object');
$expect(
    str_contains($templates, '(object)$advancedSettings')
    && str_contains($templates, 'browserJson((object)$settings')
    && str_contains($templates, "Array.isArray(base)||typeof base!=='object'"),
    'The editor must preserve object semantics before assigning named settings'
);
$expect(
    str_contains($module, 'Template setting overrides must be a JSON object')
    && str_contains($module, 'JSON_THROW_ON_ERROR'),
    'The server must reject list-shaped or malformed settings rather than incrementing a revision with empty data'
);
$expect(
    str_contains($templates, "function softkeyMap(value){return value&&typeof value==='object'&&!Array.isArray(value)?value:{};}")
    && str_contains($templates, 'softkeyPolicy.states=softkeyMap(softkeyPolicy.states)')
    && str_contains($templates, 'softkeyPolicy.definitions=softkeyMap(softkeyPolicy.definitions)')
    && str_contains($templates, 'softkeyPolicy.annotations=softkeyMap(softkeyPolicy.annotations)')
    && str_contains($module, '$this->softkeyField($d[\'softkeys\'] ?? null)')
    && str_contains($module, 'SoftkeyPolicy::normalizeV2($decoded)'),
    'Empty PHP maps must be coerced back to JavaScript objects and schema-2 policies validated at the save boundary'
);
$expect(
    str_contains($templates, 'data-policy="<?php echo $h($policy); ?>"')
    && str_contains($templates, 'function selectedFeaturePolicy()')
    && str_contains($templates, "if(value!=='')fp[String(field.data('policy'))]=value==='1'")
    && str_contains($templates, 'Each feature is tier-aware')
    && !str_contains($templates, "fp[$(this).val()]=$(this).is(':checked')"),
    'Untouched feature-policy controls must inherit instead of silently saving an all-disabled policy'
);
$expect(
    str_contains($templates, 'id="ucm-label-preview-extension"')
    && str_contains($templates, 'loadLabelPreviewExtensions')
    && str_contains($templates, 'row.final_overrides')
    && str_contains($module, "'identity'=>\$canonical")
    && str_contains($module, "'final_overrides'=>\$finalOverrides"),
    'The label preview must select a real AD-linked extension and apply its final output overrides'
);
$expect(
    str_contains($templates, 'ucm-directory-label-mode')
    && str_contains($templates, 'Inherit Phonebook Designer')
    && str_contains($templates, "input.val('').prop('disabled',true)")
    && str_contains($module, "unset(\$settings[\$patternKey])"),
    'Directory-label inheritance must be an explicit editor state that removes the template pattern'
);
$expect(
    str_contains($templates, 'None — disable key')
    && str_contains($templates, 'maxlength="256"')
    && str_contains($templates, 'browserJson($ghpIcons')
    && str_contains($templates, 'Context key \'+(i-1)')
    && str_contains($templates, 'baseEnd=ghp?Math.min(c.base,7):c.base')
    && str_contains($templates, 'if(isGhp63x())base=7')
    && str_contains($templates, "rows>=6")
    && str_contains($templates, 'data-key="gs_P8500"')
    && str_contains($templates, 'data-key="gs_P102"')
    && str_contains($templates, "['gs_P104',_('Account 1 ringtone (P104)')]")
    && str_contains($templates, "['gs_P423',_('Account 2 ringtone (P423)')]")
    && str_contains($templates, 'Always skip firmware check')
    && str_contains($templates, 'Check interval, minutes (P193)'),
    'The GHP63x editor must expose its verified context-key, display, ringtone and firmware-schedule controls'
);

$expect(
    str_contains($templates, 'ucm-ringtone-picker')
    && str_contains($templates, 'data-key="hosted_ringtone_url"')
    && str_contains($templates, 'hostedRingtoneSelection')
    && str_contains($templates, "/\\.(?:wav|mp3|ogg)$/i")
    && str_contains($templates, "model:'all'")
    && str_contains($templates, 'model:modelName')
    && str_contains($templates, 'data-key="ringtone"'),
    'Yealink and Grandstream templates must select prepared generic/exact-model audio without replacing the native ringtone slot'
);

echo "Template editor persistence assertions passed\n";

interface BMO {}
class FreePBX_Helpers {}
require_once $root . '/Ucmprovisioner.class.php';
$editorDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema = simplexml_load_file($root . '/module.xml');
foreach ($schema->database->table as $table) {
    $name = (string)$table['name'];
    if (!in_array($name, ['ucmp_templates','ucmp_groups','ucmp_extensions','ucmp_models','ucmp_phones','ucmp_default_template_rules'], true)) { continue; }
    $columns = [];
    foreach ($table->field as $field) { $key = (string)$field['name']; $columns[] = '"' . $key . '" ' . ($key === 'id' ? 'INTEGER PRIMARY KEY' : (in_array($key, ['site_id','revision','parent_group_id','group_id'], true) ? 'INTEGER' : 'TEXT')); }
    $editorDb->exec('CREATE TABLE ' . $name . ' (' . implode(',', $columns) . ')');
}
$editorModule = new class($editorDb) extends \FreePBX\modules\Ucmprovisioner {
    public function __construct(PDO $db) { parent::__construct(new class($db) { public function __construct(private PDO $db) {} public function Database(): PDO { return $this->db; } }); }
    public function localSiteId(): int { return 1; }
};
\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($editorDb);
$templateId = $editorModule->saveTemplateLocal(['name'=>'Fixture template','site_id'=>1,'target_family'=>'cisco_xml']);
$groupId = $editorModule->saveGroupLocal(['name'=>'Fixture group','site_id'=>1]);
$write = new ReflectionMethod(\FreePBX\modules\Ucmprovisioner::class, 'upsert');
foreach (['ucmp_templates'=>$templateId,'ucmp_groups'=>$groupId] as $table=>$id) {
    $editorDb->exec("UPDATE $table SET name='Concurrent edit',revision=3 WHERE id=$id");
    $rejected = false;
    try { $write->invoke($editorModule, $table, ['name'=>'Stale edit','revision'=>2], $id); }
    catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'changed or was deleted'); }
    $expect($rejected && $editorDb->query("SELECT name FROM $table WHERE id=$id")->fetchColumn() === 'Concurrent edit', 'Final ' . $table . ' update must reject a revision changed after initial validation');
    $write->invoke($editorModule, $table, ['name'=>'Reviewed edit','revision'=>4], $id);
}
foreach (['template'=>$templateId,'group'=>$groupId] as $kind=>$id) {
    $table = $kind === 'template' ? 'ucmp_templates' : 'ucmp_groups';
    $editorDb->prepare("INSERT INTO ucmp_configuration_sync_items (job_key,job_id,target_site_id,object_type,object_key,destination_id,status,soft_lock,source_site_name,source_site_key) VALUES ('cfg:fixture:7',7,1,?,?,?,'active',1,'Fixture source','fixture')")->execute([$kind,$kind . ':fixture',$id]);
    $editorDb->exec("CREATE TRIGGER fail_pause BEFORE UPDATE ON ucmp_configuration_sync_items WHEN NEW.status='paused' BEGIN SELECT RAISE(ABORT,'Fixture pause write failed'); END");
    $before = $editorDb->query("SELECT * FROM $table WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $payload = ['id'=>$id,'site_id'=>1,'name'=>'Must not persist','revision'=>4,'sync_edit_confirmed'=>1,'target_family'=>'cisco_xml'];
    $rejected = false;
    try { $kind === 'template' ? $editorModule->saveTemplateLocal($payload) : $editorModule->saveGroupLocal($payload); }
    catch (PDOException $error) { $rejected = str_contains($error->getMessage(), 'Fixture pause write failed'); }
    $expect($rejected && $editorDb->query("SELECT * FROM $table WHERE id=$id")->fetch(PDO::FETCH_ASSOC) === $before, 'A failed sync pause must not leave an unpaused ' . $kind . ' edit committed');
    $editorDb->exec('DROP TRIGGER fail_pause');
    $kind === 'template' ? $editorModule->saveTemplateLocal($payload) : $editorModule->saveGroupLocal($payload);
    $expect($editorDb->query("SELECT status FROM ucmp_configuration_sync_items WHERE object_type='$kind'")->fetchColumn() === 'paused', 'A successful edit must pause the inbound sync in the same transaction');
    $editorDb->exec("UPDATE ucmp_configuration_sync_items SET status='active' WHERE object_type='$kind'");
    $editorDb->exec("CREATE TRIGGER fail_delete_pause BEFORE UPDATE ON ucmp_configuration_sync_items WHEN NEW.status='paused' BEGIN SELECT RAISE(ABORT,'Fixture delete pause failed'); END");
    $beforeDelete = $editorDb->query("SELECT * FROM $table WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $rejected = false;
    try { $kind === 'template' ? $editorModule->deleteTemplateLocal($id, ['sync_edit_confirmed'=>1]) : $editorModule->deleteGroupLocal($id, ['sync_edit_confirmed'=>1]); }
    catch (PDOException $error) { $rejected = str_contains($error->getMessage(), 'Fixture delete pause failed'); }
    $expect($rejected && $editorDb->query("SELECT * FROM $table WHERE id=$id")->fetch(PDO::FETCH_ASSOC) === $beforeDelete, 'A failed sync pause must retain the deleted ' . $kind . ' record');
    $editorDb->exec('DROP TRIGGER fail_delete_pause');
}
echo "Template/group revision and synchronized-edit transaction assertions passed\n";
$editorDb->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY, site_id INTEGER, skey TEXT, sval TEXT, UNIQUE(site_id,skey))');
$editorModule->setSetting('voicemail_number','100',1);
$editorDb->exec("INSERT INTO ucmp_configuration_sync_items (job_key,job_id,target_site_id,object_type,object_key,status,soft_lock,source_site_name,source_site_key) VALUES ('cfg:fixture:settings',8,1,'settings_sip_service','setting:voicemail_number','active',1,'Fixture source','fixture')");
$editorDb->exec("CREATE TRIGGER fail_settings_pause BEFORE UPDATE ON ucmp_configuration_sync_items WHEN NEW.status='paused' BEGIN SELECT RAISE(ABORT,'Fixture settings pause failed'); END");
try {
    $editorModule->saveSettingsLocal(['site_id'=>1,'settings'=>['voicemail_number'=>'200'],'sync_edit_confirmed'=>1]);
    throw new LogicException('Settings save ignored a failed sync pause');
} catch (PDOException $error) { $expect(str_contains($error->getMessage(),'Fixture settings pause failed'),'Settings fixture failed for an unrelated reason'); }
$expect($editorModule->getSiteSettingsLocal(1)['voicemail_number']==='100','A failed sync pause must not commit an unpaused settings change');
$editorDb->exec('DROP TRIGGER fail_settings_pause');
$editorModule->saveSettingsLocal(['site_id'=>1,'settings'=>['voicemail_number'=>'200'],'sync_edit_confirmed'=>1]);
$expect($editorModule->getSiteSettingsLocal(1)['voicemail_number']==='200' && $editorDb->query("SELECT status FROM ucmp_configuration_sync_items WHERE object_key='setting:voicemail_number'")->fetchColumn()==='paused','Setting and sync pause must commit together');
echo "Settings synchronized-edit transaction assertions passed\n";
$directoryEngine = new class {
    public bool $fail = true;
    public function setPublishedDefaultPhonebook(string $key): array {
        if ($this->fail) { throw new RuntimeException('Fixture cache publication failed'); }
        return ['ok'=>true,'warning'=>'Fixture registry warning'];
    }
};
$engineProperty = new ReflectionProperty(\FreePBX\modules\Ucmprovisioner::class, 'engines');
$engineProperty->setValue($editorModule, ['DirectoryService'=>$directoryEngine]);
foreach ([true,false] as $publicationFails) {
    $directoryEngine->fail = $publicationFails;
    try {
        $editorModule->saveSettingsLocal(['site_id'=>1,'settings'=>['default_phonebook_key'=>'','ntp_server'=>'192.0.2.15']]);
        throw new LogicException('Late phonebook failure was hidden');
    } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $error) {
        $expect(!empty($error->receipt['settings_saved']) && in_array('ntp_server',$error->receipt['saved_keys'],true)
            && !str_contains(json_encode($error->receipt),'192.0.2.15'), 'Late publication receipt must retain saved key identities without setting values');
        $expect(!empty($error->receipt['phonebook_publication']['ok']) === !$publicationFails, 'Publication and later registry failure must remain distinct');
        $expect($editorModule->getSiteSettingsLocal(1)['ntp_server']==='192.0.2.15', 'Late cache failure must preserve committed settings');
    }
}
$engineProperty->setValue($editorModule, []);
echo "Settings late-publication receipt assertions passed\n";
foreach (['false','throw'] as $commitFault) {
    $commitDb = new class extends PDO {
        public string $fault = '';
        public int $reversals = 0;
        public function __construct() { parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
        public function commit(): bool {
            $result=parent::commit();
            if ($this->fault==='throw') { throw new RuntimeException('Fixture reply lost after commit'); }
            return $this->fault==='false' ? false : $result;
        }
        public function rollBack(): bool { $this->reversals++;return parent::rollBack(); }
    };
    $commitDb->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY,site_id INTEGER,skey TEXT,sval TEXT,UNIQUE(site_id,skey))');
    \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($commitDb);
    $commitDb->fault=$commitFault;
    $editorClass=get_class($editorModule);$commitModule=new $editorClass($commitDb);
    $blockedCache=new class { public int $calls=0;public function setPublishedDefaultPhonebook(string $key):array { $this->calls++;return ['ok'=>true]; } };
    $engineProperty->setValue($commitModule,['DirectoryService'=>$blockedCache]);
    try {
        $commitModule->saveSettingsLocal(['site_id'=>1,'settings'=>['default_phonebook_key'=>'','voicemail_number'=>'900']]);
        throw new LogicException('Unconfirmed commit was reported as complete');
    } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $error) {
        $expect($error->receipt['status']==='unknown' && empty($error->receipt['settings_saved']) && $error->receipt['failed_stage']==='settings_commit',
            'Lost commit acknowledgement must keep uncertainty distinct from a confirmed saved receipt');
    }
    $expect($commitModule->getSiteSettingsLocal(1)['voicemail_number']==='900' && $commitDb->reversals===0 && $blockedCache->calls===0,
        'An unconfirmed accepted commit must not be reversed or start a dependent cache publication');
}
echo "Settings lost-commit acknowledgement assertions passed\n";
$wallpaperValues = ['cisco_default_wallpaper'=>'old.png','cisco_wallpaper_by_profile'=>['fixture'=>'old.png'],
    'phone_label'=>'old.png','cisco_phone_password'=>'old.png','unrelated'=>['value'=>'old.png']];
$editorDb->prepare('INSERT INTO ucmp_templates (site_id,name,revision,setting_overrides) VALUES (1,?,1,?)')->execute(['Wallpaper fixture',json_encode($wallpaperValues,JSON_THROW_ON_ERROR)]);
$wallpaperTemplateId = (int)$editorDb->lastInsertId();
$editorModule->setSetting('cisco_wallpaper_defaults','{"fixture":"old.png"}',1);
$editorDb->exec("CREATE TRIGGER fail_wallpaper_template BEFORE UPDATE ON ucmp_templates WHEN OLD.id=$wallpaperTemplateId BEGIN SELECT RAISE(ABORT,'Fixture wallpaper template failed'); END");
try { $editorModule->replaceWallpaperReferencesLocal([['old'=>'old.png','new'=>'new.png']]); throw new LogicException('Failed template reference write accepted'); }
catch (PDOException $error) { $expect(str_contains($error->getMessage(),'Fixture wallpaper template failed'),'Reference fixture failed for an unrelated reason'); }
$expect($editorModule->getSiteSettingsLocal(1)['cisco_wallpaper_defaults']==='{"fixture":"old.png"}','A later template failure must leave the earlier setting map unchanged');
$editorDb->exec('DROP TRIGGER fail_wallpaper_template');
$referenceResult = $editorModule->replaceWallpaperReferencesLocal([['old'=>'old.png','new'=>'new.png']]);
$afterReferences = json_decode((string)$editorDb->query("SELECT setting_overrides FROM ucmp_templates WHERE id=$wallpaperTemplateId")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
$wallpaperValues['cisco_default_wallpaper']='new.png';$wallpaperValues['cisco_wallpaper_by_profile']['fixture']='new.png';
$expect($referenceResult['ok'] && $afterReferences===$wallpaperValues,'Wallpaper rename must only change wallpaper policy, preserving unrelated strings and credential fields');
echo "Wallpaper scoped-reference and transaction assertions passed\n";
foreach (['ucm_image_upload','ucm_ringtone_upload'] as $uploadField) {
    $_FILES = [$uploadField=>['name'=>'fixture.png','tmp_name'=>'unused-fixture-upload','error'=>UPLOAD_ERR_OK]];
    $rejected = false;
    try { $editorModule->saveTemplate(['site_id'=>1,'name'=>'Upload bypass','target_family'=>'cisco_xml']); }
    catch (InvalidArgumentException $error) { $rejected = str_contains($error->getMessage(), 'in Media'); }
    $expect($rejected, 'Legacy template uploads must not bypass managed Media validation or write into installed module files');
}
$_FILES = [];

define('FREEPBX_IS_AUTH', true);
if (!function_exists('_')) { function _(string $message): string { return $message; } }
$viewModule = new class {
    public array $catalogCalls = [];
    public function localSiteId(): int { return 1; }
    public function getTemplate(int $id, int $site): array { return ['id'=>$id,'site_id'=>$site,'name'=>'Fixture',
        'target_family'=>'cisco_xml','target_model'=>'Cisco|Unavailable','target_series'=>'missing_series','target_role'=>'missing_role',
        'phonebook_source_site'=>'missing_site','phonebook_key'=>'missing_book']; }
    public function configurationSyncStates(int $site, string $kind): array { return []; }
    public function getSites(): array { return [['id'=>2,'site_key'=>'fixture','name'=>'Fixture branch']]; }
    public function getModels(): array { return []; }
    public function getPublishedDirectoryPhonebooks(int $site): array { $this->catalogCalls[] = $site; return ['ok'=>true,'phonebooks'=>[]]; }
};
$render = static function (object $ucm): string {
    $_REQUEST = ['view'=>'template','id'=>3,'site_id'=>2]; ob_start();
    try { include dirname(__DIR__) . '/views/templates.php'; return (string)ob_get_contents(); }
    finally { ob_end_clean(); }
};
$document = new DOMDocument(); $oldErrors = libxml_use_internal_errors(true);
try { $document->loadHTML($render($viewModule), LIBXML_NONET); } finally { libxml_clear_errors(); libxml_use_internal_errors($oldErrors); }
$xpath = new DOMXPath($document);
foreach (['ucm-preview-model'=>'Cisco|Unavailable','ucm-target-series'=>'missing_series','ucm-phonebook-source'=>'missing_site','ucm-phonebook-key'=>'missing_book'] as $selector=>$value) {
    $selected = $xpath->query('//select[@id="' . $selector . '"]/option[@selected]');
    $expect($selected->length === 1 && $selected->item(0)->getAttribute('value') === $value, 'An unavailable saved choice must remain selected in ' . $selector);
}
$expect($viewModule->catalogCalls === [], 'An unavailable explicit phonebook source must not read the owning site catalog');
$expect($xpath->query('//select[@name="target_role"]/option[@selected and @value="missing_role"]')->length === 1, 'An unavailable role must not broaden to all roles');
echo "Template unavailable-reference rendered-form assertions passed\n";
