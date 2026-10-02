<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/views/phones.php');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$js = (string)file_get_contents($root . '/assets/js/ucmprovisioner.js');
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    'ucmp-phones-preview-v2', 'ucm-site-filter', 'ucm-refresh-status', 'ucm-action-result', 'ucm-phones',
    'ucm-backfill-macs', 'ucm-scan-site', 'ucm-scan-method', 'ucm-scan-cidr',
    'ucm-scan-go', 'ucm-scan-results',
] as $id) {
    $expect(substr_count($view, 'id="' . $id . '"') === 1, "#$id must exist exactly once");
}

foreach (['fleet', 'mesh', 'discovery'] as $panel) {
    $expect(
        substr_count($view, 'data-phone-tab="' . $panel . '"') === 1,
        "The $panel tab must exist exactly once"
    );
    $expect(
        substr_count($view, 'data-phone-panel="' . $panel . '"') === 1,
        "The $panel panel must exist exactly once"
    );
}
$expect(!str_contains($view, 'data-phone-tab="build"'), 'The duplicate Build tab must not return');
$expect(!str_contains($view, 'data-phone-panel="build"'), 'The duplicate Build panel must not return');

foreach ([
    'class="pp2-btn pp2-primary ucm-action" data-command="discover"',
    'class="pp2-btn pp2-primary ucm-rebuild" data-scope="selected"',
    'data-scope="all"',
    'class="pp2-btn ucm-restart-selected"',
    'class="pp2-btn pp2-primary ucm-meshsync"',
    'data-cmd="restart"',
    'data-cmd="reboot"',
] as $contract) {
    $expect(str_contains($view, $contract), "Missing preserved Phones action: $contract");
}

$expect(str_contains($view, 'colspan="11"'), 'The empty fleet row must span the table including Phone IP');
$expect(str_contains($view, "_('SIP endpoint')") && str_contains($view, "_('Phone IP')"), 'Fleet inventory must expose both the endpoint tuple and prominent IP column');
$expect(str_contains($view, "EndpointProfile::resolve(\$p"), 'Fleet endpoint values must use the same resolver as generation and save');
$expect(!str_contains($view, '<div class="ucm-toolbar">'), 'The obsolete unclosed Phones toolbar must not return');
$expect(!str_contains($view, 'refreshPhoneInventory()'), 'Phones must not call the removed refreshPhoneInventory function');
$expect(!str_contains($view, 'ucm-phone-workspace-status'), 'Phones must use the shared visible action/status surface, not an orphan status strip');
$expect(str_contains($view, "function refreshInventory()"), 'Phones must retain its bounded inventory refresh function');
$expect(str_contains($view, "ucm:phone-command-complete', function () { refreshInventory(); }"), 'Commands must refresh through the real inventory function');
$expect(str_contains($js, '.ucm-phone-selection-count'), 'Bulk selection must update the visible selection count');
$expect(str_contains($js, "cmd: 'restart'"), 'Configuration reload must retain the existing backend command');
$expect(substr_count($view, 'data-scope="all"') === 1, 'Fleet must expose the real rebuild-all action exactly once');
$expect(str_contains($view, 'name="settings[blf_mesh_schedule_enabled]"'), 'BLF mesh must expose its schedule enable control on Phones');
$expect(str_contains($view, 'name="settings[blf_mesh_interval_minutes]"'), 'BLF mesh must expose its validated interval control on Phones');
$expect(str_contains($view, 'name="action" value="savesettings"'), 'BLF schedule must reuse the existing settings save path');
$expect(
    str_contains($view, 'id="ucmp-phones-preview-v2" class="ucm-container pp2-app"')
    && str_contains($css, '#ucmp-phones-preview-v2 {')
    && str_contains($css, '#ucmp-phones-preview-v2 .pp2-tabs')
    && str_contains($css, '#ucmp-phones-preview-v2 .pp2-view[hidden]'),
    'Phones workspace styling and hidden panels must remain root-scoped'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Phones workspace structural and action-contract assertions passed\n";
