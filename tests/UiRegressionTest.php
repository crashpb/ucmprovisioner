<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$phones = (string)file_get_contents($root . '/views/phones.php');
$directory = (string)file_get_contents($root . '/views/directory.php');
$directoryEndpoint = (string)file_get_contents($root . '/directory.php');
$javascript = (string)file_get_contents($root . '/assets/js/ucmprovisioner.js');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$install = (string)file_get_contents($root . '/install.php');
$fail2ban = (string)file_get_contents($root . '/views/fail2ban.php');
$services = (string)file_get_contents($root . '/views/services.php');
$serviceEndpoint = (string)file_get_contents($root . '/phone_services.php');
$templates = (string)file_get_contents($root . '/views/templates.php');
$groups = (string)file_get_contents($root . '/views/groups.php');
$media = (string)file_get_contents($root . '/views/media.php');
$firmware = (string)file_get_contents($root . '/views/firmware.php');
$settings = (string)file_get_contents($root . '/views/settings.php');
$patches = (string)file_get_contents($root . '/views/patches.php');
$patchInventory = (string)file_get_contents($root . '/lib/PatchInventoryService.php');
$patchCatalog = (string)file_get_contents($root . '/lib/PatchCatalog.php');
$networkScan = (string)file_get_contents($root . '/lib/NetworkScan.php');
$page = (string)file_get_contents($root . '/page.ucmprovisioner.php');
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$dispatchStart = strpos($module, 'public function remoteDispatch');
$dispatchEnd = strpos($module, '/* Federated wrappers */', $dispatchStart === false ? 0 : $dispatchStart);
$remoteDispatch = ($dispatchStart !== false && $dispatchEnd !== false)
    ? substr($module, $dispatchStart, $dispatchEnd - $dispatchStart)
    : '';

$expect(str_contains($phones, 'id="ucm-refresh-status"'), 'Phones toolbar must include the Refresh Status button used by its JavaScript');
$expect(str_contains($javascript, "command: 'commandbatch'"), 'Restart selected must use one server-side command batch');
$expect(str_contains($javascript, 'This is not a list of extensions watched by the selected phone.'), 'Rebuild preview must explain that site-wide glue endpoints are not one phone’s BLF targets');
$expect(str_contains($directory, 'hide_uncategorised') && str_contains($directory, 'search_position') && str_contains($directory, 'search_scope'), 'Phonebook preview payload must include visibility, placement and scope');
$expect(str_contains($directory, 'Legacy settings') && str_contains($directory, 'Automatic sync:'), 'The migrated legacy AD source must have one clear compatibility state instead of duplicate disabled badges');
$expect(str_contains($directory, 'cisco_softkeys') && str_contains($directory, 'Cisco 7940/7960') && str_contains($directory, 'ucm-preview-search-input'), 'Phonebook designer must expose Cisco softkey editing and an interactive multi-family preview');
$expect(!str_contains($directory, 'glyphicon') && str_contains($directory, 'ucm-icon-glyph'), 'Phonebook designer icons must not depend on the unavailable Bootstrap glyphicon font');
$expect(str_contains($directory, "keys=['Search','<<','Cancel']") && str_contains($directory, 'ABC Name, login or number') && str_contains($directory, '123 Ext, mobile or EMP No.'), 'Cisco preview must show the documented alphabetic and numeric search fields and actions');
$expect(str_contains($directoryEndpoint, "'Name, login or number (ABC)'") && str_contains($directoryEndpoint, "'Ext, mobile or EMP No. (123)'") && str_contains($directoryEndpoint, "'InputFlags','A'") && str_contains($directoryEndpoint, "'InputFlags','N'"), 'Published Cisco search XML must expose separate alphabetic and numeric input fields');
$expect(
    str_contains($directoryEndpoint, "implode(' / ',\$node['path']),32)")
    && str_contains($directoryEndpoint, "'Prompt','Name, login or number'")
    && strlen('Name, login or number') <= 32
    && strlen('Ext, mobile or EMP No. (123)') <= 32,
    'Cisco directory titles and search prompts must stay within the 32-character XML schema limit'
);
$expect(str_contains($directoryEndpoint, 'directory_label_override_set') && str_contains($directoryEndpoint, 'label_pattern'), 'Published phonebooks must apply per-template short-label formatting without bypassing final directory-label overrides');
$expect(str_contains($directory, "action==='Previous'&&!state.hasPrevious") && str_contains($directory, "action==='Next'&&!state.hasNext"), 'Cisco preview must hide paging keys when no adjacent page exists');
$expect(str_contains($css, '.ucm-phone-preview-softkeys') && str_contains($css, 'background:#263941') && str_contains($css, 'color:#fff'), 'Phonebook preview softkeys must have readable high-contrast styling');
$expect(!str_contains($phones, 'data-cmd="factory"') && !str_contains($javascript, "data('cmd') === 'factory'"), 'Phones UI must not advertise an unsupported factory reset command');
$expect(str_contains($install, "'ucmp-d.php' => __DIR__ . '/directory.php'"), 'Install hook must publish the short legacy Cisco directory endpoint');
$expect(str_contains($fail2ban, 'id="ucm-f2b-site"') && str_contains($fail2ban, 'op:\'unban\''), 'Fail2ban manager must be site-scoped and expose per-client unban');
$expect(str_contains($fail2ban, 'test_filter'), 'Fail2ban monitor must expose its read-only filter tester');
$expect(!str_contains($fail2ban, 'save_jail') && !str_contains($fail2ban, 'unban_all') && !str_contains($fail2ban, "op:'ban'"), 'Restrictive Fail2ban UI must not expose configuration, bulk-unban or ban operations');
$expect(str_contains($services, 'id="ucm-services-site"'), 'Phone Services must default to an explicit site selector');
$expect(str_contains($services, "command:'phoneservices'") && str_contains($services, 'ucm-service-add-dial'), 'Phone Services must expose site-local CRUD and quick-dial editing');
$expect(str_contains($services, 'ucm-service-format') && str_contains($services, 'last-known catalog snapshot'), 'Phone Services must expose family applicability and retain an offline catalog view');
$expect(str_contains($services, 'id="ucm-service-models"') && str_contains($services, 'target_models'), 'Phone Services must expose exact-model applicability');
$expect(str_contains($services, "op:'reorder'") && str_contains($services, 'ucm-service-move'), 'Phone Services must expose deterministic direct reorder controls');
$expect(str_contains($services, 'previewCache') && str_contains($services, 'showCachedPreview'), 'Phone Services must retain token-free output previews for offline sites');
$expect(
    str_contains($services, 'id="ucm-service-protected-purpose"')
    && !str_contains($services, '<option value="directory"')
    && !str_contains($services, '<option value="info"'),
    'Protected Phone Service purposes must render read-only rather than appearing as duplicate selectable options'
);
$expect(str_contains($install, "'ucmp-s.php' => __DIR__ . '/phone_services.php'"), 'Install hook must publish the short phone-service endpoint');
$expect(str_contains($serviceEndpoint, '\\FreePBX::Ucmprovisioner()')
    && str_contains($serviceEndpoint, "\$bootstrap_settings['freepbx_auth'] = false")
    && str_contains($serviceEndpoint, "\$restrict_mods = ['core'=>true, 'ucmprovisioner'=>true]")
    && !str_contains($serviceEndpoint, 'new \\FreePBX\\modules\\Ucmprovisioner('),
    'Public phone-service endpoint must use the shared module registry with token-authenticated bootstrap');
$expect(str_contains($serviceEndpoint, "['GET','HEAD']") && str_contains($serviceEndpoint, 'strlen($path)>512'), 'Public phone-service endpoint must bound methods and route payloads');
$expect(str_contains($fail2ban, 'fwconsole ma install ucmprovisioner') && str_contains($fail2ban, 'ucmp-fail2ban'), 'Fail2ban view must document first-install enrollment and the constrained helper');
$expect(
    str_contains($templates, 'This template tier')
    && str_contains($templates, 'Inherit - property absent')
    && str_contains($templates, 'Customize - exact ordered list')
    && str_contains($templates, 'Clear inherited - null')
    && str_contains($templates, 'at most 16')
    && str_contains($templates, 'Insert Empty slot (Undefined)')
    && str_contains($templates, 'pageLabel.nextElementSibling')
    && str_contains($templates, 'ucm-move-softkey-up')
    && str_contains($templates, 'ucm-move-softkey-down'),
    'Cisco softkey editor must expose sparse tier semantics and the exact ordered 16-slot state list'
);
$expect(
    str_contains($templates, 'Repeat across selected states')
    && str_contains($templates, 'Cisco has no permanent softkey flag')
    && str_contains($templates, 'Advanced: raw custom softkey definitions')
    && str_contains($templates, 'Exact custom state ID')
    && str_contains($templates, 'Unresolved legacy entries')
    && str_contains($templates, 'invalidSoftkeyMapKey'),
    'Cisco softkey editor must provide transparent repeat, raw definition/state, and visible legacy migration controls'
);
$expect(
    !str_contains($templates, 'nativeProfiles')
    && !str_contains($templates, 'softkeyUnsupported')
    && !str_contains($templates, 'firmware · permanent')
    && !str_contains($templates, "'draggable':'false'")
    && str_contains($templates, 'do not filter the policy'),
    'Cisco softkey editor must not restore guessed native/permanent rows or model-dependent mutation'
);
$expect(str_contains($templates, 'The actionable panel may show Ignore') && str_contains($templates, 'not a configurable softkeys.xml action'), 'Cisco incoming-call help must not misrepresent the firmware Ignore control as a programmable enterprise softkey');
$expect(str_contains($templates, 'softkeyStateDescriptions') && str_contains($templates, 'softkeyKeyDescriptions') && str_contains($templates, 'ucm-softkey-state-description'), 'Every Cisco softkey state and trusted key must expose concise hover/help text');
$expect(str_contains($templates, 'cisco_display_schedule_mode') && str_contains($templates, 'Active every day') && str_contains($templates, 'No checked days means it may operate every day'), 'Cisco display/backlight editor must allow a deliberate no-inactive-days schedule');
$expect(str_contains($templates, 'value="__none__"') && str_contains($templates, 'No phonebook — omit directory URLs'), 'Template editor must expose an explicit no-phonebook policy distinct from inheritance');
$expect(str_contains($groups, "'applicability'") && str_contains($groups, "target_series") && str_contains($groups, "'scope_label'") && str_contains($groups, 'firmware/config compatibility next'), 'Group template rows must expose and bucket the same saved Applicability used by template resolution');
$expect(str_contains($css, '.ucmp-replication-activity{position:fixed;left:18px;right:auto;'), 'Replication activity must stay left of bottom-right Submit and Reset controls');
$expect(str_contains($firmware, 'id="ucm-tz-candidate-profile"') && str_contains($firmware, "op','timezone_stage_payload") && str_contains($firmware, 'does not change TFTP root files'), 'Firmware manager must retain a runtime-specific timezone payload without changing canonical root files');
$expect(str_contains($directoryEndpoint, "if(\$format!=='cisco_legacy')") && str_contains($directoryEndpoint, "'SoftKeyItem'"), 'Cisco 7940/7960 directory search must omit unsupported CMXML SoftKeyItem nodes');
$expect(str_contains($templates, 'data-key="cisco_service_provisioning"') && str_contains($templates, 'Direct URLs + embedded native applications') && str_contains($templates, 'Existing templates inherit mode 2'), 'Cisco service merge mode must expose the safe default and explicit direct-only opt-out');
$expect(
    str_contains($templates, '7940/7960 have fixed built-in line/speed-dial keys')
    && str_contains($templates, '8961 / 9951 / 9971 only')
    && str_contains($templates, 'softKeyControl workflow'),
    'Cisco editor must distinguish CNF, XML softkey, and dual-mode feature-policy phone families'
);
$expect(
    str_contains($templates, 'data-label-phone-max')
    && str_contains($templates, '&lt;phoneLabel&gt;')
    && str_contains($templates, 'Cisco XML featureLabel')
    && str_contains($templates, 'firmware shows separate status text')
    && str_contains($templates, 'account.X.label')
    && str_contains($templates, 'Account Name (P270/P417/...)')
    && str_contains($templates, 'handset.X.name')
    && str_contains($templates, 'hs.X.user.hsName')
    && str_contains($phones, 'Base phone and line-key label')
    && str_contains($phones, 'lowest assigned account')
    && str_contains($phones, 'account.X.display_name')
    && str_contains($phones, 'P418x Name carries caller identity')
    && str_contains($templates, 'AD/template/final-extension precedence always owns')
    && !str_contains($phones, 'var LABEL_LIMITS = { cisco_cnf: 11 }'),
    'Cisco, Yealink and Grandstream device/account, caller and DECT labels must be explained as separate model-constrained fields'
);
$expect(str_contains($templates, 'cisco_days_display_not_active') && str_contains($templates, 'cisco_backlight_idle_timeout'), 'Template editor must expose Cisco display and backlight scheduling');
$expect(
    str_contains($templates, 'Separate Cisco product settings')
    && str_contains($templates, 'data-key="cisco_recents_softkey"')
    && str_contains($templates, 'idle Navigation Up is firmware behavior')
    && str_contains($templates, 'hasManagedSoftkeyPolicy()')
    && str_contains($templates, 'managedFeaturePolicy=policy&&!managedSoftkeys'),
    'Cisco product settings and navigation observations must stay outside the emitted call-state lists'
);
$expect(str_contains($templates, 'ucm-add-quick-dial') && str_contains($templates, 'timeout 0'), 'Template editor must expose fixed-length immediate dialing for normal and transfer digit collection');
$expect(str_contains($groups, 'member_extensions[]') && str_contains($groups, 'ucm-group-member-search') && str_contains($groups, 'members_submitted'), 'Group editor must support direct searchable extension membership');
$expect(str_contains($groups, 'logical extension') && str_contains($module, 'synchronizeGroupMembers') && !str_contains($module, "INSERT INTO ucmp_phones (site_id, group_id, extension"), 'Group membership must live on logical extensions without creating placeholder phones');
$expect(str_contains($groups, 'parent_group_id') && str_contains($groups, 'ucm-group-template-scopes') && str_contains($groups, 'automatically arranged broad policy first'), 'Group editor must expose one parent and automatically bucket its applicability-filtered template stack');
$expect(str_contains($groups, 'member_exceptions') && str_contains($groups, 'ucm-group-exception-select-visible') && str_contains($groups, 'replaces the complete earlier template key list'), 'Group editor must expose clear multi-member final-exception semantics');
$expect(str_contains($groups, 'name="revision"') && str_contains($templates, 'name="revision"') && str_contains($module, 'changed by another administrator'), 'Group and template editors must reject stale concurrent saves');
$expect(!str_contains($phones, '$unbound') && str_contains($phones, 'collision_action') && str_contains($phones, 'Swap extensions (recommended)'), 'Phone editor must offer every PBX extension and an explicit Swap/Move collision policy');
$expect(str_contains($phones, 'data-phone-id') && str_contains($phones, 'assign_extension=') && str_contains($phones, 'MAC addresses themselves are never traded'), 'Selecting an existing MAC must reopen that physical phone without exchanging MAC identities');
$expect(str_contains($phones, 'Additional SIP accounts') && str_contains($phones, 'ucm-line-account') && str_contains($phones, 'Line appearance'), 'Phone editor must keep registration slots separate from physical line appearances');
$expect(str_contains($phones, 'Registered handset inventory') && str_contains($phones, 'dect_handsets') && str_contains($phones, 'dect_base'), 'Phone editor must model DECT handsets as children of a provisioned base');
$expect(str_contains($phones, 'ucm-gs-dect-handset') && str_contains($phones, "'_handsets'") && !str_contains($phones, '<option value="101">Circular</option>'), 'Grandstream DECT routing must select actual handset slots rather than place ring modes in the handset field');
$expect(str_contains($templates, 'ucm-restrict-model') && str_contains($templates, 'firmware_by_profile') && str_contains($templates, 'cisco_wallpaper_by_profile') && str_contains($templates, 'hosted_image_by_profile'), 'Template scope and asset selections must be compatibility-profile based');
$expect(str_contains($templates, 'static.auto_provision.handset_configured.enable') && str_contains($templates, 'custom.handset.backlight_in_charger.enable') && str_contains($templates, 'ucm-component-warning'), 'Template editor must expose base-delivered Yealink handset policy and reject standalone component previews');
$expect(
    !str_contains($templates, 'data-key="cisco_xml_tzdata_version"')
    && !str_contains($templates, 'data-key="cisco_xml_tzdata_file"')
    && str_contains($templates, 'cisco_timezone_by_profile')
    && str_contains($templates, 'ucm-timezone-asset-picker')
    && str_contains($templates, "op:'timezone_versions'")
    && str_contains($templates, 'Profile-safe inheritance'),
    'Template editor must select one retained timezone asset per runtime without exposing raw payload filenames'
);
$expect(str_contains($templates, 'data-configprofile') && str_contains($templates, 'ucm-vlan-model-note') && str_contains($templates, 'DP755 uses version-2 network aliases'), 'Template VLAN controls must explain and constrain the selected model profile');
$expect(
    str_contains($media, 'ucm-bg-save-profile')
    && str_contains($media, 'Save all catalog changes')
    && str_contains($media, 'put_media_pairs')
    && str_contains($media, "op:'special_catalog_sync'")
    && str_contains($media, 'ucm-media-sync-selected'),
    'Media manager must save one or every scoped profile and synchronize exact selected special catalogs'
);
$expect(str_contains($firmware, 'sync_firmware_bundle') && str_contains($firmware, 'ucm-fw-sync-target'), 'Firmware manager must expose per-site package synchronization');
$expect(
    str_contains($firmware, 'function targetProfiles()')
    && str_contains($firmware, 'profiles.indexOf(bundle.profile)')
    && !str_contains($firmware, 'Choose the exact phone model before uploading'),
    'Firmware manager must select compatibility profiles rather than force exact model identity'
);
$expect(
    str_contains($firmware, "op:'timezone_versions'")
    && str_contains($firmware, "op:'timezone_capture'")
    && str_contains($firmware, "op:'timezone_activate'")
    && str_contains($firmware, "op:'sync_timezone_version'")
    && str_contains($firmware, 'tzupdater.ver')
    && str_contains($firmware, 'TzDataCSV.csv')
    && str_contains($firmware, 'id="ucm-tz-assets"')
    && str_contains($firmware, 'Standard JVM')
    && str_contains($firmware, 'IBM J9')
    && str_contains($firmware, 'Lodown / RTL / Gumbo')
    && str_contains($firmware, 'No COP parser')
    && str_contains($firmware, 'bundle.capture_allowed')
    && str_contains($firmware, 'Object.keys(bundle.compatibility||{})'),
    'Firmware manager must retain and synchronize timezone revisions independently by documented runtime while preserving legacy evidence'
);
$expect(
    str_contains($firmware, 'Cisco locale companions by firmware family')
    && str_contains($firmware, 'ucm-locale-profile')
    && str_contains($firmware, 'network_locale')
    && str_contains($firmware, 'sync_cisco_locale_bundle'),
    'Firmware manager must manage locale companions by their actual Cisco load family and phone-facing directories'
);
$expect(str_contains($settings, 'firmware_defaults') && str_contains($settings, 'cisco_timezone_defaults') && str_contains($settings, 'cisco_wallpaper_defaults') && str_contains($settings, 'hosted_image_defaults'), 'Site defaults must expose inherited firmware, timezone and media policies');
$expect(str_contains($settings, 'background_access_disabled') && str_contains($settings, 'phone_personalization'), 'General settings must expose Cisco wallpaper locking and personalization policy');
$expect(str_contains($settings, 'phone_services_base_url') && str_contains($settings, 'token, vendor format and the current phone MAC'), 'General settings must expose the normalized managed phone-services base URL');
$expect(
    str_contains($module, 'assertPageMutationRequest')
    && str_contains($module, 'pageCsrfToken')
    && str_contains($page, 'window.ucmpPostAction')
    && str_contains($page, 'ucmp_csrf'),
    'Every ordinary page mutation must use a session-bound POST rather than a state-changing GET'
);
$expect(
    !str_contains($settings, 'settings[cisco_xml_tzdata_version]')
    && !str_contains($settings, 'settings[cisco_xml_tzdata_file]')
    && str_contains($settings, 'Cisco timezone payloads are runtime-owned')
    && str_contains($settings, 'Manage retained timezone revisions'),
    'General settings must select retained runtime assets without inventing a raw timezone filename override'
);
$expect(str_contains($module, "'op'=>'download_chunk'") && str_contains($module, "'op'=>'upload_chunk'"), 'Federated media sync must use bounded chunks rather than whole-file RPC payloads');
$expect(str_contains($module, 'PhoneAccounts::normalize') && str_contains($module, "case 'templatesettings'"), 'Server must validate multiple accounts and lazy-load site defaults');
$expect(str_contains($page, 'ucm-site-offline-banner') && str_contains($page, 'last-known-good read-only state') && str_contains($page, 'offlineSnapshot') && str_contains($page, '#action-bar #ucm-delete'), 'Offline pages must identify cached state and disable view and action-bar mutations');
$expect(str_contains($directory, 'ad_site_rules') && str_contains($directory, 'Authoritative AD-to-site mapping') && str_contains($directory, 'Revert to AD'), 'Directory manager must expose explicit AD mapping and audited identity override controls');
$expect(str_contains($patches, 'Asterisk Patch Manager') && str_contains($patches, "command:'patchworkflow'") && str_contains($patches, 'What is running, installed and pinned'), 'Patch Manager must expose the fixed local source/binary bundle workflow');
$expect(str_contains($patches, 'high_strength_srtp') && str_contains($patches, 'Incoming synchronized bundles') && str_contains($patches, 'ucm-bundle-sync') && str_contains($patchCatalog, "'20.20.0'") && str_contains($patchCatalog, "'20.19.0'") && str_contains($patchInventory, 'version_switch_held'), 'Patch Manager must expose matching selectable builds, incoming bundles, site sync and the FreePBX version-switch pin');
$expect(str_contains($module, "'getPatchInventoryLocal'") && str_contains($module, 'PatchInventoryService'), 'Patch inventory must be RPC-safe and available for offline site snapshots');
$expect(str_contains($javascript, 'effective template path') && str_contains($javascript, 'template_trace'), 'Phone preview must expose the applied and skipped template-layer trace');
$expect(str_contains($javascript, 'withConfirmedHostKey') && str_contains($javascript, "command: 'sshfingerprint'") && str_contains($javascript, 'expected_host_key: fingerprint'), 'SSH bootstrap UI must confirm a credential-free host fingerprint before sending credentials');
$expect(str_contains($remoteDispatch, 'assertRemoteLocalRow') && !str_contains($remoteDispatch, "'syncPublishMesh'"), 'Federated RPC must enforce local row ownership and must not expose a cross-site mesh pivot');
$expect(
    str_contains($remoteDispatch, "'firmware_inventory', 'firmware_rescan'")
    && str_contains($remoteDispatch, "'managed_asset_inventory', 'managed_asset_rescan'")
    && str_contains($remoteDispatch, "'timezone_inventory'")
    && str_contains($remoteDispatch, 'Remote TFTP RPC is limited to bounded inventory operations'),
    'Federated TFTP RPC must be limited to bounded local inventory operations'
);
$expect(str_contains($module, 'assertModulePackageTreeSafe') && str_contains($module, "hash_file('sha256'") && str_contains($module, 'sha256sum -c -'), 'Remote module distribution must reject unsafe source trees and verify the uploaded archive digest');
$expect(str_contains($module, "if (\$method === 'phoneServiceOpLocal')") && str_contains($module, "unset(\$data['urls'])"), 'Offline snapshots must not persist phone-service bearer URLs');
$expect(str_contains($networkScan, '$prefix < 0') && !str_contains($networkScan, '$configuredCidrs') && str_contains($networkScan, 'timeout 90s nmap'), 'Network discovery must stay time-bounded without restricting valid IPv4 subnets to saved ranges');

echo "UI regression fixtures passed\n";
