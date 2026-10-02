<?php
/** Sites: federated control-plane registry and two-stage least-privilege bootstrap. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$view = $_REQUEST['view'] ?? '';

if ($view === 'configuration-sync') {
    $sites = array_values(array_filter($ucm->getSites(), static fn(array $site): bool => !empty($site['enabled'])));
    try { $syncJobs = (array)($ucm->configurationSyncOp(['op'=>'list'])['jobs'] ?? []); }
    catch (\Throwable $error) { $syncJobs = []; $syncLoadError = $error->getMessage(); }
    $syncLoadError = $syncLoadError ?? '';
    $scopeLabels = [
        'templates'=>[_('Templates'), _('Template policy; destination-owned PBX addresses and credentials stay local')],
        'groups'=>[_('Groups'), _('Group hierarchy and template layers; each site keeps its own members')],
        'default_templates'=>[_('Default-template rules'), _('Model/series/family selectors and their template dependencies')],
        'site_defaults'=>[_('Legacy asset-default jobs'), _('Firmware, timezone and wallpaper defaults; individual field choices below also apply')],
        'settings_sip_service'=>[_('Endpoint and service defaults'), _('Independent Cisco XML, Cisco CNF and non-Cisco driver, transport and port defaults, plus proxy and voicemail')],
        'settings_blf_federation'=>[_('BLF federation schedule'), _('Mesh schedule only; site trunk identity stays local')],
        'settings_phone_automation'=>[_('Scheduled phone rebuild and reboot'), _('One site-owned rebuild cadence and optional supported-device reboot policy')],
        'settings_clock_date'=>[_('Clock and date'), _('NTP and vendor timezone/date policy')],
        'settings_network_vlan'=>[_('Network and VLAN'), _('Default voice/PC VLAN intent')],
        'settings_default_template_selectors'=>[_('Settings default-template selectors'), _('The selector matrix shown in Settings')],
        'settings_assets'=>[_('Firmware and wallpaper site defaults'), _('Copies the exact referenced package/catalog first, then applies the default')],
        'settings_cisco_policy'=>[_('Cisco device policy'), _('Security, locale and personalization defaults')],
        'wallpaper_catalogs'=>[_('All Media catalogs'), _('Wallpaper catalogs, special/legacy media and ringtones')],
        'firmware'=>[_('All Firmware inventory'), _('Complete firmware packages, every retained Cisco runtime timezone revision, and complete locale companions')],
        'phonebooks'=>[_('Phonebook definitions'), _('Directory books and presentation/search policy')],
        'web_directory'=>[_('Web-directory settings'), _('Employee web access, selected published books, fields, export and address; optional hard source lock')],
    ];
    ?>
    <div class="ucm-container ucm-configuration-sync" id="ucm-configuration-sync">
      <?php require __DIR__ . '/settings_sync_fields.php'; ?>
      <div class="ucm-page-intro"><div><span class="ucm-kicker"><?php echo _('Federated policy'); ?></span><h2><?php echo _('Configuration sync'); ?></h2><p><?php echo _('Push selected provisioning policy from one PBX to other sites without copying PBX addresses, credentials, AD bindings, trunks, TFTP paths, or other destination identity. Missing assets fall back to the destination default and remain visibly tracked.'); ?></p></div><a class="btn btn-default" href="config.php?display=ucmprovisioner&amp;tab=sites"><?php echo _('Back to sites'); ?></a></div>
      <?php if ($syncLoadError): ?><div class="alert alert-danger"><?php echo htmlspecialchars($syncLoadError); ?></div><?php endif; ?>
      <div class="ucm-workbench-layout">
        <aside class="ucm-card ucm-workbench-list">
          <div class="ucm-card-heading"><div><h3><?php echo _('Sync jobs'); ?></h3><p><?php echo _('Each job owns its own source, targets, scope, cadence and object-level pause state.'); ?></p></div><button type="button" class="btn btn-primary btn-sm" id="ucm-cfg-new"><?php echo _('New job'); ?></button></div>
          <div id="ucm-cfg-jobs" class="ucm-cfg-job-list"></div>
        </aside>
        <main class="ucm-card ucm-workbench-editor">
          <div class="ucm-card-heading"><div><h3 id="ucm-cfg-title"><?php echo _('New configuration sync job'); ?></h3><p><?php echo _('Changes are staged in this editor. Saving a job does not immediately push or rebuild phones.'); ?></p></div><span class="label label-default" id="ucm-cfg-status"><?php echo _('not saved'); ?></span></div>
          <input type="hidden" id="ucm-cfg-id" value="0">
          <div class="row">
            <div class="col-md-5"><div class="form-group"><label for="ucm-cfg-name"><?php echo _('Job name'); ?></label><input class="form-control" id="ucm-cfg-name" maxlength="80" placeholder="<?php echo _('Example: Headquarters fleet policy'); ?>"></div></div>
            <div class="col-md-4"><div class="form-group"><label for="ucm-cfg-source"><?php echo _('Source PBX'); ?></label><select class="form-control" id="ucm-cfg-source"><?php foreach ($sites as $site): ?><option value="<?php echo (int)$site['id']; ?>"><?php echo htmlspecialchars((string)$site['name']); ?></option><?php endforeach; ?></select></div></div>
            <div class="col-md-3"><div class="checkbox ucm-top-gap"><label><input type="checkbox" id="ucm-cfg-enabled" checked> <?php echo _('Automatic sync enabled'); ?></label></div><div class="checkbox"><label><input type="checkbox" id="ucm-cfg-lock" checked> <?php echo _('Soft-lock pushed objects'); ?></label></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><fieldset class="ucm-picker"><legend><?php echo _('Target PBXs'); ?></legend><div class="ucm-picker-toolbar"><input type="search" class="form-control ucm-picker-search" id="ucm-cfg-target-search" placeholder="<?php echo _('Search sites'); ?>"><div class="ucm-picker-actions"><button type="button" class="btn btn-default btn-sm" data-picker-action="target-visible"><?php echo _('Select visible'); ?></button><button type="button" class="btn btn-default btn-sm" data-picker-action="target-all"><?php echo _('Select all'); ?></button><button type="button" class="btn btn-default btn-sm" data-picker-action="target-clear"><?php echo _('Clear'); ?></button></div><span class="ucm-picker-count" id="ucm-cfg-target-count"></span></div><div class="ucm-picker-list" id="ucm-cfg-target-list"><?php foreach ($sites as $site): ?><label class="ucm-choice-card" data-picker-text="<?php echo htmlspecialchars(strtolower((string)$site['name'])); ?>"><input type="checkbox" class="ucm-cfg-target" value="<?php echo (int)$site['id']; ?>"> <span><strong><?php echo htmlspecialchars((string)$site['name']); ?></strong><small><?php echo !empty($site['is_local'])?_('local controller'):_('federated site'); ?></small></span></label><?php endforeach; ?></div></fieldset></div>
            <div class="col-md-6"><fieldset class="ucm-picker"><legend><?php echo _('Objects to push'); ?></legend><div class="ucm-picker-toolbar"><input type="search" class="form-control ucm-picker-search" id="ucm-cfg-scope-search" placeholder="<?php echo _('Search object scopes'); ?>"><div class="ucm-picker-actions"><button type="button" class="btn btn-default btn-sm" data-picker-action="scope-visible"><?php echo _('Select visible'); ?></button><button type="button" class="btn btn-default btn-sm" data-picker-action="scope-all"><?php echo _('Select all'); ?></button><button type="button" class="btn btn-default btn-sm" data-picker-action="scope-clear"><?php echo _('Clear'); ?></button></div><span class="ucm-picker-count" id="ucm-cfg-scope-count"></span></div><div class="ucm-picker-list" id="ucm-cfg-scope-list"><?php foreach ($scopeLabels as $scope=>$label): ?><label class="ucm-choice-card" data-picker-text="<?php echo htmlspecialchars(strtolower($label[0].' '.$label[1])); ?>"><input type="checkbox" class="ucm-cfg-scope" value="<?php echo htmlspecialchars($scope); ?>"> <span><strong><?php echo htmlspecialchars($label[0]); ?></strong><small><?php echo htmlspecialchars($label[1]); ?></small></span></label><?php endforeach; ?></div></fieldset></div>
          </div>
          <div class="alert alert-info"><strong><?php echo _('Same-name destination objects are preserved until you confirm replacement.'); ?></strong> <?php echo _('A confirmed source revision replaces that same named catalog/template/setting only. Dependencies selected by a setting are copied before the setting; if a dependency cannot be verified, destination policy is left unchanged.'); ?></div>
          <section class="ucm-inline-panel" id="ucm-cfg-web-options" hidden>
            <h4><?php echo _('Web-directory source policy'); ?></h4>
            <p><?php echo _('Every selected receiver will show the source site and effective settings. Required phonebooks must already be published there under the same keys; missing dependencies leave its current policy unchanged. This does not replicate contacts or configure DNS/TLS.'); ?></p>
            <label class="checkbox"><input type="checkbox" id="ucm-cfg-web-confirm"> <?php echo _('Apply the source access networks, proxy mode, visible fields and export permission to these targets, including scheduled future source changes.'); ?></label>
            <label class="checkbox"><input type="checkbox" id="ucm-cfg-web-hard-lock"> <?php echo _('Hard lock: prevent receiver edits and enforce the source policy. Without it, a local edit pauses this policy until resumed.'); ?></label>
            <p class="help-block"><?php echo _('Delete or change the controlling job to release ownership. Merely disabling its schedule leaves the existing lock in place.'); ?></p>
          </section>
          <section class="ucm-inline-panel">
            <h4><?php echo _('Automatic cadence'); ?></h4>
            <div class="row">
              <div class="col-md-3"><label for="ucm-cfg-mode"><?php echo _('Schedule'); ?></label><select class="form-control" id="ucm-cfg-mode"><option value="interval"><?php echo _('Every interval'); ?></option><option value="daily"><?php echo _('Daily'); ?></option><option value="weekly"><?php echo _('Selected weekdays'); ?></option></select></div>
              <div class="col-md-3 ucm-cfg-interval"><label for="ucm-cfg-minutes"><?php echo _('Interval minutes'); ?></label><input class="form-control" id="ucm-cfg-minutes" type="number" min="5" max="10080" value="60"></div>
              <div class="col-md-3 ucm-cfg-clock" hidden><label for="ucm-cfg-time"><?php echo _('PBX local time'); ?></label><input class="form-control" id="ucm-cfg-time" type="time" value="02:00"></div>
              <div class="col-md-3"><div class="checkbox ucm-top-gap"><label><input type="checkbox" id="ucm-cfg-retry" checked> <?php echo _('Retry unreachable sites after 5 minutes'); ?></label></div></div>
            </div>
            <div class="ucm-cfg-weekdays" hidden><label><?php echo _('Weekdays'); ?></label><div><?php foreach (['1'=>_('Mon'),'2'=>_('Tue'),'3'=>_('Wed'),'4'=>_('Thu'),'5'=>_('Fri'),'6'=>_('Sat'),'7'=>_('Sun')] as $day=>$label): ?><label class="checkbox-inline"><input type="checkbox" class="ucm-cfg-weekday" value="<?php echo $day; ?>" <?php echo in_array($day,['1','2','3','4','5'],true)?'checked':''; ?>> <?php echo htmlspecialchars($label); ?></label><?php endforeach; ?></div></div>
          </section>
          <div class="ucm-action-row"><button type="button" class="btn btn-primary" id="ucm-cfg-save"><?php echo _('Save job'); ?></button><button type="button" class="btn btn-info" id="ucm-cfg-run" disabled><?php echo _('Run now'); ?></button><button type="button" class="btn btn-danger" id="ucm-cfg-delete" disabled><?php echo _('Delete job'); ?></button><span id="ucm-cfg-feedback" class="ucm-inline-action-feedback" aria-live="polite"></span></div>
          <section class="ucm-inline-panel" id="ucm-cfg-results-panel" hidden><h4><?php echo _('Last run by destination'); ?></h4><div id="ucm-cfg-results" aria-live="polite"></div></section>
          <section class="ucm-inline-panel" id="ucm-cfg-items-panel" hidden><h4><?php echo _('Destination object state'); ?></h4><p class="help-block"><?php echo _('A destination edit pauses only that item. Resume it here after reviewing the destination change; deletions never propagate automatically.'); ?></p><div class="table-responsive"><table class="table table-condensed"><thead><tr><th><?php echo _('Target'); ?></th><th><?php echo _('Object'); ?></th><th><?php echo _('State'); ?></th><th><?php echo _('Reason / dependency'); ?></th><th></th></tr></thead><tbody id="ucm-cfg-items"></tbody></table></div></section>
        </main>
      </div>
    </div>
    <script>
    (function($){
      var jobs=<?php echo json_encode($syncJobs, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>||[];
      var siteNames=<?php echo json_encode(array_column($sites,'name','id'), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>||{};
      var selected=null, dirty=false, busy=false, editorGeneration=0, refreshGeneration=0;
      function esc(v){ return $('<div>').text(v==null?'':String(v)).html(); }
      function markDirty(){ dirty=true; editorGeneration++; $('#ucm-cfg-status').attr('class','label label-warning').text('unsaved changes'); }
      function selectJob(job){if(busy||(dirty&&!window.confirm('Discard unsaved job changes?')))return;load(job);}
      function setBusy(value){busy=value;$('#ucm-cfg-save,#ucm-cfg-new').prop('disabled',busy);$('#ucm-cfg-run,#ucm-cfg-delete').prop('disabled',busy||!selected);$('.ucm-cfg-job').prop('disabled',busy);}
      function drawResult(result){
        var box=$('#ucm-cfg-results').empty(),rows=result&&result.destination_results||[];
        $('#ucm-cfg-results-panel').prop('hidden',!result);
        if(!result)return;
        box.append($('<strong>').text(result.destination_summary||result.message||'No per-site completion was recorded.'));
        rows.forEach(function(row){var status={success:'Succeeded',error:'Failed',partial:'Partially completed',unknown:'Needs review'}[row.status]||'Not completed',details=$('<details>').prop('open',row.status!=='success'),list=$('<ul>');
          details.append($('<summary>').text((siteNames[row.site_id]||row.name||('Site #'+row.site_id))+' — '+status));
          (row.details||[]).forEach(function(item){list.append($('<li>').text((item.scope?item.scope+': ':'')+(item.message||'No completion recorded')+(item.recovery_required?' · Review retained transaction before retrying':'')));});
          box.append(details.append(list));
        });
        if(result.reporting_error)box.append($('<p class="text-warning">').text('Results were returned, but saving the run record failed: '+result.reporting_error));
      }
      function pickerRows(kind){return $('#ucm-cfg-'+kind+'-list .ucm-choice-card');}
      function refreshPicker(kind){var rows=pickerRows(kind),term=String($('#ucm-cfg-'+kind+'-search').val()||'').toLowerCase().trim();rows.each(function(){$(this).prop('hidden',term!==''&&String($(this).data('picker-text')||'').indexOf(term)===-1);});var boxes=rows.find('input:not(:disabled)'),visible=rows.filter(':not([hidden])').find('input:not(:disabled)'),selected=boxes.filter(':checked').length;$('#ucm-cfg-'+kind+'-count').text(selected+' selected · '+visible.length+' visible · '+boxes.length+' available');}
      function pickerAction(kind,action){var boxes=pickerRows(kind).filter(action==='visible'?':not([hidden])':'*').find('input:not(:disabled)');if(action==='clear')boxes=pickerRows(kind).find('input:not(:disabled)');boxes.prop('checked',action!=='clear').trigger('change');refreshPicker(kind);}
      function schedule(job){ var s=job&&job.schedule||{}; return {mode:s.mode||'interval',interval_minutes:s.interval_minutes||job&&job.interval_minutes||60,time:s.time||'02:00',weekdays:s.weekdays||[1,2,3,4,5]}; }
      function drawList(){ var $list=$('#ucm-cfg-jobs').empty(); if(!jobs.length){$list.append($('<p class="text-muted">').text('No configuration sync jobs yet.'));return;} jobs.forEach(function(job){ var $b=$('<button type="button" class="ucm-cfg-job">').prop('disabled',busy).toggleClass('is-active',selected&&Number(selected.id)===Number(job.id)); $b.append($('<strong>').text(job.name),$('<span>').text((job.source_site_name||'Source')+' → '+(job.target_site_names||[]).join(', ')),$('<small>').text((job.schedule_description||'manual')+' · '+(job.last_status||'never'))); $b.on('click',function(){selectJob(job);}); $list.append($b); }); }
      function drawItems(job){ var items=(job&&job.items)||[], $body=$('#ucm-cfg-items').empty(); $('#ucm-cfg-items-panel').prop('hidden',!items.length); items.forEach(function(item){ var detail=''; var missing=item.missing||{}; if(Object.keys(missing).length){detail=Object.keys(missing).map(function(k){return k+': '+missing[k];}).join('; ');} else if(item.pause_reason){detail=item.pause_reason;} var $resume=$('<button type="button" class="btn btn-xs btn-default">').text('Resume').prop('disabled',item.status!=='paused').on('click',function(){resume(item);}); $body.append($('<tr>').append($('<td>').text(siteNames[item.target_site_id]||('#'+item.target_site_id)),$('<td>').html('<strong>'+esc(item.object_type)+'</strong><br><code>'+esc(item.object_key)+'</code>'),$('<td>').append($('<span class="label">').addClass(item.status==='active'?'label-success':(item.status==='paused'||item.status==='conflict'?'label-warning':'label-default')).text(item.status)),$('<td>').text(detail),$('<td>').append($resume))); }); }
      function syncTargetAvailability(){var source=Number($('#ucm-cfg-source').val()||0);$('.ucm-cfg-target').each(function(){var same=Number(this.value)===source;$(this).prop('disabled',same);if(same)$(this).prop('checked',false);});refreshPicker('target');}
      var drawOwnedItems=drawItems;
      drawItems=function(job){
        drawOwnedItems(job);
        if(!job||(job.scopes||[]).indexOf('web_directory')!==-1)return;
        var cell=$('<td colspan="5">'),legacy=job.legacy_items||[];
        if(job.ownership_warning)cell.append($('<p class="text-warning">').text(job.ownership_warning));
        legacy.forEach(function(item){cell.append($('<div>').text((siteNames[item.target_site_id]||('#'+item.target_site_id))+' · '+item.object_type+' · '+item.object_key+' · '+item.status+' (legacy, read-only)'));});
        (job.target_site_ids||[]).forEach(function(id){cell.append($('<button type="button" class="btn btn-xs btn-default">').text('Review legacy ownership: '+(siteNames[id]||('#'+id))).on('click',function(){
          if(busy||dirty){feedback('Save job changes before reviewing ownership.',true);return;}
          mutate({command:'configurationsync',op:'preview_legacy',id:job.id,target_site_id:id});
        }));});
        $('#ucm-cfg-items').append($('<tr>').append(cell));$('#ucm-cfg-items-panel').prop('hidden',false);
      };
      function webOptions(){ $('#ucm-cfg-web-options').prop('hidden',!$('.ucm-cfg-scope[value="web_directory"]').is(':checked')); }
      function load(job){ window.ucmpSettingsFieldsLoad(job); editorGeneration++; selected=job||null; dirty=false; var s=schedule(job||{}); $('#ucm-cfg-id').val(job?job.id:0); $('#ucm-cfg-name').val(job?job.name:''); $('#ucm-cfg-source').val(job?job.source_site_id:$('#ucm-cfg-source option:first').val()); $('#ucm-cfg-enabled').prop('checked',job?(job.enabled===true||Number(job.enabled)===1):true); $('#ucm-cfg-lock').prop('checked',job?(job.soft_lock===true||Number(job.soft_lock)===1):true); $('#ucm-cfg-retry').prop('checked',job?(job.retry_unreachable===true||Number(job.retry_unreachable)===1):true); $('.ucm-cfg-target').prop('checked',false); (job&&job.target_site_ids||[]).forEach(function(id){$('.ucm-cfg-target[value="'+id+'"]').prop('checked',true);}); syncTargetAvailability(); $('.ucm-cfg-scope').prop('checked',false); (job&&job.scopes||[]).forEach(function(v){$('.ucm-cfg-scope[value="'+v+'"]').prop('checked',true);}); $('#ucm-cfg-mode').val(s.mode); $('#ucm-cfg-minutes').val(s.interval_minutes); $('#ucm-cfg-time').val(s.time); $('.ucm-cfg-weekday').prop('checked',false); (s.weekdays||[]).forEach(function(v){$('.ucm-cfg-weekday[value="'+v+'"]').prop('checked',true);}); $('#ucm-cfg-title').text(job?job.name:'New configuration sync job'); $('#ucm-cfg-status').attr('class','label '+(job&&job.enabled?'label-success':'label-default')).text(job?(job.enabled?'scheduled':'disabled'):'not saved'); $('#ucm-cfg-web-confirm').prop('checked',!!(job&&job.web_directory_access_confirmed)); $('#ucm-cfg-web-hard-lock').prop('checked',!!(job&&job.web_directory_hard_lock)); webOptions();scheduleVisibility();refreshPicker('target');refreshPicker('scope'); drawItems(job); drawList(); drawResult(job&&job.last_result); setBusy(busy); }
      function scheduleVisibility(){ var mode=$('#ucm-cfg-mode').val(); $('.ucm-cfg-interval').prop('hidden',mode!=='interval'); $('.ucm-cfg-clock').prop('hidden',mode==='interval'); $('.ucm-cfg-weekdays').prop('hidden',mode!=='weekly'); }
      function payload(op){ return {setting_keys_present:1,setting_keys:window.ucmpSettingsFieldsRead(),command:'configurationsync',op:op,id:Number($('#ucm-cfg-id').val()||0),name:$('#ucm-cfg-name').val(),source_site_id:Number($('#ucm-cfg-source').val()||0),enabled:$('#ucm-cfg-enabled').is(':checked')?1:0,soft_lock:$('#ucm-cfg-lock').is(':checked')?1:0,web_directory_access_confirmed:$('#ucm-cfg-web-confirm').is(':checked')?1:0,web_directory_hard_lock:$('#ucm-cfg-web-hard-lock').is(':checked')?1:0,retry_unreachable:$('#ucm-cfg-retry').is(':checked')?1:0,target_site_ids:window.ucmpRetainedSelection('.ucm-cfg-target',selected&&selected.target_site_ids).map(Number),scopes:window.ucmpRetainedSelection('.ucm-cfg-scope',selected&&selected.scopes),template_ids:(selected&&selected.template_ids||[]).slice(),group_ids:(selected&&selected.group_ids||[]).slice(),settings_categories:window.ucmpRetainedSelection('.ucm-cfg-scope',selected&&selected.scopes).filter(function(scope){return scope.indexOf('settings_')===0;}),schedule_mode:$('#ucm-cfg-mode').val(),interval_minutes:Number($('#ucm-cfg-minutes').val()||60),schedule_time:$('#ucm-cfg-time').val(),schedule_weekdays:$('.ucm-cfg-weekday:checked').map(function(){return Number(this.value);}).get()}; }
      function feedback(text,bad){$('#ucm-cfg-feedback').toggleClass('text-danger',!!bad).toggleClass('text-success',!bad).text(text||'');}
      function refresh(selectId,generation){
        var request=++refreshGeneration;
        window.ucmpAjax({data:{command:'configurationsync',op:'list'}}).done(function(resp){
          if(request!==refreshGeneration)return;
          var r=resp&&resp.result||{};
          if(!(resp&&resp.status&&r.ok&&Array.isArray(r.jobs))){feedback(r.message||'Cannot reload jobs; displayed results were retained.',true);return;}
          jobs=r.jobs;
          if(generation===editorGeneration){var next=jobs.find(function(j){return Number(j.id)===Number(selectId);})||null;load(next);}else{drawList();}
        }).fail(function(){if(request===refreshGeneration)feedback('Cannot reload jobs; displayed results and edits were retained.',true);});
      }
      function mutate(p){
        if(busy)return;
        if(selected&&p.op==='save'&&Number(p.source_site_id)!==Number(selected.source_site_id)){feedback('Create a new job to use a different source site; saved object selections belong to the original source.',true);return;}
        var generation=editorGeneration;
        refreshGeneration++;setBusy(true);feedback(p.op==='run'?'Synchronizing dependencies, then policy…':'Saving job change…',false);
        function send(data){
          var continuing=false;
          window.ucmpAjax({data:data}).done(function(resp){
            var r=resp&&resp.result||{},ok=!!(resp&&resp.status&&r.ok);
            feedback(r.message||'Request did not return a completion result',!ok);
            if(data.op==='run')drawResult(r);
            if(data.op==='preview_legacy'&&ok&&r.review_hash&&(r.items||[]).length){
              var objects=r.items.map(function(item){return item.object_type+' · '+item.object_key+' · '+item.status;}).join('\n');
              if(window.confirm(r.message+'\n\n'+objects+'\n\nAssign these legacy records to the selected job?')){
                continuing=true;send(Object.assign({},data,{op:'adopt_legacy',ownership_confirmed:1,review_hash:r.review_hash}));return;
              }
            }
            if(data.op==='run'&&!data.force&&resp&&resp.status&&r.needs_confirmation&&window.confirm('A same-name destination object contains different content. Replace only those conflicting objects with the source versions? Destination-specific PBX identity and credentials remain untouched.')){
              continuing=true;send(Object.assign({},data,{force:1}));return;
            }
            // A receipt-write failure must not replace known outcomes with an older saved run.
            if(!r.reporting_error&&(ok||data.op==='run'))refresh(data.op==='delete'?0:(r.job&&r.job.id||data.id),generation);
          }).fail(function(){feedback('Request outcome is unconfirmed. Review replication activity and reload the job before retrying; work may have completed.',true);})
            .always(function(){if(!continuing)setBusy(false);});
        }
        send(p);
      }
      function resume(item){if(!selected)return;mutate({command:'configurationsync',op:'resume',id:selected.id,target_site_id:item.target_site_id,object_type:item.object_type,object_key:item.object_key});}
      $('#ucm-cfg-new').on('click',function(){selectJob(null);});
      $('.ucm-picker-search').on('input',function(){refreshPicker(this.id.indexOf('target')!==-1?'target':'scope');}); $('[data-picker-action]').on('click',function(){var parts=String($(this).data('picker-action')||'').split('-');pickerAction(parts[0],parts[1]);});
      $('.ucm-cfg-scope').on('change',function(){webOptions();refreshPicker('scope');});
      $('.ucm-cfg-target').on('change',function(){refreshPicker('target');});
      $('#ucm-cfg-mode').on('change',function(){scheduleVisibility();markDirty();}); $('#ucm-cfg-source').on('change',syncTargetAvailability); $('#ucm-configuration-sync').on('change input','input,select',markDirty);
      $('#ucm-cfg-save').on('click',function(){mutate(payload('save'));});
      $('#ucm-cfg-run').on('click',function(){if(busy||!selected)return;if(dirty){feedback('Save job changes before running this job.',true);return;}mutate({command:'configurationsync',op:'run',id:selected.id,force:0});});
      $('#ucm-cfg-delete').on('click',function(){if(busy||!selected||!window.confirm('Delete this job? Destination content is preserved.'))return;mutate({command:'configurationsync',op:'delete',id:selected.id});});
      load(jobs[0]||null);
    })(jQuery);
    </script>
    <?php return;
}

if ($view === 'site') {
    $id = (int)($_REQUEST['id'] ?? 0);
    $site = $id ? ($ucm->getSite($id) ?: []) : [];
    $f = fn($key, $default = '') => htmlspecialchars((string)($site[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    $isLocal = !empty($site['is_local']);
    $pbxHost = trim((string)($site['pbx_host'] ?? '')) ?: trim((string)($site['pbx_addr'] ?? ''));
    $voiceOverride = trim((string)($site['pbx_addr'] ?? ''));
    $apiOverride = trim((string)($site['pbx_api_host'] ?? ''));
    if ($voiceOverride === $pbxHost) { $voiceOverride = ''; }
    if ($apiOverride === $pbxHost) { $apiOverride = ''; }
    $pbxTftpConfigured = trim((string)($site['pbx_tftp_service_host'] ?? ''));
    $dockerTftpConfigured = trim((string)($site['tftp_service_host'] ?? ''));
    $pbxTftpDefault = $voiceOverride ?: $pbxHost;
    $dockerTftpDefault = trim((string)($site['docker_host'] ?? ''));
    $pbxTftpHost = \FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($site, 'pbx');
    $dockerTftpHost = \FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($site, 'docker');
    $pbxTftpAutomatic = $pbxTftpConfigured === '' || $pbxTftpConfigured === $pbxTftpDefault;
    $dockerTftpAutomatic = $dockerTftpConfigured === '' || $dockerTftpConfigured === $dockerTftpDefault;
    $pbxTftpValue = $pbxTftpAutomatic ? $pbxTftpHost : $pbxTftpConfigured;
    $dockerTftpValue = $dockerTftpAutomatic ? $dockerTftpHost : $dockerTftpConfigured;
    $activeTftpMode = $id
        ? \FreePBX\modules\Ucmprovisioner\SiteConnection::activeTftpMode($site)
        : 'pbx';
    $selectedTftpMode = in_array((string)($site['tftp_pending_mode'] ?? ''), ['docker','pbx'], true)
        ? (string)$site['tftp_pending_mode']
        : $activeTftpMode;
    $tftpModePending = $id && $selectedTftpMode !== $activeTftpMode;
    $activeTftpReady = $activeTftpMode === 'pbx'
        ? !empty($site['pbx_tftp_bootstrapped_at'])
        : !empty($site['docker_bootstrapped_at']);
    $pbxControlReady = $isLocal || (!empty($site['pbx_agent_ready']) && !empty($site['pbx_host_key']));
    $apiReady = $isLocal || (!empty($site['pbx_api_enabled']) && !empty($site['pbx_api_ready']));
    $pbxStateIssue = (!$isLocal && !empty($site['pbx_agent_ready']) && empty($site['pbx_host_key']))
        ? _('The PBX control record is incomplete because its pinned SSH host key is missing. Use Install / update before using remote trunks or TFTP enrollment.')
        : '';
    $logs = $id ? $ucm->getOperationLogs($id, 20) : [];
    $moduleSigning = [
        'state' => 'unknown',
        'label' => _('Not checked'),
        'message' => $isLocal ? _('Module signing status is unavailable.') : _('Check version to refresh integrity, or select Sign with Install / update.'),
    ];
    if ($id && $isLocal && method_exists($ucm, 'moduleSigningStatus')) {
        try {
            $moduleSigning = array_merge($moduleSigning, (array)$ucm->moduleSigningStatus());
            $signingDetails = array_values(array_filter(array_map('strval', (array)($moduleSigning['details'] ?? []))));
            $moduleSigning['message'] = $signingDetails
                ? implode('; ', $signingDetails)
                : (!empty($moduleSigning['trusted'])
                    ? _('The root-owned local signature and installed module files verify successfully.')
                    : _('Use Enroll / re-sign after installing or changing this module.'));
        } catch (\Throwable $e) {
            $moduleSigning['message'] = $e->getMessage();
        }
    }
    $signingState = strtolower((string)($moduleSigning['state'] ?? 'unknown'));
    $signingTrusted = !empty($moduleSigning['trusted']) || in_array($signingState, ['trusted', 'valid', 'good', 'locally_signed'], true);
    $signingBad = in_array($signingState, ['tampered', 'invalid', 'revoked', 'expired', 'error'], true);
    $signingBadgeClass = $signingTrusted ? 'label-success' : ($signingBad ? 'label-danger' : ($signingState === 'unknown' ? 'label-default' : 'label-warning'));
    try {
        $trunkResult = $ucm->listPjsipTrunks(0);
    } catch (\Throwable $e) {
        $trunkResult = ['ok' => false, 'trunks' => [], 'message' => $e->getMessage()];
    }
    $trunks = is_array($trunkResult['trunks'] ?? null) ? $trunkResult['trunks'] : [];
    $remoteTrunkResult = ['ok' => false, 'trunks' => [], 'message' => _('Enroll the remote PBX agent first')];
    if ($id && !$isLocal && $pbxControlReady) {
        try {
            $remoteTrunkResult = $ucm->listPjsipTrunks($id);
        } catch (\Throwable $e) {
            $remoteTrunkResult = ['ok' => false, 'trunks' => [], 'message' => _('The remote PBX control channel needs repair. Use Install / update, then reload the endpoint list.')];
        }
    }
    $remoteTrunks = is_array($remoteTrunkResult['trunks'] ?? null) ? $remoteTrunkResult['trunks'] : [];
    // Inventory failure must not turn an ordinary site save into a trunk reset.
    $savedTrunk = (string)($site['trunk_endpoint'] ?? '');
    if ($savedTrunk !== '' && !in_array($savedTrunk, array_column($trunks, 'endpoint'), true)) {
        $trunks[] = ['endpoint'=>$savedTrunk,'label'=>_('Saved selection; unavailable in current inventory')];
    }
    $savedRemoteTrunk = (string)($site['remote_trunk_endpoint'] ?? '');
    if ($savedRemoteTrunk !== '' && !in_array($savedRemoteTrunk, array_column($remoteTrunks, 'endpoint'), true)) {
        $remoteTrunks[] = ['endpoint'=>$savedRemoteTrunk,'label'=>_('Saved selection; unavailable in current inventory')];
    }
    ?>
    <div class="ucm-container ucm-site-editor">
      <div class="ucm-page-intro">
        <div><h2><?php echo $id ? _('Edit site') : _('Add a federated site'); ?></h2>
          <p><?php echo _('Each PBX keeps its own phones, groups and templates locally. The TFTP service can run on that PBX or on the existing separate Docker host; every provisioning feature resolves the same active backend.'); ?></p>
        </div>
        <div class="ucm-status-stack">
          <span class="label <?php echo $pbxControlReady ? 'label-success' : (!empty($site['pbx_agent_ready']) ? 'label-warning' : 'label-default'); ?>"><?php echo $isLocal ? _('local PBX') : ($pbxControlReady ? _('PBX control enrolled') : (!empty($site['pbx_agent_ready']) ? _('PBX repair required') : _('PBX enrollment pending'))); ?></span>
          <?php if(!$isLocal): ?><span class="label <?php echo $apiReady?'label-success':'label-default'; ?>"><?php echo $apiReady?_('encrypted API enrolled'):_('API not enrolled'); ?></span><?php endif; ?>
          <span class="label <?php echo $activeTftpReady ? 'label-success' : 'label-warning'; ?>"><?php echo htmlspecialchars(strtoupper($activeTftpMode) . ' ' . ($activeTftpReady ? _('TFTP active') : _('TFTP needs bootstrap'))); ?></span>
        </div>
      </div>
      <div id="ucm-bootstrap-result" class="ucm-operation-result ucm-operation-result-sticky" aria-live="polite"><?php if ($pbxStateIssue): ?><div class="alert alert-warning"><?php echo htmlspecialchars($pbxStateIssue); ?></div><?php endif; ?></div>

      <form class="fpbx-submit" name="frm_ucmprovisioner" action="config.php?display=ucmprovisioner&amp;tab=sites" method="post" novalidate>
        <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="sites">
        <input type="hidden" name="action" value="savesite"><input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="expected_revision" value="<?php echo $id ? htmlspecialchars(\FreePBX\modules\Ucmprovisioner::siteEditorRevision($site),ENT_QUOTES,'UTF-8') : ''; ?>">
        <input type="hidden" name="site_key" value="<?php echo $f('site_key'); ?>">

        <section class="ucm-card">
          <div class="ucm-card-heading"><span class="ucm-step">1</span><div><h3><?php echo _('Site and PBX address'); ?></h3><p><?php echo _('Enter the PBX address once. SSH, the federation API and phone services use it unless an optional address override is needed.'); ?></p></div></div>
          <div class="row">
            <div class="col-md-5"><div class="form-group"><label><?php echo _('Site name'); ?></label><input class="form-control" name="name" required value="<?php echo $f('name'); ?>"></div></div>
            <div class="col-md-4"><div class="form-group"><label><?php echo _('PBX address (IP or FQDN)'); ?></label><input class="form-control" name="pbx_host" id="ucm-pbx-host" required value="<?php echo htmlspecialchars($pbxHost, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isLocal ? 'readonly' : ''; ?>><p class="help-block"><?php echo _('Default address for PBX management, web API and phone services.'); ?></p></div></div>
            <div class="col-md-1"><div class="form-group"><label><?php echo _('SIP port'); ?></label><input class="form-control" name="pbx_sip_port" type="number" min="1" max="65535" value="<?php echo $f('pbx_sip_port', '5160'); ?>"></div></div>
            <div class="col-md-2"><div class="form-group"><label><?php echo _('Enabled'); ?></label><div><input type="hidden" name="enabled" value="0"><label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" <?php echo !isset($site['enabled']) || !empty($site['enabled']) ? 'checked' : ''; ?>> <?php echo _('Manage site'); ?></label></div></div></div>
          </div>
          <details class="ucm-advanced" <?php echo ($voiceOverride !== '' || (!$isLocal && $apiOverride !== '')) ? 'open' : ''; ?>>
            <summary><?php echo _('Optional address overrides — only for different voice networks or web hostnames'); ?></summary>
            <div class="row">
              <div class="col-md-6"><div class="form-group"><label><?php echo _('Phone / SIP address override'); ?></label><input class="form-control" name="pbx_addr" id="ucm-pbx-voice-override" value="<?php echo htmlspecialchars($voiceOverride, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($pbxHost, ENT_QUOTES, 'UTF-8'); ?>"><p class="help-block"><?php echo _('Leave blank to use the PBX address. Existing distinct voice addresses are preserved.'); ?></p></div></div>
              <?php if (!$isLocal): ?><div class="col-md-6"><div class="form-group"><label><?php echo _('API hostname / address override'); ?></label><input class="form-control" name="pbx_api_host" id="ucm-api-host" value="<?php echo htmlspecialchars($apiOverride, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($pbxHost, ENT_QUOTES, 'UTF-8'); ?>"><p class="help-block"><?php echo _('Leave blank to use the PBX address; set only for a separate endpoint or HTTPS certificate hostname.'); ?></p></div></div><?php endif; ?>
            </div>
          </details>
          <?php if ($id): ?><p class="help-block"><?php echo _('Stable federation key:'); ?> <code><?php echo $f('site_key'); ?></code></p><?php endif; ?>
        </section>

        <section class="ucm-card">
          <div class="ucm-card-heading"><span class="ucm-step">2</span><div><h3><?php echo _('PBX connection and module'); ?></h3><p><?php echo _('Check version is read-only. Install / update applies the displayed connection settings, installs when needed, repairs SSH/API access and honors the signing checkbox.'); ?></p></div></div>
          <div class="row">
            <div class="col-md-2"><div class="form-group"><label><?php echo _('SSH port'); ?></label><input class="form-control" name="pbx_ssh_port" id="ucm-pbx-port" type="number" value="<?php echo $f('pbx_ssh_port', '22'); ?>" <?php echo $isLocal ? 'readonly' : ''; ?>></div></div>
            <div class="col-md-10"><div class="form-group"><label><?php echo _('Pinned host key'); ?></label><input class="form-control" id="ucm-pbx-host-key" name="pbx_host_key" value="<?php echo $f('pbx_host_key'); ?>" data-host="<?php echo htmlspecialchars($pbxHost, ENT_QUOTES, 'UTF-8'); ?>" data-port="<?php echo $f('pbx_ssh_port','22'); ?>" readonly placeholder="<?php echo _('Captured during enrollment'); ?>"></div></div>
          </div>
          <?php if (!$isLocal): ?>
          <div class="ucm-bootstrap-box">
            <strong><?php echo _('Remote module lifecycle and PBX bootstrap'); ?></strong>
            <p><span id="ucm-pbx-agent-state" class="label <?php echo $pbxControlReady ? 'label-success' : 'label-warning'; ?>"><?php echo $pbxControlReady ? _('enrolled (last verified)') : _('enrollment or repair needed'); ?></span></p>
            <div class="row ucm-compact-row ucm-temporary-credentials">
              <div class="col-md-12"><strong><?php echo _('Temporary PBX administrator credentials'); ?></strong><p class="help-block"><?php echo _('Used only by the action you click below. Use root, or an administrator account with Use sudo enabled. The password is never saved or logged.'); ?></p></div>
              <div class="col-md-3"><label for="ucm-pbx-admin-user"><?php echo _('SSH administrator'); ?></label><input class="form-control" id="ucm-pbx-admin-user" value="pbxadmin" autocomplete="section-ucm-pbx-admin username" placeholder="pbxadmin"></div>
              <div class="col-md-4"><label for="ucm-pbx-admin-pass"><?php echo _('Temporary password'); ?></label><input class="form-control" type="password" id="ucm-pbx-admin-pass" autocomplete="section-ucm-pbx-admin new-password"></div>
              <div class="col-md-2"><label>&nbsp;</label><div><label class="checkbox-inline"><input type="checkbox" id="ucm-pbx-admin-sudo" checked> <?php echo _('Use sudo'); ?></label></div></div>
            </div>
            <div class="btn-group"><button type="button" class="btn btn-default" id="ucm-remote-version" data-id="<?php echo $id; ?>" <?php echo !$id ? 'disabled' : ''; ?>><?php echo _('Check version'); ?></button><button type="button" class="btn btn-primary" id="ucm-remote-install" data-id="<?php echo $id; ?>" <?php echo !$id ? 'disabled' : ''; ?>><?php echo _('Install / update'); ?></button></div>
            <span class="ucm-inline-action-feedback" id="ucm-pbx-action-feedback" aria-live="polite"></span>
            <label class="checkbox-inline" style="margin-left:10px"><input type="checkbox" id="ucm-sign-after-install" checked> <?php echo _('Sign and verify (includes local signing-key trust setup)'); ?></label>
            <p class="ucm-top-gap" aria-live="polite"><strong><?php echo _('Controller:'); ?></strong> <span id="ucm-version-controller"><?php echo _('Not checked'); ?></span> · <strong><?php echo _('Remote:'); ?></strong> <span id="ucm-version-remote"><?php echo _('Not checked'); ?></span> · <strong id="ucm-version-comparison"><?php echo _('Not checked'); ?></strong></p>
            <p class="help-block"><?php echo _('Enter temporary administrator credentials for installation or signing. A current module is not needlessly reinstalled; signing and SSH/API repair still run when selected or required. A newer remote version is never downgraded. Existing-site connection edits are used immediately without leaving this page.'); ?></p>
            <?php if (!$id): ?><p class="help-block"><?php echo _('Save a new site once to give it an identity, then use these actions.'); ?></p><?php endif; ?>
          </div>
          <div class="ucm-bootstrap-box ucm-api-enrollment">
            <strong><?php echo _('Encrypted federation API'); ?></strong> <span id="ucm-api-state" class="label <?php echo $apiReady?'label-success':'label-default'; ?>"><?php echo $apiReady?_('ready'):_('not enrolled'); ?></span>
            <p><?php echo _('The request and response bodies are AES-256-GCM encrypted, authenticated, timestamped and replay-protected, so HTTP can be used on a trusted private network. Switch this API to HTTPS later with the same hostname and certificate used by the FreePBX GUI; legacy phone-directory traffic remains independent. SSH is used once to exchange the API key and remains available for recovery.'); ?></p>
            <div class="row ucm-compact-row"><div class="col-md-3"><label><?php echo _('Web scheme'); ?></label><select class="form-control" name="pbx_api_scheme" id="ucm-api-scheme"><option value="http" <?php echo $f('pbx_api_scheme','http')==='http'?'selected':''; ?>>HTTP</option><option value="https" <?php echo $f('pbx_api_scheme')==='https'?'selected':''; ?>>HTTPS</option></select></div><div class="col-md-3"><label><?php echo _('Web port'); ?></label><input class="form-control" type="number" min="1" max="65535" name="pbx_api_port" id="ucm-api-port" value="<?php echo $f('pbx_api_port','80'); ?>"></div><div class="col-md-6"><label><?php echo _('HTTPS certificate validation'); ?></label><select class="form-control" name="pbx_api_verify_tls" id="ucm-api-verify"><option value="1" <?php echo !isset($site['pbx_api_verify_tls'])||!empty($site['pbx_api_verify_tls'])?'selected':''; ?>>Verify certificate and hostname</option><option value="0" <?php echo isset($site['pbx_api_verify_tls'])&&empty($site['pbx_api_verify_tls'])?'selected':''; ?>>Do not verify (temporary)</option></select></div></div>
            <p class="help-block"><?php echo _('Endpoint:'); ?> <span id="ucm-api-effective-host"><?php echo htmlspecialchars($apiOverride ?: $pbxHost, ENT_QUOTES, 'UTF-8'); ?></span> · <?php echo _('Enrollment and verification are included in Install / update. Healthy API keys are reused.'); ?></p>
            <p class="help-block"><?php echo _('When switched to HTTPS, this endpoint automatically uses the same certificate and web server as the FreePBX GUI. No second certificate needs to be installed.'); ?></p>
          </div>
          <?php endif; ?>
        </section>

        <?php if ($id): ?>
        <section class="ucm-card" id="ucm-module-integrity-card">
          <div class="ucm-card-heading"><div><h3><?php echo _('Module integrity'); ?></h3><p><?php echo _('FreePBX module signing uses OpenPGP and a root-owned local signature anchor; it is separate from TLS certificates in Certificate Manager.'); ?></p></div></div>
          <p><strong><?php echo _('Current status:'); ?></strong> <span id="ucm-module-sign-state" class="label <?php echo $signingBadgeClass; ?>"><?php echo htmlspecialchars((string)($moduleSigning['label'] ?? $moduleSigning['state'] ?? _('Not checked'))); ?></span></p>
          <p id="ucm-module-sign-detail" class="help-block"><?php echo htmlspecialchars((string)($moduleSigning['message'] ?? '')); ?></p>
          <?php if ($isLocal): ?><div class="ucm-bootstrap-box"><strong><?php echo _('Temporary local PBX administrator credentials'); ?></strong><div class="row ucm-compact-row"><div class="col-md-3"><input class="form-control" id="ucm-pbx-admin-user" value="pbxadmin" autocomplete="section-ucm-pbx-signing username" aria-label="<?php echo _('PBX administrator user'); ?>"></div><div class="col-md-4"><input class="form-control" type="password" id="ucm-pbx-admin-pass" autocomplete="section-ucm-pbx-signing new-password" aria-label="<?php echo _('Temporary PBX administrator password'); ?>" placeholder="<?php echo _('Temporary password'); ?>"></div><div class="col-md-2"><label class="checkbox-inline"><input type="checkbox" id="ucm-pbx-admin-sudo" checked> <?php echo _('Use sudo'); ?></label></div><div class="col-md-3"><button type="button" class="btn btn-primary btn-block" id="ucm-module-sign" data-id="<?php echo $id; ?>"><?php echo _('Sign / re-sign'); ?></button></div></div><p class="help-block"><?php echo _('Used only for this signing operation; the password is cleared immediately and is never stored.'); ?></p></div><?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="ucm-card">
          <div class="ucm-card-heading"><span class="ucm-step">3</span><div><h3><?php echo _('Inter-site BLF link'); ?></h3><p><?php echo _('Select the existing endpoint on each side of the same PJSIP trunk. This pair builds authenticated device-state publication objects on both PBXs.'); ?></p></div></div>
          <?php if ($isLocal): ?>
          <div class="alert alert-info"><strong><?php echo _('No trunk selection is required on the local-site row.'); ?></strong> <?php echo _('Configure the two trunk endpoints on each remote site instead: “Endpoint on this PBX” is the controller side and “Endpoint on the remote PBX” is that site’s return side.'); ?></div>
          <?php else: ?>
          <div class="row"><div class="col-md-6"><div class="form-group"><label><?php echo _('Endpoint on this PBX'); ?></label>
            <select class="form-control" name="trunk_endpoint" id="ucm-trunk-endpoint" data-selected="<?php echo $f('trunk_endpoint'); ?>">
              <option value=""><?php echo _('-- select an existing trunk --'); ?></option>
              <?php foreach ($trunks as $trunk): $ep = (string)($trunk['endpoint'] ?? ''); ?>
                <option value="<?php echo htmlspecialchars($ep); ?>" <?php echo (($site['trunk_endpoint'] ?? '') === $ep) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($trunk['label'] ?? $ep) . ' — ' . $ep); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="help-block"><?php echo _('Choose the endpoint that reaches this remote site.'); ?></p>
          </div></div><div class="col-md-6"><div class="form-group"><label><?php echo _('Endpoint on the remote PBX'); ?></label>
            <select class="form-control" name="remote_trunk_endpoint" id="ucm-remote-trunk-endpoint" <?php echo ($isLocal || !$pbxControlReady) ? 'disabled' : ''; ?>>
              <option value=""><?php echo _('-- select the return trunk --'); ?></option>
              <?php foreach ($remoteTrunks as $trunk): $ep = (string)($trunk['endpoint'] ?? ''); ?>
                <option value="<?php echo htmlspecialchars($ep); ?>" <?php echo (($site['remote_trunk_endpoint'] ?? '') === $ep) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($trunk['label'] ?? $ep) . ' — ' . $ep); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!$pbxControlReady): ?><input id="ucm-remote-trunk-shadow" type="hidden" name="remote_trunk_endpoint" value="<?php echo $f('remote_trunk_endpoint'); ?>"><?php endif; ?>
            <p class="help-block <?php echo !$pbxControlReady ? 'text-warning' : ''; ?>" id="ucm-remote-trunk-help"><?php echo $pbxStateIssue ? htmlspecialchars($pbxStateIssue) : (!empty($remoteTrunkResult['ok']) ? _('Loaded from the remote PBX.') : htmlspecialchars((string)($remoteTrunkResult['message'] ?? ''))); ?></p>
          </div></div></div>
          <button type="button" class="btn btn-default ucm-refresh-trunks" data-site="0"><?php echo _('Refresh local endpoints'); ?></button>
          <?php endif; ?>
        </section>

        <section class="ucm-card ucm-tftp-architecture" data-active-mode="<?php echo htmlspecialchars($activeTftpMode); ?>" data-selected-mode="<?php echo htmlspecialchars($selectedTftpMode); ?>">
          <div class="ucm-card-heading"><span class="ucm-step">4</span><div><h3><?php echo _('TFTP service and storage'); ?></h3><p><?php echo _('One active provider per site. Phone configs, companion XML, firmware, timezone and locale files, wallpapers, ringtones, phonebooks, Phone Services, the TFTP Browser and replication all use this same selection.'); ?></p></div><span id="ucm-tftp-selection-badge" class="label <?php echo $tftpModePending?'label-warning':'label-success'; ?>"><?php echo htmlspecialchars($tftpModePending ? strtoupper($selectedTftpMode) . ' ' . _('proposed') : strtoupper($activeTftpMode) . ' ' . _('active')); ?></span></div>

          <div class="ucm-tftp-mode-grid" role="radiogroup" aria-label="<?php echo _('TFTP architecture'); ?>">
            <label class="ucm-tftp-mode-card"><span class="ucm-tftp-mode-head"><input type="radio" name="tftp_mode" value="pbx" <?php echo $selectedTftpMode==='pbx'?'checked':''; ?>><span><strong><?php echo _('PBX-hosted TFTP'); ?></strong><small><?php echo _('Run a module-managed tftpd-hpa service on this site’s FreePBX server. Bootstrap and repair use the enrolled PBX control path; file operations use the same pinned, jailed SFTP data plane as every other site architecture.'); ?></small></span><span class="label ucm-tftp-mode-badge"></span></span></label>
          <label class="ucm-tftp-mode-card"><span class="ucm-tftp-mode-head"><input type="radio" name="tftp_mode" value="docker" <?php echo $selectedTftpMode==='docker'?'checked':''; ?>><span><strong><?php echo _('Remote Docker-hosted TFTP'); ?></strong><small><?php echo _('Use only an existing compatible running TFTP container with /opt/tftp-server/tftpboot bind-mounted; the SFTP-only ucmp-tftp account remains jailed to /opt/tftp-server.'); ?></small></span><span class="label ucm-tftp-mode-badge"></span></span></label>
          </div>

          <div class="alert <?php echo $tftpModePending?'alert-warning':'alert-info'; ?>" id="ucm-tftp-transition-notice">
            <?php echo $tftpModePending
                ? sprintf(_('Mode change staged. %s remains live until the target is bootstrapped, the complete tree is copied and SHA-256 verified, and you approve cutover.'), strtoupper($activeTftpMode))
                : _('No architecture change is staged. Selecting another provider and saving this site does not switch the live tree.'); ?>
          </div>

          <div class="ucm-tftp-mode-panel" data-tftp-panel="pbx" <?php echo $selectedTftpMode==='pbx'?'':'hidden'; ?>>
            <div class="row">
              <div class="col-md-4"><div class="form-group"><label><?php echo _('Phone-facing TFTP address'); ?></label><input class="form-control" id="ucm-pbx-tftp-host" value="<?php echo htmlspecialchars($pbxTftpHost, ENT_QUOTES, 'UTF-8'); ?>" readonly><p class="help-block"><?php echo _('Effective address used by phones; follows the PBX phone address unless a TFTP override is set.'); ?></p></div></div>
              <div class="col-md-4"><div class="form-group"><label><?php echo _('TFTP address override (optional)'); ?></label><input class="form-control" id="ucm-pbx-tftp-service-host" name="pbx_tftp_service_host" value="<?php echo htmlspecialchars($pbxTftpValue, ENT_QUOTES, 'UTF-8'); ?>" data-tftp-default="<?php echo htmlspecialchars($pbxTftpDefault, ENT_QUOTES, 'UTF-8'); ?>" data-tftp-automatic="<?php echo $pbxTftpAutomatic ? '1' : '0'; ?>" placeholder="<?php echo htmlspecialchars($pbxTftpDefault, ENT_QUOTES, 'UTF-8'); ?>"><p class="help-block"><?php echo _('Leave the default to follow the PBX phone address. Set only when TFTP has a different phone-facing address; clearing restores the automatic default.'); ?></p></div></div>
              <div class="col-md-4"><div class="form-group"><label><?php echo _('Execution path'); ?></label><input class="form-control" value="<?php echo _('PBX API / SSH bootstrap · pinned SFTP data'); ?>" readonly></div></div>
            </div>
            <div class="row">
              <div class="col-md-4"><div class="form-group"><label><?php echo _('TFTP server'); ?></label><input class="form-control" value="tftpd-hpa" readonly></div></div>
              <div class="col-md-4"><div class="form-group"><label><?php echo _('Managed phone-facing root'); ?></label><input class="form-control" value="/srv/tftp/tftpboot" readonly><p class="help-block"><?php echo _('The SFTP-only runtime sees this as /tftpboot. Alternate or symlinked roots are refused.'); ?></p></div></div>
              <div class="col-md-4"><div class="form-group"><label><?php echo _('Missing TFTP packages'); ?></label><select class="form-control" id="ucm-pbx-tftp-install-policy"><option value="1"><?php echo _('Install TFTP server and client when missing'); ?></option><option value="0"><?php echo _('Report only; do not install'); ?></option></select></div></div>
            </div>
            <input type="hidden" name="pbx_tftp_root_path" value="/tftpboot"><input type="hidden" name="pbx_tftp_bind_path" value="/srv/tftp/tftpboot"><input type="hidden" name="pbx_tftp_daemon" value="tftpd-hpa">

            <div class="ucm-tftp-subpanel">
              <div class="ucm-tftp-state-row"><div><h4><?php echo _('PBX TFTP bootstrap'); ?></h4><p><?php echo _('Inspect first. Bootstrap only manages tftpd-hpa, the fixed TFTP root, the key-only ucmp-tftp jail and their required permissions. Existing unrelated UDP/69 services are refused, not replaced.'); ?></p></div><span class="label label-default" id="ucm-pbx-tftp-state"><?php echo _('Not inspected'); ?></span></div>
              <div class="ucm-tftp-checks"><div><span><?php echo _('Daemon'); ?></span><strong id="ucm-pbx-tftp-daemon"><?php echo _('Unknown'); ?></strong></div><div><span><?php echo _('Managed root'); ?></span><strong id="ucm-pbx-tftp-root"><?php echo _('Unknown'); ?></strong></div><div><span><?php echo _('UDP 69'); ?></span><strong id="ucm-pbx-tftp-port"><?php echo _('Unknown'); ?></strong></div><div><span><?php echo _('Phone networks'); ?></span><strong id="ucm-pbx-tftp-networks"><?php echo $f('voice_subnets', _('Not declared')); ?></strong></div></div>
              <div class="row ucm-compact-row ucm-temporary-credentials"><div class="col-md-3"><label for="ucm-tftp-pbx-admin-user"><?php echo _('SSH administrator'); ?></label><input class="form-control" id="ucm-tftp-pbx-admin-user" value="pbxadmin" autocomplete="section-ucm-pbx-tftp-admin username"></div><div class="col-md-4"><label for="ucm-tftp-pbx-admin-pass"><?php echo _('Temporary password'); ?></label><input class="form-control" type="password" id="ucm-tftp-pbx-admin-pass" autocomplete="section-ucm-pbx-tftp-admin new-password"></div><div class="col-md-2"><label>&nbsp;</label><div><label class="checkbox-inline"><input type="checkbox" id="ucm-tftp-pbx-admin-sudo" checked> <?php echo _('Use sudo'); ?></label></div></div></div>
              <div class="ucm-action-row"><button type="button" class="btn btn-default" id="ucm-pbx-tftp-inspect" data-id="<?php echo $id; ?>" <?php echo !$id?'disabled':''; ?>><?php echo _('Inspect PBX'); ?></button><button type="button" class="btn btn-default" id="ucm-pbx-tftp-plan"><?php echo _('Preview bootstrap'); ?></button><button type="button" class="btn btn-warning" id="ucm-pbx-tftp-bootstrap" data-id="<?php echo $id; ?>" <?php echo (!$id||(!$isLocal&&!$pbxControlReady))?'disabled':''; ?>><?php echo _('Bootstrap / repair PBX TFTP'); ?></button><span id="ucm-pbx-tftp-feedback" class="ucm-inline-action-feedback" aria-live="polite"></span></div>
               <ol class="ucm-tftp-bootstrap-plan" hidden><li><?php echo _('Detect tftpd-hpa, its configured root and any current UDP/69 owner.'); ?></li><li><?php echo _('Use the supplied temporary administrator credentials to install the TFTP server (tftpd-hpa) and client (tftp-hpa) when missing and explicitly permitted.'); ?></li><li><?php echo _('Create the root-owned SFTP jail and writable phone-facing tree without deleting files.'); ?></li><li><?php echo _('Merge and preserve existing controller and site keys, verify sshd configuration, then start and enable tftpd-hpa.'); ?></li><li><?php echo _('Verify local UDP retrieval and a key-only jailed SFTP write/read/delete round trip; phone-network firewall rules are reported, never silently changed.'); ?></li></ol>
            </div>
          </div>

          <div class="ucm-tftp-mode-panel" data-tftp-panel="docker" <?php echo $selectedTftpMode==='docker'?'':'hidden'; ?>>
              <div class="row"><div class="col-md-4"><div class="form-group"><label><?php echo _('Docker host'); ?></label><input class="form-control" name="docker_host" id="ucm-docker-host" value="<?php echo $f('docker_host'); ?>"></div></div><div class="col-md-2"><div class="form-group"><label><?php echo _('SSH port'); ?></label><input class="form-control" name="docker_ssh_port" id="ucm-docker-port" type="number" value="<?php echo $f('docker_ssh_port', '22'); ?>"></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('TFTP container name (optional)'); ?></label><input class="form-control" name="tftp_container" value="<?php echo $f('tftp_container'); ?>" placeholder="tftp-server"></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Pinned host key'); ?></label><input class="form-control" id="ucm-docker-host-key" name="docker_host_key" value="<?php echo $f('docker_host_key'); ?>" data-host="<?php echo $f('docker_host'); ?>" data-port="<?php echo $f('docker_ssh_port','22'); ?>" readonly></div></div></div>
             <div class="row"><div class="col-md-5"><div class="form-group"><label><?php echo _('Host bind path'); ?></label><input class="form-control" name="tftp_bind_path" id="ucm-tftp-bind" value="/opt/tftp-server/tftpboot" readonly><p class="help-block"><?php echo _('Fixed jail root. Enrollment refuses alternate paths and symlinks.'); ?></p></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('Phone-facing TFTP address'); ?></label><input class="form-control" id="ucm-docker-tftp-service-host" name="tftp_service_host" value="<?php echo htmlspecialchars($dockerTftpValue, ENT_QUOTES, 'UTF-8'); ?>" data-tftp-default="<?php echo htmlspecialchars($dockerTftpDefault, ENT_QUOTES, 'UTF-8'); ?>" data-tftp-automatic="<?php echo $dockerTftpAutomatic ? '1' : '0'; ?>" placeholder="<?php echo htmlspecialchars($dockerTftpDefault, ENT_QUOTES, 'UTF-8'); ?>"><p class="help-block"><?php echo _('Leave the default to follow the Docker host. Set only when phones use a different address; clearing restores the automatic default.'); ?></p></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Runtime account / SFTP path'); ?></label><input class="form-control" value="ucmp-tftp @ /tftpboot" readonly><input type="hidden" name="docker_ssh_user" value="ucmp-tftp"><input type="hidden" name="tftp_root_path" value="/tftpboot"></div></div></div>
             <div class="ucm-bootstrap-box"><strong><?php echo _('Enroll an existing Docker TFTP container'); ?></strong><p class="help-block"><?php echo _('This action only enrolls a compatible running container that already serves /opt/tftp-server/tftpboot. It does not create a container, image, bind mount or TFTP tree.'); ?></p><div class="row ucm-compact-row"><div class="col-md-3"><label for="ucm-docker-admin-user"><?php echo _('Docker host administrator'); ?></label><input class="form-control" id="ucm-docker-admin-user" value="" autocomplete="section-ucm-docker-admin username" placeholder="<?php echo _('Docker host admin'); ?>"><p class="help-block"><?php echo _('This account belongs to the Docker host and may differ from the PBX administrator. Non-root accounts require Use sudo.'); ?></p></div><div class="col-md-4"><label for="ucm-docker-admin-pass"><?php echo _('Temporary password'); ?></label><input class="form-control" type="password" id="ucm-docker-admin-pass" autocomplete="section-ucm-docker-admin new-password" placeholder="<?php echo _('Temporary admin password'); ?>"></div><div class="col-md-2"><label>&nbsp;</label><div><label class="checkbox-inline"><input type="checkbox" id="ucm-docker-admin-sudo"> <?php echo _('Use sudo'); ?></label></div></div><div class="col-md-3"><label>&nbsp;</label><button type="button" class="btn btn-warning btn-block" id="ucm-docker-bootstrap" data-id="<?php echo $id; ?>" <?php echo (!$id || (!$isLocal && !$pbxControlReady)) ? 'disabled' : ''; ?>><?php echo _('Enroll existing TFTP container'); ?></button></div></div><p id="ucm-docker-action-feedback" class="ucm-inline-action-feedback" aria-live="polite"><?php echo (!$isLocal && !$pbxControlReady) ? _('Complete PBX control enrollment first; this action then verifies the existing Docker host and fixed bind path.') : _('No container is created; enrollment verifies the existing Docker TFTP runtime and fixed bind path.'); ?></p></div>
          </div>

          <div class="ucm-tftp-cutover" id="ucm-tftp-cutover" <?php echo $tftpModePending?'':'hidden'; ?>>
            <div class="ucm-tftp-state-row"><div><h4><?php echo _('Safe architecture cutover'); ?></h4><p id="ucm-tftp-cutover-summary"><?php echo htmlspecialchars(strtoupper($activeTftpMode) . ' → ' . strtoupper($selectedTftpMode)); ?> · <?php echo _('the source remains active and untouched until the complete target passes SHA-256 verification.'); ?></p></div><span class="label label-warning" id="ucm-tftp-cutover-state"><?php echo _('Preview required'); ?></span></div>
            <button type="button" class="btn btn-default" id="ucm-tftp-cutover-preview" data-id="<?php echo $id; ?>" <?php echo !$id?'disabled':''; ?>><?php echo _('Preview copy and cutover'); ?></button> <button type="button" class="btn btn-danger" id="ucm-tftp-cutover-apply" data-id="<?php echo $id; ?>" disabled><?php echo _('Copy, verify and activate'); ?></button> <span id="ucm-tftp-cutover-feedback" class="ucm-inline-action-feedback" aria-live="polite"></span>
          </div>

          <div class="ucm-tftp-subpanel"><div class="ucm-tftp-state-row"><div><h4><?php echo _('Active phone-facing tree permissions'); ?></h4><p><?php echo _('Checks only the provider currently serving phones. Private .ucmprovisioner transaction data remains excluded.'); ?></p></div><span class="label label-info"><?php echo htmlspecialchars(strtoupper($activeTftpMode)); ?></span></div><button type="button" class="btn btn-default ucm-tftp-permissions" data-op="permission_audit" data-id="<?php echo $id; ?>" <?php echo !$id||!$activeTftpReady?'disabled':''; ?>><?php echo _('Verify permissions'); ?></button> <button type="button" class="btn btn-warning ucm-tftp-permissions" data-op="permission_repair" data-id="<?php echo $id; ?>" <?php echo !$id||!$activeTftpReady?'disabled':''; ?>><?php echo _('Repair published permissions'); ?></button><span id="ucm-tftp-permission-feedback" class="ucm-inline-action-feedback" aria-live="polite"></span></div>
          <div class="ucm-tftp-consumers"><span><?php echo _('Phone configs'); ?></span><span><?php echo _('Companion XML'); ?></span><span><?php echo _('Firmware'); ?></span><span><?php echo _('Timezone & locale'); ?></span><span><?php echo _('Wallpaper catalogs'); ?></span><span><?php echo _('Ringtones'); ?></span><span><?php echo _('Phonebooks'); ?></span><span><?php echo _('Phone Services'); ?></span><span><?php echo _('TFTP Browser'); ?></span><span><?php echo _('Replication'); ?></span></div>
        </section>

        <details class="ucm-card ucm-advanced"><summary><?php echo _('Advanced: optional discovery networks'); ?></summary>
          <div class="form-group"><label><?php echo _('Suggested discovery ranges (optional, comma-separated)'); ?></label><input class="form-control" name="voice_subnets" value="<?php echo $f('voice_subnets'); ?>" placeholder="10.20.0.0/24"><p class="help-block"><?php echo _('Optional scanner shortcuts. You can enter a different IPv4 range directly in the scanner without saving it here. These suggestions do not affect phone registration, TFTP, trunks or BLF.'); ?></p></div>
        </details>
      </form>
       <section class="ucm-card" id="ucm-operation-log-panel" data-site-id="<?php echo $id; ?>"><div class="ucm-card-heading"><div><h3><?php echo _('Recent operation log'); ?></h3><p><?php echo _('Passwords are never logged. Expand a row to see command output and the exact failed stage.'); ?></p></div><div class="ucm-operation-log-controls"><button type="button" class="btn btn-default btn-sm" id="ucm-operation-log-refresh" data-site-id="<?php echo $id; ?>"><?php echo _('Refresh'); ?></button><span id="ucm-operation-log-status" class="ucm-inline-action-feedback" aria-live="polite"><?php echo _('Loaded with page'); ?></span></div></div>
         <div id="ucm-operation-log-rows" data-site-id="<?php echo $id; ?>">
         <?php if (!$logs): ?><p class="text-muted" id="ucm-operation-log-empty"><?php echo _('No operations logged for this site yet.'); ?></p><?php endif; ?>
         <?php foreach ($logs as $log): $logId = (string)($log['id'] ?? ''); if ($logId === '') { $logId = 'legacy-' . (string)($log['created'] ?? '0'); } $createdLabel = trim((string)($log['created_label'] ?? '')); if ($createdLabel === '') { $createdLabel = date('Y-m-d H:i:s', (int)($log['created'] ?? 0)); } $detail = trim((string)($log['detail'] ?? '')); if ($detail === '') { $detail = _('No additional output was recorded for this operation.'); } ?>
           <details data-log-id="<?php echo htmlspecialchars($logId, ENT_QUOTES, 'UTF-8'); ?>"><summary><code><?php echo htmlspecialchars($createdLabel, ENT_QUOTES, 'UTF-8'); ?></code> <strong><?php echo htmlspecialchars((string)($log['operation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars((string)($log['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></summary><pre class="ucm-operation-detail"><?php echo htmlspecialchars($detail, ENT_QUOTES, 'UTF-8'); ?></pre></details>
         <?php endforeach; ?>
         </div>
       </section>
    </div>
    <?php return;
}

$rows = $ucm->getSites();
?>
<div class="ucm-container">
  <div class="ucm-page-intro"><div><h2><?php echo _('Sites'); ?></h2><p><?php echo _('One registry, separate site-owned configuration. Connection badges show whether the two least-privilege channels have been enrolled.'); ?></p></div><div class="btn-group"><a class="btn btn-default" href="config.php?display=ucmprovisioner&amp;tab=sites&amp;view=configuration-sync"><?php echo _('Configuration sync'); ?></a><a class="btn btn-primary" href="config.php?display=ucmprovisioner&amp;tab=sites&amp;view=site"><?php echo _('Add site'); ?></a></div></div>
  <div class="table-responsive"><table class="table table-hover ucm-sites-table"><thead><tr><th><?php echo _('Site'); ?></th><th><?php echo _('Voice / PBX management'); ?></th><th><?php echo _('TFTP architecture'); ?></th><th><?php echo _('BLF trunk'); ?></th><th><?php echo _('Actions'); ?></th></tr></thead><tbody>
  <?php foreach ($rows as $site): $rowTftpMode=\FreePBX\modules\Ucmprovisioner\SiteConnection::activeTftpMode($site); $rowTftpHost=\FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($site); $rowTftpReady=$rowTftpMode==='pbx'?!empty($site['pbx_tftp_bootstrapped_at']):!empty($site['docker_bootstrapped_at']); $rowPbxControlReady=!empty($site['is_local']) || (!empty($site['pbx_agent_ready']) && !empty($site['pbx_host_key'])); ?>
    <tr><td><strong><?php echo htmlspecialchars((string)$site['name']); ?></strong><br><?php if (!empty($site['is_local'])): ?><span class="label label-info"><?php echo _('local'); ?></span><?php endif; ?> <?php if (empty($site['enabled'])): ?><span class="label label-default"><?php echo _('disabled'); ?></span><?php endif; ?></td>
      <td><?php echo htmlspecialchars((string)($site['pbx_addr'] ?? '')); ?><br><small class="text-muted"><?php echo htmlspecialchars((string)($site['pbx_host'] ?? '') . ':' . (string)($site['pbx_ssh_port'] ?? 22)); ?></small><br><span class="label <?php echo $rowPbxControlReady ? 'label-success' : 'label-warning'; ?>"><?php echo $rowPbxControlReady ? _('SSH enrolled (last verified)') : _('bootstrap needed'); ?></span> <?php if(empty($site['is_local'])): $rowApiReady=!empty($site['pbx_api_enabled']) && !empty($site['pbx_api_ready']); ?><span class="label <?php echo $rowApiReady?'label-success':'label-default'; ?>"><?php echo $rowApiReady?_('API enrolled'):_('API off / unverified'); ?></span><?php endif; ?></td>
      <td><strong><?php echo htmlspecialchars($rowTftpMode==='pbx'?_('PBX-hosted'):_('Remote Docker-hosted')); ?></strong> <span class="label <?php echo $rowTftpReady?'label-success':'label-warning'; ?>"><?php echo $rowTftpReady?_('ready'):_('bootstrap needed'); ?></span><?php if(!empty($site['tftp_pending_mode'])): ?><br><span class="label label-warning"><?php echo htmlspecialchars(strtoupper((string)$site['tftp_pending_mode']) . ' ' . _('cutover staged')); ?></span><?php endif; ?><br><small><?php echo _('Phones use'); ?> <code><?php echo htmlspecialchars($rowTftpHost); ?></code></small><br><small class="text-muted"><?php echo htmlspecialchars($rowTftpMode==='pbx'?(string)($site['pbx_tftp_bind_path']??'/srv/tftp/tftpboot'):(string)($site['tftp_bind_path']??'/opt/tftp-server/tftpboot')); ?></small></td>
      <td><?php echo !empty($site['trunk_endpoint']) ? '<code>' . htmlspecialchars((string)$site['trunk_endpoint']) . '</code>' : '<span class="text-muted">' . _('- local endpoint not selected -') . '</span>'; ?>
        <?php if (empty($site['is_local'])): ?><br><small class="text-muted">↔ <?php echo !empty($site['remote_trunk_endpoint']) ? '<code>' . htmlspecialchars((string)$site['remote_trunk_endpoint']) . '</code>' : _('- remote endpoint not selected -'); ?></small><?php endif; ?>
      </td>
      <td><a class="btn btn-xs btn-default" href="config.php?display=ucmprovisioner&amp;tab=sites&amp;view=site&amp;id=<?php echo (int)$site['id']; ?>"><?php echo _('Configure'); ?></a> <button class="btn btn-xs btn-default ucm-testsite" data-id="<?php echo (int)$site['id']; ?>"><?php echo _('Test both paths'); ?></button></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div><div id="ucm-action-result" aria-live="polite"></div>
</div>
