<?php
/**
 * Remove module-owned database tables, scheduled jobs, public endpoints and
 * privileged helper programs. Generated Asterisk/TFTP files and Patch Manager
 * rollback bundles are deliberately preserved so uninstalling the web module
 * cannot unexpectedly take working phones or a running Asterisk build offline.
 * For the same reason, a module-bootstrapped tftpd-hpa service, its /srv/tftp
 * tree and the restricted ucmp-tftp account are left in place.
 */
if (!class_exists('\\FreePBX')) {
    return;
}
$db = \FreePBX::Database();
foreach ([
    'ucmp_phone_builds', 'ucmp_apply_transactions',
    'ucmp_rpc_operations', 'ucmp_operator_changes', 'ucmp_asset_scan_receipts',
    'ucmp_replication_runs', 'ucmp_configuration_sync_items', 'ucmp_configuration_sync_jobs', 'ucmp_media_replication_jobs', 'ucmp_firmware_replication_jobs', 'ucmp_site_jobs', 'ucmp_site_snapshots', 'ucmp_asset_scan_jobs', 'ucmp_assets',
    'ucmp_timezone_bundles', 'ucmp_firmware_bundles', 'ucmp_phonebooks',
    'ucmp_directory_conflicts', 'ucmp_directory_entries',
    'ucmp_directory_sync_runs', 'ucmp_directory_sources',
    'ucmp_identity_overrides', 'ucmp_extensions',
    'ucmp_default_template_rules', 'ucmp_phone_services',
    'ucmp_operation_log', 'ucmp_phone_mac_suppressions', 'ucmp_phones', 'ucmp_groups',
    'ucmp_templates', 'ucmp_models', 'ucmp_settings', 'ucmp_sites',
] as $t) {
    try {
        $db->query("DROP TABLE IF EXISTS `$t`");
    } catch (\Throwable $e) {
        // Continue when a table is already absent or an older installation never created it.
    }
}
@unlink('/etc/cron.d/ucmprovisioner-ad');
foreach ([
    '/usr/local/sbin/ucmp-fail2ban',
    '/usr/local/libexec/ucmp-fail2ban-helper.php',
    '/etc/sudoers.d/ucmp-fail2ban',
    '/etc/sudoers.d/ucmprovisioner-fail2ban',
    '/usr/local/sbin/ucmp-patch-manager',
    '/usr/local/libexec/PatchCatalog.php',
    '/usr/local/libexec/AsteriskVersionManager.php',
    '/usr/local/libexec/PatchManagerHelper.php',
    '/usr/local/libexec/ucmp-patch-manager-helper.php',
    '/usr/local/libexec/ucmp-patch-catalog.php',
    '/etc/sudoers.d/ucmp-patch-manager',
] as $privilegedFile) {
    if (is_file($privilegedFile) || is_link($privilegedFile)) { @unlink($privilegedFile); }
}
foreach (['/var/www/html/ucmprovisioner-phonebook.php','/var/www/html/ucmprovisioner-directory.php','/var/www/html/ucmp-d.php','/var/www/html/ucmp-s.php','/var/www/html/ucmprovisioner-services.php','/var/www/html/ucmp-m.php','/var/www/html/ucmprovisioner-media.php','/var/www/html/ucmprovisioner-api.php'] as $endpoint) {
    $content = is_file($endpoint) ? (string)@file_get_contents($endpoint) : '';
    if (strpos($content, 'UCM Provisioner public endpoint') !== false) { @unlink($endpoint); }
}
out("UCM Provisioner: removed module tables and privileged helper executables. Generated phone files, a module-bootstrapped TFTP service/account, Asterisk manager state and version bundles remain preserved so active phones and Asterisk are not interrupted.");
