<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$groups = (string)file_get_contents($root . '/views/groups.php');
$settings = (string)file_get_contents($root . '/views/settings.php');
$shared = (string)file_get_contents($root . '/assets/js/ucmprovisioner.js');

$expect(
    str_contains($groups, 'class="ucm-group-sync-select"')
    && str_contains($groups, "scopes:['groups']")
    && str_contains($groups, "group_ids:window.ucmpRetainedSelection('.ucm-group-sync-select'")
    && str_contains($groups, 'class="ucm-group-sync-target"'),
    'Groups replication must submit exact selected group IDs and explicit destinations'
);
$expect(
    str_contains($groups, "command:'configurationsync'")
    && str_contains($groups, "payload('save')")
    && str_contains($groups, "syncEditor.save(p,thenRun)")
    && str_contains($groups, "op:'resume'")
    && str_contains($groups, "op:'delete'")
    && str_contains($shared, 'needs_confirmation'),
    'Groups replication must reuse the ConfigurationSync AJAX lifecycle and confirmation response'
);
$expect(
    str_contains($groups, 'manual')
    && str_contains($groups, 'interval')
    && str_contains($groups, 'daily')
    && str_contains($groups, 'weekly')
    && !str_contains($groups, 'changed_only')
    && !str_contains($groups, 'Changed-only')
    && str_contains($groups, 'soft_lock')
    && str_contains($groups, 'retry_unreachable')
    && str_contains($groups, 'missing_dependency')
    && str_contains($groups, 'owner_conflict'),
    'Groups replication must expose all cadence/protection options and destination failure states'
);

$expect(
    str_contains($settings, 'class="ucm-settings-sync-scope"')
    && str_contains($settings, 'settings_sip_service')
    && str_contains($settings, 'settings_blf_federation')
    && str_contains($settings, 'settings_clock_date')
    && str_contains($settings, 'id="ucm-settings-section-sip"')
    && str_contains($settings, 'id="ucm-settings-section-blf"')
    && str_contains($settings, 'id="ucm-settings-section-clock"'),
    'Settings replication scopes must map to visible SIP, BLF, and clock subsections'
);
$expect(
    str_contains($settings, 'scopes:sections')
    && str_contains($settings, 'settings_categories:sections')
    && str_contains($settings, 'class="ucm-settings-sync-target"'),
    'Settings replication must submit subsection filters and explicit destinations through ConfigurationSync'
);
$expect(
    str_contains($settings, 'settingsScopeDraft')
    && str_contains($settings, 'restoreSettingsScopes')
    && str_contains($settings, 'settings-jump')
    && str_contains($settings, 'value="manual"')
    && str_contains($settings, 'value="weekly"')
    && !str_contains($settings, 'changed_only')
    && !str_contains($settings, 'Changed-only')
    && str_contains($settings, 'soft_lock')
    && str_contains($settings, 'retry_unreachable')
    && str_contains($settings, 'missing_dependency')
    && str_contains($settings, 'owner_conflict'),
    'Settings replication must preserve staged subsection choices while exposing cadence, protection, and state'
);

echo "Configuration replication UI contract passed\n";
