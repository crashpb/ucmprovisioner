<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); } ?>
<details class="ucm-card ucm-setting-fields">
  <summary><strong>Individual Settings fields</strong> — choose exactly what to synchronize</summary>
  <p>Only fields in selected subsections are copied. Unchecked fields retain each destination’s values. URL fields copy the exact saved URL; select them only when destinations should use that address. PBX identity, connection credentials and extension membership stay site-specific.</p>
  <button type="button" class="btn btn-default btn-xs" data-setting-fields="all">Select all fields</button>
  <button type="button" class="btn btn-default btn-xs" data-setting-fields="none">Clear fields</button>
  <?php foreach (\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_FIELDS as $fieldScope=>$fieldKeys): ?>
    <fieldset><legend><?php echo htmlspecialchars(ucwords(str_replace('_',' ',substr($fieldScope,9)))); ?></legend>
      <?php foreach ($fieldKeys as $fieldKey):
          if (str_ends_with($fieldKey,'_interval_minutes')) { continue; }
          $companion = str_ends_with($fieldKey,'_schedule_json') ? str_replace('_schedule_json','_interval_minutes',$fieldKey) : '';
          $fieldLabel = ucwords(str_replace('_',' ',str_replace('_schedule_json','_schedule_cadence',$fieldKey)));
      ?>
        <label class="checkbox-inline" title="<?php echo htmlspecialchars($companion ? 'Schedule and its compatibility interval are synchronized together' : $fieldKey); ?>"><input type="checkbox" class="ucm-setting-field" value="<?php echo htmlspecialchars($fieldKey); ?>" data-companion="<?php echo htmlspecialchars($companion); ?>" <?php echo in_array($fieldKey,\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ADDRESS_FIELDS,true)?'':'checked'; ?>> <?php echo htmlspecialchars($fieldLabel); ?></label>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
</details>
<script>
window.ucmpSettingsFieldsLoad=function(job){var keys=job&&job.setting_keys;jQuery('.ucm-setting-field').each(function(){this.checked=Array.isArray(keys)?keys.indexOf(this.value)!==-1||keys.indexOf(this.dataset.companion)!==-1:<?php echo json_encode(\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ADDRESS_FIELDS); ?>.indexOf(this.value)===-1;});};
window.ucmpSettingsFieldsRead=function(){var keys=[];jQuery('.ucm-setting-field:checked').each(function(){keys.push(this.value);if(this.dataset.companion)keys.push(this.dataset.companion);});return keys;};
jQuery(function($){$('[data-setting-fields]').on('click',function(){$('.ucm-setting-field').prop('checked',$(this).data('setting-fields')==='all').trigger('change');});});
</script>
