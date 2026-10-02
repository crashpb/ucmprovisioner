<?php
/** Dashboard view. $ucm is in scope. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$s = $ucm->getStats();
$ucmPatch = $ucm->ucmPatchStatus();
$cards = [
    ['phones', _('Phones'), $s['phones']],
    ['bound', _('Bound / Provisioned'), $s['bound']],
    ['discovered', _('Discovered (unbound)'), $s['discovered']],
    ['unassigned', _('Pre-provisioned (spare, no ext)'), $s['unassigned']],
    ['sites', _('Sites'), $s['sites']],
    ['templates', _('Templates'), $s['templates']],
    ['groups', _('Groups'), $s['groups']],
    ['directory', _('Directory contacts'), $s['directory']],
];
?>
<div class="ucm-container">
  <?php if (empty($ucmPatch['eligible'])): ?>
    <div class="alert alert-danger"><strong><?php echo _('Cisco glue apply is blocked on this PBX.'); ?></strong> <?php echo htmlspecialchars((string)($ucmPatch['message'] ?? 'The USECALLMANAGER runtime preflight failed.')); ?> <?php if(empty($ucmPatch['chan_sip_loaded'])): ?><?php echo _('chan_sip.so is not loaded.'); ?><?php endif; ?> <?php echo _('Registration and ordinary calling may still work, but the module will not write Cisco BLF glue until Asterisk, chan_sip, and SIPCiscoPage are all verified.'); ?><?php if(!empty($ucmPatch['asterisk_version'])): ?> <small><?php echo htmlspecialchars($ucmPatch['asterisk_version']); ?></small><?php endif; ?></div>
  <?php else: ?>
    <div class="alert alert-success"><strong><?php echo _('Cisco USECALLMANAGER runtime verified.'); ?></strong> <?php echo _('Asterisk, chan_sip.so, and SIPCiscoPage passed the server-side apply preflight.'); ?> <small><?php echo htmlspecialchars((string)$ucmPatch['asterisk_version']); ?></small></div>
  <?php endif; ?>
  <?php if (!empty($s['directory_conflicts'])): ?>
    <div class="alert alert-warning"><strong><?php echo (int)$s['directory_conflicts']; ?> AD directory conflict(s) need review.</strong> No conflicting identity was applied. <a href="config.php?display=ucmprovisioner&amp;tab=directory">Open Directory &amp; AD</a>.</div>
  <?php endif; ?>
  <div class="row">
    <?php foreach ($cards as $c): ?>
      <div class="col-sm-2 col-xs-6">
        <div class="panel panel-default" style="text-align:center;">
          <div class="panel-body">
            <div style="font-size:28px;font-weight:bold;"><?php echo (int)$c[2]; ?></div>
            <div class="text-muted"><?php echo $c[1]; ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="row">
    <div class="col-sm-12">
      <button class="btn btn-default ucm-action" data-command="discover"><?php echo _('Discover local extensions'); ?></button>
      <button class="btn btn-default ucm-meshsync" data-apply="0"><?php echo _('Preview BLF federation'); ?></button>
      <button class="btn btn-primary ucm-rebuild" data-scope="all" data-site="<?php echo (int)$ucm->localSiteId(); ?>"><?php echo _('Rebuild all local-site configs'); ?></button>
      <span id="ucm-action-result" class="text-muted" style="margin-left:10px;"></span>
    </div>
  </div>
  <div class="row" style="margin-top:15px;">
    <div class="col-sm-12">
      <pre id="ucm-detail-result" style="display:none;max-height:40vh;overflow:auto;"></pre>
    </div>
  </div>
</div>

