<?php
/** FreePBX 17 Asterisk / USECALLMANAGER build and version manager. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$sites = $ucm->getSites();
$siteId = (int)($_GET['site_id'] ?? 0) ?: $ucm->localSiteId();
$selectedSite = null;
foreach ($sites as $site) {
    if ((int)$site['id'] === $siteId) { $selectedSite = $site; break; }
}
if (!$selectedSite && $sites) { $selectedSite = $sites[0]; $siteId = (int)$selectedSite['id']; }
$local = !empty($selectedSite['is_local']);
$inventory = $ucm->getPatchInventory($siteId);
$manager = $ucm->getPatchWorkflow($siteId);
$runtime = (array)($manager['runtime'] ?? []);
$catalog = (array)($manager['catalog'] ?? \FreePBX\modules\Ucmprovisioner\PatchCatalog::entries());
$bundles = (array)($manager['bundles'] ?? ($inventory['managed_bundles'] ?? []));
$incoming = (array)($manager['incoming'] ?? ($inventory['managed_incoming'] ?? []));
$activeId = (string)($manager['active_bundle_id'] ?? ($inventory['active_bundle_id'] ?? ''));
$pinnedId = (string)($manager['pinned_bundle_id'] ?? ($inventory['pinned_bundle_id'] ?? ''));
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$size = static function ($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes >= 1073741824) { return number_format($bytes / 1073741824, 1) . ' GiB'; }
    if ($bytes >= 1048576) { return number_format($bytes / 1048576, 1) . ' MiB'; }
    return number_format($bytes / 1024, 1) . ' KiB';
};
?>
<div class="ucm-container ucm-patch-manager">
  <div class="ucm-page-intro">
    <div><h2><?php echo _('Asterisk Patch Manager'); ?></h2><p><?php echo _('Build an exact USECALLMANAGER-compatible Asterisk release, keep selectable runtime bundles, protect the chosen build from FreePBX version switching, and copy completed bundles to other sites.'); ?></p></div>
    <div><label><?php echo _('Inventory site'); ?></label><select id="ucm-patch-site" class="form-control"><?php foreach($sites as $site): ?><option value="<?php echo (int)$site['id']; ?>" <?php echo (int)$site['id']===$siteId?'selected':''; ?>><?php echo $h($site['name']); ?><?php echo !empty($site['is_local'])?' ('._('local').')':''; ?></option><?php endforeach; ?></select></div>
  </div>

  <div id="ucm-patch-message" class="ucm-operation-result-sticky" aria-live="polite"></div>
  <?php if (!$local): ?><div class="alert alert-warning"><strong><?php echo _('Remote inventory is read-only.'); ?></strong> <?php echo _('Open this module on the selected PBX to build, import, activate or pin Asterisk. Completed bundles may be synchronized from their source PBX.'); ?></div><?php endif; ?>
  <?php if (empty($manager['enrolled']) && $local): ?><div class="alert alert-danger"><?php echo $h($manager['message'] ?? _('Patch Manager helper is not enrolled.')); ?></div><?php endif; ?>

  <section class="ucm-card">
    <div class="ucm-card-heading"><div><h3><?php echo _('What is running, installed and pinned'); ?></h3><p><?php echo _('These are separate facts. Refresh detects a manually replaced executable and offers Capture current installation below.'); ?></p></div><button type="button" class="btn btn-default" onclick="window.location.reload()"><i class="fa fa-refresh"></i> <?php echo _('Refresh'); ?></button></div>
    <div class="row">
      <div class="col-md-3"><strong><?php echo _('Running'); ?></strong><div><code><?php echo $h($runtime['running_version'] ?? $inventory['running_version'] ?? _('Unavailable')); ?></code></div></div>
      <div class="col-md-3"><strong><?php echo _('Installed executable'); ?></strong><div><code><?php echo $h($runtime['installed_version'] ?? $inventory['installed_version'] ?? _('Unavailable')); ?></code></div></div>
      <div class="col-md-3"><strong><?php echo _('Active managed bundle'); ?></strong><div><code><?php echo $h($activeId ?: _('Not captured')); ?></code></div></div>
      <div class="col-md-3"><strong><?php echo _('Pinned'); ?></strong><div><?php if($pinnedId): ?><span class="label <?php echo !empty($manager['pin_drift'])?'label-danger':'label-success'; ?>"><?php echo $h($pinnedId); ?></span><?php else: ?><span class="label label-default"><?php echo _('No managed pin'); ?></span><?php endif; ?><br><small><?php echo !empty($manager['switch_package_held']) || !empty($inventory['version_switch_held']) ? _('FreePBX asterisk-version-switch is held') : _('FreePBX version-switch hold is not active'); ?></small></div></div>
    </div>
    <?php if (!empty($manager['pin_drift']) || !empty($inventory['pin_drift'])): ?><div class="alert alert-danger ucm-top-gap"><?php echo _('The installed executable no longer matches the pinned bundle. Capture it for history or reactivate the pinned bundle.'); ?></div><?php endif; ?>
    <?php if ($local): ?><div class="ucm-top-gap"><button type="button" class="btn btn-default ucm-patch-op" data-op="capture"><i class="fa fa-camera"></i> <?php echo _('Capture current installation'); ?></button> <small><?php echo _('Stores the current executable and complete module directory as a selectable bundle without restarting Asterisk.'); ?></small></div><?php endif; ?>
  </section>

  <section class="ucm-card">
    <div class="ucm-card-heading"><span class="ucm-step">1</span><div><h3><?php echo _('Select and build a compatible release'); ?></h3><p><?php echo _('The newest compatible patch is recommended, but every listed older release remains selectable. A newer vanilla Asterisk release never disables an exact UCM patch.'); ?></p></div></div>
    <div class="row">
      <div class="col-md-5 form-group"><label><?php echo _('Asterisk / USECALLMANAGER version'); ?></label><select id="ucm-patch-catalog" class="form-control"><?php foreach($catalog as $entry): ?><option value="<?php echo $h($entry['catalog_id']); ?>" data-note="<?php echo $h($entry['module_note'] ?? ''); ?>"><?php echo $h($entry['asterisk_version']); ?><?php echo !empty($entry['recommended'])?' — '._('recommended'):''; ?></option><?php endforeach; ?></select><p id="ucm-patch-note" class="help-block"></p></div>
      <div class="col-md-4 form-group"><label class="checkbox-inline"><input type="checkbox" id="ucm-patch-srtp"> <?php echo _('Enable AES-256 and AES-GCM SRTP options'); ?></label><p class="help-block"><?php echo _('Runs make menuselect.makeopts and enables both documented high-strength SRTP flags.'); ?></p></div>
      <div class="col-md-3 form-group"><label><?php echo _('Parallel build jobs'); ?></label><input type="number" min="1" max="16" value="4" id="ucm-patch-jobs" class="form-control"></div>
    </div>
    <div class="btn-toolbar">
      <button type="button" class="btn btn-default ucm-patch-op" data-op="preview" <?php echo !$local?'disabled':''; ?>><?php echo _('Show commands'); ?></button>
      <button type="button" class="btn btn-default ucm-patch-op" data-op="download" <?php echo !$local?'disabled':''; ?>><?php echo _('Download'); ?></button>
      <button type="button" class="btn btn-default ucm-patch-op" data-op="stage" <?php echo !$local?'disabled':''; ?>><?php echo _('Apply UCM patch'); ?></button>
      <button type="button" class="btn btn-primary ucm-patch-op" data-op="build" <?php echo !$local?'disabled':''; ?>><?php echo _('Configure and build bundle'); ?></button>
    </div>
    <pre id="ucm-patch-commands" class="ucm-top-gap" style="display:none"></pre>
    <p class="help-block"><?php echo _('Build stages make install into an isolated bundle. It does not touch the running PBX until Activate is explicitly confirmed. Sample configuration files are never installed over FreePBX.'); ?></p>
  </section>

  <section class="ucm-card">
    <div class="ucm-card-heading"><span class="ucm-step">2</span><div><h3><?php echo _('Upload an administrator build'); ?></h3><p><?php echo _('Accepts an exported UCM Provisioner bundle or a raw 64-bit x86 Asterisk executable. Raw binaries reuse the current module set and are clearly marked as administrator-declared.'); ?></p></div></div>
    <div class="row">
      <div class="col-md-5 form-group"><input type="file" id="ucm-patch-upload"></div>
      <div class="col-md-3 form-group"><input type="text" id="ucm-patch-upload-version" class="form-control" placeholder="<?php echo _('Asterisk version, e.g. 20.19.0'); ?>"></div>
      <div class="col-md-2 form-group"><label class="checkbox-inline"><input type="checkbox" id="ucm-patch-upload-patched"> <?php echo _('UCM patched'); ?></label></div>
      <div class="col-md-2"><button type="button" id="ucm-patch-upload-button" class="btn btn-default btn-block" <?php echo !$local?'disabled':''; ?>><?php echo _('Upload and import'); ?></button></div>
    </div>
  </section>

  <?php if($incoming): ?>
  <section class="ucm-card">
    <div class="ucm-card-heading"><div><h3><?php echo _('Incoming synchronized bundles'); ?></h3><p><?php echo _('Synchronization never activates Asterisk remotely. Import the received bundle locally, review it, then activate it when ready.'); ?></p></div></div>
    <div class="table-responsive"><table class="table table-condensed"><thead><tr><th><?php echo _('File'); ?></th><th><?php echo _('Size'); ?></th><th><?php echo _('SHA-256'); ?></th><th></th></tr></thead><tbody><?php foreach($incoming as $item): ?><tr><td><?php echo $h($item['filename'] ?? 'bundle'); ?><br><small><?php echo $h($item['source_site'] ?? ''); ?></small></td><td><?php echo $h($size($item['size_bytes'] ?? 0)); ?></td><td><code><?php echo $h($item['sha256'] ?? ''); ?></code></td><td><button type="button" class="btn btn-xs btn-primary ucm-import-incoming" data-id="<?php echo $h($item['incoming_id']); ?>" <?php echo !$local?'disabled':''; ?>><?php echo _('Import'); ?></button></td></tr><?php endforeach; ?></tbody></table></div>
  </section>
  <?php endif; ?>

  <section class="ucm-card">
    <div class="ucm-card-heading"><span class="ucm-step">3</span><div><h3><?php echo _('Available versions and site synchronization'); ?></h3><p><?php echo _('Up to three previous activations are retained in history; managed bundles remain available until explicitly deleted. Activation replaces the executable and its matching module directory, then verifies startup.'); ?></p></div><?php if($local && $pinnedId): ?><button type="button" class="btn btn-warning ucm-patch-op" data-op="unpin" data-confirm="UNPIN ASTERISK"><?php echo _('Unpin'); ?></button><?php endif; ?></div>
    <details class="ucm-advanced"><summary><?php echo _('Bundle synchronization targets'); ?></summary><div class="ucm-top-gap"><?php foreach($sites as $site): ?><label class="checkbox-inline"><input type="checkbox" class="ucm-asterisk-sync-target" value="<?php echo (int)$site['id']; ?>" <?php echo (int)$site['id']===$siteId?'disabled':''; ?>> <?php echo $h($site['name']); ?></label><?php endforeach; ?><p class="help-block"><?php echo _('Copies the compiled bundle into each target’s Incoming inventory. Import and activation remain explicit on that PBX.'); ?></p></div></details>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th><?php echo _('Version / origin'); ?></th><th><?php echo _('Patch state'); ?></th><th><?php echo _('Runtime files'); ?></th><th><?php echo _('State'); ?></th><th><?php echo _('Actions'); ?></th></tr></thead><tbody>
      <?php foreach($bundles as $bundle): $id=(string)($bundle['bundle_id']??''); $bundleActive=$id===$activeId; $bundlePinned=$id===$pinnedId; ?>
      <tr>
        <td><strong><?php echo $h($bundle['version'] ?? ''); ?></strong><br><code><?php echo $h($id); ?></code><br><small><?php echo $h($bundle['origin'] ?? ''); ?></small></td>
        <td><?php echo $h($bundle['patch_status'] ?? 'unknown'); ?><?php if(!empty($bundle['warning'])): ?><br><small class="text-warning"><?php echo $h($bundle['warning']); ?></small><?php endif; ?></td>
        <td><?php echo (int)($bundle['module_count'] ?? 0); ?> <?php echo _('modules'); ?><br><?php echo $h($size($bundle['size_bytes'] ?? 0)); ?><br><code><?php echo $h(substr((string)($bundle['binary_sha256'] ?? ''),0,16)); ?>…</code></td>
        <td><?php if($bundleActive): ?><span class="label label-success"><?php echo _('Active'); ?></span><?php endif; ?> <?php if($bundlePinned): ?><span class="label label-info"><?php echo _('Pinned'); ?></span><?php endif; ?><?php if(!$bundleActive&&!$bundlePinned): ?><span class="label label-default"><?php echo _('Available'); ?></span><?php endif; ?></td>
        <td>
          <?php if($local && !$bundleActive): ?><button type="button" class="btn btn-xs btn-danger ucm-bundle-op" data-op="activate" data-id="<?php echo $h($id); ?>"><?php echo _('Activate'); ?></button><?php endif; ?>
          <?php if($local && $bundleActive && !$bundlePinned): ?><button type="button" class="btn btn-xs btn-warning ucm-bundle-op" data-op="pin" data-id="<?php echo $h($id); ?>"><?php echo _('Pin'); ?></button><?php endif; ?>
          <?php if($local): ?><button type="button" class="btn btn-xs btn-default ucm-bundle-sync" data-id="<?php echo $h($id); ?>"><?php echo _('Sync'); ?></button><?php endif; ?>
          <?php if($local && !$bundleActive): ?><button type="button" class="btn btn-xs btn-default ucm-bundle-op" data-op="delete" data-id="<?php echo $h($id); ?>" data-duplicate="<?php echo $bundlePinned ? '1' : '0'; ?>" <?php echo empty($bundle['can_delete']) ? 'disabled' : ''; ?>><?php echo $bundlePinned ? _('Delete verified duplicate') : _('Delete'); ?></button><?php if($bundlePinned): ?><small><?php echo _('Deletion requires a complete payload match and retains the pin on the active copy. A different pinned version is protected.'); ?></small><?php endif; ?><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; if(!$bundles): ?><tr><td colspan="5" class="text-muted"><?php echo _('No managed versions yet. Capture the current installation, build a catalog release, or upload an administrator build.'); ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($local && !empty($manager['history'])): ?><button type="button" class="btn btn-danger ucm-patch-op" data-op="rollback" data-confirm="ROLLBACK ASTERISK"><?php echo _('Activate previous history version'); ?></button><?php endif; ?>
  </section>

  <section class="ucm-card">
    <h3><?php echo _('Deferred package-manager edition'); ?></h3>
    <p><?php echo _('A future edition may build native Debian/RPM packages, verify signing-key trust chains and provide distribution-independent transactional recovery. This release deliberately uses the simpler FreePBX 17 source-build and asterisk-version-switch hold requested for the current environment.'); ?></p>
    <p><a href="<?php echo $h(\FreePBX\modules\Ucmprovisioner\PatchCatalog::UPSTREAM_PAGE); ?>" target="_blank" rel="noopener noreferrer"><?php echo _('Open authoritative USECALLMANAGER patch instructions'); ?></a></p>
  </section>
</div>

<script>
jQuery(function($){
  var local=<?php echo $local?'true':'false'; ?>,siteId=<?php echo $siteId; ?>;
  function result(resp){return (resp&&resp.result)||{};}
  function message(text,ok,detail){var box=$('#ucm-patch-message').removeClass('alert-success alert-danger alert-info').addClass('alert '+(ok===false?'alert-danger':(ok===true?'alert-success':'alert-info'))).empty().append($('<div>').text(text));if(detail)box.append($('<pre class="ucm-operation-detail">').text(detail));}
  var busy=false,reloading=false;
  function submit(data,completed,failed){
    if(busy||!local)return;
    busy=true;
    var controls=[];$('.ucm-patch-manager :input').each(function(){controls.push({element:this,disabled:this.disabled});this.disabled=true;});
    return window.ucmpAjax({data:data}).done(completed).fail(failed||function(xhr){message(xhr.responseJSON&&xhr.responseJSON.message||'Patch Manager response was lost or failed. Refresh inventory before repeating the operation.',false);}).always(function(){
      if(reloading)return;
      busy=false;controls.forEach(function(control){control.element.disabled=control.disabled;});
    });
  }
  function request(data,completed){return submit($.extend({command:'patchworkflow',site_id:siteId},data),completed);}
  function catalogData(op){return{op:op,catalog_id:$('#ucm-patch-catalog').val(),high_strength_srtp:$('#ucm-patch-srtp').is(':checked')?1:0,jobs:$('#ucm-patch-jobs').val()};}
  function done(r){message(r.message||'Patch Manager operation completed.',!!r.ok,r.log_tail||r.reporting_error||'');if(r.ok&&r.commands){$('#ucm-patch-commands').text(r.commands.join('\n')).show();return;}if(r.ok&&!r.reporting_error){reloading=true;setTimeout(function(){window.location.reload();},700);}}
  $('#ucm-patch-site').on('change',function(){window.location='config.php?display=ucmprovisioner&tab=patches&site_id='+encodeURIComponent(this.value);});
  function note(){var option=$('#ucm-patch-catalog option:selected');$('#ucm-patch-note').text(option.data('note')||'');}
  $('#ucm-patch-catalog').on('change',note);note();
  $('.ucm-patch-op').on('click',function(){if(!local||busy)return;var op=String($(this).data('op')||''),data=['preview','download','stage','build'].indexOf(op)>=0?catalogData(op):{op:op},confirmation=String($(this).data('confirm')||'');if(confirmation){if(!confirm('This will run: '+confirmation+'. Continue?'))return;data.confirmation=confirmation;}message(op==='build'?'Building Asterisk. This can take several minutes; keep this page open.':'Running '+op+'…');request(data,function(resp){var r=result(resp);if(!r.ok){message(r.message||resp.message||'Operation failed',false);return;}done(r);});});
  $('.ucm-bundle-op').on('click',function(){if(!local||busy)return;var op=String($(this).data('op')),id=String($(this).data('id')),confirmation=(op==='activate'?'ACTIVATE ':(op==='pin'?'PIN ':'DELETE '))+id;if(!confirm('Confirm '+confirmation+'?'+(op==='activate'?'\nAsterisk will restart and active calls will be interrupted.':'')))return;message('Running '+op+'…');request({op:op,bundle_id:id,confirmation:confirmation},function(resp){var r=result(resp);if(!r.ok){message(r.message||resp.message||'Operation failed',false);return;}done(r);});});
  $('.ucm-import-incoming').on('click',function(){if(!local||busy)return;var id=String($(this).data('id'));message('Importing synchronized bundle…');request({op:'import',incoming_id:id},function(resp){var r=result(resp);if(!r.ok){message(r.message||resp.message||'Import failed',false);return;}done(r);});});
  $('#ucm-patch-upload-button').on('click',function(){if(!local||busy)return;var file=$('#ucm-patch-upload')[0].files[0];if(!file){message('Choose an Asterisk executable or exported bundle.',false);return;}var form=new FormData();form.append('command','patchworkflow');form.append('site_id',siteId);form.append('op','upload');form.append('file',file,file.name);form.append('declared_version',$('#ucm-patch-upload-version').val());form.append('declared_patched',$('#ucm-patch-upload-patched').is(':checked')?1:0);message('Uploading and importing '+file.name+'…');submit(form,function(resp){var r=result(resp);if(!r.ok){message(r.message||resp.message||'Upload failed',false);return;}done(r);},function(xhr){message(xhr.responseText&&xhr.responseText.indexOf('AJAX request denied')>=0?'PHP rejected the upload; check post_max_size and upload_max_filesize.':'Upload response was lost or failed; refresh inventory before importing again.',false);});});
  $('.ucm-bundle-sync').on('click',function(){if(!local||busy)return;var targets=$('.ucm-asterisk-sync-target:checked').map(function(){return parseInt(this.value,10);}).get(),id=String($(this).data('id'));if(!targets.length){message('Choose at least one different synchronization target.',false);return;}message('Synchronizing compiled bundle to '+targets.length+' site(s)…');request({op:'sync',bundle_id:id,target_site_ids:targets},function(resp){var r=result(resp);message(r.message||'Bundle synchronization complete',!!r.ok,r.sites?JSON.stringify(r.sites,null,2):'');});});
});
</script>
