<?php
declare(strict_types=1);

namespace {
    interface BMO {}
    class FreePBX_Helpers {}
}

namespace FreePBX\modules {
    require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
    require_once dirname(__DIR__) . '/lib/ConfigurationSyncService.php';
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

    final class ConfigurationSyncGranularityFreePBX
    {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }

    final class ConfigurationSyncGranularityModule extends Ucmprovisioner
    {
        public array $settings = [];
        public array $rules = [];
        public ?array $firmwareInventory = null;
        public array $hostedInventory = [];
        public array $tftpFiles = [];
        public bool $failPhonebookPublication = false;
        public int $phonebookPublications = 0;
        public function localSiteId(): int { return 1; }
        public function getSiteSettingsLocal(int $siteId): array { return $this->settings; }
        public function getDefaultTemplateRulesLocal(int $siteId = 0): array { return $this->rules; }
        public function saveSettingsLocal(array $data): void
        {
            foreach ((array)($data['settings'] ?? []) as $key=>$value) {
                if ($value === null) { unset($this->settings[$key]); }
                else { $this->settings[$key] = (string)$value; }
            }
            if (array_key_exists('default_template_rules',$data)) {
                $this->rules = array_values((array)$data['default_template_rules']);
            }
            if (array_key_exists('default_phonebook_key', (array)($data['settings'] ?? []))) {
                $this->phonebookPublications++;
                if ($this->failPhonebookPublication) {
                    throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Settings were saved; fixture cache unavailable', ['settings_saved'=>true]);
                }
            }
        }
        public function mediaAssetOpLocal(array $request): array { return ['ok'=>true,'items'=>$this->hostedInventory]; }
        public function tftpOp(array $request): array
        {
            if (($request['op'] ?? '') === 'firmware_rescan' && $this->firmwareInventory !== null) { return ['ok'=>true,'bundles'=>$this->firmwareInventory]; }
            return match ((string)($request['op'] ?? '')) {
                'list' => ['ok'=>true,'entries'=>$this->tftpFiles],
                'firmware_rescan' => ['ok'=>true,'bundles'=>[
                    ['profile'=>'cisco:sip78xx','load_name'=>'sip78-new','status'=>'complete'],
                    ['profile'=>'cisco:sip88xx','load_name'=>'sip88-new','status'=>'complete'],
                    ['profile'=>'cisco:sip78xx','load_name'=>'sip78-v2','status'=>'complete'],
                    ['profile'=>'cisco:sip88xx','load_name'=>'sip88-v2','status'=>'complete'],
                ]],
                'wallpaper_set_list' => ['ok'=>true,'sets'=>[]],
                'timezone_versions' => ['ok'=>true,'bundles'=>[]],
                default => ['ok'=>true],
            };
        }
    }

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    };
    $decode = static fn(string $value): array => json_decode($value,true) ?: [];

    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($db);
    $db->exec('CREATE TABLE ucmp_firmware_bundles (site_id INTEGER, profile TEXT, load_name TEXT, status TEXT)');
    $db->exec('CREATE TABLE ucmp_assets (site_id INTEGER, filename TEXT, state TEXT)');
    $db->exec('CREATE TABLE ucmp_templates (id INTEGER PRIMARY KEY, site_id INTEGER, name TEXT)');
    $db->exec("INSERT INTO ucmp_templates (id,site_id,name) VALUES (10,1,'Base'),(11,1,'Local')");
    foreach ([
        ['cisco:sip78xx','sip78-new'],['cisco:sip88xx','sip88-new'],
        ['cisco:sip78xx','sip78-v2'],['cisco:sip88xx','sip88-v2'],
    ] as [$profile,$load]) {
        $db->prepare("INSERT INTO ucmp_firmware_bundles (site_id,profile,load_name,status) VALUES (1,?,?,'complete')")->execute([$profile,$load]);
    }
    $module = new ConfigurationSyncGranularityModule(new ConfigurationSyncGranularityFreePBX($db));
    $module->settings['firmware_defaults'] = json_encode([
        'cisco:sip78xx'=>'local78','cisco:sip88xx'=>'local88',
    ], JSON_UNESCAPED_SLASHES);

    $settingsSnapshotMethod = new \ReflectionMethod(Ucmprovisioner::class, 'configurationSyncSettingsSnapshot');
    $settingsSnapshotMethod->setAccessible(true);
    $settingsSnapshot = (array)$settingsSnapshotMethod->invoke($module, [
        'ntp_server'=>'192.0.2.10',
        'date_template'=>'D/M/Y',
        'proxy'=>'USECALLMANAGER',
        'services_url'=>'https://controller.example.test/legacy-services',
        'media_base_url'=>'https://controller.example.test/media',
        'phone_services_base_url'=>'https://controller.example.test/phone-services',
    ], ['settings_clock_date','settings_sip_service'], 1, []);
    $expect(($settingsSnapshot['settings_clock_date']['ntp_server'] ?? '') === '192.0.2.10', 'Clock/date synchronization must carry the shared NTP server');
    $expect(($settingsSnapshot['settings_sip_service']['proxy'] ?? '') === 'USECALLMANAGER', 'SIP/service synchronization must carry the shared proxy policy');
    foreach ($settingsSnapshot as $category) {
        $expect(!array_key_exists('services_url', (array)$category)
            && !array_key_exists('media_base_url', (array)$category)
            && !array_key_exists('phone_services_base_url', (array)$category), 'PBX-specific URL/identity settings must never enter a portable configuration snapshot');
    }
    $context = [
        'job_key'=>'cfgc:fixture-2:9','job_id'=>9,'source_site_key'=>'site-a','source_site_name'=>'SITE-A',
        'soft_lock'=>true,'force'=>false,
    ];
    $emptySnapshot = ['schema'=>1,'source_site'=>['site_key'=>'site-a','name'=>'SITE-A'],'scopes'=>['templates'],'templates'=>[]];
    $empty = $module->configurationSyncApplyLocal($emptySnapshot, $context);
    $expect(!empty($empty['ok']), 'An explicitly empty template selection must remain valid');
    foreach ([
        $emptySnapshot + ['phonebooks'=>[['book_key'=>'unexpected']]],
        array_replace($emptySnapshot, ['templates'=>'invalid']),
        array_diff_key($emptySnapshot, ['templates'=>true]),
        $emptySnapshot + ['settings'=>['settings_network_vlan'=>['vlan_voice_vid'=>'17']]],
    ] as $badSnapshot) {
        $beforeSettings = $module->settings;
        try { $module->configurationSyncApplyLocal($badSnapshot, $context); $expect(false,'Out-of-scope or malformed payload must be rejected'); }
        catch (\InvalidArgumentException $expected) {}
        $expect($module->settings === $beforeSettings, 'Scope validation must happen before any policy mutation');
    }
    $filteredSettings = $module->configurationSyncApplyLocal([
        'schema'=>1,'source_site'=>['site_key'=>'site-a','name'=>'SITE-A'],
        'scopes'=>['settings_sip_service','settings_network_vlan'],
        'object_filter'=>['settings'=>['settings_sip_service']],
        'settings'=>['settings_sip_service'=>[]],
    ], $context);
    $expect(($filteredSettings['object_filter']['settings'] ?? []) === ['settings_sip_service'], 'An explicit Settings filter may narrow the scope payload');
    $applyDefaults = new \ReflectionMethod(Ucmprovisioner::class,'applyConfigurationSyncSiteDefaults');
    $applyDefaults->setAccessible(true);
    $source = ['firmware_defaults'=>json_encode([
        'cisco:sip78xx'=>'sip78-new','cisco:sip88xx'=>'sip88-new',
    ], JSON_UNESCAPED_SLASHES)];

    $first = (array)$applyDefaults->invoke($module,$source,$context);
    $expect(!empty($first['needs_confirmation']) && count($first['items']) === 2, 'Each initial destination assignment collision must be tracked independently');
    $again = (array)$applyDefaults->invoke($module,$source,$context);
    $expect(!empty($again['needs_confirmation']) && $decode($module->settings['firmware_defaults'])['cisco:sip78xx'] === 'local78', 'A pending assignment conflict must never overwrite itself on a later automatic run');
    $notPaused = $module->configurationSyncResumeLocal('cfgc:fixture-2:9','site_defaults','firmware_defaults:cisco:sip78xx');
    $expect(empty($notPaused['ok']), 'Resume must not bypass an unresolved revision conflict');
    $context['force'] = true;
    $applied = (array)$applyDefaults->invoke($module,$source,$context);
    $expect(empty($applied['needs_confirmation']) && $decode($module->settings['firmware_defaults'])['cisco:sip88xx'] === 'sip88-new', 'Explicit confirmation must apply only the reported assignment collisions');

    $context['force'] = false;
    $map = $decode($module->settings['firmware_defaults']);
    $map['cisco:sip78xx'] = 'local78-edit';
    $module->settings['firmware_defaults'] = json_encode($map,JSON_UNESCAPED_SLASHES);
    $guard = new \ReflectionMethod(Ucmprovisioner::class,'configurationSyncEditGuard');
    $guard->setAccessible(true);
    $pause = new \ReflectionMethod(Ucmprovisioner::class,'pauseConfigurationSyncItems');
    $pause->setAccessible(true);
    $locked = false;
    try { $guard->invoke($module,'site_defaults',0,'firmware_defaults:cisco:sip78xx',[]); }
    catch (\RuntimeException) { $locked = true; }
    $expect($locked, 'A soft-locked synchronized assignment must require destination confirmation');
    $editItems = (array)$guard->invoke($module,'site_defaults',0,'firmware_defaults:cisco:sip78xx',['sync_edit_confirmed'=>1]);
    $pause->invoke($module,$editItems,['sync_edit_reason'=>'destination test edit']);
    $sourceV2 = ['firmware_defaults'=>json_encode([
        'cisco:sip78xx'=>'sip78-v2','cisco:sip88xx'=>'sip88-v2',
    ], JSON_UNESCAPED_SLASHES)];
    $partial = (array)$applyDefaults->invoke($module,$sourceV2,$context);
    $afterPartial = $decode($module->settings['firmware_defaults']);
    $expect($afterPartial['cisco:sip78xx'] === 'local78-edit' && $afterPartial['cisco:sip88xx'] === 'sip88-v2', 'A local edit must pause only its exact profile while unrelated assignments keep synchronizing');
    $module->configurationSyncResumeLocal('cfgc:fixture-2:9','site_defaults','firmware_defaults:cisco:sip78xx');
    $applyDefaults->invoke($module,$sourceV2,$context);
    $expect($decode($module->settings['firmware_defaults'])['cisco:sip78xx'] === 'sip78-v2', 'Resume must clear the stale baseline so the next run actually reconciles the paused assignment');

    $targetKey = hash('sha256','cisco|cisco_xml|cisco_78xx||');
    $module->rules = [[
        'id'=>1,'template_id'=>11,'target_key'=>$targetKey,'target_vendor'=>'Cisco',
        'target_family'=>'cisco_xml','target_series'=>'cisco_78xx','target_model'=>'','target_role'=>'','enabled'=>1,
    ]];
    $sourceRule = [[
        'template_key'=>'','template_name'=>'Base','target_key'=>$targetKey,'target_vendor'=>'Cisco',
        'target_family'=>'cisco_xml','target_series'=>'cisco_78xx','target_model'=>'','target_role'=>'','enabled'=>1,
    ]];
    $applyRules = new \ReflectionMethod(Ucmprovisioner::class,'applyConfigurationSyncDefaultTemplates');
    $applyRules->setAccessible(true);
    $ruleConflict = (array)$applyRules->invoke($module,$sourceRule,$context);
    $expect(!empty($ruleConflict['needs_confirmation']) && $module->rules[0]['template_id'] === 11, 'One default-template target collision must be preserved and reported independently');
    $ruleAgain = (array)$applyRules->invoke($module,$sourceRule,$context);
    $expect(!empty($ruleAgain['needs_confirmation']) && $module->rules[0]['template_id'] === 11, 'A pending default-template conflict must stay pending on scheduled retries');
    $context['force'] = true;
    $ruleApplied = (array)$applyRules->invoke($module,$sourceRule,$context);
    $expect(empty($ruleApplied['needs_confirmation']) && $module->rules[0]['template_id'] === 10, 'Explicit confirmation must replace only the conflicting default-template target');

    $db->exec('CREATE TABLE ucmp_phones (site_id INTEGER, extension TEXT, updated INTEGER,last_applied INTEGER DEFAULT 0)');
    $db->exec("INSERT INTO ucmp_phones (site_id,extension,updated) VALUES (1,'1001',0)");
    $assetSnapshot = ['schema'=>1,'source_site'=>['site_key'=>'site-a','name'=>'SITE-A'],'scopes'=>['site_defaults']];
    foreach (['first-worker-load','second-worker-load'] as $load) {
        $module->firmwareInventory = [['profile'=>'cisco:sip78xx','load_name'=>$load,'status'=>'complete']];
        $applied = $module->configurationSyncApplyLocal($assetSnapshot + ['site_defaults'=>['firmware_defaults'=>['cisco:sip78xx'=>$load]]], $context);
        $expect(!empty($applied['ok']) && $decode($module->settings['firmware_defaults'])['cisco:sip78xx'] === $load,
            'Sequential jobs in one worker must inspect the current dependency inventory for each apply');
    }
    $db->exec("CREATE TRIGGER fail_phone_marker BEFORE UPDATE ON ucmp_phones BEGIN SELECT RAISE(ABORT, 'fixture phone marker failed'); END");
    $module->firmwareInventory = [['profile'=>'cisco:sip78xx','load_name'=>'third-worker-load','status'=>'complete']];
    $lateFailure = $module->configurationSyncApplyLocal($assetSnapshot + ['site_defaults'=>['firmware_defaults'=>['cisco:sip78xx'=>'third-worker-load']]], $context);
    $expect(empty($lateFailure['ok']) && !empty($lateFailure['items']) && !empty($lateFailure['reporting_error'])
        && $decode($module->settings['firmware_defaults'])['cisco:sip78xx'] === 'third-worker-load',
        'Failed phone-status bookkeeping must retain completed policy and ownership results');
    $db->exec('DROP TRIGGER fail_phone_marker');
    $db->exec('UPDATE ucmp_phones SET updated=0');
    $repair = $module->configurationSyncApplyLocal($assetSnapshot + ['site_defaults'=>['firmware_defaults'=>['cisco:sip78xx'=>'third-worker-load']]], $context);
    $expect(!empty($repair['ok']) && (int)$db->query('SELECT updated FROM ucmp_phones')->fetchColumn() > 0,
        'An unchanged retry must repair the previously failed phone marker');

    $module->hostedInventory = [
        ['filename'=>'same.png','url'=>'http://target.test/one/same.png','validation_status'=>'verified'],
        ['filename'=>'same.png','url'=>'http://target.test/two/same.png','validation_status'=>'verified'],
        ['filename'=>'invalid.png','url'=>'http://target.test/invalid.png','validation_status'=>'invalid'],
    ];
    $db->exec("INSERT INTO ucmp_assets (site_id,filename,state) VALUES (1,'stale.png','current')");
    $stale = $module->configurationSyncApplyLocal($assetSnapshot + ['site_defaults'=>['cisco_wallpaper_defaults'=>['fixture'=>'stale.png']]], $context);
    $expect(!isset($decode($module->settings['cisco_wallpaper_defaults'] ?? '{}')['fixture']) && !empty($stale['warnings']),
        'A historical current registry row must not validate a missing physical catalog dependency');
    $hosted = $module->configurationSyncApplyLocal($assetSnapshot + ['site_defaults'=>['hosted_image_defaults'=>[
        'ambiguous'=>'http://source.test/same.png','exact'=>'http://target.test/one/same.png','invalid'=>'http://source.test/invalid.png',
    ]]], $context);
    $images = $decode($module->settings['hosted_image_defaults'] ?? '{}');
    $expect(!isset($images['ambiguous']) && !isset($images['invalid']) && ($images['exact'] ?? '') === 'http://target.test/one/same.png',
        'Hosted dependency remapping must reject ambiguous basenames and invalid content while preserving an exact verified URL');
    $applyCategory = new \ReflectionMethod(Ucmprovisioner::class,'applyConfigurationSyncSettingsCategory');
    $module->failPhonebookPublication = true;
    $cacheFailure = $applyCategory->invoke($module,'settings_assets',['default_phonebook_key'=>''],$context);
    $expect(!empty($cacheFailure['changed']) && str_contains($cacheFailure['warning'],'Settings were saved')
        && ($cacheFailure['items'][0]['status'] ?? '') === 'active', 'A late cache failure must retain saved setting ownership and its warning');
    $module->failPhonebookPublication = false;
    $cacheRetry = $applyCategory->invoke($module,'settings_assets',['default_phonebook_key'=>''],$context);
    $expect($module->phonebookPublications === 2 && $cacheRetry['warning'] === '' && empty($cacheRetry['needs_confirmation']),
        'An unchanged saved default must retry its failed publication without conflicting with its own prior save');
    $chosen = ['cisco_transport','non_cisco_sip_port','services_url'];
    $snapshotFields = (array)$settingsSnapshotMethod->invoke($module,
        ['cisco_transport'=>'tcp','services_url'=>'http://shared.test/services','voicemail_number'=>'*99'],
        ['settings_sip_service'],1,[],$chosen);
    $expect($snapshotFields['settings_sip_service'] === ['cisco_transport'=>'tcp','non_cisco_sip_port'=>null,'services_url'=>'http://shared.test/services'],
        'Selected endpoint and explicit URL fields must be carried; an absent source field resets destination inheritance');
    $fieldContext = array_replace($context,['job_key'=>'cfgc:fields:1','force'=>true]);
    $module->settings['non_cisco_sip_port']='5199'; $module->settings['voicemail_number']='*88';
    $fieldPacket = ['schema'=>1,'source_site'=>['site_key'=>'site-a','name'=>'SITE-A'],'scopes'=>['settings_sip_service'],
        'object_filter'=>['setting_keys'=>$chosen],'settings'=>$snapshotFields];
    $fieldApply = $module->configurationSyncApplyLocal($fieldPacket,$fieldContext);
    $expect(!empty($fieldApply['ok']) && $module->settings['cisco_transport']==='tcp' && !isset($module->settings['non_cisco_sip_port'])
        && $module->settings['voicemail_number']==='*88','Receiver must change only selected fields and reset an absent override');
    $fieldPacket['settings']['settings_sip_service']['voicemail_number']='*11'; $before=$module->settings;
    try { $module->configurationSyncApplyLocal($fieldPacket,$fieldContext); $expect(false,'Out-of-filter fields must fail'); }
    catch (\InvalidArgumentException $expected) {}
    $expect($module->settings===$before,'Out-of-filter payload rejection must precede mutation');
    $companionPacket=['schema'=>1,'source_site'=>['site_key'=>'site-a','name'=>'SITE-A'],'scopes'=>['settings_cisco_policy'],
        'object_filter'=>['setting_keys'=>['softkey_file']], 'settings'=>['settings_cisco_policy'=>['softkey_file'=>'s_fixture.xml']]];
    $waiting=$module->configurationSyncApplyLocal($companionPacket,$fieldContext);
    $expect(($waiting['items'][0]['status'] ?? '')==='missing_dependency' && !isset($module->settings['softkey_file']),
        'Missing companion files must not replace a usable destination setting');
    $module->tftpFiles=[['name'=>'s_fixture.xml','dir'=>false,'size'=>128]];
    $healed=$module->configurationSyncApplyLocal($companionPacket,$fieldContext);
    $expect(($healed['items'][0]['status'] ?? '')==='active' && $module->settings['softkey_file']==='s_fixture.xml',
        'A later file arrival must activate the retained companion setting without reseeding');
    echo "Configuration sync granular ownership and selected-field assertions passed\n";
}
