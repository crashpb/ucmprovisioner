<?php
/** TFTP Browser view: SFTP file browser over each site's tftpboot volume. $ucm in scope. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$sites = $ucm->getSites();
$defaultSite = $ucm->localSiteId();
?>
<div class="ucm-container"><div class="ucm-page-intro"><div><h2><?php echo _('TFTP Browser'); ?></h2><p><?php echo _('Inspect the real site TFTP volume, upload several files, manage folders and perform audited phone-config overrides without bypassing asset ownership.'); ?></p></div></div><section class="ucm-card"><div class="ucm-toolbar" style="margin-bottom:10px;">
  <label style="margin-right:6px;"><?php echo _('Site'); ?></label>
  <select class="form-control" id="ucm-tftp-site" style="width:220px;display:inline-block;">
    <?php foreach ($sites as $s): ?>
      <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === $defaultSite) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-default" id="ucm-tftp-mkdir"><?php echo _('New folder'); ?></button>
  <button class="btn btn-default" id="ucm-tftp-newfile"><?php echo _('New file'); ?></button>
  <button class="btn btn-default" id="ucm-tftp-refresh"><?php echo _('Refresh'); ?></button>
  <button class="btn btn-default" id="ucm-tftp-lock-status"><?php echo _('Writer lock status'); ?></button>
  <button class="btn btn-default" id="ucm-tftp-download-selected" disabled><?php echo _('Download selected'); ?></button>
  <button class="btn btn-danger" id="ucm-tftp-delete-selected" disabled><?php echo _('Delete selected'); ?></button>
  <span style="display:inline-block;margin-left:10px;">
    <input type="file" id="ucm-tftp-upload-input" style="display:none;" multiple>
    <button class="btn btn-primary" id="ucm-tftp-upload-btn"><?php echo _('Upload files here'); ?></button>
  </span>
  <button class="btn btn-warning" id="ucm-tftp-regen-list" style="display:none;margin-left:10px;"><?php echo _('Regenerate List.xml from files here'); ?></button>
  <span id="ucm-tftp-msg" class="text-muted" style="margin-left:10px;"></span>
</div>
<div id="ucm-tftp-lock-info" class="alert alert-info" style="display:none;" role="status"></div>
<p class="text-muted">
  <?php echo _('Backgrounds (XML-generation Cisco phones only — see Settings tab for which models): upload matching pairs of full-size + "-tn" thumbnail PNGs into the model\'s exact Desktops/&lt;width&gt;x&lt;height&gt;x&lt;depth&gt; directory (per Cisco\'s documented resolution table), then click "Regenerate List.xml" while browsing that folder. Firmware belongs under Firmware/&lt;series&gt;.'); ?>
</p>
<p class="text-muted">
  <?php echo _('Files owned by the active provisioning manifest are marked Managed. Only a root phone config can be explicitly overridden for troubleshooting; other generated files stay read-only. Manual phone configs are skipped by Rebuild All until Replace config is used on Phones.'); ?>
</p>
<div class="breadcrumb" id="ucm-tftp-breadcrumb" style="margin-bottom:10px;"></div>
<table class="table table-striped">
  <thead><tr>
    <th style="width:3%"><input type="checkbox" id="ucm-tftp-check-all" title="<?php echo _('Select files or directories'); ?>"></th>
    <th style="width:42%"><?php echo _('Name'); ?></th>
    <th style="width:15%"><?php echo _('Size'); ?></th>
    <th style="width:25%"><?php echo _('Modified'); ?></th>
    <th style="width:15%"><?php echo _('Actions'); ?></th>
  </tr></thead>
  <tbody id="ucm-tftp-rows"></tbody>
</table>
</section>

<form id="ucm-tftp-download-form" action="modules/ucmprovisioner/tftp_download.php" method="post" target="_blank" style="display:none;">
  <input type="hidden" name="ucmp_csrf" value="<?php echo htmlspecialchars($ucm->pageCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
  <input type="hidden" name="site_id" value="">
  <input type="hidden" name="paths" value="[]">
</form>

<div class="modal fade" id="ucm-tftp-edit-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title"><?php echo _('Edit file'); ?>: <span id="ucm-tftp-edit-name"></span></h4>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning" id="ucm-tftp-override-warning" style="display:none;">
          <?php echo _('Saving this root phone config creates a tracked manual override. Rebuild All will leave it untouched; use Replace config on Phones to return it to generated management.'); ?>
        </div>
        <textarea id="ucm-tftp-edit-body" class="form-control" rows="22" style="font-family:monospace;"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _('Cancel'); ?></button>
        <button type="button" class="btn btn-primary" id="ucm-tftp-save"><?php echo _('Save'); ?></button>
      </div>
    </div>
  </div>
</div>

<script>
(function ($) {
  'use strict';
  var state = { siteId: $('#ucm-tftp-site').val(), path: '', phoneConfigs: {} };
  var uploadLimit = 0, uploadLimitLabel = '';
  var loadRequest = 0, editRequest = 0;
  var TEXT_EXT = ['cnf', 'xml', 'cfg', 'txt', 'conf', 'json', 'csv'];

  function isTextFile(name) {
    var ext = (name.split('.').pop() || '').toLowerCase();
    return TEXT_EXT.indexOf(ext) !== -1;
  }
  function fmtSize(n) {
    if (n === null || n === undefined) { return ''; }
    if (n < 1024) { return n + ' B'; }
    if (n < 1024 * 1024) { return (n / 1024).toFixed(1) + ' KB'; }
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }
  function fmtTime(t) {
    if (!t) { return ''; }
    var d = new Date(t * 1000);
    return d.toISOString().replace('T', ' ').substr(0, 19);
  }
  function tftpCall(extra) {
    return window.ucmpAjax({data:$.extend({command: 'tftp', site_id: state.siteId }, extra) });
  }
  function msg(text, isError) {
    $('#ucm-tftp-msg').toggleClass('text-danger', !!isError).toggleClass('text-success', !isError).text(text);
  }
  function renderBreadcrumb() {
    var parts = state.path ? state.path.split('/') : [];
    var $crumb = $('#ucm-tftp-breadcrumb').empty();
    $crumb.append($('<a href="#">').attr('data-path','').text('<?php echo _('root'); ?>'));
    var acc = [];
    parts.forEach(function (p) {
      acc.push(p);
      $crumb.append(document.createTextNode(' > '));
      $crumb.append($('<a href="#">').attr('data-path',acc.join('/')).text(p));
    });
    // Show "Regenerate List.xml" only inside a Desktops/<resolution> directory.
    var isDesktopsRes = /^Desktops\/\d+x\d+x\d+$/.test(state.path);
    $('#ucm-tftp-regen-list').toggle(isDesktopsRes);
  }
  function load() {
    var requestId = ++loadRequest, siteId = state.siteId, folder = state.path;
    renderBreadcrumb();
    $('#ucm-tftp-check-all').prop({checked:false, disabled:true});
    $('#ucm-tftp-delete-selected').prop('disabled', true);
    $('#ucm-tftp-download-selected').prop('disabled', true);
    $('#ucm-tftp-rows').html('<tr><td colspan="5" class="text-muted">' + '<?php echo _('Loading...'); ?>' + '</td></tr>');
    tftpCall({ op: 'list', site_id: siteId, path: folder }).done(function (resp) {
      if (requestId !== loadRequest || siteId !== state.siteId || folder !== state.path) { return; }
      var r = (resp && resp.result) || {};
      if (!r.ok) { $('#ucm-tftp-rows').empty().append($('<tr>').append($('<td colspan="5" class="text-danger">').text(r.message || 'Error'))); return; }
      var $body = $('#ucm-tftp-rows').empty();
      state.phoneConfigs = {};
      (r.entries || []).forEach(function (e) {
        var phoneConfig = !!(e.managed_phone_config || e.manual_override);
        if (phoneConfig) { state.phoneConfigs[e.path] = true; }
        var deletable = !e.dir && !e.managed && !e.manual_override;
        var $row = $('<tr>'), $name = $('<td>'), $actions = $('<td>');
        if (e.dir) {
          $name.append($('<a href="#" class="ucm-tftp-dir">').attr('data-path',e.path).text('📁 '+e.name));
        } else if (phoneConfig && isTextFile(e.name)) {
          $name.append($('<a href="#" class="ucm-tftp-file">').attr({
            'data-path':e.path,
            'data-name':e.name,
            'data-phone-config':'1'
          }).text('📄 '+e.name));
          $name.append(' ', $('<span class="label label-warning">').text(e.manual_override ? '<?php echo _('Manual override'); ?>' : '<?php echo _('Managed phone config'); ?>'));
        } else if (e.managed) {
          $name.append($('<span>').text(e.name), ' ', $('<span class="label label-info">').text('<?php echo _('Managed'); ?>'));
        } else {
          var $label = isTextFile(e.name) ? $('<a href="#" class="ucm-tftp-file">').attr({'data-path':e.path,'data-name':e.name}) : $('<span>');
          $name.append($label.text('📄 '+e.name));
        }
        $actions.append($('<button class="btn btn-xs btn-default ucm-tftp-download">').attr('data-path',e.path).text('<?php echo _('Download'); ?>'));
        if (!e.managed && !e.manual_override) {
          $actions.append(' ');
          $actions.append($('<button class="btn btn-xs btn-danger ucm-tftp-del">').attr({'data-path':e.path,'data-dir':e.dir?'1':'0'}).text('<?php echo _('Delete'); ?>'));
        }
        var $select = $('<td>');
        $select.append($('<input type="checkbox" class="ucm-tftp-select">')
          .attr({'data-path':e.path,'data-deletable':deletable?'1':'0','data-dir':e.dir?'1':'0'}));
        $row.append($select, $name, $('<td>').text(e.dir?'':fmtSize(e.size)), $('<td>').text(e.dir?'':fmtTime(e.mtime)), $actions);
        $body.append($row);
      });
      if (!(r.entries || []).length) { $body.append($('<tr>').append($('<td colspan="5" class="text-muted">').text('<?php echo _('Empty directory'); ?>'))); }
      $('#ucm-tftp-check-all').prop('disabled', !$('.ucm-tftp-select').length);
      msg(r.manifest_warning || '', !!r.manifest_warning);
    }).fail(function () { if (requestId === loadRequest) { $('#ucm-tftp-rows').html('<tr><td colspan="5" class="text-danger">' + '<?php echo _('Request failed'); ?>' + '</td></tr>'); } });
  }

  $('#ucm-tftp-site').on('change', function () { state.siteId = $(this).val(); state.path = ''; editRequest++; $('#ucm-tftp-edit-modal').modal('hide'); $('#ucm-tftp-lock-info').hide(); load(); });
  $('#ucm-tftp-refresh').on('click', load);
  $('#ucm-tftp-lock-status').on('click', function () {
    var siteId = state.siteId;
    tftpCall({op:'lock_status',site_id:siteId}).done(function (response) {
      if (siteId !== state.siteId) { return; }
      var r = (response && response.result) || {}, owner = r.owner || {};
      var text = r.ok ? 'Writer lock: ' + r.state + '. Observed ' + fmtTime(r.observed_at) + ' UTC.' : (r.message || 'Lock status is unknown');
      if (r.state === 'locked') { text += ' Started ' + fmtTime(owner.started) + ' UTC; receipt ' + (owner.nonce || 'unavailable') + '. ' + (r.message || ''); }
      $('#ucm-tftp-lock-info').text(text).show();
    });
  });
  $(document).on('click', '#ucm-tftp-breadcrumb a', function (e) { e.preventDefault(); state.path = $(this).data('path'); load(); });
  $(document).on('click', '.ucm-tftp-dir', function (e) { e.preventDefault(); state.path = $(this).data('path'); load(); });

  $(document).on('click', '.ucm-tftp-file', function (e) {
    e.preventDefault();
    var path = $(this).data('path'), name = $(this).data('name');
    var siteId = state.siteId, requestId = ++editRequest;
    var phoneConfig = String($(this).data('phone-config') || '0') === '1';
    tftpCall({ op: 'get', site_id: siteId, path: path }).done(function (resp) {
      if (requestId !== editRequest || siteId !== state.siteId) { return; }
      var r = (resp && resp.result) || {};
      if (!r.ok) { msg(r.message || 'Error', true); return; }
      $('#ucm-tftp-edit-name').text(name);
      $('#ucm-tftp-edit-body').val(r.content).data({'site-id':siteId, 'path':path, 'phone-config':phoneConfig ? 1 : 0});
      $('#ucm-tftp-override-warning').toggle(phoneConfig);
      $('#ucm-tftp-edit-modal').modal('show');
    });
  });
  $('#ucm-tftp-save').on('click', function () {
    var path = $('#ucm-tftp-edit-body').data('path');
    var phoneConfig = String($('#ucm-tftp-edit-body').data('phone-config') || '0') === '1';
    if (phoneConfig && !window.confirm('<?php echo _('Override this managed phone config? Rebuild All will skip it until you use Replace config on Phones.'); ?>')) { return; }
    tftpCall({
      op: 'put',
      site_id: $('#ucm-tftp-edit-body').data('site-id'),
      path: path,
      content: $('#ucm-tftp-edit-body').val(),
      override_managed: phoneConfig ? 1 : 0
    }).done(function (resp) {
      var r = (resp && resp.result) || {};
      msg(r.message || (r.ok ? 'Saved' : 'Error'), !r.ok);
      if (r.ok) { $('#ucm-tftp-edit-modal').modal('hide'); }
    });
  });

  $(document).on('click', '.ucm-tftp-del', function () {
    var path = $(this).data('path');
    if (!window.confirm('<?php echo _('Delete'); ?> ' + path + '?')) { return; }
    tftpCall({ op: 'delete', path: path }).done(function (resp) {
      var r = (resp && resp.result) || {};
      msg(r.message || (r.ok ? 'Deleted' : 'Error'), !r.ok);
      load();
    });
  });

  function selectedPaths() {
    return $('.ucm-tftp-select:checked').map(function () {
      return String($(this).data('path') || '');
    }).get().filter(Boolean);
  }
  function selectedDeletePaths() {
    return $('.ucm-tftp-select:checked[data-deletable="1"]').map(function () {
      return String($(this).data('path') || '');
    }).get().filter(Boolean);
  }
  function submitDownload(paths) {
    paths = (paths || []).filter(Boolean);
    if (!paths.length) { return; }
    var form = $('#ucm-tftp-download-form');
    form.find('[name=site_id]').val(state.siteId);
    form.find('[name=paths]').val(JSON.stringify(paths));
    form.get(0).submit();
  }
  $(document).on('click', '.ucm-tftp-download', function () { submitDownload([String($(this).data('path') || '')]); });
  $('#ucm-tftp-download-selected').on('click', function () { submitDownload(selectedPaths()); });
  $(document).on('change', '.ucm-tftp-select', function () {
    var available = $('.ucm-tftp-select').length;
    var selected = selectedPaths().length;
    $('#ucm-tftp-check-all').prop('checked', available > 0 && selected === available);
    $('#ucm-tftp-download-selected').prop('disabled', selected === 0);
    $('#ucm-tftp-delete-selected').prop('disabled', selectedDeletePaths().length === 0);
  });
  $('#ucm-tftp-check-all').on('change', function () {
    $('.ucm-tftp-select').prop('checked', this.checked);
    $('#ucm-tftp-download-selected').prop('disabled', selectedPaths().length === 0);
    $('#ucm-tftp-delete-selected').prop('disabled', selectedDeletePaths().length === 0);
  });
  $('#ucm-tftp-delete-selected').on('click', function () {
    var paths = selectedDeletePaths();
    if (!paths.length) { return; }
    if (!window.confirm('<?php echo _('Delete the selected files? This cannot be undone.'); ?>')) { return; }
    tftpCall({op:'delete_batch', paths:paths}).done(function (resp) {
      var r = (resp && resp.result) || {};
      msg(r.message || (r.ok ? 'Deleted' : 'Delete failed'), !r.ok);
      load();
    }).fail(function () { msg('<?php echo _('Batch delete request failed'); ?>', true); });
  });

  $('#ucm-tftp-mkdir').on('click', function () {
    var name = window.prompt('<?php echo _('New folder name'); ?>');
    if (!name) { return; }
    var path = state.path ? (state.path + '/' + name) : name;
    tftpCall({ op: 'mkdir', path: path }).done(function (resp) {
      var r = (resp && resp.result) || {};
      msg(r.message || (r.ok ? 'Created' : 'Error'), !r.ok);
      load();
    });
  });
  $('#ucm-tftp-newfile').on('click', function () {
    var name = window.prompt('<?php echo _('New file name'); ?>');
    if (!name) { return; }
    var path = state.path ? (state.path + '/' + name) : name;
    tftpCall({ op: 'put', path: path, content: '', allow_empty: 1 }).done(function (resp) {
      var r = (resp && resp.result) || {};
      msg(r.message || (r.ok ? 'Created' : 'Error'), !r.ok);
      load();
    });
  });

  $('#ucm-tftp-regen-list').on('click', function () {
    msg('Regenerating List.xml...');
    tftpCall({ op: 'regen_list', path: state.path }).done(function (resp) {
      var r = (resp && resp.result) || {};
      var text = r.message || (r.ok ? 'Done' : 'Error');
      if (r.skipped && r.skipped.length) { text += ' — ' + r.skipped.join('; '); }
      msg(text, !r.ok);
      load();
    }).fail(function () { msg('Request failed', true); });
  });

  $('#ucm-tftp-upload-btn').on('click', function () { $('#ucm-tftp-upload-input').trigger('click'); });
  $('#ucm-tftp-upload-input').on('change', function () {
    var input=this, files=Array.prototype.slice.call(this.files||[]), seen={}, invalid=[], managed=[];
    var uploadSiteId = state.siteId, uploadPath = state.path, uploadPhoneConfigs = $.extend({}, state.phoneConfigs);
    if (!files.length) { return; }
    if (files.length>50) { msg('Choose at most 50 files in one batch.',true); $(this).val(''); return; }
    files.forEach(function(file){
      var key=String(file.name||'').toLowerCase(),path=uploadPath?(uploadPath+'/'+file.name):file.name;
      if (!file.name||file.name.length>128||/[\x00-\x1f\x7f\/\\]/.test(file.name)||file.name==='.'||file.name==='..') invalid.push(file.name||'(unnamed)');
      else if(seen[key]) invalid.push(file.name+' (duplicate name)');
      else if(uploadLimit&&file.size+65536>uploadLimit) invalid.push(file.name+' ('+fmtSize(file.size)+') exceeds '+(uploadLimitLabel||'the PHP upload limit'));
      seen[key]=true;
      if(uploadPhoneConfigs[path]) managed.push(path);
    });
    if(invalid.length){msg('Upload cancelled: '+invalid.join('; '),true);$(this).val('');return;}
    if(managed.length&&!window.confirm(
      '<?php echo _('This batch replaces managed phone config(s) and records manual overrides. Rebuild All will skip them until Replace config is used on Phones. Continue?'); ?>'
      +'\n\n'+managed.join('\n')
    )){$(this).val('');return;}
    var uploaded=[],failed=[],index=0,button=$('#ucm-tftp-upload-btn').prop('disabled',true);
    function next(){
      if(index>=files.length){
        input.value='';
        button.prop('disabled',false);
        var summary='Uploaded '+uploaded.length+' of '+files.length+' file(s).';
        if(failed.length)summary+=' Failed: '+failed.join('; ');
        msg(summary,failed.length>0);
        load();
        return;
      }
      var file=files[index++],path=uploadPath?(uploadPath+'/'+file.name):file.name,phoneConfig=!!uploadPhoneConfigs[path],fd=new FormData();
      fd.append('command','tftp');fd.append('op','upload');fd.append('site_id',uploadSiteId);fd.append('path',path);
      if(phoneConfig)fd.append('override_managed','1');
      fd.append('file',file);
      msg('Uploading '+index+' of '+files.length+': '+file.name+' ('+fmtSize(file.size)+')...');
      window.ucmpAjax({data:fd}).done(function(resp){
        var r=(resp&&resp.result)||{};
        if(r.ok)uploaded.push(file.name);else failed.push(file.name+' — '+(r.message||'upload failed'));
      }).fail(function(xhr){
        failed.push(file.name+' — '+(xhr.responseText&&xhr.responseText.indexOf('AJAX request denied')!==-1?'PHP rejected the request; check upload limits':'request failed'));
      }).always(next);
    }
    next();
  });

  tftpCall({ op: 'upload_limits' }).done(function (resp) {
    var r = (resp && resp.result) || {};
    if (r.ok) {
      uploadLimit = Number(r.effective_max_bytes || 0);
      uploadLimitLabel = 'post_max_size ' + r.post_max + ', upload_max_filesize ' + r.upload_max;
    }
  });
  load();
})(jQuery);
</script>
</div>
