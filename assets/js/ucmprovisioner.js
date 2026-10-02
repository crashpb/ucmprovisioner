/* UCM Provisioner — UI behaviours (FreePBX bundles jQuery). */
(function ($) {
  'use strict';

  var siteOperationLogRequest = null;
  var siteOperationLogRefreshPending = false;
  var siteOperationLogTimer = null;
  var rebuildRequestGeneration = 0;
  var rebuildApplying = false;
  var cutoverPreviewGeneration = 0;
  var cutoverApplying = false;
  var meshApplying = false;

  function pinSelectedOptions(select) {
    Array.from(select.options).sort(function (a,b) {
      return Number(b.selected)-Number(a.selected) || a.text.localeCompare(b.text);
    }).forEach(function (option) { select.appendChild(option); });
  }
  $(function () {
    $('.ucm-web-multiselect').each(function () {
      var select=$(this);
      pinSelectedOptions(this);
      if (!$.fn.selectpicker) return;
      // FreePBX already supplies this searchable, keyboard-accessible dropdown.
      select.selectpicker({width:'100%',liveSearch:true,size:8,selectedTextFormat:'count > 2',countSelectedText:'{0} selected',style:'btn-default'});
      select.on('changed.bs.select',function () {
        var search=select.parent().find('.bs-searchbox input').val() || '';
        pinSelectedOptions(this);
        select.selectpicker('refresh');
        select.parent().find('.bs-searchbox input').val(search).trigger('input');
      });
    });
  });


  window.ucmpRetainedSelection = function(selector, saved) {
    var visible=$(selector).map(function(){return String(this.value);}).get();
    var selected=$(selector+':checked').map(function(){return String(this.value);}).get();
    return Array.from(new Set(selected.concat((saved||[]).map(String).filter(function(value){return visible.indexOf(value)===-1;}))));
  };

  // The three scoped editors share one save/run lifecycle and retain the server receipt.
  window.ucmpConfigurationEditor = function(options) {
    var busy=false, uncertain=false, disabled=[];
    var resultBox=$('<div class="ucmp-sync-last-result">').insertAfter('#'+options.prefix+'-items');
    function finish() {
      disabled.forEach(function(item){item.node.prop('disabled',item.value);});
      disabled=[];busy=false;
    }
    function begin() {
      if(busy)return false;
      if(uncertain){options.feedback('The last request has no completion receipt. Reload job history before another change.',true);return false;}
      busy=true;
      $('.ucmp-module-shell :input').each(function(){var node=$(this);disabled.push({node:node,value:node.prop('disabled')});node.prop('disabled',true);});
      return true;
    }
    function showResult(result) {
      resultBox.empty().append($('<h4>').text('Last recorded run'),window.ucmpReplicationResults(result));
    }
    function refresh(id, message, bad) {
      window.ucmpAjax({data:{command:'configurationsync',op:'list'}}).done(function(resp){
        var r=resp&&resp.result||{};
        if(!(resp&&resp.status&&r.ok&&Array.isArray(r.jobs))){finish();options.feedback(message+'; job history could not be refreshed: '+(r.message||resp&&resp.message||'missing receipt'),true);return;}
        finish();options.list(r.jobs||[]);
        var job=(r.jobs||[]).find(function(candidate){return Number(candidate.id)===Number(id);});
        if(job||!id)options.load(job||null,!!id);
        options.feedback(message+(id&&!job?'; this job is absent from the refreshed list':''),bad||!!(id&&!job));
      }).fail(function(){finish();options.feedback(message+'; job history refresh failed',true);});
    }
    function request(data, thenRun) {
      window.ucmpAjax({data:data}).done(function(resp){
        var r=resp&&resp.result||{},ok=!!(resp&&resp.status&&r.ok);
        var message=r.message||resp&&resp.message||(ok?'Operation completed':'Operation did not complete');
        if(data.op==='run')showResult(r);
        if(!ok&&data.op!=='run'){finish();options.feedback(message,true);return;}
        if(data.op==='save'&&ok){
          if(!(r.job&&Number(r.job.id)>0)){uncertain=true;finish();options.feedback('Save returned no job identity. Reload history before retrying.',true);return;}
          options.remember(r.job);
          if(thenRun){options.feedback('Settings saved; running this job…',false);request({command:'configurationsync',op:'run',id:r.job.id,force:0},false);return;}
        }
        if(data.op==='run'&&resp&&resp.status&&r.needs_confirmation&&!data.force&&window.confirm(message+'\n\nRun this job again with conflict replacement enabled? Review the recorded destination results first.')){
          request({command:'configurationsync',op:'run',id:data.id,force:1},false);return;
        }
        var id=data.op==='delete'&&ok?0:(r.job&&r.job.id||data.id);
        refresh(id,message,!ok);
      }).fail(function(){
        uncertain=true;finish();
        options.feedback('No '+data.op+' completion receipt was received. The outcome is unknown; reload job history before retrying.',true);
      });
    }
    return {
      busy:function(){return busy;},
      showResult:showResult,
      save:function(payload,thenRun){
        var job=options.current(),allowed=options.scopes();
        if(job&&(job.scopes||[]).some(function(scope){return allowed.indexOf(scope)===-1;})){
          options.feedback('This job includes other scopes. Edit or run the complete job in Sites → Configuration sync; its saved selections have been preserved.',true);return;
        }
        if(!begin())return;
        options.feedback('Saving synchronization settings…',false);request(payload,thenRun);
      },
      mutate:function(data){if(!begin())return;options.feedback('Working…',false);request(data,false);}
    };
  };

  function call(params, $result) {
    $result = $result || $('#ucm-action-result');
    $result.removeClass('text-danger text-success').text('Working...');
    return window.ucmpAjax({ data: params })
      .done(function (resp) {
        if (resp && resp.status) {
          var r = resp.result || {};
          var msg = r.message || 'Done.';
          $result.addClass(r.ok === false ? 'text-danger' : 'text-success').text(msg);
          if (r.ok !== false && (params.command === 'discover' || params.command === 'command')) {
            $(document).trigger('ucm:phones-changed', [params.command]);
          }
        } else {
          $result.addClass('text-danger').text((resp && resp.message) || 'Request failed');
        }
      })
      .fail(function () {
        $result.addClass('text-danger').text('Request failed');
      });
  }

  function selectedIds() {
    return $('.ucm-row:checked').map(function () { return $(this).val(); }).get().join(',');
  }

  function updateSelectionActions() {
    var count = $('.ucm-row:checked').length;
    $('.ucm-phone-selection-count').text(count);
    $('.ucm-delete-selected').prop('disabled', count === 0).text(count ? 'Delete selected (' + count + ')' : 'Delete selected');
  }

  $(document).on('click', '.ucm-action', function (e) {
    e.preventDefault();
    var $b = $(this);
    call({ command: $b.data('command'), ids: $b.data('ids') || '', site_id: $b.data('site') || '' });
  });

  $(document).on('click', '.ucm-cmd', function (e) {
    e.preventDefault();
    var $b = $(this);
    call({ command: 'command', id: $b.data('id'), cmd: $b.data('cmd'), site_id: $b.data('site') || '' });
  });

  /* Rebuild flow: dry-run plan shown in a proper modal (human-readable, not raw JSON dumped
   * into the page), explicit Apply/Cancel buttons instead of a native confirm() (which some
   * embedded/managed browser contexts suppress silently), and an unambiguous done-state instead
   * of leaving "Applying..." on screen with no indication of completion. */
  function ensureRebuildModal() {
    if ($('#ucm-rebuild-modal').length) { return; }
    $('body').append([
      '<div class="modal fade" id="ucm-rebuild-modal" tabindex="-1" role="dialog">',
      '  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">',
      '    <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>',
      '      <h4 class="modal-title">' + 'Rebuild configs' + '</h4></div>',
      '    <div class="modal-body" id="ucm-rebuild-modal-body"></div>',
      '    <div class="modal-footer">',
      '      <a href="#" id="ucm-rebuild-toggle-raw" class="pull-left text-muted">Show raw details</a>',
      '      <button type="button" class="btn btn-default" data-dismiss="modal" id="ucm-rebuild-cancel">Cancel</button>',
      '      <button type="button" class="btn btn-primary" id="ucm-rebuild-apply-btn">Apply</button>',
      '    </div>',
      '  </div></div>',
      '</div>'
    ].join(''));
  }

  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function renderRebuildPlan(r) {
    var phones = r.phones || [];
    var conflicts = (r.glue && r.glue.conflicts) || [];
    var warnCount = phones.reduce(function (a, p) { return a + ((p.warnings || []).length ? 1 : 0); }, 0);
    var html = '<p>' + phones.length + ' phone(s) will be rebuilt and pushed to their site\'s TFTP server'
      + (warnCount ? ' (' + warnCount + ' with warnings, shown below)' : '') + ':</p>';
    if (phones.length) {
      // Capped + scrollable — an unfiltered "Rebuild all" run can easily be 50-100+ rows, which
      // made the previous unbounded table an unusably long dialog to scroll/navigate.
      html += '<div style="max-height:280px;overflow:auto;border:1px solid #ddd;">'
        + '<table class="table table-condensed table-striped" style="margin-bottom:0;"><thead><tr><th>Ext</th><th>Family</th><th>File</th><th>Size</th><th>Warnings</th></tr></thead><tbody>';
      phones.forEach(function (p) {
        html += '<tr><td>' + escapeHtml(p.ext) + '</td><td>' + escapeHtml(p.family) + '</td><td>' + escapeHtml(p.filename) + '</td><td>' + p.bytes + ' bytes</td>' +
          '<td>' + ((p.warnings || []).length ? '<span class="text-warning">' + escapeHtml(p.warnings.join('; ')) + '</span>' : '<span class="text-muted">none</span>') + '</td></tr>';
      });
      html += '</tbody></table></div>';
    } else {
      html += '<div class="alert alert-info">Nothing to build for this selection (no phones with both an extension and a MAC on file).</div>';
    }
    var managedExtensions = (r.glue && r.glue.managed_extensions) || [];
    if (managedExtensions.length) {
      html += '<div class="alert alert-info"><strong>Site-wide Asterisk glue:</strong> '
        + managedExtensions.length + ' Cisco endpoint block(s) are recomputed to keep shared hints consistent. '
        + 'This is not a list of extensions watched by the selected phone.</div>';
    }
    var migrations = (r.glue && r.glue.migrations) || [];
    var preflight = (r.glue && r.glue.preflight) || {};
    if (preflight.warning) {
      html += '<div class="alert alert-danger"><strong>PBX preflight:</strong> ' + escapeHtml(preflight.warning) + '<br><small>' + escapeHtml(preflight.asterisk_version || '') + '</small></div>';
    }
    if (migrations.length) {
      html += '<div class="alert alert-info"><strong>' + migrations.length + ' pre-existing entry(s) will be consolidated</strong> into the managed block. Their effective behavior already matches the generated policy; consolidation gives the module transactional ownership and permits managed subscribe=/BLF fields:'
        + '<ul style="max-height:120px;overflow:auto;margin-bottom:4px;">';
      migrations.forEach(function (c) { html += '<li>' + escapeHtml(c) + '</li>'; });
      html += '</ul></div>';
    }
    if (conflicts.length) {
      html += '<div class="alert alert-warning"><strong>' + conflicts.length + ' extension(s) will be SKIPPED</strong> — existing hand-edited config with directives this module doesn\'t recognize, never auto-overwritten:'
        + '<ul style="max-height:150px;overflow:auto;margin-bottom:4px;">';
      conflicts.forEach(function (c) { html += '<li>' + escapeHtml(c) + '</li>'; });
      html += '</ul><small>Edit or remove the conflicting manual entry yourself if you want this module to manage that extension.</small></div>';
    }
    var notices = r.notices || [];
    if (notices.length) {
      html += '<div class="alert alert-warning"><strong>Phone configs left untouched:</strong><ul style="margin-bottom:0;">';
      notices.forEach(function (notice) { html += '<li>' + escapeHtml(notice) + '</li>'; });
      html += '</ul></div>';
    }
    var ciscoXmlCount = phones.filter(function (p) { return p.family === 'cisco_xml'; }).length;
    if (ciscoXmlCount) {
      html += '<div class="alert alert-warning"><label style="font-weight:normal;margin:0;">'
        + '<input type="checkbox" id="ucm-rebuild-restart-cisco" value="1"> '
        + '<strong>Restart ' + ciscoXmlCount + ' Cisco XML phone(s) after a successful push.</strong> '
        + 'A restart is required for newly-added BLF/line subscriptions to become active; it briefly interrupts those phones. UDP transport is unchanged.'
        + '</label></div>';
    }
    html += '<p class="text-muted">Apply writes the Asterisk glue config, pushes each file to TFTP over SFTP, and reloads chan_sip/PJSIP. Nothing is written until you click Apply.</p>';
    html += '<pre id="ucm-rebuild-raw" style="display:none;max-height:30vh;overflow:auto;">' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
    return html;
  }

  function renderApplyResult(r2, ok) {
    var stages = r2.stages || {};
    var partial = !ok && stages.files_published === true;
    var html = ok
      ? '<div class="alert alert-success"><strong>Done.</strong> ' + escapeHtml(r2.message || 'Applied.') + '</div>'
      : '<div class="alert ' + (partial ? 'alert-warning' : 'alert-danger') + '"><strong>'
        + (partial ? 'Partially completed.' : 'Failed.') + '</strong> ' + escapeHtml(r2.message || 'Apply failed.') + '</div>';
    if (Object.keys(stages).length) {
      html += '<ul><li>Configuration files: ' + (stages.files_published === true ? 'published and verified' : 'not confirmed')
        + '</li><li>PBX activation: ' + (stages.pbx_activated === true ? 'completed' : stages.pbx_activated === false ? 'failed' : 'not confirmed')
        + '</li><li>Phone applied configuration: ' + (stages.device_observed === true ? 'observed' : 'not yet verified') + '</li></ul>';
    }
    var push = r2.push || {};
    var pushFiles = Array.isArray(push) ? push : (Array.isArray(push.files) ? push.files : []);
    var failedPush = pushFiles.filter(function (x) {
      return x && (x.ok === false || x.committed === false);
    });
    if (failedPush.length) {
      html += '<div class="alert alert-warning"><strong>' + failedPush.length + ' file(s) failed to push:</strong><ul>';
      failedPush.forEach(function (x) { html += '<li>' + escapeHtml((x.ext || '') + ': ' + (x.message || 'unknown error')) + '</li>'; });
      html += '</ul></div>';
    }
    var restarts = Array.isArray(r2.restarts) ? r2.restarts : [];
    if (restarts.length) {
      var restartFailed = restarts.filter(function (x) { return !x || x.ok !== true; });
      html += '<div class="alert ' + (restartFailed.length ? 'alert-warning' : 'alert-info') + '">'
        + '<strong>' + (restarts.length - restartFailed.length) + ' phone reload request(s) dispatched.</strong> '
        + (restartFailed.length ? restartFailed.length + ' failed or unconfirmed.' : 'Phone receipt and configuration application are not yet verified.')
        + '<ul>';
      restarts.forEach(function (row) {
        row = row || {};
        html += '<li>' + escapeHtml(row.ext || row.id || 'Phone') + ': '
          + (row.ok === true ? 'dispatched — ' : 'failed or unconfirmed — ') + escapeHtml(row.message || 'No result received') + '</li>';
      });
      html += '</ul></div>';
    }
    var notices = Array.isArray(r2.notices) ? r2.notices : [];
    if (notices.length) {
      html += '<div class="alert alert-warning"><ul style="margin-bottom:0;">';
      notices.forEach(function (notice) { html += '<li>' + escapeHtml(notice) + '</li>'; });
      html += '</ul></div>';
    }
    html += '<pre id="ucm-rebuild-raw" style="display:none;max-height:30vh;overflow:auto;">' + escapeHtml(JSON.stringify(r2, null, 2)) + '</pre>';
    return html;
  }

  function doRebuild(ids, siteId, replaceManual) {
    ensureRebuildModal();
    if (rebuildApplying) { $('#ucm-rebuild-modal').modal('show'); return; }
    var requestGeneration = ++rebuildRequestGeneration;
    var $body = $('#ucm-rebuild-modal-body');
    var $applyBtn = $('#ucm-rebuild-apply-btn');
    var $cancelBtn = $('#ucm-rebuild-cancel');
    $body.html('<p class="text-muted">Computing plan (dry-run — nothing written yet)...</p>');
    $applyBtn.off('click').prop('disabled', true).text('Apply').show();
    $cancelBtn.text('Cancel').show();
    $('#ucm-rebuild-modal').modal('show');

    window.ucmpAjax({ data: {
      command: 'rebuild',
      ids: ids,
      apply: '0',
      site_id: siteId || '',
      replace_manual: replaceManual ? '1' : '0'
    } })
      .done(function (resp) {
        if (requestGeneration !== rebuildRequestGeneration) { return; }
        var r = (resp && resp.result) || {};
        if (!(resp && resp.status && r.ok)) {
          $body.html('<div class="alert alert-danger">' + escapeHtml((r && r.message) || (resp && resp.message) || 'Request failed') + '</div>');
          $applyBtn.hide();
          return;
        }
        $body.html(renderRebuildPlan(r));
        var patchBlocked = !!(r.glue && r.glue.requires_patch && !(r.glue.preflight && r.glue.preflight.eligible));
        $applyBtn.text(replaceManual ? 'Replace manual config' : 'Apply')
          .prop('disabled', !(r.phones || []).length || patchBlocked).off('click').on('click', function () {
          if (rebuildApplying || requestGeneration !== rebuildRequestGeneration) { return; }
          rebuildApplying = true;
          ++rebuildRequestGeneration;
          var restartAfter = $('#ucm-rebuild-restart-cisco').is(':checked') ? '1' : '0';
          $applyBtn.prop('disabled', true).text('Applying...');
          $cancelBtn.hide();
          window.ucmpAjax({ data: {
            command: 'rebuild',
            ids: ids,
            apply: '1',
            site_id: siteId || '',
            restart_after: restartAfter,
            plan_hash: r.plan_hash || '',
            replace_manual: replaceManual ? '1' : '0'
          } })
            .done(function (resp2) {
              rebuildApplying = false;
              var r2 = (resp2 && resp2.result) || {};
              var ok = !!(resp2 && resp2.status && r2.ok === true);
              $applyBtn.hide();
              $cancelBtn.text('Close').show();
              try {
                $body.html(renderApplyResult(r2, ok));
              } catch (renderError) {
                $body.html(
                  '<div class="alert alert-warning"><strong>Apply response received.</strong> '
                  + 'The result details could not be displayed; reload Phones to verify the current state.</div>'
                );
              }
              $('#ucm-action-result').removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger').text(r2.message || (ok ? 'Applied.' : 'Apply failed.'));
              if (ok) { $(document).trigger('ucm:rebuild-applied'); }
            })
            .fail(function () {
              rebuildApplying = false;
              $body.html('<div class="alert alert-warning">The apply response was lost. Work may have completed. Reconcile the retained build transaction before retrying.</div>');
              $applyBtn.hide();
              $cancelBtn.text('Close').show();
            });
        });
      })
      .fail(function () {
        if (requestGeneration !== rebuildRequestGeneration) { return; }
        $body.html('<div class="alert alert-danger">Rebuild request failed.</div>');
        $cancelBtn.text('Close').show();
      });
  }

  $(document).on('click', '#ucm-rebuild-toggle-raw', function (e) {
    e.preventDefault();
    $('#ucm-rebuild-raw').toggle();
    $(this).text($('#ucm-rebuild-raw').is(':visible') ? 'Hide raw details' : 'Show raw details');
  });

  $(document).on('click', '.ucm-rebuild', function (e) {
    e.preventDefault();
    var scope = $(this).data('scope');
    var replaceManual = String($(this).data('replace-manual') || '0') === '1';
    var ids = scope === 'all' ? 'all' : (scope === 'ids' ? String($(this).data('ids')) : selectedIds());
    var siteId = $(this).data('site') || $('#ucm-site-filter').val() || '';
    if (siteId === 'all') {
      $('#ucm-action-result').removeClass('text-success').addClass('text-danger').text('Choose one specific site before rebuilding. Rebuilds never cross site boundaries.');
      return;
    }
    if (scope !== 'all' && !ids) { $('#ucm-action-result').addClass('text-danger').text('Select at least one phone'); return; }
    doRebuild(ids, siteId, replaceManual);
  });

  $(document).on('click', '.ucm-restart-selected', function (e) {
    e.preventDefault();
    var $button = $(this);
    var ids = selectedIds();
    if (!ids) { $('#ucm-action-result').removeClass('text-success').addClass('text-danger').text('Select at least one phone'); return; }
    var idArray = ids.split(',');
    var siteId = $('#ucm-site-filter').val() || '';
    if (siteId === 'all' || !siteId) {
      $('#ucm-action-result').removeClass('text-success').addClass('text-danger').text('Choose one specific site before restarting phones. Commands never cross site boundaries.');
      return;
    }
    $button.prop('disabled', true);
    $('#ucm-action-result').removeClass('text-danger text-success').text('Requesting a configuration reload on ' + idArray.length + ' phone(s)...');
    window.ucmpAjax({ data: { command: 'commandbatch', ids: ids, cmd: 'restart', site_id: siteId } })
      .done(function(resp) {
        var result = (resp && resp.result) || {};
        var ok = !!(resp && resp.status && result.ok);
        $('#ucm-action-result').removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger').text(result.message || (ok ? 'Configuration reload commands sent.' : 'Configuration reload batch failed.'));
        if (ok) { $(document).trigger('ucm:phone-command-complete'); }
      })
      .fail(function() {
        $('#ucm-action-result').removeClass('text-success').addClass('text-danger').text('Configuration reload request failed.');
      })
      .always(function() { $button.prop('disabled', false); });
  });

  $(document).on('click', '.ucm-delete-selected', function (e) {
    e.preventDefault();
    var $button = $(this);
    var ids = selectedIds();
    var siteId = $('#ucm-site-filter').val() || '';
    var count = ids ? ids.split(',').length : 0;
    if (!count) { $('#ucm-action-result').removeClass('text-success').addClass('text-danger').text('Select at least one phone'); return; }
    if (siteId === 'all' || !siteId) {
      $('#ucm-action-result').removeClass('text-success').addClass('text-danger').text('Choose one specific site before deleting phones. Deletes never cross site boundaries.');
      return;
    }
    if (!window.confirm('Delete ' + count + ' selected physical phone(s)? Every MAC-derived TFTP config is removed; FreePBX extensions remain available as unprovisioned.')) { return; }

    $button.prop('disabled', true).text('Deleting...');
    $('#ucm-action-result').removeClass('text-danger text-success').text('Deleting ' + count + ' phone binding(s)...');
    var bindings={};$('.ucm-row:checked').each(function(){bindings[String(this.value)]=$(this).attr('data-binding-revision')||'';});
    window.ucmpAjax({ data: { command: 'deletephones', ids: ids, site_id: siteId, expected_bindings: JSON.stringify(bindings) } })
      .done(function(resp) {
        var result = (resp && resp.result) || {};
        var ok = !!(resp && resp.status && result.ok);
        $('#ucm-action-result').removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger').text(result.message || (ok ? 'Selected phones deleted.' : 'Delete batch failed.'));
        if (ok) {
          $('.ucm-row, #ucm-check-all').prop('checked', false);
          updateSelectionActions();
          $(document).trigger('ucm:phones-changed', ['deletephones']);
        }
      })
      .fail(function() {
        $('#ucm-action-result').removeClass('text-success').addClass('text-danger').text('Delete request failed.');
      })
      .always(updateSelectionActions);
  });

  $(document).on('click', '.ucm-testsite', function (e) {
    e.preventDefault();
    call({ command: 'testsite', id: $(this).data('id') });
  });

  function bootstrapFeedback($button, message, state, detail) {
    var tone = state === 'bad' ? 'danger' : (state === 'ok' ? 'success' : (state === 'warning' ? 'warning' : 'info'));
    var inlineTone = state === 'bad' ? 'text-danger' : (state === 'ok' ? 'text-success' : 'text-info');
    var $result = $('#ucm-bootstrap-result');
    var $feedback = $button && $button.length ? $button.closest('.ucm-card,.ucm-bootstrap-box').find('.ucm-inline-action-feedback').first() : null;
    if ($result && $result.length) {
      $result.removeClass('alert-info alert-warning alert-danger alert-success').addClass('alert alert-' + tone).empty().append($('<div>').text(message || ''));
      if (detail) { $result.append($('<pre class="ucm-operation-detail">').text(String(detail))); }
    }
    if ($feedback && $feedback.length) {
      $feedback.removeClass('text-danger text-success text-info').addClass(inlineTone).text(message || '');
    }
  }

  function bootstrapComponentReady(component) {
    return component === true || !!(component && (
      component.ok === true || component.ready === true || component.enrolled === true || component.verified === true
      || ['ready', 'enrolled', 'verified', 'healthy', 'success'].indexOf(String(component.status || '').toLowerCase()) !== -1
    ));
  }

  function bootstrapAgentResultKnown(result) {
    var components = result && result.components || {};
    return Object.prototype.hasOwnProperty.call(components, 'agent') || Object.prototype.hasOwnProperty.call(result || {}, 'agent');
  }

  function siteConnectionDraft() {
    var host = $.trim($('#ucm-pbx-host').val() || '');
    var draft = {
      pbx_host: host, pbx_addr: $.trim($('#ucm-pbx-voice-override').val() || '') || host,
      pbx_ssh_port: String($('#ucm-pbx-port').val() || '22'),
      docker_host: $.trim($('#ucm-docker-host').val() || ''),
      docker_ssh_port: String($('#ucm-docker-port').val() || '22'),
      tftp_container: $.trim($('[name="tftp_container"]').val() || ''),
      tftp_service_host: $.trim($('[name="tftp_service_host"]').val() || ''),
      pbx_tftp_service_host: $.trim($('[name="pbx_tftp_service_host"]').val() || '')
    };
    if ($('#ucm-api-scheme').length) {
      draft.pbx_api_scheme = $('#ucm-api-scheme').val();
      draft.pbx_api_host = $.trim($('#ucm-api-host').val() || '') || host;
      draft.pbx_api_port = String($('#ucm-api-port').val() || '80');
      draft.pbx_api_verify_tls = $('#ucm-api-verify').val();
    }
    return draft;
  }

  function syncTftpAddressDefault(selector, fallback, addressEdited) {
    var $field = $(selector);
    if (!$field.length) { return; }
    var current = $.trim($field.val() || ''), previous = String($field.data('tftp-default') || '');
    var automatic = $field.data('tftp-automatic') === true || String($field.data('tftp-automatic') || '') === '1';
    if (addressEdited) {
      if (current === '') { $field.val(fallback); automatic = true; }
      else if (previous === '' || current !== previous) { automatic = false; }
    } else if (automatic || current === '' || (previous !== '' && current === previous)) {
      $field.val(fallback);
      automatic = true;
    }
    $field.data('tftp-default', fallback).data('tftp-automatic', automatic ? '1' : '0');
  }

  function updatePbxAddressDefaults(addressEdited) {
    var draft = siteConnectionDraft();
    $('#ucm-pbx-voice-override, #ucm-api-host').attr('placeholder', draft.pbx_host);
    $('#ucm-api-effective-host').text(draft.pbx_api_host || draft.pbx_host);
    syncTftpAddressDefault('[name="pbx_tftp_service_host"]', draft.pbx_addr, addressEdited);
    syncTftpAddressDefault('[name="tftp_service_host"]', draft.docker_host, addressEdited);
    $('#ucm-pbx-tftp-host').val($.trim($('[name="pbx_tftp_service_host"]').val() || '') || draft.pbx_addr);
  }
  $(document).on('input change', '#ucm-pbx-host, #ucm-pbx-voice-override, #ucm-api-host, #ucm-docker-host', function () { updatePbxAddressDefaults(false); });
  $(document).on('input change', '[name="tftp_service_host"], [name="pbx_tftp_service_host"]', function () { updatePbxAddressDefaults(true); });
  if ($('#ucm-pbx-host').length) { updatePbxAddressDefaults(); }

  function operationLogId(log, index) {
    var id = String(log && log.id || '');
    return id || 'legacy-' + String(log && log.created || '0') + '-' + String(index);
  }

  function operationLogStatus(message, error) {
    $('#ucm-operation-log-status')
      .removeClass('text-danger text-success text-muted')
      .addClass(error ? 'text-danger' : 'text-success')
      .text(message || '');
  }

  function renderOperationLogs(logs) {
    var $rows = $('#ucm-operation-log-rows'), opened = {};
    if (!$rows.length) { return; }
    $rows.find('details[data-log-id]').each(function () {
      opened[String($(this).attr('data-log-id') || '')] = $(this).prop('open') === true;
    });
    $rows.empty();
    if (!Array.isArray(logs) || !logs.length) {
      $rows.append($('<p class="text-muted" id="ucm-operation-log-empty">').text('No operations logged for this site yet.'));
      return;
    }
    logs.forEach(function (log, index) {
      log = log || {};
      var id = operationLogId(log, index);
      var created = String(log.created_label || log.created || '');
      var operation = String(log.operation || '');
      var message = String(log.message || '');
      var detail = $.trim(log.detail == null ? '' : String(log.detail));
      if (!detail) { detail = 'No additional output was recorded for this operation.'; }
      var $row = $('<details>').attr('data-log-id', id).prop('open', opened[id] === true);
      var $summary = $('<summary>').append(
        $('<code>').text(created),
        ' ',
        $('<strong>').text(operation),
        ' — ',
        $('<span>').text(message)
      );
      $row.append($summary, $('<pre class="ucm-operation-detail">').text(detail));
      $rows.append($row);
    });
  }

  function operationLogRefresh(force) {
    var $panel = $('#ucm-operation-log-panel'), siteId = Number($panel.data('site-id') || 0);
    if (!$panel.length || !siteId) { return null; }
    if (siteOperationLogRequest) {
      if (force) { siteOperationLogRefreshPending = true; }
      return siteOperationLogRequest;
    }
    var $button = $('#ucm-operation-log-refresh');
    if (force) { $button.prop('disabled', true); }
    operationLogStatus('Updating…', false);
    try {
      siteOperationLogRequest = window.ucmpAjax({data: {command: 'operationlogs', id: siteId}});
    } catch (ignored) {
      siteOperationLogRequest = null;
      if (force) { $button.prop('disabled', false); }
      operationLogStatus('Update failed: the operation log request could not be started.', true);
      return null;
    }
    if (!siteOperationLogRequest || typeof siteOperationLogRequest.done !== 'function' || typeof siteOperationLogRequest.fail !== 'function' || typeof siteOperationLogRequest.always !== 'function') {
      siteOperationLogRequest = null;
      if (force) { $button.prop('disabled', false); }
      operationLogStatus('Update failed: the operation log request could not be started.', true);
      return null;
    }
    siteOperationLogRequest.done(function (response) {
      var result = response && response.result || {};
      if (!response || response.status !== true || result.ok !== true || !Array.isArray(result.logs)) {
        operationLogStatus('Update failed: ' + String(result.message || (response && response.message) || 'The operation log could not be refreshed.'), true);
        return;
      }
      renderOperationLogs(result.logs);
      var collected = Number(result.collected_at || 0);
      operationLogStatus('Last updated: ' + (collected ? new Date(collected * 1000).toLocaleString() : 'just now'), false);
    }).fail(function (xhr) {
      var body = xhr && xhr.responseJSON || {}, result = body.result || {};
      operationLogStatus('Update failed: ' + String(body.message || result.message || 'The operation log could not be refreshed.'), true);
    }).always(function () {
      siteOperationLogRequest = null;
      if (force) { $button.prop('disabled', false); }
      if (siteOperationLogRefreshPending) {
        siteOperationLogRefreshPending = false;
        operationLogRefresh(true);
      }
    });
    return siteOperationLogRequest;
  }

  function initOperationLogRefresh() {
    if (!$('#ucm-operation-log-panel').length || siteOperationLogTimer) { return; }
    $(document).on('click', '#ucm-operation-log-refresh', function () { operationLogRefresh(true); });
    $(document).on('visibilitychange', function () {
      if (!document.hidden) { operationLogRefresh(true); }
    });
    siteOperationLogTimer = window.setInterval(function () {
      if (!document.hidden) { operationLogRefresh(false); }
    }, 5000);
  }
  $(initOperationLogRefresh);

  function renderModuleVersion(result) {
    if (!result || (!Object.prototype.hasOwnProperty.call(result, 'comparison') && !result.local_version)) { return; }
    var labels = {same:'Same version', remote_older:'Update available', remote_newer:'Remote is newer — downgrade blocked', missing:'Not installed', unknown:'Unknown'};
    $('#ucm-version-controller').text(result.local_version || result.required_version || 'Unknown');
    $('#ucm-version-remote').text(result.remote_version || (result.comparison === 'missing' ? 'Not installed' : 'Unknown'));
    $('#ucm-version-comparison').text(labels[result.comparison] || result.status || 'Unknown');
  }

  function bootstrapRequest(params, $button, onSuccess, onComplete) {
    var completed = false;
    function complete() {
      if (completed) { return; }
      completed = true;
      $button.prop('disabled', false);
      $button.removeData('site-draft');
      if (typeof onComplete === 'function') {
        try { onComplete(); } catch (ignored) { /* cleanup callbacks must not strand the button */ }
      }
      operationLogRefresh(true);
    }
    var draft = $button.data('site-draft') || siteConnectionDraft();
    if (JSON.stringify(draft) !== JSON.stringify(siteConnectionDraft())) {
      bootstrapFeedback($button, 'Connection fields changed during confirmation; review them and click the action again. No credentials were sent.', 'warning');
      complete();
      return null;
    }
    params.site_draft = JSON.stringify(draft);
    params.expected_revision = $('[name="expected_revision"]').val() || '';
    if (params.command === 'bootstrapsite') { params.host = draft.docker_host; params.port = draft.docker_ssh_port; }
    if (params.command === 'bootstrappbx' || params.command === 'remoteinstall') { params.host = draft.pbx_host; params.port = draft.pbx_ssh_port; }
    if (draft.pbx_api_host) {
      params.api_host = draft.pbx_api_host; params.api_scheme = draft.pbx_api_scheme;
      params.api_port = draft.pbx_api_port; params.api_verify_tls = draft.pbx_api_verify_tls;
    }
    $button.prop('disabled', true);
    bootstrapFeedback($button, 'Connecting…', 'busy');
    var request;
    try {
      request = window.ucmpAjax({ data: params });
    } catch (ignored) {
      bootstrapFeedback($button, 'The request could not be started.', 'bad');
      complete();
      return null;
    }
    if (!request || typeof request.done !== 'function' || typeof request.fail !== 'function' || typeof request.always !== 'function') {
      bootstrapFeedback($button, 'The request could not be started.', 'bad');
      complete();
      return request || null;
    }
    request
      .done(function (resp) {
        var r = (resp && resp.result) || {};
        if (r.site_editor_revision) { $('[name="expected_revision"]').val(r.site_editor_revision); }
        var ok = !!(resp && resp.status && r.ok !== false);
        var message = r.message || (resp && resp.message) || (ok ? 'Operation complete.' : 'Operation failed.');
        var partial = ok && (params.command === 'bootstrappbx' || params.command === 'remoteinstall')
          && (r.ready === false || ['partial', 'installed_but_not_ready'].indexOf(String(r.status || '').toLowerCase()) !== -1);
        bootstrapFeedback($button, message, ok ? (partial ? 'warning' : 'ok') : 'bad', r.detail);
        if (JSON.stringify(draft) !== JSON.stringify(siteConnectionDraft())) {
          $('#ucm-bootstrap-result').append($('<p>').text('The result belongs to the connection values captured when the action started. Your newer edits have not been applied.'));
          return;
        }
        renderModuleVersion(r);
        var callbackAllowed = ok || ((params.command === 'bootstrappbx' || params.command === 'remoteinstall') && bootstrapAgentResultKnown(r));
        if (!callbackAllowed) { return; }
        var callbackFailed = false;
        if (typeof onSuccess === 'function') {
          try {
            onSuccess(r);
          } catch (ignored) {
            callbackFailed = true;
            bootstrapFeedback($button, 'The operation completed, but the page could not update.', 'bad');
          }
        }
        if (!callbackFailed && params.command !== 'remoteversion') {
          $('#ucm-bootstrap-result').append($('<p class="text-muted">').text('Connection fields are saved when this action starts, even if remote setup later fails. Other site edits still require Save.'));
        }
      })
      .fail(function (xhr) {
        var body = xhr && xhr.responseJSON || {}, result = body.result || {};
        if (result.site_editor_revision) { $('[name="expected_revision"]').val(result.site_editor_revision); }
        var message = body.message || result.message || 'The request could not be completed. See Recent operation log for the recorded stage.';
        bootstrapFeedback($button, message, 'bad', body.detail || result.detail);
      })
      .always(complete);
    return request;
  }

  function renderModuleSigning(result) {
    var signing = (result && (result.signing || result.module_signing
      || (result.remote && (result.remote.signing || result.remote.module_signing)))) || result || {};
    if (signing === true) { signing = {state:'locally_signed', trusted:true, label:'Locally signed and verified'}; }
    if (!signing || typeof signing !== 'object') { return; }
    if (!signing.state && result && result.signed === true) {
      signing = { state: 'locally_signed', trusted: true, label: 'Locally signed', message: 'The target PBX signed and verified the installed module.' };
    }
    var state = String(signing.state || '').toLowerCase();
    var trusted = signing.trusted === true || ['trusted', 'valid', 'good', 'locally_signed'].indexOf(state) !== -1;
    var bad = ['tampered', 'invalid', 'revoked', 'expired', 'error'].indexOf(state) !== -1;
    var label = signing.label || (state === 'locally_signed' ? 'Locally signed' : signing.state) || (trusted ? 'Locally signed' : 'Not checked');
    var detail = signing.message || signing.detail || signing.details || '';
    if ($.isArray(detail)) { detail = detail.join('; '); }
    else if (detail && typeof detail !== 'string') { detail = JSON.stringify(detail); }
    $('#ucm-module-sign-state')
      .removeClass('label-default label-warning label-danger label-success')
      .addClass(trusted ? 'label-success' : (bad ? 'label-danger' : (state && state !== 'unknown' ? 'label-warning' : 'label-default')))
      .text(String(label));
    if (detail) { $('#ucm-module-sign-detail').text(String(detail)); }
  }

  function clearPbxAdminPassword() {
    $('#ucm-pbx-admin-pass').val('');
  }

  function applyPbxBootstrapResult(result) {
    result = result || {};
    var components = result.components || {};
    var hasAgentComponent = Object.prototype.hasOwnProperty.call(components, 'agent');
    var agent = result.agent || {};
    var agentComponent = hasAgentComponent ? components.agent : agent;
    var api = Object.prototype.hasOwnProperty.call(components, 'api') ? components.api : (result.api || {});
    var agentReady = bootstrapComponentReady(agentComponent);
    var agentKnown = hasAgentComponent || !!result.agent;
    var apiKnown = api && typeof api === 'object' && Object.keys(api).length > 0;
    if (result.host_key || agent.host_key) { $('#ucm-pbx-host-key').val(result.host_key || agent.host_key); }
    var trunks = result.trunks || agent.trunks || [];
    if (Array.isArray(trunks)) {
      var $select = $('#ucm-remote-trunk-endpoint');
      var selected = $select.val() || $select.data('selected') || '';
      $select.empty().append($('<option>').val('').text('-- select the return trunk --'));
      $select.val('');
      trunks.forEach(function (trunk) {
        var endpoint = String(trunk.endpoint || '');
        if (endpoint) { $select.append($('<option>').val(endpoint).text(String(trunk.label || endpoint) + ' — ' + endpoint)); }
      });
      if (selected && trunks.some(function (trunk) { return String(trunk.endpoint || '') === selected; })) { $select.val(selected); }
    }
    if (agentReady) {
      $('#ucm-pbx-agent-state').removeClass('label-warning label-default').addClass('label-success').text('enrolled (last verified)');
      $('#ucm-remote-trunk-endpoint').prop('disabled', false);
      $('#ucm-remote-trunk-shadow').remove();
      $('#ucm-docker-bootstrap, #ucm-pbx-tftp-bootstrap').prop('disabled', false);
    } else if (agentKnown) {
      $('#ucm-pbx-agent-state').removeClass('label-success label-default').addClass('label-warning').text('enrollment / repair needed');
      $('#ucm-docker-bootstrap, #ucm-pbx-tftp-bootstrap, #ucm-remote-trunk-endpoint').prop('disabled', true);
    }
    if (apiKnown) {
      var apiReady = bootstrapComponentReady(api);
      $('#ucm-api-state').removeClass('label-default label-danger label-success').addClass(apiReady ? 'label-success' : 'label-default').text(apiReady ? 'enrolled' : 'not enrolled');
    }
  }

  function withConfirmedHostKey(channel, $button, callback, onCancel) {
    var selector = channel === 'docker' ? '#ucm-docker-host-key' : '#ucm-pbx-host-key';
    var draft = siteConnectionDraft(), host = channel === 'docker' ? draft.docker_host : draft.pbx_host;
    var port = channel === 'docker' ? draft.docker_ssh_port : draft.pbx_ssh_port;
    $button.data('site-draft', draft);
    var pinned = $.trim($(selector).val() || '');
    if (String($(selector).data('host') || '') !== host || String($(selector).data('port') || '') !== String(port)) { pinned = ''; }
    function cancel(message, state, detail) {
      bootstrapFeedback($button, message, state || 'bad', detail);
      $button.removeData('site-draft');
      if (typeof onCancel === 'function') {
        try { onCancel(); } catch (ignored) { /* credential cleanup must not mask the failure */ }
      }
      $button.prop('disabled', false);
    }
    function invoke(fingerprint) {
      var request;
      try {
        request = callback(fingerprint);
      } catch (ignored) {
        cancel('The operation could not be started; no credentials were sent.', 'bad');
        return null;
      }
      /* A returned jqXHR means bootstrapRequest owns the button until its own always(). */
      if (!request || typeof request.always !== 'function') { $button.prop('disabled', false); }
      return request;
    }
    if (pinned) {
      bootstrapFeedback($button, 'Using the saved SSH host key…', 'busy');
      return invoke(pinned);
    }

    $button.prop('disabled', true);
    bootstrapFeedback($button, 'Retrieving the SSH host fingerprint…', 'busy');
    var ownershipTransferred = false;
    var fingerprintRequest;
    try {
      fingerprintRequest = window.ucmpAjax({
        data: {
          command: 'sshfingerprint',
          id: $button.data('id'),
          channel: channel,
          site_draft: JSON.stringify(draft)
        }
      });
    } catch (ignored) {
      cancel('Could not obtain the SSH host fingerprint; no credentials were sent.', 'bad');
      operationLogRefresh(true);
      return null;
    }
    if (!fingerprintRequest || typeof fingerprintRequest.done !== 'function' || typeof fingerprintRequest.fail !== 'function' || typeof fingerprintRequest.always !== 'function') {
      cancel('Could not obtain the SSH host fingerprint; no credentials were sent.', 'bad');
      operationLogRefresh(true);
      return fingerprintRequest || null;
    }
    fingerprintRequest.done(function (response) {
      var result = (response && response.result) || {};
      var fingerprint = $.trim(result.fingerprint || '');
      if (!response || response.status !== true || result.ok !== true || !fingerprint) {
        cancel(result.message || (response && response.message) || 'Could not obtain the SSH host fingerprint.', 'bad', result.detail || (response && response.detail));
        return;
      }
      var target = String(result.host || '') + ':' + String(result.port || '');
      var message = 'First connection to ' + target + '.\n\nSSH host key:\n' + fingerprint +
        '\n\nVerify this fingerprint through an independent channel. Continue and pin it?';
      bootstrapFeedback($button, 'SSH host fingerprint retrieved; awaiting confirmation…', 'busy');
      var confirmed;
      try {
        confirmed = window.confirm(message);
      } catch (ignored) {
        cancel('Enrollment cancelled; no credentials were sent.', 'warning');
        return;
      }
      if (!confirmed) {
        cancel('Enrollment cancelled; no credentials were sent.', 'warning');
        return;
      }
      $(selector).val(fingerprint).data('host', String(result.host || '')).data('port', String(result.port || ''));
      var request = invoke(fingerprint);
      ownershipTransferred = !!(request && typeof request.always === 'function');
    }).fail(function (xhr) {
      var body = xhr && xhr.responseJSON || {}, result = body.result || {};
      cancel(body.message || result.message || 'Could not obtain the SSH host fingerprint; no credentials were sent.', 'bad', body.detail || result.detail);
    }).always(function () {
      operationLogRefresh(true);
      if (!ownershipTransferred) { $button.prop('disabled', false); }
    });
    return fingerprintRequest;
  }

  $(document).on('change', '#ucm-api-scheme', function () {
    var $port = $('#ucm-api-port'), current = String($port.val() || '');
    if (current === '' || current === '80' || current === '443') {
      $port.val($(this).val() === 'https' ? '443' : '80');
    }
  });

  $(document).on('click', '#ucm-docker-bootstrap', function () {
    var $button = $(this);
    withConfirmedHostKey('docker', $button, function (fingerprint) {
    return bootstrapRequest({
      command: 'bootstrapsite', id: $button.data('id'), host: $('#ucm-docker-host').val(), port: $('#ucm-docker-port').val(),
      root_path: $('#ucm-tftp-bind').val(), admin_user: $('#ucm-docker-admin-user').val(), admin_pass: $('#ucm-docker-admin-pass').val(),
      use_sudo: $('#ucm-docker-admin-sudo').is(':checked') ? '1' : '0', expected_host_key: fingerprint
    }, $button, function (result) {
      if (result.host_key) { $('#ucm-docker-host-key').val(result.host_key); }
      $('#ucm-docker-action-feedback').removeClass('text-danger').addClass('text-success').text('Existing TFTP container access is enrolled and verified.');
    }, function () { $('#ucm-docker-admin-pass').val(''); });
    }, function () { $('#ucm-docker-admin-pass').val(''); });
  });

  function syncTftpArchitectureUi() {
    var $root = $('.ucm-tftp-architecture');
    if (!$root.length) { return; }
    ++cutoverPreviewGeneration;
    var active = String($root.data('active-mode') || 'docker');
    var savedSelection = String($root.data('selected-mode') || active);
    var selected = String($root.find('input[name="tftp_mode"]:checked').val() || active);
    var staged = selected !== active && selected === savedSelection;
    $root.find('[data-tftp-panel]').prop('hidden', true).filter('[data-tftp-panel="' + selected + '"]').prop('hidden', false);
    $root.find('.ucm-tftp-mode-card').each(function () {
      var mode = String($(this).find('input').val() || '');
      var $badge = $(this).find('.ucm-tftp-mode-badge').removeClass('label-success label-warning label-default');
      if (mode === active) { $badge.addClass('label-success').text('Current'); }
      else if (mode === selected) { $badge.addClass('label-warning').text(staged ? 'Staged' : 'Save to stage'); }
      else { $badge.addClass('label-default').text('Available'); }
    });
    $('#ucm-tftp-selection-badge')
      .toggleClass('label-success', selected === active)
      .toggleClass('label-warning', selected !== active)
      .text(selected === active ? active.toUpperCase() + ' active' : selected.toUpperCase() + (staged ? ' proposed' : ' unsaved'));
    $('#ucm-tftp-cutover').prop('hidden', selected === active);
    $('#ucm-tftp-cutover-preview').prop('disabled', !staged);
    $('#ucm-tftp-cutover-apply').prop('disabled', true).removeData('plan-hash');
    $('#ucm-tftp-cutover-state').attr('class', 'label label-warning').text(staged ? 'Preview required' : 'Save site first');
    $('#ucm-tftp-transition-notice')
      .attr('class', 'alert ' + (selected === active ? 'alert-info' : 'alert-warning'))
      .text(selected === active
        ? 'No architecture change is staged. Selecting another provider and saving this site does not switch the live tree.'
        : (staged
          ? 'Mode change staged. ' + active.toUpperCase() + ' remains live until the target is bootstrapped, copied, SHA-256 verified, and explicitly activated.'
          : 'Save this site to stage the proposed architecture. The live ' + active.toUpperCase() + ' tree will not change.'));
  }

  $(document).on('change', '.ucm-tftp-architecture input[name="tftp_mode"]', syncTftpArchitectureUi);
  syncTftpArchitectureUi();

  function renderPbxTftpInspection(result) {
    var ready = !!result.ok;
    $('#ucm-pbx-tftp-state').attr('class', 'label ' + (ready ? 'label-success' : 'label-warning')).text(ready ? 'Ready' : 'Needs attention');
    $('#ucm-pbx-tftp-daemon').text(String(result.service_state || 'unknown') + (result.enabled_state ? ' · ' + result.enabled_state : ''));
    $('#ucm-pbx-tftp-root').text(result.root_exists ? String(result.root || '/srv/tftp/tftpboot') + (result.root_owner ? ' · ' + result.root_owner : '') : 'Missing');
    $('#ucm-pbx-tftp-port').text(String(result.listener || 'no listener'));
    $('#ucm-pbx-tftp-networks').text(String(result.voice_subnets || 'Not declared; no firewall rules changed'));
    $('#ucm-pbx-tftp-feedback').removeClass('text-danger text-success').addClass(ready ? 'text-success' : 'text-danger').text(result.message || 'Inspection complete.');
  }

  $(document).on('click', '#ucm-pbx-tftp-inspect', function () {
    var $button = $(this), siteId = Number($button.data('id') || 0);
    if (!siteId) { return; }
    $button.prop('disabled', true);
    $('#ucm-pbx-tftp-feedback').removeClass('text-danger text-success').text('Inspecting daemon, root, UDP/69 and SFTP state…');
    window.ucmpAjax({data:{command:'tftparchitecture', op:'inspect', site_id:siteId}}).done(function (response) {
      renderPbxTftpInspection(response && response.result || {});
    }).fail(function (xhr) {
      $('#ucm-pbx-tftp-feedback').addClass('text-danger').text(xhr.responseJSON && xhr.responseJSON.message || 'PBX TFTP inspection failed.');
    }).always(function () { $button.prop('disabled', false); });
  });

  $(document).on('click', '#ucm-pbx-tftp-plan', function () {
    var $plan = $('.ucm-tftp-bootstrap-plan');
    $plan.prop('hidden', !$plan.prop('hidden'));
    $(this).text($plan.prop('hidden') ? 'Preview bootstrap' : 'Hide bootstrap plan');
  });

  $(document).on('click', '#ucm-pbx-tftp-bootstrap', function () {
    var $button = $(this);
    withConfirmedHostKey('pbx', $button, function (fingerprint) {
      return bootstrapRequest({
        command:'bootstrappbxtftp', id:$button.data('id'),
        admin_user:$('#ucm-tftp-pbx-admin-user').val(), admin_pass:$('#ucm-tftp-pbx-admin-pass').val(),
        use_sudo:$('#ucm-tftp-pbx-admin-sudo').is(':checked') ? '1' : '0',
        install_missing:$('#ucm-pbx-tftp-install-policy').val(), expected_host_key:fingerprint
      }, $button, function (result) {
        if (result.host_key) { $('#ucm-pbx-host-key').val(result.host_key); }
        $('#ucm-pbx-tftp-state').attr('class','label label-success').text('Ready');
        $('#ucm-pbx-tftp-feedback').removeClass('text-danger').addClass('text-success').text(result.message || 'PBX-hosted TFTP is ready.');
        $('#ucm-pbx-tftp-inspect').trigger('click');
      }, function () { $('#ucm-tftp-pbx-admin-pass').val(''); });
    }, function () { $('#ucm-tftp-pbx-admin-pass').val(''); });
  });

  $(document).on('click', '#ucm-tftp-cutover-preview', function () {
    var $button = $(this), siteId = Number($button.data('id') || 0);
    if (!siteId || cutoverApplying) { return; }
    var requestGeneration = ++cutoverPreviewGeneration;
    $('#ucm-tftp-cutover-apply').prop('disabled', true).removeData('plan-hash');
    $button.prop('disabled', true);
    $('#ucm-tftp-cutover-feedback').removeClass('text-danger text-success').text('Inventorying both backends…');
    window.ucmpAjax({data:{command:'tftparchitecture', op:'cutover_preview', site_id:siteId}}).done(function (response) {
      if (requestGeneration !== cutoverPreviewGeneration) { return; }
      var result = response && response.result || {}, ok = !!(response && response.status && result.ok);
      $('#ucm-tftp-cutover-feedback').toggleClass('text-success', ok).toggleClass('text-danger', !ok).text(result.message || 'Cutover preview failed.');
      if (!ok) { return; }
      var extra = Number(result.target_only_count || 0);
      $('#ucm-tftp-cutover-summary').text(result.source_mode.toUpperCase() + ' → ' + result.target_mode.toUpperCase() + ' · ' + result.files + ' files · ' + result.bytes + ' bytes' + (extra ? ' · ' + extra + ' target-only entries retained' : '') + '.');
      $('#ucm-tftp-cutover-state').attr('class','label label-success').text('Ready to apply');
      $('#ucm-tftp-cutover-apply').data('plan-hash', result.plan_hash).prop('disabled', false);
    }).fail(function (xhr) {
      if (requestGeneration !== cutoverPreviewGeneration) { return; }
      $('#ucm-tftp-cutover-feedback').addClass('text-danger').text(xhr.responseJSON && xhr.responseJSON.message || 'Cutover preview failed.');
    }).always(function () { if (requestGeneration === cutoverPreviewGeneration) { $button.prop('disabled', false); } });
  });

  $(document).on('click', '#ucm-tftp-cutover-apply', function () {
    var $button = $(this), siteId = Number($button.data('id') || 0), planHash = String($button.data('plan-hash') || '');
    if (cutoverApplying || !siteId || !planHash || !window.confirm('Copy the previewed tree, verify every file with SHA-256, and activate the target TFTP architecture? The current source tree will be retained.')) { return; }
    cutoverApplying = true;
    ++cutoverPreviewGeneration;
    $button.prop('disabled', true).removeData('plan-hash');
    $('#ucm-tftp-cutover-feedback').removeClass('text-danger text-success').text('Copying and verifying the complete TFTP tree. Keep this page open…');
    window.ucmpAjax({data:{command:'tftparchitecture', op:'cutover_apply', site_id:siteId, plan_hash:planHash}}).done(function (response) {
      var result = response && response.result || {}, ok = !!(response && response.status && result.ok);
      $('#ucm-tftp-cutover-feedback').toggleClass('text-success', ok).toggleClass('text-danger', !ok).text(result.message || 'Cutover failed.');
      if (ok) { window.setTimeout(function () { window.location.reload(); }, 900); }
      else { $('#ucm-tftp-cutover-state').attr('class','label label-warning').text('Review result before a new preview'); }
    }).fail(function (xhr) {
      $('#ucm-tftp-cutover-feedback').addClass('text-danger').text(xhr.responseJSON && xhr.responseJSON.message || 'Cutover response lost; the active backend is unconfirmed. Reconcile the retained transaction before retrying.');
    }).always(function () { cutoverApplying = false; });
  });

  $(document).on('click', '.ucm-tftp-permissions', function () {
    var $button = $(this), op = String($button.data('op') || ''), siteId = Number($button.data('id') || 0);
    if (!siteId || (op === 'permission_repair' && !window.confirm('Repair phone-facing read/traverse permissions on this site? Ownership and private transaction data will not be changed.'))) { return; }
    var $feedback = $('#ucm-tftp-permission-feedback');
    $('.ucm-tftp-permissions').prop('disabled', true);
    $feedback.removeClass('text-danger text-success').text(op === 'permission_repair' ? 'Repairing published permissions…' : 'Checking published permissions…');
    window.ucmpAjax({data:{command:'tftp', op:op, site_id:siteId}}).done(function (response) {
      var result = response && response.result || {};
      var detail = result.message || (result.ok ? 'Permission check completed' : 'Permission check failed');
      if (result.issues && result.issues.length) {
        detail += ' — ' + result.issues.slice(0, 3).map(function (issue) { return issue.path + ': ' + issue.message; }).join('; ');
        if (result.issues.length > 3) { detail += '; +' + (result.issues.length - 3) + ' more'; }
      }
      $feedback.toggleClass('text-success', !!result.ok).toggleClass('text-danger', !result.ok).text(detail);
    }).fail(function () {
      $feedback.addClass('text-danger').text('TFTP permission request failed');
    }).always(function () {
      $('.ucm-tftp-permissions').prop('disabled', false);
    });
  });

  $(document).on('click', '#ucm-remote-version, #ucm-remote-install', function () {
    var $button = $(this), install = $button.attr('id') === 'ucm-remote-install';
    if (install && !window.confirm('Apply the displayed connection settings, install/update the module if needed, and prepare its SSH/API access? The signing checkbox is honored even when the version is current. A newer remote version will not be downgraded.')) {
      clearPbxAdminPassword();
      return;
    }
    withConfirmedHostKey('pbx', $button, function (fingerprint) {
    return bootstrapRequest({
      command: install ? 'remoteinstall' : 'remoteversion', id: $button.data('id'),
      admin_user: $('#ucm-pbx-admin-user').val(), admin_pass: $('#ucm-pbx-admin-pass').val(),
      use_sudo: $('#ucm-pbx-admin-sudo').is(':checked') ? '1' : '0',
      sign_after: install && $('#ucm-sign-after-install').is(':checked') ? '1' : '0',
      api_scheme: $('#ucm-api-scheme').val(), api_host: $('#ucm-api-host').val(),
      api_port: $('#ucm-api-port').val(), api_verify_tls: $('#ucm-api-verify').val(),
      expected_host_key: fingerprint
    }, $button, function (result) {
      if (install) { applyPbxBootstrapResult(result); }
      renderModuleSigning(result);
    }, clearPbxAdminPassword);
    }, clearPbxAdminPassword);
  });

  $(document).on('click', '#ucm-module-sign', function () {
    var $button = $(this);
    withConfirmedHostKey('pbx', $button, function (fingerprint) {
    return bootstrapRequest({
      command: 'signmodule', id: $button.data('id'),
      admin_user: $('#ucm-pbx-admin-user').val(), admin_pass: $('#ucm-pbx-admin-pass').val(),
      use_sudo: $('#ucm-pbx-admin-sudo').is(':checked') ? '1' : '0',
      expected_host_key: fingerprint
    }, $button, renderModuleSigning, clearPbxAdminPassword);
    }, clearPbxAdminPassword);
  });


  $(document).on('click', '.ucm-refresh-trunks', function () {
    var $button = $(this), $select = $('#ucm-trunk-endpoint');
    $button.prop('disabled', true).text('Refreshing...');
    window.ucmpAjax({ data: { command: 'listtrunks', site_id: $button.data('site') } })
      .done(function (resp) {
        var r = (resp && resp.result) || {}, trunks = r.trunks || [];
        if (!(resp && resp.status && r.ok === true && Array.isArray(r.trunks))) {
          $button.attr('title', r.message || (resp && resp.message) || 'Trunk refresh failed; previous selection retained');
          return;
        }
        var selected = $select.val() || $select.data('selected') || '';
        $select.empty().append($('<option>').val('').text('-- select an existing trunk --'));
        trunks.forEach(function (trunk) {
          var endpoint = String(trunk.endpoint || '');
          if (endpoint) { $select.append($('<option>').val(endpoint).text(String(trunk.label || endpoint) + ' — ' + endpoint)); }
        });
        $select.val(selected);
        $button.attr('title', r.message || 'Trunks refreshed');
      })
      .fail(function () { $button.attr('title', 'Trunk refresh failed; previous selection retained'); })
      .always(function () { $button.prop('disabled', false).text('Refresh from PBX'); });
  });

  $(document).on('change', '#ucm-check-all', function () {
    $('.ucm-row').prop('checked', $(this).prop('checked'));
    updateSelectionActions();
  });

  $(document).on('change', '.ucm-row', updateSelectionActions);

  function renderMeshPlan(r) {
    var lines = [];
    Object.keys(r.participants || {}).forEach(function (id) {
      var plan = r.participants[id] || {};
      var repairs = plan.repairs || [], promotions = plan.promotions || [], creations = plan.creations || [];
      lines.push('=== ' + id + ' ===');
      lines.push(plan.message || (plan.ok ? 'Ready.' : 'Requires attention.'));
      lines.push('Peers: ' + ((plan.peers || []).join(', ') || '(none)'));
      lines.push('Changed: ' + (plan.changed ? 'yes' : 'no') + '; repairs: ' + repairs.length + '; promotions: ' + promotions.length + '; creations: ' + creations.length);
      repairs.forEach(function (repair) {
        lines.push('--- ' + repair.name + ' (' + repair.type + ', peer: ' + repair.peer + ') ---');
        lines.push('OLD:\n' + repair.old);
        lines.push('NEW:\n' + repair['new']);
      });
      creations.forEach(function (section) { lines.push(section); });
      (plan.warnings || []).forEach(function (warning) { lines.push('- ' + warning); });
      lines.push('');
    });
    return lines.join('\n');
  }

  $(document).on('click', '.ucm-meshsync', function (e) {
    e.preventDefault();
    if (meshApplying) { return; }
    meshApplying = true;
    var $button = $(this).prop('disabled', true), commitPending = false;
    var $result = $('#ucm-action-result');
    $result.removeClass('text-danger text-success').text('Working...');
    window.ucmpAjax({ data: { command: 'meshsync', apply: '0' } })
      .done(function (resp) {
        var r = (resp && resp.result) || {};
        if (resp && resp.status && r.ok) {
          $result.addClass('text-success').text(r.message || 'Plan computed.');
          $('#ucm-detail-result').show().text(renderMeshPlan(r));
          var hasWork = Object.keys(r.participants || {}).some(function (id) { return !!(r.participants[id] || {}).changed; });
          if (hasWork && window.confirm('Apply these BLF mesh repairs now? This writes pjsip_custom.conf and reloads.')) {
            commitPending = true;
            window.ucmpAjax({ data: { command: 'meshsync', apply: '1', plan_hash: r.plan_hash || '' } }).done(function (resp2) {
              var r2 = (resp2 && resp2.result) || {};
              var ok = !!(resp2 && resp2.status && r2.ok === true);
              $result.toggleClass('text-success', ok).toggleClass('text-danger', !ok).text(r2.message || (ok ? 'Applied.' : 'Mesh apply failed.'));
              $('#ucm-detail-result').show().text(JSON.stringify(r2, null, 2));
            }).fail(function () {
              $result.removeClass('text-success').addClass('text-danger').text('Mesh apply response lost. Reconcile the retained transaction before retrying; participant completion is unconfirmed.');
            }).always(function () { meshApplying = false; $button.prop('disabled', false); });
          }
        } else {
          $result.addClass('text-danger').text((r && r.message) || (resp && resp.message) || 'Request failed');
        }
      })
      .fail(function () { $result.addClass('text-danger').text('Request failed'); })
      .always(function () { if (!commitPending) { meshApplying = false; $button.prop('disabled', false); } });
  });

  $(document).on('click', '.ucm-preview', function (e) {
    e.preventDefault();
    var id = $(this).data('id');
    var siteId = $(this).data('site') || '';
    window.ucmpAjax({ data: { command: 'preview', id: id, site_id: siteId } })
      .done(function (resp) {
        var r = (resp && resp.result) || {};
        var body = r.content || r.xml || '(no content — generator may not be implemented for this family)';
        if (r.warnings && r.warnings.length) {
          body = '/* warnings: ' + r.warnings.join('; ') + ' */\n\n' + body;
        }
        if (r.template_trace && r.template_trace.length) {
          var path = (r.template_group_path || []).map(function (group) { return group.name || ('Group ' + group.id); }).join(' > ');
          var trace = r.template_trace.map(function (row) {
            return '[' + String(row.status || 'unknown').toUpperCase() + '] '
              + (row.group_name || ('Group ' + row.group_id)) + ' / '
              + (row.template_name || ('Template ' + row.template_id))
              + ' — ' + (row.reason || '');
          });
          body = '/* effective template path: ' + path + '\n'
            + trace.join('\n') + ' */\n\n' + body;
        }
        $('#ucm-preview-body').text(body);
        $('#ucm-preview-modal').modal('show');
      })
      .fail(function () {
        $('#ucm-preview-body').text('Request failed');
        $('#ucm-preview-modal').modal('show');
      });
  });

})(jQuery);
