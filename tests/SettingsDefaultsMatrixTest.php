<?php
declare(strict_types=1);

$settings = (string)file_get_contents(dirname(__DIR__) . '/views/settings.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($settings, 'id="ucm-default-asset-workbench"')
    && str_contains($settings, 'data-default-kind="firmware"')
    && str_contains($settings, 'data-default-kind="cisco_timezone"')
    && str_contains($settings, 'data-default-kind="cisco_wallpaper"')
    && str_contains($settings, 'data-default-kind="hosted_wallpaper"')
    && str_contains($settings, 'id="ucm-default-asset-body"')
    && str_contains($settings, 'ucm-default-asset-choice')
    && str_contains($settings, 'id="ucm-default-asset-reload"')
    && str_contains($settings, 'id="ucm-save-site-defaults"'),
    'Site defaults must use one inline compatibility matrix and one final save action'
);
$expect(
    str_contains($settings, 'firmware_defaults')
    && str_contains($settings, 'cisco_timezone_defaults')
    && str_contains($settings, 'cisco_wallpaper_defaults')
    && str_contains($settings, 'hosted_image_defaults')
    && str_contains($settings, 'defaultAssetState.dirty')
    && str_contains($settings, 'Switching tabs will not discard them')
    && str_contains($settings, "$('form.fpbx-submit').on('submit.ucmpAssetDraft',save)"),
    'All matrix tabs must share one durable draft that serializes every hidden site-default map on submit'
);
$expect(
    str_contains($settings, 'Template override → this site default → device fallback')
    && str_contains($settings, 'Site unreachable — selection preserved')
    && str_contains($settings, 'staged/saved, currently unavailable')
    && str_contains($settings, 'defaultAssetState.firmware=null')
    && str_contains($settings, 'defaultAssetState.timezone=null')
    && str_contains($settings, 'defaultAssetState.wallpapers=null'),
    'The matrix must explain precedence and preserve saved or staged values when inventory is offline'
);
$expect(
    !str_contains($settings, 'ucm-default-fw-profile')
    && !str_contains($settings, 'ucm-default-bg-profile')
    && !str_contains($settings, 'ucm-default-hosted-family'),
    'Obsolete one-profile-at-a-time selectors must not remain beside the matrix'
);
$expect(
    str_contains($settings, "String(asset.profile||'')===String(profile.id)")
    && str_contains($settings, 'ModelCatalog::hostedMediaProfileKey'),
    'PBX-hosted defaults must retain the exact shared Media profile identity; ui-usability.js exercises request routing'
);
$expect(
    str_contains($settings, 'var hostedGroups={},hostedOrder=[]')
    && str_contains($settings, "String(asset.url)===String(current||'')")
    && str_contains($settings, "group.count+' compatible model libraries'"),
    'PBX-hosted selectors must collapse per-model delivery copies while preserving the exact saved URL as the representative'
);
$expect(
    str_contains($settings, 'name="settings[default_phonebook_key]"')
    && str_contains($settings, 'Generated-cache origin')
    && str_contains($settings, 'Copy selected policy to local AD')
    && str_contains($settings, "command:'phonebookcopy'"),
    'Settings must expose a per-site generated phonebook default, its source, and a credential-free local-policy copy action'
);
$expect(
    str_contains($settings, "\$select('cisco_transport'")
    && str_contains($settings, 'name="settings[cisco_sip_port]"')
    && str_contains($settings, 'name="settings[cisco_cnf_transport]"')
    && str_contains($settings, 'name="settings[cisco_cnf_sip_port]"')
    && str_contains($settings, "\$select('non_cisco_driver'")
    && str_contains($settings, "\$select('non_cisco_transport'")
    && str_contains($settings, 'name="settings[non_cisco_sip_port]"')
    && !str_contains($settings, 'name="settings[transport]"')
    && !str_contains($settings, 'name="settings[sip_port]"'),
    'Settings must expose separate Cisco XML, Cisco CNF and non-Cisco endpoint defaults without the obsolete shared controls'
);
$templates = (string)file_get_contents(dirname(__DIR__) . '/views/templates.php');
$expect(
    !str_contains($templates, 'data-key="transport"')
    && str_contains($templates, 'Phones override → vendor-class site default'),
    'Templates must describe endpoint inheritance without storing a competing transport override'
);

echo "Settings default assignment matrix assertions passed\n";
