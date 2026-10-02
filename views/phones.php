<?php
/** Phones view: inventory + per-phone commands + bulk rebuild + bind/edit form. $ucm in scope. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$view = $_REQUEST['view'] ?? '';

if ($view === 'phone') {
    $id = (int)($_REQUEST['id'] ?? 0);
    $requestedSiteId = (int)($_REQUEST['site_id'] ?? 0) ?: $ucm->localSiteId();
    $phone = $id ? ($ucm->getPhone($id, $requestedSiteId) ?: []) : ['site_id' => $requestedSiteId];
    if ($id && !$phone) { throw new \RuntimeException('This phone no longer exists on the selected site. Reload the inventory.'); }
    if (!$id) {
        // Prefill from a network scan result ("Bind this" link) — never trusted beyond form defaults.
        if (!empty($_REQUEST['prefill_ip'])) { $phone['ip'] = (string) $_REQUEST['prefill_ip']; }
        if (!empty($_REQUEST['prefill_mac'])) { $phone['mac'] = (string) $_REQUEST['prefill_mac']; }
        if (!empty($_REQUEST['prefill_vendor'])) {
            foreach (['Cisco', 'Grandstream', 'Yealink'] as $v) {
                if (stripos((string) $_REQUEST['prefill_vendor'], $v) !== false) { $phone['vendor'] = $v; }
            }
        }
    }
    $f = fn($k, $d = '') => htmlspecialchars((string)($phone[$k] ?? $d));

    $sites = $ucm->getSites();
    $models = $ucm->getModels();
    $formSiteId = (int)($phone['site_id'] ?? $requestedSiteId) ?: $ucm->localSiteId();
    $siteEndpointSettings = [];
    $endpointSettingsError = '';
    try {
        $siteEndpointSettings = $ucm->getSiteSettings($formSiteId);
    } catch (\Throwable $e) {
        $endpointSettingsError = $e->getMessage();
    }
    $groups = $ucm->getGroups($formSiteId);
    $currentExt = (string)($phone['extension'] ?? '');
    // One inventory and one extension-catalog request feed every picker on this form.
    // This matters especially for remote sites, where each duplicate call is an SSH/RPC round trip.
    $sitePhones = $ucm->getPhones(['site_id' => $formSiteId]);
    $allSipExtensions = $ucm->getAllSipExtensions($formSiteId);
    // Older remote getPhoneLocal responses did not include the joined model
    // identity, while the fleet inventory did.  Enrich the editor from that
    // same owning-site inventory so a cross-version read never selects a local
    // PBX model merely because its numeric row ID matches.
    if ($id > 0) {
        foreach ($sitePhones as $sitePhone) {
            if ((int)($sitePhone['id'] ?? 0) !== $id) { continue; }
            foreach (['m_vendor','m_model','m_family','m_series_key','m_role'] as $modelField) {
                if (isset($sitePhone[$modelField])) { $phone[$modelField] = $sitePhone[$modelField]; }
            }
            break;
        }
    }
    $selectedModelKey = !empty($phone['m_vendor']) && !empty($phone['m_model'])
        ? (string)$phone['m_vendor'] . '|' . (string)$phone['m_model']
        : '';
    $selectedEndpointModel = [];
    foreach ($models as $candidateModel) {
        if (($selectedModelKey === '' && $formSiteId === $ucm->localSiteId()
                && !empty($phone['model_id']) && (int)$candidateModel['id'] === (int)$phone['model_id'])
            || ($selectedModelKey !== '' && strcasecmp(
                (string)$candidateModel['vendor'] . '|' . (string)$candidateModel['model'],
                $selectedModelKey
            ) === 0)) {
            $selectedEndpointModel = $candidateModel;
            $selectedModelKey = (string)$candidateModel['vendor'] . '|' . (string)$candidateModel['model'];
            break;
        }
    }
    $endpointPreview = \FreePBX\modules\Ucmprovisioner\EndpointProfile::resolve(
        $phone,
        $selectedEndpointModel,
        $siteEndpointSettings
    );
    $phoneByExtension = [];
    foreach ($sitePhones as $sitePhone) {
        $assignedExt = (string)($sitePhone['extension'] ?? '');
        if ($assignedExt !== '' && (!empty($sitePhone['mac']) || !empty($sitePhone['model_id']))) {
            $phoneByExtension[$assignedExt] = $sitePhone;
        }
    }
    $validExtensions = array_fill_keys(array_map(static fn($ext) => (string)($ext['id'] ?? ''), $allSipExtensions), true);
    $requestedExt = trim((string)($_REQUEST['assign_extension'] ?? ''));
    $selectedExt = ($requestedExt !== '' && isset($validExtensions[$requestedExt])) ? $requestedExt : $currentExt;
    $device = $selectedExt !== '' ? $ucm->getBoundDevice($selectedExt, $formSiteId) : null;
    $collisionAction = in_array((string)($_REQUEST['collision_action'] ?? 'swap'), ['swap', 'move'], true)
        ? (string)($_REQUEST['collision_action'] ?? 'swap') : 'swap';
    $syncAssignmentLabel = !array_key_exists('sync_assignment_label', $_REQUEST)
        || !empty($_REQUEST['sync_assignment_label']);
    $maxLen = \FreePBX\modules\Ucmprovisioner\ExtensionBinder::MAX_SECRET_LEN;
    $manualConfig = (string)($phone['config_mode'] ?? 'managed') === 'manual';
    $extDescs = [];
    foreach ($allSipExtensions as $e) { $extDescs[$e['id']] = $e['description']; }
    $editorTemplateSummary = _('Not resolved');
    if (!empty($phone['model_id'])) {
        try {
            $editorTemplateParts = [];
            $editorTemplateEngine = new \FreePBX\modules\Ucmprovisioner\TemplateEngine($ucm);
            foreach ($editorTemplateEngine->templateAssignmentTrace($phone) as $entry) {
                if (($entry['status'] ?? '') !== 'applied') { continue; }
                $source = (string)($entry['source'] ?? '');
                $origin = $source === 'default'
                    ? _('Default')
                    : ($source === 'group'
                        ? sprintf(_('Group: %s'), (string)($entry['group_name'] ?? _('unknown')))
                        : ($source === 'exception'
                            ? sprintf(_('Member exception: %s'), (string)($entry['group_name'] ?? _('group')))
                            : ucfirst($source ?: _('Assigned'))));
                $editorTemplateParts[] = '(' . $origin . ') ' . (string)($entry['template_name'] ?? sprintf(_('Template %d'), (int)($entry['template_id'] ?? 0)));
            }
            if ($editorTemplateParts) { $editorTemplateSummary = implode(' + ', $editorTemplateParts); }
        } catch (\Throwable $e) {
            $editorTemplateSummary = _('Unavailable until the next successful resolution');
        }
    }
    ?>
    <?php
    $knownMacs = [];
    foreach ($sitePhones as $knownPhone) {
        if (empty($knownPhone['mac'])) { continue; }
        $knownModel = trim(($knownPhone['m_vendor'] ?? '') . ' ' . ($knownPhone['m_model'] ?? ''));
        $knownContext = !empty($knownPhone['extension'])
            ? $knownPhone['extension'] . ' — ' . ($knownPhone['label'] ?: $knownModel ?: 'bound')
            : 'UNASSIGNED' . (!empty($knownPhone['label']) ? ' — ' . $knownPhone['label'] : '') . ($knownModel ? " ($knownModel)" : '');
        $knownMacs[] = ['id' => (int)$knownPhone['id'], 'mac' => $knownPhone['mac'], 'context' => trim($knownContext)];
    }
    $currentMac = strtoupper((string)($phone['mac'] ?? ''));
    $macIsKnown = false;
    foreach ($knownMacs as $km) { if (strtoupper($km['mac']) === $currentMac && $currentMac !== '') { $macIsKnown = true; } }
    $initialLineCount = 1 + count(json_decode((string)($phone['lines_json'] ?? '[]'), true) ?: []);
    $formSiteName = _('Selected site');
    foreach ($sites as $siteOption) {
        if ((int)($siteOption['id'] ?? 0) === $formSiteId) { $formSiteName = (string)($siteOption['name'] ?? $formSiteName); break; }
    }
    ?>
    <div class="ucm-container" id="ucmp-phone-editor-v2">
    <form class="fpbx-submit" name="frm_ucmprovisioner" action="config.php?display=ucmprovisioner&amp;tab=phones&amp;view=phone&amp;site_id=<?php echo $formSiteId; ?>&amp;site_filter=<?php echo $formSiteId; ?><?php echo $id ? '&amp;id=' . $id : ''; ?>" method="post" novalidate>
      <input type="hidden" name="display" value="ucmprovisioner">
      <input type="hidden" name="tab" value="phones">
      <input type="hidden" name="view" value="phone">
      <input type="hidden" name="site_filter" value="<?php echo $formSiteId; ?>">
      <input type="hidden" name="action" value="savephone">
      <input type="hidden" name="expected_binding" value="<?php echo \FreePBX\modules\Ucmprovisioner::phoneBindingRevision($phone); ?>">
      <input type="hidden" name="id" value="<?php echo $id; ?>">
      <input type="hidden" name="lines_json" id="ucm-phone-lines-json">
      <?php if ($manualConfig): ?><input type="hidden" name="replace_manual" value="1"><?php endif; ?>

      <header class="pe2-header">
        <div class="pe2-heading">
          <div class="pe2-badges">
            <span class="pe2-badge"><?php echo $manualConfig ? _('Manual override') : _('Managed config'); ?></span>
            <span class="pe2-badge"><?php echo $id ? _('Existing phone') : _('New binding'); ?></span>
            <span class="pe2-badge"><?php echo htmlspecialchars($formSiteName); ?></span>
            <span class="pe2-badge" id="ucm-pe-role-badge"><?php echo _('Phone'); ?></span>
          </div>
          <h2><?php echo $id ? sprintf(_('Edit phone %s'), $f('extension')) : _('Bind and provision a phone'); ?></h2>
          <p id="ucm-pe-device-heading"><?php echo htmlspecialchars(trim((string)($phone['vendor'] ?? '') . ' ' . (string)($phone['m_model'] ?? '')) ?: _('Choose the physical endpoint and its SIP identity')); ?></p>
        </div>
        <div class="pe2-actions">
          <a class="btn btn-default" href="config.php?display=ucmprovisioner&amp;tab=phones&amp;site_filter=<?php echo $formSiteId; ?>"><?php echo _('Back to phones'); ?></a>
          <button type="submit" class="btn btn-default"><?php echo _('Save only'); ?></button>
          <button type="submit" name="rebuild_after" value="1" class="btn <?php echo $manualConfig ? 'btn-warning' : 'btn-primary'; ?>"><?php echo $manualConfig ? _('Save & replace manual config') : _('Save & Rebuild'); ?></button>
        </div>
      </header>

      <nav class="pe2-tabs" aria-label="<?php echo _('Phone editor sections'); ?>">
        <button type="button" class="pe2-tab" data-phone-edit-tab="provisioning" aria-selected="true"><?php echo _('Provisioning'); ?></button>
        <button type="button" class="pe2-tab" data-phone-edit-tab="advanced" aria-selected="false"><?php echo _('Advanced'); ?></button>
      </nav>
      <div class="pe2-status" id="ucm-pe-status" role="status"><?php echo _('No unsaved changes. Save only leaves TFTP unchanged; Save & Rebuild applies generated configuration.'); ?></div>

      <?php if ($manualConfig): ?>
        <div class="alert alert-warning pe2-alert"><strong><?php echo _('Manual TFTP config override is active.'); ?></strong> <?php echo _('Rebuild All leaves this phone file untouched. Save & replace manual config explicitly returns it to generated management; site-wide Asterisk/BLF glue is still maintained while the override exists.'); ?></div>
      <?php endif; ?>

      <div class="pe2-layout">
        <main class="pe2-main">
          <div data-phone-edit-panel="provisioning">
            <section class="pe2-section">
              <div class="pe2-section-head"><div><h3><?php echo _('Assignment & identity'); ?></h3><p><?php echo _('Bind the physical device, choose collision behavior, and control only its fallback label.'); ?></p></div></div>
              <div class="pe2-grid">
                <div class="form-group pe2-span">
                  <label><?php echo _('Extension (existing CHAN_SIP or PJSIP device — module never creates new extensions)'); ?></label>
                  <select class="form-control ucm-chosen" name="extension" id="ucm-extension-select" data-placeholder="<?php echo _('-- search every extension, or leave blank to unassign --'); ?>">
                    <option value=""></option>
                    <?php if ($selectedExt !== '' && !isset($validExtensions[$selectedExt])): ?>
                      <option value="<?php echo htmlspecialchars($selectedExt); ?>" selected><?php echo htmlspecialchars($selectedExt . ' — saved extension unavailable; refresh or choose explicitly'); ?></option>
                    <?php endif; ?>
                    <?php foreach ($allSipExtensions as $e):
                      $extension = (string)$e['id'];
                      $fallbackLabel = \FreePBX\modules\Ucmprovisioner\PhoneAssignment::fallbackLabel($e);
                      $assignedPhone = $phoneByExtension[$extension] ?? null;
                      $assignment = _('available');
                      if ($assignedPhone) {
                          $assignment = ((int)($assignedPhone['id'] ?? 0) === $id)
                              ? _('this phone')
                              : sprintf(_('assigned to %s'), (string)($assignedPhone['mac'] ?: $assignedPhone['label'] ?: ('phone #' . $assignedPhone['id'])));
                      }
                    ?>
                      <option value="<?php echo htmlspecialchars($extension); ?>" data-driver="<?php echo htmlspecialchars(strtolower((string)($e['driver'] ?? 'sip'))); ?>" data-desc="<?php echo htmlspecialchars((string)$e['description']); ?>" data-fallback-label="<?php echo htmlspecialchars($fallbackLabel['label']); ?>" data-fallback-source="<?php echo htmlspecialchars($fallbackLabel['source']); ?>" data-phone-id="<?php echo (int)($assignedPhone['id'] ?? 0); ?>" data-phone-mac="<?php echo htmlspecialchars((string)($assignedPhone['mac'] ?? '')); ?>" data-group-id="<?php echo (int)($e['group_id'] ?? 0); ?>" <?php echo $extension === $selectedExt ? 'selected' : ''; ?>><?php echo htmlspecialchars($extension . ' — ' . $e['description'] . ' [' . \FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel((string)($e['driver'] ?? 'sip')) . ' · ' . $assignment . ']'); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="help-block"><?php echo _('Every extension is selectable. Assigned entries identify their current phone; choosing one uses the collision action below. Leaving this blank keeps the physical phone in inventory but unassigns its primary extension.'); ?></p>
                  <div class="panel panel-info" id="ucm-assignment-collision" style="display:none;margin-top:10px"><div class="panel-heading"><strong><?php echo _('Extension already assigned'); ?></strong></div><div class="panel-body"><label for="ucm-collision-action"><?php echo _('What should happen to the other phone?'); ?></label><select class="form-control" name="collision_action" id="ucm-collision-action"><option value="swap" <?php echo $collisionAction === 'swap' ? 'selected' : ''; ?>><?php echo _('Swap extensions (recommended)'); ?></option><option value="move" <?php echo $collisionAction === 'move' ? 'selected' : ''; ?>><?php echo _('Move extension here; leave the other phone unassigned'); ?></option></select><p class="help-block" id="ucm-assignment-preview"></p></div></div>
                  <div class="alert alert-info" id="ucm-assignment-ready" style="display:none"></div>
                </div>
                <div class="form-group pe2-span">
                  <label><?php echo _('Base phone and line-key label'); ?> <small class="text-muted"><?php echo _('(physical fallback)'); ?></small></label>
                  <input class="form-control" name="label" id="ucm-label-input" value="<?php echo $f('label'); ?>">
                  <input type="hidden" name="sync_assignment_label" value="0">
                  <div class="checkbox"><label><input type="checkbox" name="sync_assignment_label" id="ucm-sync-assignment-label" value="1" <?php echo $syncAssignmentLabel?'checked':''; ?>> <?php echo _('When the primary extension changes, update this phone’s fallback label from the extension it receives. During Swap, update the other phone’s fallback label too.'); ?></label></div>
                  <p class="help-block" id="ucm-label-limit-note"></p><p class="text-muted" id="ucm-label-sync-note" style="display:none;"></p>
                  <p class="help-block"><?php echo _('This field is only the physical phone’s fallback. Accepted AD identity and its audited canonical overrides follow the logical extension automatically; template patterns are applied next; an audited final per-extension output override wins last. Reassigning here never edits or deletes those audited overrides. Uncheck the option above to keep a deliberately phone-specific fallback label.'); ?></p>
                  <p class="help-block"><?php echo _('Templates → Identity can split Cisco top/status and line-key labels. Yealink and Grandstream use the primary account/line pattern because their supported modern profiles have no universal independent top/status identity field. Caller identity remains a separate AD/FreePBX display-name field on every vendor.'); ?></p>
                </div>
                <div class="form-group"><label><?php echo _('Site'); ?></label><select class="form-control" name="site_id" id="ucm-phone-site" <?php echo $id ? 'disabled' : ''; ?>><?php foreach ($sites as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo ($formSiteId === (int)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?></select><?php if ($id): ?><input type="hidden" name="site_id" value="<?php echo $formSiteId; ?>"><?php endif; ?><p class="help-block"><?php echo $id ? _('A phone stays owned by its site. Create a new binding to move hardware between sites.') : _('Changing the site reloads this form with that PBX\'s extensions, groups and known MAC addresses.'); ?></p></div>
                <div class="form-group"><label><?php echo _('Group (template assignment)'); ?></label><input type="hidden" name="group_changed" id="ucm-group-changed" value="0"><select class="form-control" name="group_id" id="ucm-phone-group"><option value=""><?php echo _('-- none --'); ?></option><?php foreach ($groups as $g): ?><option value="<?php echo (int)$g['id']; ?>" <?php echo ((int)($phone['group_id'] ?? 0) === (int)$g['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($g['name']); ?></option><?php endforeach; ?></select><p class="help-block"><?php echo _('This provisioning-group template assignment belongs to the extension and follows it during a handset swap. Selecting another group here explicitly changes that assignment.'); ?></p></div>
                <div class="checkbox pe2-span"><input type="hidden" name="exclude_bulk_rebuild" value="0"><label><input type="checkbox" name="exclude_bulk_rebuild" value="1" <?php echo !empty($phone['exclude_bulk_rebuild']) ? 'checked' : ''; ?>> <?php echo _('Exclude this phone from Rebuild All'); ?></label><p class="help-block"><?php echo _('Individual Preview/Rebuild still works. Rebuild All skips only this phone config and warns that its phone-side BLF subscriptions may be stale; shared Asterisk glue continues to update.'); ?></p></div>
              </div>
            </section>

            <section class="pe2-section">
              <div class="pe2-section-head"><div><h3><?php echo _('Hardware & SIP'); ?></h3><p><?php echo _('Identify the provisioned endpoint or DECT system and update live SIP settings only when saved.'); ?></p></div></div>
              <div class="pe2-grid">
                <div class="form-group"><label><?php echo _('Vendor'); ?></label><select class="form-control" name="vendor" id="ucm-vendor"><?php foreach (['Cisco', 'Grandstream', 'Yealink'] as $v): ?><option value="<?php echo $v; ?>" <?php echo (($phone['vendor'] ?? '') === $v) ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label><?php echo _('Model / managed system'); ?></label><input type="hidden" name="model_id" id="ucm-model-id" value="<?php echo (int)($phone['model_id'] ?? 0); ?>"><select class="form-control" name="model_key" id="ucm-model"><option value=""><?php echo _('-- choose --'); ?></option><?php foreach ($models as $m): $modelRole=(string)($m['role'] ?? 'desk'); $managedComponent=in_array($modelRole,['dect_handset','dect_radio','dect_manager'],true); $labelPolicy=\FreePBX\modules\Ucmprovisioner\ModelCatalog::identityLabelPolicy((string)$m['vendor'],(string)$m['model'])??[]; $modelKey=(string)$m['vendor'].'|'.(string)$m['model']; ?><option value="<?php echo htmlspecialchars($modelKey); ?>" data-id="<?php echo (int)$m['id']; ?>" data-vendor="<?php echo htmlspecialchars($m['vendor']); ?>" data-model="<?php echo htmlspecialchars($m['model']); ?>" data-family="<?php echo htmlspecialchars($m['family']); ?>" data-role="<?php echo htmlspecialchars($modelRole); ?>" data-accounts="<?php echo (int)($m['sip_accounts'] ?? $m['line_keys']); ?>" data-keys="<?php echo (int)($m['programmable_keys'] ?? $m['line_keys']); ?>" data-handsets="<?php echo (int)($m['handsets_max'] ?? 0); ?>" data-label-phone-max="<?php echo (int)($labelPolicy['phone_max_chars']??0); ?>" data-label-line-max="<?php echo (int)($labelPolicy['line_max_chars']??0); ?>" data-label-phone-spaces="<?php echo !empty($labelPolicy['phone_spaces'])?'1':'0'; ?>" data-label-status="<?php echo htmlspecialchars((string)($labelPolicy['firmware_status_text']??'')); ?>" <?php echo $selectedModelKey !== '' && strcasecmp($selectedModelKey,$modelKey)===0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['vendor'] . ' ' . $m['model'] . ($managedComponent ? ' — managed inside its DECT system' : ' (' . (int)($m['sip_accounts'] ?? $m['line_keys']) . ' accounts · ' . (int)($m['programmable_keys'] ?? $m['line_keys']) . ' keys)')); ?></option><?php endforeach; ?><?php if ($selectedModelKey !== '' && !$selectedEndpointModel): ?><option value="<?php echo htmlspecialchars($selectedModelKey); ?>" data-vendor="<?php echo htmlspecialchars((string)($phone['m_vendor'] ?? '')); ?>" data-family="<?php echo htmlspecialchars((string)($phone['m_family'] ?? '')); ?>" data-unavailable="1" selected><?php echo htmlspecialchars($selectedModelKey . ' — saved model unavailable; refresh or choose explicitly'); ?></option><?php endif; ?></select><p class="help-block text-warning" id="ucm-model-role-warning" style="display:none"></p></div>
                <div class="form-group pe2-span"><label for="ucm-mac-custom"><?php echo _('MAC address — 12 hexadecimal characters (0–9, A–F)'); ?></label><select class="form-control ucm-chosen" id="ucm-mac-select" data-placeholder="<?php echo _('-- search by MAC or current extension --'); ?>"><option value=""></option><option value="__custom__" <?php echo ($currentMac !== '' && !$macIsKnown) ? 'selected' : ''; ?>><?php echo _('+ Enter a new/unknown MAC manually'); ?></option><?php foreach ($knownMacs as $km): ?><option value="<?php echo htmlspecialchars($km['mac']); ?>" data-phone-id="<?php echo (int)$km['id']; ?>" <?php echo (strtoupper($km['mac']) === $currentMac) ? 'selected' : ''; ?>><?php echo htmlspecialchars($km['mac'] . ' — ' . ($km['context'] ?: 'unassigned')); ?></option><?php endforeach; ?></select>
                  <input class="form-control" type="text" id="ucm-mac-custom" style="display:none;margin-top:6px;" value="<?php echo $f('mac'); ?>" placeholder="AABBCCDDEEFF" pattern="[0-9A-Fa-f]{12}" aria-describedby="ucm-mac-help ucm-mac-error" autocomplete="off" spellcheck="false">
                  <input type="hidden" name="mac" id="ucm-mac-value" value="<?php echo $f('mac'); ?>">
                  <p class="help-block" id="ucm-mac-help"><?php echo _('Enter all 12 characters. Lowercase and uppercase are accepted and stored as uppercase. Pasted colons, dashes, dots and spaces are removed automatically.'); ?></p>
                  <p class="text-danger" id="ucm-mac-error" role="alert" hidden></p>
                  <p class="help-block"><?php echo _('The MAC identifies the physical phone. Choosing another known MAC opens that phone while preserving the extension selected above; saving then performs the same Swap/Move operation. MAC addresses themselves are never traded between database rows. Handsets and multicell radios are children of their base or manager, not separate SIP phones.'); ?></p>
                </div>
                <div class="form-group"><label><?php echo _('IP address'); ?> <small class="text-muted"><?php echo _('(informational only)'); ?></small></label><input class="form-control" name="ip" value="<?php echo $f('ip'); ?>" placeholder="10.20.30.40"></div>
                <div class="form-group"><label><?php echo _('Driver'); ?></label><select class="form-control" name="driver" id="ucm-driver"><option value="inherit" <?php echo $endpointPreview['driver_mode']==='inherit'?'selected':''; ?>><?php echo _('Inherit vendor-class site default'); ?></option><option value="sip" <?php echo $endpointPreview['driver_mode']==='sip'?'selected':''; ?>>CHAN_SIP</option><option value="pjsip" <?php echo $endpointPreview['driver_mode']==='pjsip'?'selected':''; ?>>PJSIP</option></select><p class="help-block" id="ucm-driver-help"><?php echo _('Cisco is fixed to CHAN_SIP for USECALLMANAGER; Yealink and Grandstream may inherit or override their site default.'); ?></p></div>
                <fieldset class="form-group" id="ucm-transport"><legend><?php echo _('Transport override for this extension'); ?></legend><?php foreach (['inherit'=>'Use site default','udp'=>'UDP','tcp'=>'TCP','tls'=>'TLS'] as $transportValue=>$transportLabel): ?><label class="radio-inline"><input type="radio" name="transport" value="<?php echo $transportValue; ?>" <?php echo $endpointPreview['transport_mode']===$transportValue?'checked':''; ?>> <?php echo htmlspecialchars($transportLabel); ?></label><?php endforeach; ?><p class="help-block" id="ucm-transport-help"></p></fieldset>
                <div class="form-group"><label><?php echo _('Phone SIP port'); ?></label><input class="form-control" type="number" min="1" max="65535" name="sip_port" id="ucm-sip-port" value="<?php echo $endpointPreview['port_mode'] > 0 ? (int)$endpointPreview['port_mode'] : ''; ?>" placeholder="<?php echo _('Inherit'); ?>"><p class="help-block"><?php echo _('Leave blank to inherit the Cisco or non-Cisco site default.'); ?></p></div>
                <div class="form-group pe2-span"><input type="hidden" name="apply_transport" value="1"><div class="alert alert-info" id="ucm-endpoint-effective"><strong><?php echo _('Effective endpoint:'); ?></strong> <span id="ucm-endpoint-effective-value"><?php echo htmlspecialchars(\FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($endpointPreview['driver']) . ' / ' . strtoupper($endpointPreview['transport']) . ' / ' . $endpointPreview['port']); ?></span><br><small id="ucm-endpoint-effective-source"><?php echo htmlspecialchars($endpointPreview['driver_source'] . '; ' . $endpointPreview['transport_source'] . '; ' . $endpointPreview['port_source']); ?></small><?php if ($endpointSettingsError !== ''): ?><div class="text-warning"><?php echo htmlspecialchars(_('Site endpoint defaults could not be loaded: ') . $endpointSettingsError); ?></div><?php endif; ?><div class="text-warning" id="ucm-endpoint-driver-warning" style="display:none"></div></div></div>
                <div class="form-group pe2-span"><label><?php echo _('New SIP secret (leave blank to keep current)'); ?></label><div class="input-group"><input class="form-control" type="password" name="secret" id="ucm-secret" maxlength="64" autocomplete="new-password" placeholder="<?php echo $device ? _('(unchanged)') : ''; ?>"><span class="input-group-btn"><button type="button" class="btn btn-default" id="ucm-generate-secret"><?php echo _('Generate compatible'); ?></button></span></div><p class="help-block"><span id="ucm-secret-len">0</span>/<span id="ucm-secret-max"><?php echo $maxLen; ?></span> <span id="ucm-secret-limit-note"><?php echo _('characters'); ?></span>. <?php if ($device && !empty($device['secret'])): ?><?php echo sprintf(_('Current secret is %d characters.'), strlen((string)$device['secret'])); ?><?php endif; ?></p><p class="help-block" id="ucm-secret-action-note"><?php echo _('Saving updates the extension’s SIP device secret and generated phone config. It does not change the separate UCP/voicemail web-login password. Existing secrets are never silently truncated.'); ?></p></div>
              </div>
            </section>

            <section class="pe2-section" id="ucm-phone-lines-panel" style="display:none;">
              <div class="pe2-section-head"><div><h3><?php echo _('SIP accounts & line keys'); ?></h3><p id="ucm-phone-lines-help"><small><?php echo _('Additional SIP accounts register more extensions on this physical endpoint and may use a line appearance. Programmable BLF/speed-dial keys still come from its template.'); ?></small></p></div><span class="pe2-badge" id="ucm-pe-account-capacity"><?php echo $initialLineCount; ?> <?php echo _('account(s)'); ?></span></div>
              <div class="table-responsive"><table class="table table-condensed table-striped" id="ucm-phone-lines-table"><thead><tr><th><?php echo _('Account slot'); ?></th><th><?php echo _('Extension'); ?></th><th><?php echo _('Display label'); ?></th><th class="ucm-line-appearance-col"><?php echo _('Line appearance'); ?></th><th></th></tr></thead><tbody><tr class="ucm-primary-line-row"><td><strong><?php echo _('Account 1'); ?></strong><br><small class="text-muted"><?php echo _('Primary assignment'); ?></small></td><td id="ucm-pe-primary-extension"><?php echo htmlspecialchars($selectedExt ?: _('Unassigned')); ?></td><td id="ucm-pe-primary-label"><?php echo htmlspecialchars((string)($phone['label'] ?? '')); ?></td><td class="ucm-line-appearance-col"><?php echo _('Primary line'); ?></td><td><small class="text-muted"><?php echo _('Edit above'); ?></small></td></tr></tbody></table></div>
              <div class="pe2-toolbar"><button type="button" class="btn btn-primary btn-sm" id="ucm-phone-add-line"><?php echo _('Add another extension'); ?></button><span class="text-muted" id="ucm-pe-account-note"></span></div>
            </section>

            <section class="pe2-section" id="ucm-dect-section" style="display:none;">
              <div class="pe2-section-head"><div><h3><?php echo _('DECT base routing'); ?></h3><p><?php echo _('Shown only for a supported provisioned DECT base. Handsets are logical children and never receive independent TFTP phone configs.'); ?></p></div><span class="pe2-badge" id="ucm-pe-dect-vendor"><?php echo _('DECT mapping'); ?></span></div>
              <div id="ucm-dect-panel">
                <div class="pe2-dect-facts"><div><strong id="ucm-pe-dect-account-limit">—</strong><span><?php echo _('SIP account capacity'); ?></span></div><div><strong id="ucm-pe-dect-handset-limit">—</strong><span><?php echo _('Handset capacity'); ?></span></div><div><strong><?php echo _('Base owned'); ?></strong><span><?php echo _('Configuration delivery'); ?></span></div></div>
                <div id="ucm-dect-handset-inventory" class="pe2-subsection"><div class="pe2-section-head"><div><h4><?php echo _('Registered handset inventory'); ?></h4><p><?php echo _('Record only the slots used by this base. An explicit handset label wins; otherwise the generator resolves the lowest assigned account label and warns when the result is ambiguous.'); ?></p></div><input class="form-control pe2-search" type="search" id="ucm-dect-inventory-search" placeholder="<?php echo _('Search slot, model, label or IPUI'); ?>"></div><div class="table-responsive pe2-handset-table"><table class="table table-condensed"><thead><tr><th><?php echo _('Slot'); ?></th><th><?php echo _('Handset model'); ?></th><th><?php echo _('Explicit label'); ?></th><th><?php echo _('IPUI / identifier'); ?></th></tr></thead><tbody><?php for($handsetSlot=1;$handsetSlot<=10;$handsetSlot++): ?><tr class="ucm-dect-handset-row" data-slot="<?php echo $handsetSlot; ?>"><th>HS <?php echo $handsetSlot; ?></th><td><select class="form-control input-sm ucm-dect-meta-model"><option value=""><?php echo _('-- not recorded --'); ?></option><?php foreach($models as $handsetModel): if((string)($handsetModel['role']??'')!=='dect_handset')continue; ?><option value="<?php echo htmlspecialchars((string)$handsetModel['model']); ?>" data-vendor="<?php echo htmlspecialchars((string)$handsetModel['vendor']); ?>"><?php echo htmlspecialchars((string)$handsetModel['vendor'].' '.(string)$handsetModel['model']); ?></option><?php endforeach; ?></select></td><td><input class="form-control input-sm ucm-dect-meta-label"></td><td><input class="form-control input-sm ucm-dect-meta-id" maxlength="64"></td></tr><?php endfor; ?></tbody></table></div></div>
                <div class="pe2-subsection"><h4><?php echo _('Extension → handset routing'); ?></h4><p class="help-block"><?php echo _('Each row shows the real SIP account extension and its assigned handset slots. Edit a row to search the base’s handset inventory instead of navigating a wide account-by-handset matrix.'); ?></p><div class="table-responsive"><table class="table table-condensed" id="ucm-dect-route-table"><thead><tr><th><?php echo _('SIP account'); ?></th><th><?php echo _('Extension / label'); ?></th><th><?php echo _('Assigned handsets'); ?></th><th class="ucm-pe-profile-heading"><?php echo _('Shared SIP server profile'); ?></th><th></th></tr></thead><tbody id="ucm-dect-route-list"></tbody></table></div><div class="alert alert-info" id="ucm-pe-profile-help" style="display:none"><strong><?php echo _('Shared SIP server profile is not an extension.'); ?></strong> <?php echo _('On Grandstream DP750/DP752 it is a reusable bundle of SIP server, transport, voicemail and call settings. Multiple extension accounts normally use Profile 1. DP755 and Yealink do not use this selector.'); ?></div><div class="pe2-route-editor" id="ucm-dect-route-editor" style="display:none"><div class="pe2-section-head"><div><h4 id="ucm-dect-route-editor-title"><?php echo _('Edit handset assignment'); ?></h4><p><?php echo _('Choose from this base’s handset slots. Empty inventory records remain clearly marked.'); ?></p></div><button type="button" class="btn btn-default btn-sm" id="ucm-dect-route-close"><?php echo _('Close'); ?></button></div><input class="form-control pe2-search" type="search" id="ucm-dect-route-search" placeholder="<?php echo _('Search handset, label or IPUI'); ?>"><div id="ucm-dect-route-options" class="pe2-route-options"></div><button type="button" class="btn btn-primary btn-sm" id="ucm-dect-route-apply"><?php echo _('Apply handset assignment'); ?></button></div></div>
                <p class="alert alert-warning pe2-multicell-note"><?php echo _('Multicell managers and radios remain managed components in this revision; the form blocks false standalone phone generation until a real manager/topology generator exists.'); ?></p>
                <div class="pe2-compat-matrix" aria-hidden="true"><div id="yl-dect-panel" style="display:none"><table class="ucm-dect-matrix"><tbody><?php for($a=1;$a<=10;$a++): ?><tr data-account="<?php echo $a; ?>"><?php for($h=1;$h<=10;$h++): ?><td data-handset="<?php echo $h; ?>"><input type="checkbox" class="ucm-dect-handset" data-account="<?php echo $a; ?>" value="<?php echo $h; ?>"></td><?php endfor; ?></tr><?php endfor; ?></tbody></table></div><div id="gs-dect-panel" style="display:none"><table class="ucm-dect-matrix"><tbody><?php for($a=1;$a<=20;$a++): ?><tr data-account="<?php echo $a; ?>"><?php for($h=1;$h<=10;$h++): ?><td data-handset="<?php echo $h; ?>"><input type="checkbox" class="ucm-gs-dect-handset" data-account="<?php echo $a; ?>" value="<?php echo $h; ?>"></td><?php endfor; ?><td class="ucm-gs-profile-col"><select class="ucm-set-input" data-key="gs_dect_account_<?php echo $a; ?>_profile"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></td></tr><?php endfor; ?></tbody></table></div></div>
              </div>
            </section>
          </div>

          <section class="pe2-section" data-phone-edit-panel="advanced" hidden>
            <div class="pe2-section-head"><div><h3><?php echo _('Advanced per-phone overrides'); ?></h3><p><?php echo _('Raw JSON remains available for supported settings that deliberately override resolved template output. Invalid JSON is rejected on save.'); ?></p></div></div>
            <div class="form-group"><label><?php echo _('Per-phone overrides (JSON — e.g. {"buttons":[...]})'); ?></label><textarea class="form-control pe2-code" name="overrides" rows="14"><?php echo htmlspecialchars((string)($phone['overrides'] ?? '')); ?></textarea></div>
            <div class="alert alert-warning"><?php echo _('Advanced values have final per-phone precedence. Prefer templates and audited per-extension identity overrides for policy that should follow an extension.'); ?></div>
          </section>
        </main>

        <aside class="pe2-summary" aria-label="<?php echo _('Resolved phone summary'); ?>">
          <h3><?php echo _('Resolved device state'); ?></h3>
          <dl class="pe2-facts"><div><dt><?php echo _('Extension'); ?></dt><dd id="ucm-pe-summary-extension"><?php echo htmlspecialchars($selectedExt !== '' ? $selectedExt . (isset($extDescs[$selectedExt]) ? ' · ' . $extDescs[$selectedExt] : '') : _('Unassigned')); ?></dd></div><div><dt><?php echo _('Hardware'); ?></dt><dd id="ucm-pe-summary-hardware"><?php echo htmlspecialchars(trim((string)($phone['vendor'] ?? '') . ' ' . (string)($phone['m_model'] ?? '')) ?: _('Not selected')); ?></dd></div><div><dt><?php echo _('Endpoint'); ?></dt><dd id="ucm-pe-summary-endpoint"><?php echo htmlspecialchars(\FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($endpointPreview['driver']) . ' / ' . strtoupper($endpointPreview['transport']) . ' / ' . $endpointPreview['port']); ?></dd></div><div><dt><?php echo _('MAC'); ?></dt><dd id="ucm-pe-summary-mac"><?php echo htmlspecialchars($currentMac ?: _('Not selected')); ?></dd></div><div><dt><?php echo _('Templates'); ?></dt><dd><?php echo htmlspecialchars($editorTemplateSummary); ?></dd></div><div><dt><?php echo _('Accounts'); ?></dt><dd id="ucm-pe-summary-accounts"><?php echo $initialLineCount; ?></dd></div><div><dt><?php echo _('Config'); ?></dt><dd><?php echo $manualConfig ? _('Manual override') : _('Managed'); ?></dd></div></dl>
          <h3><?php echo _('Save impact'); ?></h3><ul class="pe2-impact"><li><strong><?php echo _('Save only'); ?></strong> <?php echo _('stages inventory, assignment, endpoint and secret changes while leaving TFTP and the current live Asterisk route unchanged until Apply Config or a rebuild.'); ?></li><li><strong><?php echo $manualConfig ? _('Save & replace manual config') : _('Save & Rebuild'); ?></strong> <?php echo _('publishes the matching phone config, sends reload through the old registered route when needed, then activates the FreePBX endpoint.'); ?></li><li><?php echo _('Move or Unassign removes a displaced phone’s old per-MAC config only when its model is known.'); ?></li><li><?php echo _('AD identity and audited extension overrides remain attached to the logical extension.'); ?></li></ul>
        </aside>
      </div>

      <footer class="pe2-footer"><span><?php echo _('Provisioning stays in one pane; only Advanced is separate.'); ?></span><div class="pe2-actions"><button type="submit" class="btn btn-default"><?php echo _('Save only'); ?></button><button type="submit" name="rebuild_after" value="1" class="btn <?php echo $manualConfig ? 'btn-warning' : 'btn-primary'; ?>"><?php echo $manualConfig ? _('Save & replace manual config') : _('Save & Rebuild'); ?></button></div></footer>
    </form>
    <script>
    jQuery(function ($) {
      var initialPhoneLines = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson(json_decode((string)($phone['lines_json'] ?? '[]'), true) ?: []); ?>;
      var allSipExtensions = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson($allSipExtensions); ?>;
      var siteEndpointDefaults = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson([
          'cisco_transport'=>(string)($siteEndpointSettings['cisco_transport'] ?? $siteEndpointSettings['transport'] ?? 'udp'),
          'cisco_sip_port'=>(int)($siteEndpointSettings['cisco_sip_port'] ?? $siteEndpointSettings['sip_port'] ?? 5060),
          'cisco_cnf_transport'=>'udp',
          'cisco_cnf_sip_port'=>(int)($siteEndpointSettings['cisco_cnf_sip_port'] ?? $siteEndpointSettings['cisco_sip_port'] ?? $siteEndpointSettings['sip_port'] ?? 5060),
          'non_cisco_driver'=>(string)($siteEndpointSettings['non_cisco_driver'] ?? 'pjsip'),
          'non_cisco_transport'=>(string)($siteEndpointSettings['non_cisco_transport'] ?? 'udp'),
          'non_cisco_sip_port'=>(int)($siteEndpointSettings['non_cisco_sip_port'] ?? 5160),
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var currentPhoneId = <?php echo $id; ?>;
      var currentPhoneExtension = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson($currentExt); ?>;
      var currentPhoneMac = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson($currentMac); ?>;
      var assignmentPhones = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values(array_map(static fn($p) => [
          'id'=>(int)($p['id'] ?? 0), 'extension'=>(string)($p['extension'] ?? ''),
          'mac'=>(string)($p['mac'] ?? ''), 'label'=>(string)($p['label'] ?? '')
      ], $sitePhones))); ?>;

      $('[data-phone-edit-tab]').on('click', function () {
        var name = String($(this).data('phone-edit-tab') || 'provisioning');
        $('[data-phone-edit-tab]').attr('aria-selected', 'false');
        $(this).attr('aria-selected', 'true');
        $('[data-phone-edit-panel]').prop('hidden', true);
        $('[data-phone-edit-panel="' + name + '"]').prop('hidden', false);
      });
      $('form[name="frm_ucmprovisioner"]').on('change input', ':input:not([type="hidden"])', function () {
        $('#ucm-pe-status').text('Unsaved changes — review the resolved state, then choose Save only or Save & Rebuild.').addClass('is-dirty');
      });

      $('#ucm-phone-site').on('change', function () {
        window.location.href = 'config.php?display=ucmprovisioner&tab=phones&view=phone&site_id=' + encodeURIComponent($(this).val());
      });

      // This block runs after DOM-ready so optional FreePBX widgets are loaded before use.
      $('#ucm-secret').on('input', function () {
        $('#ucm-secret-len').text($(this).val().length);
      });
      $('#ucm-generate-secret').on('click', function () {
        var family = $('#ucm-model option:selected').data('family') || '';
        var length = family === 'cisco_cnf' ? 16 : (family === 'cisco_xml' ? 30 : 32);
        var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%+-_';
        var bytes = new Uint32Array(length);
        if (!window.crypto || !window.crypto.getRandomValues) {
          $('#ucm-secret-action-note').addClass('text-warning').text('Secure random generation is unavailable in this browser. Enter a secret generated by your password manager.');
          return;
        }
        window.crypto.getRandomValues(bytes);
        var value=''; for (var j=0;j<length;j++) { value += alphabet[bytes[j] % alphabet.length]; }
        $('#ucm-secret').val(value).attr('type','text').trigger('input').focus();
        $('#ucm-secret-action-note').addClass('text-warning').text('A new SIP secret has been generated but is not applied until you Save. Copy it now if another device shares this extension; changing it will disconnect every client using the old secret.');
      });
      function filterModels() {
        var vendor = $('#ucm-vendor').val();
        $('#ucm-model option').each(function () {
          var v = $(this).data('vendor');
          $(this).prop('disabled',!!v && v !== vendor).toggle(!v || v === vendor);
        });
        if ($('#ucm-model option:selected').prop('disabled')) $('#ucm-model').val('').trigger('change');
      }
      $('#ucm-vendor').on('change', filterModels);
      filterModels(); // Hide incompatible models when an existing phone first opens.

      function endpointDriverLabel(driver) {
        return String(driver || '').toLowerCase() === 'pjsip' ? 'PJSIP' : 'CHAN_SIP';
      }
      function refreshEndpointProfile() {
        var $model = $('#ucm-model option:selected');
        var vendor = String($model.data('vendor') || $('#ucm-vendor').val() || '').toLowerCase();
        var family = String($model.data('family') || '').toLowerCase();
        var isCisco = vendor === 'cisco' || family.indexOf('cisco_') === 0;
        var isCiscoCnf = family === 'cisco_cnf';
        var $driver = $('#ucm-driver'), driverMode = String($driver.val() || 'inherit').toLowerCase();
        var $transport = $('#ucm-transport input');
        var transportMode = String($transport.filter(':checked').val() || 'inherit').toLowerCase();
        var portMode = parseInt($('#ucm-sip-port').val() || '0', 10) || 0;
        var driver, transport, port, driverSource, transportSource, portSource;
        if (isCisco) {
          driver = 'sip';
          driverSource = 'Cisco UCM requirement';
          $driver.val('inherit').prop('disabled', true);
          $('#ucm-driver-help').text(isCiscoCnf
            ? 'Cisco 7940/7960 CNF phones are fixed to CHAN_SIP/UDP by their SIP firmware. The SIP port may still inherit or be overridden here.'
            : 'Cisco XML USECALLMANAGER phones are fixed to CHAN_SIP. Transport and port may still inherit or be overridden here.');
        } else {
          $driver.prop('disabled', false);
          driverMode = String($driver.val() || 'inherit').toLowerCase();
          driver = driverMode === 'inherit' ? String(siteEndpointDefaults.non_cisco_driver || 'pjsip').toLowerCase() : driverMode;
          driverSource = driverMode === 'inherit' ? 'Non-Cisco site default' : 'Phone override';
          $('#ucm-driver-help').text('Yealink and Grandstream may inherit the non-Cisco site default or use a per-phone override. The selected FreePBX extension must already use the effective driver.');
        }
        if (isCiscoCnf) {
          $transport.prop('disabled', false).filter('[value="tcp"], [value="tls"]').prop('disabled', true);
          if (transportMode !== 'inherit' && transportMode !== 'udp') {
            $transport.filter('[value="inherit"]').prop('checked', true);
            transportMode = 'inherit';
          }
          transport = 'udp';
          transportSource = 'Cisco 7940/7960 SIP firmware (UDP only)';
          port = portMode > 0 ? portMode : parseInt(siteEndpointDefaults.cisco_cnf_sip_port, 10);
          portSource = portMode > 0 ? 'Phone override' : 'Cisco CNF site default';
        } else {
          $transport.prop('disabled', false);
          transport = transportMode === 'inherit'
            ? String(isCisco ? siteEndpointDefaults.cisco_transport : siteEndpointDefaults.non_cisco_transport).toLowerCase()
            : transportMode;
          port = portMode > 0 ? portMode : parseInt(isCisco ? siteEndpointDefaults.cisco_sip_port : siteEndpointDefaults.non_cisco_sip_port, 10);
          transportSource = transportMode === 'inherit' ? (isCisco ? 'Cisco XML site default' : 'Non-Cisco site default') : 'Phone override';
          portSource = portMode > 0 ? 'Phone override' : (isCisco ? 'Cisco XML site default' : 'Non-Cisco site default');
        }
        $('#ucm-transport-help').text(isCiscoCnf ? 'This Cisco 7940/7960 firmware supports UDP only.' : 'Choose UDP, TCP or TLS explicitly, or keep the displayed site default. Save applies this choice to this phone and its extension.');
        var label = endpointDriverLabel(driver) + ' / ' + String(transport || 'udp').toUpperCase() + ' / ' + port;
        $('#ucm-endpoint-effective-value, #ucm-pe-summary-endpoint').text(label);
        $('#ucm-endpoint-effective-source').text('Driver: ' + driverSource + '; transport: ' + transportSource + '; port: ' + portSource + '.');
        var actualDriver = String($('#ucm-extension-select option:selected').data('driver') || '').toLowerCase();
        var mismatch = !!actualDriver && actualDriver !== driver;
        $('#ucm-endpoint-driver-warning').toggle(mismatch).text(mismatch
          ? ('Selected extension is ' + endpointDriverLabel(actualDriver) + ', but this phone resolves to ' + endpointDriverLabel(driver) + '. Choose a matching extension/override or change the extension driver in FreePBX.')
          : '');
        $('#ucm-endpoint-effective').toggleClass('alert-info', !mismatch).toggleClass('alert-warning', mismatch);
        return {driver:driver, actualDriver:actualDriver, mismatch:mismatch, label:label};
      }
      $('#ucm-driver, #ucm-transport input, #ucm-sip-port').on('change input', refreshEndpointProfile);

      // Chosen (bundled by FreePBX core) — searchable/scrollable dropdowns for extension + MAC.
      $('.ucm-chosen').chosen({ width: '100%', allow_single_deselect: true, search_contains: true });

      function phoneText(phone) {
        if (!phone) return 'the other phone';
        return (phone.mac || ('phone #' + phone.id)) + (phone.label ? ' (' + phone.label + ')' : '');
      }
      function fallbackForExtension(extension) {
        var $opt = $('#ucm-extension-select option').filter(function () {
          return String(this.value) === String(extension || '');
        }).first();
        return {
          label: String($opt.attr('data-fallback-label') || extension || ''),
          source: String($opt.attr('data-fallback-source') || 'extension number')
        };
      }
      function assignmentLabelSummary(desired, owner, policy) {
        if (!$('#ucm-sync-assignment-label').is(':checked') || desired === currentPhoneExtension) return '';
        var target = desired ? fallbackForExtension(desired) : null;
        var summary = target
          ? ' Base fallback label becomes “' + target.label + '” (' + target.source + ').'
          : ' Its existing fallback label is kept while unassigned.';
        if (owner && policy === 'swap' && currentPhoneExtension) {
          var displaced = fallbackForExtension(currentPhoneExtension);
          summary += ' The other phone’s fallback becomes “' + displaced.label + '” (' + displaced.source + ').';
        } else if (owner) {
          summary += ' The unassigned phone keeps its existing physical fallback label.';
        }
        return summary;
      }
      function refreshAssignmentPreview() {
        var desired = String($('#ucm-extension-select').val() || '');
        var owner = null;
        assignmentPhones.some(function (phone) {
          if (String(phone.extension || '') === desired && phone.id !== currentPhoneId) { owner = phone; return true; }
          return false;
        });
        $('#ucm-assignment-collision').toggle(!!owner);
        if (owner) {
          var policy = $('#ucm-collision-action').val();
          var otherResult = policy === 'swap' && currentPhoneExtension
            ? ('gets extension ' + currentPhoneExtension)
            : 'becomes unassigned';
          $('#ucm-assignment-preview').text(
            phoneText({id:currentPhoneId,mac:currentPhoneMac}) + ' gets extension ' + desired + '; ' +
            phoneText(owner) + ' ' + otherResult + '. MAC addresses do not change.' +
            assignmentLabelSummary(desired, owner, policy)
          );
          $('#ucm-assignment-ready').hide();
        } else {
          var message = desired
            ? (phoneText({id:currentPhoneId,mac:currentPhoneMac}) + ' will use extension ' + desired + '.')
            : (phoneText({id:currentPhoneId,mac:currentPhoneMac}) + ' will become unassigned.');
          $('#ucm-assignment-ready').text(message + assignmentLabelSummary(desired, null, 'move')).show();
        }
      }
      $('#ucm-collision-action').on('change', refreshAssignmentPreview);

      // DECT Panel Visibility Logic
      function selectedModelName() {
        return String($('#ucm-model option:selected').data('model') || '').toUpperCase();
      }
      function isDectBase() {
        return String($('#ucm-model option:selected').data('role') || '') === 'dect_base';
      }
      function updateModelRoleWarning() {
        var role=String($('#ucm-model option:selected').data('role')||'desk'),name=selectedModelName(),message='';
        if(role==='dect_handset') message=name+' is a DECT handset, not a provisioned SIP endpoint. Select its matching base/manager and map the handset there.';
        else if(role==='dect_radio') message=name+' is a multi-cell DECT radio. It has no independent SIP account configuration and is managed through its DECT manager; multi-cell manager/radio topology editing is not implemented in this revision.';
        else if(role==='dect_manager') message=name+' requires a multi-cell DECT topology. This revision deliberately blocks standalone desk-phone generation until manager/radio topology is configured.';
        $('#ucm-model-role-warning').text(message).toggle(!!message);
      }
      function updateDectVisibility() {
        var vendor = $('#ucm-vendor').val(),$model=$('#ucm-model option:selected'),accounts=parseInt($model.data('accounts')||0,10),handsets=parseInt($model.data('handsets')||0,10);
        if (isDectBase()) {
          $('#ucm-dect-section').show();
          $('#ucm-dect-panel').show();
          if (vendor === 'Yealink') {
            $('#yl-dect-panel').show();
            $('#gs-dect-panel').hide();
          } else {
            $('#yl-dect-panel').hide();
            $('#gs-dect-panel').show();
          }
          $('#yl-dect-panel tbody tr[data-account]').each(function(){$(this).toggle(parseInt($(this).data('account'),10)<=accounts);});
          $('#yl-dect-panel [data-handset]').each(function(){$(this).toggle(parseInt($(this).data('handset'),10)<=handsets);});
          $('#gs-dect-panel tbody tr[data-account]').each(function(){$(this).toggle(parseInt($(this).data('account'),10)<=accounts);});
          $('#gs-dect-panel [data-handset]').each(function(){$(this).toggle(parseInt($(this).data('handset'),10)<=handsets);});
          $('.ucm-dect-handset-row').each(function(){$(this).toggle(parseInt($(this).data('slot'),10)<=handsets);});
          $('.ucm-dect-meta-model option').each(function(){var optionVendor=String($(this).data('vendor')||'');$(this).toggle(!optionVendor||optionVendor===vendor).prop('disabled',!!optionVendor&&optionVendor!==vendor);});
          $('.ucm-gs-profile-col').toggle(String($model.data('model')||'').toUpperCase()!=='DP755');
          var hasSharedProfiles = vendor === 'Grandstream' && /^DP75[02]$/.test(String($model.data('model')||'').toUpperCase());
          $('#ucm-pe-dect-vendor').text(vendor + ' mapping');
          $('#ucm-pe-dect-account-limit').text(accounts);
          $('#ucm-pe-dect-handset-limit').text(handsets);
          $('.ucm-pe-profile-heading').toggle(hasSharedProfiles);
          $('#ucm-pe-profile-help').toggle(hasSharedProfiles);
        } else {
          $('#ucm-dect-section').hide();
          $('#ucm-dect-panel').hide();
        }
        $('#ucm-dect-route-editor').hide();
        renderDectRoutes();
        updatePhoneEditorSummary();
      }
      $('#ucm-vendor, #ucm-model').on('change', function(){updateDectVisibility();updateModelRoleWarning();refreshEndpointProfile();});
      updateDectVisibility();
      updateModelRoleWarning();

      // Populate UI fields from initial JSON
      var overridesObj = {};
      try { overridesObj = JSON.parse($('textarea[name="overrides"]').val() || '{}'); } catch(e) {}
      function readCurrentOverrides(){
        var input=$('textarea[name="overrides"]'),value;
        try{value=JSON.parse(input.val()||'{}');if(!value||Array.isArray(value)||typeof value!=='object')throw new Error('object required');}
        catch(error){input[0].setCustomValidity('Advanced overrides must be a valid JSON object.');return false;}
        input[0].setCustomValidity('');overridesObj=value;return true;
      }
      $('textarea[name="overrides"]').on('input change',function(){
        if(!readCurrentOverrides())return;
        $('.ucm-set-input').each(function(){var field=$(this),value=overridesObj[field.data('key')];if(field.attr('type')==='checkbox')field.prop('checked',value!==undefined&&String(value)===String(field.val()));else field.val(value===undefined?'':value);});
        $('.ucm-dect-handset-row').each(function(){var row=$(this),slot=parseInt(row.data('slot'),10),handset=(Array.isArray(overridesObj.dect_handsets)?overridesObj.dect_handsets:[]).find(function(item){return Number(item.slot)===slot;})||{};row.find('.ucm-dect-meta-model').val(handset.model||'');row.find('.ucm-dect-meta-label').val(handset.label||'');row.find('.ucm-dect-meta-id').val(handset.identifier||'');});
        $('.ucm-dect-handset,.ucm-gs-dect-handset').each(function(){var field=$(this),account=field.data('account'),prefix=field.hasClass('ucm-dect-handset')?'yl':'gs',raw=String(overridesObj[prefix+'_dect_account_'+account+'_handsets']||overridesObj[prefix+'_dect_account_'+account+'_handset']||'');field.prop('checked',raw.split(',').indexOf(String(field.val()))!==-1);});
        refreshDectHandsetLabels();renderDectRoutes();
      });

      $.each(Array.isArray(overridesObj.dect_handsets)?overridesObj.dect_handsets:[],function(_,handset){var row=$('.ucm-dect-handset-row[data-slot="'+parseInt(handset.slot||0,10)+'"]');row.find('.ucm-dect-meta-model').val(String(handset.model||''));row.find('.ucm-dect-meta-label').val(String(handset.label||''));row.find('.ucm-dect-meta-id').val(String(handset.identifier||''));});
      function refreshDectHandsetLabels(){
        $('.ucm-dect-handset-row').each(function(){var row=$(this),slot=parseInt(row.data('slot'),10),label=$.trim(row.find('.ucm-dect-meta-label').val());$('#yl-dect-panel [data-handset="'+slot+'"],#gs-dect-panel [data-handset="'+slot+'"]').filter('th').text('HS '+slot+(label?' · '+label:''));});
      }
      function serializeDectHandsets(){
        if(!readCurrentOverrides())return false;
        var handsets=[];$('.ucm-dect-handset-row').each(function(){var row=$(this),model=String(row.find('.ucm-dect-meta-model').val()||''),label=$.trim(row.find('.ucm-dect-meta-label').val()),identifier=$.trim(row.find('.ucm-dect-meta-id').val());if(model||label||identifier)handsets.push({slot:parseInt(row.data('slot'),10),model:model,label:label,identifier:identifier});});if(handsets.length)overridesObj.dect_handsets=handsets;else delete overridesObj.dect_handsets;$('textarea[name="overrides"]').val(JSON.stringify(overridesObj,null,2));refreshDectHandsetLabels();renderDectRoutes();
      }
      $('.ucm-dect-handset-row').on('change input','select,input',serializeDectHandsets);
      refreshDectHandsetLabels();
      
      $('.ucm-set-input').each(function() {
        var key = $(this).data('key');
        if (typeof overridesObj[key] !== 'undefined') {
          if ($(this).attr('type') === 'checkbox') {
            $(this).prop('checked', overridesObj[key] == $(this).val());
          } else {
            $(this).val(overridesObj[key]);
          }
        }
      });
      $('.ucm-dect-handset').each(function () {
        var account = $(this).data('account'), raw = String(overridesObj['yl_dect_account_' + account + '_handsets'] || '');
        $(this).prop('checked', raw.split(',').indexOf(String($(this).val())) !== -1);
      });
      $('.ucm-gs-dect-handset').each(function () {
        var account = $(this).data('account'), raw = String(overridesObj['gs_dect_account_' + account + '_handsets'] || overridesObj['gs_dect_account_' + account + '_handset'] || '');
        $(this).prop('checked', raw.split(',').indexOf(String($(this).val())) !== -1);
      });
      // Restored DECT membership can change which account-specific fields are relevant.
      updateDectVisibility();

      // Serialize UI fields back to JSON on change
      $('.ucm-set-input').on('change input', function() {
        if(!readCurrentOverrides())return;
        var key = $(this).data('key');
        if ($(this).attr('type') === 'checkbox') {
          if ($(this).is(':checked')) {
            overridesObj[key] = $(this).val();
          } else {
            delete overridesObj[key];
          }
        } else {
          var val = $(this).val();
          if (val === '') {
            delete overridesObj[key];
          } else {
            overridesObj[key] = val;
          }
        }
        $('textarea[name="overrides"]').val(JSON.stringify(overridesObj, null, 2));
      });
      $('.ucm-dect-handset').on('change', function () {
        if(!readCurrentOverrides())return;
        var account = $(this).data('account'), values = [];
        $('.ucm-dect-handset[data-account="' + account + '"]:checked').each(function () { values.push($(this).val()); });
        var key = 'yl_dect_account_' + account + '_handsets';
        if (values.length) { overridesObj[key] = values.join(','); } else { delete overridesObj[key]; }
        $('textarea[name="overrides"]').val(JSON.stringify(overridesObj, null, 2));
        renderDectRoutes();
      });
      $('.ucm-gs-dect-handset').on('change', function () {
        if(!readCurrentOverrides())return;
        var account = $(this).data('account'), values = [];
        $('.ucm-gs-dect-handset[data-account="' + account + '"]:checked').each(function () { values.push($(this).val()); });
        var key = 'gs_dect_account_' + account + '_handsets';
        delete overridesObj['gs_dect_account_' + account + '_handset'];
        if (values.length) { overridesObj[key] = values.join(','); } else { delete overridesObj[key]; }
        $('textarea[name="overrides"]').val(JSON.stringify(overridesObj, null, 2));
        renderDectRoutes();
      });

      var activeDectRouteAccount = 0;
      function activePhoneAccounts() {
        var rows = [], primaryExtension = String($('#ucm-extension-select').val() || '');
        if (primaryExtension) {
          rows.push({account:1, extension:primaryExtension, label:$.trim($('#ucm-label-input').val()) || String($('#ucm-extension-select option:selected').data('desc') || '')});
        }
        $('#ucm-phone-lines-table tbody tr:not(.ucm-primary-line-row)').each(function () {
          var row=$(this),account=parseInt(row.find('.ucm-line-account').val()||0,10),extension=String(row.find('.ucm-line-ext').val()||''),label=$.trim(row.find('.ucm-line-lbl').val());
          if(account&&extension) rows.push({account:account,extension:extension,label:label||String(row.find('.ucm-line-ext option:selected').text()||'').replace(/^\S+\s+[—-]\s+/, '')});
        });
        rows.sort(function(a,b){return a.account-b.account;});
        return rows;
      }
      function routeCheckboxes(account) {
        var selector = $('#ucm-vendor').val() === 'Yealink' ? '.ucm-dect-handset' : '.ucm-gs-dect-handset';
        return $(selector + '[data-account="' + account + '"]');
      }
      function handsetInfo(slot) {
        var row=$('.ucm-dect-handset-row[data-slot="'+slot+'"]'),model=String(row.find('.ucm-dect-meta-model').val()||''),label=$.trim(row.find('.ucm-dect-meta-label').val()),identifier=$.trim(row.find('.ucm-dect-meta-id').val());
        return {slot:slot,model:model,label:label,identifier:identifier,recorded:!!(model||label||identifier)};
      }
      function handsetName(slot) {
        var info=handsetInfo(slot),parts=['HS '+slot];
        if(info.label)parts.push(info.label); else if(info.model)parts.push(info.model);
        return parts.join(' · ');
      }
      function updatePhoneEditorSummary() {
        var $model=$('#ucm-model option:selected'),model=String($model.data('model')||''),vendor=String($('#ucm-vendor').val()||''),role=String($model.data('role')||'desk'),limit=Math.max(1,parseInt($model.data('accounts')||1,10)),accounts=activePhoneAccounts(),extension=String($('#ucm-extension-select').val()||''),description=String($('#ucm-extension-select option:selected').data('desc')||''),mac=String($('#ucm-mac-value').val()||'');
        $('#ucm-pe-summary-extension').text(extension ? extension + (description?' · '+description:'') : 'Unassigned');
        $('#ucm-pe-primary-extension').text(extension||'Unassigned');
        $('#ucm-pe-primary-label').text($.trim($('#ucm-label-input').val())||'Auto');
        $('#ucm-pe-summary-hardware').text(model ? vendor+' '+model : 'Not selected');
        $('#ucm-pe-summary-mac').text(mac||'Not selected');
        $('#ucm-pe-summary-accounts').text(accounts.length+' / '+limit);
        $('#ucm-pe-account-capacity').text(accounts.length+' of '+limit+' accounts');
        $('#ucm-pe-account-note').text('The selected model supports '+limit+' SIP account'+(limit===1?'':'s')+' in total; Account 1 is the primary assignment above.');
        $('#ucm-pe-role-badge').text(role==='dect_base'?'DECT base':(role==='desk'?'Desk phone':role.replace(/_/g,' ')));
        $('#ucm-pe-device-heading').text((model?vendor+' '+model:'Choose a model')+(mac?' · '+mac:''));
      }
      function renderDectRoutes() {
        var $body=$('#ucm-dect-route-list');
        if(!$body.length)return;
        $body.empty();
        if(!isDectBase())return;
        var accounts=activePhoneAccounts(),model=selectedModelName(),hasProfiles=$('#ucm-vendor').val()==='Grandstream'&&/^DP75[02]$/.test(model);
        if(!accounts.length){$body.append($('<tr>').append($('<td colspan="5" class="text-muted">').text('Assign at least one SIP extension above before mapping handsets.')));return;}
        $.each(accounts,function(_,account){
          var selected=[];routeCheckboxes(account.account).filter(':checked').each(function(){selected.push(parseInt($(this).val(),10));});
          var $tr=$('<tr>').attr('data-account',account.account),$identity=$('<div class="pe2-account-id">').append($('<strong>').text(account.extension)).append($('<small>').text(account.label||'Automatic label')),$chips=$('<div class="pe2-route-chips">');
          if(selected.length){$.each(selected,function(_,slot){$chips.append($('<span class="pe2-route-chip">').text(handsetName(slot)));});}else{$chips.append($('<span class="text-muted">').text('No handsets assigned'));}
          $tr.append($('<td>').append($('<strong>').text('Account '+account.account))).append($('<td>').append($identity)).append($('<td>').append($chips));
          var $profileCell=$('<td class="ucm-pe-profile-cell">');
          if(hasProfiles){
            var $source=$('.ucm-set-input[data-key="gs_dect_account_'+account.account+'_profile"]'),$select=$('<select class="form-control input-sm pe2-profile-select" aria-label="Shared SIP server profile">');
            for(var profile=1;profile<=4;profile++)$select.append($('<option>').val(profile).text('Profile '+profile));
            $select.val(String($source.val()||'1')).on('change',function(){$source.val($(this).val()).trigger('change');});$profileCell.append($select);
          } else {$profileCell.append($('<span class="text-muted">').text('Not used'));}
          $tr.append($profileCell).append($('<td>').append($('<button type="button" class="btn btn-default btn-xs ucm-dect-route-edit">').attr('data-account',account.account).text('Edit handsets')));$body.append($tr);
        });
      }
      function openDectRouteEditor(account) {
        activeDectRouteAccount=parseInt(account||0,10);if(!activeDectRouteAccount)return;
        var max=Math.max(0,parseInt($('#ucm-model option:selected').data('handsets')||0,10)),selected={};routeCheckboxes(activeDectRouteAccount).filter(':checked').each(function(){selected[String($(this).val())]=true;});
        var $options=$('#ucm-dect-route-options').empty();
        for(var slot=1;slot<=max;slot++){
          var info=handsetInfo(slot),$label=$('<label class="pe2-route-option">').attr('data-search',[slot,info.model,info.label,info.identifier].join(' ').toLowerCase()),$checkbox=$('<input type="checkbox">').attr('data-slot',slot).prop('checked',!!selected[String(slot)]),$copy=$('<span>').append($('<strong>').text(handsetName(slot))).append($('<small>').text(info.identifier|| (info.recorded?'Identifier not recorded':'Empty inventory record')));
          if(!info.recorded)$label.addClass('is-empty');$label.append($checkbox).append($copy);$options.append($label);
        }
        $('#ucm-dect-route-editor-title').text('Edit Account '+activeDectRouteAccount+' handset assignment');$('#ucm-dect-route-search').val('');$('#ucm-dect-route-editor').show();
      }
      $(document).on('click','.ucm-dect-route-edit',function(){openDectRouteEditor($(this).data('account'));});
      $('#ucm-dect-route-close').on('click',function(){$('#ucm-dect-route-editor').hide();});
      $('#ucm-dect-route-apply').on('click',function(){
        var selected={};$('#ucm-dect-route-options input:checked').each(function(){selected[String($(this).data('slot'))]=true;});
        routeCheckboxes(activeDectRouteAccount).each(function(){$(this).prop('checked',!!selected[String($(this).val())]);}).first().trigger('change');
        renderDectRoutes();$('#ucm-dect-route-editor').hide();
      });
      $('#ucm-dect-route-search').on('input',function(){var term=$.trim($(this).val()).toLowerCase();$('#ucm-dect-route-options .pe2-route-option').each(function(){$(this).toggle(!term||String($(this).data('search')||'').indexOf(term)!==-1);});});
      $('#ucm-dect-inventory-search').on('input',function(){var term=$.trim($(this).val()).toLowerCase();$('.ucm-dect-handset-row').each(function(){var row=$(this),slot=parseInt(row.data('slot'),10),limit=parseInt($('#ucm-model option:selected').data('handsets')||0,10),text=row.text().toLowerCase()+' '+row.find('input,select').map(function(){return $(this).val();}).get().join(' ').toLowerCase();row.toggle(slot<=limit&&(!term||text.indexOf(term)!==-1));});});

      // This is an explicit physical fallback-label choice. Logical AD identity
      // and audited output overrides remain attached to the extension.
      function syncLabelFromExtension() {
        var $opt = $('#ucm-extension-select option:selected');
        var desired = String($opt.val() || '');
        if (!$('#ucm-sync-assignment-label').is(':checked')
            || desired === currentPhoneExtension
            || desired === '') {
          $('#ucm-label-sync-note').hide();
          return;
        }
        var fallback = fallbackForExtension(desired);
        $('#ucm-label-input').val(fallback.label);
        $('#ucm-label-sync-note').text(
          'Fallback preview: “' + fallback.label + '” from ' + fallback.source +
          '. Accepted AD/template/audited output rules are still resolved later at build time.'
        ).show();
      }
      function syncGroupFromExtension() {
        var groupId = parseInt($('#ucm-extension-select option:selected').attr('data-group-id') || '0', 10);
        $('#ucm-phone-group').val(groupId > 0 ? String(groupId) : '');
        $('#ucm-group-changed').val('0');
      }
      $('#ucm-phone-group').on('change', function () { $('#ucm-group-changed').val('1'); });
      $('#ucm-extension-select').on('change', function () { syncLabelFromExtension(); syncGroupFromExtension(); refreshAssignmentPreview(); updatePhoneEditorSummary(); refreshEndpointProfile(); renderDectRoutes(); });
      $('#ucm-sync-assignment-label').on('change', function () { syncLabelFromExtension(); refreshAssignmentPreview(); });
      syncLabelFromExtension();
      syncGroupFromExtension();
      refreshAssignmentPreview();
      $('#ucm-label-input').on('input', function () {
        $('#ucm-sync-assignment-label').prop('checked', false);
        $('#ucm-label-sync-note').hide();
        refreshAssignmentPreview();
        updatePhoneEditorSummary();
        renderDectRoutes();
      });

      function applyLabelLimit() {
        var selected = $('#ucm-model option:selected'), family = selected.data('family') || '', model = String(selected.data('model') || '');
        var phoneMax = parseInt(selected.data('label-phone-max') || 0, 10), lineMax = parseInt(selected.data('label-line-max') || 0, 10);
        var spaces = String(selected.attr('data-label-phone-spaces') || '') !== '0', status = String(selected.data('label-status') || '');
        var inputLimit = Math.min(lineMax || 128, 128);
        $('#ucm-label-input').attr('maxlength', inputLimit);
        var note = 'This fallback accepts ' + inputLimit + ' characters; AD/template values stay canonical and device-specific limits are applied only to final rendered fields.';
        if (family === 'cisco_xml') {
          note = 'Cisco ' + model + ': top/status <phoneLabel> is ' + phoneMax + ' characters'
            + (spaces ? '' : ' and this enterprise XML 79xx firmware does not accept spaces')
            + '; the line-key <featureLabel> is ' + lineMax + ' characters.';
        } else if (family === 'cisco_cnf') {
          note = 'Cisco ' + model + ': top-right phone_label is ' + phoneMax + ' characters; firmware shows separate “'
            + (status || 'SIP') + '” status text. lineX_shortname uses this module’s ' + lineMax
            + '-character safety policy because Cisco publishes no numeric limit; the LCD may clip sooner.';
        } else if (family === 'yealink') {
          note = 'Yealink ' + model + ': account.X.label and DSS labels accept 99 characters; account.X.display_name is a separate 99-character caller identity. DECT bases emit handset.X.name with a 24-character limit.';
        } else if (family === 'grandstream' && model === 'DP755') {
          note = 'Grandstream DP755 alias-v2: account.X.name and SIP subscriber name are separate 512-character configuration fields; this module’s fallback label storage is 128 characters. Handset names are 10 characters.';
        } else if (family === 'grandstream' && /^DP75[02]$/.test(model)) {
          note = 'Grandstream ' + model + ': this P-value profile has no separate per-account LCD label; P418x Name carries caller identity (256 characters). Handset names are separate 10-character fields.';
        } else if (family === 'grandstream') {
          note = 'Grandstream ' + model + ': Account Name and SIP Name/caller identity are emitted separately. Current official desk templates publish no single numeric limit across every supported model; this fallback storage is capped at 128 characters.';
        }
        $('#ucm-label-limit-note').text(note);
        var handsetMax = family === 'yealink' ? 24 : (family === 'grandstream' ? 10 : 32);
        $('.ucm-dect-meta-label').attr('maxlength', handsetMax).attr('title', family === 'yealink'
          ? 'Yealink handset.X.name — 24 characters'
          : (family === 'grandstream' ? 'Grandstream DP75x handset name — 10 characters' : 'Handset display label'));
        var secretMax = family === 'cisco_cnf' ? 16 : (family === 'cisco_xml' ? 30 : 256);
        $('#ucm-secret').attr('maxlength', secretMax);
        $('#ucm-secret-max').text(secretMax);
        $('#ucm-secret-limit-note').text(family === 'cisco_cnf'
          ? 'characters (Cisco 7940/7960 firmware limit)'
          : (family === 'cisco_xml' ? 'characters (Cisco XML SIP limit)' : 'characters'));
        $('#ucm-generate-secret').toggle(family === 'cisco_cnf' || family === 'cisco_xml');
      }
      $('#ucm-model').on('change', applyLabelLimit);
      applyLabelLimit();

      function syncCustomMac() {
        var custom = !$('#ucm-mac-select').val() || $('#ucm-mac-select').val() === '__custom__';
        var $input = $('#ucm-mac-custom');
        var value = String($input.val() || '').replace(/[:.\s-]/g, '').toUpperCase();
        var valid = !custom || value === '' || /^[0-9A-F]{12}$/.test(value);
        var message = valid ? '' : 'Enter exactly 12 hexadecimal characters (0–9, A–F), for example AABBCCDDEEFF.';
        $input.prop('disabled', !custom).attr('aria-invalid', valid ? 'false' : 'true');
        $input.get(0).setCustomValidity(message);
        $('#ucm-mac-error').text(message).prop('hidden', valid);
        if (custom) { $input.val(value); $('#ucm-mac-value').val(value); }
        return valid;
      }

      function syncMacFromSelect() {
        var $selected = $('#ucm-mac-select option:selected');
        var v = $('#ucm-mac-select').val();
        var selectedPhoneId = parseInt($selected.data('phone-id') || '0', 10);
        if (selectedPhoneId && selectedPhoneId !== currentPhoneId) {
          var url = 'config.php?display=ucmprovisioner&tab=phones&view=phone&id=' + encodeURIComponent(selectedPhoneId)
            + '&site_id=' + encodeURIComponent(<?php echo $formSiteId; ?>)
            + '&assign_extension=' + encodeURIComponent($('#ucm-extension-select').val() || '')
            + '&collision_action=' + encodeURIComponent($('#ucm-collision-action').val() || 'swap');
          window.location.href = url;
          return;
        }
        if (v === '__custom__' || !v) {
          $('#ucm-mac-custom').show();
          $('#ucm-mac-value').val($('#ucm-mac-custom').val());
        } else {
          $('#ucm-mac-custom').hide();
          $('#ucm-mac-value').val(v);
        }
        syncCustomMac();
        currentPhoneMac = String($('#ucm-mac-value').val() || '');
        updatePhoneEditorSummary();
      }
      $('#ucm-mac-select').on('change', syncMacFromSelect);
      $('#ucm-mac-custom').on('input', function () {
        syncCustomMac();
        currentPhoneMac = $('#ucm-mac-value').val();
        refreshAssignmentPreview();
        updatePhoneEditorSummary();
      });
      syncMacFromSelect();

      function getAccountLimit() {
        var $opt = $('#ucm-model option:selected');
        return Math.max(1,parseInt($opt.data('accounts') || '1', 10));
      }

      function getPhysicalKeyLimit() {
        return Math.max(1,parseInt($('#ucm-model option:selected').data('keys') || '1',10));
      }

      function serializePhoneLines() {
        var lines = [];
        $('#ucm-phone-lines-table tbody tr:not(.ucm-primary-line-row)').each(function () {
          var account = parseInt($(this).find('.ucm-line-account').val(), 10);
          var button = parseInt($(this).find('.ucm-line-btn').val() || '0', 10);
          var ext = $(this).find('.ucm-line-ext').val();
          var label = $(this).find('.ucm-line-lbl').val();
          lines.push({ account: account || 0, button: button, extension: ext || '', label: label || '' });
        });
        $('#ucm-phone-lines-json').val(JSON.stringify(lines));
        updatePhoneEditorSummary();
        renderDectRoutes();
      }

      function updatePhoneLinesPanel() {
        var limit = getAccountLimit(), keyLimit=getPhysicalKeyLimit(), dect=isDectBase(),cisco=/^cisco_/.test(String($('#ucm-model option:selected').data('family')||''));
        if (limit > 1 || $('#ucm-phone-lines-table tbody tr:not(.ucm-primary-line-row)').length) {
          $('#ucm-phone-lines-panel').show();
        } else {
          $('#ucm-phone-lines-panel').hide();
        }
        $('.ucm-line-appearance-col').toggle(!dect);
        $('#ucm-phone-lines-help small').text(dect
          ? 'Each row is a SIP account on this DECT base. Use the handset matrix below to decide which registered handsets can use or ring for it; handsets do not get separate TFTP files.'
          : 'Each row is another registration on this physical phone. A line appearance is optional; Registration only keeps the account available without consuming a physical key.');

        // Keep saved assignments visible across model changes; validation decides compatibility.
        $('#ucm-phone-lines-table tbody tr:not(.ucm-primary-line-row)').each(function () {
          var account = parseInt($(this).find('.ucm-line-account').val(), 10);
            var $account = $(this).find('.ucm-line-account'),accountValue=$account.val();
            $account.empty();
            for (var i = 2; i <= limit; i++) {
              $account.append($('<option>').val(i).text('Account ' + i));
            }
            if (account > limit) $account.append($('<option>').val(account).text('Account ' + account + ' — exceeds this model’s capacity'));
            $account.val(accountValue);
            var $appearance=$(this).find('.ucm-line-btn'),appearanceValue=parseInt($appearance.val()||'0',10);
            $appearance.empty().append($('<option>').val(0).text(cisco?'Automatic Cisco line key':'Registration only'));
            for(var key=2;key<=keyLimit;key++)$appearance.append($('<option>').val(key).text('Base key '+key));
            if(appearanceValue>keyLimit)$appearance.append($('<option>').val(appearanceValue).text('Base key '+appearanceValue+' — exceeds this model’s capacity'));
            $appearance.val(appearanceValue).prop('disabled',dect);
        });
      }

      function addPhoneLineRow(line) {
        line = line || {};
        var limit = getAccountLimit(),keyLimit=getPhysicalKeyLimit(),cisco=/^cisco_/.test(String($('#ucm-model option:selected').data('family')||''));
        var savedAccount=parseInt(line.account||line.account_index||line.lineIndex||line.button||0,10);
        if (limit <= 1 && !Object.keys(line).length) return;
        if (savedAccount < 2 && Object.keys(line).length) savedAccount=2;
        var $tr = $('<tr>');

        var $accountSelect = $('<select class="form-control input-sm ucm-line-account">');
        for (var i = 2; i <= limit; i++) {
          $accountSelect.append($('<option>').val(i).text('Account ' + i));
        }
        if (savedAccount >= 2) {
          if (savedAccount > limit) $accountSelect.append($('<option>').val(savedAccount).text('Account ' + savedAccount + ' — unavailable on this model'));
          $accountSelect.val(savedAccount);
        } else {
          var used = {};
          $('#ucm-phone-lines-table tbody tr:not(.ucm-primary-line-row)').each(function () {
            used[$(this).find('.ucm-line-account').val()] = true;
          });
          for (var i = 2; i <= limit; i++) {
            if (!used[i]) {
              $accountSelect.val(i);
              break;
            }
          }
        }

        var $btnSelect=$('<select class="form-control input-sm ucm-line-btn">').append($('<option>').val(0).text(cisco?'Automatic Cisco line key':'Registration only'));
        for(var key=2;key<=keyLimit;key++)$btnSelect.append($('<option>').val(key).text('Base key '+key));
        var savedButton=parseInt(line.button||0,10),accountValue=parseInt($accountSelect.val()||0,10);
        if(savedButton>keyLimit)$btnSelect.append($('<option>').val(savedButton).text('Base key '+savedButton+' — unavailable on this model'));
        var initialButton=Object.keys(line).length?savedButton:(cisco&&accountValue<=keyLimit?accountValue:0);$btnSelect.val(initialButton);

        // Extension select options
        var $extSelect = $('<select class="form-control input-sm ucm-line-ext">');
        $extSelect.append($('<option>').val('').text('-- select extension --'));
        $.each(allSipExtensions, function (idx, e) {
          $extSelect.append($('<option>').val(e.id).attr('data-driver', e.driver || 'sip').text(e.id + ' — ' + e.description + ' [' + endpointDriverLabel(e.driver || 'sip') + ']'));
        });
        if (line.extension) {
          if (!$extSelect.find('option').filter(function(){return String($(this).val())===String(line.extension);}).length) {
            $extSelect.append($('<option>').val(line.extension).text(line.extension+' — saved extension unavailable'));
          }
          $extSelect.val(line.extension);
        }
        
        var $lblInput = $('<input class="form-control input-sm ucm-line-lbl" type="text" maxlength="32" placeholder="Auto (optional)">').val(line.label || '');
        var $delBtn = $('<button type="button" class="btn btn-xs btn-danger ucm-line-del">&times;</button>');

        $tr.append($('<td>').append($accountSelect));
        $tr.append($('<td>').append($extSelect));
        $tr.append($('<td>').append($lblInput));
        $tr.append($('<td class="ucm-line-appearance-col">').append($btnSelect));
        $tr.append($('<td>').append($delBtn));
        $('#ucm-phone-lines-table tbody').append($tr);
        updatePhoneLinesPanel();
      }

      $(document).on('click', '#ucm-phone-add-line', function () {
        var limit = getAccountLimit();
        var currentCount = $('#ucm-phone-lines-table tbody tr:not(.ucm-primary-line-row)').length;
        if (currentCount >= (limit - 1)) {
          alert('This model supports ' + limit + ' SIP accounts in total (Account 1 is the primary extension).');
          return;
        }
        addPhoneLineRow({});
        serializePhoneLines();
      });

      $(document).on('click', '.ucm-line-del', function () {
        $(this).closest('tr').remove();
        serializePhoneLines();
      });

      $(document).on('change input', '#ucm-phone-lines-table', serializePhoneLines);
      
      $('#ucm-model').on('change', function () {
        $('#ucm-model-id').val(String($('#ucm-model option:selected').data('id') || ''));
        updatePhoneLinesPanel();
        updatePhoneEditorSummary();
        renderDectRoutes();
      });

      // Load initial lines
      $.each(initialPhoneLines, function (idx, line) {
        addPhoneLineRow(line);
      });
      updatePhoneLinesPanel();
      serializePhoneLines();
      updatePhoneEditorSummary();
      refreshEndpointProfile();
      renderDectRoutes();
      $('form[name="frm_ucmprovisioner"]').on('submit', function (event) {
        if (!syncCustomMac()) {
          event.preventDefault();
          $('#ucm-mac-custom').get(0).reportValidity();
          $('#ucm-mac-custom').focus();
          return;
        }
        var role = String($('#ucm-model option:selected').data('role') || 'desk');
        if (/^dect_(?:handset|radio|manager)$/.test(role)) {
          event.preventDefault();
          alert('This DECT component is managed through its base/system. Select the provisioned DECT base here and record the handset in its inventory instead.');
          $('#ucm-model').focus();
          return;
        }
        var endpointState = refreshEndpointProfile();
        if (endpointState.mismatch) {
          event.preventDefault();
          alert('The selected extension driver does not match the resolved phone endpoint. Correct the Driver selection or use a matching FreePBX extension before saving.');
          $('#ucm-driver').focus();
          return;
        }
        serializePhoneLines();
        var invalidLines=JSON.parse($('#ucm-phone-lines-json').val()).some(function(line){return !line.extension||line.account<2||line.account>getAccountLimit()||(!isDectBase()&&line.button>getPhysicalKeyLimit());});
        if(invalidLines){event.preventDefault();alert('An additional SIP account is incomplete or exceeds this model’s capacity. Correct or explicitly remove that row before saving.');return;}
        if(!readCurrentOverrides()){event.preventDefault();alert('Advanced overrides must be a valid JSON object.');$('textarea[name="overrides"]').focus();}
      });
    });
    </script>
    </div>
    <?php
    return;
}

$allSites = $ucm->getSites();
$sites = [];
foreach ($allSites as $s) { $sites[(int)$s['id']] = $s['name']; }
$localSiteId = $ucm->localSiteId();
$siteFilter = $_REQUEST['site_filter'] ?? ($_REQUEST['site_id'] ?? '');
if ($siteFilter === '') {
    $siteFilter = (string)$localSiteId;
}
$selectedSiteId = ($siteFilter !== '' && $siteFilter !== 'all') ? (int)$siteFilter : $localSiteId;
$inventorySiteIds = $siteFilter === 'all'
    ? array_values(array_unique(array_map(static fn(array $site): int => (int)$site['id'], $allSites)))
    : [$selectedSiteId];
$groupNamesBySite = [];
$extDescsBySite = [];
$extensionIdentityBySite = [];
$endpointSettingsBySite = [];
$inventoryErrors = [];
foreach ($inventorySiteIds as $inventorySiteId) {
    try {
        $endpointSettingsBySite[$inventorySiteId] = $ucm->getSiteSettings($inventorySiteId);
    } catch (\Throwable $e) {
        $endpointSettingsBySite[$inventorySiteId] = [];
        $inventoryErrors[$inventorySiteId][] = _('endpoint defaults: ') . $e->getMessage();
    }
    try {
        foreach ($ucm->getGroups($inventorySiteId) as $g) {
            $groupNamesBySite[$inventorySiteId][(int)$g['id']] = $g['name'];
        }
    } catch (\Throwable $e) {
        $inventoryErrors[$inventorySiteId][] = _('groups: ') . $e->getMessage();
    }
    try {
        foreach ($ucm->getAllSipExtensions($inventorySiteId) as $e) {
            $extDescsBySite[$inventorySiteId][(string)$e['id']] = $e['description'];
            $extensionIdentityBySite[$inventorySiteId][(string)$e['id']] = $e;
        }
    } catch (\Throwable $e) {
        $inventoryErrors[$inventorySiteId][] = _('extensions: ') . $e->getMessage();
    }
}
$showAllExtensions = !empty($_REQUEST['show_all_ext']);
$everyRowForFilter = [];
foreach ($inventorySiteIds as $inventorySiteId) {
    try {
        $everyRowForFilter = array_merge(
            $everyRowForFilter,
            $ucm->getPhones(['site_id'=>$inventorySiteId])
        );
    } catch (\Throwable $e) {
        $inventoryErrors[$inventorySiteId][] = _('phones: ') . $e->getMessage();
    }
}
$realPhones = array_values(array_filter($everyRowForFilter, static fn($row) => trim((string)($row['mac'] ?? '')) !== ''));
$extensionOnlyCount = count($everyRowForFilter) - count($realPhones);
$phones = $showAllExtensions ? $everyRowForFilter : $realPhones;
$templateEngine = new \FreePBX\modules\Ucmprovisioner\TemplateEngine($ucm);
$defaultCidr = '';
foreach ($allSites as $s) {
    if ((int)$s['id'] === $selectedSiteId && !empty($s['voice_subnets'])) {
        $defaultCidr = trim(explode(',', $s['voice_subnets'])[0]);
    }
}
$meshScheduleEnabled = false;
$meshInterval = 60;
$meshSchedule = ['mode'=>'interval','interval_minutes'=>60,'time'=>'02:00','weekdays'=>[1,2,3,4,5,6,7]];
$meshNextRun = 0;
$meshLastStatus = _('Not run yet');
try {
    $meshScheduleEnabled = (string)$ucm->getSetting('blf_mesh_schedule_enabled', $selectedSiteId, '0') === '1';
    $meshInterval = max(15, min(10080, (int)$ucm->getSetting('blf_mesh_interval_minutes', $selectedSiteId, '60')));
    $meshSchedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
        $ucm->getSetting('blf_mesh_schedule_json', $selectedSiteId, ''),
        $meshInterval,
        15
    );
    $meshNextRun = (int)$ucm->getSetting('blf_mesh_next_run', $selectedSiteId, '0');
    $meshLastStatus = (string)$ucm->getSetting('blf_mesh_last_status', $selectedSiteId, _('Not run yet'));
} catch (\Throwable $e) {
    $meshLastStatus = sprintf(_('Schedule status unavailable: %s'), $e->getMessage());
}
$phoneRebuildScheduleEnabled = false;
$phoneRebuildSchedule = ['mode'=>'daily','interval_minutes'=>1440,'time'=>'03:00','weekdays'=>[1,2,3,4,5,6,7]];
$phoneRebuildReboot = false;
$phoneRebuildNextRun = 0;
$phoneRebuildLastStatus = _('Not run yet');
try {
    $phoneRebuildScheduleEnabled = (string)$ucm->getSetting('phone_rebuild_schedule_enabled', $selectedSiteId, '0') === '1';
    $phoneRebuildSchedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
        $ucm->getSetting('phone_rebuild_schedule_json', $selectedSiteId, ''),
        max(15, (int)$ucm->getSetting('phone_rebuild_interval_minutes', $selectedSiteId, '1440')),
        15
    );
    $phoneRebuildReboot = (string)$ucm->getSetting('phone_rebuild_reboot', $selectedSiteId, '0') === '1';
    $phoneRebuildNextRun = (int)$ucm->getSetting('phone_rebuild_next_run', $selectedSiteId, '0');
    $phoneRebuildLastStatus = (string)$ucm->getSetting('phone_rebuild_last_status', $selectedSiteId, _('Not run yet'));
} catch (\Throwable $e) {
    $phoneRebuildLastStatus = sprintf(_('Schedule status unavailable: %s'), $e->getMessage());
}
?>
<div id="ucmp-phones-preview-v2" class="ucm-container pp2-app">
  <header class="pp2-top">
    <div>
      <h2><?php echo _('Phone fleet'); ?></h2>
      <p class="pp2-muted"><?php echo _('Inventory, assignments, generated configurations, device commands, BLF federation and discovery'); ?></p>
    </div>
    <form method="get" id="ucm-site-filter-form" class="pp2-site-context">
      <input type="hidden" name="display" value="ucmprovisioner">
      <input type="hidden" name="tab" value="phones">
      <label for="ucm-site-filter"><?php echo _('Working site'); ?></label>
      <select name="site_filter" id="ucm-site-filter" onchange="this.form.submit()">
        <option value="all" <?php echo ($siteFilter === '' || $siteFilter === 'all') ? 'selected' : ''; ?>><?php echo _('All sites'); ?></option>
        <?php foreach ($allSites as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$siteFilter === (string)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?><?php echo !empty($s['is_local']) ? ' (' . _('local') . ')' : ''; ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </header>

  <nav class="pp2-tabs" aria-label="<?php echo _('Phone fleet work areas'); ?>">
    <button type="button" class="pp2-tab pp2-active" data-phone-tab="fleet" aria-selected="true"><?php echo _('Fleet inventory'); ?></button>
    <button type="button" class="pp2-tab" data-phone-tab="mesh" aria-selected="false"><?php echo _('BLF mesh'); ?></button>
    <button type="button" class="pp2-tab" data-phone-tab="discovery" aria-selected="false"><?php echo _('Discovery & recovery'); ?></button>
  </nav>

  <div class="pp2-live" id="ucm-live-status-result" role="status" aria-live="polite">
    <?php echo sprintf(_('%d physical phone(s) shown. Live registration and reachability refresh automatically every minute.'), count($realPhones)); ?>
  </div>
  <div class="pp2-live" id="ucm-action-result" role="status" aria-live="polite"></div>
  <pre id="ucm-detail-result" class="pp2-detail" style="display:none;"></pre>

  <section class="pp2-view pp2-active ucm-phone-panel" data-phone-panel="fleet">
    <section class="pp2-surface">
      <header class="pp2-surface-head">
        <div class="pp2-head-copy">
          <span class="pp2-kicker"><?php echo _('Routine operations'); ?></span>
          <h3><?php echo _('Fleet inventory'); ?></h3>
          <p class="pp2-muted"><?php echo _('Physical phones, extension bindings, effective template sources and current management state.'); ?></p>
        </div>
        <a class="pp2-btn pp2-primary" href="config.php?display=ucmprovisioner&amp;tab=phones&amp;view=phone&amp;site_id=<?php echo $selectedSiteId; ?>"><?php echo _('Bind / pre-provision phone'); ?></a>
      </header>
      <div class="pp2-toolbar">
        <button class="pp2-btn" type="button" id="ucm-refresh-status"><?php echo _('Refresh live status'); ?></button>
        <button class="pp2-btn" type="button" data-phone-tab-target="discovery"><?php echo _('Discover & recover'); ?></button>
        <button class="pp2-btn ucm-rebuild" type="button" data-scope="all" data-site="<?php echo $selectedSiteId; ?>"><?php echo _('Preview / rebuild all eligible phones'); ?></button>
        <button class="pp2-btn" type="button" data-phone-tab-target="mesh"><?php echo _('Sync BLF mesh'); ?></button>
        <span class="pp2-grow"></span>
        <span class="pp2-muted"><?php echo sprintf(_('%d phone(s) · %d hidden unbound extension(s)'), count($phones), $showAllExtensions ? 0 : $extensionOnlyCount); ?></span>
      </div>
      <div class="pp2-filters" id="ucm-table-filter-row">
        <input type="search" id="ucm-table-filter" placeholder="<?php echo _('Filter extension, label, MAC, model, site, template or status…'); ?>">
        <?php if ($extensionOnlyCount > 0 || $showAllExtensions): ?>
          <a class="pp2-btn" href="config.php?display=ucmprovisioner&amp;tab=phones&amp;site_filter=<?php echo htmlspecialchars($siteFilter); ?>&amp;show_all_ext=<?php echo $showAllExtensions ? '0' : '1'; ?>">
            <?php echo $showAllExtensions ? _('Hide unbound extensions') : sprintf(_('Show %d unbound extension(s)'), $extensionOnlyCount); ?>
          </a>
        <?php endif; ?>
      </div>
      <div class="pp2-selection">
        <strong><span class="ucm-phone-selection-count">0</span> <?php echo _('selected'); ?></strong>
        <button type="button" class="pp2-btn pp2-primary ucm-rebuild" data-scope="selected"><?php echo _('Preview / rebuild selected'); ?></button>
        <button type="button" class="pp2-btn ucm-restart-selected"><?php echo _('Reload config selected'); ?></button>
        <button type="button" class="pp2-btn pp2-danger ucm-delete-selected" disabled><?php echo _('Delete selected'); ?></button>
      </div>
      <div class="pp2-note">
        <?php echo _('A MAC identifies a real provisionable phone, so unbound extensions are hidden by default. Every rebuild starts with a dry-run plan before anything is written.'); ?>
      </div>
      <?php if ($inventoryErrors): ?>
        <div class="pp2-warning">
          <strong><?php echo _('Some site inventory could not be loaded. Last available rows remain visible where possible.'); ?></strong>
          <ul>
            <?php foreach ($inventoryErrors as $errorSiteId => $messages): ?>
              <li><?php echo htmlspecialchars((string)($sites[(int)$errorSiteId] ?? ('Site #' . (int)$errorSiteId))); ?>: <?php echo htmlspecialchars(implode('; ', $messages)); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <div class="pp2-table-wrap">
<table class="table" id="ucm-phones">
  <thead><tr>
    <th><input type="checkbox" id="ucm-check-all" aria-label="<?php echo _('Select every visible phone'); ?>"></th>
    <th class="ucm-sortable" data-column="1" title="<?php echo _('Live registration and reachability'); ?>"><?php echo _('Live'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="2"><?php echo _('Phone / extension'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="3" title="<?php echo _('Endpoint addresses reported by the selected PBX; saved addresses are labeled until a live observation is available'); ?>"><?php echo _('Phone IP'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="4"><?php echo _('Model'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="5"><?php echo _('SIP endpoint'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="6"><?php echo _('Site / group'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="7" title="<?php echo _('Effective template layers and their assignment source'); ?>"><?php echo _('Effective templates'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="8"><?php echo _('Config state'); ?> <span class="ucm-sort-mark"></span></th>
    <th class="ucm-sortable" data-column="9"><?php echo _('Last command'); ?> <span class="ucm-sort-mark"></span></th>
    <th><?php echo _('Actions'); ?></th>
  </tr></thead>
  <tbody>
    <?php foreach ($phones as $p): ?>
      <?php
        $additionalAccounts = json_decode((string)($p['lines_json'] ?? '[]'), true);
        $additionalAccounts = is_array($additionalAccounts) ? $additionalAccounts : [];
        $additionalExtensions = array_values(array_filter(array_map(static fn($line): string => trim((string)($line['extension'] ?? '')), $additionalAccounts)));
        $templateAssignments = [];
        $templateResolutionError = '';
        if (!empty($p['model_id'])) {
            try {
                foreach ($templateEngine->templateAssignmentTrace($p) as $entry) {
                    if (($entry['status'] ?? '') !== 'applied') { continue; }
                    $source = (string)($entry['source'] ?? '');
                    $origin = $source === 'default'
                        ? _('Default')
                        : ($source === 'group'
                            ? sprintf(_('Group: %s'), (string)($entry['group_name'] ?? _('unknown')))
                            : ($source === 'exception'
                                ? sprintf(_('Member exception: %s'), (string)($entry['group_name'] ?? _('group')))
                                : ucfirst($source ?: _('Assigned'))));
                    $templateAssignments[] = [
                        'origin'=>$origin,
                        'name'=>(string)($entry['template_name'] ?? sprintf(_('Template %d'), (int)($entry['template_id'] ?? 0))),
                        'reason'=>(string)($entry['reason'] ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                $templateResolutionError = $e->getMessage();
            }
        }
        $templateFilterText = implode(' ', array_map(
            static fn(array $assignment): string => '(' . $assignment['origin'] . ') ' . $assignment['name'],
            $templateAssignments
        ));
        $rowSiteId = (int)($p['site_id'] ?? $localSiteId) ?: $localSiteId;
        $identityRow = (array)($extensionIdentityBySite[$rowSiteId][(string)$p['extension']] ?? []);
        $identityFullName = !empty($identityRow['identity_resolved']) ? (string)($identityRow['identity']['display_name'] ?? '') : '';
        $identityMode = (string)($identityRow['identity_binding']['mode'] ?? 'unbound');
        $identityAdoption = (string)($identityRow['identity_binding']['adoption'] ?? 'unknown');
        $rowGroupName = (string)($groupNamesBySite[$rowSiteId][(int)($p['group_id'] ?? 0)] ?? '');
        $endpointProfile = \FreePBX\modules\Ucmprovisioner\EndpointProfile::resolve($p, [
            'vendor'=>(string)($p['m_vendor'] ?? $p['vendor'] ?? ''),
            'model'=>(string)($p['m_model'] ?? ''),
            'family'=>(string)($p['m_family'] ?? $p['family'] ?? ''),
        ], (array)($endpointSettingsBySite[$rowSiteId] ?? []));
        $endpointText = \FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($endpointProfile['driver'])
            . ' / ' . strtoupper($endpointProfile['transport']) . ' / ' . $endpointProfile['port'];
        $actualEndpointDriver = strtolower(trim((string)($p['extension_driver'] ?? '')));
        $endpointDriverMismatch = $actualEndpointDriver !== '' && $actualEndpointDriver !== $endpointProfile['driver'];
        $filterText = strtolower(implode(' ', [
            $p['extension'], $p['label'], $identityFullName, $identityMode, $sites[$rowSiteId] ?? '', $rowGroupName,
            $p['m_vendor'] ?? '', $p['m_model'] ?? '', $p['mac'], $p['ip'], $p['status'],
            $p['config_mode'] ?? 'managed', !empty($p['exclude_bulk_rebuild']) ? 'excluded rebuild all' : '',
            implode(' ', $additionalExtensions), $templateFilterText, $endpointText,
        ]));
        $lastCmd = '';
        if (!empty($p['last_command'])) {
            $ago = !empty($p['last_command_at']) ? (time() - (int)$p['last_command_at']) : null;
            $lastCmd = htmlspecialchars($p['last_command']) . ($ago !== null ? ' (' . ($ago < 60 ? $ago . 's' : ($ago < 3600 ? round($ago/60) . 'm' : round($ago/3600) . 'h')) . ' ago)' : '');
        }
        $statusClass = ['bound' => 'label-success', 'unassigned' => 'label-info', 'discovered' => 'label-default'][$p['status']] ?? 'label-default';
        $missingBuildFields = \FreePBX\modules\Ucmprovisioner\Provisioner::missingBuildFields($p);
        $isConfigurable = !$missingBuildFields;
        $manualConfig = (string)($p['config_mode'] ?? 'managed') === 'manual';
        $pending = $isConfigurable && !$manualConfig && ((int)($p['last_applied'] ?? 0) === 0 || (int)$p['updated'] > (int)$p['last_applied']);
        $canSignal = in_array((string)($p['m_family'] ?? ''), ['cisco_xml','cisco_cnf','yealink'], true);
      ?>
      <tr data-ext="<?php echo htmlspecialchars((string)$p['extension']); ?>" data-site="<?php echo $rowSiteId; ?>" data-filter="<?php echo htmlspecialchars($filterText); ?>">
        <td><input type="checkbox" class="ucm-row" value="<?php echo (int)$p['id']; ?>" data-binding-revision="<?php echo \FreePBX\modules\Ucmprovisioner::phoneBindingRevision($p); ?>"></td>
        <td class="ucm-live-cell" data-sort="0"><span class="ucm-live-dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ccc;" title="<?php echo _('Not checked yet — click Refresh Status'); ?>"></span><br><small class="ucm-registration-evidence"><?php echo _('Registration: unknown'); ?></small></td>
        <td class="pp2-phone-identity">
          <div class="pp2-phone-primary">
            <?php if ((string)$p['extension'] !== ''): ?><strong><?php echo htmlspecialchars((string)$p['extension']); ?></strong><?php else: ?><strong class="text-muted"><?php echo _('unassigned'); ?></strong><?php endif; ?>
            <span aria-hidden="true">·</span>
          <?php if ((string)$p['label'] !== ''): ?>
            <?php echo htmlspecialchars((string)$p['label']); ?>
          <?php elseif (!empty($p['extension']) && !empty($extDescsBySite[$rowSiteId][(string)$p['extension']])): ?>
            <span class="text-muted" title="<?php echo _('No custom label set — showing this extension\'s FreePBX name'); ?>"><?php echo htmlspecialchars($extDescsBySite[$rowSiteId][(string)$p['extension']]); ?></span>
          <?php endif; ?>
          </div>
          <div class="pp2-phone-meta ucm-identity-summary">
            <span><?php echo htmlspecialchars($identityFullName !== '' ? $identityFullName : _('No resolved identity')); ?></span>
            <?php if ($identityAdoption !== 'managed' && !empty($identityRow['identity_binding']['source_id'])): ?><span class="label label-warning"><?php echo _('Identity needs review'); ?></span><?php elseif (empty($identityRow['identity_resolved'])): ?><span class="text-muted"><?php echo empty($identityRow['identity_binding']['source_id']) ? _('No directory identity linked') : _('Linked identity unavailable'); ?></span><?php endif; ?>
          </div>
          <details class="ucm-assignment-details"><summary><?php echo _('Advanced details'); ?></summary><dl class="ucm-detail-grid">
            <dt><?php echo _('Relationship'); ?></dt><dd><?php echo htmlspecialchars(str_replace('_', ' ', $identityMode)); ?></dd>
            <dt><?php echo _('Management'); ?></dt><dd><?php echo htmlspecialchars(str_replace('_', ' ', $identityAdoption)); ?></dd>
            <dt><?php echo _('FreePBX name'); ?></dt><dd><?php echo htmlspecialchars((string)($identityRow['observed_freepbx_name'] ?? $identityRow['description'] ?? _('Unknown'))); ?></dd>
            <dt><?php echo _('Last AD snapshot'); ?></dt><dd><?php echo !empty($identityRow['identity_accepted_at']) ? htmlspecialchars(gmdate('d M Y, H:i',(int)$identityRow['identity_accepted_at']) . ' UTC') : _('Not available'); ?></dd>
          </dl></details>
          <?php if (!empty($p['prev_extension']) && $p['prev_extension'] !== $p['extension']): ?>
            <span class="label label-warning" title="<?php echo _('Just reassigned — the physical phone is likely still registered under its old extension until it fetches its new config. Restart/Reboot will automatically target the old one if needed.'); ?>"><?php echo _('was'); ?> <?php echo htmlspecialchars((string)$p['prev_extension']); ?></span>
          <?php endif; ?>
          <div class="pp2-phone-meta">
            <?php if ((string)$p['mac'] !== ''): ?><code><?php echo htmlspecialchars((string)$p['mac']); ?></code><?php else: ?><em><?php echo _('no MAC / phone yet'); ?></em><?php endif; ?>
          </div>
          <?php foreach ($additionalAccounts as $account): $accountExtension=trim((string)($account['extension']??'')); if($accountExtension==='')continue; ?><br><small class="text-muted" title="<?php echo htmlspecialchars(sprintf(_('SIP account %d on this same physical endpoint'), (int)($account['account']??$account['button']??0))); ?>">+ <?php echo htmlspecialchars($accountExtension); ?><?php if(!empty($account['label'])): ?> — <?php echo htmlspecialchars((string)$account['label']); ?><?php endif; ?></small><?php endforeach; ?>
        </td>
        <td class="ucm-phone-ip-cell" data-saved-ip="<?php echo htmlspecialchars((string)$p['ip']); ?>" data-sort="<?php echo htmlspecialchars((string)$p['ip']); ?>"><strong class="ucm-phone-ip"><?php echo htmlspecialchars((string)$p['ip'] !== '' ? (string)$p['ip'] : _('Unknown')); ?></strong><br><small class="text-muted ucm-phone-ip-source"><?php echo (string)$p['ip'] !== '' ? _('Saved address · checking') : _('Checking PBX'); ?></small></td>
        <td><?php echo htmlspecialchars(trim(($p['m_vendor'] ?? '') . ' ' . ($p['m_model'] ?? ''))); ?></td>
        <td data-sort="<?php echo htmlspecialchars(strtolower($endpointText)); ?>"><strong title="<?php echo htmlspecialchars($endpointProfile['driver_source'] . '; ' . $endpointProfile['transport_source'] . '; ' . $endpointProfile['port_source']); ?>"><?php echo htmlspecialchars($endpointText); ?></strong><?php if ($endpointDriverMismatch): ?><br><span class="label label-warning" title="<?php echo htmlspecialchars(sprintf(_('FreePBX extension is %s; edit this phone or the extension driver before rebuilding.'), \FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($actualEndpointDriver))); ?>"><?php echo _('driver mismatch'); ?></span><?php endif; ?></td>
        <td><strong><?php echo htmlspecialchars($sites[$rowSiteId] ?? ''); ?></strong><?php if ($rowGroupName !== ''): ?><br><span class="pp2-muted"><?php echo htmlspecialchars($rowGroupName); ?></span><?php endif; ?></td>
        <td>
          <?php if ($templateAssignments): ?>
            <?php foreach ($templateAssignments as $assignment): ?>
              <div class="pp2-template" title="<?php echo htmlspecialchars((string)$assignment['reason']); ?>"><span><?php echo htmlspecialchars((string)$assignment['origin']); ?></span> <?php echo htmlspecialchars((string)$assignment['name']); ?></div>
            <?php endforeach; ?>
          <?php elseif ($templateResolutionError !== ''): ?>
            <span class="label label-warning" title="<?php echo htmlspecialchars($templateResolutionError); ?>"><?php echo _('unresolved'); ?></span>
          <?php elseif (!empty($p['model_id'])): ?>
            <span class="text-muted"><em><?php echo _('No matching template'); ?></em></span>
          <?php else: ?>
            <span class="text-muted"><em><?php echo _('No model'); ?></em></span>
          <?php endif; ?>
        </td>
        <td>
          <span class="label <?php echo $statusClass; ?>"><?php echo htmlspecialchars((string)$p['status']); ?></span>
          <?php if ($manualConfig): ?>
            <span class="label label-danger" title="<?php echo _('This root phone config was explicitly edited/uploaded in TFTP Browser. Rebuild All will not replace it.'); ?>"><?php echo _('manual config'); ?></span>
          <?php endif; ?>
          <?php if (!empty($p['exclude_bulk_rebuild'])): ?>
            <span class="label label-default" title="<?php echo _('This phone config is skipped by Rebuild All; individual rebuild remains available.'); ?>"><?php echo _('excluded from all'); ?></span>
          <?php endif; ?>
          <?php if ($pending): ?>
            <span class="label label-warning" title="<?php echo _('This phone\'s settings changed (or were never applied) since the last successful Rebuild — its live config may be out of date until you Rebuild.'); ?>"><?php echo _('rebuild pending'); ?></span>
          <?php endif; ?>
          <?php if ($missingBuildFields): ?><span class="label label-warning"><?php echo htmlspecialchars('Needs ' . implode(', ', $missingBuildFields)); ?></span><?php endif; ?>
          <details class="ucm-phone-evidence ucm-assignment-details" data-site="<?php echo $rowSiteId; ?>" data-phone="<?php echo (int)$p['id']; ?>"><summary><?php echo _('Deployment details'); ?></summary>
            <small class="ucm-evidence-values" style="display:block;white-space:pre-line"><?php echo _('Desired: not read') . "\n" . _('Published: not read') . "\n" . _('PBX active: unknown') . "\n" . _('Notification: unknown') . "\n" . _('Device applied: unknown'); ?></small>
            <button type="button" class="btn btn-default btn-xs ucm-read-evidence"><?php echo _('Read retained evidence'); ?></button>
          </details>
        </td>
        <td><small><?php echo $lastCmd; ?></small></td>
        <td class="pp2-actions">
          <div class="btn-group btn-group-xs">
            <a class="btn btn-default" href="config.php?display=ucmprovisioner&amp;tab=phones&amp;view=phone&amp;id=<?php echo (int)$p['id']; ?>&amp;site_id=<?php echo (int)$p['site_id']; ?>"><?php echo _('Edit'); ?></a>
            <button class="btn btn-default ucm-preview" data-id="<?php echo (int)$p['id']; ?>" data-site="<?php echo (int)$p['site_id']; ?>" title="<?php echo _('Preview generated config'); ?>"><?php echo _('Preview'); ?></button>
            <button class="btn <?php echo $manualConfig ? 'btn-warning' : 'btn-info'; ?> ucm-rebuild" <?php echo $isConfigurable ? '' : 'disabled'; ?> data-scope="ids" data-ids="<?php echo (int)$p['id']; ?>" data-site="<?php echo (int)$p['site_id']; ?>" data-replace-manual="<?php echo $manualConfig ? '1' : '0'; ?>" title="<?php echo htmlspecialchars($missingBuildFields ? 'Assign ' . implode(', ', $missingBuildFields) . ' in Edit first' : ($manualConfig ? _('Preview, then explicitly replace this manual TFTP file with the generated config') : _('Rebuild + apply just this phone (still previews first)'))); ?>"><?php echo $manualConfig ? _('Replace config') : _('Rebuild'); ?></button>
            <button class="btn btn-default <?php echo $canSignal?'ucm-cmd':''; ?>" <?php echo $canSignal?'':'disabled'; ?> data-id="<?php echo (int)$p['id']; ?>" data-cmd="restart" data-site="<?php echo (int)$p['site_id']; ?>" title="<?php echo $canSignal?_('Ask the phone to reload its configuration'):_('This family has no safe provisioning reload command'); ?>"><?php echo _('Reload'); ?></button>
            <button class="btn btn-default <?php echo $canSignal?'ucm-cmd':''; ?>" <?php echo $canSignal?'':'disabled'; ?> data-id="<?php echo (int)$p['id']; ?>" data-cmd="reboot" data-site="<?php echo (int)$p['site_id']; ?>" title="<?php echo $canSignal?_('Warm reboot; does not erase settings'):_('This family has no safe provisioning reboot command'); ?>"><?php echo _('Reboot'); ?></button>
            <a class="btn btn-danger" href="config.php?display=ucmprovisioner&amp;tab=phones&amp;action=delphone&amp;id=<?php echo (int)$p['id']; ?>&amp;site_id=<?php echo (int)$p['site_id']; ?>" title="<?php echo _('Delete this phone binding (and its TFTP config file, best-effort)'); ?>" onclick="return confirm('<?php echo _('Delete this phone binding? This removes it from ucmprovisioner (and tries to remove its TFTP config file) but never touches the FreePBX extension itself.'); ?>');"><?php echo _('Delete'); ?></a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$phones): ?>
      <tr><td colspan="11" class="text-muted"><?php echo _('No phones yet. Use Discovery & recovery to reconcile existing extensions, or bind a phone manually.'); ?></td></tr>
    <?php endif; ?>
  </tbody>
</table>
      </div>
    </section>
    <section class="pp2-surface ucm-top-gap">
      <header class="pp2-surface-head"><div class="pp2-head-copy"><span class="pp2-kicker"><?php echo _('Site automation'); ?></span><h3><?php echo _('Scheduled configuration rebuild'); ?></h3><p class="pp2-muted"><?php echo _('The same saved schedule appears in Site Settings. Each run previews and commits one verified Rebuild All generation; optional reboot happens only after success.'); ?></p></div></header>
      <div class="pp2-mesh-grid"><div class="pp2-pad">
        <form method="post" class="pp2-phone-rebuild-schedule" action="config.php?display=ucmprovisioner&amp;tab=phones">
          <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="phones"><input type="hidden" name="action" value="savesettings"><input type="hidden" name="site_id" value="<?php echo $selectedSiteId; ?>"><input type="hidden" name="site_filter" value="<?php echo htmlspecialchars((string)$siteFilter); ?>">
          <div class="row"><div class="col-md-4"><label class="pp2-field"><span><?php echo _('Automatic rebuild'); ?></span><select name="settings[phone_rebuild_schedule_enabled]" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>><option value="0" <?php echo !$phoneRebuildScheduleEnabled?'selected':''; ?>><?php echo _('Disabled'); ?></option><option value="1" <?php echo $phoneRebuildScheduleEnabled?'selected':''; ?>><?php echo _('Enabled'); ?></option></select></label></div><div class="col-md-4"><label class="pp2-field"><span><?php echo _('After a successful rebuild'); ?></span><select name="settings[phone_rebuild_reboot]" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>><option value="0" <?php echo !$phoneRebuildReboot?'selected':''; ?>><?php echo _('Do not reboot phones'); ?></option><option value="1" <?php echo $phoneRebuildReboot?'selected':''; ?>><?php echo _('Warm reboot supported phones'); ?></option></select></label></div><div class="col-md-4"><strong><?php echo _('Last result'); ?></strong><p class="pp2-muted"><?php echo htmlspecialchars($phoneRebuildLastStatus); ?><?php if($phoneRebuildNextRun>0): ?><br><?php echo sprintf(_('Next run: %s'),htmlspecialchars(date('Y-m-d H:i',$phoneRebuildNextRun))); ?><?php endif; ?></p></div></div>
          <input type="hidden" name="settings[phone_rebuild_schedule_json]" id="ucm-phones-rebuild-schedule-json" value="<?php echo htmlspecialchars(\FreePBX\modules\Ucmprovisioner\RecurringSchedule::encode($phoneRebuildSchedule),ENT_QUOTES,'UTF-8'); ?>"><input type="hidden" name="settings[phone_rebuild_interval_minutes]" id="ucm-phones-rebuild-interval" value="<?php echo (int)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::compatibilityMinutes($phoneRebuildSchedule); ?>">
          <div class="ucmp-schedule-editor pp2-schedule-editor" id="ucm-phones-rebuild-schedule" data-schedule-editor>
            <div class="ucmp-schedule-heading"><strong><?php echo _('Rebuild timing'); ?></strong><span><?php echo _('Uses the owning PBX local time'); ?></span></div>
            <label><?php echo _('Cadence'); ?><select data-schedule-mode <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>><option value="manual" <?php echo $phoneRebuildSchedule['mode']==='manual'?'selected':''; ?>><?php echo _('Manual only'); ?></option><option value="interval" <?php echo $phoneRebuildSchedule['mode']==='interval'?'selected':''; ?>><?php echo _('Minute interval'); ?></option><option value="daily" <?php echo $phoneRebuildSchedule['mode']==='daily'?'selected':''; ?>><?php echo _('Daily at a time'); ?></option><option value="weekly" <?php echo $phoneRebuildSchedule['mode']==='weekly'?'selected':''; ?>><?php echo _('Selected weekdays'); ?></option></select></label>
            <label data-schedule-field="interval"><?php echo _('Every (minutes)'); ?><input type="number" min="15" max="10080" step="1" data-schedule-interval value="<?php echo (int)$phoneRebuildSchedule['interval_minutes']; ?>" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>></label>
            <label data-schedule-field="clock"><?php echo _('PBX local time'); ?><input type="time" step="60" data-schedule-time value="<?php echo htmlspecialchars($phoneRebuildSchedule['time']); ?>" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>></label>
            <fieldset data-schedule-field="weekly" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>><legend><?php echo _('Run on'); ?></legend><div class="ucmp-weekdays"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" data-schedule-weekday value="<?php echo $day; ?>" <?php echo in_array($day,$phoneRebuildSchedule['weekdays'],true)?'checked':''; ?>> <?php echo _($label); ?></label><?php endforeach; ?></div></fieldset>
          </div>
          <?php if($siteFilter==='all'): ?><div class="pp2-warning"><?php echo _('Choose one specific working site before changing its schedule.'); ?></div><?php else: ?><button type="submit" class="pp2-btn pp2-primary"><?php echo _('Save rebuild schedule'); ?></button><?php endif; ?>
        </form>
      </div><div class="pp2-safe"><strong><?php echo _('Safety'); ?></strong><p><?php echo _('The scheduler defers while FreePBX is writing Asterisk configuration. Manual overrides and Rebuild All exclusions are honored. Cisco and Yealink receive a supported warm-reboot command; Grandstream is never sent a guessed command.'); ?></p></div></div>
    </section>
  </section>

  <section class="pp2-view ucm-phone-panel" data-phone-panel="mesh" hidden>
    <section class="pp2-surface">
      <header class="pp2-surface-head">
        <div class="pp2-head-copy">
          <span class="pp2-kicker"><?php echo _('Independent federation job'); ?></span>
          <h3><?php echo _('Cross-site BLF mesh'); ?></h3>
          <p class="pp2-muted"><?php echo _('Preview and reconcile the explicit inter-site PJSIP publication peers without coupling site availability to ordinary phone rebuilds.'); ?></p>
        </div>
        <button type="button" class="pp2-btn pp2-primary ucm-meshsync"><?php echo _('Preview / sync BLF mesh'); ?></button>
      </header>
      <div class="pp2-mesh-grid">
        <div class="pp2-pad">
          <form method="post" class="pp2-mesh-schedule" action="config.php?display=ucmprovisioner&amp;tab=phones">
            <input type="hidden" name="display" value="ucmprovisioner">
            <input type="hidden" name="tab" value="phones">
            <input type="hidden" name="action" value="savesettings">
            <input type="hidden" name="site_id" value="<?php echo $selectedSiteId; ?>">
            <input type="hidden" name="site_filter" value="<?php echo htmlspecialchars((string)$siteFilter); ?>">
            <span class="pp2-label"><?php echo sprintf(_('Recurring schedule for %s'), htmlspecialchars((string)($sites[$selectedSiteId] ?? _('selected site')))); ?></span>
            <label class="pp2-field">
              <span><?php echo _('Automatic mesh sync'); ?></span>
              <select name="settings[blf_mesh_schedule_enabled]" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>>
                <option value="0" <?php echo !$meshScheduleEnabled ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
                <option value="1" <?php echo $meshScheduleEnabled ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
              </select>
            </label>
            <input type="hidden" name="settings[blf_mesh_schedule_json]" id="ucm-phones-mesh-schedule-json" value="<?php echo htmlspecialchars(\FreePBX\modules\Ucmprovisioner\RecurringSchedule::encode($meshSchedule),ENT_QUOTES,'UTF-8'); ?>"><input type="hidden" name="settings[blf_mesh_interval_minutes]" id="ucm-phones-mesh-interval-compat" value="<?php echo (int)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::compatibilityMinutes($meshSchedule); ?>">
            <div class="ucmp-schedule-editor pp2-schedule-editor" id="ucm-phones-mesh-schedule" data-schedule-editor>
              <div class="ucmp-schedule-heading"><strong><?php echo _('Mesh timing'); ?></strong><span><?php echo sprintf(_('PBX timezone: %s'),htmlspecialchars(date_default_timezone_get())); ?></span></div>
              <label><?php echo _('Cadence'); ?><select data-schedule-mode <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>><option value="manual" <?php echo $meshSchedule['mode']==='manual'?'selected':''; ?>><?php echo _('Manual only'); ?></option><option value="interval" <?php echo $meshSchedule['mode']==='interval'?'selected':''; ?>><?php echo _('Minute interval'); ?></option><option value="daily" <?php echo $meshSchedule['mode']==='daily'?'selected':''; ?>><?php echo _('Daily at a time'); ?></option><option value="weekly" <?php echo $meshSchedule['mode']==='weekly'?'selected':''; ?>><?php echo _('Selected weekdays'); ?></option></select></label>
              <label data-schedule-field="interval"><?php echo _('Every (minutes)'); ?><input type="number" min="15" max="10080" step="1" data-schedule-interval value="<?php echo (int)$meshSchedule['interval_minutes']; ?>" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>></label>
              <label data-schedule-field="clock"><?php echo _('PBX local time'); ?><input type="time" step="60" data-schedule-time value="<?php echo htmlspecialchars($meshSchedule['time']); ?>" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>></label>
              <fieldset data-schedule-field="weekly" <?php echo $siteFilter === 'all' ? 'disabled' : ''; ?>><legend><?php echo _('Run on'); ?></legend><div class="ucmp-weekdays"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" data-schedule-weekday value="<?php echo $day; ?>" <?php echo in_array($day,$meshSchedule['weekdays'],true)?'checked':''; ?>> <?php echo _($label); ?></label><?php endforeach; ?></div></fieldset>
            </div>
            <?php if ($siteFilter === 'all'): ?>
              <div class="pp2-warning"><?php echo _('Choose one specific working site before changing its schedule.'); ?></div>
            <?php else: ?>
              <button type="submit" class="pp2-btn pp2-primary"><?php echo _('Save mesh schedule'); ?></button>
            <?php endif; ?>
            <p class="pp2-muted"><strong><?php echo _('Last scheduled result:'); ?></strong> <?php echo htmlspecialchars($meshLastStatus); ?><?php if($meshNextRun>0): ?><br><?php echo sprintf(_('Next run: %s'),htmlspecialchars(date('Y-m-d H:i',$meshNextRun))); ?><?php endif; ?></p>
          </form>
        </div>
        <div class="pp2-safe">
          <strong><?php echo _('Write safety'); ?></strong>
          <p><?php echo _('A mesh apply is deferred while FreePBX/Asterisk configuration files are being generated or are still settling. Remote participants prepare first; the controller commits last, with rollback on failure.'); ?></p>
          <p><?php echo _('Ordinary phone rebuilds never invoke this cross-site operation.'); ?></p>
        </div>
      </div>
    </section>
  </section>

  <section class="pp2-view ucm-phone-panel" data-phone-panel="discovery" hidden>
    <div class="pp2-discovery-grid">
      <section class="pp2-surface pp2-flow-card">
        <span class="pp2-step">1</span>
        <h3><?php echo _('Reconcile registered phones'); ?></h3>
        <p class="pp2-muted"><?php echo _('Discover reads existing CHAN_SIP and PJSIP extensions and endpoint details. It updates labels, registration data, IP, driver, vendor/model and a MAC when the phone exposes one. It does not scan a subnet.'); ?></p>
        <button type="button" class="pp2-btn pp2-primary ucm-action" data-command="discover" data-site="<?php echo $selectedSiteId; ?>"><?php echo sprintf(_('Run Discover on %s'), htmlspecialchars((string)($sites[$selectedSiteId] ?? _('selected site')))); ?></button>
      </section>
      <section class="pp2-surface pp2-flow-card">
        <span class="pp2-step">2</span>
        <h3><?php echo _('Recover identities from TFTP'); ?></h3>
        <p class="pp2-muted"><?php echo _('Scan existing Cisco, Yealink and Grandstream per-device config filenames. A missing MAC is filled only when one extension has exactly one unambiguous candidate. Discover runs this automatically, but this manual retry remains available.'); ?></p>
        <button type="button" class="pp2-btn" id="ucm-backfill-macs" data-site="<?php echo $selectedSiteId; ?>"><?php echo _('Recover MACs from TFTP configs'); ?></button>
      </section>
      <section class="pp2-surface pp2-flow-card">
        <span class="pp2-step">3</span>
        <h3><?php echo _('Bind / pre-provision'); ?></h3>
        <p class="pp2-muted"><?php echo _('Create or correct the physical phone binding, model, extension, group, label override and management policy after discovery. FreePBX extensions are never created or deleted here.'); ?></p>
        <a class="pp2-btn" href="config.php?display=ucmprovisioner&amp;tab=phones&amp;view=phone&amp;site_id=<?php echo $selectedSiteId; ?>"><?php echo _('Bind / pre-provision phone'); ?></a>
      </section>
    </div>

    <section class="pp2-surface" id="ucm-netscan-panel">
      <header class="pp2-surface-head">
        <div class="pp2-head-copy">
          <span class="pp2-kicker"><?php echo _('Unregistered devices'); ?></span>
          <h3><?php echo _('Network scan'); ?></h3>
          <p class="pp2-muted"><?php echo _('Enter an IPv4 network range, for example 10.20.30.0/24, to scan from this site. No saved discovery range is required. The scan finds live IPs and probes web interfaces for vendor hints without changing phones or configurations.'); ?></p>
        </div>
      </header>
      <div class="pp2-pad">
        <div class="pp2-scan-controls">
          <select id="ucm-scan-site" aria-label="<?php echo _('Scan site'); ?>">
            <?php foreach ($allSites as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === $selectedSiteId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <input id="ucm-scan-cidr" value="<?php echo htmlspecialchars($defaultCidr); ?>" placeholder="10.20.30.0/24" aria-label="<?php echo _('Voice subnet CIDR'); ?>">
          <select id="ucm-scan-method" aria-label="<?php echo _('Scan method'); ?>">
            <option value="nmap"><?php echo _('nmap ping sweep'); ?></option>
            <option value="arp"><?php echo _('ARP table (local L2 only)'); ?></option>
          </select>
          <button type="button" class="pp2-btn pp2-primary" id="ucm-scan-go"><?php echo _('Scan'); ?></button>
        </div>
        <details><summary>Privileged scan with a PBX administrator</summary><p>Authenticate with a local Linux administrator account on the selected PBX and run nmap there. Remote requests use the encrypted federation API; scanning never uses SSH. Credentials stay in the current request; no password or sudo rule is saved.</p><div class="pp2-scan-controls"><input id="ucm-scan-admin-user" autocomplete="username" placeholder="PBX administrator username" aria-label="Scan administrator username"><input type="password" id="ucm-scan-admin-pass" autocomplete="off" placeholder="Administrator password" aria-label="Scan administrator password"><label><input type="checkbox" id="ucm-scan-sudo" checked> Use sudo</label><button type="button" class="pp2-btn" id="ucm-scan-clear-password">Clear password</button></div></details>
        <p id="ucm-scan-status" class="pp2-muted"></p>
        <div class="pp2-warning"><?php echo _('A routed nmap scan may find the phone IP without its MAC. ARP cannot cross a router; use the phone label or switch CAM table when Layer-2 identity is unavailable.'); ?></div>
        <div class="pp2-table-wrap">
          <table class="table" id="ucm-scan-results" style="display:none;">
            <thead><tr><th><?php echo _('IP'); ?></th><th><?php echo _('MAC'); ?></th><th><?php echo _('Vendor hint'); ?></th><th><?php echo _('HTTP guess'); ?></th><th><?php echo _('Status'); ?></th><th><?php echo _('Action'); ?></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </section>
  </section>
<script>
(function ($) {
  function phoneMeshSchedule(){var box=$('#ucm-phones-mesh-schedule'),mode=String(box.find('[data-schedule-mode]').val()||'interval'),days=box.find('[data-schedule-weekday]:checked').map(function(){return parseInt(this.value,10);}).get();return{mode:mode,interval_minutes:parseInt(box.find('[data-schedule-interval]').val(),10)||15,time:String(box.find('[data-schedule-time]').val()||'02:00'),weekdays:mode==='weekly'?days:[1,2,3,4,5,6,7]};}
  function refreshPhoneMeshSchedule(){var box=$('#ucm-phones-mesh-schedule');if(!box.length)return null;var value=phoneMeshSchedule();box.find('[data-schedule-field="interval"]').prop('hidden',value.mode!=='interval');box.find('[data-schedule-field="clock"]').prop('hidden',['daily','weekly'].indexOf(value.mode)===-1);box.find('[data-schedule-field="weekly"]').prop('hidden',value.mode!=='weekly');$('#ucm-phones-mesh-schedule-json').val(JSON.stringify(value));$('#ucm-phones-mesh-interval-compat').val(value.mode==='manual'?0:(value.mode==='daily'?1440:(value.mode==='weekly'?10080:value.interval_minutes)));return value;}
  $('#ucm-phones-mesh-schedule').on('input change',':input',refreshPhoneMeshSchedule);refreshPhoneMeshSchedule();
  $('.pp2-mesh-schedule').on('submit',function(event){var value=refreshPhoneMeshSchedule();if(value&&value.mode==='weekly'&&!value.weekdays.length){event.preventDefault();alert('Choose at least one weekday for the weekly BLF mesh schedule.');}});
  function refreshPhoneRebuildSchedule(){var box=$('#ucm-phones-rebuild-schedule');if(!box.length)return null;var mode=String(box.find('[data-schedule-mode]').val()||'daily'),days=box.find('[data-schedule-weekday]:checked').map(function(){return parseInt(this.value,10);}).get(),value={mode:mode,interval_minutes:parseInt(box.find('[data-schedule-interval]').val(),10)||15,time:String(box.find('[data-schedule-time]').val()||'03:00'),weekdays:mode==='weekly'?days:[1,2,3,4,5,6,7]};box.find('[data-schedule-field="interval"]').prop('hidden',mode!=='interval');box.find('[data-schedule-field="clock"]').prop('hidden',['daily','weekly'].indexOf(mode)===-1);box.find('[data-schedule-field="weekly"]').prop('hidden',mode!=='weekly');$('#ucm-phones-rebuild-schedule-json').val(JSON.stringify(value));$('#ucm-phones-rebuild-interval').val(mode==='manual'?0:(mode==='daily'?1440:(mode==='weekly'?10080:value.interval_minutes)));return value;}
  $('#ucm-phones-rebuild-schedule').on('input change',':input',refreshPhoneRebuildSchedule);refreshPhoneRebuildSchedule();
  $('.pp2-phone-rebuild-schedule').on('submit',function(event){var value=refreshPhoneRebuildSchedule();if(value&&value.mode==='weekly'&&!value.weekdays.length){event.preventDefault();alert('Choose at least one weekday for the weekly phone rebuild schedule.');}});
  function selectPhonePanel(name) {
    $('.pp2-tab[data-phone-tab]').each(function () {
      var active = String($(this).data('phone-tab')) === name;
      $(this).toggleClass('pp2-active', active).attr('aria-selected', active ? 'true' : 'false');
    });
    $('.ucm-phone-panel').each(function () {
      var active = String($(this).data('phone-panel')) === name;
      $(this).prop('hidden', !active).toggleClass('pp2-active', active);
    });
  }
  $(document).on('click', '.pp2-tab[data-phone-tab], [data-phone-tab-target]', function () {
    selectPhonePanel(String($(this).data('phone-tab') || $(this).data('phone-tab-target')));
  });

  $('#ucm-scan-clear-password').on('click',function(){$('#ucm-scan-admin-pass').val('');});
  $('#ucm-scan-site').on('change',function(){$('#ucm-scan-admin-pass').val('');});
  $('#ucm-scan-go').on('click', function () {
    if ($(this).prop('disabled')) { return; }
    var $status = $('#ucm-scan-status');
    var $tbl = $('#ucm-scan-results');
    var cidr = $('#ucm-scan-cidr').val();
    var siteId = $('#ucm-scan-site').val();
    var method = $('#ucm-scan-method').val();
    if (!cidr) { $status.addClass('text-danger').text('Enter a CIDR, e.g. 10.20.30.0/24'); return; }
    $('#ucm-scan-go, #ucm-scan-site').prop('disabled',true);
    $status.removeClass('text-danger text-success').text('Scanning (this can take a minute)...');
    $tbl.hide().find('tbody').empty();
    window.ucmpAjax({data:{command: 'netscan', site_id: siteId, cidr: cidr, method: method, admin_user:$('#ucm-scan-admin-user').val(),admin_pass:$('#ucm-scan-admin-pass').val(),use_sudo:$('#ucm-scan-sudo').is(':checked')?1:0 }})
      .done(function (resp) {
        var r = (resp && resp.result) || {};
        if (!(resp && resp.status && r.ok)) {
          $status.addClass('text-danger').text((r && r.message) || 'Scan failed');
          return;
        }
        $status.addClass('text-success').text(r.message || 'Done.');
        var hosts = r.hosts || [];
        if (!hosts.length) { return; }
        var $body = $tbl.find('tbody').empty();
        hosts.forEach(function (h) {
          var $row=$('<tr>'), href='config.php?display=ucmprovisioner&tab=phones&view=phone&site_id='+encodeURIComponent(siteId)+'&prefill_ip='+encodeURIComponent(h.ip || '')+'&prefill_mac='+encodeURIComponent(h.mac || '')+'&prefill_vendor='+encodeURIComponent(h.vendor_hint || h.http_guess || '');
          $row.append($('<td>').text(h.ip || ''), $('<td>').text(h.mac || 'unknown'), $('<td>').text(h.vendor_hint || ''), $('<td>').text(h.http_guess || ''), $('<td>').append($('<span class="label">').addClass(h.already_known?'label-default':'label-info').text(h.already_known?'already bound':'new')), $('<td>').append($('<a class="btn btn-xs btn-primary">').attr('href',href).text('Bind this')));
          $body.append($row);
        });
        $tbl.show();
      })
      .fail(function () { $status.addClass('text-danger').text('Request failed'); })
      .always(function () { $('#ucm-scan-go, #ucm-scan-site').prop('disabled',false); });
  });

  // Quick client-side filter over the whole table (extension/label/MAC/model/site/status).
  $('#ucm-table-filter').on('input', function () {
    var q = $(this).val().toLowerCase();
    $('#ucm-phones tbody tr[data-filter]').each(function () {
      var $tr = $(this);
      $tr.toggle(($tr.data('filter').toString() + ' ' + $tr.find('.ucm-phone-ip').text()).toLowerCase().indexOf(q) !== -1);
    });
  });

  // Sort the already-loaded inventory. This never re-queries a local or remote PBX.
  var phoneSort = { column: -1, direction: 1 };
  function sortPhoneRows(column, direction) {
    var rows = $('#ucm-phones tbody tr[data-filter]').get();
    rows.sort(function (a, b) {
      var ac = $(a).children('td').eq(column), bc = $(b).children('td').eq(column);
      var av = String(ac.attr('data-sort') !== undefined ? ac.attr('data-sort') : ac.text()).trim();
      var bv = String(bc.attr('data-sort') !== undefined ? bc.attr('data-sort') : bc.text()).trim();
      var an = Number(av), bn = Number(bv), result;
      if (av !== '' && bv !== '' && !isNaN(an) && !isNaN(bn)) { result = an - bn; }
      else { result = av.localeCompare(bv, undefined, {numeric:true, sensitivity:'base'}); }
      return result * direction;
    });
    $.each(rows, function (_index, row) { $('#ucm-phones tbody').append(row); });
    phoneSort = { column: column, direction: direction };
    $('#ucm-phones th.ucm-sortable').attr('aria-sort', 'none').find('.ucm-sort-mark').text('');
    $('#ucm-phones th.ucm-sortable[data-column="'+column+'"]').attr('aria-sort', direction === 1 ? 'ascending' : 'descending').find('.ucm-sort-mark').text(direction === 1 ? '▲' : '▼');
  }
  $('#ucm-phones').on('click', 'th.ucm-sortable', function () {
    var column = parseInt($(this).data('column'), 10);
    var direction = phoneSort.column === column ? phoneSort.direction * -1 : 1;
    sortPhoneRows(column, direction);
  });

  // Live status: registered-with-Asterisk + ping reachability. Auto-runs once on page load and
  // every AUTO_REFRESH_MS after that; the button forces an immediate refresh (and resets the
  // timer so it doesn't fire again right on its heels).
  var AUTO_REFRESH_MS = 60000;
  var refreshTimer = null;
  var refreshInFlight = false;
  var inventoryRefreshInFlight = false;

  function refreshInventory() {
    if (inventoryRefreshInFlight) { return; }
    inventoryRefreshInFlight = true;
    var q = $('#ucm-table-filter').val() || '';
    var current = new URL(window.location.href);
    var params = new URLSearchParams({display: 'ucmprovisioner', tab: 'phones'});
    var siteFilter = $('#ucm-site-filter').val() || '';
    var showAll = current.searchParams.get('show_all_ext');
    if (siteFilter) { params.set('site_filter', siteFilter); }
    if (showAll !== null) { params.set('show_all_ext', showAll); }
    var url = current.pathname + '?' + params.toString() + ' #ucm-phones tbody > *';
    $('#ucm-phones tbody').load(url, function (_response, status) {
      inventoryRefreshInFlight = false;
      if (status !== 'success') {
        $('#ucm-live-status-result').removeClass('text-success').addClass('text-danger').text('The phone inventory could not be refreshed automatically. The last action result is retained.');
        return;
      }
      $('#ucm-table-filter').val(q).trigger('input');
      $('#ucm-check-all').prop('checked', false).trigger('change');
      if (phoneSort.column >= 0) { sortPhoneRows(phoneSort.column, phoneSort.direction); }
      refreshLiveStatus(false);
    });
  }

  function paintPhoneIp($row, info, failed) {
    var $cell = $row.find('.ucm-phone-ip-cell');
    var saved = String($cell.attr('data-saved-ip') || '');
    var contacts = info && info.contacts && info.contacts.length ? info.contacts : (info ? [info] : []);
    var ips = [];
    contacts.forEach(function (contact) {
      var ip = String(contact.ip || '').trim();
      if (ip && ips.indexOf(ip) === -1) { ips.push(ip); }
    });
    var label = ips.length ? (ips.length > 1 ? 'Multiple PBX contacts' : 'PBX contact')
      : (saved ? 'Saved address' : 'No address');
    if (failed) { label += ' · check failed'; }
    else if (!ips.length) { label += ' · no live contact'; }
    else if (!info.registered) { label += ' · not registered'; }
    $cell.attr('data-sort', ips.join(' ') || saved);
    $cell.find('.ucm-phone-ip').text(ips.join(', ') || saved || 'Unknown');
    $cell.find('.ucm-phone-ip-source').text(label);
  }

  function paintLiveStatus(bySite, failures) {
    var statusCount = 0;
    Object.keys(bySite).forEach(function (siteId) {
      statusCount += Object.keys(bySite[siteId] || {}).length;
    });
    $('#ucm-phones tbody tr[data-ext][data-site]').each(function () {
      var siteId = String($(this).data('site') || '');
      var ext = String($(this).data('ext') || '');
      var $cell = $(this).find('.ucm-live-cell');
      var $dot = $(this).find('.ucm-live-dot');
      var $registration = $(this).find('.ucm-registration-evidence');
      var info = (bySite[siteId] || {})[ext];
      paintPhoneIp($(this), failures[siteId] ? null : info, !!failures[siteId]);
      if (failures[siteId]) {
        $cell.attr('data-sort', '0');
        $dot.css('background', '#ccc').attr('title', 'Status unavailable for site ' + siteId + ': ' + failures[siteId]);
        $registration.text('Registration: unknown — check failed');
        return;
      }
      if (!info) {
        $cell.attr('data-sort', '0');
        $dot.css('background', '#ccc').attr('title', 'No SIP peer data for this extension at site ' + siteId);
        $registration.text('Registration: unknown — no peer observation');
        return;
      }
      var color, title;
      if (info.registered && info.reachable !== false) {
        color = '#5cb85c'; title = 'Registered' + (info.status_raw ? ' (' + info.status_raw + ')' : '') + (info.reachable ? ', ping OK' : '');
        $cell.attr('data-sort', '3');
      } else if (!info.registered && info.reachable) {
        color = '#f0ad4e'; title = 'NOT registered with Asterisk, but IP responds to ping — check the phone\'s SIP config/credentials';
        $cell.attr('data-sort', '2');
      } else if (info.registered && info.reachable === false) {
        color = '#f0ad4e'; title = 'Registered (may be cached/stale) but does not respond to ping right now';
        $cell.attr('data-sort', '2');
      } else {
        color = '#d9534f'; title = 'Not registered, not reachable — ' + (info.status_raw || 'down');
        $cell.attr('data-sort', '1');
      }
      $dot.css('background', color).attr('title', title);
      $registration.text('Registration: ' + (info.registered ? 'reported registered' : 'not registered') + '\nResult received ' + new Date().toLocaleString());
    });
    if (phoneSort.column === 1 || phoneSort.column === 3) { sortPhoneRows(phoneSort.column, phoneSort.direction); }
    var failedSites = Object.keys(failures);
    var $result = $('#ucm-live-status-result').removeClass('text-danger text-success');
    if (failedSites.length) {
      $result.addClass('text-danger').text('Status updated, but ' + failedSites.length + ' site(s) were unavailable: ' + failedSites.join(', '));
    } else {
      $result.addClass('text-success').text('Status as of ' + new Date().toLocaleTimeString() + ' (' + statusCount + ' extension(s); auto-refreshes every minute).');
    }
    $result.toggleClass('pp2-live-error', failedSites.length > 0);
  }

  function refreshLiveStatus(forced) {
    if (refreshInFlight) { return; }
    refreshInFlight = true;
    var $btn = $('#ucm-refresh-status');
    var selectedSite = String($('#ucm-site-filter').val() || '');
    var siteIds = [];
    var seenSites = {};
    if (selectedSite && selectedSite !== 'all') {
      siteIds.push(selectedSite);
    } else {
      $('#ucm-phones tbody tr[data-site]').each(function () {
        var siteId = String($(this).data('site') || '');
        if (siteId && !seenSites[siteId]) {
          seenSites[siteId] = true;
          siteIds.push(siteId);
        }
      });
    }
    if (forced) { $btn.prop('disabled', true).text('Checking...'); }
    var bySite = {};
    var failures = {};
    var remaining = siteIds.length;
    var finish = function () {
      paintLiveStatus(bySite, failures);
      refreshInFlight = false;
      $btn.prop('disabled', false).text('Refresh live status');
    };
    if (!remaining) { finish(); return; }
    siteIds.forEach(function (siteId) {
      window.ucmpAjax({data: {command: 'livestatus', site_id: siteId } })
        .done(function (resp) {
          var r = (resp && resp.result) || {};
          if (resp && resp.status && r.ok) {
            bySite[siteId] = r.byExt || {};
          } else {
            failures[siteId] = (r && r.message) || 'Status check failed';
          }
        })
        .fail(function () {
          failures[siteId] = 'Status check request failed';
        })
        .always(function () {
          remaining--;
          if (remaining === 0) { finish(); }
        });
    });
  }

  function scheduleAutoRefresh() {
    if (refreshTimer) { clearInterval(refreshTimer); }
    refreshTimer = setInterval(function () { refreshLiveStatus(false); }, AUTO_REFRESH_MS);
  }

  $('#ucm-refresh-status').on('click', function () { refreshLiveStatus(true); scheduleAutoRefresh(); });
  $(document).on('ucm:phone-command-complete', function () { refreshInventory(); });

  $('#ucm-backfill-macs').on('click', function () {
    var $btn = $(this);
    var $result = $('#ucm-action-result');
    $btn.prop('disabled', true).text('Scanning TFTP configs...');
    $result.removeClass('text-danger text-success').text('Working...');
    window.ucmpAjax({data: {command: 'backfillmacs', site_id: $btn.data('site') } })
      .done(function (resp) {
        var r = (resp && resp.result) || {};
        if (!(resp && resp.status && r.ok)) {
          $result.addClass('text-danger').text((r && r.message) || 'Backfill failed');
          return;
        }
        $result.addClass('text-success').text(r.message || 'Done.');
        var detail = '';
        if ((r.filled || []).length) { detail += 'Filled in:\n' + r.filled.join('\n') + '\n\n'; }
        if ((r.ambiguous || []).length) { detail += 'Left for manual entry (multiple historical candidates):\n' + r.ambiguous.join('\n'); }
        if (detail) { $('#ucm-detail-result').show().text(detail); }
        if ((r.filled || []).length) { refreshInventory(); }
      })
      .fail(function () { $result.addClass('text-danger').text('Request failed'); })
      .always(function () { $btn.prop('disabled', false).text('Recover MACs from TFTP configs'); });
  });
  $(document).on('ucm:rebuild-applied ucm:phones-changed', function () { refreshInventory(); });

  // Kick off automatically as soon as the Phones page opens — no manual click needed to see
  // whether the fleet is actually alive.
  refreshLiveStatus(false);
  scheduleAutoRefresh();
})(jQuery);
</script>
<div class="modal fade" id="ucm-preview-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title"><?php echo _('Config preview'); ?></h4>
      </div>
      <div class="modal-body">
        <pre id="ucm-preview-body" style="max-height:60vh;overflow:auto;"></pre>
      </div>
    </div>
  </div>
</div>
</div>
