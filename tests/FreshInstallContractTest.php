<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$read = static fn(string $relative): string => (string)file_get_contents($root . '/' . $relative);

$module = $read('module.xml');
$install = $read('install.php');
$uninstall = $read('uninstall.php');
$guide = $read('docs/FRESH-INSTALL.md');

$expect(str_contains($module, '<phpversion>8.2.0</phpversion>'), 'Fresh-install PHP requirement drifted');
$expect(str_contains($module, '<module>framework ge 17.0.1</module>'), 'Framework dependency drifted');
$expect(str_contains($module, '<module>core ge 17.0.1</module>'), 'Core dependency drifted');
foreach (['tftp_mode','tftp_pending_mode','pbx_tftp_service_host','pbx_tftp_root_path','pbx_tftp_bind_path','pbx_tftp_daemon','pbx_tftp_bootstrapped_at'] as $field) {
    $expect(str_contains($module, '<field name="' . $field . '"'), "Fresh-install site schema omits $field");
}
$expect(str_contains($install, "'pbx', ?, '/tftpboot', '/srv/tftp/tftpboot', 'tftpd-hpa'"), 'Fresh local site does not select the PBX-hosted TFTP profile');

preg_match_all('/<table\s+name="([a-z0-9_]+)"/', $module, $tableMatches);
$tables = $tableMatches[1] ?? [];
$expect($tables !== [] && count($tables) === count(array_unique($tables)), 'Fresh-install table declarations are absent or duplicated');
foreach (['ucmp_sites','ucmp_phones','ucmp_templates','ucmp_groups','ucmp_settings','ucmp_assets','ucmp_phone_builds'] as $table) {
    $expect(in_array($table, $tables, true), "Fresh-install schema omits $table");
    $expect(str_contains($uninstall, "'$table'"), "Uninstall hook omits $table");
}

$publicEndpoints = [
    'ucmprovisioner-directory.php'=>'directory.php',
    'ucmp-d.php'=>'directory.php',
    'ucmp-s.php'=>'phone_services.php',
    'ucmprovisioner-services.php'=>'phone_services.php',
    'ucmp-m.php'=>'media_asset.php',
    'ucmprovisioner-media.php'=>'media_asset.php',
    'ucmprovisioner-api.php'=>'api.php',
    'ucmprovisioner-phonebook.php'=>'web_phonebook.php',
];
foreach ($publicEndpoints as $name=>$target) {
    $expect(str_contains($install, "'$name' => __DIR__ . '/$target'"), "Install hook omits public endpoint $name");
    $expect(str_contains($uninstall, "/var/www/html/$name"), "Uninstall hook omits public endpoint $name");
}

foreach (['cli_ad_sync.php','cli_mesh_sync.php','cli_phone_rebuild.php','cli_media_sync.php'] as $dispatcher) {
    $expect(is_file($root . '/' . $dispatcher), "Scheduled dispatcher is missing: $dispatcher");
    $expect(str_contains($install, "/$dispatcher"), "Install hook does not schedule $dispatcher");
}
foreach ([
    'resources/ucmp-fail2ban'=>'/usr/local/sbin/ucmp-fail2ban',
    'resources/ucmp-patch-manager'=>'/usr/local/sbin/ucmp-patch-manager',
] as $source=>$target) {
    $expect(is_file($root . '/' . $source), "Privileged helper entry point is missing: $source");
    $expect(str_contains($install, $target), "Install hook omits privileged helper $target");
    $expect(str_contains($uninstall, $target), "Uninstall hook omits privileged helper $target");
}

$expect(is_file($root . '/vendor/autoload.php'), 'Production package lacks its locked dependency autoloader');
$expect(str_contains($install, "'/var/lib/asterisk/ucmprovisioner/api-nonces'"), 'Replay-protection storage is not created');
$expect(str_contains($install, "'/etc/cron.d/ucmprovisioner-ad'"), 'Scheduled-job file is not installed');
$releaseVersion = trim((string)simplexml_load_string($module)->version);
$expect(str_contains($guide, 'ucmprovisioner-' . $releaseVersion . '.tgz'), 'Fresh-install guide names the wrong package');
$expect(str_contains($guide, 'Asterisk 22') && str_contains($guide, 'Asterisk 20'), 'Fresh-install guide omits the Asterisk compatibility boundary');
$expect(str_contains($guide, 'sha256sum -c'), 'Fresh-install guide omits package verification');
$expect(str_contains($guide, 'fwconsole ma install ucmprovisioner'), 'Fresh-install guide omits the install command');
$expect(str_contains($guide, 'PBX-hosted TFTP') && str_contains($guide, 'tftpd-hpa') && str_contains($guide, 'UDP/69'), 'Fresh-install guide omits the PBX-hosted TFTP bootstrap boundary');

echo "Fresh-install schema, endpoints, schedules, helpers and guide are coherent\n";
