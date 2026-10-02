<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/ConfigurationSyncService.php';

use FreePBX\modules\Ucmprovisioner\ConfigurationSyncService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

final class ConfigurationSyncScopeFake
{
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['site_key'=>'controller-a']; }
    public array $snapshots = [];
    public array $capabilityCalls = [];
    public array $events = [];
    public int $applies = 0;
    public bool $dropGroupFilter = false;
    public bool $omitGroupsCapability = false;
    public bool $fieldsCapability = true;
    public bool $dropFieldsSource = false;
    public bool $dropFieldsReply = false;

    public function getSites(): array
    {
        return [
            ['id'=>1,'name'=>'SITE-A','enabled'=>1],
            ['id'=>2,'name'=>'SITE-B','enabled'=>1],
            ['id'=>3,'name'=>'SITE-C','enabled'=>1],
        ];
    }

    public function configurationSyncSnapshot(int $source, array $scopes, array $objectFilter = []): array
    {
        $this->events[] = 'snapshot';
        $this->snapshots[] = compact('source','scopes','objectFilter');
        if ($this->dropGroupFilter) { unset($objectFilter['groups']); }
        if ($this->dropFieldsSource) { unset($objectFilter['setting_keys']); }
        return [
            'ok'=>true,
            'schema'=>1,
            'source_site'=>['site_key'=>'site-a','name'=>'SITE-A'],
            'scopes'=>$scopes,
            'object_filter'=>$objectFilter,
        ];
    }

    public function configurationSyncCapabilities(int $target): array
    {
        $this->events[] = 'capabilities:' . $target;
        $this->capabilityCalls[] = $target;
        $scopes = ConfigurationSyncService::SCOPES;
        if ($this->omitGroupsCapability && $target === 2) {
            $scopes = array_values(array_diff($scopes, ['groups']));
        }
        return [
            'ok'=>true,
            'schema'=>1,
            'scopes'=>$scopes,
            'object_filter'=>['templates'=>true,'groups'=>true,'settings'=>true,'setting_keys'=>$this->fieldsCapability],
        ];
    }

    public function configurationSyncAssets(int $source, array $targets, array $scopes, array $options = []): array
    {
        $this->events[] = 'assets';
        return ['ok'=>true,'status'=>'success','items'=>[]];
    }

    public function configurationSyncApply(int $target, array $snapshot, array $options): array
    {
        $this->events[] = 'apply:' . $target;
        $this->applies++;
        if ($this->dropFieldsReply) { unset($snapshot['object_filter']['setting_keys']); }
        return [
            'ok'=>true,
            'status'=>'success',
            'scopes'=>$snapshot['scopes'] ?? [],
            'object_filter'=>$snapshot['object_filter'] ?? [],
            'items'=>[],
        ];
    }

    public function configurationSyncRelease(int $target, string $jobKey): array
    {
        return ['ok'=>true];
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ConfigurationSyncService::ensureSchema($db);
$module = new ConfigurationSyncScopeFake();
$stateDir = sys_get_temp_dir() . '/ucmp-configuration-scope-' . bin2hex(random_bytes(6));
$service = new ConfigurationSyncService($db, $module, $stateDir, static fn(): int => 1000);

try {
    $saved = $service->save([
        'name'=>'Groups and settings',
        'source_site_id'=>1,
        'target_site_ids'=>[2],
        'scopes'=>['settings_vlan','groups','settings_sip_service'],
        'group_ids'=>[12,4,12],
        'settings_categories'=>['network_vlan','sip'],
        'enabled'=>0,
    ]);
    $job = (array)($saved['job'] ?? []);
    $expect($job['scopes'] === ['groups','settings_network_vlan','settings_sip_service'], 'Group and granular Settings scopes must be canonical and deterministic');
    $expect($job['group_ids'] === [4,12], 'Group filters must be de-duplicated and sorted');
    $expect($job['settings_categories'] === ['settings_network_vlan','settings_sip_service'], 'Settings categories must be canonical and deterministic');
    $expect(($job['object_filter']['groups'] ?? []) === [4,12], 'Group filters must be persisted in the durable object filter');
    $expect(($job['object_filter']['settings'] ?? []) === ['settings_network_vlan','settings_sip_service'], 'Settings filters must be persisted in the durable object filter');

    $run = $service->runNow((int)$job['id']);
    $expect(!empty($run['ok']), 'A groups and granular Settings synchronization must run through the existing hooks');
    $expect($module->capabilityCalls === [2], 'The destination capability contract must be probed for the selected site');
    $expect($module->events === ['snapshot','capabilities:2','assets','apply:2'], 'Destination capabilities must be checked before any asset copy or policy apply');
    $expect(($module->snapshots[0]['objectFilter']['groups'] ?? []) === [4,12], 'The source snapshot must receive the selected groups');
    $expect(($module->snapshots[0]['objectFilter']['settings'] ?? []) === ['settings_network_vlan','settings_sip_service'], 'The source snapshot must receive selected Settings categories');

    $module->omitGroupsCapability = true;
    $capabilityRejected = $service->runNow((int)$job['id']);
    $expect(empty($capabilityRejected['ok']) && str_contains((string)($capabilityRejected['message'] ?? ''), 'requested group/granular Settings scopes'), 'A destination that cannot acknowledge the selected scopes must fail closed');
    $expect($module->capabilityCalls === [2,2] && $module->applies === 1 && end($module->events) === 'capabilities:2', 'Capability rejection must happen before asset copy or destination apply');
    $module->omitGroupsCapability = false;
    $module->dropGroupFilter = true;
    $rejected = $service->runNow((int)$job['id']);
    $expect(empty($rejected['ok']) && str_contains((string)($rejected['message'] ?? ''), 'selected-group') && $module->applies === 1, 'A source that ignores a selected group filter must fail closed before destination apply');

    $module->dropGroupFilter = false;
    $module->omitGroupsCapability = true;
    $multi = $service->save(['name'=>'Independent sites','source_site_id'=>1,'target_site_ids'=>[2,3],'scopes'=>['groups'],'enabled'=>0]);
    $independent = $service->runNow((int)$multi['job']['id']);
    $expect(empty($independent['ok']) && empty($independent['targets'][2]['ok']) && !empty($independent['targets'][3]['ok']), 'An incompatible destination must not prevent a compatible destination from finishing');
    $expect($module->applies === 2 && end($module->events) === 'apply:3', 'Only the qualified destination may receive policy');
    $module->omitGroupsCapability = false;
    foreach ([['settings_sip_service',['cisco_transport','non_cisco_sip_port']],['settings_sip_service',[]],['site_defaults',['firmware_defaults']]] as [$fieldScope,$keys]) {
        $fields = $service->save(['name'=>'Fields ' . $fieldScope . count($keys),'source_site_id'=>1,'target_site_ids'=>[2],
            'scopes'=>[$fieldScope],'setting_keys_present'=>1,'setting_keys'=>$keys,'enabled'=>0]);
        $expect($fields['job']['setting_keys'] === $keys,'Explicit field choices, including none, must survive storage');
        $run = $service->runNow((int)$fields['job']['id']);
        $expect(!empty($run['ok']),'Updated peers must acknowledge the exact field selection');
        foreach (['fieldsCapability','dropFieldsSource','dropFieldsReply'] as $fault) {
            $module->$fault = $fault !== 'fieldsCapability'; $module->events=[];
            $result = $service->runNow((int)$fields['job']['id']);
            $expect(empty($result['ok']),'A peer must not silently ignore selected fields: ' . $fault);
            if ($fault !== 'dropFieldsReply') { $expect(!in_array('assets',$module->events,true),'Field rejection must precede any asset copy'); }
            $module->$fault = $fault === 'fieldsCapability';
        }
    }
    echo "Configuration sync group/settings scope and field-selection assertions passed\n";
} finally {
    foreach (glob($stateDir . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
    if (is_dir($stateDir)) { rmdir($stateDir); }
}
