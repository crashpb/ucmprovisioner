<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
$options = getopt('', ['site:','apply','confirm:']);
$siteId = max(0, (int)($options['site'] ?? 0));
$apply = array_key_exists('apply', $options);
if ($siteId < 1) { fwrite(STDERR, "Usage: reset-site-storage.php --site=ID [--apply --confirm=PRESERVE_PHONE_CONFIGS]\n"); exit(2); }
if ($apply && !hash_equals('PRESERVE_PHONE_CONFIGS', (string)($options['confirm'] ?? ''))) {
    fwrite(STDERR, "Apply requires --confirm=PRESERVE_PHONE_CONFIGS\n"); exit(2);
}

global $amp_conf, $bootstrap_settings;
$bootstrap_settings = ['freepbx_auth'=>false];
ob_start();
try { require_once '/etc/freepbx.conf'; }
finally { ob_end_clean(); }

$module = \FreePBX::Ucmprovisioner();
$tftp = $module->resetManagedTftpStorage($siteId, $apply);
$media = $module->mediaAssetOp([
    'op'=>'reset_media_library','site_id'=>$siteId,'apply'=>$apply ? 1 : 0,
    'confirm'=>$apply ? 'RESET_MEDIA_LIBRARY' : '',
]);
$result = [
    'ok'=>!empty($tftp['ok']) && !empty($media['ok']),
    'mode'=>$apply ? 'apply' : 'dry-run','site_id'=>$siteId,
    'tftp'=>$tftp,'http_media'=>$media,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
