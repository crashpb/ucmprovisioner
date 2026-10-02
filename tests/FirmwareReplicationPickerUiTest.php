<?php
declare(strict_types=1);

$view = (string)file_get_contents(dirname(__DIR__) . '/views/firmware.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expect(str_contains($view, 'id="ucm-fw-package-search"'), 'Firmware replication must expose a package search field');
$expect(str_contains($view, 'id="ucm-fw-package-select-visible"'), 'Firmware replication must expose Select visible for packages');
$expect(str_contains($view, 'id="ucm-fw-package-select-all"'), 'Firmware replication must expose Select all for packages');
$expect(str_contains($view, 'id="ucm-fw-package-clear"'), 'Firmware replication must expose Clear for packages');
$expect(str_contains($view, 'id="ucm-fw-target-search"'), 'Firmware replication must expose a destination search field');
$expect(str_contains($view, 'id="ucm-fw-target-select-visible"'), 'Firmware replication must expose Select visible for destinations');
$expect(str_contains($view, 'class="ucm-fw-picker-selected"') && !str_contains($view, 'input-group-addon" id="ucm-fw-target-count"'), 'Firmware picker counts must remain bounded badges instead of wrapping as Bootstrap input add-ons');
$expect(!str_contains($view, "message(r.message||'Firmware replication job saved',!r.ok)"), 'Successful firmware job saves must not be rendered as error feedback');
$expect(str_contains($view, 'id="ucm-fw-target-select-all"'), 'Firmware replication must expose Select all for destinations');
$expect(str_contains($view, 'id="ucm-fw-target-clear"'), 'Firmware replication must expose Clear for destinations');
$expect(str_contains($view, 'selectedFirmwarePackagesBySite'), 'Package selections must remain isolated and persistent per source site');
$expect(str_contains($view, 'filterReplicationPackages'), 'Package filtering must be client-side and preserve staged selections');
$expect(!str_contains($view, "selectedFirmwarePackages={};refreshSyncTargets();refreshFirmwareJobTargets();refreshInventory();"), 'Changing source site must not discard staged package selections');
$expect(str_contains($view, 'id="ucm-fw-include-all-firmware"'), 'Firmware replication must expose an explicit all-firmware scope');
$expect(str_contains($view, 'id="ucm-fw-include-all-timezones"'), 'Firmware replication must expose retained timezone versions as an independent scope');
$expect(str_contains($view, 'id="ucm-fw-include-all-locales"'), 'Firmware replication must expose Cisco locale companions as an independent scope');
$expect(str_contains($view, 'id="ucm-fw-replication-user-locale"') && str_contains($view, 'id="ucm-fw-replication-network-locale"'), 'Saved jobs must retain their locale-directory context');
$expect(str_contains($view, 'id="ucm-fw-select-entire-inventory"'), 'The complete Firmware-tab inventory must be selectable with one action');
$expect(str_contains($view, 'firmwareInventoryScope()'), 'Manual and recurring replication must serialize the same complete inventory-scope controls');
$expect(str_contains($view, 'Make the source active set (or exact selection) active on each destination'), 'All-timezone replication must expose source-active activation without requiring an exact fingerprint');
$expect(!str_contains($view, 'ucm-fw-replication-timezone-radio'), 'Rollback history must not contain a second competing timezone radio selector');
$expect(str_contains($view, 'Legacy canonical-root captures') && str_contains($view, 'preserve migration and root-file rollback'), 'Retained timezone versions must remain available as secondary rollback history');

echo "Firmware replication picker UI assertions passed\n";
