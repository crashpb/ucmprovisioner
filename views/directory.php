<?php
/** AD/LDAP configuration, staged conflicts and company directory preview. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$settings = $ucm->getDirectorySettings();
$stats = $ucm->getDirectoryStats();
$conflicts = $ucm->getDirectoryConflicts();
$entries = $ucm->getDirectoryEntries(true, 5000);
$sites = $ucm->getSites();
$identityOverrides = $ucm->getDirectoryIdentityOverrides();
$rawSiteRules = json_decode((string)($settings['ad_site_rules'] ?? '[]'), true);
$siteRulesJson = json_encode(
    is_array($rawSiteRules) ? $rawSiteRules : [],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$siteRulesJson = is_string($siteRulesJson) ? $siteRulesJson : '[]';
$exampleSiteKey = 'replace-with-site-key';
foreach ($sites as $siteRuleSite) {
    if (trim((string)($siteRuleSite['site_key'] ?? '')) !== '') {
        $exampleSiteKey = (string)$siteRuleSite['site_key'];
        break;
    }
}
$v = static fn($key, $default='') => htmlspecialchars((string)($settings[$key] ?? $default));
$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$directorySources = $ucm->getDirectorySources();
$legacyCompatibilitySource = null;
foreach ($directorySources as $candidateSource) {
    if (
        ($candidateSource['source_key'] ?? '') === 'legacy-default'
        && ($candidateSource['secret_ref'] ?? '') === 'legacy-setting:ad_bind_password'
    ) {
        $legacyCompatibilitySource = $candidateSource;
        break;
    }
}
$directorySourceNew = !empty($_REQUEST['directory_source_new']);
$directorySourceId = (int)($_REQUEST['directory_source_id'] ?? ($_POST['source']['id'] ?? 0));
$directorySourceEditor = $directorySourceId > 0 ? $ucm->getDirectorySource($directorySourceId) : null;
if ($directorySourceNew) {
    $directorySourceEditor = [
        'id'=>0, 'source_key'=>'', 'domain'=>'', 'enabled'=>false, 'endpoints'=>[],
        'tls_mode'=>'ldaps', 'secret_ref'=>'', 'base_dn'=>'',
        'filter'=>'(&(objectCategory=person)(objectClass=user))',
        'attribute_map'=>[
            'display_name'=>'displayName', 'extension'=>'telephoneNumber', 'label'=>'sAMAccountName',
            'department'=>'department', 'company'=>'company', 'mail'=>'mail', 'mobile'=>'mobile', 'pager'=>'pager',
            'extra_attributes'=>['l','memberOf'],
        ],
        'site_rules'=>[], 'schedule_minutes'=>60,
        'schedule'=>['mode'=>'interval','interval_minutes'=>60,'time'=>'02:00','weekdays'=>[1,2,3,4,5,6,7]],
        'updated'=>0,
    ];
}
if (
    $directorySourceEditor
    && ($_REQUEST['action'] ?? '') === 'savedirectorysource'
    && is_array($_POST['source'] ?? null)
) {
    foreach ([
        'id', 'expected_updated', 'source_key', 'domain', 'enabled', 'endpoints',
        'tls_mode', 'secret_ref', 'base_dn', 'filter', 'attribute_map',
        'site_rules', 'schedule_minutes', 'schedule_mode', 'schedule_time', 'schedule_weekdays',
    ] as $field) {
        if (array_key_exists($field, $_POST['source'])) {
            $directorySourceEditor[$field] = $_POST['source'][$field];
        }
    }
    if (array_key_exists('schedule_mode', $_POST['source'])) {
        unset($directorySourceEditor['schedule']);
    }
}
if (
    ($directorySourceEditor['source_key'] ?? '') === 'legacy-default'
    && ($directorySourceEditor['secret_ref'] ?? '') === 'legacy-setting:ad_bind_password'
) {
    $directorySourceEditor = null;
}
$directorySourceMapDefaults = [
    'display_name'=>'displayName', 'extension'=>'telephoneNumber', 'label'=>'sAMAccountName',
    'department'=>'department', 'company'=>'company', 'mail'=>'mail', 'mobile'=>'mobile', 'pager'=>'pager',
    'sam_account_name'=>'sAMAccountName','user_principal_name'=>'userPrincipalName','given_name'=>'givenName','surname'=>'sn',
    'extra_attributes'=>[],
];
$directorySourceMap = $directorySourceEditor && is_array($directorySourceEditor['attribute_map'] ?? null)
    ? array_replace($directorySourceMapDefaults, $directorySourceEditor['attribute_map'])
    : $directorySourceMapDefaults;
$directorySourceSchedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
    $directorySourceEditor['schedule'] ?? [
        'mode'=>$directorySourceEditor['schedule_mode'] ?? ((int)($directorySourceEditor['schedule_minutes'] ?? 60) === 0 ? 'manual' : 'interval'),
        'interval_minutes'=>max(5, (int)($directorySourceEditor['schedule_minutes'] ?? 60)),
        'time'=>$directorySourceEditor['schedule_time'] ?? '02:00',
        'weekdays'=>$directorySourceEditor['schedule_weekdays'] ?? [1,2,3,4,5,6,7],
    ],
    max(5, (int)($directorySourceEditor['schedule_minutes'] ?? 60)),
    5
);
$legacySchedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
    $settings['ad_sync_schedule_json'] ?? [
        'mode'=>(int)($settings['ad_sync_interval'] ?? 15) === 0 ? 'manual' : 'interval',
        'interval_minutes'=>max(5, (int)($settings['ad_sync_interval'] ?? 15)),
    ],
    max(5, (int)($settings['ad_sync_interval'] ?? 15)),
    5
);
$directorySourceEndpoints = '';
if ($directorySourceEditor) {
    if (is_string($directorySourceEditor['endpoints'] ?? null)) {
        $directorySourceEndpoints = (string)$directorySourceEditor['endpoints'];
    } else {
        $lines = [];
        foreach ((array)($directorySourceEditor['endpoints'] ?? []) as $endpoint) {
            $host = trim((string)($endpoint['host'] ?? ''));
            if ($host === '') { continue; }
            if (strpos($host, ':') !== false) { $host = '[' . trim($host, '[]') . ']'; }
            $lines[] = $host . ':' . (int)($endpoint['port'] ?? (($directorySourceEditor['tls_mode'] ?? '') === 'ldaps' ? 636 : 389));
        }
        $directorySourceEndpoints = implode("\n", $lines);
    }
}
$directorySourceRules = $directorySourceEditor['site_rules'] ?? [];
if (is_array($directorySourceRules)) {
    $directorySourceRules = json_encode(
        $directorySourceRules,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '[]';
}
$mappingLabels = [
    'ad_attr_display'=>'FreePBX display name', 'ad_attr_extension'=>'Extension / phone number', 'ad_attr_label'=>'Phone line label',
    'ad_attr_department'=>'Department', 'ad_attr_company'=>'Company', 'ad_attr_mail'=>'Email', 'ad_attr_mobile'=>'Mobile', 'ad_attr_pager'=>'EMP No.',
];
$attributeSuggestions = [
    'ad_attr_display'=>['displayName','cn','name'],
    'ad_attr_extension'=>['telephoneNumber','ipPhone','extensionAttribute1','pager'],
    'ad_attr_label'=>['sAMAccountName','mailNickname','userPrincipalName','employeeID'],
    'ad_attr_department'=>['department','division','physicalDeliveryOfficeName'],
    'ad_attr_company'=>['company','o'],
    'ad_attr_mail'=>['mail','userPrincipalName'],
    'ad_attr_mobile'=>['mobile','otherMobile','telephoneNumber'],
    'ad_attr_pager'=>['pager','otherPager','ipPhone'],
];
$phonebooks = $ucm->getDirectoryPhonebooks();
$phonebookSyncById = [];
try {
    foreach ($ucm->configurationSyncStates($ucm->localSiteId(), 'phonebook') as $state) {
        $destinationId = (int)($state['destination_id'] ?? 0);
        if ($destinationId > 0 && !isset($phonebookSyncById[$destinationId]) && (string)($state['status'] ?? '') !== 'released') { $phonebookSyncById[$destinationId] = $state; }
    }
} catch (\Throwable) { $phonebookSyncById = []; }
$selectedBookId = !empty($_REQUEST['book_new']) ? 0 : (int)($_REQUEST['book_id'] ?? ($phonebooks[0]['id'] ?? 0));
$phonebook = $selectedBookId ? $ucm->getDirectoryPhonebook($selectedBookId) : null;
$phonebookSyncState = $phonebookSyncById[$selectedBookId] ?? null;
if (!$phonebook) {
    $phonebook = ['id'=>0,'book_key'=>'','name'=>'','filename'=>'','title'=>'Company Directory','include_mode'=>'all',
        'rules'=>['match'=>'all','items'=>[]],'exclusions'=>[],'manual_entries'=>[],'group_by'=>'site_name',
        'hierarchy'=>['version'=>2,'children'=>[
            ['type'=>'group','source'=>'site_name','fallback'=>'Unmapped site','aliases'=>[],'children'=>[
                ['type'=>'group','source'=>'ou:1','fallback'=>'Other','aliases'=>[],'children'=>[]]
            ]]
        ]], 'publish_sites'=>['*'],
        'name_format'=>'display_label','sort_by'=>'display_name','include_mobile'=>true,'cisco_page_size'=>32,
        'hide_uncategorised'=>false,'search_enabled'=>true,'search_position'=>'first','search_scope'=>'root',
        'search_extension'=>true,'search_mobile'=>true,'search_pager'=>true,'numeric_min_length'=>3,
        'mobile_result_mode'=>'find_extension','mobile_editdial'=>false,
        'cisco_softkeys'=>['menu'=>['Select','Exit','Previous','Next'],'list'=>['Dial','EditDial','Exit','Next']],'is_default'=>false];
}
$excludedKeys = array_fill_keys(array_map('strval', (array)($phonebook['exclusions'] ?? [])), true);
$ciscoSoftkeys = is_array($phonebook['cisco_softkeys'] ?? null) ? $phonebook['cisco_softkeys'] : [];
$ciscoSoftkeys += ['menu'=>['Select','Exit','Previous','Next'],'list'=>['Dial','EditDial','Exit','Next']];
?>
<div class="ucm-container">
  <div class="ucm-page-intro"><div><h2>Directory &amp; Active Directory</h2><p>Read authoritative identities from AD, map every object to a stable site, and publish the resulting company directory to managed PBXs. PBX reachability is delivery state; it never changes the accepted AD identity.</p></div></div>
  <?php if ($stats['conflicts']): ?>
    <div class="alert alert-warning"><strong><?php echo (int)$stats['conflicts']; ?> unresolved AD identity conflict(s).</strong> FreePBX identity updates for those objects are paused. Phonebook designers may still include the people, or exclude them individually.</div>
  <?php endif; ?>
  <?php if (!empty($stats['identity_skips'])): ?>
    <div class="alert alert-warning" id="ucm-directory-identity-skips">
      <strong><?php echo count($stats['identity_skips']); ?> extension(s) skipped during identity sync.</strong>
      Other eligible extensions continue to sync. These entries are checked again during the next directory sync and rejoin automatically when the issue is resolved.
      <details><summary>Show skipped extensions and reasons</summary>
        <div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Site</th><th>Extension</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($stats['identity_skips'] as $skip): ?>
          <tr><td><?php echo $h($skip['site_name'] ?: ('Site #'.$skip['site_id'])); ?></td><td><?php echo $h($skip['extension']); ?></td><td><?php echo $h($skip['reason']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      </details>
    </div>
  <?php endif; ?>
  <div class="row">
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body text-center"><div style="font-size:28px;font-weight:bold"><?php echo (int)$stats['entries']; ?></div><span class="text-muted">Unique synchronized AD objects</span></div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body text-center"><div style="font-size:28px;font-weight:bold"><?php echo (int)$stats['conflicts']; ?></div><span class="text-muted">Open conflicts</span></div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body text-center"><div style="font-size:28px;font-weight:bold"><?php echo (int)$stats['unmapped']; ?></div><span class="text-muted">No AD site mapping</span></div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body text-center"><div style="font-size:18px;font-weight:bold;margin:9px 0"><?php echo $stats['last_sync'] ? htmlspecialchars(date('Y-m-d H:i', $stats['last_sync'])) : 'Never'; ?></div><span class="text-muted">Last completed sync</span></div></div></div>
  </div>

  <div id="ucm-directory-result" class="ucm-operation-result-sticky" aria-live="polite"></div>

  <section class="ucm-card" id="ucm-directory-sources">
    <div class="ucm-card-heading"><div><h3>Directory authorities and domain-controller failover</h3><p>Register one authoritative source per AD DNS domain and put that domain's redundant controllers in priority order.</p></div><a class="btn btn-primary btn-sm" href="config.php?display=ucmprovisioner&amp;tab=directory&amp;directory_source_new=1#ucm-directory-source-editor">Add domain authority</a></div>
    <div class="alert alert-info"><strong>Independent authorities:</strong> every enabled source runs on its own schedule and accepts only a complete LDAP result. A failed domain keeps its last accepted revision; successful domains continue. Phonebook publication is rebuilt from all accepted revisions, and unreachable sites receive queued delivery work.</div>
    <div class="table-responsive">
      <table class="table table-condensed">
        <thead><tr><th>State</th><th>AD authority</th><th>Domain controllers</th><th>Transport / scope</th><th>Credential reference</th><th>Schedule / last run</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($directorySources as $directorySource): $isLegacySource=($directorySource['source_key']??'')==='legacy-default'&&($directorySource['secret_ref']??'')==='legacy-setting:ad_bind_password'; ?>
            <tr>
              <td>
                <?php if($isLegacySource): ?>
                  <span class="label label-primary">Legacy settings</span><br>
                  <small class="text-muted">Automatic sync: <?php echo !empty($directorySource['enabled'])?'enabled':'disabled'; ?></small>
                <?php else: ?>
                  <span class="label <?php echo !empty($directorySource['enabled'])?'label-success':'label-default'; ?>"><?php echo !empty($directorySource['enabled'])?'Enabled':'Disabled'; ?></span><br>
                  <small class="text-muted"><?php echo $h(str_replace('_',' ',(string)($directorySource['health']??'unknown'))); ?></small>
                <?php endif; ?>
              </td>
              <td><strong><?php echo $h($directorySource['domain'] ?? ''); ?></strong><br><code><?php echo $h($directorySource['source_key'] ?? ''); ?></code></td>
              <td><?php foreach((array)($directorySource['endpoints']??[]) as $endpoint): ?><code><?php echo $h(($endpoint['host']??'').':'.($endpoint['port']??'')); ?></code> <small class="text-muted">priority <?php echo (int)($endpoint['priority']??0); ?></small><br><?php endforeach; ?><?php if(empty($directorySource['endpoints'])): ?><span class="text-muted">None configured</span><?php endif; ?></td>
              <td><strong><?php echo $h(strtoupper((string)($directorySource['tls_mode']??''))); ?></strong><br><small><?php echo $h($directorySource['base_dn']??''); ?></small></td>
              <td><?php if(!empty($directorySource['secret_configured'])): ?><span class="label label-success">Credential ready</span><?php elseif(!empty($directorySource['secret_reference_set'])): ?><span class="label label-warning">Reference only</span><?php else: ?><span class="label label-danger">Missing</span><?php endif; ?><br><code><?php echo $h($directorySource['secret_ref']??''); ?></code><?php if(!empty($directorySource['secret_error'])): ?><br><small class="text-danger"><?php echo $h($directorySource['secret_error']); ?></small><?php endif; ?></td>
              <td><?php echo $h($directorySource['schedule_description'] ?? ((int)($directorySource['schedule_minutes']??0) === 0 ? 'Manual only' : 'Every '.(int)$directorySource['schedule_minutes'].' minutes')); ?><?php if(!empty($directorySource['next_run'])): ?><br><small class="text-muted">Next <?php echo $h(date('Y-m-d H:i',(int)$directorySource['next_run'])); ?><?php echo !empty($directorySource['backoff_seconds'])?' (backoff)':''; ?></small><?php endif; ?><br><small class="text-muted"><?php echo !empty($directorySource['last_success']) ? 'Accepted '. $h(date('Y-m-d H:i',(int)$directorySource['last_success'])) : 'No accepted run'; ?></small><?php if(!empty($directorySource['latest_run']['status'])): ?><br><span class="label label-default"><?php echo $h($directorySource['latest_run']['status']); ?></span><?php endif; ?><?php if(!empty($directorySource['last_error'])): ?><br><small class="text-danger"><?php echo $h($directorySource['last_error']); ?></small><?php endif; ?></td>
              <td>
                <?php if($isLegacySource): ?>
                  <a class="btn btn-default btn-xs" href="#ucm-legacy-default">Manage active sync below</a>
                <?php else: ?>
                  <a class="btn btn-default btn-xs" href="config.php?display=ucmprovisioner&amp;tab=directory&amp;directory_source_id=<?php echo (int)$directorySource['id']; ?>#ucm-directory-source-editor">Edit</a>
                  <button class="btn btn-default btn-xs ucm-directory-source-test" type="button" data-source-id="<?php echo (int)$directorySource['id']; ?>" <?php echo empty($directorySource['secret_configured'])?'disabled':''; ?>>Test</button>
                  <button class="btn btn-primary btn-xs ucm-directory-source-sync" type="button" data-source-id="<?php echo (int)$directorySource['id']; ?>" <?php echo empty($directorySource['enabled'])||empty($directorySource['secret_configured'])?'disabled':''; ?>>Sync now</button>
                  <form method="post" action="config.php?display=ucmprovisioner&amp;tab=directory#ucm-directory-sources" style="display:inline">
                    <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="toggledirectorysource">
                    <input type="hidden" name="source_id" value="<?php echo (int)$directorySource['id']; ?>"><input type="hidden" name="expected_updated" value="<?php echo (int)$directorySource['updated']; ?>"><input type="hidden" name="enabled" value="<?php echo !empty($directorySource['enabled'])?'0':'1'; ?>">
                    <button class="btn btn-link btn-xs" type="submit" <?php echo empty($directorySource['enabled'])&&empty($directorySource['secret_configured'])?'disabled title="Provision the external credential first"':''; ?>><?php echo !empty($directorySource['enabled'])?'Disable':'Enable'; ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$directorySources): ?><tr><td colspan="7" class="text-muted">No directory authority records exist. Run the module schema upgrade before configuring AD.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if($directorySourceEditor): ?>
    <section class="ucm-card" id="ucm-directory-source-editor">
      <div class="ucm-card-heading"><div><h3><?php echo !empty($directorySourceEditor['id'])?'Edit':'Add'; ?> domain authority</h3><p>The source key becomes the permanent namespace for objects from this domain. Controller order is failover priority.</p></div><a class="btn btn-default btn-sm" href="config.php?display=ucmprovisioner&amp;tab=directory#ucm-directory-sources">Close</a></div>
      <form method="post" action="config.php?display=ucmprovisioner&amp;tab=directory#ucm-directory-source-editor">
        <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="savedirectorysource"><input type="hidden" name="directory_source_new" value="<?php echo empty($directorySourceEditor['id'])?'1':'0'; ?>">
        <input type="hidden" name="directory_source_id" value="<?php echo (int)($directorySourceEditor['id']??0); ?>"><input type="hidden" name="source[id]" value="<?php echo (int)($directorySourceEditor['id']??0); ?>"><input type="hidden" name="source[expected_updated]" value="<?php echo (int)($directorySourceEditor['expected_updated']??$directorySourceEditor['updated']??0); ?>">
        <div class="row">
          <div class="col-md-3 form-group"><label>Immutable source key</label><input class="form-control" id="ucm-directory-source-key" name="source[source_key]" required maxlength="64" pattern="[a-z0-9][a-z0-9_-]{0,63}" value="<?php echo $h($directorySourceEditor['source_key']??''); ?>" <?php echo !empty($directorySourceEditor['id'])?'readonly':''; ?> placeholder="example-domain"><p class="help-block">Lowercase namespace; it cannot change after creation.</p></div>
          <div class="col-md-3 form-group"><label>AD DNS domain</label><input class="form-control" name="source[domain]" required value="<?php echo $h($directorySourceEditor['domain']??''); ?>" placeholder="example.com"><p class="help-block">Exactly one authority may own a canonical DNS domain.</p></div>
          <div class="col-md-2 form-group"><label>Secure transport</label><select class="form-control" name="source[tls_mode]" id="ucm-directory-source-tls"><?php foreach(['ldaps'=>'LDAPS','starttls'=>'StartTLS'] as $mode=>$label): ?><option value="<?php echo $mode; ?>" <?php echo ($directorySourceEditor['tls_mode']??'ldaps')===$mode?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><p class="help-block">The DC certificate chain and hostname must validate against this PBX's trust store.</p></div>
          <div class="col-md-2 form-group"><label>Runner state</label><input type="hidden" name="source[enabled]" value="0"><label class="checkbox"><input type="checkbox" name="source[enabled]" value="1" <?php echo !empty($directorySourceEditor['enabled'])?'checked':''; ?> <?php echo empty($directorySourceEditor['secret_configured'])?'disabled':''; ?>> Enabled</label><p class="help-block">A source can be enabled only after its external credential is ready.</p></div>
        </div>
        <div class="ucmp-schedule-editor" data-schedule-editor>
          <div class="ucmp-schedule-heading"><strong>Automatic synchronization</strong><span>Evaluated every minute in <?php echo $h(date_default_timezone_get()); ?>; an in-progress run is never duplicated.</span></div>
          <label>Cadence<select class="form-control" name="source[schedule_mode]" data-schedule-mode><option value="manual" <?php echo $directorySourceSchedule['mode']==='manual'?'selected':''; ?>>Manual only</option><option value="interval" <?php echo $directorySourceSchedule['mode']==='interval'?'selected':''; ?>>Minute interval</option><option value="daily" <?php echo $directorySourceSchedule['mode']==='daily'?'selected':''; ?>>Daily at a time</option><option value="weekly" <?php echo $directorySourceSchedule['mode']==='weekly'?'selected':''; ?>>Selected weekdays</option></select></label>
          <label data-schedule-field="interval">Every (minutes)<input class="form-control" type="number" min="5" max="10080" step="1" name="source[schedule_minutes]" value="<?php echo (int)$directorySourceSchedule['interval_minutes']; ?>"></label>
          <label data-schedule-field="clock">PBX local time<input class="form-control" type="time" step="60" name="source[schedule_time]" value="<?php echo $h($directorySourceSchedule['time']); ?>"></label>
          <fieldset data-schedule-field="weekly"><legend>Run on</legend><div class="ucmp-weekdays"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" name="source[schedule_weekdays][]" value="<?php echo $day; ?>" <?php echo in_array($day,$directorySourceSchedule['weekdays'],true)?'checked':''; ?>> <?php echo $label; ?></label><?php endforeach; ?></div></fieldset>
        </div>
        <div class="row">
          <div class="col-md-5 form-group"><label>Domain controllers, highest priority first</label><textarea class="form-control" name="source[endpoints]" rows="5" required placeholder="dc01.example.com:636&#10;dc02.example.com:636"><?php echo $h($directorySourceEndpoints); ?></textarea><p class="help-block">One <code>host[:port]</code> per line, up to 16. Usernames, passwords and URLs are rejected.</p></div>
          <div class="col-md-4 form-group"><label>User search base</label><input class="form-control" name="source[base_dn]" required value="<?php echo $h($directorySourceEditor['base_dn']??''); ?>" placeholder="OU=Phone Users,DC=example,DC=com"><label class="ucm-top-gap">Enabled-user LDAP filter</label><input class="form-control" name="source[filter]" required value="<?php echo $h($directorySourceEditor['filter']??''); ?>"></div>
          <div class="col-md-3 form-group"><label>Managed credential reference</label><input class="form-control" id="ucm-directory-secret-ref" name="source[secret_ref]" required pattern="file:[a-z0-9][a-z0-9_-]{0,63}" value="<?php echo $h($directorySourceEditor['secret_ref']??''); ?>" placeholder="file:example-domain"><p class="help-block">Must be exactly <code>file:&lt;source-key&gt;</code>. Source metadata never accepts or stores the credential itself.</p></div>
        </div>
        <h4>LDAP identity attributes</h4>
        <div class="row">
          <?php foreach(['display_name'=>'Display name','extension'=>'Extension','label'=>'Short phone label','department'=>'Department','company'=>'Company','mail'=>'Email','mobile'=>'Mobile','pager'=>'EMP No.','sam_account_name'=>'Login account','user_principal_name'=>'UPN','given_name'=>'Given name','surname'=>'Surname'] as $field=>$label): ?>
            <div class="col-md-3 form-group"><label><?php echo $label; ?><?php echo in_array($field,['display_name','extension','label'],true)?' *':''; ?></label><input class="form-control" name="source[attribute_map][<?php echo $field; ?>]" value="<?php echo $h($directorySourceMap[$field]??''); ?>" <?php echo in_array($field,['display_name','extension','label'],true)?'required':''; ?>></div>
          <?php endforeach; ?>
          <div class="col-md-3 form-group"><label>Additional attributes</label><input class="form-control" name="source[attribute_map][extra_attributes]" value="<?php echo $h(is_array($directorySourceMap['extra_attributes']??null)?implode(', ',$directorySourceMap['extra_attributes']):(string)($directorySourceMap['extra_attributes']??'')); ?>" placeholder="l, st, memberOf"></div>
        </div>
        <div class="row">
          <div class="col-md-8 form-group"><label>Ordered authoritative site rules (JSON)</label><textarea class="form-control" name="source[site_rules]" rows="9" spellcheck="false"><?php echo $h((string)$directorySourceRules); ?></textarea><p class="help-block">Uses the same validated attribute, OU, group and number-range rule format as the active synchronizer.</p></div>
          <div class="col-md-4"><label>Allowed site keys</label><table class="table table-condensed"><tbody><?php foreach($sites as $sourceSite): ?><tr><td><?php echo $h($sourceSite['name']??''); ?></td><td><code><?php echo $h($sourceSite['site_key']??''); ?></code></td></tr><?php endforeach; ?></tbody></table></div>
        </div>
        <button class="btn btn-primary" type="submit">Save domain authority</button>
      </form>
      <?php if(!empty($directorySourceEditor['id'])): ?>
        <hr>
        <form method="post" action="config.php?display=ucmprovisioner&amp;tab=directory&amp;directory_source_id=<?php echo (int)$directorySourceEditor['id']; ?>#ucm-directory-source-editor" autocomplete="off">
          <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="savedirectorysourcesecret"><input type="hidden" name="directory_secret[source_id]" value="<?php echo (int)$directorySourceEditor['id']; ?>">
          <h4>External reader credential <?php echo !empty($directorySourceEditor['secret_configured'])?'<span class="label label-success">ready</span>':'<span class="label label-warning">not provisioned</span>'; ?></h4>
          <p class="help-block">Provision or rotate the mode-0600 PBX-local credential object referenced above. Both fields are required on every rotation; neither value is stored in the module database, returned by an API, or rendered after submission.</p>
          <div class="row">
            <div class="col-md-5 form-group"><label>Reader username</label><input class="form-control" name="directory_secret[bind_user]" required maxlength="512" autocomplete="off" placeholder="svc_ucmprovisioner@example.com"></div>
            <div class="col-md-5 form-group"><label>Reader password</label><input class="form-control" type="password" name="directory_secret[password]" required maxlength="4096" autocomplete="new-password"></div>
            <div class="col-md-2 form-group"><label>&nbsp;</label><button class="btn btn-warning btn-block" type="submit"><?php echo !empty($directorySourceEditor['secret_configured'])?'Rotate':'Provision'; ?></button></div>
          </div>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if($legacyCompatibilitySource): ?>
    <div class="alert alert-warning" id="ucm-legacy-default">
      <strong>Credential upgrade did not finish.</strong> Credential storage is upgraded automatically during installation. Check the failed installation result and retry Install/update module from Sites after correcting the reported problem. The original AD settings are retained below for recovery.
    </div>
  <?php else: ?>
    <p class="text-muted" id="ucm-legacy-default">Manage AD connections above. The settings below apply to all connections.</p>
  <?php endif; ?>
  <form class="fpbx-submit" id="ucm-directory-form" method="post" action="config.php?display=ucmprovisioner&amp;tab=directory">
    <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="savedirectory">

    <?php if($legacyCompatibilitySource): ?>
    <section class="ucm-card">
      <div class="ucm-card-heading"><span class="ucm-step">1</span><div><h3>Legacy-default server and search scope</h3><p>Keep the original single-controller authority for compatibility, or migrate additional DNS domains to independent sources above.</p></div></div>
      <div class="row">
        <div class="col-md-4 form-group"><label>Domain controller</label><input class="form-control" name="directory[ad_host]" value="<?php echo $v('ad_host'); ?>" placeholder="dc01.example.com or 10.0.0.10"><p class="help-block">A hostname or IP address of one reachable domain controller.</p></div>
        <div class="col-md-2 form-group"><label>Port</label><input class="form-control" id="ucm-ad-port" name="directory[ad_port]" value="<?php echo $v('ad_port','636'); ?>"></div>
        <div class="col-md-3 form-group"><label>Connection security</label><select class="form-control" id="ucm-ad-security" name="directory[ad_security]"><?php foreach (['ldap'=>'LDAP (no TLS)','starttls'=>'LDAP + StartTLS','ldaps'=>'LDAPS'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $v('ad_security')===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><div id="ucm-ad-security-note" class="alert alert-warning ucm-ad-security-note"></div></div>
      </div>
      <?php if(trim((string)$v('ad_attr_mobile'))===''): ?><div class="alert alert-warning"><strong>Mobile search has no AD source.</strong> Set this field to the standard AD attribute <code>mobile</code> (or your domain's exact mobile-number attribute), save, then synchronize. Existing cached contacts cannot match digits that were never imported.</div><?php endif; ?>
      <div class="row">
        <div class="col-md-5 form-group"><label>User search base</label><div class="input-group"><input class="form-control" name="directory[ad_base_dn]" id="ucm-ad-base-dn" value="<?php echo $v('ad_base_dn'); ?>" placeholder="DC=example,DC=com"><span class="input-group-btn"><button class="btn btn-default ucm-ad-browse-tree" type="button">Browse OUs</button></span></div><p class="help-block">Users below this domain or OU are considered. The browser fills this LDAP DN for you.</p></div>
        <div class="col-md-7 form-group"><label>Enabled-user LDAP filter</label><input class="form-control" name="directory[ad_user_filter]" value="<?php echo $v('ad_user_filter'); ?>"><p class="help-block">The default selects AD user objects. Disabled accounts are always removed after the search, even if a custom filter includes them.</p></div>
      </div>
    </section>

    <section class="ucm-card">
      <div class="ucm-card-heading"><span class="ucm-step">2</span><div><h3>Saved reader credentials</h3><p>These least-privilege credentials are stored encrypted on this PBX and are used only for browsing, testing, and scheduled synchronization.</p></div></div>
      <div class="row">
        <div class="col-md-6 form-group"><label>Reader username</label><input class="form-control" id="ucm-ad-bind-user" name="directory[ad_bind_user]" value="<?php echo $v('ad_bind_user'); ?>" autocomplete="username" placeholder="svc_ucmprovisioner"><p class="help-block">A short login is enough. The module automatically expands <code>svc_ucmprovisioner</code> using the domain in the search base. UPN, <code>DOMAIN\user</code>, and full LDAP DN formats also remain accepted.</p></div>
        <div class="col-md-6 form-group"><label>Reader password</label><input class="form-control" id="ucm-ad-bind-password" type="password" name="directory[ad_bind_password]" autocomplete="new-password" placeholder="<?php echo !empty($settings['ad_password_saved']) ? 'Saved securely — leave blank to keep' : 'Required'; ?>"><p class="help-block">Leave blank when saving to keep the existing encrypted password.</p></div>
      </div>
      <button class="btn btn-default" type="button" id="ucm-ad-test">Test reader and show a sample</button><span class="ucm-inline-action-feedback" aria-live="polite"></span>
    </section>

    <section class="ucm-card">
      <div class="ucm-card-heading"><span class="ucm-step">3</span><div><h3>AD attribute mapping</h3><p>Choose a common AD attribute from each suggestion list or type an exact custom attribute name. The first three mappings are required; optional directory fields may be blank.</p></div></div>
      <div class="row">
        <?php foreach ($mappingLabels as $key=>$label): ?>
          <div class="col-md-3 form-group"><label><?php echo htmlspecialchars($label); ?></label><input class="form-control ucm-ad-attribute" list="<?php echo $key; ?>_choices" name="directory[<?php echo $key; ?>]" value="<?php echo $v($key); ?>" autocomplete="off"><datalist id="<?php echo $key; ?>_choices"><?php foreach ($attributeSuggestions[$key] as $attribute): ?><option value="<?php echo htmlspecialchars($attribute); ?>"><?php endforeach; ?></datalist></div>
        <?php endforeach; ?>
      </div>
      <div class="row"><div class="col-md-8 form-group"><label>Additional attributes available to phonebook rules</label><input class="form-control ucm-ad-attribute" name="directory[ad_extra_attributes]" value="<?php echo $v('ad_extra_attributes','l, st, memberOf'); ?>" list="ucm_ad_extra_attribute_choices" placeholder="l, st, memberOf, extensionAttribute2"><datalist id="ucm_ad_extra_attribute_choices"><option value="l, st, memberOf"><option value="physicalDeliveryOfficeName, l, st, memberOf"><option value="division, employeeType, extensionAttribute1, memberOf"></datalist><p class="help-block">Comma-separated exact LDAP names. Use <code>l</code> for city, <code>st</code> for state/province and <code>memberOf</code> for AD groups. Multi-valued attributes are preserved, not flattened.</p></div></div>
      <p class="help-block"><strong>Current default:</strong> FreePBX name = <code>displayName</code>, extension = <code>telephoneNumber</code>, and short phone label = <code>sAMAccountName</code>.</p>
    </section>

    <section class="ucm-card">
      <div class="ucm-card-heading"><span class="ucm-step">4</span><div><h3>Authoritative AD-to-site mapping</h3><p>Map users by an AD attribute, OU subtree, group membership, or extension range. The first matching priority wins; equal-priority rules that disagree become an explicit conflict.</p></div></div>
      <div class="row">
        <div class="col-md-8 form-group">
          <label>Ordered site rules (JSON)</label>
          <textarea class="form-control" name="directory[ad_site_rules]" rows="12" spellcheck="false"><?php echo $h($siteRulesJson); ?></textarea>
          <p class="help-block">Lower priority numbers run first. Rules may use <code>attribute</code> with <code>attribute</code>/<code>value</code>, <code>ou</code> with <code>dn</code>, <code>group</code> with <code>group</code>, or <code>number_range</code> with <code>start</code>/<code>end</code>. Every rule targets an exact saved <code>site_key</code>.</p>
        </div>
        <div class="col-md-4">
          <label>Saved site keys</label>
          <table class="table table-condensed"><thead><tr><th>Site</th><th>site_key</th></tr></thead><tbody>
            <?php foreach ($sites as $siteRuleSite): ?><tr><td><?php echo $h($siteRuleSite['name'] ?? ''); ?></td><td><code><?php echo $h($siteRuleSite['site_key'] ?? ''); ?></code></td></tr><?php endforeach; ?>
          </tbody></table>
          <p class="help-block">Example:</p>
          <pre class="small"><?php echo $h(json_encode([[
              'id'=>'office-tehran',
              'type'=>'attribute',
              'attribute'=>'physicalDeliveryOfficeName',
              'value'=>'Tehran',
              'site_key'=>$exampleSiteKey,
              'priority'=>10,
          ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
        </div>
      </div>
      <div class="alert alert-info">Until explicit rules are saved, migrated installations may use existing logical extension bindings as a temporary compatibility map. A live or unreachable PBX is never queried to decide a person’s site.</div>
    </section>
    <?php endif; ?>

    <section class="ucm-card">
      <div class="ucm-card-heading"><?php if($legacyCompatibilitySource): ?><span class="ucm-step">5</span><?php endif; ?><div><h3>Shared identity policy</h3><p>Choose which PBX identity fields accepted AD data owns. Connection, mapping and schedule settings belong to each managed authority above.</p></div></div>
      <div class="row">
        <?php if($legacyCompatibilitySource): ?>
          <div class="col-md-2 form-group"><label>Legacy automatic sync</label><select class="form-control" name="directory[ad_enabled]"><option value="0" <?php echo $v('ad_enabled')==='0'?'selected':''; ?>>Disabled</option><option value="1" <?php echo $v('ad_enabled')==='1'?'selected':''; ?>>Enabled</option></select></div>
          <input type="hidden" name="directory[ad_sync_schedule_json]" id="ucm-legacy-schedule-json" value="<?php echo $h(\FreePBX\modules\Ucmprovisioner\RecurringSchedule::encode($legacySchedule)); ?>"><input type="hidden" name="directory[ad_sync_interval]" id="ucm-legacy-schedule-minutes" value="<?php echo (int)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::compatibilityMinutes($legacySchedule); ?>">
        <?php endif; ?>
        <div class="col-md-2 form-group"><label>FreePBX names</label><select class="form-control" name="directory[ad_sync_names]"><option value="1" <?php echo $v('ad_sync_names')==='1'?'selected':''; ?>>AD owns them</option><option value="0" <?php echo $v('ad_sync_names')==='0'?'selected':''; ?>>Do not change</option></select></div>
        <div class="col-md-2 form-group"><label>Phone labels</label><select class="form-control" name="directory[ad_sync_labels]"><option value="1" <?php echo $v('ad_sync_labels')==='1'?'selected':''; ?>>AD owns them</option><option value="0" <?php echo $v('ad_sync_labels')==='0'?'selected':''; ?>>Do not change</option></select></div>
        <div class="col-md-4 form-group"><label>Conflict-notification recipient</label><input class="form-control" type="email" name="directory[ad_notification_email]" value="<?php echo $v('ad_notification_email'); ?>" placeholder="pbx-admins@example.com"><p class="help-block">Destination address. A changed conflict set is emailed once if the PBX mail transport works.</p></div>
      </div>
      <?php if($legacyCompatibilitySource): ?><div class="ucmp-schedule-editor" id="ucm-legacy-schedule" data-schedule-editor data-json-target="#ucm-legacy-schedule-json">
        <div class="ucmp-schedule-heading"><strong>Legacy authority schedule</strong><span>Upgrade legacy-default when ready; this schedule remains fully supported meanwhile.</span></div>
        <label>Cadence<select class="form-control" data-schedule-mode><option value="manual" <?php echo $legacySchedule['mode']==='manual'?'selected':''; ?>>Manual only</option><option value="interval" <?php echo $legacySchedule['mode']==='interval'?'selected':''; ?>>Minute interval</option><option value="daily" <?php echo $legacySchedule['mode']==='daily'?'selected':''; ?>>Daily at a time</option><option value="weekly" <?php echo $legacySchedule['mode']==='weekly'?'selected':''; ?>>Selected weekdays</option></select></label>
        <label data-schedule-field="interval">Every (minutes)<input class="form-control" type="number" min="5" max="10080" step="1" data-schedule-interval value="<?php echo (int)$legacySchedule['interval_minutes']; ?>"></label>
        <label data-schedule-field="clock">PBX local time<input class="form-control" type="time" step="60" data-schedule-time value="<?php echo $h($legacySchedule['time']); ?>"></label>
        <fieldset data-schedule-field="weekly"><legend>Run on</legend><div class="ucmp-weekdays"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" data-schedule-weekday value="<?php echo $day; ?>" <?php echo in_array($day,$legacySchedule['weekdays'],true)?'checked':''; ?>> <?php echo $label; ?></label><?php endforeach; ?></div></fieldset>
      </div><?php endif; ?>
    </section>

    <section class="ucm-card">
      <div class="ucm-card-heading"><?php if($legacyCompatibilitySource): ?><span class="ucm-step">6</span><?php endif; ?><div><h3>Save and synchronize</h3><p><?php echo $legacyCompatibilitySource?'Save the compatibility connection and shared identity policy.':'Save the shared identity policy. A manual run executes every enabled managed authority independently.'; ?></p></div></div>
      <button class="btn btn-primary" type="submit"><?php echo $legacyCompatibilitySource?'Save directory settings':'Save shared identity policy'; ?></button>
      <button class="btn btn-success" type="button" id="ucm-ad-sync"><?php echo $legacyCompatibilitySource?'Sync enabled sources + legacy':'Sync enabled authorities'; ?></button>
      <span class="ucm-inline-action-feedback" aria-live="polite"></span>
    </section>
  </form>

  <section class="ucm-card" id="ucm-identity-overrides">
    <div class="ucm-card-heading"><div><h3>Audited per-extension identity overrides</h3><p>AD remains unchanged and visible as the canonical source. An override is a separate, field-specific exception with a reason and operator audit trail.</p></div></div>
    <form method="post" action="config.php?display=ucmprovisioner&amp;tab=directory#ucm-identity-overrides">
      <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="saveidentityoverride">
      <div class="row">
        <div class="col-md-4 form-group"><label for="ucm-identity-extension">Logical extension / AD identity</label><select id="ucm-identity-extension" class="form-control ucm-chosen" data-placeholder="Search extension, name or username" name="extension_id" required><option value="">Choose an extension</option><?php foreach($entries as $entry): if(empty($entry['extension_id']) || !empty($entry['conflicted']))continue; ?><option value="<?php echo (int)$entry['extension_id']; ?>"><?php echo $h(implode(' — ',array_filter([$entry['site_name']??'Unmapped',$entry['extension']??'',$entry['display_name']??'',$entry['canonical_label']??($entry['label']??'')]))); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5 form-group"><label>Fields to override</label><p class="help-block">Select one or more fields. Hover or focus the help marker for its meaning. Unchecked fields keep their current values.</p>
          <?php foreach ([
            'phone_label'=>['Top-screen phone label','The main label at the top of the phone screen. Does not rename its BLF keys or phonebook entry.'],
            'display_name'=>['Canonical display name','Full person or shared-desk name used for FreePBX identity and directory display. AD itself remains unchanged.'],
            'label'=>['Canonical short label','Base short name, such as a.b, used by inherited phone, line, BLF and directory label patterns.'],
            'line_label'=>['Primary line label','Label next to the extension line key on the phone.'],
            'blf_label'=>['BLF label','Name shown when other phones monitor this extension with a BLF key.'],
            'directory_label'=>['Directory short label','Short label used in the handset phonebook, according to that book’s name layout.'],
          ] as $fieldKey=>[$fieldTitle,$fieldHelp]): ?>
            <div class="ucm-identity-override-field"><label><input type="checkbox" class="ucm-identity-field-toggle"> <?php echo $h($fieldTitle); ?> <span tabindex="0" title="<?php echo $h($fieldHelp); ?>" aria-label="<?php echo $h($fieldHelp); ?>">ⓘ</span></label><input class="form-control" name="overrides[<?php echo $h($fieldKey); ?>]" maxlength="255" disabled aria-label="<?php echo $h($fieldTitle); ?> value"></div>
          <?php endforeach; ?>
          <p class="help-block">Model character and length limits still apply. A selected empty value intentionally clears that field.</p>
        </div>
        <div class="col-md-3 form-group"><label>Reason / ticket</label><input class="form-control" name="reason" maxlength="255" required placeholder="HR-approved display exception"><p class="help-block">Required; AD is never written by this action.</p></div>
      </div>
      <button class="btn btn-primary" type="submit">Save selected overrides and refresh outputs</button>
    </form>
    <?php if($identityOverrides): ?>
      <div class="table-responsive ucm-top-gap"><table class="table table-condensed"><thead><tr><th>Site / extension</th><th>Field</th><th>AD value</th><th>Override / effective value</th><th>Reason</th><th>Audit</th><th></th></tr></thead><tbody>
        <?php foreach($identityOverrides as $override): $adValue=($override['field_name']??'')==='display_name'?($override['ad_display_name']??''):($override['ad_label']??''); ?>
          <tr><td><?php echo $h(($override['site_name']??'Site').' / '.($override['extension']??'')); ?></td><td><code><?php echo $h(($override['mode']??'').' / '.($override['field_name']??'')); ?></code></td><td><?php echo $h($adValue); ?></td><td><strong><?php echo $h($override['value'] ?? ''); ?></strong></td><td><?php echo $h($override['reason'] ?? ''); ?></td><td><small><?php echo $h($override['updated_by'] ?? ''); ?><br><?php echo !empty($override['updated'])?$h(date('Y-m-d H:i',(int)$override['updated'])):''; ?></small></td><td><form method="post" action="config.php?display=ucmprovisioner&amp;tab=directory#ucm-identity-overrides" onsubmit="return confirm('Revert this field to the accepted AD/template result?');"><input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="revertidentityoverride"><input type="hidden" name="override_id" value="<?php echo (int)$override['id']; ?>"><button class="btn btn-link btn-xs text-danger" type="submit">Revert to AD</button></form></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <?php require __DIR__ . '/web_phonebook_settings.php'; ?>

  <section class="ucm-card ucm-phonebook-workbench">
    <div class="ucm-card-heading"><div><h3>Graphical phonebook designer</h3><p>Create several independent directories, combine AD and manual contacts, exclude individual people without changing AD, and preview the hierarchy phones receive.</p></div><a class="btn btn-primary btn-sm" href="config.php?display=ucmprovisioner&amp;tab=directory&amp;book_new=1#ucm-phonebook-editor"><span class="ucm-icon-glyph" aria-hidden="true">＋</span> New phonebook</a></div>
    <div class="ucm-phonebook-grid">
      <aside class="ucm-phonebook-list">
        <strong>Managed files</strong><p><a class="btn btn-default btn-sm" href="#ucm-manual-contacts">Add a non-AD contact</a></p>
        <?php foreach ($phonebooks as $book): ?>
          <?php $bookSync=$phonebookSyncById[(int)$book['id']]??null; ?>
          <a class="ucm-phonebook-list-item <?php echo (int)$book['id']===$selectedBookId?'active':''; ?>" href="config.php?display=ucmprovisioner&amp;tab=directory&amp;book_id=<?php echo (int)$book['id']; ?>#ucm-phonebook-editor">
            <span><?php echo $h($book['name']); ?><?php if(!empty($book['is_default'])): ?> <em>default</em><?php endif; ?><?php if($bookSync): ?> <em><?php echo $h('sync · '.str_replace('_',' ',(string)($bookSync['status']??'active'))); ?></em><?php endif; ?></span><code><?php echo $h($book['filename']); ?></code>
          </a>
        <?php endforeach; ?>
        <p class="help-block">Definitions are stored in this PBX database. A versioned JSON cache is published locally and copied to managed PBXs; vendor XML is rendered on request.</p>
      </aside>
      <div class="ucm-phonebook-editor" id="ucm-phonebook-editor">
        <form method="post" id="ucm-phonebook-form" action="config.php?display=ucmprovisioner&amp;tab=directory&amp;book_id=<?php echo (int)$phonebook['id']; ?>#ucm-phonebook-editor">
          <input type="hidden" name="phonebook[expected_updated]" value="<?php echo (int)($phonebook['updated'] ?? 0); ?>">
          <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="savephonebook"><input type="hidden" name="book_id" value="<?php echo (int)$phonebook['id']; ?>"><input type="hidden" name="phonebook[id]" value="<?php echo (int)$phonebook['id']; ?>"><input type="hidden" name="sync_edit_reason" value="Edited in Phonebook Designer">
          <input type="hidden" name="phonebook[rules]" id="ucm-phonebook-rules"><input type="hidden" name="phonebook[exclusions]" id="ucm-phonebook-exclusions"><input type="hidden" name="phonebook[manual_entries]" id="ucm-phonebook-manual"><input type="hidden" name="phonebook[hierarchy]" id="ucm-phonebook-hierarchy"><input type="hidden" name="phonebook[publish_sites]" id="ucm-phonebook-publish-sites"><input type="hidden" name="phonebook[group_by]" value="site_name">

          <input type="hidden" name="review_revision" id="ucm-book-review-revision">
          <div class="alert alert-info"><strong>Publication review</strong><div id="ucm-book-review">Waiting for the current compiled membership review.</div><label id="ucm-book-review-approval" hidden><input type="checkbox" name="review_approved" id="ucm-book-review-approved" value="1"> I confirm this large audience change.</label></div>
          <div class="ucm-phonebook-toolbar">
            <div><span class="text-muted"><?php echo $phonebook['id']?'Editing':'Creating'; ?></span><h3><?php echo $h($phonebook['name'] ?: 'New phonebook'); ?></h3></div>
            <div><button class="btn btn-success" type="submit">Save &amp; publish</button><?php if($phonebook['id']): ?> <a class="btn btn-default" href="config.php?display=ucmprovisioner&amp;tab=directory&amp;action=dupphonebook&amp;book_id=<?php echo (int)$phonebook['id']; ?>#ucm-phonebook-editor">Duplicate</a> <button class="btn btn-link text-danger ucm-delete-phonebook" type="button">Delete</button><?php endif; ?></div>
          </div>
          <?php if($phonebookSyncState): $bookSyncStatus=(string)($phonebookSyncState['status']??'active'); ?>
            <div class="alert <?php echo in_array($bookSyncStatus,['active','missing_dependency'],true)?'alert-warning':'alert-info'; ?> ucm-sync-notice"><strong><?php echo _('Configuration sync:'); ?></strong> <?php echo sprintf(_('This phonebook came from %s and is currently %s.'),$h($phonebookSyncState['source_site_name']??'another site'),$h(str_replace('_',' ',$bookSyncStatus))); ?>
              <?php if(!empty($phonebookSyncState['soft_lock'])&&in_array($bookSyncStatus,['active','missing_dependency'],true)): ?><label class="checkbox-inline"><input type="checkbox" name="sync_edit_confirmed" value="1"> <?php echo _('Confirm a local save or delete; automatic sync will pause for this phonebook only.'); ?></label><?php elseif(in_array($bookSyncStatus,['active','missing_dependency'],true)): ?><div><?php echo _('A local save or delete pauses automatic sync for this phonebook only.'); ?></div><?php elseif($bookSyncStatus==='paused'): ?><div><?php echo $h($phonebookSyncState['pause_reason']??_('Automatic updates are paused.')); ?></div><?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="ucm-phonebook-block">
            <h4>1. Identity and managed output</h4>
            <div class="row"><div class="col-md-4 form-group"><label>Designer name</label><input class="form-control" name="phonebook[name]" required maxlength="64" value="<?php echo $h($phonebook['name']); ?>" placeholder="Company Directory"></div><div class="col-md-4 form-group"><label>Phone-screen title</label><input class="form-control" name="phonebook[title]" required maxlength="64" value="<?php echo $h($phonebook['title']); ?>"></div><div class="col-md-4 form-group"><label>Managed XML filename</label><input class="form-control" name="phonebook[filename]" maxlength="128" pattern="[A-Za-z0-9][A-Za-z0-9_.-]*\.xml" value="<?php echo $h($phonebook['filename']); ?>" placeholder="Automatic short filename"></div></div>
            <label class="checkbox-inline"><input type="checkbox" name="phonebook[is_default]" value="1" <?php echo !empty($phonebook['is_default'])?'checked':''; ?>> Use when a template does not select a phonebook</label>
            <input type="hidden" name="phonebook[hide_uncategorised]" value="0"><label class="checkbox-inline"><input type="checkbox" name="phonebook[hide_uncategorised]" value="1" <?php echo !empty($phonebook['hide_uncategorised'])?'checked':''; ?>> Hide Uncategorized contacts from root menu</label>
            <div class="ucm-publication-targets"><strong>Publish cached copies to</strong><label class="checkbox-inline"><input type="checkbox" id="ucm-publish-all" <?php echo in_array('*',(array)$phonebook['publish_sites'],true)?'checked':''; ?>> Every enabled site</label><div id="ucm-publish-site-list"><?php foreach($sites as $publishSite): $siteKey=(string)($publishSite['site_key']??''); ?><label class="checkbox-inline"><input type="checkbox" class="ucm-publish-site" value="<?php echo $h($siteKey); ?>" <?php echo in_array($siteKey,(array)$phonebook['publish_sites'],true)?'checked':''; ?>> <?php echo $h($publishSite['name']); ?><?php echo !empty($publishSite['is_local'])?' (local)':''; ?></label><?php endforeach; ?><?php foreach(array_diff((array)$phonebook['publish_sites'], array_column($sites,'site_key'), ['*']) as $missingSiteKey): ?><label class="checkbox-inline"><input type="checkbox" class="ucm-publish-site" value="<?php echo $h($missingSiteKey); ?>" checked> <?php echo $h($missingSiteKey); ?> — unavailable saved site</label><?php endforeach; ?></div><p class="help-block">The default is company-wide replication. Phones then use their local PBX and keep working if another site or WAN link is unavailable. Templates can deliberately select a different source PBX.</p></div>
          </div>

          <div class="ucm-phonebook-block">
            <h4>2. AD audience rules</h4><p class="help-block">Start with every enabled AD contact or include only matching contacts. Explicit exclusions below always win.</p>
            <div class="row"><div class="col-md-4 form-group"><label>Base audience</label><select class="form-control" name="phonebook[include_mode]" id="ucm-book-include-mode"><option value="all" <?php echo $phonebook['include_mode']==='all'?'selected':''; ?>>All synchronized AD contacts</option><option value="matching" <?php echo $phonebook['include_mode']==='matching'?'selected':''; ?>>Only contacts matching rules</option></select></div><div class="col-md-4 form-group"><label>When several rules exist</label><select class="form-control" id="ucm-book-rule-match"><option value="all" <?php echo ($phonebook['rules']['match']??'all')==='all'?'selected':''; ?>>Match every rule (AND)</option><option value="any" <?php echo ($phonebook['rules']['match']??'all')==='any'?'selected':''; ?>>Match any rule (OR)</option></select></div></div>
            <div id="ucm-book-rule-list">
              <?php foreach ((array)($phonebook['rules']['items']??[]) as $rule): ?><div class="ucm-book-rule-row"><select class="form-control input-sm ucm-book-rule-field"><?php foreach(['display_name'=>'Display name','label'=>'Login label','extension'=>'Extension','department'=>'Department','company'=>'Company','org_unit'=>'AD OU','site_name'=>'PBX site','mail'=>'Email','mobile'=>'Mobile','pager'=>'EMP No.'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $rule['field']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><select class="form-control input-sm ucm-book-rule-op"><?php foreach(['is'=>'is exactly','contains'=>'contains','starts_with'=>'starts with','ends_with'=>'ends with'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $rule['operator']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><input class="form-control input-sm ucm-book-rule-value" value="<?php echo $h($rule['value']); ?>"><button class="btn btn-link btn-sm ucm-book-remove-rule" type="button">Remove</button></div><?php endforeach; ?>
            </div>
            <button class="btn btn-default btn-sm" type="button" id="ucm-book-add-rule"><span class="ucm-icon-glyph" aria-hidden="true">＋</span> Add rule</button>
          </div>

          <div class="ucm-phonebook-block">
            <div class="ucm-phonebook-block-title"><div><h4 id="ucm-manual-contacts">3. Manual contacts (non-AD)</h4><p class="help-block">Use for shared desks, security, reception, external numbers, or extensions absent from AD.</p></div><button class="btn btn-default btn-sm" type="button" id="ucm-book-add-manual"><span class="ucm-icon-glyph" aria-hidden="true">＋</span> Add contact</button></div>
            <div class="table-responsive"><table class="table table-condensed ucm-manual-contact-table"><thead><tr><th>Name</th><th>Short label</th><th>Number / extension</th><th>Exact menu path</th><th>Department</th><th>PBX site</th><th>OU / hierarchy group</th><th>Company</th><th>Mobile</th><th>EMP No.</th><th>Email</th><th></th></tr></thead><tbody id="ucm-book-manual-list">
              <?php foreach ((array)$phonebook['manual_entries'] as $manual): ?><tr class="ucm-book-manual-row" data-source-key="<?php echo $h($manual['source_key'] ?? ''); ?>"><td><input class="form-control input-sm" data-field="display_name" required value="<?php echo $h($manual['display_name'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="label" value="<?php echo $h($manual['label'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="extension" required value="<?php echo $h($manual['extension'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="menu_path" value="<?php echo $h(is_array($manual['menu_path'] ?? null) ? implode(' / ', $manual['menu_path']) : ($manual['menu_path'] ?? '')); ?>" placeholder="Tehran / Security"></td><td><input class="form-control input-sm" data-field="department" value="<?php echo $h($manual['department'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="site_name" value="<?php echo $h($manual['site_name'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="org_unit" value="<?php echo $h($manual['org_unit'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="company" value="<?php echo $h($manual['company'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="mobile" value="<?php echo $h($manual['mobile'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="pager" value="<?php echo $h($manual['pager'] ?? ''); ?>"></td><td><input class="form-control input-sm" data-field="mail" value="<?php echo $h($manual['mail'] ?? ''); ?>"></td><td><button class="btn btn-link btn-sm ucm-book-remove-manual" type="button">Remove</button></td></tr><?php endforeach; ?>
            </tbody></table></div>
          </div>

          <div class="ucm-phonebook-block">
            <div class="ucm-phonebook-block-title"><div><h4>4. Individual AD contacts</h4><p class="help-block">An exclusion belongs only to this phonebook. It never edits AD and never affects extension-name synchronization.</p></div><input class="form-control input-sm" id="ucm-book-source-filter" placeholder="Filter name, number, department, site or OU"></div>
            <div class="ucm-phonebook-source-list" id="ucm-book-source-list">
              <?php foreach ($entries as $entry): $source=(string)($entry['source_key']??'');$excluded=isset($excludedKeys[$source])||isset($excludedKeys[(string)($entry['object_key']??'')]);$contactFields=array_intersect_key($entry,array_flip(['source_key','object_key','display_name','label','extension','department','company','org_unit','ou_path','site_name','mail','mobile','pager','groups','attributes']));$filter=strtolower(implode(' ',array_map(static fn($value)=>is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE):(string)$value,array_values($contactFields)))); ?>
                <div class="ucm-phonebook-source <?php echo $excluded?'is-excluded':''; ?>" data-source="<?php echo $h($source); ?>" data-filter="<?php echo $h($filter); ?>" data-contact="<?php echo $h(json_encode($contactFields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>"><span class="ucm-source-state" aria-hidden="true"><?php echo $excluded?'×':'✓'; ?></span><div><strong><?php echo $h($entry['display_name']); ?></strong> <code><?php echo $h($entry['extension']); ?></code><small><?php echo $h(implode(' · ',array_filter([$entry['label']??'', $entry['department']??'', $entry['site_name']??'', $entry['org_unit']??'']))); ?></small></div><?php if(!empty($entry['conflicted'])): ?><span class="label label-warning">shared number / identity conflict</span><?php endif; ?><button type="button" class="btn btn-xs <?php echo $excluded?'btn-success':'btn-default'; ?> ucm-book-toggle-source"><?php echo $excluded?'Include':'Exclude'; ?></button></div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="ucm-phonebook-block">
            <h4>5. Menu tree and exact phone preview</h4>
            <p class="help-block">Build up to eight navigation levels. A <strong>manual category</strong> has your label and matching rules; a <strong>dynamic split</strong> creates one submenu for every distinct OU, AD group or attribute value. Either node may contain more nodes.</p>
            <div class="alert alert-info"><strong>Four-city example:</strong> choose <em>Four-city starter</em>, rename each top category, and match its <code>attr:l</code> (city) or <code>attr:st</code> (state/province). Inside each category add a dynamic OU, group-membership, or custom-attribute split. The saved tree—not a second browser-only approximation—drives the preview and published XML.</div>
            <div class="ucm-hierarchy-toolbar"><button type="button" class="btn btn-default btn-sm" id="ucm-add-branch"><span class="ucm-icon-glyph" aria-hidden="true">▣</span> Add manual category</button> <button type="button" class="btn btn-default btn-sm" id="ucm-add-group"><span class="ucm-icon-glyph" aria-hidden="true">⑂</span> Add dynamic split</button> <button type="button" class="btn btn-primary btn-sm" id="ucm-city-starter"><span class="ucm-icon-glyph" aria-hidden="true">⌖</span> Four-city starter</button> <span class="text-muted" id="ucm-tree-depth-status"></span></div>
            <div class="ucm-hierarchy-tree" id="ucm-hierarchy-tree"></div>
            <div class="row ucm-top-gap"><div class="col-md-4 form-group"><label>Text shown on phone</label><select class="form-control ucm-book-layout" name="phonebook[name_format]"><?php foreach(['display_label'=>'Display name (short label)','display'=>'Display name','label_display'=>'Short label — display name','label'=>'Short label only','display_extension'=>'Display name (extension)'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $phonebook['name_format']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><p class="help-block">This controls the complete contact-name layout. On Cisco XML, Yealink and Grandstream, a selected template may format the <em>short label</em> ingredient; an audited final directory-label override still wins. Legacy 7940/7960 keeps the shared label because its directory URL must remain short and query-free.</p></div><div class="col-md-3 form-group"><label>Sort contacts by</label><select class="form-control ucm-book-layout" name="phonebook[sort_by]"><?php foreach(['display_name'=>'Display name','label'=>'Short label','extension'=>'Extension'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $phonebook['sort_by']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div><div class="col-md-3 form-group"><label>Cisco page size</label><select class="form-control" name="phonebook[cisco_page_size]"><?php foreach([16,24,32] as $size): ?><option value="<?php echo $size; ?>" <?php echo (int)$phonebook['cisco_page_size']===$size?'selected':''; ?>><?php echo $size; ?></option><?php endforeach; ?></select></div><div class="col-md-2 form-group"><label>Mobile number</label><select class="form-control" name="phonebook[include_mobile]"><option value="1" <?php echo !empty($phonebook['include_mobile'])?'selected':''; ?>>Publish where supported</option><option value="0" <?php echo empty($phonebook['include_mobile'])?'selected':''; ?>>Hide</option></select><p class="help-block">Adds AD/manual mobile as Yealink Phone2 and Grandstream Mobile. Cisco XML directory rows support one dial target, so Cisco keeps the extension.</p></div></div>
            <div class="row"><div class="col-md-4 form-group"><input type="hidden" name="phonebook[search_enabled]" value="0"><label class="checkbox-inline"><input type="checkbox" name="phonebook[search_enabled]" value="1" <?php echo !empty($phonebook['search_enabled'])?'checked':''; ?>> Show Search in Cisco menus</label><p class="help-block">Cisco presents separate ABC and numeric input fields. Both use the search fields selected below.</p></div><div class="col-md-3 form-group"><label>Search position</label><select class="form-control" name="phonebook[search_position]"><?php foreach(['first'=>'First','after_uncategorised'=>'After Contacts / Uncategorized','last'=>'Last'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo ($phonebook['search_position']??'first')===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div><div class="col-md-3 form-group"><label>Search appears in</label><select class="form-control" name="phonebook[search_scope]"><option value="root" <?php echo ($phonebook['search_scope']??'root')==='root'?'selected':''; ?>>Root menu only (recommended)</option><option value="all" <?php echo ($phonebook['search_scope']??'root')==='all'?'selected':''; ?>>Every category</option></select><p class="help-block">Root-only prevents Search repeating in every 7940/7960 submenu.</p></div></div>
            <div class="ucm-directory-search-policy"><h5>Searchable number fields and results</h5><div class="row"><div class="col-md-4 form-group"><input type="hidden" name="phonebook[search_extension]" value="0"><input type="hidden" name="phonebook[search_mobile]" value="0"><input type="hidden" name="phonebook[search_pager]" value="0"><label class="checkbox-inline"><input type="checkbox" name="phonebook[search_extension]" value="1" <?php echo !empty($phonebook['search_extension'])?'checked':''; ?>> Extension</label><label class="checkbox-inline"><input type="checkbox" name="phonebook[search_mobile]" value="1" <?php echo !empty($phonebook['search_mobile'])?'checked':''; ?>> Mobile</label><label class="checkbox-inline"><input type="checkbox" name="phonebook[search_pager]" value="1" <?php echo !empty($phonebook['search_pager'])?'checked':''; ?>> EMP No.</label><p class="help-block">An employee-number match finds the person but always dials their extension. Mobile can optionally be shown as a second direct-dial result.</p></div><div class="col-md-2 form-group"><label>Minimum numeric digits</label><input class="form-control" type="number" min="1" max="10" name="phonebook[numeric_min_length]" value="<?php echo max(1,min(10,(int)($phonebook['numeric_min_length']??1))); ?>"></div><div class="col-md-4 form-group"><label>When a mobile matches</label><select class="form-control" name="phonebook[mobile_result_mode]"><option value="find_extension" <?php echo ($phonebook['mobile_result_mode']??'find_extension')==='find_extension'?'selected':''; ?>>Show the person and dial their extension</option><option value="extension_and_mobile" <?php echo ($phonebook['mobile_result_mode']??'find_extension')==='extension_and_mobile'?'selected':''; ?>>Show extension plus a direct mobile row</option></select><input type="hidden" name="phonebook[mobile_editdial]" value="0"><label class="checkbox-inline"><input type="checkbox" name="phonebook[mobile_editdial]" value="1" <?php echo !empty($phonebook['mobile_editdial'])?'checked':''; ?>> Allow EditDial on direct mobile results</label></div></div></div>
            <div class="ucm-cisco-directory-softkeys">
              <h5>Cisco XML directory softkeys</h5><p class="help-block">Arrange the four possible positions for category menus and contact lists. The preview hides paging keys until another page exists. The search form itself uses the standard Search, backspace and Cancel actions plus separate ABC/123 fields. Select/Exit and Dial/Exit are retained automatically so navigation remains usable.</p>
              <?php foreach (['menu'=>'Category menu','list'=>'Contact list'] as $softView=>$softLabel): ?><div class="row"><div class="col-md-2"><strong><?php echo $softLabel; ?></strong></div><?php for($position=0;$position<4;$position++): ?><div class="col-md-2 form-group"><label>Position <?php echo $position+1; ?></label><select class="form-control input-sm ucm-directory-softkey" name="phonebook[cisco_softkeys][<?php echo $softView; ?>][]" data-softview="<?php echo $softView; ?>"><?php $allowed=$softView==='menu'?['Select','Search','Exit','Previous','Next','None']:['Dial','EditDial','Search','Exit','Previous','Next','None'];foreach($allowed as $action): ?><option value="<?php echo $action; ?>" <?php echo (($ciscoSoftkeys[$softView][$position]??'None')===$action)?'selected':''; ?>><?php echo $action==='None'?'— empty —':$action; ?></option><?php endforeach; ?></select></div><?php endfor; ?></div><?php endforeach; ?>
            </div>
            <div class="ucm-phone-preview-tabs"><button type="button" class="active" data-vendor="cisco78">Cisco 78xx</button><button type="button" data-vendor="cisco88">Cisco 88xx</button><button type="button" data-vendor="cisco_legacy">Cisco 7940/7960</button><button type="button" data-vendor="yealink">Yealink</button><button type="button" data-vendor="grandstream">Grandstream</button><span id="ucm-phone-preview-count"></span></div>
            <div class="ucm-phone-preview"><div class="ucm-phone-preview-bezel"><div class="ucm-phone-preview-title"><?php echo $h($phonebook['title']); ?></div><div class="ucm-preview-navigation"><button type="button" class="btn btn-link btn-xs" id="ucm-preview-home" title="Phonebook root" aria-label="Phonebook root"><span class="ucm-icon-glyph" aria-hidden="true">⌂</span></button><button type="button" class="btn btn-link btn-xs" id="ucm-preview-back" title="Back one menu" aria-label="Back one menu"><span class="ucm-icon-glyph" aria-hidden="true">←</span></button><span id="ucm-preview-breadcrumb">Root</span></div><div class="ucm-phone-preview-prompt" id="ucm-phone-preview-prompt">Choose a category</div><div id="ucm-phone-preview-groups"></div><div class="ucm-phone-preview-softkeys" id="ucm-phone-preview-softkeys"></div></div><div class="ucm-phone-preview-notes" id="ucm-phone-preview-notes">Loading the exact server-rendered tree…</div></div>
          </div>
        </form>
      </div>
    </div>
  </section>

  <?php if($legacyCompatibilitySource): ?>
  <section class="ucm-card" id="ucm-ad-bootstrap-card">
    <div class="ucm-card-heading"><div><h3>Optional: create the least-privilege reader</h3><p>Use this once if an AD reader does not already exist. The account is a normal domain user and is never added to a privileged group.</p></div></div>
    <div id="ucm-ad-bootstrap-explanation" class="alert alert-info"></div>
    <div class="row">
      <div class="col-md-3 form-group"><label>New reader login</label><input class="form-control" id="ucm-ad-service-sam" value="svc_ucmprovisioner"><p class="help-block">Short <code>sAMAccountName</code> for the new account.</p></div>
      <div class="col-md-5 form-group"><label>OU/container for the new reader</label><div class="input-group"><input class="form-control" id="ucm-ad-service-container" placeholder="Select an OU from the browser"><span class="input-group-btn"><button class="btn btn-default ucm-ad-browse-tree" type="button">Browse OUs</button></span></div><p class="help-block">The browser copies the exact DN; no manual OU syntax is required.</p></div>
      <div class="col-md-2 form-group"><label>Temporary AD admin username</label><input class="form-control" id="ucm-ad-admin-user" autocomplete="username" placeholder="administrator"><p class="help-block">Short login, UPN, or DOMAIN\user.</p></div>
      <div class="col-md-2 form-group"><label>Temporary AD admin password</label><input class="form-control" type="password" id="ucm-ad-admin-pass" autocomplete="new-password"><p class="help-block">Never saved or logged; cleared after each action.</p></div>
    </div>
    <button class="btn btn-warning" type="button" id="ucm-ad-create-account">Create AD reader</button>
    <span class="ucm-inline-action-feedback" aria-live="polite"></span>
    <div id="ucm-ad-bootstrap-output" class="ucm-ad-bootstrap-output" style="display:none">
      <p><strong>Run this once in PowerShell on a domain controller or an RSAT-equipped admin workstation.</strong> Then return here, save the prefilled reader credentials, and click Test.</p>
      <textarea class="form-control" id="ucm-ad-bootstrap-script" rows="8" readonly></textarea>
      <button class="btn btn-default btn-sm" type="button" id="ucm-ad-copy-script">Copy PowerShell</button><span id="ucm-ad-copy-feedback" class="text-muted"></span>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($conflicts): ?>
    <section class="ucm-card">
      <div class="ucm-card-heading"><div><h3>Conflict resolution</h3><p>Unambiguous AD site/extension changes and complete move chains reconcile automatically. A logical-binding conflict appears here only when the destination is occupied outside that accepted move set or another ambiguity prevents a safe automatic assignment.</p></div></div>
      <?php foreach ($conflicts as $conflict): ?>
        <div class="well ucm-ad-conflict" data-id="<?php echo (int)$conflict['id']; ?>">
          <?php $conflictType=(string)($conflict['conflict_type'] ?? 'duplicate_extension'); ?>
          <h4><?php echo $conflictType==='duplicate_extension'?'Duplicate phone value':($conflictType==='site_mapping'?'Ambiguous site mapping':'Logical extension binding'); ?> <code><?php echo $h($conflict['conflict_value']); ?></code> <span class="label <?php echo $conflict['status']==='open'?'label-warning':'label-info'; ?>"><?php echo $h($conflict['status']); ?></span></h4>
          <?php if ($conflictType === 'duplicate_extension'): ?>
            <table class="table table-condensed"><thead><tr><th>Winner</th><th>Display name</th><th>Login / phone label</th><th>AD phone value</th><th>Source OU</th></tr></thead><tbody>
              <?php foreach ($conflict['candidate_rows'] as $candidate): ?>
                <tr><td><input type="radio" name="winner_<?php echo (int)$conflict['id']; ?>" value="<?php echo $h($candidate['source_key'] ?? ''); ?>" <?php echo $conflict['resolution_key']===($candidate['source_key']??'')?'checked':''; ?>></td><td><?php echo $h($candidate['display_name'] ?? ''); ?></td><td><code><?php echo $h($candidate['label'] ?? ''); ?></code></td><td><code><?php echo $h($candidate['extension'] ?? ''); ?></code></td><td><?php echo $h($candidate['org_unit'] ?? ''); ?></td></tr>
              <?php endforeach; ?>
            </tbody></table>
            <button class="btn btn-default btn-sm ucm-ad-resolve-local">Keep selected locally</button>
            <?php if (!empty($conflict['resolution_key'])): ?><button class="btn btn-link btn-sm ucm-ad-resolve-clear">Remove local decision &amp; check AD again</button><?php endif; ?>
            <p class="help-block">To correct the phone value itself, update the authoritative AD object with your normal AD administration tools, then run Sync now.</p>
          <?php elseif ($conflictType === 'logical_binding'): $move=(array)($conflict['reconciliation']??[]); ?>
            <?php foreach ($conflict['candidate_rows'] as $candidate): ?><p><strong><?php echo $h($candidate['label'] ?? $candidate['source_key'] ?? 'AD object'); ?></strong> <code><?php echo $h($candidate['source_key'] ?? ''); ?></code></p><?php endforeach; ?>
            <?php if(!empty($move['available'])): ?>
              <label class="ucm-choice-card"><input type="checkbox" class="ucm-logical-reconcile-select" value="<?php echo (int)$conflict['id']; ?>"> <span><strong>Include this reviewed move</strong><small>Only the AD binding moves; phones, groups and per-extension overrides stay with their existing site/number slots.</small></span></label>
              <div class="table-responsive ucm-top-gap"><table class="table table-condensed"><thead><tr><th>Current binding</th><th>AD now requires</th><th>Destination occupant</th></tr></thead><tbody><tr><td><strong><?php echo $h(($move['current']['site_name']??'Site').' / '.($move['current']['extension']??'')); ?></strong></td><td><strong><?php echo $h(($move['desired']['site_name']??'Site').' / '.($move['desired']['extension']??'')); ?></strong></td><td><?php if(!empty($move['occupant'])): ?><span class="text-warning"><?php echo $h($move['occupant']['display_name']??'Another AD object'); ?></span><br><small>Select that person's open move in the same transaction, or first free this destination in AD.</small><?php else: ?><span class="text-success">Free or already this identity</span><?php endif; ?></td></tr></tbody></table></div>
            <?php else: ?><div class="alert alert-danger"><?php echo $h($move['message']??'This binding cannot currently be reconciled.'); ?></div><?php endif; ?>
            <p class="help-block">Temporary and permanent site/number changes use the same reviewed action. No phone configuration is rebuilt by reconciliation itself.</p>
          <?php else: ?>
            <?php foreach ($conflict['candidate_rows'] as $candidate): ?>
              <p><strong><?php echo $h($candidate['label'] ?? $candidate['source_key'] ?? 'AD object'); ?></strong> <code><?php echo $h($candidate['source_key'] ?? ''); ?></code></p>
              <?php if (!empty($candidate['mapping']['message'])): ?><p><?php echo $h($candidate['mapping']['message']); ?><?php if(!empty($candidate['mapping']['matched_rules'])): ?> Matched: <code><?php echo $h(implode(', ', (array)$candidate['mapping']['matched_rules'])); ?></code>.<?php endif; ?></p><?php endif; ?>
              <?php if (!empty($candidate['message'])): ?><p><?php echo $h($candidate['message']); ?></p><?php endif; ?>
            <?php endforeach; ?>
            <p class="help-block">Correct the ordered AD site rules above, then synchronize again. A local winner is intentionally unavailable because AD mapping is authoritative.</p>
          <?php endif; ?>
          <span class="ucm-inline-action-feedback" aria-live="polite"></span>
        </div>
      <?php endforeach; ?>
      <?php if(array_filter($conflicts,static fn(array $conflict):bool=>(string)($conflict['conflict_type']??'')==='logical_binding'&&(string)($conflict['status']??'')==='open')): ?><div class="ucm-card-actions"><button type="button" class="btn btn-primary" id="ucm-ad-reconcile-selected">Reconcile selected AD moves</button><span class="help-block">Select every dependent move in a chain. The complete batch is validated and either all bindings change or none do.</span></div><?php endif; ?>
    </section>
  <?php endif; ?>

</div>

<div class="modal fade" id="ucm-ad-tree-modal" tabindex="-1" role="dialog" aria-labelledby="ucm-ad-tree-title">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="ucm-ad-tree-title">Browse Active Directory OUs and containers</h4></div>
    <div class="modal-body">
      <div class="row"><div class="col-md-7"><input class="form-control" id="ucm-ad-tree-query" placeholder="Search OU name, container, or full DN"></div><div class="col-md-5 text-right"><button class="btn btn-default btn-sm" type="button" id="ucm-ad-expand-all">Expand all</button> <button class="btn btn-default btn-sm" type="button" id="ucm-ad-collapse-all">Collapse all</button> <span id="ucm-ad-tree-status" class="text-muted"></span></div></div>
      <div id="ucm-ad-tree" class="ucm-ad-tree"></div>
      <p class="help-block"><strong>Use as search base</strong> chooses which users are synchronized. <strong>Put reader here</strong> chooses where the optional service account is created.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div>
  </div></div>
</div>

<script>
jQuery(function($){
  function scheduleValue(editor){var box=$(editor),mode=String(box.find('[data-schedule-mode]').val()||'interval'),minutes=parseInt(box.find('[data-schedule-interval],input[name="source[schedule_minutes]"]').val(),10)||5,time=String(box.find('[data-schedule-time],input[name="source[schedule_time]"]').val()||'02:00'),days=box.find('[data-schedule-weekday]:checked,input[name="source[schedule_weekdays][]"]:checked').map(function(){return parseInt(this.value,10);}).get();return{mode:mode,interval_minutes:minutes,time:time,weekdays:mode==='weekly'?days:[1,2,3,4,5,6,7]};}
  function refreshSchedule(editor){var box=$(editor),value=scheduleValue(box),target=String(box.data('json-target')||'');box.find('[data-schedule-field="interval"]').prop('hidden',value.mode!=='interval');box.find('[data-schedule-field="clock"]').prop('hidden',['daily','weekly'].indexOf(value.mode)===-1);box.find('[data-schedule-field="weekly"]').prop('hidden',value.mode!=='weekly');if(target)$(target).val(JSON.stringify(value));if(target==='#ucm-legacy-schedule-json')$('#ucm-legacy-schedule-minutes').val(value.mode==='manual'?0:(value.mode==='daily'?1440:(value.mode==='weekly'?10080:value.interval_minutes)));return value;}
  $('[data-schedule-editor]').each(function(){refreshSchedule(this);});
  $(document).on('input change','[data-schedule-editor] :input',function(){refreshSchedule($(this).closest('[data-schedule-editor]'));});
  $('[data-schedule-editor]').closest('form').on('submit.ucmpSchedule',function(event){var valid=true;$(this).find('[data-schedule-editor]').each(function(){var value=refreshSchedule(this);if(value.mode==='weekly'&&!value.weekdays.length)valid=false;});if(!valid){event.preventDefault();alert('Choose at least one weekday for each weekly directory schedule.');}});
  var adTreeNodes=[], treeExpanded={}, initialHierarchy=<?php echo json_encode($phonebook['hierarchy'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>, previewData=null, previewPath=[], previewTimer=null, previewDirect=false, previewSearch=false, previewSearchMode='text', previewSearchQuery='';
  function result(message,ok,detail,$button){var $r=$('#ucm-directory-result').removeClass('alert-info alert-danger alert-success').addClass('alert '+(ok?'alert-success':'alert-danger')).empty().append($('<div>').text(message||'Done.'));if(detail)$r.append($('<pre class="ucm-operation-detail">').text(detail));if($button&&$button.length){$button.closest('.ucm-card,.well').find('.ucm-inline-action-feedback').first().removeClass('text-danger text-success text-info').addClass(ok?'text-success':'text-danger').text(message||'Done.');}}
  var directoryRequestBusy=false;
  function request(data,$button,onDone){if(directoryRequestBusy)return;directoryRequestBusy=true;$button.prop('disabled',true);result('Working…',true,'',$button);return window.ucmpAjax({data:data}).done(function(resp){var r=(resp&&resp.result)||{},ok=!!(resp&&resp.status&&r.ok!==false),detail=r.sample?JSON.stringify(r.sample,null,2):(r.sources?JSON.stringify(r.sources,null,2):'');result(r.message||(resp&&resp.message)||(ok?'Done.':'Failed'),ok,detail,$button);if(ok&&onDone)onDone(r);if(ok&&(data.command==='adsync'||data.command==='adsyncsource'||data.command==='adresolve'))$(document).trigger('ucmp-directory-refresh');}).fail(function(xhr){result((xhr.responseJSON&&xhr.responseJSON.message)||'Request failed',false,'',$button);}).always(function(){directoryRequestBusy=false;$button.prop('disabled',false);$('#ucm-ad-admin-pass').val('');});}
  function formData(command){var data={command:command};$('#ucm-directory-form').serializeArray().forEach(function(x){data[x.name]=x.value;});return data;}
  function updateSecurity(){var security=$('#ucm-ad-security').val(),plain=security==='ldap',message=plain?'LDAP/389 is supported for synchronization, but bind credentials travel without TLS. Keep it on a trusted management network. AD will not accept password creation over this connection.':'This encrypted connection supports both normal synchronization and direct reader-account creation.';$('#ucm-ad-security-note').toggleClass('alert-warning',plain).toggleClass('alert-success',!plain).text(message);$('#ucm-ad-create-account').text(plain?'Generate PowerShell bootstrap':'Create AD reader directly');$('#ucm-ad-bootstrap-explanation').html(plain?'<strong>No AD CS is required.</strong> The module will generate a one-time PowerShell command containing a new random password. Your administrator password is not needed for generation; it is useful only for browsing before the reader exists.':'The module can create and enable the reader directly over this encrypted connection. Enter temporary AD credentials with permission to create a user in the selected OU; they are never stored.');}
  $('#ucm-ad-security').on('change',function(){var old=$(this).data('previous')||'ldaps',oldPort=old==='ldaps'?'636':'389',newPort=$(this).val()==='ldaps'?'636':'389';if($('#ucm-ad-port').val()===oldPort)$('#ucm-ad-port').val(newPort);$(this).data('previous',$(this).val());updateSecurity();}).data('previous',$('#ucm-ad-security').val());updateSecurity();

  function key(dn){return String(dn||'').toLowerCase();}
  function renderAdTree(){var q=$.trim($('#ucm-ad-tree-query').val()).toLowerCase(),byDn={},children={},visibleSet={},shown=0,$tree=$('#ucm-ad-tree').empty();adTreeNodes.forEach(function(n){byDn[key(n.dn)]=n;children[key(n.dn)]=[];});adTreeNodes.forEach(function(n){var parent=key(n.parent);if(children[parent])children[parent].push(n);});Object.keys(children).forEach(function(k){children[k].sort(function(a,b){return String(a.label).localeCompare(String(b.label));});});if(q){adTreeNodes.forEach(function(n){var hay=(String(n.label)+' '+String(n.dn)+' '+String(n.type)).toLowerCase();if(hay.indexOf(q)===-1)return;var current=n;while(current){visibleSet[key(current.dn)]=true;current=byDn[key(current.parent)];}});}var roots=adTreeNodes.filter(function(n){return !byDn[key(n.parent)];}).sort(function(a,b){return String(a.label).localeCompare(String(b.label));});function visit(node,level){var nodeKey=key(node.dn),kids=children[nodeKey]||[],expanded=q?true:!!treeExpanded[nodeKey];if(q&&!visibleSet[nodeKey])return;shown++;var row=$('<div class="ucm-ad-tree-row">').css('padding-left',(8+level*20)+'px'),toggle=$('<button type="button" class="btn btn-link btn-xs ucm-ad-tree-toggle" aria-label="Expand or collapse">').attr('data-dn',node.dn).text(kids.length?(expanded?'▼':'▶'):'');if(!kids.length)toggle.prop('disabled',true);row.append(toggle,$('<span class="label label-default">').text(node.type),$('<strong>').text(node.label),$('<code>').attr('title',node.dn).text(node.dn),$('<span class="ucm-ad-tree-actions">').append($('<button type="button" class="btn btn-xs btn-primary ucm-ad-use-base">').attr('data-dn',node.dn).text('Use as search base'),$('<button type="button" class="btn btn-xs btn-default ucm-ad-use-container">').attr('data-dn',node.dn).text('Put reader here')));$tree.append(row);if(expanded)kids.forEach(function(child){visit(child,level+1);});}roots.forEach(function(root){if(treeExpanded[key(root.dn)]===undefined)treeExpanded[key(root.dn)]=true;visit(root,0);});if(!shown)$tree.append($('<p class="text-muted ucm-ad-tree-empty">').text('No matching OUs or containers.'));$('#ucm-ad-tree-status').text(shown+' visible / '+adTreeNodes.length+' loaded');}
  $('.ucm-ad-browse-tree').on('click',function(){var $button=$(this),data=formData('adtree');if($('#ucm-ad-admin-user').val()){data.ad_admin_user=$('#ucm-ad-admin-user').val();data.ad_admin_pass=$('#ucm-ad-admin-pass').val();}$button.prop('disabled',true);$('#ucm-ad-tree-modal').modal('show');$('#ucm-ad-tree').empty().append($('<p class="text-muted ucm-ad-tree-empty">').text('Loading directory hierarchy…'));$('#ucm-ad-tree-status').text('Loading…');window.ucmpAjax({data:data}).done(function(resp){var r=(resp&&resp.result)||{};if(!(resp&&resp.status&&r.ok!==false)){result(r.message||(resp&&resp.message)||'Directory browse failed',false,'',$button);return;}adTreeNodes=r.nodes||[];treeExpanded={};if(!$('#ucm-ad-base-dn').val()&&r.base_dn)$('#ucm-ad-base-dn').val(r.base_dn);renderAdTree();result(r.message,true,'',$button);}).fail(function(xhr){result((xhr.responseJSON&&xhr.responseJSON.message)||'Directory browse failed',false,'',$button);}).always(function(){$button.prop('disabled',false);$('#ucm-ad-admin-pass').val('');});});
  $('#ucm-ad-tree-query').on('input',renderAdTree);$('#ucm-ad-tree').on('click','.ucm-ad-tree-toggle',function(){var k=key($(this).data('dn'));treeExpanded[k]=!treeExpanded[k];renderAdTree();}).on('click','.ucm-ad-use-base',function(){$('#ucm-ad-base-dn').val($(this).data('dn')).focus();$('#ucm-ad-tree-modal').modal('hide');result('User search base selected. Save directory settings to keep it.',true);}).on('click','.ucm-ad-use-container',function(){$('#ucm-ad-service-container').val($(this).data('dn')).focus();$('#ucm-ad-tree-modal').modal('hide');result('Reader-account OU selected.',true);});
  $('#ucm-ad-expand-all').on('click',function(){adTreeNodes.forEach(function(n){treeExpanded[key(n.dn)]=true;});renderAdTree();});$('#ucm-ad-collapse-all').on('click',function(){treeExpanded={};renderAdTree();});
  $('#ucm-ad-test').on('click',function(){request(formData('adtest'),$(this));});
  $('#ucm-ad-sync').on('click',function(){request({command:'adsync'},$(this));});
  $('.ucm-directory-source-test').on('click',function(){request({command:'adtestsource',source_id:$(this).data('source-id')},$(this));});
  $('.ucm-directory-source-sync').on('click',function(){request({command:'adsyncsource',source_id:$(this).data('source-id')},$(this));});
  $('#ucm-ad-create-account').on('click',function(){var plain=$('#ucm-ad-security').val()==='ldap',prompt=plain?'Generate a PowerShell command and a new random reader password?':'Create a normal AD user with no privileged group membership?';if(!confirm(prompt))return;var data=formData('adcreateaccount');data.service_sam=$('#ucm-ad-service-sam').val();data.service_container_dn=$('#ucm-ad-service-container').val();data.ad_admin_user=$('#ucm-ad-admin-user').val();data.ad_admin_pass=$('#ucm-ad-admin-pass').val();request(data,$(this),function(r){if(r.bind_user)$('#ucm-ad-bind-user').val(r.bind_user);if(r.generated_password)$('#ucm-ad-bind-password').val(r.generated_password);if(r.bootstrap_script){$('#ucm-ad-bootstrap-script').val(r.bootstrap_script);$('#ucm-ad-bootstrap-output').show();}});});
  $('#ucm-ad-copy-script').on('click',function(){var text=$('#ucm-ad-bootstrap-script').val(),$feedback=$('#ucm-ad-copy-feedback');if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(function(){$feedback.text(' Copied.');},function(){$feedback.text(' Copy failed; select the text manually.');});}else{$('#ucm-ad-bootstrap-script').select();document.execCommand('copy');$feedback.text(' Copied.');}});
  $('.ucm-ad-resolve-local').on('click',function(){var $c=$(this).closest('.ucm-ad-conflict'),winner=$c.find('input[type=radio]:checked').val();if(!winner){result('Choose the correct AD user first.',false,'',$(this));return;}request({command:'adresolve',mode:'local',conflict_id:$c.data('id'),winner:winner},$(this));});
  $('.ucm-ad-resolve-clear').on('click',function(){var $c=$(this).closest('.ucm-ad-conflict');if(!confirm('Remove this local winner and re-check the current AD values?'))return;request({command:'adresolve',mode:'clear',conflict_id:$c.data('id')},$(this));});
  $('#ucm-ad-reconcile-selected').on('click',function(){var ids=$('.ucm-logical-reconcile-select:checked').map(function(){return String($(this).val());}).get();if(!ids.length){result('Select at least one logical-binding move.',false,'',$(this));return;}if(!confirm('Reconcile '+ids.length+' reviewed AD binding move(s)? Phones, groups and per-extension overrides will not move or rebuild.'))return;request({command:'adresolve',mode:'reconcile',conflict_ids:ids.join(',')},$(this));});
  function addRule(rule){rule=rule||{};var fields={display_name:'Display name',label:'Login label',extension:'Extension',department:'Department',company:'Company',org_unit:'AD OU',site_name:'PBX site',mail:'Email',mobile:'Mobile',pager:'EMP No.'},ops={is:'is exactly',contains:'contains',starts_with:'starts with',ends_with:'ends with'},row=$('<div class="ucm-book-rule-row">'),field=$('<select class="form-control input-sm ucm-book-rule-field">'),op=$('<select class="form-control input-sm ucm-book-rule-op">');$.each(fields,function(k,v){field.append($('<option>').val(k).text(v));});$.each(ops,function(k,v){op.append($('<option>').val(k).text(v));});field.val(rule.field||'department');op.val(rule.operator||'is');row.append(field,op,$('<input class="form-control input-sm ucm-book-rule-value">').val(rule.value||''),$('<button class="btn btn-link btn-sm ucm-book-remove-rule" type="button">').text('Remove'));$('#ucm-book-rule-list').append(row);}
  function rules(){var items=[];$('#ucm-book-rule-list .ucm-book-rule-row').each(function(){var r=$(this),value=$.trim(r.find('.ucm-book-rule-value').val());if(value)items.push({field:r.find('.ucm-book-rule-field').val(),operator:r.find('.ucm-book-rule-op').val(),value:value});});return {match:$('#ucm-book-rule-match').val()||'all',items:items};}
  $('#ucm-identity-overrides .ucm-chosen').chosen({width:'100%',allow_single_deselect:true,search_contains:true,disable_search_threshold:0});
  $('.ucm-identity-field-toggle').on('change',function(){$(this).closest('.ucm-identity-override-field').find('input[name]').prop('disabled',!this.checked);});
  $('#ucm-identity-overrides form').first().on('submit',function(event){if(!$(this).find('.ucm-identity-field-toggle:checked').length){event.preventDefault();alert('Select at least one identity field.');}});
  function manualEntries(){var out=[];$('#ucm-book-manual-list .ucm-book-manual-row').each(function(){var row={source_key:String($(this).data('source-key')||'')};$(this).find('[data-field]').each(function(){row[$(this).data('field')]=$.trim($(this).val());});row.label=row.label||row.display_name;out.push(row);});return out;}
  var retainedExclusions=new Set(<?php echo json_encode(array_values((array)($phonebook['exclusions']??[])),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);
  function exclusions(){return Array.from(retainedExclusions);}
  function parseAliases(text){var out={};String(text||'').split(/\r?\n/).forEach(function(line){var at=line.indexOf('=');if(at<1)return;var raw=$.trim(line.slice(0,at)),label=$.trim(line.slice(at+1));if(raw&&label)out[raw]=label;});return out;}
  function aliasText(aliases){var lines=[];$.each(aliases||{},function(raw,label){lines.push(raw+'='+label);});return lines.join('\n');}
  function hierarchyNodes($container){
    var out=[];
    $container.children('.ucm-hnode').each(function(){
      var $node=$(this),type=$node.data('type'),$body=$node.children('.ucm-hnode-body'),children=hierarchyNodes($node.children('.ucm-hnode-children'));
      if(type==='branch'){
        var items=[];
        $body.children('.ucm-branch-rules').children('.ucm-branch-rule').each(function(){var r=$(this),source=$.trim(r.find('.ucm-rule-source').val()),operator=r.find('.ucm-rule-operator').val()||'is',value=$.trim(r.find('.ucm-rule-value').val());if(source&&(value||operator==='present'||operator==='not_present'))items.push({source:source,operator:operator,value:value});});
        out.push({type:'branch',label:$.trim($body.find('.ucm-hnode-label').first().val())||'Category',when:{match:$body.find('.ucm-branch-match').first().val()||'any',items:items},children:children});
      }else{
        out.push({type:'group',source:$.trim($body.find('.ucm-hnode-source').first().val())||'department',fallback:$.trim($body.find('.ucm-hnode-fallback').first().val())||'Other',aliases:parseAliases($body.find('.ucm-hnode-aliases').first().val()),children:children});
      }
    });
    return out;
  }
  function hierarchy(){return {version:2,children:hierarchyNodes($('#ucm-hierarchy-tree'))};}
  function publishSites(){if($('#ucm-publish-all').is(':checked'))return ['*'];var out=[];$('.ucm-publish-site:checked').each(function(){out.push(String($(this).val()));});return out;}
  function serializeBook(){$('#ucm-phonebook-rules').val(JSON.stringify(rules()));$('#ucm-phonebook-exclusions').val(JSON.stringify(exclusions()));$('#ucm-phonebook-manual').val(JSON.stringify(manualEntries()));$('#ucm-phonebook-hierarchy').val(JSON.stringify(hierarchy()));$('#ucm-phonebook-publish-sites').val(JSON.stringify(publishSites()));}
  var hierarchySources=[['site_name','PBX site'],['ou_path','OU subtree (includes descendants)'],['ou:0','Closest meaningful OU'],['ou:1','Parent OU'],['ou:2','Grandparent OU'],['ou:3','Fourth OU'],['ou:4','Fifth OU'],['ou:5','Sixth OU'],['groups','AD group memberships'],['department','Mapped department'],['company','Mapped company'],['org_unit','Closest OU (legacy)'],['attr:l','AD city (l)'],['attr:st','AD state/province (st)'],['attr:physicalDeliveryOfficeName','Office location'],['attr:division','Division']];
  function sourceInput(value,cls){var input=$('<input class="form-control input-sm">').addClass(cls).attr({list:'ucm-hierarchy-source-list',placeholder:'Choose or type attr:exactName'}).val(value||'');return input;}
  function branchRule(rule){rule=rule||{};var row=$('<div class="ucm-branch-rule">'),op=$('<select class="form-control input-sm ucm-rule-operator">');$.each({is:'is exactly',under:'is inside OU subtree',contains:'contains',starts_with:'starts with',ends_with:'ends with',present:'has any value',not_present:'is empty'},function(k,v){op.append($('<option>').val(k).text(v));});op.val(rule.operator||(rule.source==='ou_path'?'under':'is'));row.append(sourceInput(rule.source||'attr:l','ucm-rule-source'),op,$('<input class="form-control input-sm ucm-rule-value" placeholder="Value, e.g. Example Corporation / CEO / CFO">').val(rule.value||''),$('<button type="button" class="btn btn-link btn-sm ucm-branch-rule-remove">').text('Remove'));return row;}
  function nodeCard(node){node=node||{};var type=node.type==='branch'?'branch':'group',card=$('<div class="ucm-hnode">').attr('data-type',type).data('type',type),head=$('<div class="ucm-hnode-head">').append($('<span class="label">').addClass(type==='branch'?'label-primary':'label-info').text(type==='branch'?'Manual category':'Dynamic split'),$('<span class="ucm-hnode-summary">')),actions=$('<div class="btn-group btn-group-xs">').append('<button type="button" class="btn btn-default ucm-hnode-up" title="Move up" aria-label="Move up"><span class="ucm-icon-glyph" aria-hidden="true">↑</span></button><button type="button" class="btn btn-default ucm-hnode-down" title="Move down" aria-label="Move down"><span class="ucm-icon-glyph" aria-hidden="true">↓</span></button><button type="button" class="btn btn-danger ucm-hnode-remove" title="Remove" aria-label="Remove"><span class="ucm-icon-glyph" aria-hidden="true">×</span></button>'),body=$('<div class="ucm-hnode-body">'),children=$('<div class="ucm-hnode-children">'),childTools=$('<div class="ucm-hnode-child-tools">').append('<button type="button" class="btn btn-link btn-xs ucm-hnode-add-branch">+ nested category</button><button type="button" class="btn btn-link btn-xs ucm-hnode-add-group">+ nested dynamic split</button>');head.append(actions);if(type==='branch'){var match=$('<select class="form-control input-sm ucm-branch-match"><option value="any">Any condition (OR)</option><option value="all">Every condition (AND)</option></select>').val((node.when||{}).match||'any'),rulesBox=$('<div class="ucm-branch-rules">');((node.when||{}).items||[]).forEach(function(rule){rulesBox.append(branchRule(rule));});if(!rulesBox.children().length)rulesBox.append(branchRule({source:'attr:l',operator:'is'}));body.append($('<div class="ucm-hnode-main-fields">').append($('<label>').text('Phone menu label').append($('<input class="form-control input-sm ucm-hnode-label" placeholder="Tehran">').val(node.label||'')),$('<label>').text('Contact matches').append(match)),$('<div class="ucm-hnode-subtitle">').text('Conditions'),rulesBox,$('<button type="button" class="btn btn-default btn-xs ucm-branch-rule-add">').text('+ condition'));}else{body.append($('<div class="ucm-hnode-main-fields">').append($('<label>').text('Split contacts by').append(sourceInput(node.source||'department','ucm-hnode-source')),$('<label>').text('Empty value appears as').append($('<input class="form-control input-sm ucm-hnode-fallback">').val(node.fallback||'Other'))),$('<label class="ucm-hnode-alias-label">').text('Optional value renames (one raw=value per line)').append($('<textarea class="form-control input-sm ucm-hnode-aliases" rows="2" placeholder="Tehran Province=Tehran\nHelpdesk-Team=Support">').val(aliasText(node.aliases))));}card.append(head,body.append(childTools),children);(node.children||[]).forEach(function(child){children.append(nodeCard(child));});return card;}
  function normalizedHierarchy(value){if(typeof value==='string'){try{value=JSON.parse(value);}catch(e){value=[];}}if(value&&Number(value.version)===2&&Array.isArray(value.children))return value;var levels=Array.isArray(value)?value:[],children=[];for(var i=levels.length-1;i>=0;i--){children=[{type:'group',source:levels[i].source||levels[i].field||'department',fallback:levels[i].fallback||'Other',aliases:{},children:children}];}return {version:2,children:children};}
  function renderHierarchy(value){var tree=normalizedHierarchy(value),$root=$('#ucm-hierarchy-tree').empty();tree.children.forEach(function(node){$root.append(nodeCard(node));});refreshHierarchyMeta();}
  function maxTreeDepth(){var max=0;$('#ucm-hierarchy-tree .ucm-hnode').each(function(){max=Math.max(max,$(this).parents('.ucm-hnode').length+1);});return max;}
  function refreshHierarchyMeta(){$('#ucm-hierarchy-tree .ucm-hnode').each(function(){var $n=$(this),type=$n.data('type'),$body=$n.children('.ucm-hnode-body'),summary=type==='branch'?($body.find('.ucm-hnode-label').first().val()||'Unnamed category'):($body.find('.ucm-hnode-source').first().val()||'Choose a source');$n.children('.ucm-hnode-head').find('.ucm-hnode-summary').text(summary);});$('#ucm-tree-depth-status').text($('#ucm-hierarchy-tree .ucm-hnode').length+' nodes · '+maxTreeDepth()+'/8 levels');}
  function directorySoftkeys(view){var out=[];$('[data-softview="'+view+'"]').each(function(){out.push($(this).val());});return out;}
  function draft(){return {id:$('[name="phonebook[id]"]').val(),title:$('[name="phonebook[title]"]').val(),include_mode:$('#ucm-book-include-mode').val(),rules:rules(),exclusions:exclusions(),manual_entries:manualEntries(),hierarchy:hierarchy(),name_format:$('[name="phonebook[name_format]"]').val(),sort_by:$('[name="phonebook[sort_by]"]').val(),include_mobile:$('[name="phonebook[include_mobile]"]').val(),cisco_page_size:$('[name="phonebook[cisco_page_size]"]').val(),hide_uncategorised:$('[name="phonebook[hide_uncategorised]"]').is(':checked')?1:0,search_enabled:$('[name="phonebook[search_enabled]"]').is(':checked')?1:0,search_position:$('[name="phonebook[search_position]"]').val(),search_scope:$('[name="phonebook[search_scope]"]').val(),search_extension:$('[name="phonebook[search_extension]"]').is(':checked')?1:0,search_mobile:$('[name="phonebook[search_mobile]"]').is(':checked')?1:0,search_pager:$('[name="phonebook[search_pager]"]').is(':checked')?1:0,numeric_min_length:Number($('[name="phonebook[numeric_min_length]"]').val()||1),mobile_result_mode:$('[name="phonebook[mobile_result_mode]"]').val(),mobile_editdial:$('[name="phonebook[mobile_editdial]"]').is(':checked')?1:0,cisco_softkeys:{menu:directorySoftkeys('menu'),list:directorySoftkeys('list')}};}
  function nodeById(id){if(!previewData)return null;var nodes=previewData.nodes||{},node=nodes[id];if(node)return node;if(Array.isArray(nodes))return nodes.filter(function(n){return String(n.id)===String(id);})[0]||null;return null;}
  function childIds(node){return (node.child_ids||node.children||[]).map(function(x){return typeof x==='object'?x.id:x;});}
  function contactObjects(node){var contacts=previewData.contacts||{},values=node.contact_ids||node.contacts||[];return values.map(function(x){return typeof x==='object'?x:contacts[x];}).filter(Boolean);}
  function contactIdsUnder(id,seen){seen=seen||{};if(seen[id])return [];seen[id]=true;var node=nodeById(id),ids=[];if(!node)return ids;(node.contact_ids||node.contacts||[]).forEach(function(value){ids.push(typeof value==='object'?value.source_key:value);});childIds(node).forEach(function(child){ids=ids.concat(contactIdsUnder(child,seen));});return ids.filter(function(value,index,all){return all.indexOf(value)===index;});}
  function previewContactName(c){return c.shown_name||c.name||c.display_name||c.label||'';}
  function renderPreviewSoftkeys(layout,vendor,state){var $bar=$('#ucm-phone-preview-softkeys').empty(),keys=[];if(vendor.indexOf('cisco')!==0)return;if(state.search)keys=['Search','<<','Cancel'];else if(vendor==='cisco_legacy')keys=state.list?['Dial','EditDial','Exit']:['Select',state.root?'Exit':'Back'];else if(state.root&&!state.list&&layout.search_enabled!==false)keys=['Select','Search'];else{var defaults=state.list?['Dial','EditDial','Exit','Next']:['Select','Exit','Previous','Next'];(((layout.cisco_softkeys||{})[state.list?'list':'menu'])||defaults).forEach(function(action){if(action==='None'||(action==='Search'&&layout.search_enabled===false)||(action==='EditDial'&&state.directMobile&&!layout.mobile_editdial)||(action==='Previous'&&!state.hasPrevious)||(action==='Next'&&!state.hasNext))return;keys.push(action==='Exit'&&!state.list&&!state.root?'Back':action);});}keys.forEach(function(action){$bar.append($('<span>').text(action));});}
  function renderBookPreview(){
    var $out=$('#ucm-phone-preview-groups').empty(),rootId=previewData&&(previewData.root_id||previewData.root),currentId=previewPath.length?previewPath[previewPath.length-1]:rootId,node=nodeById(currentId),vendor=$('.ucm-phone-preview-tabs button.active').data('vendor')||'cisco78',isCisco=vendor.indexOf('cisco')===0,children=childIds(node||{}),directContacts=contactObjects(node||{}),directLabel=currentId===rootId?'Uncategorised':'Contacts',layout=(previewData&&previewData.layout)||{},pageSize=Number(layout.cisco_page_size)||32,hideRoot=!!layout.hide_uncategorised,showSearch=isCisco&&layout.search_enabled!==false&&((layout.search_scope||'root')==='all'||currentId===rootId),searchPosition=layout.search_position||'first';
    if(!node){$out.append($('<div class="ucm-phone-preview-empty">').text('No contacts match this design.'));$('#ucm-preview-breadcrumb').text('Root');return;}
    var crumbs=['Root'];previewPath.forEach(function(id){var n=nodeById(id);if(n)crumbs.push(n.title||n.label||'Menu');});if(previewDirect)crumbs.push(directLabel);$('#ucm-preview-breadcrumb').text(crumbs.join(' / '));
    function addSearch(){if(showSearch)$out.append($('<button type="button" class="ucm-phone-preview-menu ucm-preview-search">').append($('<span>').text('Search'),$('<b>').text('name / login / extension'),$('<i>').text('›')));}
    function addDirect(){if(children.length&&directContacts.length&&!(currentId===rootId&&hideRoot))$out.append($('<button type="button" class="ucm-phone-preview-menu ucm-preview-direct">').append($('<span>').text(directLabel),$('<b>').text(directContacts.length),$('<i>').text('›')));}
    function addContact(c){var number=c.number||c.extension||'',name=previewContactName(c)+(c.search_suffix||'');if(!isCisco&&layout.include_mobile&&c.mobile)number+=(number?' / ':'')+c.mobile;$out.append($('<div class="ucm-phone-preview-contact">').append($('<span>').text(name),$('<code>').text(number)));}
    var matches=[];
    if(previewSearch){
      var contacts=previewData.contacts||{},needle=previewSearchQuery.toLowerCase(),digit=function(v){return String(v||'').replace(/[۰-۹]/g,function(x){return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(x));}).replace(/[٠-٩]/g,function(x){return String('٠١٢٣٤٥٦٧٨٩'.indexOf(x));}).replace(/\D/g,'');},numberNeedle=digit(needle),minimum=Number(layout.numeric_min_length||1),tooShort=previewSearchMode==='number'&&numberNeedle.length>0&&numberNeedle.length<minimum;
      contactIdsUnder(currentId).map(function(id){return contacts[id];}).filter(Boolean).forEach(function(c){if(!needle){matches.push(c);return;}var hasDigits=numberNeedle!=='',ext=hasDigits&&!!layout.search_extension&&digit(c.extension).indexOf(numberNeedle)!==-1,mobile=hasDigits&&!!layout.search_mobile&&digit(c.mobile).indexOf(numberNeedle)!==-1,pager=hasDigits&&!!layout.search_pager&&digit(c.pager).indexOf(numberNeedle)!==-1,textHay=[c.display_name,c.label,c.canonical_label,c.mail,c.department,c.company,c.org_unit,c.site_name].join(' ').toLowerCase(),matched=previewSearchMode==='number'?!tooShort&&(ext||mobile||pager):textHay.indexOf(needle)!==-1||(numberNeedle.length>=minimum&&(ext||mobile||pager));if(!matched)return;var base=$.extend({},c),suffix=pager&&!ext&&!mobile?' — EMP No. match':(mobile&&!ext?' — Mobile match':'');base.search_suffix=suffix;matches.push(base);if(mobile&&layout.mobile_result_mode==='extension_and_mobile'&&c.mobile){var direct=$.extend({},c,{number:c.mobile,search_suffix:' — Mobile',direct_mobile:true});matches.push(direct);}});
      var $modes=$('<div class="btn-group btn-group-xs" style="margin-bottom:6px">').append($('<button type="button" class="btn ucm-preview-search-mode" data-mode="text">').toggleClass('btn-primary',previewSearchMode==='text').toggleClass('btn-default',previewSearchMode!=='text').text('ABC Name, login or number'),$('<button type="button" class="btn ucm-preview-search-mode" data-mode="number">').toggleClass('btn-primary',previewSearchMode==='number').toggleClass('btn-default',previewSearchMode!=='number').text('123 Ext, mobile or EMP No.'));
      var $input=$('<input class="form-control" id="ucm-preview-search-input">').attr('placeholder',previewSearchMode==='number'?'Type number digits':'Type a name, login or number').attr('inputmode',previewSearchMode==='number'?'numeric':'text').val(previewSearchQuery);
      var $search=$('<div class="ucm-preview-search-box">').append($modes,$('<div class="input-group input-group-sm">').append($input,$('<span class="input-group-btn">').append($('<button type="button" class="btn btn-default ucm-preview-search-close">').text('Close'))));
      $out.append($search);matches.slice(0,pageSize).forEach(addContact);if(!matches.length)$out.append($('<div class="ucm-phone-preview-empty">').text(tooShort?'Enter at least '+minimum+' digits.':'No matching contacts.'));
    }else{
      if(!previewDirect){var fixed=(showSearch?1:0)+(children.length&&directContacts.length&&!(currentId===rootId&&hideRoot)?1:0),menuPageSize=Math.max(1,pageSize-fixed);if(searchPosition==='first')addSearch();addDirect();if(searchPosition==='after_uncategorised')addSearch();children.slice(0,menuPageSize).forEach(function(id){var child=nodeById(id);if(!child)return;$out.append($('<button type="button" class="ucm-phone-preview-menu ucm-preview-open">').attr('data-node',id).append($('<span>').text(child.title||child.label||'Menu'),$('<b>').text(child.count==null?(child.total_contacts==null?'':child.total_contacts):child.count),$('<i>').text('›')));});if(searchPosition==='last')addSearch();}
      if(previewDirect||!children.length)directContacts.slice(0,pageSize).forEach(addContact);
    }
    if(!$out.children().length)$out.append($('<div class="ucm-phone-preview-empty">').text('This menu is empty.'));var isList=previewSearch||previewDirect||!children.length,listCount=previewSearch?matches.length:directContacts.length,menuFixed=(showSearch?1:0)+(children.length&&directContacts.length&&!(currentId===rootId&&hideRoot)?1:0),hasNext=isList?listCount>pageSize:children.length>Math.max(1,pageSize-menuFixed),total=previewData.total_contacts==null?(Array.isArray(previewData.entries)?previewData.entries.length:Object.keys(previewData.contacts||{}).length):previewData.total_contacts,directMobile=previewSearch&&matches.some(function(c){return !!c.direct_mobile;});$('#ucm-phone-preview-count').text(total+' contacts · exact compiled tree');$('.ucm-phone-preview-title').text($('[name="phonebook[title]"]').val()||'Phonebook');$('#ucm-phone-preview-prompt').text(previewSearch?(previewSearchMode==='number'?'Extension, mobile or EMP No. (123)':'Name, login or number (ABC)'):(!previewDirect&&children.length?'Choose a category':'Choose a contact'));$('#ucm-preview-back').prop('disabled',!previewSearch&&!previewDirect&&!previewPath.length);renderPreviewSoftkeys(layout,vendor,{search:previewSearch,list:isList,root:currentId===rootId,hasPrevious:false,hasNext:hasNext,directMobile:directMobile});var notes={cisco78:'Cisco 78xx search uses separate ABC and numeric fields. Enabled fields are extension, mobile and employee number; an employee-number match dials the extension.',cisco88:'Cisco 88xx uses the same per-phonebook search policy. A direct-mobile row uses its mobile number; EditDial follows the saved option.',cisco_legacy:'Cisco 7940/7960 receives the same search results through its short External Directory URL with legacy-safe XML text.',yealink:'Yealink publishes the same leaf paths as remote-phonebook groups. Mobile is Phone2 when enabled; Cisco-only Search controls do not apply.',grandstream:'Grandstream publishes the same leaf paths as contact groups. Mobile is a second typed number when enabled; Cisco-only Search controls do not apply.'};$('#ucm-phone-preview-notes').text(notes[vendor]||'Exact compiled phonebook preview.');
  }
  function reviewDraft(){serializeBook();var value={};$('#ucm-phonebook-form').serializeArray().forEach(function(input){var match=/^phonebook\[([^\]]+)\]$/.exec(input.name);if(match)value[match[1]]=input.value;});value.cisco_softkeys={menu:directorySoftkeys('menu'),list:directorySoftkeys('list')};return value;}
  var previewSequence=0;
  function requestBookPreview(){serializeBook();$('#ucm-book-review-approval').prop('hidden',true);$('#ucm-phone-preview-notes').text('Compiling exact preview…');var value=reviewDraft(),sequence=++previewSequence;$('#ucm-book-review-revision').val('');$('#ucm-book-review-approved').prop('checked',false);window.ucmpAjax({data:{command:'phonebookpreview',phonebook:JSON.stringify(value)}}).done(function(resp){if(sequence!==previewSequence||JSON.stringify(value)!==JSON.stringify(reviewDraft()))return;var r=(resp&&resp.result)||{};if(!(resp&&resp.status&&r.ok!==false)){previewData=null;$('#ucm-phone-preview-groups').html($('<div class="ucm-phone-preview-empty">').text(r.message||(resp&&resp.message)||'Preview failed'));return;}previewData=r.preview||r.book||r;var review=r.review||previewData.review;if(review){$('#ucm-book-review-approval').prop('hidden',!review.exceptional);$('#ucm-book-review-revision').val(review.revision);var panel=$('#ucm-book-review').empty().append($('<p>').text(review.before_count+' previously published → '+review.after_count+' proposed; '+review.additions.length+' additions, '+review.removals.length+' removals, '+review.changed.length+' changed. '+(review.exceptional?'Exceptional change: approval required.':'Ordinary change.')));['additions','removals','changed'].forEach(function(kind){if(review[kind].length)panel.append($('<details>').append($('<summary>').text(kind+' ('+review[kind].length+')'),$('<pre>').text(review[kind].map(function(c){return [c.name,c.extension,c.site].join(' / ');}).join('\n'))));});panel.append($('<p>').text('Publish sites: '+review.publish_sites.join(', ')+' | Contact sites: '+review.sites.join(', ')+' | Web fields: '+review.exposed_fields.join(', ')), $('<p>').text(review.reasons.join(' ')));}previewPath=[];previewDirect=false;previewSearch=false;previewSearchMode='text';previewSearchQuery='';renderBookPreview();}).fail(function(xhr){if(sequence!==previewSequence)return;previewData=null;$('#ucm-phone-preview-groups').html($('<div class="ucm-phone-preview-empty">').text((xhr.responseJSON&&xhr.responseJSON.message)||'Preview request failed'));});}
  function scheduleBookPreview(){++previewSequence;$('#ucm-book-review-approval').prop('hidden',true);$('#ucm-book-review-revision').val('');$('#ucm-book-review-approved').prop('checked',false);clearTimeout(previewTimer);previewTimer=setTimeout(requestBookPreview,350);}
  $(document).on('ucmp-directory-refresh',scheduleBookPreview);
  $('#ucm-book-add-rule').on('click',function(){addRule({});scheduleBookPreview();});$(document).on('click','.ucm-book-remove-rule',function(){$(this).closest('.ucm-book-rule-row').remove();scheduleBookPreview();});
  $('#ucm-book-add-manual').on('click',function(){var row=$('<tr class="ucm-book-manual-row">');['display_name','label','extension','menu_path','department','site_name','org_unit','company','mobile','pager','mail'].forEach(function(field){row.append($('<td>').append($('<input class="form-control input-sm">').attr('data-field',field).prop('required',field==='display_name'||field==='extension').attr('placeholder',field==='menu_path'?'City / Department':'')));});row.append($('<td>').append($('<button class="btn btn-link btn-sm ucm-book-remove-manual" type="button">').text('Remove')));$('#ucm-book-manual-list').append(row);scheduleBookPreview();});$(document).on('click','.ucm-book-remove-manual',function(){$(this).closest('tr').remove();scheduleBookPreview();});
  $(document).on('click','.ucm-book-toggle-source',function(){var row=$(this).closest('.ucm-phonebook-source'),contact=row.data('contact')||{},source=String(row.data('source')||''),legacy=String(contact.object_key||'');if(retainedExclusions.has(legacy)){result('This is a legacy cross-source exclusion. It is preserved; migrate its scope before including individual identities.',false);return;}var excluded=row.toggleClass('is-excluded').hasClass('is-excluded');if(excluded)retainedExclusions.add(source);else retainedExclusions.delete(source);$(this).toggleClass('btn-success',excluded).toggleClass('btn-default',!excluded).text(excluded?'Include':'Exclude');row.find('.ucm-source-state').text(excluded?'×':'✓');scheduleBookPreview();});
  $('#ucm-book-source-filter').on('input',function(){var q=$(this).val().toLowerCase();$('#ucm-book-source-list .ucm-phonebook-source').each(function(){$(this).toggle(String($(this).data('filter')).indexOf(q)!==-1);});});
  $('#ucm-add-branch').on('click',function(){$('#ucm-hierarchy-tree').append(nodeCard({type:'branch',label:'New category',when:{match:'any',items:[{source:'attr:l',operator:'is',value:''}]},children:[]}));refreshHierarchyMeta();scheduleBookPreview();});
  $('#ucm-add-group').on('click',function(){$('#ucm-hierarchy-tree').append(nodeCard({type:'group',source:'department',fallback:'Other',children:[]}));refreshHierarchyMeta();scheduleBookPreview();});
  $('#ucm-city-starter').on('click',function(){if($('#ucm-hierarchy-tree').children().length&&!confirm('Replace the current menu tree with four editable city categories?'))return;var nodes=[];for(var i=1;i<=4;i++)nodes.push({type:'branch',label:'City '+i,when:{match:'any',items:[{source:'attr:l',operator:'is',value:''},{source:'attr:st',operator:'is',value:''}]},children:[{type:'group',source:'ou:1',fallback:'Other',children:[]}]});renderHierarchy({version:2,children:nodes});scheduleBookPreview();});
  $(document).on('click','.ucm-hnode-add-branch,.ucm-hnode-add-group',function(){var parent=$(this).closest('.ucm-hnode'),depth=parent.parents('.ucm-hnode').length+1;if(depth>=8){alert('Phonebook menus are limited to eight levels.');return;}var node=$(this).hasClass('ucm-hnode-add-branch')?{type:'branch',label:'Nested category',when:{match:'any',items:[{source:'department',operator:'is',value:''}]},children:[]}:{type:'group',source:'department',fallback:'Other',children:[]};parent.children('.ucm-hnode-children').append(nodeCard(node));refreshHierarchyMeta();scheduleBookPreview();}).on('click','.ucm-hnode-remove',function(){if(confirm('Remove this menu node and all nested nodes?')){$(this).closest('.ucm-hnode').remove();refreshHierarchyMeta();scheduleBookPreview();}}).on('click','.ucm-hnode-up,.ucm-hnode-down',function(){var node=$(this).closest('.ucm-hnode');if($(this).hasClass('ucm-hnode-up'))node.prev('.ucm-hnode').before(node);else node.next('.ucm-hnode').after(node);refreshHierarchyMeta();scheduleBookPreview();}).on('click','.ucm-branch-rule-add',function(){$(this).siblings('.ucm-branch-rules').append(branchRule({source:'department',operator:'is'}));scheduleBookPreview();}).on('click','.ucm-branch-rule-remove',function(){var box=$(this).closest('.ucm-branch-rules');$(this).closest('.ucm-branch-rule').remove();if(!box.children().length)box.append(branchRule({source:'department',operator:'is'}));scheduleBookPreview();});
  $(document).on('change','.ucm-rule-source',function(){var op=$(this).closest('.ucm-branch-rule').find('.ucm-rule-operator');if($(this).val()==='ou_path')op.val('under');else if(op.val()==='under')op.val('is');}).on('input change','#ucm-hierarchy-tree :input',function(){refreshHierarchyMeta();scheduleBookPreview();});
  $('#ucm-publish-all').on('change',function(){var all=$(this).is(':checked');$('.ucm-publish-site').prop('disabled',all);$('#ucm-publish-site-list').toggleClass('text-muted',all);serializeBook();});
  $('#ucm-phonebook-form').on('input change',function(e){if(e.target&&(e.target.id==='ucm-preview-search-input'||e.target.id==='ucm-book-review-approved'))return;serializeBook();scheduleBookPreview();}).on('submit',function(e){if($(this).find('[name="action"]').val()==='delphonebook')return;serializeBook();if(!publishSites().length){e.preventDefault();alert('Choose at least one publication site, or select Every enabled site.');}});$('.ucm-phone-preview-tabs').on('click','button',function(){$(this).addClass('active').siblings('button').removeClass('active');previewSearch=false;renderBookPreview();});$('.ucm-delete-phonebook').on('click',function(){var form=$('#ucm-phonebook-form');if(!confirm('Delete this managed phonebook? Deletion is blocked if any local or remote template still selects it. Automatic sync for this phonebook will be paused.'))return;if(form.find('[name="sync_edit_confirmed"]').length&&!form.find('[name="sync_edit_confirmed"]').is(':checked')){alert('Confirm the synchronized-object edit before deleting this phonebook.');return;}form.find('[name="action"]').val('delphonebook');form.find('[name="sync_edit_reason"]').val('Deleted in Phonebook Designer');form.trigger('submit');});
  $('#ucm-phone-preview-groups').on('click','.ucm-preview-open',function(){previewSearch=false;previewDirect=false;previewPath.push(String($(this).data('node')));renderBookPreview();}).on('click','.ucm-preview-direct',function(){previewSearch=false;previewDirect=true;renderBookPreview();}).on('click','.ucm-preview-search',function(){previewSearch=true;previewSearchMode='text';previewSearchQuery='';renderBookPreview();$('#ucm-preview-search-input').focus();}).on('click','.ucm-preview-search-mode',function(){previewSearchMode=String($(this).data('mode'))==='number'?'number':'text';previewSearchQuery='';renderBookPreview();$('#ucm-preview-search-input').focus();}).on('input','#ucm-preview-search-input',function(){previewSearchQuery=$(this).val();renderBookPreview();$('#ucm-preview-search-input').focus().get(0).setSelectionRange(previewSearchQuery.length,previewSearchQuery.length);}).on('click','.ucm-preview-search-close',function(){previewSearch=false;previewSearchQuery='';renderBookPreview();});$('#ucm-preview-back').on('click',function(){if(previewSearch){previewSearch=false;previewSearchQuery='';}else if(previewDirect)previewDirect=false;else previewPath.pop();renderBookPreview();});$('#ucm-preview-home').on('click',function(){previewSearch=false;previewSearchQuery='';previewDirect=false;previewPath=[];renderBookPreview();});
  $('[name="phonebook[name]"]').on('input',function(){var filename=$('[name="phonebook[filename]"]');if(!filename.val()){filename.attr('placeholder','Automatic short filename');}});var sourceList=$('<datalist id="ucm-hierarchy-source-list">');hierarchySources.forEach(function(item){sourceList.append($('<option>').val(item[0]).attr('label',item[1]));});$('body').append(sourceList);renderHierarchy(initialHierarchy);$('#ucm-publish-all').triggerHandler('change');serializeBook();requestBookPreview();
});
</script>
