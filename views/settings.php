<?php
/** Validated, site-owned provisioning defaults inherited by that site's templates. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$sites = $ucm->getSites();
$siteId = (int)($_REQUEST['site_id'] ?? 0) ?: $ucm->localSiteId();
$models = $ucm->getModels();
$loadError = '';
try { $values = $ucm->getSiteSettings($siteId); }
catch (\Throwable $e) { $values = []; $loadError = $e->getMessage(); }
try {
    $defaultTemplateRules = $ucm->getDefaultTemplateRules($siteId);
    $siteTemplates = $ucm->getTemplates($siteId, false);
} catch (\Throwable $e) {
    $defaultTemplateRules = [];
    $siteTemplates = [];
    $loadError = trim($loadError . ' ' . $e->getMessage());
}
$publishedPhonebooks = ['ok'=>false,'phonebooks'=>[],'source'=>[],'replicated'=>false];
try { $publishedPhonebooks = $ucm->getPublishedDirectoryPhonebooks($siteId); }
catch (\Throwable $e) { $publishedPhonebooks['message'] = $e->getMessage(); }
$siteDefaultSyncStates = $defaultTemplateSyncStates = [];
$settingsInboundSyncStates = [];
try {
    foreach ($ucm->configurationSyncStates($siteId, 'site_defaults') as $state) {
        if ((string)($state['status'] ?? '') !== 'released') { $siteDefaultSyncStates[] = $state; }
    }
    foreach ($ucm->configurationSyncStates($siteId, 'default_templates') as $state) {
        if ((string)($state['status'] ?? '') !== 'released') { $defaultTemplateSyncStates[] = $state; }
    }
    foreach (\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES as $settingsScope) {
        foreach ($ucm->configurationSyncStates($siteId, (string)$settingsScope) as $state) {
            if ((string)($state['status'] ?? '') !== 'released') {
                $settingsInboundSyncStates[(string)$settingsScope][] = $state;
            }
        }
    }
} catch (\Throwable) {
    $siteDefaultSyncStates = $defaultTemplateSyncStates = [];
    $settingsInboundSyncStates = [];
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$v = fn($k, $d = '') => (string)($values[$k] ?? $d);
$select = static function(string $name, string $current, array $options) use ($h): void {
    echo '<select class="form-control" name="settings[' . $h($name) . ']">';
    foreach ($options as $value => $label) {
        echo '<option value="' . $h($value) . '"' . ((string)$value === $current ? ' selected' : '') . '>' . $h($label) . '</option>';
    }
    echo '</select>';
};
$transports = ['udp'=>'UDP', 'tcp'=>'TCP', 'tls'=>'TLS'];
$endpointDrivers = ['sip'=>'CHAN_SIP', 'pjsip'=>'PJSIP'];
$dateFormats = ['D/M/Y'=>'DD/MM/YYYY', 'M/D/Y'=>'MM/DD/YYYY', 'Y/M/D'=>'YYYY/MM/DD', 'D-M-Y'=>'DD-MM-YYYY', 'Y-M-D'=>'YYYY-MM-DD'];
$ciscoZones = [
    'GMT Standard/Daylight Time'=>'GMT / United Kingdom', 'W. Europe Standard/Daylight Time'=>'Western Europe',
    'E. Europe Standard/Daylight Time'=>'Eastern Europe', 'Egypt Standard/Daylight Time'=>'Egypt',
    'South Africa Standard Time'=>'South Africa', 'Saudi Arabia Standard Time'=>'Arabia (UTC+03:00)',
    'Iran Standard/Daylight Time'=>'Iran', 'India Standard Time'=>'India', 'China Standard/Daylight Time'=>'China',
    'Tokyo Standard Time'=>'Japan', 'AUS Eastern Standard/Daylight Time'=>'Australia Eastern',
    'US Eastern Standard/Daylight Time'=>'US Eastern', 'Central Standard/Daylight Time'=>'US Central',
    'Mountain Standard/Daylight Time'=>'US Mountain', 'Pacific Standard/Daylight Time'=>'US Pacific',
];
$legacyZones = ['GMT'=>'GMT / UTC', 'CET'=>'Central European', 'EET'=>'Eastern European', 'EST'=>'US Eastern', 'CST'=>'US Central', 'MST'=>'US Mountain', 'PST'=>'US Pacific', 'IT'=>'Iran Time'];
$offsets = [];
foreach (['-12','-11','-10','-9:30','-9','-8','-7','-6','-5','-4','-3:30','-3','-2','-1','+0','+1','+2','+3','+3:30','+4','+4:30','+5','+5:30','+5:45','+6','+6:30','+7','+8','+9','+9:30','+10','+11','+12','+13','+14'] as $offset) { $offsets[$offset] = 'UTC' . ($offset === '+0' ? '±00:00' : $offset); }
$firmwareProfiles = []; $backgroundProfiles = []; $hostedProfiles = []; $timezoneProfiles = [];
foreach ($models as $model) {
    $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::firmwareProfile((string)$model['vendor'], (string)$model['model']);
    if ($profile !== null) {
        $firmwareProfiles[$profile] = $firmwareProfiles[$profile] ?? ['id'=>$profile,'vendor'=>(string)$model['vendor'],'model_id'=>(int)$model['id'],'models'=>[]];
        $firmwareProfiles[$profile]['models'][] = (string)$model['vendor'].' '.(string)$model['model'];
    }
    $bgKey = \FreePBX\modules\Ucmprovisioner\ModelCatalog::backgroundProfileKey((string)$model['vendor'], (string)$model['model']);
    $bgDir = \FreePBX\modules\Ucmprovisioner\ModelCatalog::backgroundDir((string)$model['vendor'], (string)$model['model']);
    $bgSize = \FreePBX\modules\Ucmprovisioner\ModelCatalog::backgroundProfile((string)$model['vendor'], (string)$model['model']);
    if ($bgKey !== null && $bgDir !== null && $bgSize !== null) {
        $backgroundProfiles[$bgKey] = $backgroundProfiles[$bgKey] ?? ['id'=>$bgKey,'vendor'=>(string)$model['vendor'],'dir'=>$bgDir,'size'=>$bgSize,'models'=>[]];
        $backgroundProfiles[$bgKey]['models'][] = (string)$model['model'];
    }
    $hostedKey = \FreePBX\modules\Ucmprovisioner\ModelCatalog::hostedMediaProfileKey((string)$model['vendor'], (string)$model['model']);
    if ($hostedKey !== null) {
        $hostedProfiles[$hostedKey] = $hostedProfiles[$hostedKey] ?? ['id'=>$hostedKey,'vendor'=>(string)$model['vendor'],'family'=>(string)$model['family'],'models'=>[],'libraries'=>[]];
        $hostedProfiles[$hostedKey]['models'][] = (string)$model['vendor'].' '.(string)$model['model'];
        $hostedProfiles[$hostedKey]['libraries'][] = strtolower((string)$model['model']);
    }
}
foreach (\FreePBX\modules\Ucmprovisioner\ModelCatalog::CISCO_TIMEZONE_PROFILES as $profileId=>$profile) {
    $timezoneProfiles[$profileId] = [
        'id'=>$profileId,
        'vendor'=>'Cisco',
        'label'=>(string)$profile['label'],
        'architecture'=>(string)($profile['architecture'] ?? ''),
        'models'=>array_values((array)($profile['models'] ?? [])),
        'firmware_profiles'=>array_values((array)($profile['firmware_profiles'] ?? [])),
        'payload'=>(string)$profile['payload'],
    ];
}
$seriesProfiles = \FreePBX\modules\Ucmprovisioner\ModelCatalog::templateScopeOptions(array_values(array_filter(
    $models,
    static fn(array $model): bool => !in_array(
        (string)($model['role'] ?? 'desk'),
        ['dect_handset','dect_radio','dect_manager','unsupported'],
        true
    )
)));
ksort($firmwareProfiles); ksort($backgroundProfiles); ksort($hostedProfiles); ksort($timezoneProfiles);
$meshSchedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
    $v('blf_mesh_schedule_json', ''),
    max(15, (int)$v('blf_mesh_interval_minutes', '60')),
    15
);
$meshNextRun = (int)$v('blf_mesh_next_run', '0');
$phoneRebuildSchedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
    $v('phone_rebuild_schedule_json', ''),
    max(15, (int)$v('phone_rebuild_interval_minutes', '1440')),
    15
);
$phoneRebuildNextRun = (int)$v('phone_rebuild_next_run', '0');
$settingsSyncScopes = [
    'settings_sip_service' => _('Endpoint and service defaults'),
    'settings_blf_federation' => _('BLF federation schedule'),
    'settings_phone_automation' => _('Scheduled phone rebuild and reboot'),
    'settings_clock_date' => _('Clock and date'),
    'settings_network_vlan' => _('Default network VLAN intent'),
    'settings_default_template_selectors' => _('Default template selectors'),
    'settings_assets' => _('Firmware, timezone, wallpaper and phonebook defaults'),
    'settings_cisco_policy' => _('Cisco XML device policy and generated companions'),
];
$settingsSyncJobs = [];
$settingsSyncError = '';
$configurationArchiveScopes = \FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService::scopeLabels();
$configurationArchiveResult = is_array($_REQUEST['configuration_archive_result'] ?? null)
    ? (array)$_REQUEST['configuration_archive_result']
    : [];
try {
    foreach ((array)($ucm->configurationSyncOp(['op'=>'list'])['jobs'] ?? []) as $job) {
        if ((int)($job['source_site_id'] ?? 0) === $siteId
            && array_intersect(array_keys($settingsSyncScopes), (array)($job['scopes'] ?? []))) {
            $settingsSyncJobs[] = $job;
        }
    }
} catch (\Throwable $error) {
    $settingsSyncError = $error->getMessage();
}
?>
<div class="ucm-container">
  <div class="ucm-page-intro"><div><h2><?php echo _('Site provisioning defaults'); ?></h2><p><?php echo _('These values belong to the selected site. A template field left unset inherits the corresponding value from this page; another site has its own independent defaults.'); ?></p></div></div>
  <form method="get" class="form-inline ucm-filter"><input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="settings"><input type="hidden" name="view" value="form"><label><?php echo _('Site'); ?> <select class="form-control" name="site_id" onchange="this.form.submit()"><?php foreach ($sites as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $siteId === (int)$s['id'] ? 'selected' : ''; ?>><?php echo $h($s['name']); ?><?php echo !empty($s['is_local']) ? ' (' . _('local') . ')' : ''; ?></option><?php endforeach; ?></select></label></form>
  <?php if ($loadError): ?><div class="alert alert-danger"><?php echo $h($loadError); ?></div><?php else: ?>
  <form class="fpbx-submit" name="frm_ucmprovisioner" action="config.php?display=ucmprovisioner&amp;tab=settings&amp;view=form&amp;site_id=<?php echo $siteId; ?>" method="post" novalidate>
    <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="settings"><input type="hidden" name="view" value="form"><input type="hidden" name="action" value="savesettings"><input type="hidden" name="site_id" value="<?php echo $siteId; ?>"><input type="hidden" name="sync_edit_reason" value="Edited in Site Settings">
    <?php $allDefaultSync=array_merge($siteDefaultSyncStates,$defaultTemplateSyncStates);$lockedSync=array_values(array_filter($allDefaultSync,static fn($state):bool=>!empty($state['soft_lock'])&&in_array((string)($state['status']??''),['active','missing_dependency'],true)));if($allDefaultSync): ?>
      <div class="alert alert-warning ucm-sync-notice"><strong><?php echo _('Configuration sync is tracking site defaults on this PBX.'); ?></strong>
        <div><?php if($siteDefaultSyncStates): ?><?php echo sprintf(_('%d firmware or screen assignment(s) tracked.'),count($siteDefaultSyncStates)).' '; ?><?php endif; ?><?php if($defaultTemplateSyncStates): ?><?php echo sprintf(_('%d default-template target(s) tracked.'),count($defaultTemplateSyncStates)); ?><?php endif; ?></div>
        <?php if($lockedSync): ?><label class="checkbox-inline"><input type="checkbox" name="sync_edit_confirmed" value="1"> <?php echo _('If I change a synchronized row, pause only that assignment or default-template target before saving.'); ?></label><?php else: ?><div><?php echo _('A local change pauses only the matching assignment or rule; unrelated site defaults continue syncing.'); ?></div><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php $granularInbound=array_filter($settingsInboundSyncStates,static fn($states):bool=>!empty($states));$granularLocked=array_filter($granularInbound,static fn($states):bool=>(bool)array_filter($states,static fn($state):bool=>!empty($state['soft_lock'])&&in_array((string)($state['status']??''),['active','missing_dependency'],true)));if($granularInbound): ?>
      <div class="alert alert-warning ucm-sync-notice"><strong><?php echo _('Inbound Settings synchronization is active on this PBX.'); ?></strong>
        <div><?php foreach($granularInbound as $scope=>$states): ?><span class="label <?php echo isset($granularLocked[$scope])?'label-warning':'label-info'; ?>" style="margin-right:6px" title="<?php echo $h(implode('; ',array_filter(array_map(static fn($state):string=>(string)($state['pause_reason']??''),$states)))); ?>"><?php echo $h($settingsSyncScopes[$scope] ?? $scope); ?> · <?php echo count($states); ?></span><?php endforeach; ?></div>
        <?php if($granularLocked): ?><label class="checkbox-inline"><input type="checkbox" name="sync_edit_confirmed" value="1"> <?php echo _('I understand that changing a highlighted subsection pauses its inbound sync on this PBX.'); ?></label><?php else: ?><div><?php echo _('A local change pauses only the affected subsection; unrelated Settings synchronization continues.'); ?></div><?php endif; ?>
      </div>
    <?php endif; ?>
    <section class="ucm-card" id="ucm-settings-section-sip">
      <div class="ucm-card-heading"><div><h3><?php echo _('Endpoint and service defaults'); ?></h3><p><?php echo _('Cisco UCM phones and non-Cisco phones have independent site defaults. A phone override may replace transport or port; templates cannot split the generated config from its FreePBX endpoint.'); ?></p></div></div>
      <div class="ucm-inline-panel">
        <h4><?php echo _('Cisco XML phones (USECALLMANAGER)'); ?></h4>
        <div class="row">
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Driver'); ?></label><input class="form-control" value="CHAN_SIP" readonly><p class="help-block"><?php echo _('Required for the patched Cisco USECALLMANAGER channel behavior.'); ?></p></div></div>
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Default transport'); ?></label><?php $select('cisco_transport', $v('cisco_transport',$v('transport','udp')), $transports); ?></div></div>
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Default phone SIP port'); ?></label><input class="form-control" type="number" min="1" max="65535" name="settings[cisco_sip_port]" value="<?php echo $h($v('cisco_sip_port',$v('sip_port','5060'))); ?>"><p class="help-block"><?php echo _('Must match the chan_sip bind port in FreePBX SIP Settings.'); ?></p></div></div>
        </div>
      </div>
      <div class="ucm-inline-panel ucm-top-gap">
        <h4><?php echo _('Cisco 7940/7960 CNF phones'); ?></h4>
        <div class="row">
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Driver'); ?></label><input class="form-control" value="CHAN_SIP" readonly><p class="help-block"><?php echo _('Fixed for the legacy SIP firmware and Asterisk integration.'); ?></p></div></div>
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Default transport'); ?></label><select class="form-control" name="settings[cisco_cnf_transport]"><option value="udp" selected>UDP</option></select><p class="help-block"><?php echo _('Cisco documents SIP-over-TCP as unsupported on 7940/7960 firmware; this separate default prevents an XML-phone TCP policy from breaking CNF registration.'); ?></p></div></div>
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Default phone SIP port'); ?></label><input class="form-control" type="number" min="1" max="65535" name="settings[cisco_cnf_sip_port]" value="<?php echo $h($v('cisco_cnf_sip_port',$v('cisco_sip_port',$v('sip_port','5060')))); ?>"><p class="help-block"><?php echo _('Must match the chan_sip UDP bind port.'); ?></p></div></div>
        </div>
      </div>
      <div class="ucm-inline-panel ucm-top-gap">
        <h4><?php echo _('Yealink and Grandstream phones'); ?></h4>
        <div class="row">
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Default driver'); ?></label><?php $select('non_cisco_driver', $v('non_cisco_driver','pjsip'), $endpointDrivers); ?><p class="help-block"><?php echo _('The selected FreePBX extension must already use this driver. The module does not silently convert extensions.'); ?></p></div></div>
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Default transport'); ?></label><?php $select('non_cisco_transport', $v('non_cisco_transport','udp'), $transports); ?></div></div>
          <div class="col-md-4"><div class="form-group"><label><?php echo _('Default phone SIP port'); ?></label><input class="form-control" type="number" min="1" max="65535" name="settings[non_cisco_sip_port]" value="<?php echo $h($v('non_cisco_sip_port','5160')); ?>"><p class="help-block"><?php echo _('Must match the selected driver/transport bind in FreePBX SIP Settings; a standard FreePBX PJSIP endpoint commonly uses 5160.'); ?></p></div></div>
        </div>
      </div>
      <div class="row ucm-top-gap">
        <div class="col-md-6"><div class="form-group"><label><?php echo _('Cisco XML proxy keyword'); ?></label><select class="form-control" name="settings[proxy]"><option value="USECALLMANAGER" <?php echo $v('proxy','USECALLMANAGER')==='USECALLMANAGER'?'selected':''; ?>>USECALLMANAGER</option><option value="" <?php echo $v('proxy','USECALLMANAGER')===''?'selected':''; ?>><?php echo _('PBX address'); ?></option></select></div></div>
        <div class="col-md-6"><div class="form-group"><label><?php echo _('Voicemail number'); ?></label><input class="form-control" name="settings[voicemail_number]" value="<?php echo $h($v('voicemail_number','*97')); ?>"></div></div>
      </div>
    </section>
    <section class="ucm-card ucm-settings-replication" id="ucm-settings-replication">
      <?php require __DIR__ . '/settings_sync_fields.php'; ?>
      <div class="ucm-card-heading"><div><span class="ucm-kicker"><?php echo _('Federated policy'); ?></span><h3><?php echo _('Replicate selected Settings'); ?></h3><p><?php echo _('Choose visible Settings subsections and destination sites. Selections remain staged while you move around this page, but a push always reads the source site’s last saved values—save this page first when you changed a field. Conflicts, missing dependencies and soft-lock pauses are reported per destination.'); ?></p></div></div>
      <?php if ($settingsSyncError): ?><div class="alert alert-warning"><?php echo $h(_('Settings synchronization state could not be loaded: ') . $settingsSyncError); ?></div><?php endif; ?>
      <div class="row"><div class="col-md-4"><label for="ucm-settings-sync-job"><?php echo _('Saved job'); ?></label><select class="form-control" id="ucm-settings-sync-job"><option value="0"><?php echo _('New Settings sync'); ?></option><?php foreach($settingsSyncJobs as $job): ?><option value="<?php echo (int)$job['id']; ?>"><?php echo $h($job['name']); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label for="ucm-settings-sync-name"><?php echo _('Job name'); ?></label><input class="form-control" id="ucm-settings-sync-name" maxlength="80" value="<?php echo $h(_('Settings push from ') . ($sites[array_search($siteId, array_column($sites, 'id'))]['name'] ?? ('Site #' . $siteId))); ?>"></div><div class="col-md-4"><label><?php echo _('Selected subsections'); ?></label><div class="form-control-static" id="ucm-settings-sync-selection"><?php echo _('None selected'); ?></div></div></div>
      <div class="row ucm-top-gap"><div class="col-md-6"><fieldset class="ucm-choice-grid ucm-picker" data-ucm-picker><legend><?php echo _('Settings subsections to push'); ?></legend><div class="ucm-picker-toolbar"><div class="ucm-picker-actions"><button type="button" class="btn btn-xs btn-default ucm-picker-all"><?php echo _('Select all'); ?></button><button type="button" class="btn btn-xs btn-default ucm-picker-clear"><?php echo _('Clear'); ?></button></div><span class="ucm-picker-count" aria-live="polite"></span></div><div class="ucm-picker-list"><?php foreach($settingsSyncScopes as $scope=>$label): ?><label class="ucm-choice-card ucm-picker-item"><input type="checkbox" class="ucm-settings-sync-scope" data-settings-replication-scope="<?php echo $h($scope); ?>" value="<?php echo $h($scope); ?>"> <span><strong><?php echo $h($label); ?></strong><small><?php echo _('Only this visible subsection is included.'); ?></small></span></label><?php endforeach; ?></div></fieldset><div class="ucm-top-gap"><a class="btn btn-xs btn-default" href="#ucm-settings-section-sip" data-settings-jump="settings_sip_service"><?php echo _('Jump to endpoint / service'); ?></a><?php if ($siteId === $ucm->localSiteId()): ?> <a class="btn btn-xs btn-default" href="#ucm-settings-section-blf" data-settings-jump="settings_blf_federation"><?php echo _('Jump to BLF schedule'); ?></a><?php endif; ?> <a class="btn btn-xs btn-default" href="#ucm-settings-section-phone-maintenance" data-settings-jump="settings_phone_automation"><?php echo _('Jump to phone automation'); ?></a> <a class="btn btn-xs btn-default" href="#ucm-settings-section-clock" data-settings-jump="settings_clock_date"><?php echo _('Jump to Clock / date'); ?></a></div></div><div class="col-md-6"><fieldset class="ucm-choice-grid ucm-picker" data-ucm-picker><legend><?php echo _('Destination and protection'); ?></legend><div class="ucm-picker-toolbar"><label class="ucm-picker-search"><span class="sr-only"><?php echo _('Search destination PBXs'); ?></span><input type="search" class="form-control input-sm ucm-picker-filter" placeholder="<?php echo _('Search destinations'); ?>" autocomplete="off"></label><div class="ucm-picker-actions"><button type="button" class="btn btn-xs btn-default ucm-picker-visible"><?php echo _('Select visible'); ?></button><button type="button" class="btn btn-xs btn-default ucm-picker-all"><?php echo _('Select all'); ?></button><button type="button" class="btn btn-xs btn-default ucm-picker-clear"><?php echo _('Clear'); ?></button></div><span class="ucm-picker-count" aria-live="polite"></span></div><div class="ucm-picker-list"><?php foreach($sites as $site): if((int)$site['id']===$siteId||empty($site['enabled']))continue; ?><label class="ucm-choice-card ucm-picker-item"><input type="checkbox" class="ucm-settings-sync-target" value="<?php echo (int)$site['id']; ?>"> <span><strong><?php echo $h($site['name']); ?></strong><small><?php echo !empty($site['is_local'])?_('local controller'):_('federated destination'); ?></small></span></label><?php endforeach; ?></div><div class="ucm-picker-protection"><label class="ucm-choice-card"><input type="checkbox" id="ucm-settings-sync-enabled" checked> <span><strong><?php echo _('Automatic synchronization'); ?></strong><small><?php echo _('Off means a saved one-time/manual push job. Revision-aware synchronization preserves destination edits until an explicit conflict confirmation.'); ?></small></span></label><label class="ucm-choice-card"><input type="checkbox" id="ucm-settings-sync-lock" checked> <span><strong><?php echo _('Soft-lock destination settings'); ?></strong><small><?php echo _('A destination edit pauses only that setting subsection and requires confirmation.'); ?></small></span></label><label class="ucm-choice-card"><input type="checkbox" id="ucm-settings-sync-retry" checked> <span><strong><?php echo _('Retry unreachable sites'); ?></strong><small><?php echo _('Retry after five minutes without clearing the last known state.'); ?></small></span></label></div></fieldset></div></div>
      <div class="ucm-inline-panel ucm-top-gap"><h4><?php echo _('Automatic cadence'); ?></h4><div class="row"><div class="col-md-3"><label for="ucm-settings-sync-mode"><?php echo _('Schedule'); ?></label><select class="form-control" id="ucm-settings-sync-mode"><option value="manual"><?php echo _('Manual only'); ?></option><option value="interval"><?php echo _('Every interval'); ?></option><option value="daily"><?php echo _('Daily'); ?></option><option value="weekly"><?php echo _('Selected weekdays'); ?></option></select></div><div class="col-md-3 ucm-settings-sync-interval"><label for="ucm-settings-sync-minutes"><?php echo _('Interval minutes'); ?></label><input class="form-control" id="ucm-settings-sync-minutes" type="number" min="5" max="10080" value="60"></div><div class="col-md-3 ucm-settings-sync-clock" hidden><label for="ucm-settings-sync-time"><?php echo _('PBX local time'); ?></label><input class="form-control" id="ucm-settings-sync-time" type="time" value="02:00"></div></div><div class="ucm-settings-sync-weekdays" hidden><label><?php echo _('Weekdays'); ?></label><div><?php foreach(['1'=>_('Mon'),'2'=>_('Tue'),'3'=>_('Wed'),'4'=>_('Thu'),'5'=>_('Fri'),'6'=>_('Sat'),'7'=>_('Sun')] as $day=>$label): ?><label class="checkbox-inline"><input type="checkbox" class="ucm-settings-sync-weekday" value="<?php echo $day; ?>" <?php echo (int)$day<=5?'checked':''; ?>> <?php echo $h($label); ?></label><?php endforeach; ?></div></div></div>
      <div class="ucm-action-row"><button type="button" class="btn btn-primary" id="ucm-settings-sync-save"><?php echo _('Save sync settings'); ?></button><button type="button" class="btn btn-info" id="ucm-settings-sync-run"><?php echo _('Push now'); ?></button><button type="button" class="btn btn-danger" id="ucm-settings-sync-delete" disabled><?php echo _('Delete job'); ?></button><span id="ucm-settings-sync-feedback" class="ucm-inline-action-feedback" aria-live="polite"></span></div><div id="ucm-settings-sync-items"></div>
    </section>
    <?php if ($siteId === $ucm->localSiteId()): ?>
    <section class="ucm-card" id="ucm-settings-section-blf"><div class="ucm-card-heading"><div><h3><?php echo _('BLF federation schedule'); ?></h3><p><?php echo _('Cross-site BLF mesh repair is independent from phone configuration rebuilds. A one-minute tick evaluates the saved schedule and defers while FreePBX is writing Asterisk configuration.'); ?></p></div></div><div class="row">
      <div class="col-md-3"><label><?php echo _('Scheduled mesh sync'); ?></label><?php $select('blf_mesh_schedule_enabled', $v('blf_mesh_schedule_enabled','0'), ['0'=>_('Disabled'),'1'=>_('Enabled')]); ?></div>
      <div class="col-md-9"><label><?php echo _('Last scheduled result'); ?></label><p class="form-control-static"><?php echo $h($v('blf_mesh_last_status', _('Not run yet'))); ?><?php if($meshNextRun>0): ?> <span class="text-muted">· <?php echo sprintf(_('Next %s'),$h(date('Y-m-d H:i',$meshNextRun))); ?></span><?php endif; ?></p><p class="help-block"><?php echo _('Use Sync BLF Mesh on the Phones or Dashboard tab for an immediate preview/apply. Ordinary phone rebuilds never contact other sites.'); ?></p></div>
    </div><input type="hidden" name="settings[blf_mesh_schedule_json]" id="ucm-settings-mesh-schedule-json" value="<?php echo $h(\FreePBX\modules\Ucmprovisioner\RecurringSchedule::encode($meshSchedule)); ?>"><input type="hidden" name="settings[blf_mesh_interval_minutes]" id="ucm-settings-mesh-interval-compat" value="<?php echo (int)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::compatibilityMinutes($meshSchedule); ?>">
      <div class="ucmp-schedule-editor" id="ucm-settings-mesh-schedule" data-schedule-editor>
        <div class="ucmp-schedule-heading"><strong><?php echo _('Mesh timing'); ?></strong><span><?php echo sprintf(_('PBX timezone: %s; minimum interval: 15 minutes'),$h(date_default_timezone_get())); ?></span></div>
        <label><?php echo _('Cadence'); ?><select class="form-control" data-schedule-mode><option value="manual" <?php echo $meshSchedule['mode']==='manual'?'selected':''; ?>><?php echo _('Manual only'); ?></option><option value="interval" <?php echo $meshSchedule['mode']==='interval'?'selected':''; ?>><?php echo _('Minute interval'); ?></option><option value="daily" <?php echo $meshSchedule['mode']==='daily'?'selected':''; ?>><?php echo _('Daily at a time'); ?></option><option value="weekly" <?php echo $meshSchedule['mode']==='weekly'?'selected':''; ?>><?php echo _('Selected weekdays'); ?></option></select></label>
        <label data-schedule-field="interval"><?php echo _('Every (minutes)'); ?><input class="form-control" type="number" min="15" max="10080" step="1" data-schedule-interval value="<?php echo (int)$meshSchedule['interval_minutes']; ?>"></label>
        <label data-schedule-field="clock"><?php echo _('PBX local time'); ?><input class="form-control" type="time" step="60" data-schedule-time value="<?php echo $h($meshSchedule['time']); ?>"></label>
        <fieldset data-schedule-field="weekly"><legend><?php echo _('Run on'); ?></legend><div class="ucmp-weekdays"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" data-schedule-weekday value="<?php echo $day; ?>" <?php echo in_array($day,$meshSchedule['weekdays'],true)?'checked':''; ?>> <?php echo _($label); ?></label><?php endforeach; ?></div></fieldset>
      </div>
    </section>
    <?php endif; ?>
    <section class="ucm-card" id="ucm-settings-section-phone-maintenance"><div class="ucm-card-heading"><div><h3><?php echo _('Scheduled phone configuration rebuild'); ?></h3><p><?php echo _('This is the same site-owned schedule shown on the Phones tab. It previews and applies one verified Rebuild All generation; an optional warm reboot is sent only after a successful commit.'); ?></p></div></div><div class="row">
      <div class="col-md-3"><label><?php echo _('Scheduled rebuild'); ?></label><?php $select('phone_rebuild_schedule_enabled', $v('phone_rebuild_schedule_enabled','0'), ['0'=>_('Disabled'),'1'=>_('Enabled')]); ?></div>
      <div class="col-md-3"><label><?php echo _('After successful rebuild'); ?></label><?php $select('phone_rebuild_reboot', $v('phone_rebuild_reboot','0'), ['0'=>_('Do not reboot phones'),'1'=>_('Warm reboot supported phones')]); ?></div>
      <div class="col-md-6"><label><?php echo _('Last scheduled result'); ?></label><p class="form-control-static"><?php echo $h($v('phone_rebuild_last_status', _('Not run yet'))); ?><?php if($phoneRebuildNextRun>0): ?> <span class="text-muted">· <?php echo sprintf(_('Next %s'),$h(date('Y-m-d H:i',$phoneRebuildNextRun))); ?></span><?php endif; ?></p><p class="help-block"><?php echo _('Manual overrides and Rebuild All exclusions remain respected. Grandstream has no supported SIP reboot command and is rebuilt without a remote reboot.'); ?></p></div>
    </div><input type="hidden" name="settings[phone_rebuild_schedule_json]" id="ucm-settings-phone-rebuild-json" value="<?php echo $h(\FreePBX\modules\Ucmprovisioner\RecurringSchedule::encode($phoneRebuildSchedule)); ?>"><input type="hidden" name="settings[phone_rebuild_interval_minutes]" id="ucm-settings-phone-rebuild-interval" value="<?php echo (int)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::compatibilityMinutes($phoneRebuildSchedule); ?>">
      <div class="ucmp-schedule-editor" id="ucm-settings-phone-rebuild-schedule" data-schedule-editor>
        <div class="ucmp-schedule-heading"><strong><?php echo _('Rebuild timing'); ?></strong><span><?php echo sprintf(_('Owning PBX local time; minimum interval: 15 minutes')); ?></span></div>
        <label><?php echo _('Cadence'); ?><select class="form-control" data-schedule-mode><option value="manual" <?php echo $phoneRebuildSchedule['mode']==='manual'?'selected':''; ?>><?php echo _('Manual only'); ?></option><option value="interval" <?php echo $phoneRebuildSchedule['mode']==='interval'?'selected':''; ?>><?php echo _('Minute interval'); ?></option><option value="daily" <?php echo $phoneRebuildSchedule['mode']==='daily'?'selected':''; ?>><?php echo _('Daily at a time'); ?></option><option value="weekly" <?php echo $phoneRebuildSchedule['mode']==='weekly'?'selected':''; ?>><?php echo _('Selected weekdays'); ?></option></select></label>
        <label data-schedule-field="interval"><?php echo _('Every (minutes)'); ?><input class="form-control" type="number" min="15" max="10080" step="1" data-schedule-interval value="<?php echo (int)$phoneRebuildSchedule['interval_minutes']; ?>"></label>
        <label data-schedule-field="clock"><?php echo _('PBX local time'); ?><input class="form-control" type="time" step="60" data-schedule-time value="<?php echo $h($phoneRebuildSchedule['time']); ?>"></label>
        <fieldset data-schedule-field="weekly"><legend><?php echo _('Run on'); ?></legend><div class="ucmp-weekdays"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" data-schedule-weekday value="<?php echo $day; ?>" <?php echo in_array($day,$phoneRebuildSchedule['weekdays'],true)?'checked':''; ?>> <?php echo _($label); ?></label><?php endforeach; ?></div></fieldset>
      </div>
    </section>
    <section class="ucm-card" id="ucm-settings-section-clock"><div class="ucm-card-heading"><div><h3><?php echo _('Clock and date'); ?></h3><p><?php echo _('The NTP server supplies UTC. Each phone family applies its own selected time-zone representation.'); ?></p></div></div><div class="row">
      <div class="col-md-4"><div class="form-group"><label><?php echo _('NTP server used by phones'); ?></label><input class="form-control" name="settings[ntp_server]" value="<?php echo $h($v('ntp_server')); ?>" placeholder="pbx.example.net"><p class="help-block"><?php echo _('Use this site PBX or another reachable NTP server.'); ?></p></div></div>
      <div class="col-md-4"><div class="form-group"><label><?php echo _('Cisco XML time zone'); ?></label><?php $select('cisco_xml_timezone', $v('cisco_xml_timezone','GMT Standard/Daylight Time'), $ciscoZones); ?></div></div>
      <div class="col-md-4"><div class="form-group"><label><?php echo _('Cisco XML Olson time zone'); ?></label><input class="form-control" name="settings[cisco_xml_olson_timezone]" value="<?php echo $h($v('cisco_xml_olson_timezone')); ?>" placeholder="Asia/Tehran"><p class="help-block"><?php echo _('IANA zone used with a matching Cisco timezone-data payload. The name alone does not update a phone’s built-in DST rules.'); ?></p></div></div>
      <div class="col-md-8"><div class="alert alert-info"><strong><?php echo _('Cisco timezone payloads are runtime-owned.'); ?></strong> <?php echo _('Firmware Manager retains independent revisions for Standard JVM, IBM J9, and Lodown / RTL / Gumbo phones. Assign this site’s default revision for each runtime in the matrix below; a template may inherit it or choose another compatible revision.'); ?> <a href="config.php?display=ucmprovisioner&amp;tab=firmware&amp;site_id=<?php echo $siteId; ?>#ucm-cisco-timezone-manager"><?php echo _('Manage retained timezone revisions'); ?></a></div></div>
      <div class="col-md-4"><div class="form-group"><label><?php echo _('Cisco XML date format'); ?></label><?php $select('date_template', $v('date_template','D/M/Y'), $dateFormats); ?></div></div>
      <div class="col-md-4"><div class="form-group"><label><?php echo _('Cisco 7940/7960 time zone'); ?></label><?php $select('cisco_legacy_timezone', $v('cisco_legacy_timezone','GMT'), $legacyZones); ?></div></div>
      <div class="col-md-4"><div class="form-group"><label><?php echo _('Cisco 7940/7960 daylight saving'); ?></label><?php $select('cisco_legacy_dst_auto_adjust', $v('cisco_legacy_dst_auto_adjust','1'), ['1'=>_('Automatic adjustment'), '0'=>_('Disabled')]); ?><p class="help-block"><?php echo _('Writes the documented dst_auto_adjust setting to SIPDefault.cnf.'); ?></p></div></div>
      <div class="col-md-4"><div class="form-group"><label><?php echo _('Yealink UTC offset'); ?></label><?php $select('yealink_timezone', $v('yealink_timezone','+0'), $offsets); ?></div></div>
    </div></section>
    <section class="ucm-card"><div class="ucm-card-heading"><div><h3><?php echo _('Default network VLAN intent'); ?></h3><p><?php echo _('Inherited by templates and phones. Existing installations remain on Inherit, which emits no new VLAN controls. Use a canary before changing a live voice network.'); ?></p></div></div>
      <div class="row">
        <div class="col-md-3"><label><?php echo _('Mode'); ?></label><?php $select('vlan_mode',$v('vlan_mode','inherit'),['inherit'=>_('Inherit / emit nothing'),'discovery'=>_('Network discovery'),'manual'=>_('Manual voice VLAN'),'off'=>_('Disable managed VLAN')]); ?></div>
        <div class="col-md-3"><label><?php echo _('Discovery order/set'); ?></label><input class="form-control" name="settings[vlan_discovery]" value="<?php echo $h($v('vlan_discovery','lldp,cdp,dhcp')); ?>" placeholder="lldp,cdp,dhcp"><p class="help-block"><?php echo _('Supported values: lldp, cdp, dhcp. A vendor emits only controls verified for that profile.'); ?></p></div>
        <div class="col-md-2"><label><?php echo _('Voice VID'); ?></label><input class="form-control" type="number" min="1" max="4094" name="settings[vlan_voice_vid]" value="<?php echo $h($v('vlan_voice_vid')); ?>"></div>
        <div class="col-md-2"><label><?php echo _('Voice PCP'); ?></label><input class="form-control" type="number" min="0" max="7" name="settings[vlan_voice_pcp]" value="<?php echo $h($v('vlan_voice_pcp','5')); ?>"></div>
      </div>
      <div class="row ucm-top-gap">
        <div class="col-md-3"><label><?php echo _('PC port'); ?></label><?php $select('vlan_pc_port',$v('vlan_pc_port','inherit'),['inherit'=>_('Inherit'),'untagged'=>_('Untagged'),'tagged'=>_('Tagged VLAN'),'disabled'=>_('Disabled where verified')]); ?></div>
        <div class="col-md-2"><label><?php echo _('PC VID'); ?></label><input class="form-control" type="number" min="1" max="4094" name="settings[vlan_pc_vid]" value="<?php echo $h($v('vlan_pc_vid')); ?>"></div>
        <div class="col-md-2"><label><?php echo _('PC PCP'); ?></label><input class="form-control" type="number" min="0" max="7" name="settings[vlan_pc_pcp]" value="<?php echo $h($v('vlan_pc_pcp','0')); ?>"></div>
        <div class="col-md-5"><div class="alert alert-warning"><?php echo _('Cisco XML has verified CDP/LLDP and selected PC-port policy but no safe static voice VID; Cisco CNF remains switch-owned. Yealink uses desk/legacy/DECT profiles. Grandstream GXP/GRP, GHP63x, DP750/752 and alias-v2 DP755 are rendered with separate verified grammars. Unsupported combinations fail before build.'); ?></div></div>
      </div>
    </section>
    <section class="ucm-card" id="ucm-default-template-rules"><div class="ucm-card-heading"><div><h3><?php echo _('Default template selectors'); ?></h3><p><?php echo _('Choose the first template layer for each family, broad policy group, firmware/config compatibility group, or exact model. Group inheritance is merged afterward and therefore has precedence. Exact model wins over compatibility, compatibility over broad group, and broad group over family. Equal-priority overlaps are rejected on save.'); ?></p></div><button type="button" class="btn btn-default" id="ucm-add-default-template"><?php echo _('Add selector'); ?></button></div>
      <input type="hidden" name="default_template_rules" id="ucm-default-template-json" value="<?php echo $h(\FreePBX\modules\Ucmprovisioner::browserJson(array_map(static fn(array $rule): array => [
          'template_id'=>(int)$rule['template_id'],
          'target_vendor'=>$rule['target_vendor'] ?? '',
          'target_family'=>$rule['target_family'] ?? '',
          'target_series'=>$rule['target_series'] ?? '',
          'target_model'=>$rule['target_model'] ?? '',
          'target_role'=>$rule['target_role'] ?? '',
          'enabled'=>(int)($rule['enabled'] ?? 1),
      ], $defaultTemplateRules), JSON_UNESCAPED_SLASHES)); ?>">
      <div class="table-responsive"><table class="table table-striped"><thead><tr><th><?php echo _('Scope'); ?></th><th><?php echo _('Target'); ?></th><th><?php echo _('Role'); ?></th><th><?php echo _('Template'); ?></th><th><?php echo _('Enabled'); ?></th><th></th></tr></thead><tbody id="ucm-default-template-body"></tbody></table></div>
      <div class="alert alert-info"><?php echo _('Example: Cisco XML family → Base XML, Cisco 79xx policy group → shared legacy policy, SIP41 compatibility → Base 7941/7961, or SIP75 compatibility → Base 7975. A DeptA group template then merges on top of whichever default applies.'); ?></div>
    </section>
    <section class="ucm-card" id="ucm-site-assets"><div class="ucm-card-heading"><div><h3><?php echo _('Firmware, timezone, wallpaper and phonebook defaults'); ?></h3><p><?php echo _('These are real site-wide inheritance values. A template may override one compatibility profile without being restricted to one phone model.'); ?></p></div></div>
      <div class="row">
        <div class="col-md-6"><div class="form-group"><label><?php echo _('Phone-facing media base URL'); ?></label><input class="form-control" type="url" name="settings[media_base_url]" value="<?php echo $h($v('media_base_url')); ?>" placeholder="http://pbx.example.com"><p class="help-block"><?php echo _('Scheme, host, optional port and base path that phones can reach. The module appends a query-free /ucmp-m.php/family/model/file route. Leave blank to use this site PBX address over HTTP.'); ?></p></div></div>
        <div class="col-md-6"><div class="form-group"><label><?php echo _('Managed phone-services base URL'); ?></label><input class="form-control" type="url" name="settings[phone_services_base_url]" value="<?php echo $h($v('phone_services_base_url')); ?>" placeholder="http://pbx.example.com"><p class="help-block"><?php echo _('Public scheme, host, optional port and base path for this PBX. The generator appends a token, vendor format and the current phone MAC; query strings and embedded credentials are rejected. Leave blank to use the site PBX address over HTTP.'); ?></p></div></div>
      </div>
      <?php $savedPhonebook=$v('default_phonebook_key','');$phonebookRows=(array)($publishedPhonebooks['phonebooks']??[]);$phonebookKeys=array_fill_keys(array_map(static fn(array $book):string=>(string)($book['book_key']??''),$phonebookRows),true);$phonebookSource=(array)($publishedPhonebooks['source']??[]); ?>
      <div class="ucm-inline-panel ucm-top-gap"><div class="row"><div class="col-md-6"><div class="form-group"><label for="ucm-default-phonebook"><?php echo _('Default published phonebook'); ?></label><select class="form-control" name="settings[default_phonebook_key]" id="ucm-default-phonebook"><option value="" <?php echo $savedPhonebook===''?'selected':''; ?>><?php echo _('Use publisher default'); ?></option><?php foreach($phonebookRows as $book): $key=(string)($book['book_key']??''); if($key==='')continue; ?><option value="<?php echo $h($key); ?>" <?php echo hash_equals($savedPhonebook,$key)?'selected':''; ?>><?php echo $h((string)($book['name']??$key)); ?> · <?php echo (int)($book['contacts']??0); ?> <?php echo _('contacts'); ?><?php echo !empty($book['is_default'])?' — '._('current default'):''; ?></option><?php endforeach; ?><?php if($savedPhonebook!==''&&!isset($phonebookKeys[$savedPhonebook])): ?><option value="<?php echo $h($savedPhonebook); ?>" selected disabled><?php echo $h($savedPhonebook); ?> — <?php echo _('not present in this PBX cache'); ?></option><?php endif; ?></select><p class="help-block"><?php echo _('Templates set to Site default use this generated cache. An explicit template phonebook still wins.'); ?></p></div></div><div class="col-md-6"><label><?php echo _('Generated-cache origin'); ?></label><div class="ucm-default-phonebook-origin"><?php if(!empty($publishedPhonebooks['replicated'])): ?><strong><?php echo sprintf(_('Replicated snapshot from %s'),$h((string)($phonebookSource['site_name']??$phonebookSource['site_key']??'another PBX'))); ?></strong><span><?php echo _('Contacts and compiled menus were copied; AD bind credentials were not.'); ?></span><?php elseif(!empty($phonebookSource)): ?><strong><?php echo sprintf(_('Generated locally by %s'),$h((string)($phonebookSource['site_name']??$phonebookSource['site_key']??'this PBX'))); ?></strong><span><?php echo _('This cache is rebuilt from this publisher’s accepted directory data.'); ?></span><?php else: ?><strong><?php echo _('Origin will be recorded on the next directory publication'); ?></strong><span><?php echo $h((string)($publishedPhonebooks['message']??_('No published cache is available yet.'))); ?></span><?php endif; ?></div><?php if(!empty($publishedPhonebooks['replicated'])&&$phonebookRows): ?><button type="button" class="btn btn-default btn-sm ucm-top-gap" id="ucm-copy-published-phonebook"><?php echo _('Copy selected policy to local AD'); ?></button><p class="help-block" id="ucm-copy-published-phonebook-status"><?php echo _('Creates a separate phonebook using this layout/search policy and this destination PBX’s own directory entries. It never copies credentials or contacts.'); ?></p><?php endif; ?></div></div></div>
      <input type="hidden" name="settings[firmware_defaults]" id="ucm-default-firmware-json" value="<?php echo $h($v('firmware_defaults','{}')); ?>">
      <input type="hidden" name="settings[cisco_timezone_defaults]" id="ucm-default-timezone-json" value="<?php echo $h($v('cisco_timezone_defaults','{}')); ?>">
      <input type="hidden" name="settings[cisco_wallpaper_defaults]" id="ucm-default-wallpaper-json" value="<?php echo $h($v('cisco_wallpaper_defaults','{}')); ?>">
      <input type="hidden" name="settings[hosted_image_defaults]" id="ucm-default-hosted-json" value="<?php echo $h($v('hosted_image_defaults','{}')); ?>">
      <div class="ucm-default-asset-workbench" id="ucm-default-asset-workbench">
        <div class="ucm-default-asset-tabs" role="tablist" aria-label="<?php echo _('Site default assignment type'); ?>">
          <button type="button" class="active" data-default-kind="firmware"><?php echo _('Firmware'); ?></button>
          <button type="button" data-default-kind="cisco_timezone"><?php echo _('Cisco timezone'); ?></button>
          <button type="button" data-default-kind="cisco_wallpaper"><?php echo _('Cisco List.xml screens'); ?></button>
          <button type="button" data-default-kind="hosted_wallpaper"><?php echo _('PBX-hosted screens'); ?></button>
        </div>
        <div class="ucm-default-asset-toolbar"><label><?php echo _('Vendor'); ?><select class="form-control" id="ucm-default-asset-vendor"></select></label><div class="ucm-default-asset-inventory"><span class="ucm-default-asset-status" id="ucm-default-asset-status" role="status"><?php echo _('Inventory has not been loaded yet.'); ?></span><button type="button" class="btn btn-default btn-sm" id="ucm-default-asset-reload"><?php echo _('Reload inventory'); ?></button></div></div>
        <div class="table-responsive"><table class="table ucm-default-asset-table"><thead><tr><th><?php echo _('Compatibility profile'); ?></th><th><?php echo _('Models'); ?></th><th><?php echo _('Site default'); ?></th><th><?php echo _('Status'); ?></th></tr></thead><tbody id="ucm-default-asset-body"></tbody></table></div>
        <div class="ucm-default-asset-footer"><div><strong><?php echo _('Precedence'); ?></strong><span><?php echo _('Template override → this site default → device fallback. A failed/offline inventory lookup preserves both saved and staged selections.'); ?></span><span id="ucm-default-asset-dirty"><?php echo _('No unsaved assignment changes.'); ?></span></div><button type="submit" class="btn btn-primary" id="ucm-save-site-defaults"><?php echo _('Save site defaults'); ?></button></div>
      </div>
      <div class="alert alert-info ucm-top-gap"><strong><?php echo _('Phonebook delivery:'); ?></strong> <?php echo _('Directory & AD publishes a secret-free generated snapshot to each selected PBX. The selector above chooses this site’s fallback; it does not change which contacts are compiled.'); ?> <a href="config.php?display=ucmprovisioner&amp;tab=directory"><?php echo _('Open Phonebook manager'); ?></a></div>
    </section>
    <section class="ucm-card"><div class="ucm-card-heading"><div><h3><?php echo _('Cisco XML device policy and generated companions'); ?></h3><p><?php echo _('Softkey and feature-policy filenames are generated from the effective template stack and are not editable site settings.'); ?></p></div><span class="label label-info"><?php echo _('Managed lifecycle'); ?></span></div><div class="row">
      <div class="col-md-6"><div class="form-group"><label><?php echo _('Optional fallback dial plan'); ?></label><select class="form-control" name="settings[dial_template_file]" id="ucm-default-dialplan" data-saved="<?php echo $h($v('dial_template_file','')); ?>"><option value=""><?php echo _('None — template digit rules generate their own file'); ?></option><?php if($v('dial_template_file','')!==''): ?><option value="<?php echo $h($v('dial_template_file')); ?>" selected><?php echo $h($v('dial_template_file').' — saved; verifying TFTP'); ?></option><?php endif; ?></select><p class="help-block"><?php echo _('Only existing root-level XML files discovered on this site can be selected. A missing filename is never invented.'); ?></p></div></div>
      <div class="col-md-6"><div class="table-responsive"><table class="table table-condensed"><thead><tr><th><?php echo _('Companion'); ?></th><th><?php echo _('Owner'); ?></th><th><?php echo _('Lifecycle'); ?></th></tr></thead><tbody><tr><td><?php echo _('Dial plan'); ?></td><td><?php echo _('Template digit rules or optional fallback above'); ?></td><td><span class="label label-success"><?php echo _('Validated / generated'); ?></span></td></tr><tr><td><?php echo _('Softkeys'); ?></td><td><?php echo _('Templates → Softkey editor'); ?></td><td><span class="label label-success"><?php echo _('Generated per stack'); ?></span></td></tr><tr><td><?php echo _('Feature policy'); ?></td><td><?php echo _('Effective template policy'); ?></td><td><span class="label label-success"><?php echo _('Generated per stack'); ?></span></td></tr></tbody></table></div></div>
      <div class="col-md-3"><div class="form-group"><label><?php echo _('Cisco device security mode'); ?></label><?php $select('device_security', $v('device_security','1'), ['1'=>_('1 — non-secure SIP'),'2'=>_('2 — authenticated SIP'),'3'=>_('3 — encrypted SIP')]); ?></div></div>
      <div class="col-md-3"><div class="form-group"><label><?php echo _('Network locale'); ?></label><input class="form-control" name="settings[network_locale]" value="<?php echo $h($v('network_locale')); ?>"></div></div>
      <div class="col-md-6"><div class="alert alert-info"><strong><?php echo _('Moved to its owning manager:'); ?></strong> <?php echo _('Phone Services owns the vendor application menu, Directory & AD owns directory behavior, and Media/Templates own Cisco 7940/7960 logo selection. Existing compatibility values remain readable but are no longer presented as competing settings here.'); ?></div></div>
      <div class="col-md-3"><div class="form-group"><label><?php echo _('Cisco wallpaper catalog'); ?></label><?php $select('background_access_disabled', $v('background_access_disabled','0'), ['0'=>_('Users may choose'),'1'=>_('Locked by administrator')]); ?><p class="help-block"><?php echo _('Selecting a default wallpaper in General or a template automatically locks that provisioned default because Cisco requires backgroundImageAccess=false.'); ?></p></div></div>
      <div class="col-md-3"><div class="form-group"><label><?php echo _('Cisco phone personalization'); ?></label><?php $select('phone_personalization', $v('phone_personalization','0'), ['0'=>_('Disabled'),'1'=>_('Enabled')]); ?><p class="help-block"><?php echo _('Controls phonePersonalization/CGI services. It does not replace the wallpaper catalog policy.'); ?></p></div></div>
    </div></section>
  </form><?php endif; ?>
  <section class="ucm-card" id="ucm-configuration-package">
    <div class="ucm-card-heading"><div><h3><?php echo _('Configuration package'); ?></h3><p><?php echo _('Export or restore this controller’s module-owned configuration and managed files. Clear any tab that must be excluded. This is a controller snapshot; use Site replication for translating policy between independently owned PBXs.'); ?></p></div><span class="label label-info"><?php echo _('ZIP · hash verified'); ?></span></div>
    <?php if($configurationArchiveResult): ?><div class="alert <?php echo !empty($configurationArchiveResult['ok'])?'alert-success':'alert-warning'; ?>"><strong><?php echo !empty($configurationArchiveResult['ok'])?_('Import completed.'):_('Recovery review required.'); ?></strong> <?php echo $h((string)($configurationArchiveResult['message'] ?? '')); ?> <?php echo $h((string)($configurationArchiveResult['recovery_id'] ?? '')); ?></div><?php endif; ?>
    <div class="alert alert-info"><?php echo _('Configuration exports omit credentials and private keys. Restricted recovery packages include selected secrets and must stay with the matching controller/database/key pair. Imports merge matching IDs and files; they do not rotate keys or overwrite unrelated records. Cross-controller transfers use Site replication.'); ?></div>
    <div class="row">
      <div class="col-md-6">
        <form method="post" class="ucm-archive-form" action="config.php?display=ucmprovisioner&amp;tab=settings&amp;view=form&amp;site_id=<?php echo $siteId; ?>">
          <input type="hidden" name="ucmp_csrf" value="<?php echo $h($ucm->pageCsrfToken()); ?>"><input type="hidden" name="action" value="exportconfiguration">
          <div class="form-group"><label><?php echo _('Package type'); ?></label><select name="archive_mode" class="form-control"><option value="configuration"><?php echo _('Configuration — no credentials'); ?></option><option value="recovery"><?php echo _('Restricted recovery — includes selected secrets'); ?></option></select></div>
          <label class="checkbox"><input type="checkbox" name="confirm_recovery_secrets" value="1"> <?php echo _('For recovery: I will protect this archive and retain its matching database/key pair.'); ?></label>
          <fieldset class="ucm-picker"><legend><?php echo _('Export tabs'); ?></legend><div class="ucm-picker-toolbar"><div class="ucm-picker-actions"><button type="button" class="btn btn-xs btn-default" data-archive-select="all"><?php echo _('Select all'); ?></button><button type="button" class="btn btn-xs btn-default" data-archive-select="none"><?php echo _('Clear'); ?></button></div><span class="ucm-picker-count" aria-live="polite"></span></div><div class="ucm-picker-list">
            <?php foreach($configurationArchiveScopes as $scope=>$label): ?><label class="ucm-choice-card"><input type="checkbox" name="archive_scopes[]" value="<?php echo $h($scope); ?>" checked> <span><strong><?php echo $h(_($label)); ?></strong><small><?php echo $scope==='tftp'?_('Only files not owned by another selected manager.'):($scope==='fail2ban'?_('Monitor-only tab; no bans or operating-system jail state.'):_('Stored policy and managed assets owned by this tab.')); ?></small></span></label><?php endforeach; ?>
          </div></fieldset>
          <p class="help-block"><?php echo _('Firmware and media can make this download large. Configuration downloads are removed afterward; restricted recovery archives remain in private controller storage.'); ?></p>
          <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> <?php echo _('Export selected tabs'); ?></button>
        </form>
      </div>
      <div class="col-md-6">
        <form method="post" enctype="multipart/form-data" class="ucm-archive-form" action="config.php?display=ucmprovisioner&amp;tab=settings&amp;view=form&amp;site_id=<?php echo $siteId; ?>">
          <input type="hidden" name="ucmp_csrf" value="<?php echo $h($ucm->pageCsrfToken()); ?>"><input type="hidden" name="action" value="importconfiguration">
          <div class="form-group"><label><?php echo _('Package type'); ?></label><select name="archive_mode" class="form-control"><option value="configuration"><?php echo _('Configuration — no credentials'); ?></option><option value="recovery"><?php echo _('Restricted recovery — matching database/key'); ?></option></select></div>
          <label class="checkbox"><input type="checkbox" name="confirm_recovery_secrets" value="1"> <?php echo _('For recovery: this is the matching controller/database/key pair.'); ?></label>
          <div class="form-group"><label for="ucm-configuration-package-file"><?php echo _('Configuration ZIP'); ?></label><input type="file" class="form-control" id="ucm-configuration-package-file" name="configuration_package" accept=".zip,application/zip" required><p class="help-block"><?php echo _('Only tabs both present in the package and selected below are imported. Keep Sites selected for a complete same-controller restore.'); ?></p></div>
          <fieldset class="ucm-picker"><legend><?php echo _('Import tabs'); ?></legend><div class="ucm-picker-toolbar"><div class="ucm-picker-actions"><button type="button" class="btn btn-xs btn-default" data-archive-select="all"><?php echo _('Select all'); ?></button><button type="button" class="btn btn-xs btn-default" data-archive-select="none"><?php echo _('Clear'); ?></button></div><span class="ucm-picker-count" aria-live="polite"></span></div><div class="ucm-picker-list">
            <?php foreach($configurationArchiveScopes as $scope=>$label): ?><label class="ucm-choice-card"><input type="checkbox" name="archive_scopes[]" value="<?php echo $h($scope); ?>" checked> <span><strong><?php echo $h(_($label)); ?></strong><small><?php echo $scope==='tftp'?_('Residual TFTP Browser content only.'):($scope==='fail2ban'?_('No persistent state exists in this monitor-only tab.'):_('Merge matching records and replace matching managed files.')); ?></small></span></label><?php endforeach; ?>
          </div></fieldset>
          <label class="checkbox ucm-top-gap"><input type="checkbox" name="confirm_configuration_import" value="1" required> <?php echo _('I understand that matching selected configuration and managed files will be replaced.'); ?></label>
          <button type="submit" class="btn btn-warning"><i class="fa fa-upload"></i> <?php echo _('Import selected tabs'); ?></button>
        </form>
      </div>
    </div>
  </section>
<?php if((int)$siteId===(int)$ucm->localSiteId()): ?>
  <?php try { $keyState=$ucm->credentialKeyStatus(); } catch(\Throwable $error) { $keyState=['state'=>'recovery_required']; } ?>
  <?php if($keyState['state']!=='private_file'): ?>
    <div class="alert alert-warning" id="ucm-credential-key"><?php echo _('Credential storage upgrade did not finish. Check the failed installation result and retry Install/update module from Sites after correcting the reported problem. Matching recovery data must be retained.'); ?></div>
  <?php endif; ?>
<?php endif; ?>
</div>
<script>
jQuery(function($){
  var siteId=<?php echo (int)$siteId; ?>;
  $('#ucm-copy-published-phonebook').on('click',function(){var button=$(this).prop('disabled',true),status=$('#ucm-copy-published-phonebook-status'),key=String($('#ucm-default-phonebook').val()||'');if(!key){status.addClass('text-danger').text('Choose an explicit published phonebook first.');button.prop('disabled',false);return;}status.removeClass('text-danger').text('Creating a separate local AD-backed phonebook…');window.ucmpAjax({data:{command:'phonebookcopy',site_id:siteId,book_key:key}}).done(function(response){var result=response&&response.result!==undefined?response.result:response;if(!result||!result.ok){status.addClass('text-danger').text(result&&result.message||'Phonebook policy copy failed.');return;}status.removeClass('text-danger').text(result.message||'Local AD-backed phonebook created.');}).fail(function(){status.addClass('text-danger').text('Phonebook policy copy request failed.');}).always(function(){button.prop('disabled',false);});});
  function meshScheduleValue(){var box=$('#ucm-settings-mesh-schedule'),mode=String(box.find('[data-schedule-mode]').val()||'interval'),days=box.find('[data-schedule-weekday]:checked').map(function(){return parseInt(this.value,10);}).get();return{mode:mode,interval_minutes:parseInt(box.find('[data-schedule-interval]').val(),10)||15,time:String(box.find('[data-schedule-time]').val()||'02:00'),weekdays:mode==='weekly'?days:[1,2,3,4,5,6,7]};}
  function refreshMeshSchedule(){var box=$('#ucm-settings-mesh-schedule');if(!box.length)return null;var value=meshScheduleValue();box.find('[data-schedule-field="interval"]').prop('hidden',value.mode!=='interval');box.find('[data-schedule-field="clock"]').prop('hidden',['daily','weekly'].indexOf(value.mode)===-1);box.find('[data-schedule-field="weekly"]').prop('hidden',value.mode!=='weekly');$('#ucm-settings-mesh-schedule-json').val(JSON.stringify(value));$('#ucm-settings-mesh-interval-compat').val(value.mode==='manual'?0:(value.mode==='daily'?1440:(value.mode==='weekly'?10080:value.interval_minutes)));return value;}
  $('#ucm-settings-mesh-schedule').on('input change',':input',refreshMeshSchedule);refreshMeshSchedule();
  function refreshPhoneRebuildSchedule(){var box=$('#ucm-settings-phone-rebuild-schedule');if(!box.length)return null;var mode=String(box.find('[data-schedule-mode]').val()||'daily'),days=box.find('[data-schedule-weekday]:checked').map(function(){return parseInt(this.value,10);}).get(),value={mode:mode,interval_minutes:parseInt(box.find('[data-schedule-interval]').val(),10)||15,time:String(box.find('[data-schedule-time]').val()||'03:00'),weekdays:mode==='weekly'?days:[1,2,3,4,5,6,7]};box.find('[data-schedule-field="interval"]').prop('hidden',mode!=='interval');box.find('[data-schedule-field="clock"]').prop('hidden',['daily','weekly'].indexOf(mode)===-1);box.find('[data-schedule-field="weekly"]').prop('hidden',mode!=='weekly');$('#ucm-settings-phone-rebuild-json').val(JSON.stringify(value));$('#ucm-settings-phone-rebuild-interval').val(mode==='manual'?0:(mode==='daily'?1440:(mode==='weekly'?10080:value.interval_minutes)));return value;}
  $('#ucm-settings-phone-rebuild-schedule').on('input change',':input',refreshPhoneRebuildSchedule);refreshPhoneRebuildSchedule();
  function serializeDraft(){var mesh=refreshMeshSchedule(),phones=refreshPhoneRebuildSchedule();save();if(mesh&&mesh.mode==='weekly'&&!mesh.weekdays.length){alert('Choose at least one weekday for the weekly BLF mesh schedule.');return false;}if(phones&&phones.mode==='weekly'&&!phones.weekdays.length){alert('Choose at least one weekday for the weekly phone rebuild schedule.');return false;}return true;}
  $('form.fpbx-submit').on('ucmp:serialize-draft',serializeDraft).on('submit.ucmpSchedule',function(event){if(!serializeDraft())event.preventDefault();});
  $('.ucm-archive-form').each(function(){var form=$(this),boxes=form.find('input[name="archive_scopes[]"]'),count=form.find('.ucm-picker-count');function refresh(){count.text(boxes.filter(':checked').length+' selected · '+boxes.length+' available');}form.on('change','input[name="archive_scopes[]"]',refresh).on('click','[data-archive-select]',function(){boxes.prop('checked',String($(this).data('archive-select'))==='all');refresh();});form.on('submit',function(event){if(!boxes.filter(':checked').length){event.preventDefault();alert('Select at least one module tab.');}});refresh();});
  var defaultCatalog={
    families:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson([
        ['value'=>'cisco_xml','label'=>'Cisco XML'],
        ['value'=>'cisco_cnf','label'=>'Cisco CNF 7940/7960'],
        ['value'=>'yealink','label'=>'Yealink'],
        ['value'=>'grandstream','label'=>'Grandstream'],
    ], JSON_UNESCAPED_SLASHES); ?>,
    series:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values(array_map(static fn(array $series): array => [
        'value'=>$series['id'],
        'label'=>$series['label'].' — '.implode(', ',$series['models']),
        'vendor'=>$series['vendor'],
        'family'=>$series['family'],
    ], $seriesProfiles)), JSON_UNESCAPED_SLASHES); ?>,
    models:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values(array_map(static fn(array $model): array => [
        'value'=>(string)$model['vendor'].'|'.(string)$model['model'],
        'label'=>(string)$model['vendor'].' '.(string)$model['model'],
        'vendor'=>(string)$model['vendor'],
        'family'=>(string)$model['family'],
        'role'=>(string)($model['role']??'desk'),
    ], array_values(array_filter($models, static fn(array $model): bool => !in_array((string)($model['role']??'desk'), ['dect_handset','dect_radio','dect_manager','unsupported'], true))))), JSON_UNESCAPED_SLASHES); ?>,
    roles:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values(array_unique(array_map(
        static fn(array $model): string => (string)($model['role']??'desk'),
        array_values(array_filter($models, static fn(array $model): bool => !in_array((string)($model['role']??'desk'), ['dect_handset','dect_radio','dect_manager','unsupported'], true)))
    ))), JSON_UNESCAPED_SLASHES); ?>,
    templates:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values(array_map(static fn(array $template): array => [
        'id'=>(int)$template['id'],
        'name'=>(string)$template['name'],
        'family'=>(string)($template['target_family']??''),
        'series'=>(string)($template['target_series']??''),
        'role'=>(string)($template['target_role']??''),
        'model'=>(string)($template['target_model']??''),
    ], $siteTemplates)), JSON_UNESCAPED_SLASHES); ?>
  };
  var defaultRules=(function(){try{var rows=JSON.parse($('#ucm-default-template-json').val()||'[]');return Array.isArray(rows)?rows:[];}catch(e){return [];}})();
  function defaultOptions(rows,value,labelKey,valueKey){var out=$('<select class="form-control">');rows.forEach(function(row){var v=typeof row==='string'?row:row[valueKey||'value'],label=typeof row==='string'?row.replace(/_/g,' '):row[labelKey||'label'],option=$('<option>').val(v).text(label);if(typeof row==='object'){if(row.vendor)option.attr('data-vendor',row.vendor);if(row.family)option.attr('data-family',row.family);}out.append(option);});var selected=String(value||'');if(selected&&!out.find('option').filter(function(){return String($(this).val())===selected;}).length)out.append($('<option>').val(selected).text(selected+' — currently unavailable').attr('data-unavailable','1'));out.val(selected);return out;}
  function renderDefaultRules(){
    var body=$('#ucm-default-template-body').empty();
    defaultRules.forEach(function(rule,index){
      var type=rule.scope_type||(rule.target_model?'model':(rule.target_series?'series':'family')),target=rule.target_model||rule.target_series||rule.target_family||'',row=$('<tr>');
      var scope=defaultOptions([{value:'family',label:'Family'},{value:'series',label:'Policy / compatibility group'},{value:'model',label:'Exact model'}],type);
      var targetRows=type==='model'?defaultCatalog.models:(type==='series'?defaultCatalog.series:defaultCatalog.families);
      var targetSelect=defaultOptions(targetRows,target);
      var role=defaultOptions([{value:'',label:'All roles'}].concat(defaultCatalog.roles.map(function(value){return {value:value,label:value.replace(/_/g,' ')};})),rule.target_role||'');
      var template=defaultOptions([{value:'',label:'Choose template'}].concat(defaultCatalog.templates.map(function(value){return {value:String(value.id),label:value.name+' — '+(value.family||'generic')};})),String(rule.template_id||''));
      var enabled=$('<input type="checkbox">').prop('checked',rule.enabled!==0&&rule.enabled!=='0'),remove=$('<button type="button" class="btn btn-xs btn-danger">Remove</button>');
      function sync(){var selected=targetSelect.find('option:selected'),value=String(targetSelect.val()||'');rule={scope_type:type,template_id:parseInt(template.val()||0,10),target_vendor:String(selected.data('unavailable')?rule.target_vendor||'':selected.data('vendor')||''),target_family:type==='family'?value:String(selected.data('unavailable')?rule.target_family||'':selected.data('family')||''),target_series:type==='series'?value:'',target_model:type==='model'?value:'',target_role:String(role.val()||''),enabled:enabled.is(':checked')?1:0};defaultRules[index]=rule;$('#ucm-default-template-json').val(JSON.stringify(defaultRules));}
      scope.on('change',function(){var oldRule=defaultRules[index];oldRule.scope_type=String($(this).val()||'family');oldRule.target_model='';oldRule.target_series='';oldRule.target_family='';renderDefaultRules();});
      targetSelect.on('change',sync);role.add(template).add(enabled).on('change',sync);remove.on('click',function(){defaultRules.splice(index,1);renderDefaultRules();});
      row.append($('<td>').append(scope),$('<td>').append(targetSelect),$('<td>').append(role),$('<td>').append(template),$('<td class="text-center">').append(enabled),$('<td>').append(remove));body.append(row);
    });
    $('#ucm-default-template-json').val(JSON.stringify(defaultRules));
  }
  $('#ucm-add-default-template').on('click',function(){defaultRules.push({template_id:0,target_family:'cisco_xml',target_series:'',target_model:'',target_role:'',enabled:1});renderDefaultRules();});
  var defaultMapError='';
  function map(id){try{var value=JSON.parse($(id).val()||'{}');if(Array.isArray(value)&&value.length===0)return {};if(!value||typeof value!=='object'||Array.isArray(value))throw Error('Invalid assignments');return value;}catch(e){defaultMapError='Saved asset assignments are invalid. Their original values were retained; repair the saved policy before saving Settings.';return {};}}
  var firmware=map('#ucm-default-firmware-json'),timezone=map('#ucm-default-timezone-json'),wallpaper=map('#ucm-default-wallpaper-json'),hosted=map('#ucm-default-hosted-json');
  var assetProfiles={
    firmware:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values($firmwareProfiles),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>,
    cisco_timezone:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values($timezoneProfiles),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>,
    cisco_wallpaper:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values($backgroundProfiles),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>,
    hosted_wallpaper:<?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values($hostedProfiles),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>
  };
  function tftp(data){return window.ucmpAjax({data:$.extend({command:'tftp',site_id:siteId},data)});}
  function loadDialPlans(){var select=$('#ucm-default-dialplan');if(!select.length)return;var saved=String(select.data('saved')||'');select.prop('disabled',true);tftp({op:'list',path:''}).done(function(resp){var r=(resp&&resp.result)||{},seen={};select.empty().append($('<option>').val('').text('None — template digit rules generate their own file'));(r.entries||[]).filter(function(item){return !item.dir&&/dial[^/]*\.xml$/i.test(String(item.name||''));}).forEach(function(item){var name=String(item.name);if(!seen[name]){seen[name]=true;select.append($('<option>').val(name).text(name+' — present on TFTP'));}});if(saved&&!seen[saved])select.append($('<option>').val(saved).text(saved+' — saved, currently missing'));select.val(saved);}).fail(function(){if(saved&&!select.find('option').filter(function(){return this.value===saved;}).length)select.append($('<option>').val(saved).text(saved+' — saved; TFTP unavailable'));select.val(saved);}).always(function(){select.prop('disabled',false);});}
  function media(data){return window.ucmpAjax({data:$.extend({command:'mediaasset',site_id:siteId},data)});}
  function save(){if(defaultMapError){$('#ucm-default-asset-status').addClass('text-danger').text(defaultMapError);return false;}$('#ucm-default-firmware-json').val(JSON.stringify(firmware));$('#ucm-default-timezone-json').val(JSON.stringify(timezone));$('#ucm-default-wallpaper-json').val(JSON.stringify(wallpaper));$('#ucm-default-hosted-json').val(JSON.stringify(hosted));}
  // One durable draft backs every matrix tab. Inventory refreshes only replace
  // option catalogs; they never rewrite these three staged maps.
  // A legacy family default is not proof that an image fits every model profile.
  // Retain it as its own editable row until the operator explicitly clears it.
  Object.keys({cisco_cnf:'Cisco',yealink:'Yealink',grandstream:'Grandstream'}).forEach(function(family){
    if(hosted[family])assetProfiles.hosted_wallpaper.push({id:family,family:family,vendor:{cisco_cnf:'Cisco',yealink:'Yealink',grandstream:'Grandstream'}[family],label:'Legacy '+family+' default',models:[]});
  });
  save();
  var defaultAssetState={kind:'firmware',vendor:'Cisco',firmware:null,timezone:null,wallpapers:null,hosted:null,firmwareError:'',timezoneError:'',wallpaperError:'',hostedError:'',dirty:{}};
  function assignmentMap(kind){return kind==='firmware'?firmware:(kind==='cisco_timezone'?timezone:(kind==='cisco_wallpaper'?wallpaper:hosted));}
  function assignmentRows(){return (assetProfiles[defaultAssetState.kind]||[]).filter(function(row){return String(row.vendor||'').toLowerCase()===String(defaultAssetState.vendor||'').toLowerCase();});}
  function assignmentVendors(){var seen={},vendors=[];(assetProfiles[defaultAssetState.kind]||[]).forEach(function(row){var vendor=String(row.vendor||'');if(vendor&&!seen[vendor.toLowerCase()]){seen[vendor.toLowerCase()]=true;vendors.push(vendor);}});return vendors.sort();}
  function setAssignmentVendors(){var select=$('#ucm-default-asset-vendor'),vendors=assignmentVendors();if(vendors.indexOf(defaultAssetState.vendor)===-1)defaultAssetState.vendor=vendors[0]||'';select.empty();vendors.forEach(function(vendor){select.append($('<option>').val(vendor).text(vendor));});select.val(defaultAssetState.vendor).prop('disabled',vendors.length<2);}
  function uniqueAssignmentOptions(options){var seen={};return options.filter(function(option){var value=String(option.value||'');if(!value||seen[value])return false;seen[value]=true;return true;});}
  function assignmentOptions(kind,profile,current){var options=[];
    if(kind==='firmware'&&defaultAssetState.firmware){(defaultAssetState.firmware.bundles||[]).filter(function(bundle){return bundle.status==='complete'&&String(bundle.profile||'')===String(profile.id);}).forEach(function(bundle){var load=String(bundle.load_name||bundle.filename||'');if(load)options.push({value:load,label:load+(bundle.version?' — '+bundle.version:'')});});}
    if(kind==='cisco_timezone'&&defaultAssetState.timezone){(defaultAssetState.timezone.assets||[]).filter(function(asset){return asset.selectable!==false&&String(asset.profile||'')===String(profile.id);}).forEach(function(asset){options.push({value:String(asset.asset_key||''),label:String(asset.version||'unknown')+' — '+String(asset.payload||'')+' · '+String(asset.sha256||'').slice(0,12)+(asset.canonical_active?' · canonical root copy':'')});});}
    if(kind==='cisco_wallpaper'&&defaultAssetState.wallpapers){(defaultAssetState.wallpapers.sets||[]).forEach(function(set){(set.variants||[]).filter(function(variant){return String(variant.profile||'')===String(profile.id)&&variant.enabled!==false&&variant.current!==false&&variant.delivery==='cisco_list'&&variant.full;}).forEach(function(variant){options.push({value:String(variant.full),label:String(set.name||set.id)+' — '+String(variant.full)});});});}
    if(kind==='hosted_wallpaper'&&defaultAssetState.hosted){
      // A catalog output is published into every compatible model library.
      // Those URLs are delivery copies, not five different operator choices.
      // Prefer the already-saved URL inside a group so merely opening the
      // matrix never rewrites a durable site default.
      var hostedGroups={},hostedOrder=[];
      (defaultAssetState.hosted.background_choices||[]).filter(function(asset){return String(asset.profile||'')===String(profile.id)&&asset.selectable===true&&asset.url;}).forEach(function(asset){
        var filename=String(asset.filename||asset.name||''),hash=String(asset.content_sha256||''),catalog=String(asset.catalog_id||asset.catalog_name||''),identity=[String(profile.id),catalog,filename,hash||String(asset.url)].join('|');
        if(!hostedGroups[identity]){hostedGroups[identity]={asset:asset,count:0};hostedOrder.push(identity);}
        hostedGroups[identity].count++;
        if(String(asset.url)===String(current||''))hostedGroups[identity].asset=asset;
      });
      hostedOrder.forEach(function(identity){var group=hostedGroups[identity],asset=group.asset,title=String(asset.catalog_name||asset.name||asset.filename),filename=String(asset.filename||asset.url),label=title+(title!==filename?' — '+filename:'');if(group.count>1)label+=' · '+group.count+' compatible model libraries';options.push({value:String(asset.url),label:label});});
    }
    return uniqueAssignmentOptions(options);
  }
  function assignmentInventoryState(kind){if(kind==='firmware')return defaultAssetState.firmwareError?'error':(defaultAssetState.firmware?'loaded':'loading');if(kind==='cisco_timezone')return defaultAssetState.timezoneError?'error':(defaultAssetState.timezone?'loaded':'loading');if(kind==='hosted_wallpaper')return defaultAssetState.hostedError?'error':(defaultAssetState.hosted?'loaded':'loading');return defaultAssetState.wallpaperError?'error':(defaultAssetState.wallpapers?'loaded':'loading');}
  function renderAssignmentMatrix(){setAssignmentVendors();var kind=defaultAssetState.kind,mapValue=assignmentMap(kind),body=$('#ucm-default-asset-body').empty(),inventoryState=assignmentInventoryState(kind),error=kind==='firmware'?defaultAssetState.firmwareError:(kind==='cisco_timezone'?defaultAssetState.timezoneError:(kind==='hosted_wallpaper'?defaultAssetState.hostedError:defaultAssetState.wallpaperError));
    if(defaultMapError)error=defaultMapError;
    assignmentRows().forEach(function(profile){var current=String(mapValue[profile.id]||''),options=assignmentOptions(kind,profile,current),select=$('<select class="form-control ucm-default-asset-choice">').attr({'data-kind':kind,'data-profile':profile.id}),emptyLabel=kind==='firmware'?'No site default — preserve device load':(kind==='cisco_timezone'?'No site default — use recognized canonical file':'No site default — use device fallback');select.append($('<option>').val('').text(emptyLabel));options.forEach(function(option){select.append($('<option>').val(option.value).text(option.label));});var available=options.some(function(option){return option.value===current;});if(current&&!available)select.append($('<option>').val(current).text(current+' — staged/saved, currently unavailable'));select.val(current);var statusText=inventoryState==='loading'?'Loading inventory…':(inventoryState==='error'?'Site unreachable — selection preserved':(options.length?options.length+' available option(s)':'No complete managed option'));if(current&&!available&&inventoryState==='loaded')statusText='Saved selection unavailable — preserved';var profileLabel=String(profile.label||profile.id),models=(profile.models||[]).join(', ');if(profile.architecture)profileLabel+=' · '+profile.architecture;if(!profile.label&&kind==='cisco_wallpaper'&&profile.size)profileLabel=profile.size[0]+'×'+profile.size[1]+' / '+profile.size[2]+'×'+profile.size[3];body.append($('<tr>').append($('<td>').append($('<strong>').text(profileLabel),$('<code>').text(profile.id),profile.payload?$('<small>').text(profile.payload):null),$('<td>').text(models||'—'),$('<td>').append(select),$('<td>').append($('<span class="ucm-default-row-status">').toggleClass('is-warning',inventoryState==='error'||(current&&!available)).text(statusText))));});
    if(!body.children().length)body.append($('<tr>').append($('<td colspan="4" class="text-muted">').text('No compatibility profiles are defined for this vendor.')));var dirtyCount=Object.keys(defaultAssetState.dirty).length;$('#ucm-default-asset-dirty').text(dirtyCount?dirtyCount+' unsaved assignment change(s). Switching tabs will not discard them.':'No unsaved assignment changes.');$('#ucm-default-asset-status').toggleClass('text-danger',!!error).text(error|| (inventoryState==='loaded'?'Managed inventory loaded.':'Loading managed inventory…'));}
  var assignmentReads={};
  function loadAssignmentInventory(kind){
    var config={firmware:['firmware','firmwareError','firmware_inventory'],cisco_timezone:['timezone','timezoneError','timezone_versions'],hosted_wallpaper:['hosted','hostedError','inventory'],cisco_wallpaper:['wallpapers','wallpaperError','wallpaper_set_list']}[kind];
    if(!config)throw Error('Unknown assignment inventory');
    var field=config[0],errorField=config[1];
    if(defaultAssetState[field]||defaultAssetState[errorField]){renderAssignmentMatrix();return $.Deferred().resolve().promise();}
    var generation=(assignmentReads[kind]||0)+1;assignmentReads[kind]=generation;defaultAssetState[errorField]='';
    function current(){return assignmentReads[kind]===generation;}
    return (kind==='hosted_wallpaper'?media:tftp)({op:config[2]}).done(function(resp){if(!current())return;var result=(resp&&resp.result)||{};if(!result.ok)defaultAssetState[errorField]=result.message||'Cannot load managed assignment inventory';else if(kind==='hosted_wallpaper'&&!Array.isArray(result.background_choices))defaultAssetState[errorField]='Current catalog choices require an updated source site; saved assignments were preserved.';else{defaultAssetState[field]=result;defaultAssetState[errorField]='';}}).fail(function(){if(current())defaultAssetState[errorField]='Site unreachable; saved assignments were preserved.';}).always(function(){if(current())renderAssignmentMatrix();});
  }
  $('[data-default-kind]').on('click',function(){defaultAssetState.kind=String($(this).data('default-kind'));$(this).addClass('active').siblings().removeClass('active');setAssignmentVendors();renderAssignmentMatrix();loadAssignmentInventory(defaultAssetState.kind);});
  $('#ucm-default-asset-vendor').on('change',function(){defaultAssetState.vendor=String($(this).val()||'');renderAssignmentMatrix();});
  $('#ucm-default-asset-reload').on('click',function(){var button=$(this).prop('disabled',true),kind=defaultAssetState.kind;if(kind==='firmware'){defaultAssetState.firmware=null;defaultAssetState.firmwareError='';}else if(kind==='cisco_timezone'){defaultAssetState.timezone=null;defaultAssetState.timezoneError='';}else if(kind==='hosted_wallpaper'){defaultAssetState.hosted=null;defaultAssetState.hostedError='';}else{defaultAssetState.wallpapers=null;defaultAssetState.wallpaperError='';}renderAssignmentMatrix();$.when(loadAssignmentInventory(kind)).always(function(){button.prop('disabled',false);});});
  $('#ucm-default-asset-body').on('change','.ucm-default-asset-choice',function(){var kind=String($(this).data('kind')),profile=String($(this).data('profile')),value=String($(this).val()||''),target=assignmentMap(kind);if(value)target[profile]=value;else delete target[profile];defaultAssetState.dirty[kind+'|'+profile]=true;save();renderAssignmentMatrix();});
  $('form.fpbx-submit').on('submit.ucmpAssetDraft',save);
  renderDefaultRules();setAssignmentVendors();renderAssignmentMatrix();loadAssignmentInventory('firmware');loadDialPlans();
});
</script>
<script>jQuery(function($){
  var settingsSyncJobs=<?php echo \FreePBX\modules\Ucmprovisioner::browserJson($settingsSyncJobs,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>||[],settingsSource=<?php echo (int)$siteId; ?>,settingsCurrentJob=null,settingsScopeDraft={};
  var syncEditor=window.ucmpConfigurationEditor({prefix:'ucm-settings-sync',current:function(){return settingsCurrentJob;},scopes:function(){return $('.ucm-settings-sync-scope').map(function(){return String(this.value);}).get();},feedback:settingsFeedback,load:settingsLoadJob,
    remember:function(job){settingsCurrentJob=job;var select=$('#ucm-settings-sync-job');if(!select.find('option[value="'+job.id+'"]').length)select.append($('<option>').val(job.id).text(job.name));select.val(job.id);},
    list:function(rows){settingsSyncJobs=rows.filter(function(job){return Number(job.source_site_id)===settingsSource&&((job.scopes||[]).some(function(value){return $('.ucm-settings-sync-scope').map(function(){return String(this.value);}).get().indexOf(value)!==-1;}));});var select=$('#ucm-settings-sync-job').empty().append($('<option value="0">').text('New sync job'));settingsSyncJobs.forEach(function(job){select.append($('<option>').val(job.id).text(job.name));});}
  });
  function wirePicker(root){var $root=$(root),$items=$root.find('.ucm-picker-item'),$filter=$root.find('.ucm-picker-filter');function refresh(){var query=String($filter.val()||'').toLowerCase(),visible=0,total=$items.length;$items.each(function(){var $item=$(this),match=!query||$item.text().toLowerCase().indexOf(query)!==-1;$item.prop('hidden',!match);if(match)visible++;});var selected=$items.find('input:checked').length;$root.find('.ucm-picker-count').text(selected+' selected · '+visible+' visible of '+total);} if($filter.length)$filter.on('input',refresh);$root.on('ucm-picker-refresh',refresh);$root.find('.ucm-picker-visible').on('click',function(){$items.filter(function(){return !$(this).prop('hidden');}).find('input').prop('checked',true).trigger('change');});$root.find('.ucm-picker-all').on('click',function(){$items.find('input').prop('checked',true).trigger('change');});$root.find('.ucm-picker-clear').on('click',function(){$items.find('input').prop('checked',false).trigger('change');});$items.find('input').on('change',refresh);refresh();}
  $('.ucm-picker').each(function(){wirePicker(this);});function refreshPickers(){$('.ucm-picker').trigger('ucm-picker-refresh');}
  $('.ucm-settings-sync-scope').each(function(){settingsScopeDraft[String($(this).val())]=$(this).is(':checked');});
  function selectedSettingsScopes(){return $('.ucm-settings-sync-scope:checked').map(function(){return String(this.value);}).get();}
  function rememberSettingsScopes(){ $('.ucm-settings-sync-scope').each(function(){settingsScopeDraft[String($(this).val())]=$(this).is(':checked');}); settingsSelectionSummary(); }
  function restoreSettingsScopes(){ $('.ucm-settings-sync-scope').each(function(){ $(this).prop('checked',!!settingsScopeDraft[String($(this).val())]); }); settingsSelectionSummary(); }
  function settingsSelectionSummary(){var labels=[];$('.ucm-settings-sync-scope:checked').each(function(){labels.push($.trim($(this).closest('.ucm-choice-card').find('strong').text()));});$('#ucm-settings-sync-selection').text(labels.length?labels.length+' selected: '+labels.join(', '):'None selected');}
  function settingsSchedule(job){var s=job&&job.schedule||{};return{mode:s.mode||'manual',interval_minutes:s.interval_minutes||job&&job.interval_minutes||60,time:s.time||'02:00',weekdays:s.weekdays||[1,2,3,4,5]};}
  function settingsScheduleVisibility(){var mode=$('#ucm-settings-sync-mode').val();$('.ucm-settings-sync-interval').prop('hidden',mode!=='interval');$('.ucm-settings-sync-clock').prop('hidden',['daily','weekly'].indexOf(mode)===-1);$('.ucm-settings-sync-weekdays').prop('hidden',mode!=='weekly');}
  function settingsFeedback(text,bad){$('#ucm-settings-sync-feedback').toggleClass('text-danger',!!bad).toggleClass('text-success',!bad).text(text||'');}
  function settingsSectionsFor(job){var sections=job&&(job.settings_categories||job.settings_sections||job.settings_scopes||job.setting_scopes||(job.object_filter&&job.object_filter.settings))||[];return Array.isArray(sections)?sections.map(String):[];}
  function renderSettingsItems(job){var items=job&&job.items||[],$box=$('#ucm-settings-sync-items').empty();if(!items.length)return;$box.append($('<h4>').text('Destination Settings state'));items.forEach(function(item){var missing=item.missing||{},detail=item.pause_reason||'';if(Object.keys(missing).length)detail=Object.keys(missing).map(function(k){return k+': '+missing[k];}).join('; ');var status=String(item.status||'active'),$row=$('<div class="ucm-sync-item">').append($('<strong>').text('Site #'+item.target_site_id+' · '+(item.object_key||item.object_type||'settings')),$('<span class="label">').addClass(['conflict','owner_conflict','paused','missing_dependency'].indexOf(status)!==-1?'label-warning':'label-success').text(status.replace(/_/g,' ')),$('<small>').text((item.soft_lock?'soft-lock · ':'')+detail));if(status==='paused')$row.append($('<button type="button" class="btn btn-xs btn-default">').text('Resume').on('click',function(){syncEditor.mutate({command:'configurationsync',op:'resume',id:job.id,target_site_id:item.target_site_id,object_type:item.object_type,object_key:item.object_key});}));$box.append($row);});}
  function settingsLoadJob(job,preserveResult){if(syncEditor.busy())return;window.ucmpSettingsFieldsLoad(job);if(!preserveResult)syncEditor.showResult(job&&job.last_result||{});settingsCurrentJob=job||null;$('#ucm-settings-sync-job').val(job?job.id:0);$('#ucm-settings-sync-name').val(job?job.name:'Settings push from Site #'+settingsSource);$('.ucm-settings-sync-target').prop('checked',false);(job&&job.target_site_ids||[]).forEach(function(id){$('.ucm-settings-sync-target[value="'+id+'"]').prop('checked',true);});if(job){settingsScopeDraft={};settingsSectionsFor(job).forEach(function(scope){settingsScopeDraft[scope]=true;});}restoreSettingsScopes();$('#ucm-settings-sync-enabled').prop('checked',job?(job.enabled===true||Number(job.enabled)===1):true);$('#ucm-settings-sync-lock').prop('checked',job?(job.soft_lock===true||Number(job.soft_lock)===1):true);$('#ucm-settings-sync-retry').prop('checked',job?(job.retry_unreachable===true||Number(job.retry_unreachable)===1):true);var s=settingsSchedule(job);$('#ucm-settings-sync-mode').val(s.mode);$('#ucm-settings-sync-minutes').val(s.interval_minutes);$('#ucm-settings-sync-time').val(s.time);$('.ucm-settings-sync-weekday').prop('checked',false);(s.weekdays||[1,2,3,4,5]).forEach(function(day){$('.ucm-settings-sync-weekday[value="'+day+'"]').prop('checked',true);});$('#ucm-settings-sync-delete').prop('disabled',!job);settingsScheduleVisibility();refreshPickers();renderSettingsItems(job);}
  function settingsPayload(op){var sections=window.ucmpRetainedSelection('.ucm-settings-sync-scope',settingsSectionsFor(settingsCurrentJob));return{setting_keys_present:1,setting_keys:window.ucmpSettingsFieldsRead(),command:'configurationsync',op:op,id:settingsCurrentJob?settingsCurrentJob.id:0,name:$('#ucm-settings-sync-name').val(),source_site_id:settingsSource,enabled:$('#ucm-settings-sync-enabled').is(':checked')?1:0,soft_lock:$('#ucm-settings-sync-lock').is(':checked')?1:0,retry_unreachable:$('#ucm-settings-sync-retry').is(':checked')?1:0,target_site_ids:window.ucmpRetainedSelection('.ucm-settings-sync-target',settingsCurrentJob&&settingsCurrentJob.target_site_ids).map(Number),scopes:sections,settings_categories:sections,schedule_mode:$('#ucm-settings-sync-mode').val(),interval_minutes:Number($('#ucm-settings-sync-minutes').val()||60),schedule_time:$('#ucm-settings-sync-time').val(),schedule_weekdays:$('.ucm-settings-sync-weekday:checked').map(function(){return Number(this.value);}).get()};}

  function settingsSave(thenRun){var p=settingsPayload('save');if(!p.settings_categories.length){settingsFeedback('Select at least one Settings subsection.',true);return;}if(!p.target_site_ids.length){settingsFeedback('Select at least one destination PBX.',true);return;}if(p.schedule_mode==='weekly'&&!p.schedule_weekdays.length){settingsFeedback('Choose at least one weekday for a weekly Settings sync job.',true);return;}syncEditor.save(p,thenRun);}

  $('[data-settings-jump]').on('click',function(){restoreSettingsScopes();});
  $('.ucm-settings-sync-scope').on('change',rememberSettingsScopes);$('#ucm-settings-sync-mode').on('change',settingsScheduleVisibility);$('#ucm-settings-sync-job').on('change',function(){settingsLoadJob(settingsSyncJobs.find(function(job){return Number(job.id)===Number($('#ucm-settings-sync-job').val());})||null);});$('#ucm-settings-sync-save').on('click',function(){settingsSave(false);});$('#ucm-settings-sync-run').on('click',function(){settingsSave(true);});$('#ucm-settings-sync-delete').on('click',function(){if(!settingsCurrentJob||!window.confirm('Delete this Settings sync job? Destination content is preserved.'))return;syncEditor.mutate({command:'configurationsync',op:'delete',id:settingsCurrentJob.id});});settingsScheduleVisibility();settingsSelectionSummary();refreshPickers();
});</script>
