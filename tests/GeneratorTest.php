<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

require_once dirname(__DIR__) . '/lib/CiscoXmlGenerator.php';
require_once dirname(__DIR__) . '/lib/CiscoCnfGenerator.php';
require_once dirname(__DIR__) . '/lib/GrandstreamGenerator.php';
require_once dirname(__DIR__) . '/lib/YealinkGenerator.php';
require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/DefaultTemplateResolver.php';
require_once dirname(__DIR__) . '/lib/TemplateStackResolver.php';
require_once dirname(__DIR__) . '/lib/VlanPolicy.php';
require_once dirname(__DIR__) . '/lib/MediaUrl.php';
require_once dirname(__DIR__) . '/lib/IdentityLabelFormatter.php';
require_once dirname(__DIR__) . '/lib/EndpointProfile.php';
require_once dirname(__DIR__) . '/lib/TemplateEngine.php';
require_once dirname(__DIR__) . '/lib/SoftkeyPolicyGenerator.php';
require_once dirname(__DIR__) . '/lib/PublishMesh.php';
require_once dirname(__DIR__) . '/lib/DialTemplateGenerator.php';

use FreePBX\modules\Ucmprovisioner\CiscoXmlGenerator;
use FreePBX\modules\Ucmprovisioner\CiscoCnfGenerator;
use FreePBX\modules\Ucmprovisioner\GrandstreamGenerator;
use FreePBX\modules\Ucmprovisioner\YealinkGenerator;
use FreePBX\modules\Ucmprovisioner\PublishMesh;
use FreePBX\modules\Ucmprovisioner\DialTemplateGenerator;
use FreePBX\modules\Ucmprovisioner\SoftkeyPolicyGenerator;
use FreePBX\modules\Ucmprovisioner\TemplateEngine;

function expectContains(string $needle, string $haystack, string $message): void {
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . "\nMissing: " . $needle);
    }
}
function expectNotContains(string $needle, string $haystack, string $message): void {
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message . "\nUnexpected: " . $needle);
    }
}
function baseContext(string $model, string $family): array {
    return [
        'phone' => ['mac' => '001122AABBCC', 'extension' => '301', 'label' => 'Alice', 'family' => $family],
        'model' => ['model' => $model, 'family' => $family, 'line_keys' => 6],
        'settings' => ['pbx_addr' => '10.0.0.10', 'sip_port' => '5060', 'transport' => 'tcp', 'voicemail_number' => '*97'],
        'lines' => [
            ['button' => 1, 'feature' => 'line', 'featureId' => 9, 'lineIndex' => 1, 'label' => 'Alice', 'name' => '301', 'authName' => '301', 'secret' => 'primary-secret', 'contact' => '301'],
            ['button' => 2, 'feature' => 'line', 'featureId' => 9, 'lineIndex' => 2, 'label' => 'Alice 2', 'name' => '302', 'authName' => '302', 'secret' => 'secondary-secret', 'contact' => '302'],
            ['button' => 3, 'feature' => 'blf', 'featureId' => 21, 'label' => 'Bob', 'speedDialNumber' => '303', 'contact' => '303', 'featureOptionMask' => 1],
        ],
    ];
}

$cisco = (new CiscoXmlGenerator())->generate(baseContext('8841', 'cisco_xml'))['xml'];
$ciscoRepeat = (new CiscoXmlGenerator())->generate(baseContext('8841', 'cisco_xml'))['xml'];
if ($cisco !== $ciscoRepeat) {
    throw new RuntimeException('Identical Cisco configurations must produce byte-identical preview and apply output');
}
expectContains('<phoneLabel>Alice</phoneLabel>', $cisco, 'Cisco XML must emit the independently configurable top/status phoneLabel');
expectNotContains('<dialTemplate>', $cisco, 'Cisco XML must not reference a fallback dial-template file unless the operator selected one');
$ciscoDoc = new DOMDocument();
$ciscoDoc->loadXML($cisco);
$ciscoXpath = new DOMXPath($ciscoDoc);
if ($ciscoXpath->query('/device/sipProfile/phoneLabel')->length !== 1
    || $ciscoXpath->query('/device/phoneLabel')->length !== 0) {
    throw new RuntimeException('Cisco XML phoneLabel must be a direct sipProfile child');
}
$sipProfileChildren = [];
foreach ($ciscoXpath->query('/device/sipProfile/*') as $child) {
    $sipProfileChildren[] = $child->nodeName;
}
if (array_search('sipStack', $sipProfileChildren, true) >= array_search('phoneLabel', $sipProfileChildren, true)
    || array_search('phoneLabel', $sipProfileChildren, true) >= array_search('sipLines', $sipProfileChildren, true)) {
    throw new RuntimeException('Cisco XML phoneLabel must follow sipStack and precede sipLines');
}
expectContains('<contact>301</contact>', $cisco, 'Primary Cisco line needs contact');
expectContains('<contact>303</contact>', $cisco, 'Cisco BLF needs contact');
$secondary = substr($cisco, strpos($cisco, 'lineIndex="2"'), strpos($cisco, '<line button="3"') - strpos($cisco, 'lineIndex="2"'));
expectNotContains('<authPassword>', $secondary, 'Bulk-registered Cisco secondary line must not authenticate separately');
foreach (['<MissedCallLoggingOption>11</MissedCallLoggingOption>', '<phoneServices useHTTPS="false">', '<provisioning>2</provisioning>', '<url>Application:Cisco/MissedCalls</url>', '<url>Application:Cisco/ReceivedCalls</url>', '<url>Application:Cisco/PlacedCalls</url>'] as $needle) {
    expectContains($needle, $cisco, 'Cisco native call history service mapping mismatch');
}
expectNotContains('<name>Company Directory</name>', $cisco, 'The provisioner must not inject a duplicate Company Directory phone service');
$cisco78 = (new CiscoXmlGenerator())->generate(baseContext('7841', 'cisco_xml'))['xml'];
expectContains('<provisioning>2</provisioning>', $cisco78, 'Cisco 78xx must retain direct URLs and embedded native call-history applications by default');
$cisco78DirectOnly = baseContext('7841', 'cisco_xml');
$cisco78DirectOnly['settings']['cisco_service_provisioning'] = '1';
expectContains(
    '<provisioning>1</provisioning>',
    (new CiscoXmlGenerator())->generate($cisco78DirectOnly)['xml'],
    'Cisco 78xx direct-URL-only mode must remain available as an explicit operator opt-out'
);
$defaultSettings = (new TemplateEngine(new class {
    public function localSiteId(): int { return 1; }
    public function getSiteSettings(int $siteId): array { return []; }
}))->effectiveSettings(1);
if (($defaultSettings['cisco_service_provisioning'] ?? null) !== '2') {
    throw new RuntimeException('Templates which omit Cisco service provisioning must inherit mode 2 without a database migration');
}
foreach (['<remotePartyID>true</remotePartyID>', '<userInfo>Phone</userInfo>'] as $needle) {
    expectContains($needle, $cisco, 'Cisco connected-line identity mapping mismatch');
}
$xmlLabels = baseContext('7841', 'cisco_xml');
$xmlLabels['phone']['label'] = 'Department Alpha';
$xmlLabels['lines'][0]['label'] = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
$xmlLabels['lines'][0]['displayName'] = 'Caller-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
$xmlLabelResult = (new CiscoXmlGenerator())->generate($xmlLabels);
expectContains('<phoneLabel>Department A</phoneLabel>', $xmlLabelResult['xml'], 'Cisco XML phoneLabel must use its own 12-character render limit');
expectContains('<featureLabel>ABCDEFGHIJKLMNOPQRSTUVWXYZ1234</featureLabel>', $xmlLabelResult['xml'], 'Cisco XML line-key featureLabel must retain its independent 30-character limit');
expectContains('<displayName>Caller-ABCDEFGHIJKLMNOPQRSTUVW</displayName>', $xmlLabelResult['xml'], 'Cisco XML caller identity must be independent and limited to 30 characters');
if (count($xmlLabelResult['warnings']) < 3) {
    throw new RuntimeException('Every clipped Cisco XML identity surface must produce a warning');
}
$xml7900Labels = baseContext('7962', 'cisco_xml');
$xml7900Labels['phone']['label'] = 'Team Alpha';
$xml7900Result = (new CiscoXmlGenerator())->generate($xml7900Labels);
expectContains('<phoneLabel>TeamAlpha</phoneLabel>', $xml7900Result['xml'], 'Enterprise XML 7900 phoneLabel must remove unsupported spaces without changing line labels');
expectContains('<featureLabel>Alice</featureLabel>', $xml7900Result['xml'], 'Enterprise XML 7900 no-spaces policy must apply only to phoneLabel');

$managedCisco = baseContext('8845', 'cisco_xml');
$managedCisco['model']['supports_softkeys'] = 1;
$managedCisco['model']['supports_featurepolicy'] = 1;
$managedCisco['settings'] += [
    'softkey_file' => 'tpl7-softkeys.xml', 'featurepolicy_file' => 'tpl7-featurepolicy.xml',
    '_softkey_layout' => [
        'schema'=>2,
        'states'=>['On Hook'=>['NewCall','Redial']],
        'definitions'=>[],
    ],
    'firmware' => 'sip8845_65.14-3-1', 'inactive_firmware' => 'sip8845_65.12-8-1',
    'cisco_phone_password' => 'phone-admin', 'cisco_recents_softkey' => '1',
    'cisco_video_capability' => '1', 'cisco_camera' => '1', 'cisco_web_access' => '0',
    'cisco_ssh_access' => '1', 'cisco_ssh_user' => 'ops', 'cisco_ssh_password' => 'debug-secret',
    'cisco_load_server' => '10.0.0.20', 'cisco_peer_firmware_sharing' => '0',
];
$managedXml = (new CiscoXmlGenerator())->generate($managedCisco)['xml'];
foreach (['<softKeyFile>tpl7-softkeys.xml</softKeyFile>', '<phonePassword>phone-admin</phonePassword>', '<inactiveLoadInformation>sip8845_65.12-8-1</inactiveLoadInformation>', '<videoCapability>1</videoCapability>', '<ciscoCamera>1</ciscoCamera>', '<webAccess>0</webAccess>', '<sshAccess>1</sshAccess>', '<loadServer>10.0.0.20</loadServer>', '<sshUserId>ops</sshUserId>', '<sshPassword>debug-secret</sshPassword>'] as $needle) {
    expectContains($needle, $managedXml, 'Cisco managed device/firmware policy mismatch');
}
expectContains('<softkeyControl>1</softkeyControl>', $managedXml, '78xx/88xx custom softkey files must enable firmware softkey control');
expectNotContains('<featurePolicyFile>', $managedXml, 'A managed softKeyFile must take precedence over featurePolicy on dual-capability phones');
expectNotContains('<recentsSoftKey>', $managedXml, 'The 78xx-only Recents vendor switch must not be emitted for an 8845');

$managedFeature = $managedCisco;
unset($managedFeature['settings']['_softkey_layout']);
$managedFeature['settings']['_feature_policy_layout'] = ['Forward All'=>true];
$managedFeatureXml = (new CiscoXmlGenerator())->generate($managedFeature)['xml'];
expectContains('<featurePolicyFile>tpl7-featurepolicy.xml</featurePolicyFile>', $managedFeatureXml, 'Managed feature-policy layout must emit its companion reference');
expectNotContains('<softKeyFile>', $managedFeatureXml, 'A filename without a managed softkey layout must not create a broken reference');

$managed8961 = $managedCisco;
$managed8961['model']['model'] = '8961';
$managed8961['model']['supports_softkeys'] = 1;
$managed8961['model']['supports_featurepolicy'] = 1;
$managed8961Xml = (new CiscoXmlGenerator())->generate($managed8961)['xml'];
expectContains('<softKeyFile>tpl7-softkeys.xml</softKeyFile>', $managed8961Xml, '8961 softKeyControl mode must consume the managed call-state policy');
expectContains('<softkeyControl>1</softkeyControl>', $managed8961Xml, '8961 must explicitly switch from feature policy to softKeyFile control');
expectNotContains('<featurePolicyFile>', $managed8961Xml, '8961 must not reference both policy mechanisms at once');
$feature8961 = $managed8961;
unset($feature8961['settings']['_softkey_layout']);
$feature8961['settings']['_feature_policy_layout'] = ['Forward All'=>true];
$feature8961Xml = (new CiscoXmlGenerator())->generate($feature8961)['xml'];
expectContains('<featurePolicyFile>tpl7-featurepolicy.xml</featurePolicyFile>', $feature8961Xml, '8961 must retain feature-policy fallback when no call-state policy is managed');
expectNotContains('<softkeyControl>', $feature8961Xml, '8961 feature-policy mode must not enable softKeyFile control');

$unmanagedPolicyName = $managedCisco;
unset($unmanagedPolicyName['settings']['_softkey_layout']);
$unmanagedPolicyName['model']['supports_featurepolicy'] = 0;
$unmanagedPolicyXml = (new CiscoXmlGenerator())->generate($unmanagedPolicyName)['xml'];
expectNotContains('<softKeyFile>', $unmanagedPolicyXml, 'An unmanaged/default softkey filename must not be referenced');

$cisco78xx = baseContext('7841', 'cisco_xml');
$cisco78xx['settings'] += [
    'cisco_recents_softkey' => '1',
    'cisco_days_display_not_active' => '1,7',
    'cisco_display_on_time' => '07:30',
    'cisco_display_on_duration' => '10:00',
    'cisco_display_idle_timeout' => '00:30',
    'cisco_display_on_incoming_call' => '1',
    'cisco_days_backlight_not_active' => '1',
    'cisco_backlight_on_time' => '07:35',
    'cisco_backlight_on_duration' => '09:55',
    'cisco_backlight_idle_timeout' => '00:15',
    'cisco_backlight_on_incoming_call' => '0',
    'cisco_more_key_reversion_timer' => '10',
    'cisco_show_call_history_selected_line' => '1',
    'cisco_admin_configurable_ringer' => '3',
];
$cisco78xxXml = (new CiscoXmlGenerator())->generate($cisco78xx)['xml'];
expectContains('<vendorConfig>', $cisco78xxXml, 'Cisco 7800 Recents belongs in vendorConfig');
expectContains('<recentsSoftKey>1</recentsSoftKey>', $cisco78xxXml, 'Cisco 7800 native Recents toggle must render');
$cisco78xxRecentsOff = $cisco78xx;
$cisco78xxRecentsOff['settings']['cisco_recents_softkey'] = '0';
expectContains(
    '<recentsSoftKey>0</recentsSoftKey>',
    (new CiscoXmlGenerator())->generate($cisco78xxRecentsOff)['xml'],
    'An explicit operator-disabled Cisco 7800 Recents setting must not be forced back on'
);
foreach (['<daysDisplayNotActive>1,7</daysDisplayNotActive>', '<displayOnTime>07:30</displayOnTime>', '<displayOnDuration>10:00</displayOnDuration>', '<displayIdleTimeout>00:30</displayIdleTimeout>', '<displayOnWhenIncomingCall>1</displayOnWhenIncomingCall>', '<daysBacklightNotActive>1</daysBacklightNotActive>', '<backlightOnTime>07:35</backlightOnTime>', '<backlightOnDuration>09:55</backlightOnDuration>', '<backlightIdleTimeout>00:15</backlightIdleTimeout>', '<backlightOnWhenIncomingCall>0</backlightOnWhenIncomingCall>', '<moreKeyReversionTimer>10</moreKeyReversionTimer>', '<showCallHistoryForSelectedLine>1</showCallHistoryForSelectedLine>', '<adminConfigurableRinger>3</adminConfigurableRinger>'] as $needle) {
    expectContains($needle, $cisco78xxXml, 'Cisco display/backlight/call behavior mapping mismatch');
}
$dailySchedule = baseContext('7841', 'cisco_xml');
$dailySchedule['settings']['cisco_display_schedule_mode'] = 'daily';
$dailySchedule['settings']['cisco_days_display_not_active'] = '1,7';
$dailyScheduleXml = (new CiscoXmlGenerator())->generate($dailySchedule)['xml'];
expectContains('<daysDisplayNotActive/>', $dailyScheduleXml, 'Active-every-day mode must emit an explicit empty Cisco day list to clear inherited inactive days');
$offSchedule = baseContext('7841', 'cisco_xml');
$offSchedule['settings']['cisco_backlight_schedule_mode'] = 'off';
$offScheduleXml = (new CiscoXmlGenerator())->generate($offSchedule)['xml'];
expectContains('<daysBacklightNotActive>1,2,3,4,5,6,7</daysBacklightNotActive>', $offScheduleXml, 'Always-off mode must explicitly mark every Cisco weekday inactive');
if (substr_count($managedXml, '<softKeyFile>') !== 1 || substr_count($managedFeatureXml, '<featurePolicyFile>') !== 1) {
    throw new RuntimeException('Each managed Cisco policy file reference must be emitted exactly once');
}

$softkeyLayout = [
    'schema'=>2,
    'states'=>[
        'On Hook'=>['NewCall','Redial','Undefined','Redial','CFwdAll'],
        'Ring In'=>['iDivert'],
        'Connected'=>['Transfer','EndCall'],
    ],
    'definitions'=>[],
    'annotations'=>[
        'Ring In'=>[['label'=>'Ignore','note'=>'Observed firmware control; not emitted.']],
    ],
];
$softkeyXml = (new SoftkeyPolicyGenerator())->softkeysXml($softkeyLayout);
$softkeyRepeat = (new SoftkeyPolicyGenerator())->softkeysXml($softkeyLayout);
if ($softkeyXml !== $softkeyRepeat) {
    throw new RuntimeException('Identical Cisco softkey policies must produce byte-identical preview and apply output');
}
$featurePolicy = ['ForwardAll' => true, 'Mobility' => false];
if ((new SoftkeyPolicyGenerator())->featurePolicyXml($featurePolicy)
    !== (new SoftkeyPolicyGenerator())->featurePolicyXml($featurePolicy)) {
    throw new RuntimeException('Identical Cisco feature policies must produce byte-identical preview and apply output');
}
expectContains('<softKey keyID="Transfer"/>', $softkeyXml, 'Schema-2 softkey order must preserve the operator sequence');
if (substr_count($softkeyXml, '<softKey keyID="Redial"/>') !== 2) {
    throw new RuntimeException('Schema-2 duplicate softkey slots must be preserved exactly');
}
expectContains('<softKey keyID="Undefined"/>', $softkeyXml, 'Undefined must reserve an explicit logical slot');
expectNotContains('Ignore', $softkeyXml, 'Display-only observations must never enter softkeys.xml');
expectContains('<softKey keyID="iDivert"/>', $softkeyXml, 'A schema-2 policy must never silently add or remove an operator key');
$forwardXml = (new SoftkeyPolicyGenerator())->softkeysXml(['On Hook' => ['NewCall','CFwdALL']]);
expectContains('keyID="CFwdAll"', $forwardXml, 'Cisco softkeys.xml requires the documented CFwdAll keyID case');

$legacy = baseContext('7960', 'cisco_cnf');
$legacy['settings'] += ['firmware' => 'P0S3-08-12-00', 'phone_prompt' => 'SITE', 'phone_password' => 'console-pass', 'telnet_level' => '1', 'cisco_legacy_dst_auto_adjust' => '0'];
$legacyResult = (new CiscoCnfGenerator())->generate($legacy);
$legacyCnf = $legacyResult['content'];
foreach (['phone_prompt: "SITE"', 'phone_password: "console-pass"', 'telnet_level: "1"'] as $needle) {
    expectContains($needle, $legacyCnf, 'Legacy Cisco console access mapping mismatch');
}
expectContains('line1_shortname: "Alice"', $legacyCnf, 'Legacy Cisco visible line-key label must not be hardcoded to the extension');
$legacyLatin1 = baseContext('7960', 'cisco_cnf');
$legacyLatin1['lines'][0]['label'] = 'José';
$legacyLatin1['lines'][0]['displayName'] = 'André';
$legacyLatin1Result = (new CiscoCnfGenerator())->generate($legacyLatin1);
expectContains("line1_shortname: \"Jos\xE9\"", $legacyLatin1Result['content'], 'Legacy Cisco line-key labels must preserve ISO-8859-1 accents');
expectContains("line1_displayname: \"Andr\xE9\"", $legacyLatin1Result['content'], 'Legacy Cisco caller display names must preserve ISO-8859-1 accents');
if ($legacyLatin1Result['warnings']) {
    throw new RuntimeException('Representable ISO-8859-1 Cisco line labels must not be reported as transliterated');
}
$legacyDistinctLabels = baseContext('7960', 'cisco_cnf');
$legacyDistinctLabels['phone']['label'] = 'Front Desk Phone';
$legacyDistinctLabels['lines'][0]['label'] = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
$legacyDistinctLabels['lines'][0]['displayName'] = 'Reception Caller';
$legacyDistinctResult = (new CiscoCnfGenerator())->generate($legacyDistinctLabels);
expectContains('phone_label: "Front Desk "', $legacyDistinctResult['content'], 'Legacy Cisco top/status phone_label must retain its separate 11-character limit and allow spaces');
expectContains('line1_shortname: "ABCDEFGHIJKLMNOPQRSTUVWXYZ1234"', $legacyDistinctResult['content'], 'Legacy Cisco line1_shortname must use the resolved line label and module 30-character policy');
expectContains('line1_displayname: "Reception Caller"', $legacyDistinctResult['content'], 'Legacy Cisco line1_displayname must remain caller identity rather than line-key text');
if (count($legacyDistinctResult['warnings']) < 2) {
    throw new RuntimeException('Legacy Cisco phone and line label clipping must be explicit in warnings');
}
expectContains('dst_auto_adjust: "0"', $legacyResult['sip_default'], 'Legacy Cisco DST disable must render in SIPDefault.cnf');
$legacySafeDirectory = $legacy;
$legacySafeDirectory['settings']['cisco_directory_url'] = 'http://pbx/directory.php?token=unsafe&format=cisco';
$legacySafeDirectory['settings']['cisco_legacy_directory_url'] = 'http://pbx/ucmp-d.php/safe-token';
$legacySafeResult = (new CiscoCnfGenerator())->generate($legacySafeDirectory);
expectContains('directory_url: "http://pbx/ucmp-d.php/safe-token"', $legacySafeResult['content'], 'Cisco CNF must prefer the query-free directory endpoint');
expectContains('directory_url: "http://pbx/ucmp-d.php/safe-token"', $legacySafeResult['sip_default'], 'SIPDefault must prefer the query-free directory endpoint');
$legacyServices = $legacy;
$legacyServices['settings']['services_url'] = 'http://pbx/unsafe-services.php?mac=some-other-phone';
$legacyServices['settings']['cisco_services_url'] = 'http://pbx/ucmp-s.php/service-token/cisco_legacy/001122aabbcc';
$legacyServicesResult = (new CiscoCnfGenerator())->generate($legacyServices);
expectContains('services_url: "http://pbx/ucmp-s.php/service-token/cisco_legacy/001122aabbcc"', $legacyServicesResult['content'], 'Cisco CNF must use the managed per-phone service URL');
expectNotContains('unsafe-services.php', $legacyServicesResult['content'], 'Cisco CNF must not fall back to a query-bearing site-wide service URL');
expectNotContains('services_url:', $legacyServicesResult['sip_default'], 'SIPDefault must not contain a phone-specific managed service URL');
$legacyLogo = $legacy;
$legacyLogo['settings']['hosted_image_url'] = 'http://pbx/ucmp-m.php/cisco_cnf/7960/company.bmp';
$legacyLogoResult = (new CiscoCnfGenerator())->generate($legacyLogo);
expectContains('logo_url: "http://pbx/ucmp-m.php/cisco_cnf/7960/company.bmp"', $legacyLogoResult['content'], 'Cisco CNF must retain a direct managed BMP URL');
$formerManagedLogo = $legacy;
$formerManagedLogo['settings']['hosted_image_url'] = 'http://pbx/ucmp-m.php/cisco_cnf/all/RG_Base_2026.bmp?v=243a68b4be8175e8';
$formerManagedLogoResult = (new CiscoCnfGenerator())->generate($formerManagedLogo);
expectContains('logo_url: "http://pbx/ucmp-m.php/cisco_cnf/all/RG_Base_2026.bmp"', $formerManagedLogoResult['content'], 'Cisco CNF must narrowly repair the cache token emitted by older module releases');
if (!empty($formerManagedLogoResult['warnings'])) {
    throw new RuntimeException('Repairing a former module-owned CNF URL must not leave a logo warning');
}
foreach ([
    'https://pbx/company.bmp',
    'http://user:pass@pbx/company.bmp',
    'http://pbx/company.bmp?token=secret',
    'http://pbx/company.png',
] as $unsafeLogo) {
    $unsafeLegacyLogo = $legacy;
    $unsafeLegacyLogo['settings']['hosted_image_url'] = $unsafeLogo;
    $unsafeLogoResult = (new CiscoCnfGenerator())->generate($unsafeLegacyLogo);
    expectNotContains('logo_url:', $unsafeLogoResult['content'], "Cisco CNF must reject unsafe or incompatible logo URL $unsafeLogo");
    if (empty($unsafeLogoResult['warnings'])) {
        throw new RuntimeException("Rejected Cisco logo URL must produce a provisioning warning: $unsafeLogo");
    }
}
foreach ([$legacyCnf, $legacyResult['sip_default']] as $legacyFile) {
    if (preg_match('/[\x80-\xFF]/', $legacyFile)) {
        throw new RuntimeException('ASCII-only legacy fixture unexpectedly gained non-ASCII bytes');
    }
    if (preg_match('/(?<!\r)\n/', $legacyFile)) {
        throw new RuntimeException('Legacy Cisco CNF output must use CRLF line endings');
    }
}
$legacyLimits = baseContext('7960', 'cisco_cnf');
$legacyLimits['phone']['label'] = 'José Very Long';
$legacyLimits['settings']['phone_prompt'] = '12345678901234567890';
$legacyLimitsResult = (new CiscoCnfGenerator())->generate($legacyLimits);
expectContains('phone_label: "Jose Very L"', $legacyLimitsResult['content'], 'Legacy phone label must be ASCII and limited to 11 characters');
expectContains('phone_prompt: "123456789012345"', $legacyLimitsResult['content'], 'Legacy phone prompt must be limited to 15 characters');
$legacyApostrophe = baseContext('7960', 'cisco_cnf');
$legacyApostrophe['phone']['label'] = "O'Connor";
expectContains('phone_label: "O\'Connor"', (new CiscoCnfGenerator())->generate($legacyApostrophe)['content'], 'A real apostrophe must survive deterministic legacy-CNF transliteration');
$legacyLimits['settings']['phone_password'] = str_repeat('x', 32);
try {
    (new CiscoCnfGenerator())->generate($legacyLimits);
    throw new RuntimeException('Legacy console password over 31 characters must be rejected');
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), '31-character') === false) { throw $e; }
}

$kem = baseContext('8861', 'cisco_xml');
$kem['model']['kem_support'] = 1;
$kem['model']['max_expansion_modules'] = 3;
$kem['model']['expansion_types'] = json_encode(['BEKEM','CP-8800-Audio']);
$kem['settings']['cisco_xml_timezone'] = 'Iran Standard/Daylight Time';
$kem['settings']['cisco_xml_olson_timezone'] = 'Asia/Tehran';
$kem['settings']['_cisco_tzdata_version'] = '2025b-2026';
$kem['settings']['_cisco_tzdata_file'] = 'j9-tzdata.jar';
$kem['settings']['_cisco_tzdata_warning'] = 'j9-tzdata.jar is present, but its embedded IANA release could not be independently read.';
$kem['settings']['cisco_kem_count'] = 1;
$kem['settings']['cisco_kem_type'] = 'CP-8800-Audio';
$kem['settings']['cisco_default_wallpaper'] = 'Company.png';
$kem['settings']['background_access_disabled'] = '1';
$kem['lines'][] = ['button' => 6, 'feature' => 'blf', 'featureId' => 21, 'label' => 'KEM BLF', 'speedDialNumber' => '399', 'contact' => '399', 'featureOptionMask' => 1];
$kemResult = (new CiscoXmlGenerator())->generate($kem);
$kemXml = $kemResult['xml'];
foreach (['<tzolsonversion>2025b-2026</tzolsonversion>', '<tzupdater>j9-tzdata.jar</tzupdater>', '<timeZone>Iran Standard/Daylight Time</timeZone>', '<olsonTimeZone>Asia/Tehran</olsonTimeZone>', '<addOnModule idx="1">', '<deviceType>CP-8800-Audio</deviceType>', '<deviceLine>28</deviceLine>', '<defaultWallpaperFile>Company.png</defaultWallpaperFile>', '<backgroundImageAccess>false</backgroundImageAccess>', '<line button="6">'] as $needle) {
    expectContains($needle, $kemXml, 'Cisco KEM/time-zone rendering mismatch');
}
if (!in_array($kem['settings']['_cisco_tzdata_warning'], $kemResult['warnings'], true)) {
    throw new RuntimeException('An emitted but independently unreadable Cisco timezone payload must preserve its build warning');
}

$dialXml = (new DialTemplateGenerator())->generate([
    ['match'=>'3..', 'timeout'=>1],
    ['match'=>'', 'timeout'=>0, 'line'=>2, 'rewrite'=>'390'],
    ['match'=>'\\*', 'timeout'=>4],
]);
$dialRepeat = (new DialTemplateGenerator())->generate([
    ['match'=>'3..', 'timeout'=>1],
    ['match'=>'', 'timeout'=>0, 'line'=>2, 'rewrite'=>'390'],
    ['match'=>'\\*', 'timeout'=>4],
]);
if ($dialXml !== $dialRepeat) {
    throw new RuntimeException('Identical Cisco dial templates must produce byte-identical preview and apply output');
}
foreach (['<dialTemplate>', '<TEMPLATE match="3.." timeout="1"/>', '<TEMPLATE match="" timeout="0" line="2" rewrite="390"/>', '<TEMPLATE match="\\*" timeout="4"/>'] as $needle) {
    expectContains($needle, $dialXml, 'Cisco DialTemplate schema mismatch');
}
$dialSettings = ['_template_id'=>14, '_template_key'=>'f2dc45c3e9641248', 'dial_rules'=>[['match'=>'*','timeout'=>4]]];
$dialName = 'd' . substr(hash('sha256', DialTemplateGenerator::fromSettings($dialSettings)), 0, 12) . '.xml';
if (DialTemplateGenerator::filenameFor($dialSettings) !== $dialName) {
    throw new RuntimeException('Cisco dial-template companion naming must use the final normalized bytes');
}
$dialContext = baseContext('8845', 'cisco_xml');
$dialContext['settings'] += $dialSettings + ['dial_rules'=>[['match'=>'*','timeout'=>4]]];
$dialContextXml = (new CiscoXmlGenerator())->generate($dialContext)['xml'];
expectContains(
    '<dialTemplate>' . $dialName . '</dialTemplate>',
    $dialContextXml,
    'SEP config must reference the same content-key dial-template name written by the provisioner'
);
$changedRules = $dialSettings;
$changedRules['dial_rules'][0]['timeout'] = 6;
if (DialTemplateGenerator::filenameFor($changedRules) === $dialName) {
    throw new RuntimeException('Per-phone dial rules collided under the same template stack');
}
$sameBytes = $dialSettings;
$sameBytes['_template_key'] = 'other-template';
if (DialTemplateGenerator::filenameFor($sameBytes) !== $dialName) {
    throw new RuntimeException('Identical normalized dialplan bytes must share one companion identity');
}
expectNotContains(
    '<dialTemplate>dialplan-tpl14.xml</dialTemplate>',
    $dialContextXml,
    'SEP config must not reference the template row id when the companion is stack-keyed'
);
$provisionerSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Provisioner.php');
if (substr_count($provisionerSource, 'DialTemplateGenerator::filenameFor') < 2) {
    throw new RuntimeException('Transactional and legacy TFTP writers must share the SEP dial-template filename resolver');
}

$deskContext = baseContext('GRP2634', 'grandstream');
$deskContext['model'] += ['sip_accounts'=>12,'programmable_keys'=>10,'config_profile'=>'grandstream_grp'];
$desk = (new GrandstreamGenerator())->generate($deskContext)['content'];
foreach (['<P271>1</P271>', '<P47>10.0.0.10</P47>', '<P35>301</P35>', '<P36>301</P36>', '<P34>primary-secret</P34>', '<P130>1</P130>', '<P1363>31</P1363>', '<P1364>1</P1364>', '<P1365>1</P1365>', '<P1467>Bob</P1467>', '<P1468>303</P1468>'] as $needle) {
    expectContains($needle, $desk, 'Grandstream desk P-value mapping mismatch');
}
$grandstreamFirmware = baseContext('GRP2634', 'grandstream');
$grandstreamFirmware['settings'] += ['gs_P6767' => '1', 'gs_P192' => 'firmware.example/gs'];
$grandstreamFirmwareXml = (new GrandstreamGenerator())->generate($grandstreamFirmware)['content'];
expectContains('<P192>firmware.example/gs</P192>', $grandstreamFirmwareXml, 'Grandstream firmware server mapping missing');

$ghp = baseContext('GHP631', 'grandstream');
$ghp['model']['line_keys'] = 7;
$ghp['settings']['hosted_image_url'] = 'http://10.0.0.10/media/ghp.png';
$ghp['settings'] += [
    'gs_P8500'=>'0', 'gs_P102'=>'4',
    'gs_P104'=>'6', 'gs_P423'=>'7',
    'gs_P238'=>'2', 'gs_P194'=>'3', 'gs_P193'=>'10080',
    'gs_P285'=>'1', 'gs_P8459'=>'4', 'gs_P286'=>'1', 'gs_P8458'=>'1',
];
$ghp['settings']['ghp_speed_dials'] = [['digit'=>'1','mode'=>'0','account'=>0,'label'=>'Lobby','target'=>'100']];
$ghp['lines'][2] = ['button'=>2,'feature'=>'7','featureId'=>7,'label'=>'Call back','speedDialNumber'=>'','icon'=>'C-3'];
$ghp['lines'][] = ['button'=>3,'feature'=>'-1','featureId'=>-1,'label'=>'Disabled','speedDialNumber'=>''];
$ghpXml = (new GrandstreamGenerator())->generate($ghp)['content'];
foreach ([
    '<P2916>1</P2916>', '<P2917>http://10.0.0.10/media/ghp.png</P2917>',
    '<P23990>C-3</P23990>', '<P1363>7</P1363>', '<P1465>Call back</P1465>', '<P1365>-1</P1365>',
    '<P324>0</P324>', '<P305>Lobby</P305>', '<P306>100</P306>',
    '<P8500>0</P8500>', '<P102>4</P102>', '<P104>6</P104>', '<P423>7</P423>',
    '<P238>2</P238>', '<P194>3</P194>', '<P193>10080</P193>',
    '<P285>1</P285>', '<P8459>4</P8459>', '<P286>1</P286>', '<P8458>1</P8458>',
] as $needle) {
    expectContains($needle, $ghpXml, 'Grandstream GHP630/631 model-specific mapping mismatch');
}

$dp = baseContext('DP752', 'grandstream');
$dp['settings']['gs_dect_account_1_handset'] = '1';
$dpXml = (new GrandstreamGenerator())->generate($dp)['content'];
foreach (['<P4060>301</P4060>', '<P4090>301</P4090>', '<P4120>primary-secret</P4120>', '<P27080>1</P27080>', '<P4300>1</P4300>'] as $needle) {
    expectContains($needle, $dpXml, 'Grandstream DP75x mapping mismatch');
}

$yealink = baseContext('W70B', 'yealink');
$yealink['settings']['yl_dect_account_1_handsets'] = '1,3';
$yl = (new YealinkGenerator())->generate($yealink)['content'];
expectContains('account.1.sip_server.1.address = 10.0.0.10', $yl, 'Yealink SIP server key mismatch');
expectContains('account.1.handset.1.enable = 1', $yl, 'Yealink DECT handset mapping missing');
expectContains('account.1.handset.3.enable = 1', $yl, 'Yealink multi-handset mapping missing');
expectNotContains('linekey.1.type', $yl, 'DECT bases must not receive desk-phone linekey mappings');
$yealinkFirmware = baseContext('T48U', 'yealink');
$yealinkFirmware['settings']['static.firmware.url'] = 'http://firmware.example/t48u.rom';
expectContains('static.firmware.url = http://firmware.example/t48u.rom', (new YealinkGenerator())->generate($yealinkFirmware)['content'], 'Yealink firmware URL mapping missing');

$ylKem = baseContext('T48U', 'yealink');
$ylKem['model']['line_keys'] = 29;
$ylKem['model']['kem_support'] = 1;
$ylKem['settings']['_base_line_keys'] = 29;
$ylKem['settings']['yealink_timezone'] = '+3:30';
$ylKem['settings']['hosted_image_url'] = 'http://10.0.0.10/wallpaper.png';
$ylKem['settings']['yealink_wallpaper_file'] = 'wallpaper.png';
$ylKem['lines'][] = ['button' => 30, 'feature' => 'blf', 'featureId' => 21, 'label' => 'EXP BLF', 'speedDialNumber' => '399'];
$ylKemCfg = (new YealinkGenerator())->generate($ylKem)['content'];
foreach (['local_time.time_zone = +3:30', 'wallpaper_upload.url = http://10.0.0.10/wallpaper.png', 'phone_setting.backgrounds = wallpaper.png', 'expansion_module.1.key.1.type = 16', 'expansion_module.1.key.1.value = 399'] as $needle) {
    expectContains($needle, $ylKemCfg, 'Yealink expansion/wallpaper/time rendering mismatch');
}

$mesh = new PublishMesh(new stdClass());
$normalize = new ReflectionMethod(PublishMesh::class, 'normalizePeer');
$build = new ReflectionMethod(PublishMesh::class, 'buildPeerSections');
$peer = $normalize->invoke($mesh, [
    'key' => 'site-b', 'name' => 'SITE-B',
    'endpoint' => 'SITEA-SITEB_PJSIP', 'peer_resource' => 'SITEB-SITEA_PJSIP',
    'local_addr' => '192.0.2.10', 'local_port' => 5160,
    'peer_addr' => '198.51.100.10', 'peer_port' => 5160,
]);
$meshSections = $build->invoke($mesh, $peer);
if (count($meshSections) !== 3) {
    throw new RuntimeException('A new explicit BLF peer must plan exactly three publication objects');
}
$meshText = implode("\n", $meshSections);
expectContains('outbound_auth=SITEA-SITEB_PJSIP', $meshText, 'Mesh must reuse the selected trunk auth object');
expectContains('server_uri=sip:SITEB-SITEA_PJSIP@198.51.100.10:5160', $meshText, 'Mesh peer resource URI mismatch');
expectContains('from_uri=sip:SITEA-SITEB_PJSIP@192.0.2.10:5160', $meshText, 'Mesh local URI mismatch');

$filteredMeshUcm = new class {
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['id'=>1,'name'=>'SITE-A','pbx_addr'=>'192.0.2.10','pbx_sip_port'=>5160]; }
    public function getSites(): array { return [
        ['id'=>1,'is_local'=>1,'enabled'=>1,'site_key'=>'site-a','name'=>'SITE-A','trunk_endpoint'=>'','remote_trunk_endpoint'=>'','pbx_addr'=>'192.0.2.10','pbx_sip_port'=>5160],
        ['id'=>3,'is_local'=>0,'enabled'=>1,'site_key'=>'site-b','name'=>'SITE-B','trunk_endpoint'=>'SITEA-SITEB_PJSIP','remote_trunk_endpoint'=>'SITEB-SITEA_PJSIP','pbx_addr'=>'198.51.100.10','pbx_sip_port'=>5160],
        ['id'=>4,'is_local'=>0,'enabled'=>1,'site_key'=>'site-c','name'=>'SITE-C','trunk_endpoint'=>'SITEA-SITEC_PJSIP','remote_trunk_endpoint'=>'SITEC-SITEA_PJSIP','pbx_addr'=>'203.0.113.10','pbx_sip_port'=>5160],
    ]; }
};
$filteredPlan = (new PublishMesh($filteredMeshUcm))->plan([3]);
if (($filteredPlan['peers'] ?? []) !== ['SITE-B']) {
    throw new RuntimeException('Targeted BLF rebuild must not plan unrelated federation peers');
}

$fakeUcm = new class {
    public $labelSource = 'phone_label';
    public $defaultRules = [];
    public $phonebookPolicy = 'hr';
    public function localSiteId(): int { return 1; }
    public function phoneServicesToken(): string { return '0123456789abcdef0123456789abcdef'; }
    public function getSiteSettings(int $siteId): array { return ['transport'=>'udp','cisco_directory_url'=>'http://10.0.0.10/ucmprovisioner-directory.php?token=abc&format=cisco']; }
    public function getSite(int $siteId): array { return ['pbx_addr'=>'10.0.0.10']; }
    public function getModel(int $id): array { return ['id'=>10,'vendor'=>'Cisco','model'=>'8845','family'=>'cisco_xml','line_keys'=>5,'kem_support'=>0]; }
    public function getGroup(int $id): array { return ['id'=>2,'site_id'=>1,'parent_group_id'=>1,'member_blf'=>0,'extra_targets'=>'[]','template_map'=>json_encode([
        ['template_id'=>8,'family'=>'cisco_xml','vendor'=>'Cisco','model'=>'8845','position'=>10],
    ])]; }
    public function getGroups(int $siteId): array { return [
        ['id'=>1,'site_id'=>1,'name'=>'Base','parent_group_id'=>null,'member_blf'=>0,'extra_targets'=>'[]','template_map'=>json_encode([
            ['template_id'=>7,'family'=>'cisco_xml','position'=>10],
        ])],
        $this->getGroup(2) + ['name'=>'HR management'],
    ]; }
    public function getTemplates(int $siteId, bool $includeGlobal=false): array { return [$this->getTemplate(7),$this->getTemplate(8)]; }
    public function getDefaultTemplateRules(int $siteId): array { return $this->defaultRules; }
    public function getTemplate(int $id): array { return [
        'id'=>$id,'name'=>$id===8?'HR 8845':'HR Cisco fallback','target_family'=>'cisco_xml','target_model'=>$id===8?'Cisco|8845':'','phonebook_key'=>$id===8?$this->phonebookPolicy:'','phonebook_filename'=>$id===8&&$this->phonebookPolicy==='hr'?'hr-handsets.xml':'','phonebook_source_site'=>$id===8&&$this->phonebookPolicy==='hr'?'site-b':'','phonebook_source_url'=>$id===8&&$this->phonebookPolicy==='hr'?'https://directory.example.test:8443/ucmprovisioner-directory.php?token=source-token':'','setting_overrides'=>json_encode(['blf_label_source'=>$this->labelSource,'transport'=>'tls'] + ($id===7?['ringtone'=>'InheritedClassic']:['cisco_backlight_idle_timeout'=>600])),'softkeys'=>$id===8?json_encode(['Connected'=>['EndCall','Join','RmLstC','Select','VidMode']]):'{}','feature_policy'=>'{}','member_blf'=>0,
        'buttons'=>json_encode($id===8 ? [['feature'=>'blf','target'=>'227','label'=>'','target_site'=>3]] : [['feature'=>'blf','target'=>'999','label'=>'Fallback']]),
    ]; }
    public function getAllSipExtensions(?int $siteId=null): array { return $siteId===3 ? [['id'=>'227','description'=>'Branch Reception']] : []; }
    public function getPhones(array $filters=[]): array { return (int)($filters['site_id'] ?? 0)===3 ? [['extension'=>'227','label'=>'BRANCH-RCP']] : []; }
    public function getSites(): array { return [['id'=>1,'site_key'=>'site-a'],['id'=>3,'site_key'=>'site-b']]; }
};
$resolved = (new TemplateEngine($fakeUcm))->resolvePhone([
    'id'=>1,'site_id'=>1,'group_id'=>2,'model_id'=>10,'vendor'=>'Cisco','family'=>'cisco_xml','extension'=>'4201','label'=>'HR User','lines_json'=>'[]','overrides'=>'','transport'=>'tcp',
]);
if (($resolved['settings']['_template_id'] ?? 0) !== 8) {
    throw new RuntimeException('An exact 8845 group template must win over the Cisco-family fallback');
}
if (($resolved['settings']['_template_stack'] ?? []) !== [7,8]
    || ($resolved['settings']['ringtone'] ?? '') !== 'InheritedClassic'
    || ($resolved['settings']['cisco_backlight_idle_timeout'] ?? 0) !== 600) {
    throw new RuntimeException('Parent and leaf templates must merge root-to-leaf with a visible stack');
}
if (($resolved['settings']['transport'] ?? '') !== 'tcp'
    || !str_contains((new CiscoXmlGenerator())->generate($resolved)['xml'], '<transportLayerProtocol>1</transportLayerProtocol>')) {
    throw new RuntimeException('The phone transport must override conflicting site/template transports in resolved and generated configuration');
}
if (($resolved['settings']['_softkey_layout']['Connected'] ?? []) !== ['EndCall','Join','RmLstC','Select','VidMode']) {
    throw new RuntimeException('Model preview/build must never silently filter the shared operator softkey policy');
}
$fakeUcm->defaultRules = [['id'=>1,'template_id'=>8,'target_model'=>'Cisco|8845','enabled'=>1]];
$ungroupedDefault = (new TemplateEngine($fakeUcm))->resolvePhone([
    'id'=>3,'site_id'=>1,'group_id'=>null,'model_id'=>10,'vendor'=>'Cisco','family'=>'cisco_xml','extension'=>'4203','label'=>'Default User','lines_json'=>'[]','overrides'=>'','transport'=>'udp',
]);
if (($ungroupedDefault['settings']['_template_stack'] ?? []) !== [8]
    || count(array_filter(
        $ungroupedDefault['lines'],
        static fn(array $line): bool => (int)($line['featureId'] ?? 0) === 21
            && (string)($line['speedDialNumber'] ?? '') === '227'
    )) !== 1) {
    throw new RuntimeException('An ungrouped phone must receive both settings and buttons from its matching default template');
}
$fakeUcm->defaultRules = [];
expectContains('book=hr&file=hr-handsets.xml', (string)($resolved['settings']['cisco_directory_url'] ?? ''), 'The selected managed phonebook and filename alias must reach the generated phone settings');
expectContains('https://directory.example.test:8443/ucmprovisioner-directory.php?token=source-token&format=cisco', (string)($resolved['settings']['cisco_directory_url'] ?? ''), 'A template-selected source PBX must use the source token while preserving the vendor format');
$fakeUcm->phonebookPolicy = TemplateEngine::PHONEBOOK_NONE;
$noPhonebook = (new TemplateEngine($fakeUcm))->resolvePhone([
    'id'=>4,'site_id'=>1,'group_id'=>2,'model_id'=>10,'vendor'=>'Cisco','family'=>'cisco_xml','extension'=>'4204','label'=>'No Directory','lines_json'=>'[]','overrides'=>'','transport'=>'udp',
]);
foreach (['cisco_directory_url','cisco_directoryURL','cisco_legacy_directory_url','directory_url','yealink_phonebook_url','grandstream_phonebook_url','phonebook_url'] as $directoryKey) {
    if (array_key_exists($directoryKey, $noPhonebook['settings'])) {
        throw new RuntimeException('Explicit no-phonebook policy must remove ' . $directoryKey);
    }
}
$fakeUcm->phonebookPolicy = 'hr';
$serviceResolved = (new TemplateEngine($fakeUcm))->resolvePhone([
    'id'=>1,'site_id'=>1,'group_id'=>2,'model_id'=>10,'vendor'=>'Cisco','family'=>'cisco_xml','mac'=>'AABBCCDDEEFF','extension'=>'4201','label'=>'HR User','lines_json'=>'[]','overrides'=>'','transport'=>'udp',
]);
$expectedCiscoService = 'http://10.0.0.10/ucmp-s.php/0123456789abcdef0123456789abcdef/cisco/aabbccddeeff';
if (($serviceResolved['settings']['_phone_services_url'] ?? '') !== $expectedCiscoService
    || ($serviceResolved['settings']['cisco_servicesURL'] ?? '') !== $expectedCiscoService) {
    throw new RuntimeException('Cisco XML service URL must contain the managed token, actual family format, and current phone MAC');
}
$serviceUrl = new ReflectionMethod(TemplateEngine::class, 'phoneServicesUrl');
$serviceUrl->setAccessible(true);
$legacyServiceUrl = $serviceUrl->invoke(
    new TemplateEngine($fakeUcm),
    'cisco_cnf',
    ['mac'=>'AABBCCDDEEFF'],
    ['pbx_addr'=>'10.0.0.10','phone_services_base_url'=>'https://phones.example.test:8443/pbx/']
);
if ($legacyServiceUrl !== 'https://phones.example.test:8443/pbx/ucmp-s.php/0123456789abcdef0123456789abcdef/cisco_legacy/aabbccddeeff') {
    throw new RuntimeException('Legacy Cisco service URL must preserve the configured authority, port, and base path');
}
$modelMatch = new ReflectionMethod(TemplateEngine::class, 'templateMatchesModel');
$modelMatch->setAccessible(true);
if ($modelMatch->invoke(new TemplateEngine($fakeUcm), ['target_model'=>'Cisco|8845'], ['vendor'=>'Cisco','model'=>'7841'])) {
    throw new RuntimeException('A template saved for 8845 must not apply to a 7841');
}
$profileAssets = new ReflectionMethod(TemplateEngine::class, 'resolveProfileAssets');
$profileAssets->setAccessible(true);
$mapped = [
    'firmware_defaults'=>json_encode(['cisco:sip88xx'=>'sip88xx.site-default']),
    'firmware_by_profile'=>['cisco:sip8845_65'=>'sip8845_65.template'],
    'cisco_wallpaper_defaults'=>json_encode([
        \FreePBX\modules\Ucmprovisioner\ModelCatalog::backgroundProfileKey('Cisco','8845')=>'Company-800x480.png',
    ]),
    'hosted_image_defaults'=>json_encode(['yealink'=>'http://10.0.0.10/media/yealink.png']),
];
$profileAssets->invokeArgs(new TemplateEngine($fakeUcm), ['cisco_xml',['vendor'=>'Cisco','model'=>'8845'],&$mapped]);
if (($mapped['firmware'] ?? '') !== 'sip8845_65.template' || ($mapped['firmware_profile'] ?? '') !== 'cisco:sip8845_65') {
    throw new RuntimeException('A broad template must resolve firmware by the actual phone firmware profile');
}
$wrongProfile = ['firmware'=>'sip78xx.explicit','firmware_profile'=>'cisco:sip78xx'];
$profileAssets->invokeArgs(new TemplateEngine($fakeUcm), ['cisco_xml',['vendor'=>'Cisco','model'=>'8845'],&$wrongProfile]);
if ($wrongProfile['firmware_profile'] !== 'cisco:sip78xx') {
    throw new RuntimeException('An explicit firmware profile must reach compatibility validation unchanged');
}
$assertFirmware = new ReflectionMethod(TemplateEngine::class, 'assertFirmwareSelection');
try {
    $assertFirmware->invoke(new TemplateEngine($fakeUcm),1,['vendor'=>'Cisco','model'=>'8845'],$wrongProfile);
    throw new LogicException('A wrong explicit firmware profile passed validation');
} catch (RuntimeException $expected) {
    if (!str_contains($expected->getMessage(), 'does not match')) { throw $expected; }
}
if (($mapped['cisco_default_wallpaper'] ?? '') !== 'Company-800x480.png') {
    throw new RuntimeException('A broad template must resolve wallpaper by the actual Cisco screen profile');
}
$yealinkProfile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::hostedMediaProfileKey('Yealink','T48U');
$hostedMapped = ['hosted_image_defaults'=>json_encode([$yealinkProfile=>'http://10.0.0.10/media/t48u.png'])];
$profileAssets->invokeArgs(new TemplateEngine($fakeUcm), ['yealink',['vendor'=>'Yealink','model'=>'T48U'],&$hostedMapped]);
if (($hostedMapped['hosted_image_url'] ?? '') !== 'http://10.0.0.10/media/t48u.png') {
    throw new RuntimeException('A broad template must resolve PBX-hosted media by the actual phone screen profile');
}
if (($resolved['lines'][1]['label'] ?? '') !== 'BRANCH-RCP') {
    throw new RuntimeException('A blank remote BLF label must default to the target phone short label');
}
$fakeUcm->labelSource = 'extension_name';
$resolvedFullName = (new TemplateEngine($fakeUcm))->resolvePhone([
    'id'=>1,'site_id'=>1,'group_id'=>2,'model_id'=>10,'vendor'=>'Cisco','family'=>'cisco_xml','extension'=>'4201','label'=>'HR User','lines_json'=>'[]','overrides'=>'','transport'=>'udp',
]);
if (($resolvedFullName['lines'][1]['label'] ?? '') !== 'Branch Reception') {
    throw new RuntimeException('A template must be able to opt into the full FreePBX extension name for BLF labels');
}

$resolvedMultiAccount = (new TemplateEngine($fakeUcm))->resolvePhone([
    'id'=>1,'site_id'=>1,'group_id'=>2,'model_id'=>10,'vendor'=>'Cisco','family'=>'cisco_xml','extension'=>'4201','label'=>'HR User',
    'lines_json'=>json_encode([['account'=>2,'button'=>2,'extension'=>'4202','label'=>'Second line']]),
    'overrides'=>json_encode(['buttons'=>[['slot'=>3,'feature'=>'speeddial','target'=>'700','label'=>'Service']]]),'transport'=>'udp',
]);
$registrations = array_values(array_filter($resolvedMultiAccount['lines'], static fn(array $line): bool => (int)($line['featureId'] ?? 0) === 9));
if (array_column($registrations, 'name') !== ['4201','4202']) {
    throw new RuntimeException('A per-phone programmable-button override must preserve every SIP registration on the physical phone');
}
$overrideButtons = array_values(array_filter($resolvedMultiAccount['lines'], static fn(array $line): bool => (int)($line['featureId'] ?? 0) !== 9));
if (count($overrideButtons) !== 1 || ($overrideButtons[0]['speedDialNumber'] ?? '') !== '700') {
    throw new RuntimeException('A per-phone programmable-button override must replace only non-registration appearances');
}

$ghpButtons = [];
for ($position = 1; $position <= 7; $position++) {
    $ghpButtons[] = [
        'feature'=>'speeddial',
        'target'=>'81' . str_pad((string)$position, 2, '0', STR_PAD_LEFT),
        'label'=>'Context ' . $position,
    ];
}
$ghpUcm = new class($ghpButtons) {
    private array $buttons;
    public function __construct(array $buttons) { $this->buttons = $buttons; }
    public function localSiteId(): int { return 1; }
    public function getSiteSettings(int $siteId): array {
        return ['pbx_addr'=>'10.0.0.10', 'sip_port'=>'5060', 'transport'=>'udp'];
    }
    public function getSite(int $siteId): array { return ['pbx_addr'=>'10.0.0.10']; }
    public function getModel(int $id): array {
        return [
            'id'=>30, 'vendor'=>'Grandstream', 'model'=>'GHP631', 'family'=>'grandstream',
            'role'=>'desk', 'line_keys'=>7, 'programmable_keys'=>16, 'sip_accounts'=>2,
            'config_profile'=>'grandstream_ghp', 'kem_support'=>0,
        ];
    }
    public function getTemplates(int $siteId, bool $includeGlobal=false): array { return [$this->template()]; }
    public function getTemplate(int $id, ?int $siteId=null): array { return $this->template(); }
    public function getDefaultTemplateRules(int $siteId): array {
        return [['id'=>1, 'template_id'=>30, 'target_model'=>'Grandstream|GHP631', 'enabled'=>1]];
    }
    private function template(): array {
        return [
            'id'=>30, 'name'=>'GHP context capacity', 'target_family'=>'grandstream',
            'target_model'=>'Grandstream|GHP631', 'target_series'=>'', 'target_role'=>'',
            'phonebook_key'=>'', 'phonebook_filename'=>'', 'phonebook_source_site'=>'',
            'phonebook_source_url'=>'',
            'setting_overrides'=>json_encode([
                'ghp_speed_dials'=>[
                    ['digit'=>'0', 'mode'=>'0', 'account'=>0, 'label'=>'Operator', 'target'=>'8190'],
                ],
            ]),
            'buttons'=>json_encode($this->buttons), 'capacity_overrides'=>'{}',
            'softkeys'=>'{}', 'feature_policy'=>'{}', 'member_blf'=>0,
        ];
    }
};
$resolvedGhp = (new TemplateEngine($ghpUcm))->resolvePhone([
    'id'=>30, 'site_id'=>1, 'group_id'=>null, 'model_id'=>30,
    'vendor'=>'Grandstream', 'family'=>'grandstream', 'extension'=>'8100', 'label'=>'Guest',
    'lines_json'=>json_encode([
        ['account'=>2, 'extension'=>'8200', 'label'=>'Guest alternate'],
    ]),
    'overrides'=>'', 'transport'=>'udp',
]);
$ghpRegistrations = array_values(array_filter(
    $resolvedGhp['lines'],
    static fn(array $line): bool => (int)($line['featureId'] ?? 0) === 9
));
$ghpContextLines = array_values(array_filter(
    $resolvedGhp['lines'],
    static fn(array $line): bool => (int)($line['featureId'] ?? 0) !== 9
));
if (($resolvedGhp['settings']['_base_line_keys'] ?? 0) !== 7
    || ($resolvedGhp['settings']['_total_line_keys'] ?? 0) !== 7
    || count($ghpRegistrations) !== 2
    || count($ghpContextLines) !== 6
    || array_column($ghpContextLines, 'button') !== [2,3,4,5,6,7]) {
    throw new RuntimeException('GHP63x resolution must keep two SIP accounts while allocating exactly six context keys');
}
$generatedGhp = (new GrandstreamGenerator())->generate($resolvedGhp);
foreach (['8101','8102','8103','8104','8105','8106','8190'] as $target) {
    expectContains($target, $generatedGhp['content'], "GHP63x end-to-end output lost target $target");
}
expectNotContains('8107', $generatedGhp['content'], 'A seventh generic row must never reach a GHP63x context-key parameter');
if (array_filter(
    $generatedGhp['warnings'],
    static fn(string $warning): bool => str_contains($warning, 'outside the six context keys')
)) {
    throw new RuntimeException('The resolver must cap GHP63x auto-flow before the generator has to drop an overflow row');
}

$dectUcm = new class {
    public function localSiteId(): int { return 1; }
    public function getSiteSettings(int $siteId): array { return []; }
    public function getSite(int $siteId): array { return ['pbx_addr'=>'10.0.0.10']; }
    public function getModel(int $id): array { return ['id'=>20,'vendor'=>'Grandstream','model'=>'DP752','family'=>'grandstream','role'=>'dect_base','line_keys'=>0,'programmable_keys'=>0,'sip_accounts'=>10,'handsets_max'=>5,'kem_support'=>0]; }
};
$resolvedDect = (new TemplateEngine($dectUcm))->resolvePhone([
    'id'=>2,'site_id'=>1,'group_id'=>0,'model_id'=>20,'vendor'=>'Grandstream','family'=>'grandstream','extension'=>'4201','label'=>'DECT account 1',
    'lines_json'=>json_encode([['account'=>2,'button'=>0,'extension'=>'4202','label'=>'DECT account 2']]),
    'overrides'=>json_encode(['dect_handsets'=>[['slot'=>1,'model'=>'DP730','label'=>'Reception','identifier'=>'HS-01']]]),
    'transport'=>'udp',
]);
if (($resolvedDect['settings']['_base_line_keys'] ?? -1) !== 0
    || count($resolvedDect['lines']) !== 2
    || array_filter($resolvedDect['lines'], static fn(array $line): bool => (int)($line['button'] ?? -1) !== 0)
    || ($resolvedDect['settings']['dect_handsets'][0]['label'] ?? '') !== 'Reception') {
    throw new RuntimeException('A DECT base must register multiple SIP accounts without inventing physical line-key appearances');
}

$templateEngine = new TemplateEngine($fakeUcm);
$phonebookUrl = new ReflectionMethod(TemplateEngine::class, 'phonebookUrl'); $phonebookUrl->setAccessible(true);
$legacyUrl = $phonebookUrl->invoke($templateEngine, 'http://10.0.0.10/ucmp-d.php/abcdef1234567890', 'hr', 'ignored.xml');
if ($legacyUrl !== 'http://10.0.0.10/ucmp-d.php/abcdef1234567890/hr' || str_contains($legacyUrl, '?') || str_contains($legacyUrl, '&')) {
    throw new RuntimeException('Legacy Cisco phonebook selection must remain a query-free short URL');
}
$legacyPatternUrl = $phonebookUrl->invoke($templateEngine, $legacyUrl, 'hr', 'ignored.xml', '<{initial}.{surname}>');
if ($legacyPatternUrl !== $legacyUrl) {
    throw new RuntimeException('Legacy Cisco directory label formatting must not break its query-free short URL');
}
$modernPatternUrl = $phonebookUrl->invoke(
    $templateEngine,
    'http://10.0.0.10/ucmprovisioner-directory.php?token=abcdef1234567890&format=cisco',
    'hr',
    'hr.xml',
    '<{initial}.{surname}>'
);
parse_str((string)parse_url($modernPatternUrl, PHP_URL_QUERY), $modernPatternQuery);
if (($modernPatternQuery['book'] ?? '') !== 'hr'
    || ($modernPatternQuery['label_pattern'] ?? '') !== '<{initial}.{surname}>') {
    throw new RuntimeException('Modern phonebook URLs must carry the selected template directory-label pattern');
}
$sourceUrl = new ReflectionMethod(TemplateEngine::class, 'phonebookSourceUrl'); $sourceUrl->setAccessible(true);
$legacyRemote = $sourceUrl->invoke($templateEngine, $legacyUrl, 'http://10.0.0.20/ucmprovisioner-directory.php?token=0123456789abcdef');
if ($legacyRemote !== 'http://10.0.0.20/ucmp-d.php/0123456789abcdef') {
    throw new RuntimeException('A remote legacy directory must use the selected source PBX and bearer token');
}

echo "Generator and federation fixtures passed\n";
