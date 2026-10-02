<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$install = $read('install.php');
$directoryService = $read('lib/DirectoryService.php');
$directory = $read('views/directory.php');
$media = $read('views/media.php');
$firmware = $read('views/firmware.php');
$phones = $read('views/phones.php');
$settings = $read('views/settings.php');
$patches = $read('views/patches.php');
$page = $read('page.ucmprovisioner.php');
$css = $read('assets/css/ucmprovisioner.css');

$expect(substr_count($install, '* * * * * asterisk /usr/bin/php') === 4, 'Every recurring module runner must tick once per minute');
$expect(!str_contains($install, '*/5 * * * *'), 'The retired five-minute cron cadence must not remain in the installer');
$expect(!preg_match("/'blf_mesh_schedule_json'\\s*=>/", $install), 'Install must not seed a granular BLF schedule independently from the existing interval');
$expect(str_contains($install, '$seededMeshSchedule') && str_contains($install, '$legacyInterval'), 'Upgrade must repair the earlier seeded-schedule conflict');
$expect((bool)preg_match("/'ad_sync_schedule_json'\\s*=>\\s*''/", $directoryService), 'Legacy AD must defer to its stored interval until a granular schedule is saved');

foreach (['directory'=>$directory, 'media'=>$media, 'phones'=>$phones, 'settings'=>$settings] as $name=>$view) {
    $expect(str_contains($view, 'data-schedule-mode'), ucfirst($name) . ' must expose schedule cadence');
    $expect(str_contains($view, 'data-schedule-time'), ucfirst($name) . ' must expose an exact PBX-local run time');
    $expect(str_contains($view, 'data-schedule-weekday'), ucfirst($name) . ' must expose weekday selection');
}
$expect(str_contains($media, 'interval_minutes:schedule.interval_minutes'), 'Media replication must submit its selected minute interval');
$expect(str_contains($media, 'refreshMediaSchedule();'), 'Media replication schedule fields must initialize before operator interaction');
$expect(str_contains($firmware, 'id="ucm-firmware-replication"') && str_contains($firmware, "command:'firmwarereplication'"), 'Firmware must expose a dedicated exact-package replication work area and service endpoint');
$expect(str_contains($firmware, 'data-schedule-mode') && str_contains($firmware, 'data-schedule-time') && str_contains($firmware, 'data-schedule-weekday'), 'Firmware replication must expose manual, interval, daily and weekly schedule controls');
$expect(str_contains($firmware, 'packages:JSON.stringify(packages)') && str_contains($firmware, 'timezone_bundle_key:timezone'), 'Firmware replication must submit exact package and retained timezone selections');
$expect(str_contains($firmware, 'sync_timezone_version') && str_contains($firmware, 'without activating it'), 'Firmware replication UI must distinguish retained timezone copies from canonical activation');
$expect(str_contains($firmware, 'selectedFirmwarePackages') && str_contains($firmware, 'Unavailable on source inventory'), 'Firmware package choices must survive compatibility filters and show unavailable saved selections');
$expect(str_contains($firmware, 'firmwareSourceBundles') && !str_contains($firmware, 'if(profiles.length===1)req.profile'), 'Replication choices must use source-wide inventory instead of the current brand/model filter');
$expect(str_contains($settings, 'name="settings[dial_template_file]"'), 'Settings must retain the optional operator-selected dial-template control');
$expect(!str_contains($settings, 'name="settings[softkey_file]"') && !str_contains($settings, 'name="settings[featurepolicy_file]"'), 'Generated Cisco policy companions must not remain misleading editable filenames');

foreach (['groups','sites','directory','patches','firmware','services','fail2ban','tftp','settings'] as $tab) {
    $expect(str_contains($page, "'{$tab}'"), "Unified administration shell must include {$tab}");
}
$expect(str_contains($page, 'class="ucmp-admin-v3"'), 'Unified pages must use the shared administration shell');
$expect(str_contains($css, '.ucmp-admin-v3'), 'The shared administration shell must have scoped styling');
$expect(!preg_match('/\\$active\\s*=/', $patches), 'A child view must not overwrite the page router active-tab variable');

echo "Recurring schedule and unified administration UI fixtures passed\n";
