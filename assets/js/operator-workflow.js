/* Fixed-target operator reviews; all presentation uses text nodes. */
(function ($) {
  'use strict';
  if (window.ucmpOperatorWorkflowsLoaded) return;
  window.ucmpOperatorWorkflowsLoaded = true;
  var generation = 0;
  function request(payload) {
    return window.ucmpAjax({data:$.extend({command:'operatorworkflow'},payload)}).then(function (response) {
      var result = response && response.result || {};
      if (!result.ok) return $.Deferred().reject($.extend({ok:false,message:'Operation did not complete'},result)).promise();
      return result;
    },function () {
      return $.Deferred().reject({ok:false,state:'request_failed',message:'The request failed or its response was lost. For an apply, read retained change status before retrying.'}).promise();
    });
  }
  function dialog(title) {
    var token = ++generation;
    $('#ucm-operator-modal').remove();
    var modal = $('<div id="ucm-operator-modal" class="modal fade ucmp-operator-modal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="ucm-operator-title">'), body = $('<div class="modal-body ucm-card-body">');
    modal.append($('<div class="modal-dialog modal-lg" role="document">').append($('<div class="modal-content">').append(
      $('<div class="modal-header">').append($('<button type="button" class="close" data-dismiss="modal" aria-label="Close">').text('×'),$('<h4 id="ucm-operator-title">').text(title)),body)));
    $('body').append(modal);modal.modal('show');
    return {body:body,current:function () { return token === generation && $.contains(document,modal[0]); }};
  }
  function displayKey(key) {
    return String(key || '').replace(/_/g,' ').replace(/\b\w/g,function (letter) { return letter.toUpperCase(); });
  }
  function safeKey(key) {
    return !/(?:password|secret|token|private_key|credential|(?:^|_)(?:input|.*hash)(?:_|$))/i.test(String(key || ''));
  }
  function renderValue(value, key, depth, budget) {
    if (!safeKey(key)) return null;
    budget=budget || {remaining:200};
    if(budget.remaining--<=0)return null;
    if (value === null || value === undefined || value === '') return $('<span class="text-muted">').text('Not recorded');
    if (typeof value === 'boolean') return $('<span>').text(value ? 'Yes' : 'No');
    if (typeof value !== 'object') {
      if (/^(accepted_at|identity_accepted_at|last_success|last_attempt|created|updated|completed_at)$/.test(key) && Number(value)>1000000000) {
        var date=new Date(Number(value)*1000);
        if (!isNaN(date.getTime())) return $('<time>').attr('datetime',date.toISOString()).text(date.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}));
      }
      return $('<span>').text(String(value).slice(0,1200));
    }
    if (depth > 3) return $('<span class="text-muted">').text('Details available in the retained record');
    var list=$('<dl class="ucm-detail-grid ucm-operator-details">');
    var keys=Object.keys(value).filter(safeKey);
    keys.slice(0,30).forEach(function (child) {
      if (!safeKey(child)) return;
      var item=renderValue(value[child],child,depth+1,budget);if (!item) return;
      list.append($('<dt>').text(Array.isArray(value)?'Item '+(Number(child)+1):displayKey(child)),$('<dd>').append(item));
    });
    if(keys.length>30 || budget.remaining<=0)list.append($('<dt>').text('More'),$('<dd>').text('Additional details are available in the retained record'));
    return list.children().length ? list : $('<span class="text-muted">').text('No details');
  }
  function output(view, value) {
    if (!view.current()) return;
    view.body.find('.ucm-operator-output').remove();
    var box=$('<div class="ucm-operator-output ucm-card">');
    if (typeof value === 'string') { box.append($('<p class="alert alert-warning">').text(value.slice(0,1200)));view.body.append(box);return; }
    value=value || {};
    if(value.statusText && !value.message)value={ok:false,message:'The request failed or its response was lost. Read retained change status before repeating an apply.'};
    if (value.message) box.append($('<p class="alert '+(value.ok===false?'alert-warning':'alert-info')+'">').text(String(value.message).slice(0,1200)));
    if(value.state)box.append($('<p class="text-muted">').text('State: '+displayKey(value.state)));
    if (value.state==='no_eligible_relationships' || value.state==='selection_required' || value.eligible_count!==undefined) {
      var summary=$('<div class="ucm-operator-summary">').append($('<strong>').text('Identity refresh summary'));
      if (value.eligible_count!==undefined) summary.append($('<span>').text('Eligible: '+Number(value.eligible_count)));
      if (value.skipped_count!==undefined) summary.append($('<span>').text('Skipped: '+Number(value.skipped_count)));
      box.append(summary);
      var reasons=value.skipped_reasons||{};
      if (Object.keys(reasons).length) { var reasonList=$('<ul class="list-unstyled">');Object.keys(reasons).forEach(function(reason){reasonList.append($('<li>').text(Number(reasons[reason])+' — '+reason));});box.append($('<h5>').text('Why rows were skipped'),reasonList); }
    }
    if (value.review) box.append($('<h4>').text('Review details'),renderValue(value.review,'review',0));
    var details={};Object.keys(value).forEach(function(key){if(['ok','message','review','state','eligible_count','skipped_count','skipped_reasons'].indexOf(key)===-1&&safeKey(key))details[key]=value[key];});
    if (Object.keys(details).length) box.append($('<details class="ucm-advanced">').append($('<summary>').text('Advanced details'),renderValue(details,'details',0)));
    if (!box.children().length) box.append($('<p class="text-muted">').text('No additional details were returned.'));
    view.body.append(box);
  }
  function review(view, payload, executeOp) {
    output(view,'Preparing an owner-scoped review…');
    request(payload).done(function (plan) {
      if (!view.current()) return;
      output(view,plan);
      view.body.find('.ucm-impact-next').remove();
      if(payload.op==='impact' && plan.pagination && plan.pagination.next_after_phone_id!==null){var next=$.extend({},payload,{after_phone_id:plan.pagination.next_after_phone_id,inventory_hash:plan.pagination.inventory_hash});view.body.append($('<button type="button" class="btn btn-info ucm-impact-next">').text('Compare next owner-site page').on('click',function(){$(this).prop('disabled',true);review(view,next);}));}
      view.body.find('.ucm-operator-approve').remove();
      if (!executeOp || !plan.plan_id) return;
      view.body.append($('<button type="button" class="btn btn-default">').text('Read retained change status').on('click',function(){request({op:'change_status',site_id:plan.site_id,plan_id:plan.plan_id,authority:['identity','identity_refresh'].indexOf(plan.kind)!==-1?'directory':'owner'}).done(function(result){output(view,result);}).fail(function(error){output(view,error);});}));
      var exact = {op:executeOp,site_id:plan.site_id,plan_id:plan.plan_id,plan_hash:plan.plan_hash};
      view.body.append($('<button type="button" class="btn btn-warning ucm-operator-approve">').text('Apply this exact review').on('click',function () {
        if (!window.confirm('Apply only the fixed targets and values shown in this review?')) return;
        var button = $(this).prop('disabled',true);
        request(exact).done(function (result) { output(view,result);if (view.current() && result.affected_phone_ids && result.affected_phone_ids.length) view.body.append($('<button type="button" class="btn btn-info">').text('Review rebuild of the reassigned phones').on('click',function () { review(view,{op:'rebuild_plan',site_id:plan.site_id,phone_ids:JSON.stringify(result.affected_phone_ids)},'rebuild_execute'); })); }).fail(function (error) { output(view,error); });
        button.text('Review submitted — create a fresh review to retry');
      }));
    }).fail(function (error) { output(view,error); });
  }
  function inspect(site,phone,op) {
    var view = dialog(op === 'explain' ? 'Explain phone — desired policy and separate evidence' : 'Deployment evidence');
    output(view,'Reading the physical owner…');
    request({op:op,site_id:site,phone_id:phone}).done(function (result) { output(view,result);if(!view.current())return;var seen={};Object.keys(result.desired&&result.desired.setting_trace||{}).forEach(function(key){result.desired.setting_trace[key].forEach(function(row){var tab={template:'templates',group:'groups',site:'settings',phone:'phones'}[row.source],id=Number(row.id),owner=Number(row.site_id||site);if(!tab||owner<1||(tab!=='settings'&&id<1))return;var href='config.php?display=ucmprovisioner&tab='+tab+'&view='+(tab==='templates'?'template':tab==='groups'?'group':'form')+'&site_id='+owner+(id?'&id='+id:'');if(seen[href])return;seen[href]=true;view.body.append($('<a class="btn btn-default">').attr('href',href).text('Open '+row.source+' policy '+(id||owner)));});}); }).fail(function (error) { output(view,error); });
  }
  function identity(site,extension) {
    var view=dialog('Person identity — caller name and phone label');
    request({op:'identity_editor',site_id:site,extension:extension}).done(function (state) {
      if (!view.current()) return;
      var resolved=state.resolved || {}, canonical=resolved.canonical || {}, mode=$('<select class="form-control">')
        .append($('<option value="ad_primary">').text('AD primary — existing number authority'),$('<option value="linked_additional">').text('Additional extension linked to a person'),$('<option value="manual_shared">').text('Manual / shared identity')).val(state.binding.mode);
      var full=$('<input class="form-control" maxlength="128">').val(canonical.display_name || ''),short=$('<input class="form-control" maxlength="128">').val(canonical.label || ''),search=$('<input class="form-control" placeholder="Search accepted people (2+ characters)">'),people=$('<select class="form-control">'),selected=null,searchSequence=0;
      view.body.append($('<p>').text('Extension '+extension+' · relationship revision '+state.revision+'. Full caller name is independent of the short on-phone label. Saving does not write AD or deploy phones.'),
        $('<label>').text('Identity relationship').append(mode),$('<label>').text('Full caller name (manual/shared)').append(full),$('<label>').text('Short phone name (manual/shared)').append(short),
        $('<label>').text('Accepted person (additional link)').append(search,people));
      search.on('input',function () {
        var sequence=++searchSequence,query=search.val();selected=null;
        request({op:'identity_people',search:query}).done(function (result) {
          if (!view.current() || sequence!==searchSequence) return;
          people.empty().append($('<option>').val('').text('Choose a stable person relationship'));
          (result.people || []).forEach(function (person,index) { people.append($('<option>').val(index).text((person.display_name || person.label || 'Accepted person')+' · '+(person.label || 'no short label')+' · accepted '+new Date(Number(person.last_success)*1000).toLocaleString()).data('person',person)); });
        });
      });
      people.on('change',function () { selected=people.find('option:selected').data('person') || null; });
      view.body.append($('<button type="button" class="btn btn-default">').text('Review relationship change').on('click',function () {
        var link=selected || (mode.val()==='linked_additional' ? state.binding : {});
        review(view,{op:'identity_preview',extension_id:state.extension_id,revision:state.revision,action:'binding',binding_mode:mode.val(),source_id:link.source_id || 0,object_key:link.object_key || '',manual_identity:JSON.stringify({display_name:full.val(),label:short.val()})},'identity_save');
      }));
      var adoption=$('<fieldset>').append($('<legend>').text('Adoption / final presentation review')),fields={display_name:'Full caller name',phone_label:'Phone label',line_label:'Account line label',blf_label:'Derived BLF label',directory_label:'Directory label'};
      Object.keys(fields).forEach(function (key) {
        var choice=$('<select class="form-control">').attr('data-adopt-mode',key).append($('<option value="">').text('Choose explicitly'),$('<option value="managed">').text('Use managed identity'),$('<option value="preserve">').text('Preserve the value I enter'));
        adoption.append($('<label>').text(fields[key]).append(choice,$('<input class="form-control">').attr('data-adopt-value',key).val((resolved.outputs || {})[key] || canonical[key] || '')));
      });
      adoption.append($('<p class="help-block">').text('Proposed values are not proof of the current physical display. Enter the exact value you intend to preserve; unknown device state remains unverified.'),$('<button type="button" class="btn btn-default">').text('Review every adoption decision').on('click',function () {
        var choices={};Object.keys(fields).forEach(function (key) { choices[key]={mode:adoption.find('[data-adopt-mode="'+key+'"]').val(),value:adoption.find('[data-adopt-value="'+key+'"]').val()}; });
        review(view,{op:'identity_preview',extension_id:state.extension_id,revision:state.revision,action:'adoption',choices:JSON.stringify(choices)},'identity_save');
      }));
      view.body.append(adoption,$('<button type="button" class="btn btn-info">').text('Review force refresh of this relationship').on('click',function () { review(view,{op:'identity_refresh_plan',scope:JSON.stringify({extension_ids:[state.extension_id]})},'identity_refresh_execute'); }));
      if (Number(state.binding.source_id)>0) view.body.append($('<button type="button" class="btn btn-info">').text('Review this person across all linked extensions').on('click',function () { review(view,{op:'identity_refresh_plan',scope:JSON.stringify({source_id:Number(state.binding.source_id),object_key:state.binding.object_key})},'identity_refresh_execute'); }));
      view.body.on('input change',':input',function () { view.body.find('.ucm-operator-approve').remove(); });
      output(view,{binding:state.binding,desired:resolved,existing_audited_overrides:state.overrides});
    }).fail(function (error) { output(view,error); });
  }
  $(function () {
    if ($('#ucm-site-filter').length && !$('#ucm-operator-identity-toolbar').length) {
      var toolbar=$('<details id="ucm-operator-identity-toolbar" class="ucm-advanced ucm-operator-toolbar">').append($('<summary>').text('Advanced identity actions'),$('<p class="help-block">').append(document.createTextNode('Reapply saved AD names and labels without contacting AD. For new directory data, use '),$('<a>').attr('href','config.php?display=ucmprovisioner&tab=directory').text('Directory & AD → Sync now'),document.createTextNode('. Manual overrides are preserved.')));
      toolbar.append($('<button type="button" class="btn btn-default">').text('Review selected primary relationships').on('click',function () {
        var targets=$('.ucm-row:checked').map(function(){var row=$(this).closest('tr');return {site_id:Number(row.data('site')),extension:String(row.data('ext') || '')};}).get();
        if (!targets.length) return window.alert('Select at least one relationship first.');
        review(dialog('Selected managed relationships'),{op:'identity_refresh_plan',scope:JSON.stringify({targets:targets})},'identity_refresh_execute');
      }),$('<button type="button" class="btn btn-default">').text('Review selected site').on('click',function () {
        var site=Number($('#ucm-site-filter').val());if(!site)return window.alert('Select one explicit site first.');
        review(dialog('Site managed identity refresh'),{op:'identity_refresh_plan',scope:JSON.stringify({site_id:site})},'identity_refresh_execute');
      }),$('<button type="button" class="btn btn-default">').text('Review one accepted source').on('click',function () {
        var view=dialog('Accepted source refresh'),sources=$('<select class="form-control">');view.body.append(sources);
        request({op:'identity_sources'}).done(function(result){if(!view.current())return;var rows=result.sources || [];if(!rows.length){output(view,{message:'No enabled directory authority has an accepted snapshot.',state:'no_eligible_relationships',eligible_count:0,skipped_count:0});return;}(rows).forEach(function(source){var label=(source.domain || source.source_key || 'Directory authority');sources.append($('<option>').val(source.id).text(label+' · '+(source.source_key || 'source')+' · accepted '+new Date(Number(source.last_success)*1000).toLocaleString()));});view.body.append($('<button type="button" class="btn btn-info">').text('Build source review').on('click',function(){review(view,{op:'identity_refresh_plan',scope:JSON.stringify({source_id:Number(sources.val())})},'identity_refresh_execute');}));}).fail(function(error){output(view,error);});
      }),$('<button type="button" class="btn btn-warning">').text('Review all managed identities').on('click',function () { review(dialog('Reapply saved AD names — all managed identities'),{op:'identity_refresh_plan',scope:JSON.stringify({all:true})},'identity_refresh_execute'); }));
      $('#ucm-site-filter').closest('form').after(toolbar);
    }
    $('.ucm-preview').each(function () {
      var original=$(this),row=original.closest('tr'),site=Number(row.data('site') || original.data('site')),phone=Number(original.data('id')),extension=String(row.data('ext') || '');
      var advanced=$('<details class="ucm-row-advanced">').append($('<summary>').text('More actions'));
      advanced.append($('<button type="button" class="btn btn-default">').text('Explain configuration').on('click',function () { inspect(site,phone,'explain'); }),
        $('<button type="button" class="btn btn-default">').text('Edit identity').on('click',function () { identity(site,extension); }),
        $('<button type="button" class="btn btn-default">').text('Review exact rebuild').on('click',function () { review(dialog('Review exact phone rebuild'),{op:'rebuild_plan',site_id:site,phone_ids:JSON.stringify([phone])},'rebuild_execute'); }));
      original.closest('td').append(advanced);
    });
    var form=$('form[name=frm_ucmprovisioner]').first(),params=new URLSearchParams(window.location.search),tab=params.get('tab');
    if(form.length){['owner','directory'].forEach(function(authority){form.prepend($('<button type="button" class="btn btn-default">').text('Recent '+authority+' changes').on('click',function(){var site=Number(form.find('[name=site_id]').first().val()||params.get('site_id')||params.get('site_filter'));review(dialog('Retained change history'),{op:'change_history',site_id:site,authority:authority});}));});}
    if (form.length && ['templates','groups','settings'].indexOf(tab)!==-1) {
      form.prepend($('<button type="button" class="btn btn-info">').text('Compare unsaved draft impact').on('click',function () {
        if(form.triggerHandler('ucmp:serialize-draft')===false)return;
        var values={},site=Number(form.find('[name=site_id]').first().val() || params.get('site_id')),kind=tab==='templates'?'template':(tab==='groups'?'group':'settings');
        form.serializeArray().forEach(function (field) { var setting=/^settings\[([^\]]+)\]$/.exec(field.name);if(kind==='settings'){if(setting)values[setting[1]]=field.value;else if(field.name==='default_template_rules')values.default_template_rules=field.value;}else if(['action','ucmp_csrf','view','id','site_id','site_filter'].indexOf(field.name)===-1)values[field.name]=field.value; });
        form.find('input[type=checkbox][name]:not(:checked)').each(function(){var name=this.name,setting=/^settings\[([^\]]+)\]$/.exec(name);if(kind==='settings'&&setting)values[setting[1]]='0';else if(kind!=='settings'&&name.indexOf('[')===-1)values[name]='0';});
        review(dialog('Unsaved draft impact — no save or deployment'),{op:'impact',site_id:site,kind:kind,id:Number(form.find('[name=id]').val() || 0),values:JSON.stringify(values)});
      }));
    }
    if (form.length && tab==='phones' && Number(form.find('[name=id]').val())>0) {
      form.prepend($('<button type="button" class="btn btn-default">').text('Review replacement / reassignment').on('click',function () {
        var selected=form.find('[name=extension] option:selected'),site=Number(form.find('[name=site_id]').first().val());
        review(dialog('Replacement guide — person versus physical phone'),{op:'replacement_preview',site_id:site,phone_id:Number(form.find('[name=id]').val()),other_phone_id:Number(selected.data('phone-id') || 0),extension:String(selected.val() || ''),collision_action:form.find('[name=collision_action]').val() || 'move'},'replacement_execute');
      }));
    }
  });
})(jQuery);
