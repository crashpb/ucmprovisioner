<?php
/** Groups view: named member sets with model/family-specific reusable templates. $ucm in scope. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$view = $_REQUEST['view'] ?? '';

$featureCatalog = [
    'blf'           => _('BLF speed dial'),
    'blfpark'       => _('BLF directed call park'),
    'speeddial'     => _('Speed dial'),
    'serviceurl'    => _('Service URL'),
];

if ($view === 'group') {
    $id = (int)($_REQUEST['id'] ?? 0);
    $requestedSiteId = (int)($_REQUEST['site_id'] ?? 0) ?: $ucm->localSiteId();
    if (!$ucm->getSite($requestedSiteId)) { throw new \InvalidArgumentException('Choose a valid group owner site'); }
    $grp = $id ? ($ucm->getGroup($id,$requestedSiteId) ?: []) : [];
    if ($id && !$grp) { throw new \RuntimeException('Group not found on the requested owner site'); }
    $f = fn($k, $d = '') => htmlspecialchars((string)($grp[$k] ?? $d));
    $extra = json_decode((string)($grp['extra_targets'] ?? '[]'), true) ?: [];
    $sites = $ucm->getSites();
    $grpSiteId = $requestedSiteId; // Remote numeric site IDs are not controller owner IDs.
    $templates = $ucm->getTemplates($grpSiteId, false);
    $templateMap = json_decode((string)($grp['template_map'] ?? '[]'), true) ?: [];
    $memberExceptions = json_decode((string)($grp['member_exceptions'] ?? '[]'), true) ?: [];
    if (!$templateMap && !empty($grp['template_id'])) {
        foreach ($templates as $template) {
            if ((int)$template['id'] === (int)$grp['template_id']) {
                $templateMap[] = ['template_id'=>(int)$template['id'], 'family'=>(string)$template['target_family']];
                break;
            }
        }
        if (!$templateMap) { $templateMap[] = ['template_id'=>(int)$grp['template_id']]; }
    }
    $templateChoices = array_map(static function (array $template): array {
        $targetFamily = trim((string)($template['target_family'] ?? ''));
        $targetSeries = trim((string)($template['target_series'] ?? ''));
        $targetModel = trim((string)($template['target_model'] ?? ''));
        $targetRole = trim((string)($template['target_role'] ?? ''));
        $scope = [];
        if ($targetSeries !== '') { $scope[] = str_replace('_', ' ', $targetSeries); }
        if ($targetRole !== '') { $scope[] = str_replace('_', ' ', $targetRole); }
        if ($targetModel !== '') { $scope[] = str_replace('|', ' ', $targetModel); }
        $rank = $targetModel !== ''
            ? 30
            : ($targetSeries !== ''
                ? (\FreePBX\modules\Ucmprovisioner\ModelCatalog::isFirmwareTemplateScope($targetSeries) ? 20 : 10)
                : 0);
        $scopeLabel = $targetSeries !== '' ? str_replace('_', ' ', $targetSeries) : str_replace('_', ' ', $targetFamily);
        if ($scopeLabel === '') { $scopeLabel = _('All compatible phones'); }
        if ($targetRole !== '') { $scopeLabel .= ' · ' . str_replace('_', ' ', $targetRole); }
        if ($targetModel !== '') { $scopeLabel .= ' › ' . str_replace('|', ' ', $targetModel); }
        return [
            'id'=>(int)$template['id'],
            'name'=>(string)$template['name'],
            'applicability'=>$scope ? implode(' / ', $scope) : _('All compatible in family'),
            'scope_key'=>$targetModel !== ''
                ? 'model:' . strtolower($targetModel)
                : ($targetSeries !== '' ? 'series:' . strtolower($targetSeries) . '|role:' . strtolower($targetRole) : 'family:' . strtolower($targetFamily) . '|role:' . strtolower($targetRole)),
            'scope_label'=>$scopeLabel,
            'scope_rank'=>$rank,
        ];
    }, $templates);
    // Inventory availability must not silently remove saved policy references.
    $knownTemplateIds = array_fill_keys(array_column($templateChoices, 'id'), true);
    foreach (array_merge($templateMap, $memberExceptions) as $reference) {
        $templateId = (int)($reference['template_id'] ?? 0);
        if ($templateId < 1 || isset($knownTemplateIds[$templateId])) { continue; }
        $templateChoices[] = ['id'=>$templateId, 'name'=>sprintf(_('Unavailable template #%d'), $templateId),
            'applicability'=>_('Saved reference; resolve or remove explicitly'), 'scope_key'=>'unavailable',
            'scope_label'=>_('Unavailable templates'), 'scope_rank'=>40];
        $knownTemplateIds[$templateId] = true;
    }
    $allPhones = $ucm->getPhones(['site_id' => $grpSiteId]);
    $availableGroups = $ucm->getGroups($grpSiteId);
    $phonesByExtension = [];
    $selectedMemberExtensions = [];
    $hasLogicalMembership = array_key_exists('member_extensions', $grp)
        && is_array($grp['member_extensions']);
    if ($hasLogicalMembership) {
        foreach ($grp['member_extensions'] as $extension) {
            $extension = trim((string)$extension);
            if ($extension !== '') { $selectedMemberExtensions[$extension] = true; }
        }
    }
    foreach ($allPhones as $phone) {
        $extension = trim((string)($phone['extension'] ?? ''));
        if ($extension === '') { continue; }
        $phonesByExtension[$extension] = $phone;
        if (!$hasLogicalMembership && $id && (int)($phone['group_id'] ?? 0) === $id) { $selectedMemberExtensions[$extension] = true; }
    }
    $groupNames = [];
    foreach ($availableGroups as $existingGroup) { $groupNames[(int)$existingGroup['id']] = (string)$existingGroup['name']; }
    $extensionCatalog = $ucm->getAllSipExtensions($grpSiteId);
    $availableExtensions = array_fill_keys(array_map(static fn(array $row): string => trim((string)($row['id'] ?? '')), $extensionCatalog), true);
    foreach (array_keys($selectedMemberExtensions) as $extension) {
        if (!isset($availableExtensions[$extension])) {
            $extensionCatalog[] = ['id'=>(string)$extension, 'description'=>_('Saved member; unavailable in current PBX inventory'), 'group_id'=>$id];
        }
    }
    usort($extensionCatalog, static function (array $a, array $b) use ($selectedMemberExtensions): int {
        $aSelected = !empty($selectedMemberExtensions[trim((string)($a['id'] ?? ''))]);
        $bSelected = !empty($selectedMemberExtensions[trim((string)($b['id'] ?? ''))]);
        return ($bSelected <=> $aSelected)
            ?: strnatcasecmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    ?>
    <div class="ucm-container"><div class="ucm-page-intro"><div><h2><?php echo $id ? _('Edit group') : _('Add group'); ?></h2><p><?php echo _('Compose inherited template layers, direct extension membership and cross-site BLF targets while keeping precedence visible.'); ?></p></div><a class="btn btn-default" href="config.php?display=ucmprovisioner&amp;tab=groups&amp;site_filter=<?php echo (int)$grpSiteId; ?>"><?php echo _('Back to groups'); ?></a></div>
    <form class="fpbx-submit ucm-card" name="frm_ucmprovisioner" action="config.php?display=ucmprovisioner&amp;tab=groups&amp;view=group&amp;site_id=<?php echo (int)$grpSiteId; ?>&amp;site_filter=<?php echo (int)$grpSiteId; ?><?php echo $id ? '&amp;id=' . $id : ''; ?>" method="post" novalidate>
      <input type="hidden" name="display" value="ucmprovisioner">
      <input type="hidden" name="tab" value="groups">
      <input type="hidden" name="view" value="group">
      <input type="hidden" name="site_filter" value="<?php echo (int)$grpSiteId; ?>">
      <input type="hidden" name="action" value="savegroup">
      <input type="hidden" name="id" value="<?php echo $id; ?>">
      <input type="hidden" name="revision" value="<?php echo (int)($grp['revision'] ?? 0); ?>">
      <input type="hidden" name="template_id" id="ucm-grp-template-id">
      <input type="hidden" name="template_map" id="ucm-grp-template-map">
      <input type="hidden" name="member_exceptions" id="ucm-grp-member-exceptions">
      <h3><?php echo $id ? _('Edit Group') : _('Add Group'); ?></h3>
      <div class="row"><div class="col-md-4">
        <div class="form-group"><label><?php echo _('Name'); ?></label><input class="form-control" name="name" value="<?php echo $f('name'); ?>" required></div>
      </div><div class="col-md-4">
        <div class="form-group">
          <label><?php echo _('Site'); ?></label>
          <?php if ($id): ?><input type="hidden" name="site_id" value="<?php echo (int)$grpSiteId; ?>"><?php endif; ?>
          <select class="form-control" name="site_id" <?php echo $id ? 'disabled' : ''; ?> onchange="window.location.href='config.php?display=ucmprovisioner&amp;tab=groups&amp;view=group&amp;site_id=' + this.value">
            <?php foreach ($sites as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo ($grpSiteId === (int)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div><div class="col-md-4">
        <div class="form-group">
          <label><?php echo _('Parent provisioning group'); ?></label>
          <select class="form-control" name="parent_group_id">
            <option value=""><?php echo _('None — root group'); ?></option>
            <?php foreach ($availableGroups as $parent): if ((int)$parent['id'] === $id) { continue; } ?>
              <option value="<?php echo (int)$parent['id']; ?>" <?php echo (int)($grp['parent_group_id'] ?? 0) === (int)$parent['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$parent['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="help-block"><?php echo _('This group inherits its parent path root-to-leaf. Cycles and cross-site parents are rejected.'); ?></p>
        </div>
      </div></div>
      <h4><?php echo _('Ordered template layers'); ?></h4>
      <p class="help-block"><?php echo _('Parent groups are evaluated root-to-leaf. Within each group, matching layers are automatically arranged broad policy first, firmware/config compatibility next, and exact-model layers last. Layers in the same Applicability bucket retain their visible top-to-bottom order. Blank or Inherit preserves the earlier value.'); ?></p>
      <div class="ucm-group-layer-add"><select class="form-control" id="ucm-grp-template-choice"><option value=""><?php echo _('-- choose template --'); ?></option></select><button type="button" class="btn btn-default" id="ucm-grp-add-template"><?php echo _('Add template layer'); ?></button></div>
      <div id="ucm-grp-template-scopes" class="ucm-group-template-scopes"></div>
      <span id="ucm-grp-template-warning" class="help-block"></span>
      <?php if (!$templates): ?><p class="text-muted"><a href="config.php?display=ucmprovisioner&amp;tab=templates&amp;view=template&amp;site_id=<?php echo $grpSiteId; ?>"><?php echo _('Create a template first'); ?></a></p><?php endif; ?>
      <div class="form-group">
        <label><input type="checkbox" name="member_blf" value="1" <?php echo !empty($grp['member_blf']) ? 'checked' : ''; ?>> <?php echo _('Expand the template\'s "Other group members" BLF slot for this group\'s members'); ?></label>
        <p class="text-muted"><small><?php echo _('Off by default. Only meaningful for groups where members genuinely need to see and pick up each other\'s calls (e.g. a shared reception line) — most groups are organizational and don\'t need this.'); ?></small></p>
      </div>

      <div class="ucm-subsection ucm-group-exceptions">
        <div class="ucm-card-heading"><div><h4><?php echo _('Member exceptions'); ?></h4><p><?php echo _('Choose one or more members and one compatible template. The normal inherited group stack resolves first; if no group layer matches, the most-specific site default is used. The exception template is then applied last.'); ?></p></div></div>
        <div class="alert alert-info"><strong><?php echo _('Final-layer behavior:'); ?></strong> <?php echo _('Every explicit scalar or setting in the exception replaces the resolved value. A non-empty programmable-key / BLF list replaces the complete earlier template key list instead of merging with it. Blank and Inherit fields preserve the resolved stack. Automatic group-member BLFs and Extra targets remain group behavior and are added separately.'); ?></div>
        <div class="ucm-group-exception-grid">
          <div><label><?php echo _('Final exception template'); ?></label><select class="form-control" id="ucm-group-exception-template"><option value=""><?php echo _('-- choose template --'); ?></option><?php foreach($templateChoices as $choice): ?><option value="<?php echo (int)$choice['id']; ?>"><?php echo htmlspecialchars($choice['name'].' — '.$choice['applicability']); ?></option><?php endforeach; ?></select></div>
          <div><label><?php echo _('Find selected group members'); ?></label><input type="search" class="form-control" id="ucm-group-exception-search" placeholder="<?php echo htmlspecialchars(_('Search extension, name or model')); ?>"></div>
        </div>
        <div class="ucm-group-member-tools ucm-group-exception-tools"><button type="button" class="btn btn-default" id="ucm-group-exception-select-visible"><?php echo _('Select visible'); ?></button><button type="button" class="btn btn-default" id="ucm-group-exception-clear-visible"><?php echo _('Clear visible'); ?></button><strong id="ucm-group-exception-count"></strong></div>
        <div id="ucm-group-exception-members" class="ucm-group-exception-members"></div>
        <button type="button" class="btn btn-primary ucm-top-gap" id="ucm-group-exception-assign"><?php echo _('Add / replace exception for selected members'); ?></button>
        <p class="help-block"><?php echo _('One extension can have one final exception template. Assigning it again replaces only that extension’s previous exception. Template Applicability is checked again during preview and Build; an incompatible exception is skipped without discarding the normal group/default stack. Removing a member from this group also removes its exception when the group is saved.'); ?></p>
        <div class="table-responsive"><table class="table table-condensed" id="ucm-group-exception-table"><thead><tr><th><?php echo _('Exception template'); ?></th><th><?php echo _('Members'); ?></th><th><?php echo _('Applicability'); ?></th><th></th></tr></thead><tbody></tbody></table></div>
      </div>

      <h4><?php echo _('Extra targets for this group'); ?> <small class="text-muted"><?php echo _('(buttons appended after the template + member BLFs, e.g. a group-specific watched extension)'); ?></small></h4>
      <table class="table table-condensed" id="ucm-grp-extra">
        <thead><tr>
          <th style="width:25%"><?php echo _('Feature'); ?></th>
          <th style="width:25%"><?php echo _('Target / number'); ?></th>
          <th style="width:30%"><?php echo _('Label'); ?></th>
          <th style="width:15%"><?php echo _('Cross-site target'); ?></th>
          <th style="width:5%"></th>
        </tr></thead>
        <tbody></tbody>
      </table>
      <button type="button" class="btn btn-default btn-sm" id="ucm-grp-add-row"><?php echo _('+ Add target'); ?></button>
      <input type="hidden" name="extra_targets" id="ucm-grp-extra-json">

      <input type="hidden" name="members_submitted" value="1">
      <div class="ucm-subsection ucm-group-members"><h4><?php echo _('Extension membership'); ?></h4><p class="help-block"><?php echo _('Assign existing PBX extensions here without opening each phone. Membership belongs to the logical extension, so it follows handset swaps and replacements. Selecting an extension already in another group moves its policy to this group; no placeholder phone is created.'); ?></p>
        <div class="ucm-group-member-tools"><input type="search" class="form-control" id="ucm-group-member-search" placeholder="<?php echo htmlspecialchars(_('Search extension, name, model or group')); ?>"><button type="button" class="btn btn-default" id="ucm-group-members-select-visible"><?php echo _('Select visible'); ?></button><button type="button" class="btn btn-default" id="ucm-group-members-clear-visible"><?php echo _('Clear visible'); ?></button><strong id="ucm-group-member-count"></strong></div>
        <div class="table-responsive ucm-group-member-list"><table class="table table-condensed table-hover"><thead><tr><th></th><th><?php echo _('Extension'); ?></th><th><?php echo _('PBX name / phone label'); ?></th><th><?php echo _('Device'); ?></th><th><?php echo _('Current group'); ?></th></tr></thead><tbody>
        <?php foreach ($extensionCatalog as $extensionRow): $extension=trim((string)($extensionRow['id']??'')); if($extension==='')continue; $phone=$phonesByExtension[$extension]??[]; $currentGroupId=(int)($extensionRow['group_id']??($phone['group_id']??0)); $description=trim((string)($extensionRow['description']??'')); $phoneLabel=trim((string)($phone['label']??'')); $device=trim((string)(($phone['m_vendor']??$phone['vendor']??'').' '.($phone['m_model']??''))); $mac=trim((string)($phone['mac']??'')); $searchText=strtolower(implode(' ',[$extension,$description,$phoneLabel,$device,$mac,$groupNames[$currentGroupId]??''])); ?>
          <tr data-extension="<?php echo htmlspecialchars($extension,ENT_QUOTES,'UTF-8'); ?>" data-member-label="<?php echo htmlspecialchars($description!==''?$description:($phoneLabel!==''?$phoneLabel:_('(unnamed)')),ENT_QUOTES,'UTF-8'); ?>" data-device="<?php echo htmlspecialchars(trim($device)?:_('No model assigned'),ENT_QUOTES,'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($searchText,ENT_QUOTES,'UTF-8'); ?>"><td><input type="checkbox" class="ucm-group-member-check" name="member_extensions[]" value="<?php echo htmlspecialchars($extension,ENT_QUOTES,'UTF-8'); ?>" <?php echo !empty($selectedMemberExtensions[$extension])?'checked':''; ?>></td><td><strong><?php echo htmlspecialchars($extension); ?></strong></td><td><?php echo htmlspecialchars($description!==''?$description:($phoneLabel!==''?$phoneLabel:_('(unnamed)'))); ?><?php if($phoneLabel!==''&&$phoneLabel!==$description): ?><br><small class="text-muted"><?php echo htmlspecialchars($phoneLabel); ?></small><?php endif; ?></td><td><?php echo htmlspecialchars(trim($device)?:_('No model assigned')); ?><?php if($mac!==''): ?><br><code><?php echo htmlspecialchars($mac); ?></code><?php endif; ?></td><td><?php if($currentGroupId&&$currentGroupId!==$id): ?><span class="label label-warning"><?php echo htmlspecialchars($groupNames[$currentGroupId]??('#'.$currentGroupId)); ?></span><?php elseif($currentGroupId===$id&&$id): ?><span class="label label-success"><?php echo htmlspecialchars((string)($grp['name']??_('This group'))); ?></span><?php else: ?><span class="text-muted"><?php echo _('Unassigned'); ?></span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$extensionCatalog): ?><tr><td colspan="5" class="text-muted"><?php echo _('No chan_sip extensions were returned by this site. Verify the remote PBX agent and run phone discovery.'); ?></td></tr><?php endif; ?>
        </tbody></table></div>
      </div>
    </form></div>
    <script>
    (function ($) {
      var FEATURES = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson($featureCatalog); ?>;
      var SITES = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $sites)); ?>;
      var initial = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values($extra)); ?>;
      var TEMPLATES = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson($templateChoices, JSON_UNESCAPED_SLASHES); ?>;
      var initialTemplates = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values($templateMap), JSON_UNESCAPED_SLASHES); ?>;
      var initialExceptions = <?php echo \FreePBX\modules\Ucmprovisioner::browserJson(array_values($memberExceptions), JSON_UNESCAPED_SLASHES); ?>;
      var templateLayers = [];
      var exceptionByExtension = {};
      var extensionCache = {}, pickerSeq = 0;

      function featureOptions(sel) {
        var select = $('<select>');
        $.each(FEATURES, function (k, label) {
          select.append($('<option>').val(k).text(label).prop('selected', k === sel));
        });
        return select.html();
      }
      function siteOptions(sel) {
        var select = $('<select>').append($('<option>').val('').text('<?php echo _('-- local --'); ?>'));
        $.each(SITES, function (i, s) {
          select.append($('<option>').val(s.id).text(s.name).prop('selected', String(sel) === String(s.id)));
        });
        return select.html();
      }
      function loadExtensions(siteId, done) {
        siteId = String(siteId || $('select[name=site_id]').val() || '<?php echo $grpSiteId; ?>');
        if (extensionCache[siteId]) { done(extensionCache[siteId]); return; }
        window.ucmpAjax({data:{command:'extensions', site_id:siteId}}).done(function(resp){
          var result=(resp&&resp.result)||{}, rows=result.extensions||[];
          if(result.ok)extensionCache[siteId]=rows; done(result.ok?rows:[]);
        }).fail(function(){ done([]); });
      }
      function wireExtensionPicker($target, $site, $label) {
        var listId='ucm-grp-ext-'+(++pickerSeq), $list=$('<datalist>').attr('id',listId), request=0;
        $target.attr('list',listId).after($list);
        function refresh(){var id=++request;loadExtensions($site.val(),function(rows){if(id!==request)return;$list.empty();rows.forEach(function(row){var ext=String(row.extension||row.ext||row.id||''),name=String(row.description||row.name||row.display_name||row.label||'');if(ext)$list.append($('<option>').val(ext).attr('data-label',name).text(name));});autolabel();});}
        function autolabel(){if($.trim($label.val()))return;var value=String($target.val()||''),match=$list.find('option').filter(function(){return this.value===value;}).first();if(match.length)$label.val(match.attr('data-label')||'');}
        $site.on('change',refresh);$target.on('change blur',autolabel);refresh();
      }
      function templateById(id){id=parseInt(id||0,10);return TEMPLATES.filter(function(t){return Number(t.id)===id;})[0]||null;}
      function orderedTemplateLayers(){return templateLayers.map(function(layer,index){return{template_id:Number(layer.template_id),index:index,template:templateById(layer.template_id)};}).filter(function(layer){return layer.template;}).sort(function(a,b){return Number(a.template.scope_rank||0)-Number(b.template.scope_rank||0)||String(a.template.scope_label||'').localeCompare(String(b.template.scope_label||''),undefined,{numeric:true})||a.index-b.index;}).map(function(layer){return{template_id:layer.template_id};});}
      function serializeTemplates(){var rows=orderedTemplateLayers().map(function(layer,index){return{template_id:Number(layer.template_id),position:(index+1)*10};});templateLayers=rows.map(function(row){return{template_id:row.template_id};});$('#ucm-grp-template-map').val(JSON.stringify(rows));$('#ucm-grp-template-id').val(rows.length?rows[0].template_id:'');$('#ucm-grp-template-warning').removeClass('text-danger').text(rows.length?'Matching layers resolve in the applicability buckets shown above.':'No layer is defined here. If no compatible layer exists anywhere in the inherited group path, the phone uses its most-specific site default template.');return true;}
      function refreshTemplateChoice(){var assigned={};templateLayers.forEach(function(layer){assigned[Number(layer.template_id)]=true;});var select=$('#ucm-grp-template-choice'),selected=String(select.val()||'');select.find('option:not(:first)').remove();TEMPLATES.slice().sort(function(a,b){return Number(a.scope_rank||0)-Number(b.scope_rank||0)||String(a.scope_label||'').localeCompare(String(b.scope_label||''),undefined,{numeric:true})||String(a.name).localeCompare(String(b.name));}).forEach(function(t){if(!assigned[Number(t.id)])select.append($('<option>').val(t.id).text(t.name+' — '+t.applicability));});if(select.find('option[value="'+selected+'"]').length)select.val(selected);}
      function moveTemplate(templateId,direction){var current=templateById(templateId);if(!current)return;var indices=[];templateLayers.forEach(function(layer,index){var t=templateById(layer.template_id);if(t&&t.scope_key===current.scope_key)indices.push(index);});var index=templateLayers.findIndex(function(layer){return Number(layer.template_id)===Number(templateId);}),at=indices.indexOf(index),swap=indices[at+direction];if(at<0||swap===undefined)return;var hold=templateLayers[index];templateLayers[index]=templateLayers[swap];templateLayers[swap]=hold;renderTemplateLayers();}
      function renderTemplateLayers(){templateLayers=orderedTemplateLayers();var host=$('#ucm-grp-template-scopes').empty(),buckets={};templateLayers.forEach(function(layer){var t=templateById(layer.template_id);if(!t)return;(buckets[t.scope_key]||(buckets[t.scope_key]={template:t,layers:[]})).layers.push(layer);});Object.keys(buckets).sort(function(a,b){var x=buckets[a].template,y=buckets[b].template;return Number(x.scope_rank||0)-Number(y.scope_rank||0)||String(x.scope_label||'').localeCompare(String(y.scope_label||''),undefined,{numeric:true});}).forEach(function(key){var bucket=buckets[key],section=$('<section class="ucm-group-scope-bucket">'),head=$('<div class="ucm-group-scope-heading">').append($('<strong>').text(bucket.template.scope_label),$('<span class="label label-info">').text(bucket.layers.length+' layer'+(bucket.layers.length===1?'':'s'))),list=$('<div class="ucm-group-scope-layers">');bucket.layers.forEach(function(layer,index){var t=templateById(layer.template_id),actions=$('<div class="btn-group btn-group-xs">').append($('<button type="button" class="btn btn-default" title="Move earlier inside this applicability bucket">').text('↑').prop('disabled',index===0).on('click',function(){moveTemplate(layer.template_id,-1);}),$('<button type="button" class="btn btn-default" title="Move later inside this applicability bucket">').text('↓').prop('disabled',index===bucket.layers.length-1).on('click',function(){moveTemplate(layer.template_id,1);}),$('<button type="button" class="btn btn-danger" title="Remove layer">').text('×').on('click',function(){templateLayers=templateLayers.filter(function(row){return Number(row.template_id)!==Number(layer.template_id);});renderTemplateLayers();}));list.append($('<div class="ucm-group-layer-row">').append($('<span class="ucm-group-layer-order">').text(index+1),$('<div>').append($('<strong>').text(t.name),$('<small>').text(t.applicability)),actions));});section.append(head,list);host.append(section);});serializeTemplates();refreshTemplateChoice();}

      function activeMembers(){var members={};$('.ucm-group-member-check:checked').each(function(){members[String(this.value)]=true;});return members;}
      function serializeExceptions(){var active=activeMembers(),grouped={};Object.keys(exceptionByExtension).sort(function(a,b){return a.localeCompare(b,undefined,{numeric:true});}).forEach(function(extension){if(!active[extension])return;var templateId=Number(exceptionByExtension[extension]);(grouped[templateId]||(grouped[templateId]=[])).push(extension);});var rows=Object.keys(grouped).map(function(templateId){return{template_id:Number(templateId),extensions:grouped[templateId]};});$('#ucm-grp-member-exceptions').val(JSON.stringify(rows));return rows;}
      function exceptionSearch(){return $.trim(String($('#ucm-group-exception-search').val()||'')).toLowerCase();}
      function refreshExceptionCount(){var selected=$('#ucm-group-exception-members .ucm-group-exception-check:checked').length,visible=$('#ucm-group-exception-members .ucm-group-exception-member:visible').length;$('#ucm-group-exception-count').text(selected+' selected · '+visible+' visible');}
      function renderExceptionMembers(){var previously={};$('#ucm-group-exception-members .ucm-group-exception-check:checked').each(function(){previously[String(this.value)]=true;});var host=$('#ucm-group-exception-members').empty(),query=exceptionSearch();$('.ucm-group-member-list tbody tr[data-extension]').each(function(){var row=$(this),check=row.find('.ucm-group-member-check');if(!check.is(':checked'))return;var extension=String(row.data('extension')||check.val()||''),label=String(row.data('member-label')||''),device=String(row.data('device')||''),search=(extension+' '+label+' '+device).toLowerCase(),item=$('<label class="ucm-group-exception-member">').attr('data-search',search).toggle(!query||search.indexOf(query)!==-1).append($('<input type="checkbox" class="ucm-group-exception-check">').val(extension).prop('checked',!!previously[extension]),$('<span>').append($('<strong>').text(extension+' · '+label),$('<small>').text(device)));host.append(item);});if(!host.children().length)host.append($('<p class="text-muted">').text('Select extensions in Extension membership below before creating an exception.'));refreshExceptionCount();}
      function renderExceptions(){var active=activeMembers(),grouped={};Object.keys(exceptionByExtension).forEach(function(extension){var templateId=Number(exceptionByExtension[extension]);(grouped[templateId]||(grouped[templateId]=[])).push(extension);});var body=$('#ucm-group-exception-table tbody').empty();Object.keys(grouped).sort(function(a,b){var x=templateById(a),y=templateById(b);return String(x&&x.name||a).localeCompare(String(y&&y.name||b),undefined,{numeric:true});}).forEach(function(templateId){var t=templateById(templateId),extensions=grouped[templateId].sort(function(a,b){return a.localeCompare(b,undefined,{numeric:true});}),members=$('<div class="ucm-group-exception-chips">');extensions.forEach(function(extension){members.append($('<span class="label">').addClass(active[extension]?'label-primary':'label-warning').attr('title',active[extension]?'Group member':'No longer selected; removed on Save').text(extension));});var actions=$('<div class="btn-group btn-group-xs">').append($('<button type="button" class="btn btn-default">').text('Select / edit').on('click',function(){$('#ucm-group-exception-template').val(String(templateId));renderExceptionMembers();$('#ucm-group-exception-members .ucm-group-exception-check').each(function(){$(this).prop('checked',extensions.indexOf(String(this.value))!==-1);});refreshExceptionCount();}),$('<button type="button" class="btn btn-danger">').text('Remove').on('click',function(){extensions.forEach(function(extension){delete exceptionByExtension[extension];});renderExceptions();}));body.append($('<tr>').append($('<td>').append($('<strong>').text(t?t.name:('Missing template #'+templateId)),$('<small class="help-block">').text('Applied last')), $('<td>').append(members),$('<td>').text(t?t.applicability:'Template is unavailable'),$('<td>').append(actions)));});if(!Object.keys(grouped).length)body.append($('<tr>').append($('<td colspan="4" class="text-muted">').text('No member exceptions.')));serializeExceptions();}
      function addRow(b) {
        b = b || {};
        var $tr = $('<tr>');
        $tr.append($('<td>').append($('<select class="form-control input-sm ucm-row-feature">').html(featureOptions(b.feature))));
        var $target=$('<input class="form-control input-sm ucm-row-target" type="text" placeholder="Choose or type an extension">').val(b.target || ''),$label=$('<input class="form-control input-sm ucm-row-label" type="text">').val(b.label || ''),$site=$('<select class="form-control input-sm ucm-row-site">').html(siteOptions(b.target_site || ''));
        $tr.append($('<td>').append($target));
        $tr.append($('<td>').append($label));
        $tr.append($('<td>').append($site));
        $tr.append($('<td>').append($('<button type="button" class="btn btn-xs btn-danger ucm-row-del">&times;</button>')));
        $('#ucm-grp-extra tbody').append($tr);
        wireExtensionPicker($target,$site,$label);
      }
      function serialize() {
        var rows = [];
        $('#ucm-grp-extra tbody tr').each(function () {
          var $tr = $(this);
          var row = {
            feature: $tr.find('.ucm-row-feature').val(),
            target: $tr.find('.ucm-row-target').val(),
            label: $tr.find('.ucm-row-label').val()
          };
          var site = $tr.find('.ucm-row-site').val();
          if (site) { row.target_site = site; }
          rows.push(row);
        });
        $('#ucm-grp-extra-json').val(JSON.stringify(rows));
      }
      $(document).on('click', '#ucm-grp-add-row', function () { addRow({}); serialize(); });
      $(document).on('click', '.ucm-row-del', function () { $(this).closest('tr').remove(); serialize(); });
      $(document).on('change input', '#ucm-grp-extra', serialize);
      $('#ucm-grp-add-template').on('click',function(){var templateId=Number($('#ucm-grp-template-choice').val()||0);if(!templateId)return;if(templateLayers.some(function(row){return Number(row.template_id)===templateId;}))return;templateLayers.push({template_id:templateId});$('#ucm-grp-template-choice').val('');renderTemplateLayers();});
      function refreshMemberCount(){var selected=$('.ucm-group-member-check:checked').length,visible=$('.ucm-group-member-list tbody tr:visible .ucm-group-member-check').length;$('#ucm-group-member-count').text(selected+' selected · '+visible+' visible');}
      $('#ucm-group-member-search').on('input',function(){var q=$.trim(String($(this).val()||'')).toLowerCase();$('.ucm-group-member-list tbody tr[data-search]').each(function(){$(this).toggle(!q||String($(this).data('search')||'').indexOf(q)!==-1);});refreshMemberCount();});
      $('#ucm-group-members-select-visible').on('click',function(){$('.ucm-group-member-list tbody tr:visible .ucm-group-member-check').prop('checked',true);refreshMemberCount();renderExceptionMembers();renderExceptions();});
      $('#ucm-group-members-clear-visible').on('click',function(){$('.ucm-group-member-list tbody tr:visible .ucm-group-member-check').prop('checked',false);refreshMemberCount();renderExceptionMembers();renderExceptions();});
      $(document).on('change','.ucm-group-member-check',function(){refreshMemberCount();renderExceptionMembers();renderExceptions();});
      $('#ucm-group-exception-search').on('input',renderExceptionMembers);
      $('#ucm-group-exception-select-visible').on('click',function(){$('#ucm-group-exception-members .ucm-group-exception-member:visible .ucm-group-exception-check').prop('checked',true);refreshExceptionCount();});
      $('#ucm-group-exception-clear-visible').on('click',function(){$('#ucm-group-exception-members .ucm-group-exception-member:visible .ucm-group-exception-check').prop('checked',false);refreshExceptionCount();});
      $(document).on('change','.ucm-group-exception-check',refreshExceptionCount);
      $('#ucm-group-exception-assign').on('click',function(){var templateId=Number($('#ucm-group-exception-template').val()||0),extensions=$('#ucm-group-exception-members .ucm-group-exception-check:checked').map(function(){return String(this.value);}).get();if(!templateId){alert('Choose a final exception template.');return;}if(!extensions.length){alert('Select at least one group member.');return;}extensions.forEach(function(extension){exceptionByExtension[extension]=templateId;});$('#ucm-group-exception-members .ucm-group-exception-check').prop('checked',false);renderExceptions();refreshExceptionCount();});
      $.each(initial, function (i, b) { addRow(b); });
      $.each(initialTemplates, function(i, rule){var id=Number(rule&&rule.template_id||0);if(id&&templateById(id)&&!templateLayers.some(function(row){return Number(row.template_id)===id;}))templateLayers.push({template_id:id});});
      $.each(initialExceptions,function(i,rule){var templateId=Number(rule&&rule.template_id||0);if(!templateId)return;(Array.isArray(rule.extensions)?rule.extensions:[]).forEach(function(extension){extension=$.trim(String(extension||''));if(extension)exceptionByExtension[extension]=templateId;});});
      function serializeDraft(){serialize();serializeExceptions();return serializeTemplates();}
      $('form[name=frm_ucmprovisioner]').on('ucmp:serialize-draft',serializeDraft).on('submit',function(e){if(!serializeDraft())e.preventDefault();});
      renderTemplateLayers();
      serialize();
      refreshMemberCount();
      renderExceptionMembers();
      renderExceptions();
    })(jQuery);
    </script>
    <?php
    return;
}

$siteFilter = (int)($_REQUEST['site_filter'] ?? 0) ?: $ucm->localSiteId();
$allSites = $ucm->getSites();
$rows = $ucm->getGroups($siteFilter);
$tplNames = [];
foreach ($ucm->getTemplates($siteFilter, false) as $t) { $tplNames[(int)$t['id']] = $t['name']; }
$siteNames = [];
foreach ($allSites as $s) { $siteNames[(int)$s['id']] = $s['name']; }
$memberCounts = [];
$phoneFilters = ['site_id' => $siteFilter];
foreach ($ucm->getPhones($phoneFilters) as $p) {
    $gid = (int)($p['group_id'] ?? 0);
    if ($gid) { $memberCounts[$gid] = ($memberCounts[$gid] ?? 0) + 1; }
}
$groupSyncJobs = [];
$groupSyncError = '';
try {
    foreach ((array)($ucm->configurationSyncOp(['op'=>'list'])['jobs'] ?? []) as $job) {
        if ((int)($job['source_site_id'] ?? 0) === $siteFilter
            && in_array('groups', (array)($job['scopes'] ?? []), true)) {
            $groupSyncJobs[] = $job;
        }
    }
} catch (\Throwable $error) {
    $groupSyncError = $error->getMessage();
}
$groupSyncStateById = [];
// Badges describe inbound ownership on the selected destination.  Outbound
// job rows do not identify the destination group reliably after a rename.
$groupSyncStateError = '';
try {
    foreach ($ucm->configurationSyncStates($siteFilter, 'group') as $state) {
        $destinationId = (int)($state['destination_id'] ?? 0);
        if ($destinationId > 0 && (string)($state['status'] ?? '') !== 'released') {
            $groupSyncStateById[$destinationId] = $state;
        }
    }
} catch (\Throwable $error) {
    $groupSyncStateError = $error->getMessage();
}
?>
<div class="ucm-container"><div class="ucm-page-intro"><div><h2><?php echo _('Groups'); ?></h2><p><?php echo _('Layer templates, inherit provisioning-group settings and assign extensions without losing the source or precedence of each setting.'); ?></p></div><button type="button" class="btn btn-default" id="ucm-group-sync-open"><?php echo _('Push / sync groups'); ?></button></div><section class="ucm-card"><div class="ucm-toolbar" style="margin-bottom:10px;">
  <form method="get" style="display:inline-block;margin-right:8px;" id="ucm-site-filter-form">
    <input type="hidden" name="display" value="ucmprovisioner">
    <input type="hidden" name="tab" value="groups">
    <label style="margin-right:4px;"><?php echo _('Site'); ?>:</label>
    <select class="form-control" name="site_filter" id="ucm-site-filter" style="display:inline-block;width:auto;" onchange="this.form.submit()">
      <?php foreach ($allSites as $s): ?>
        <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$siteFilter === (string)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <a class="btn btn-primary" href="config.php?display=ucmprovisioner&amp;tab=groups&amp;view=group&amp;site_id=<?php echo (int)$siteFilter; ?>"><?php echo _('Add Group'); ?></a>
  <button type="button" class="btn btn-default" id="ucm-group-sync-select-all"><?php echo _('Select all groups'); ?></button>
  <?php if ($groupSyncError): ?><div class="alert alert-warning ucm-top-gap"><?php echo htmlspecialchars(_('Group synchronization state could not be loaded: ') . $groupSyncError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <?php if ($groupSyncStateError): ?><div class="alert alert-warning ucm-top-gap"><?php echo htmlspecialchars(_('Inbound group synchronization state could not be loaded: ') . $groupSyncStateError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
</div>
<table class="table table-striped">
  <thead><tr>
    <th style="width:32px"><input type="checkbox" id="ucm-group-sync-all" aria-label="<?php echo _('Select all groups'); ?>"></th><th><?php echo _('Name'); ?></th><th><?php echo _('Site'); ?></th><th><?php echo _('Template'); ?></th>
    <th><?php echo _('Members'); ?></th><th><?php echo _('Actions'); ?></th>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $g): ?>
      <?php $g['site_id'] = $siteFilter; // Every row came from this explicit owner-site query. ?>
      <tr>
        <td><input type="checkbox" class="ucm-group-sync-select" value="<?php echo (int)$g['id']; ?>" aria-label="<?php echo htmlspecialchars(sprintf(_('Select %s for synchronization'), (string)$g['name']), ENT_QUOTES, 'UTF-8'); ?>"></td>
        <td><?php echo htmlspecialchars((string)$g['name']); ?><?php $syncState=$groupSyncStateById[(int)$g['id']]??null; if($syncState): ?><br><span class="label <?php echo in_array((string)($syncState['status']??''),['conflict','owner_conflict','paused','missing_dependency'],true)?'label-warning':'label-success'; ?>" title="<?php echo htmlspecialchars((string)($syncState['pause_reason']??''),ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars(_('sync') . ' · ' . str_replace('_',' ',(string)($syncState['status']??'active')), ENT_QUOTES, 'UTF-8'); ?><?php if(!empty($syncState['soft_lock'])): ?> · <?php echo _('soft-lock'); ?><?php endif; ?></span><?php if(!empty($syncState['missing'])): ?> <span class="label label-warning"><?php echo _('missing dependency'); ?></span><?php endif; ?><?php endif; ?></td>
        <td><?php echo htmlspecialchars($siteNames[(int)$g['site_id']] ?? _('local')); ?></td>
        <td><?php $mapped=json_decode((string)($g['template_map']??'[]'),true)?:[];$labels=[];foreach($mapped as $rule){$name=$tplNames[(int)($rule['template_id']??0)]??'';if($name!=='')$labels[]=$name.(!empty($rule['model_id'])?' ('._('exact model').')':' ('._('family').')');}if(!$labels&&!empty($g['template_id']))$labels[]=$tplNames[(int)$g['template_id']]??'';echo htmlspecialchars(implode(', ',array_filter($labels))?:_('(none)')); ?></td>
        <td><?php echo (int)($memberCounts[(int)$g['id']] ?? 0); ?></td>
        <td>
          <a class="btn btn-xs btn-default" href="config.php?display=ucmprovisioner&amp;tab=groups&amp;view=group&amp;id=<?php echo (int)$g['id']; ?>&amp;site_id=<?php echo (int)$g['site_id']; ?>"><?php echo _('Edit'); ?></a>
          <form method="post" action="config.php?display=ucmprovisioner&amp;tab=groups" class="ucm-inline-form" onsubmit="return confirm('<?php echo addslashes(_('Delete this empty group? A synchronized destination copy will be paused; groups with children or assigned extensions must be reorganized first.')); ?>')"><input type="hidden" name="ucmp_csrf" value="<?php echo htmlspecialchars($ucm->pageCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="delgroup"><input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>"><input type="hidden" name="site_id" value="<?php echo (int)$g['site_id']; ?>"><input type="hidden" name="site_filter" value="<?php echo (int)$g['site_id']; ?>"><input type="hidden" name="sync_edit_confirmed" value="1"><input type="hidden" name="sync_edit_reason" value="Deleted in Groups"><button type="submit" class="btn btn-xs btn-danger"><?php echo _('Delete'); ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="6" class="text-muted"><?php echo _('No groups yet.'); ?></td></tr>
    <?php endif; ?>
  </tbody>
 </table>
</section>
<section class="ucm-card ucm-group-sync-drawer" id="ucm-group-sync-drawer" hidden>
  <div class="ucm-card-heading"><div><span class="ucm-kicker"><?php echo _('Federated policy'); ?></span><h3><?php echo _('Push / synchronize groups'); ?></h3><p><?php echo _('Sync the selected group structure, template layers and shared BLF policy. Each site keeps its own extension members and member-specific exceptions; source extensions are never required. New groups start without members. Missing templates resolve automatically when they arrive.'); ?></p></div><button type="button" class="btn btn-default" id="ucm-group-sync-close"><?php echo _('Close'); ?></button></div>
  <div class="row"><div class="col-md-4"><label for="ucm-group-sync-job"><?php echo _('Saved job'); ?></label><select class="form-control" id="ucm-group-sync-job"><option value="0"><?php echo _('New group sync'); ?></option><?php foreach($groupSyncJobs as $job): ?><option value="<?php echo (int)$job['id']; ?>"><?php echo htmlspecialchars((string)$job['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label for="ucm-group-sync-name"><?php echo _('Job name'); ?></label><input class="form-control" id="ucm-group-sync-name" maxlength="80" value="<?php echo htmlspecialchars(_('Group push from ') . ($siteNames[$siteFilter] ?? ('Site #' . $siteFilter)), ENT_QUOTES, 'UTF-8'); ?>"></div><div class="col-md-4"><label><?php echo _('Selected groups'); ?></label><div class="form-control-static" id="ucm-group-sync-selection"><?php echo _('None selected'); ?></div></div></div>
  <div class="row ucm-top-gap"><div class="col-md-6"><fieldset class="ucm-choice-grid ucm-picker" data-ucm-picker><legend><?php echo _('Destination PBXs'); ?></legend><div class="ucm-picker-toolbar"><label class="ucm-picker-search"><span class="sr-only"><?php echo _('Search destination PBXs'); ?></span><input type="search" class="form-control input-sm ucm-picker-filter" placeholder="<?php echo _('Search destinations'); ?>" autocomplete="off"></label><div class="ucm-picker-actions"><button type="button" class="btn btn-xs btn-default ucm-picker-visible"><?php echo _('Select visible'); ?></button><button type="button" class="btn btn-xs btn-default ucm-picker-all"><?php echo _('Select all'); ?></button><button type="button" class="btn btn-xs btn-default ucm-picker-clear"><?php echo _('Clear'); ?></button></div><span class="ucm-picker-count" aria-live="polite"></span></div><div class="ucm-picker-list"><?php foreach($allSites as $site): if((int)$site['id']===$siteFilter||empty($site['enabled']))continue; ?><label class="ucm-choice-card ucm-picker-item"><input type="checkbox" class="ucm-group-sync-target" value="<?php echo (int)$site['id']; ?>"> <span><strong><?php echo htmlspecialchars((string)$site['name'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo !empty($site['is_local'])?_('local controller'):_('federated destination'); ?></small></span></label><?php endforeach; ?></div></fieldset></div><div class="col-md-6"><fieldset class="ucm-choice-grid"><legend><?php echo _('Protection and change policy'); ?></legend><label class="ucm-choice-card"><input type="checkbox" id="ucm-group-sync-enabled" checked> <span><strong><?php echo _('Automatic synchronization'); ?></strong><small><?php echo _('Off means a saved one-time/manual push job. Revision-aware synchronization preserves destination edits until an explicit conflict confirmation.'); ?></small></span></label><label class="ucm-choice-card"><input type="checkbox" id="ucm-group-sync-lock" checked> <span><strong><?php echo _('Soft-lock destination copies'); ?></strong><small><?php echo _('A destination edit pauses only that group and requires confirmation.'); ?></small></span></label><label class="ucm-choice-card"><input type="checkbox" id="ucm-group-sync-retry" checked> <span><strong><?php echo _('Retry unreachable sites'); ?></strong><small><?php echo _('Retry after five minutes without clearing the last known state.'); ?></small></span></label></fieldset></div></div>
  <div class="ucm-inline-panel ucm-top-gap"><h4><?php echo _('Automatic cadence'); ?></h4><div class="row"><div class="col-md-3"><label for="ucm-group-sync-mode"><?php echo _('Schedule'); ?></label><select class="form-control" id="ucm-group-sync-mode"><option value="manual"><?php echo _('Manual only'); ?></option><option value="interval"><?php echo _('Every interval'); ?></option><option value="daily"><?php echo _('Daily'); ?></option><option value="weekly"><?php echo _('Selected weekdays'); ?></option></select></div><div class="col-md-3 ucm-group-sync-interval"><label for="ucm-group-sync-minutes"><?php echo _('Interval minutes'); ?></label><input class="form-control" id="ucm-group-sync-minutes" type="number" min="5" max="10080" value="60"></div><div class="col-md-3 ucm-group-sync-clock" hidden><label for="ucm-group-sync-time"><?php echo _('PBX local time'); ?></label><input class="form-control" id="ucm-group-sync-time" type="time" value="02:00"></div></div><div class="ucm-group-sync-weekdays" hidden><label><?php echo _('Weekdays'); ?></label><div><?php foreach(['1'=>_('Mon'),'2'=>_('Tue'),'3'=>_('Wed'),'4'=>_('Thu'),'5'=>_('Fri'),'6'=>_('Sat'),'7'=>_('Sun')] as $day=>$label): ?><label class="checkbox-inline"><input type="checkbox" class="ucm-group-sync-weekday" value="<?php echo $day; ?>" <?php echo (int)$day<=5?'checked':''; ?>> <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></label><?php endforeach; ?></div></div></div>
  <div class="ucm-action-row"><button type="button" class="btn btn-primary" id="ucm-group-sync-save"><?php echo _('Save sync settings'); ?></button><button type="button" class="btn btn-info" id="ucm-group-sync-run"><?php echo _('Push now'); ?></button><button type="button" class="btn btn-danger" id="ucm-group-sync-delete" disabled><?php echo _('Delete job'); ?></button><span id="ucm-group-sync-feedback" class="ucm-inline-action-feedback" aria-live="polite"></span></div>
  <div id="ucm-group-sync-items"></div>
</section></div>
<script>jQuery(function($){
  var jobs=<?php echo \FreePBX\modules\Ucmprovisioner::browserJson($groupSyncJobs,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>||[],sourceSite=<?php echo (int)$siteFilter; ?>,currentJob=null;
  var syncEditor=window.ucmpConfigurationEditor({prefix:'ucm-group-sync',current:function(){return currentJob;},scopes:function(){return ['groups'];},feedback:feedback,load:loadJob,
    remember:function(job){currentJob=job;var select=$('#ucm-group-sync-job');if(!select.find('option[value="'+job.id+'"]').length)select.append($('<option>').val(job.id).text(job.name));select.val(job.id);},
    list:function(rows){jobs=rows.filter(function(job){return Number(job.source_site_id)===sourceSite&&((job.scopes||[]).indexOf('groups')!==-1);});var select=$('#ucm-group-sync-job').empty().append($('<option value="0">').text('New sync job'));jobs.forEach(function(job){select.append($('<option>').val(job.id).text(job.name));});}
  });
  function wirePicker(root){var $root=$(root),$items=$root.find('.ucm-picker-item'),$filter=$root.find('.ucm-picker-filter');function refresh(){var query=String($filter.val()||'').toLowerCase(),visible=0,total=$items.length;$items.each(function(){var $item=$(this),match=!query||$item.text().toLowerCase().indexOf(query)!==-1;$item.prop('hidden',!match);if(match)visible++;});var selected=$items.find('input:checked').length;$root.find('.ucm-picker-count').text(selected+' selected · '+visible+' visible of '+total);} $filter.on('input',refresh);$root.on('ucm-picker-refresh',refresh);$root.find('.ucm-picker-visible').on('click',function(){$items.filter(function(){return !$(this).prop('hidden');}).find('input').prop('checked',true).trigger('change');});$root.find('.ucm-picker-all').on('click',function(){$items.find('input').prop('checked',true).trigger('change');});$root.find('.ucm-picker-clear').on('click',function(){$items.find('input').prop('checked',false).trigger('change');});$items.find('input').on('change',refresh);refresh();}
  $('.ucm-picker').each(function(){wirePicker(this);});function refreshPickers(){$('.ucm-picker').trigger('ucm-picker-refresh');}
  function selectedIds(){return $('.ucm-group-sync-select:checked').map(function(){return Number(this.value);}).get();}
  function selectionSummary(){var ids=selectedIds(),names=[];ids.forEach(function(id){names.push($.trim($('.ucm-group-sync-select[value="'+id+'"]').closest('tr').find('td').eq(1).clone().children().remove().end().text()));});$('#ucm-group-sync-selection').text(ids.length?ids.length+' selected: '+names.join(', '):'None selected');}
  function idsFor(job){var ids=job&&job.group_ids||job&&job.object_filter&&job.object_filter.groups||[];if(ids.length)return ids.map(Number);(job&&job.items||[]).forEach(function(item){var sourceId=Number(item&&item.intended&&item.intended._source_group_id||0);if(item&&item.object_type==='group'&&sourceId>0)ids.push(sourceId);});return ids;}
  function schedule(job){var s=job&&job.schedule||{};return{mode:s.mode||'manual',interval_minutes:s.interval_minutes||job&&job.interval_minutes||60,time:s.time||'02:00',weekdays:s.weekdays||[1,2,3,4,5]};}
  function visibility(){var mode=$('#ucm-group-sync-mode').val();$('.ucm-group-sync-interval').prop('hidden',mode!=='interval');$('.ucm-group-sync-clock').prop('hidden',['daily','weekly'].indexOf(mode)===-1);$('.ucm-group-sync-weekdays').prop('hidden',mode!=='weekly');}
  function feedback(text,bad){$('#ucm-group-sync-feedback').toggleClass('text-danger',!!bad).toggleClass('text-success',!bad).text(text||'');}
  function renderItems(job){var items=job&&job.items||[],$box=$('#ucm-group-sync-items').empty();if(!items.length)return;$box.append($('<h4>').text('Destination group state'));items.forEach(function(item){var missing=item.missing||{},detail=item.pause_reason||'';if(Object.keys(missing).length)detail=Object.keys(missing).map(function(k){return k+': '+missing[k];}).join('; ');var status=String(item.status||'active'),$row=$('<div class="ucm-sync-item">').append($('<strong>').text('Site #'+item.target_site_id+' · '+item.object_key),$('<span class="label">').addClass(['conflict','owner_conflict','paused','missing_dependency'].indexOf(status)!==-1?'label-warning':'label-success').text(status.replace(/_/g,' ')),$('<small>').text((item.soft_lock?'soft-lock · ':'')+detail));if(status==='paused')$row.append($('<button type="button" class="btn btn-xs btn-default">').text('Resume').on('click',function(){syncEditor.mutate({command:'configurationsync',op:'resume',id:job.id,target_site_id:item.target_site_id,object_type:item.object_type,object_key:item.object_key});}));$box.append($row);});}
  function loadJob(job,preserveResult){if(syncEditor.busy())return;if(!preserveResult)syncEditor.showResult(job&&job.last_result||{});currentJob=job||null;$('#ucm-group-sync-job').val(job?job.id:0);$('#ucm-group-sync-name').val(job?job.name:'Group push from Site #'+sourceSite);$('.ucm-group-sync-target').prop('checked',false);(job&&job.target_site_ids||[]).forEach(function(id){$('.ucm-group-sync-target[value="'+id+'"]').prop('checked',true);});var ids=idsFor(job);if(job){$('.ucm-group-sync-select').prop('checked',false);ids.forEach(function(id){$('.ucm-group-sync-select[value="'+id+'"]').prop('checked',true);});}$('#ucm-group-sync-enabled').prop('checked',job?(job.enabled===true||Number(job.enabled)===1):true);$('#ucm-group-sync-lock').prop('checked',job?(job.soft_lock===true||Number(job.soft_lock)===1):true);$('#ucm-group-sync-retry').prop('checked',job?(job.retry_unreachable===true||Number(job.retry_unreachable)===1):true);var s=schedule(job);$('#ucm-group-sync-mode').val(s.mode);$('#ucm-group-sync-minutes').val(s.interval_minutes);$('#ucm-group-sync-time').val(s.time);$('.ucm-group-sync-weekday').prop('checked',false);(s.weekdays||[1,2,3,4,5]).forEach(function(day){$('.ucm-group-sync-weekday[value="'+day+'"]').prop('checked',true);});$('#ucm-group-sync-delete').prop('disabled',!job);visibility();selectionSummary();refreshPickers();renderItems(job);}
  function payload(op){var ids=selectedIds();return{command:'configurationsync',op:op,id:currentJob?currentJob.id:0,name:$('#ucm-group-sync-name').val(),source_site_id:sourceSite,enabled:$('#ucm-group-sync-enabled').is(':checked')?1:0,soft_lock:$('#ucm-group-sync-lock').is(':checked')?1:0,retry_unreachable:$('#ucm-group-sync-retry').is(':checked')?1:0,target_site_ids:window.ucmpRetainedSelection('.ucm-group-sync-target',currentJob&&currentJob.target_site_ids).map(Number),scopes:['groups'],group_ids:window.ucmpRetainedSelection('.ucm-group-sync-select',currentJob&&currentJob.group_ids).map(Number),schedule_mode:$('#ucm-group-sync-mode').val(),interval_minutes:Number($('#ucm-group-sync-minutes').val()||60),schedule_time:$('#ucm-group-sync-time').val(),schedule_weekdays:$('.ucm-group-sync-weekday:checked').map(function(){return Number(this.value);}).get()};}

  function save(thenRun){var p=payload('save');if(!p.group_ids.length){feedback('Select at least one group.',true);return;}if(!p.target_site_ids.length){feedback('Select at least one destination PBX.',true);return;}if(p.schedule_mode==='weekly'&&!p.schedule_weekdays.length){feedback('Choose at least one weekday for a weekly group sync job.',true);return;}syncEditor.save(p,thenRun);}

  $('#ucm-group-sync-open').on('click',function(){selectionSummary();$('#ucm-group-sync-drawer').prop('hidden',false)[0].scrollIntoView({behavior:'smooth',block:'start'});});$('#ucm-group-sync-close').on('click',function(){$('#ucm-group-sync-drawer').prop('hidden',true);});$('#ucm-group-sync-all,#ucm-group-sync-select-all').on('change click',function(){var checked=this.checked===undefined?true:this.checked;$('.ucm-group-sync-select').prop('checked',checked);$('#ucm-group-sync-all').prop('checked',checked);selectionSummary();});$('.ucm-group-sync-select').on('change',selectionSummary);$('#ucm-group-sync-job').on('change',function(){loadJob(jobs.find(function(job){return Number(job.id)===Number($('#ucm-group-sync-job').val());})||null);});$('#ucm-group-sync-mode').on('change',visibility);$('#ucm-group-sync-save').on('click',function(){save(false);});$('#ucm-group-sync-run').on('click',function(){save(true);});$('#ucm-group-sync-delete').on('click',function(){if(!currentJob||!window.confirm('Delete this group sync job? Destination content is preserved.'))return;syncEditor.mutate({command:'configurationsync',op:'delete',id:currentJob.id});});visibility();selectionSummary();
});</script>
