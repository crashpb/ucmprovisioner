<?php
/**
 * Rebuild helper for an already-bootstrapped FreePBX CLI environment.
 *
 * argv[1] = selection: all, comma-separated phone IDs, or ext:<extension> (default: all)
 * argv[2] = apply, 1 or 0 (default: 0 / dry run)
 * argv[3] = site id (default: local site)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
if (!isset($amp_conf) && !class_exists('FreePBX')) {
    fwrite(STDERR, "Load /etc/freepbx.conf before running this helper.\n");
    exit(2);
}

$selection = isset($argv[1]) ? (string)$argv[1] : 'all';
$apply = isset($argv[2]) && in_array(strtolower((string)$argv[2]), ['apply', '1', 'true', 'yes'], true);
$siteId = isset($argv[3]) && $argv[3] !== '' ? (int)$argv[3] : null;

$ucm = \FreePBX::Ucmprovisioner();
$result = $ucm->rebuild($selection, false, $siteId);
if ($apply && !empty($result['ok']) && !empty($result['plan_hash'])) {
    $result = $ucm->rebuild(
        $selection,
        true,
        $siteId,
        false,
        true,
        (string)$result['plan_hash']
    );
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) || !empty($result['success']) ? 0 : 1);
