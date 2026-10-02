<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$module = $read('Ucmprovisioner.class.php');
$settings = $read('views/settings.php');
$phones = $read('views/phones.php');
$sites = $read('views/sites.php');
$install = $read('install.php');
$glue = $read('lib/AsteriskGlue.php');
$mesh = $read('lib/PublishMesh.php');
$sync = $read('lib/ConfigurationSyncService.php');

$pageInitStart = strpos($module, 'public function doConfigPageInit');
$pageInitEnd = strpos($module, 'public function getActionBar', $pageInitStart ?: 0);
$pageInit = ($pageInitStart !== false && $pageInitEnd !== false) ? substr($module, $pageInitStart, $pageInitEnd - $pageInitStart) : '';
$expect($pageInit !== '' && !str_contains($pageInit, 'needreload()'), 'Module-local form saves must not create a global Apply Config marker');
$expect(!str_contains($glue, 'needreload()') && !str_contains($mesh, 'needreload()'), 'Targeted Asterisk reload services must not also leave a global Apply Config marker');

$expect(str_contains($settings, 'name="settings[cisco_cnf_sip_port]"') && str_contains($settings, 'name="settings[cisco_cnf_transport]"'), 'Settings must expose the separate Cisco CNF endpoint default');
$expect(str_contains($phones, 'Cisco 7940/7960 SIP firmware (UDP only)') || str_contains($phones, 'cisco_cnf_sip_port'), 'Phone UI must show the Cisco CNF endpoint source');

foreach (['phone_rebuild_schedule_enabled','phone_rebuild_schedule_json','phone_rebuild_reboot'] as $key) {
    $expect(str_contains($module, $key) && str_contains($settings, $key) && str_contains($phones, $key), "Shared phone automation setting $key is incomplete");
}
$expect(str_contains($install, 'cli_phone_rebuild.php') && str_contains($module, 'runScheduledPhoneRebuild'), 'Scheduled rebuild needs an installed runner and bounded service method');
$expect(str_contains($sync, 'settings_phone_automation') && str_contains($sites, 'settings_phone_automation'), 'Phone automation must be selectable in both Settings and the one-stop site sync editor');

$expect(str_contains($module, "case 'exportconfiguration':") && str_contains($module, "case 'importconfiguration':"), 'Authenticated configuration package actions must be routed');
$expect(str_contains($settings, 'id="ucm-configuration-package"') && str_contains($settings, 'archive_scopes[]'), 'Settings must expose per-tab configuration package exclusions');

echo "Apply-Config, Cisco CNF, group navigation, phone automation and configuration-package contracts passed\n";
