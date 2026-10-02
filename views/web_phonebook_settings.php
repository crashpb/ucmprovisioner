<?php
declare(strict_types=1);
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$webDirectory = new \FreePBX\modules\Ucmprovisioner\WebPhonebook();
$webPolicyError = false;
$webCatalogError = false;
try {
    $webSnapshot = $webDirectory->policySnapshot();
    $webPolicy = $webSnapshot['policy'];
    $webRevision = $webSnapshot['revision'];
    $webManagement = $webSnapshot['management'];
} catch (\Throwable $e) { $webPolicyError = true; }
try { $webBooks = $webDirectory->availableBooks(); }
catch (\Throwable $e) { $webBooks = []; $webCatalogError = true; }
$webEscape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<section class="ucm-card" id="ucm-web-phonebook-settings">
  <div class="ucm-card-heading"><div><h3>Web phonebook</h3><p>Choose the phonebooks, fields and networks available to employees.</p></div><a class="btn btn-default btn-sm" href="/ucmprovisioner-phonebook.php" target="_blank" rel="noopener noreferrer">Open phonebook</a></div>
  <?php if ($webPolicyError): ?>
    <p class="alert alert-warning">The private web-phonebook policy cannot be read. Web requests are denied. Ask the system administrator to inspect the policy file and its permissions before saving new access grants.</p>
  <?php else: ?>
  <?php if ($webManagement): ?>
    <div class="alert <?php echo !empty($webManagement['hard_lock'])?'alert-info':'alert-warning'; ?>">
      <strong>Managed from <?php echo $webEscape($webManagement['source_site_name'] ?? $webManagement['source_site_key']); ?></strong>
      — <?php echo !empty($webManagement['hard_lock'])?'Hard locked: only the source job can change this policy.':'Source default: a local save pauses automatic updates for this policy.'; ?>
      <div>Status: <?php echo $webEscape($webManagement['status']); ?> · Source key: <?php echo $webEscape($webManagement['source_site_key']); ?> · Job: <?php echo $webEscape($webManagement['job_key']); ?></div>
      <small>Change or release ownership in the controlling PBX's Configuration sync job. Disabling its schedule does not release the lock.</small>
    </div>
  <?php endif; ?>
  <p class="help-block">To use one site's settings across sites, choose <strong>Web-directory settings</strong> in <a href="config.php?display=ucmprovisioner&amp;tab=sites&amp;view=configuration-sync">Configuration sync</a>. Settings do not publish contact data or configure DNS/TLS.</p>
  <?php if ($webCatalogError): ?><p class="alert alert-warning">The published phonebook catalog cannot be read. Check directory publication. You can still uncheck Enable web access and save to withdraw access.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" action="config.php?display=ucmprovisioner&amp;tab=directory#ucm-web-phonebook-settings">
    <fieldset <?php echo !empty($webManagement['hard_lock'])?'disabled':''; ?>>
    <input type="hidden" name="display" value="ucmprovisioner"><input type="hidden" name="tab" value="directory"><input type="hidden" name="action" value="savewebphonebook">
    <input type="hidden" name="web_phonebook_revision" value="<?php echo $webEscape($webRevision); ?>">
    <input type="hidden" name="web_phonebook[enabled]" value="0">
    <input type="hidden" name="web_phonebook[fields][]" value="name"><input type="hidden" name="web_phonebook[fields][]" value="extension">
    <div class="ucm-web-access-controls">
      <label class="ucm-toggle-chip"><input type="checkbox" name="web_phonebook[enabled]" value="1" <?php echo $webPolicy['enabled']?'checked':''; ?>> Enable web phonebook</label>
      <input type="hidden" name="web_phonebook[export_enabled]" value="0"><label class="ucm-toggle-chip"><input type="checkbox" name="web_phonebook[export_enabled]" value="1" <?php echo $webPolicy['export_enabled']?'checked':''; ?>> Allow Excel export</label>
    </div>
    <div class="row">
      <div class="col-md-6 form-group"><label for="ucmp-web-books">Published phonebooks</label>
        <select class="form-control ucm-web-multiselect" id="ucmp-web-books" name="web_phonebook[books][]" multiple size="6" title="Choose phonebooks" aria-describedby="ucmp-web-books-help">
          <?php foreach ($webBooks as $webBook): ?><option value="<?php echo $webEscape($webBook['key']); ?>" <?php echo in_array($webBook['key'],$webPolicy['books'],true)?'selected':''; ?>><?php echo $webEscape($webBook['name']); ?></option><?php endforeach; ?>
        </select>
        <?php if (!$webBooks): ?><p class="help-block">No phonebook is published on this PBX yet. Publish one before enabling web access.</p><?php endif; ?>
        <p class="help-block" id="ucmp-web-books-help">Search and select multiple books. Selected books stay at the top.</p>
      </div>
      <div class="col-md-6 form-group"><label for="ucmp-web-fields">Visible fields</label>
        <select class="form-control ucm-web-multiselect" id="ucmp-web-fields" name="web_phonebook[fields][]" multiple size="6" title="Choose fields">
        <?php foreach (\FreePBX\modules\Ucmprovisioner\WebPhonebook::FIELDS as $webField=>$webLabel): $required=in_array($webField,['name','extension'],true); ?>
          <option value="<?php echo $webEscape($webField); ?>" <?php echo in_array($webField,$webPolicy['fields'],true)?'selected':''; ?> <?php echo $required?'disabled':''; ?>><?php echo $webEscape($webLabel); ?><?php echo $required?' (required)':''; ?></option>
        <?php endforeach; ?>
        </select>
        <p class="help-block">Name and extension are required. Unselected fields are not sent to readers or exports.</p>
      </div>
    </div>
    <div class="row">
      <div class="col-md-6 form-group"><label for="ucmp-web-address">Phonebook address</label><input class="form-control" id="ucmp-web-address" name="web_phonebook[preferred_url]" value="<?php echo $webEscape($webPolicy['preferred_url']); ?>" placeholder="https://directory.example.com/"><p class="help-block">Optional employee-facing HTTPS address. Leave blank to use the PBX's own phonebook link. Configure DNS and TLS separately.</p></div>
      <div class="col-md-6 form-group"><label for="ucmp-web-networks">Allowed private / VPN networks</label><textarea class="form-control" id="ucmp-web-networks" name="web_phonebook[networks]" rows="3" placeholder="10.0.0.0/8"><?php echo $webEscape(implode("\n",$webPolicy['networks'])); ?></textarea><p class="help-block">One network per line. Everyone admitted by these networks can read the selected phonebooks without login.</p></div>
    </div>
    <section class="ucm-card">
      <h4>Directory appearance</h4>
      <p class="help-block">Browser title: Company Directory</p>
      <div class="row">
        <div class="col-md-6 form-group"><label for="ucmp-web-favicon">Browser favicon</label>
          <input type="file" id="ucmp-web-favicon" name="web_phonebook_favicon" accept="image/png,image/jpeg">
          <p class="help-block">PNG or JPEG, up to 1 MiB and 2048 pixels per side. Saved as a 64 × 64 PNG icon. Saving other settings keeps the current icon.</p>
          <?php if ($webPolicy['favicon'] !== ''): ?><p>Custom favicon uploaded.</p><label><input type="checkbox" name="web_phonebook_remove_favicon" value="1"> Remove current favicon</label><?php endif; ?>
        </div>
        <div class="col-md-6 form-group"><label for="ucmp-web-site-labels">Site display labels</label>
          <textarea class="form-control" id="ucmp-web-site-labels" name="web_phonebook[site_labels]" rows="5" placeholder="MAIN-PBX = Main office"><?php foreach ($webPolicy['site_labels'] as $original=>$label) { echo $webEscape($original.' = '.$label)."\n"; } ?></textarea>
          <p class="help-block">One original site name = display label per line. Used in directory results, site filters, printouts and exports. PBX site names stay unchanged.</p>
        </div>
      </div>
    </section>
    <details class="ucm-advanced" <?php echo $webPolicy['access_mode']==='trusted_proxy'?'open':''; ?>><summary>Advanced settings<?php echo $webPolicy['access_mode']==='trusted_proxy'?' — proxy mode active':''; ?></summary>
      <div class="row">
        <div class="col-md-6 form-group"><label>Connection mode</label><select class="form-control" name="web_phonebook[access_mode]"><?php foreach(['direct'=>'Private network / VPN','trusted_proxy'=>'Explicit proxy mode'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $webPolicy['access_mode']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><p class="help-block">Normal mode checks the actual connecting address and ignores forwarding headers.</p></div>
        <div class="col-md-6 form-group"><label>Policy label</label><input class="form-control" name="web_phonebook[policy_label]" value="<?php echo $webEscape($webPolicy['policy_label']); ?>"></div>
        <div class="col-md-6 form-group"><label>Exact proxy addresses</label><textarea class="form-control" name="web_phonebook[trusted_proxies]" rows="3"><?php echo $webEscape(implode("\n",$webPolicy['trusted_proxies'])); ?></textarea><p class="help-block">Used only in explicit proxy mode.</p></div>
        <div class="col-md-6 form-group"><label>Client address header</label><select class="form-control" name="web_phonebook[client_header]"><?php foreach(['HTTP_X_REAL_IP'=>'X-Real-IP','HTTP_X_FORWARDED_FOR'=>'X-Forwarded-For (one IP only)'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $webPolicy['client_header']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><p class="help-block">The front end must overwrite this header in explicit proxy mode.</p></div>
        <div class="col-md-6 form-group"><label>Help / correction address</label><input class="form-control" name="web_phonebook[help_url]" value="<?php echo $webEscape($webPolicy['help_url']); ?>" placeholder="HTTPS or mailto address"></div>
        <div class="col-md-6 form-group"><label>Alternate-name mapping</label><select class="form-control" name="web_phonebook[alternate_field]"><?php foreach(['label'=>'Canonical short label','name'=>'Canonical display name'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $webPolicy['alternate_field']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><p class="help-block">Used only when Alternate name is a selected field.</p></div>
      </div>
    </details>
    <?php if ($webManagement && empty($webManagement['hard_lock']) && ($webManagement['status'] ?? '') === 'active'): ?>
      <label class="checkbox"><input type="checkbox" name="sync_edit_confirmed" value="1" required> I understand that a local save pauses the source default until its job is resumed.</label>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Save web phonebook settings</button>
    </fieldset>
  </form>
  <?php endif; ?>
</section>
