<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/GrandstreamGenerator.php';
require_once dirname(__DIR__) . '/lib/YealinkGenerator.php';

use FreePBX\modules\Ucmprovisioner\GrandstreamGenerator;
use FreePBX\modules\Ucmprovisioner\YealinkGenerator;

function vendorAssertContains(string $needle, string $text): void
{
    if (strpos($text, $needle) === false) {
        throw new RuntimeException("Missing expected vendor setting: $needle");
    }
}

function vendorAssertNotContains(string $needle, string $text): void
{
    if (strpos($text, $needle) !== false) {
        throw new RuntimeException("Unexpected vendor setting: $needle");
    }
}

function vendorLine(int $account, int $button): array
{
    return [
        'button'=>$button, 'feature'=>'line', 'featureId'=>9,
        'accountIndex'=>$account, 'lineIndex'=>$account,
        'name'=>(string)(300 + $account), 'authName'=>(string)(300 + $account),
        'label'=>'User ' . $account, 'secret'=>'secret-' . $account,
    ];
}

$yealink = new YealinkGenerator();
$ylContext = [
    'phone'=>['mac'=>'001122AABBCC'],
    'model'=>[
        'model'=>'T48U', 'role'=>'desk', 'sip_accounts'=>4, 'programmable_keys'=>2,
        'expansion_types'=>['EXP43'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>60,
    ],
    'settings'=>[
        'pbx_addr'=>'10.0.0.10', 'sip_port'=>5060, 'transport'=>'udp',
        'firmware_bundle_url'=>'http://10.0.0.10/fw/96.86.0.70.rom',
    ],
    'lines'=>[
        vendorLine(1, 1), vendorLine(2, 2), vendorLine(3, 3),
        ['button'=>4, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Gap BLF', 'speedDialNumber'=>'399'],
        ['button'=>64, 'feature'=>'blf', 'featureId'=>21, 'label'=>'EXP gap', 'speedDialNumber'=>'398'],
    ],
];
$ylContext['lines'][1]['authName'] = 'auth-302';
$ylResult = $yealink->generate($ylContext);
$ylCfg = $ylResult['content'];
foreach ([
    'account.3.enable = 1',
    'account.2.auth_name = auth-302',
    'account.1.transport = 0',
    'static.firmware.url = http://10.0.0.10/fw/96.86.0.70.rom',
    'expansion_module.1.key.2.type = 16',
    'expansion_module.1.key.2.value = 399',
    'expansion_module.2.key.2.value = 398',
] as $needle) {
    vendorAssertContains($needle, $ylCfg);
}
vendorAssertNotContains('linekey.3.type = 15', $ylCfg); // account 3 registers without inventing a physical key

$ylDect = $ylContext;
$ylDect['model'] = [
    'model'=>'W70B', 'role'=>'dect_base', 'sip_accounts'=>10, 'handsets_max'=>10,
    'config_profile'=>'yealink_dect_single',
];
$ylDect['settings']['static.auto_provision.handset_configured.enable'] = 1;
$ylDect['settings']['static.auto_provision.custom.handset.protect'] = 1;
$ylDect['settings']['custom.handset.time_format'] = 1;
$ylDect['settings']['custom.handset.backlight_in_charger.enable'] = 0;
$ylDect['settings']['yl_dect_account_2_handsets'] = '2,4';
$ylDect['lines'] = [vendorLine(1, 0), vendorLine(2, 0)];
$ylDectCfg = $yealink->generate($ylDect)['content'];
foreach ([
    'account.2.handset.2.enable = 1',
    'account.2.handset.4.enable = 1',
    'static.auto_provision.handset_configured.enable = 1',
    'static.auto_provision.custom.handset.protect = 1',
    'custom.handset.time_format = 1',
    'custom.handset.backlight_in_charger.enable = 0',
] as $needle) {
    vendorAssertContains($needle, $ylDectCfg);
}

$ylDeskWithDectPolicy = $ylContext;
$ylDeskWithDectPolicy['settings']['custom.handset.time_format'] = 1;
$ylDeskWithDectPolicy['settings']['static.auto_provision.handset_configured.enable'] = 1;
$ylDeskPolicyCfg = $yealink->generate($ylDeskWithDectPolicy)['content'];
vendorAssertNotContains('custom.handset.time_format', $ylDeskPolicyCfg);
vendorAssertNotContains('static.auto_provision.handset_configured.enable', $ylDeskPolicyCfg);

$ylVendorFirmware = $ylContext;
$ylVendorFirmware['settings']['firmware_bundle_url'] = '';
$ylVendorFirmware['settings']['yealink_firmware_url'] = 'http://10.0.0.10/fw/t48u.rom';
vendorAssertContains(
    'static.firmware.url = http://10.0.0.10/fw/t48u.rom',
    $yealink->generate($ylVendorFirmware)['content']
);

$ylHandset = $ylContext;
$ylHandset['model'] = ['model'=>'W71H', 'role'=>'handset'];
$ylHandsetResult = $yealink->generate($ylHandset);
if (!array_key_exists('filename', $ylHandsetResult) || $ylHandsetResult['filename'] !== null || empty($ylHandsetResult['error'])) {
    throw new RuntimeException('A Yealink handset must return a clear non-generating result.');
}

$grandstream = new GrandstreamGenerator();
$gsContext = [
    'phone'=>['mac'=>'001122AABBCC'],
    'model'=>['model'=>'GRP2634', 'role'=>'desk', 'sip_accounts'=>8, 'programmable_keys'=>9, 'config_profile'=>'grandstream_grp'],
    'settings'=>[
        'pbx_addr'=>'10.0.0.10', 'sip_port'=>5060, 'transport'=>'tcp',
        'hosted_image_url'=>'http://10.0.0.10/media/wallpaper.jpg',
        'firmware_bundle_url'=>'https://firmware.example/grp/1.0.13.127',
        'grandstream_ringtone'=>7,
    ],
    'lines'=>[
        vendorLine(1, 1), vendorLine(5, 5),
        ['button'=>4, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Fourth position', 'speedDialNumber'=>'399'],
        ['button'=>8, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Dynamic gap', 'speedDialNumber'=>'398'],
    ],
];
$gsXml = $grandstream->generate($gsContext)['content'];
foreach ([
    '<P130>1</P130>', '<P1701>1</P1701>', '<P1704>305</P1704>', '<P1748>1</P1748>',
    '<P2916>1</P2916>', '<P2917>http://10.0.0.10/media/wallpaper.jpg</P2917>',
    '<P6767>2</P6767>', '<P192>firmware.example/grp/1.0.13.127</P192>', '<P8398>7</P8398>',
    '<P1367>1</P1367>', '<P1469>Fourth position</P1469>', '<P1470>399</P1470>',
    '<P1369>31</P1369>', '<P1370>4</P1370>',
    '<P1371>-1</P1371>', '<P1373>-1</P1373>',
    '<P23800>1</P23800>', '<P23802>Dynamic gap</P23802>', '<P23803>398</P23803>',
] as $needle) {
    vendorAssertContains($needle, $gsXml);
}
vendorAssertNotContains('<P1362>', $gsXml);
vendorAssertNotContains('<P323>', $gsXml); // the button-4 assignment must not collapse into the first MPK

$ghp = $gsContext;
$ghp['model'] = [
    'model'=>'GHP631', 'role'=>'desk', 'sip_accounts'=>2,
    'programmable_keys'=>16, 'config_profile'=>'grandstream_ghp',
];
$ghp['lines'] = [
    vendorLine(1, 1),
    ['button'=>2, 'feature'=>'speed_dial', 'featureId'=>2, 'label'=>'Front door', 'speedDialNumber'=>'399', 'icon'=>'fd-1'],
    ['button'=>3, 'feature'=>'none', 'featureId'=>-1, 'label'=>'Disabled', 'speedDialNumber'=>''],
    ['button'=>4, 'feature'=>'speed_dial', 'featureId'=>2, 'label'=>'Invalid icon', 'speedDialNumber'=>'397', 'icon'=>'ZZ-9'],
];
$ghp['settings']['ghp_speed_dials'] = [
    ['digit'=>'1', 'mode'=>27, 'account'=>0, 'label'=>'Door', 'target'=>'399'],
    ['digit'=>'2', 'mode'=>6, 'account'=>0, 'label'=>'Unsupported', 'target'=>'398'],
    ['digit'=>'3', 'mode'=>0, 'account'=>1, 'label'=>str_repeat('L', 100), 'target'=>str_repeat('9', 100)],
];
$ghpResult = $grandstream->generate($ghp);
foreach ([
    '<P23990>FD-1</P23990>', '<P1363>0</P1363>', '<P1365>-1</P1365>',
    '<P1367>0</P1367>', '<P1470>397</P1470>',
    '<P324>27</P324>', '<P306>399</P306>',
    '<P326>0</P326>', '<P311>' . str_repeat('L', 100) . '</P311>', '<P312>' . str_repeat('9', 100) . '</P312>',
] as $needle) {
    vendorAssertContains($needle, $ghpResult['content']);
}
vendorAssertNotContains('<P325>6</P325>', $ghpResult['content']);
vendorAssertNotContains('<P23992>ZZ-9</P23992>', $ghpResult['content']);
if (!array_filter($ghpResult['warnings'], static fn(string $warning): bool => strpos($warning, 'unsupported mode 6') !== false)) {
    throw new RuntimeException('An unsupported GHP631 digit-key mode must be reported and skipped.');
}
if (!array_filter($ghpResult['warnings'], static fn(string $warning): bool => strpos($warning, 'unsupported icon code ZZ-9') !== false)) {
    throw new RuntimeException('An unsupported GHP631 context-key icon must be reported and omitted.');
}

$gsVendorFirmware = $gsContext;
$gsVendorFirmware['settings']['firmware_bundle_url'] = '';
$gsVendorFirmware['settings']['grandstream_firmware_url'] = 'http://10.0.0.10/fw/grp';
vendorAssertContains('<P192>10.0.0.10/fw/grp</P192>', $grandstream->generate($gsVendorFirmware)['content']);

$gxp = $gsContext;
$gxp['model'] = [
    'model'=>'GXP2140', 'role'=>'desk', 'sip_accounts'=>4, 'programmable_keys'=>4,
    'config_profile'=>'grandstream_gxp', 'max_expansion_modules'=>4, 'keys_per_expansion'=>40,
    'expansion_types'=>['GXP2200EXT'],
];
$gxp['settings']['grandstream_expansion_count'] = 2;
$gxp['lines'] = [
    vendorLine(1, 1),
    ['button'=>3, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Fixed GXP BLF', 'speedDialNumber'=>'397'],
    ['button'=>46, 'feature'=>'blf', 'featureId'=>21, 'label'=>'EXT2 key2', 'speedDialNumber'=>'396'],
];
$gxpXml = $grandstream->generate($gxp)['content'];
foreach ([
    '<P1365>11</P1365>', '<P1467>Fixed GXP BLF</P1467>', '<P1468>397</P1468>',
    '<P23205>1</P23205>', '<P23207>EXT2 key2</P23207>', '<P23208>396</P23208>',
] as $needle) {
    vendorAssertContains($needle, $gxpXml);
}
vendorAssertContains('<P23000>-1</P23000>', $gxpXml); // earlier EXT gaps are deliberately cleared

$dpLines = [];
for ($index = 1; $index <= 20; $index++) {
    $dpLines[] = vendorLine($index, $index);
}
$dp755 = [
    'phone'=>['mac'=>'001122AABBCC'],
    'model'=>[
        'model'=>'DP755', 'role'=>'base', 'sip_accounts'=>20, 'handsets_max'=>10,
        'config_profile'=>'grandstream_dp755_alias_v2',
    ],
    'settings'=>[
        'pbx_addr'=>'10.0.0.10', 'sip_port'=>5060, 'transport'=>'tls',
        'gs_dect_account_20_handsets'=>'1,10',
        'firmware_bundle_url'=>'tftp://10.0.0.20/grandstream',
    ],
    'lines'=>$dpLines,
];
$dp755Xml = $grandstream->generate($dp755)['content'];
foreach ([
    '<config version="2">',
    '<item name="account.20.enable">Yes</item>',
    '<item name="account.20.sip.userid">320</item>',
    '<item name="account.20.sip.transport">Tls Or Tcp</item>',
    '<item name="account.20.hs1">Yes</item>',
    '<item name="account.20.hs10">Yes</item>',
    '<item name="provisioning.firmware.protocol">TFTP</item>',
    '<item name="provisioning.firmware.serverPath">10.0.0.20/grandstream</item>',
] as $needle) {
    vendorAssertContains($needle, $dp755Xml);
}
vendorAssertNotContains('<P4060>', $dp755Xml);

$dp752 = $dp755;
$dp752['model'] = ['model'=>'DP752', 'role'=>'base', 'sip_accounts'=>10, 'handsets_max'=>5, 'config_profile'=>'grandstream_dp75x_pvalue'];
$dp752['settings']['transport'] = 'udp';
$dp752['lines'] = array_slice($dpLines, 0, 10);
$dp752Xml = $grandstream->generate($dp752)['content'];
foreach (['<config version="1">', '<P4069>310</P4069>', '<P130>0</P130>', '<P4300>1</P4300>'] as $needle) {
    vendorAssertContains($needle, $dp752Xml);
}

$gsHandset = $gsContext;
$gsHandset['model'] = ['model'=>'DP730', 'role'=>'handset'];
$gsHandsetResult = $grandstream->generate($gsHandset);
if (!array_key_exists('filename', $gsHandsetResult) || $gsHandsetResult['filename'] !== null || empty($gsHandsetResult['error'])) {
    throw new RuntimeException('A Grandstream handset must return a clear non-generating result.');
}

$nativeYealink=$ylContext;
foreach (['enable','user_name','auth_name','password','transport','sip_server.1.address','sip_server.1.port'] as $field) {
    $nativeYealink['settings']['account.1.'.$field]='conflicting-fixture';
}
$nativeYealink['settings']['account.1.codec.1.enable']='0';
$nativeOutput=$yealink->generate($nativeYealink);
vendorAssertNotContains('conflicting-fixture',$nativeOutput['content']);
vendorAssertContains('account.1.codec.1.enable = 0',$nativeOutput['content']);
foreach ([$gsContext,$dp752] as $nativeGrandstream) {
    $baseXml=new DOMDocument();$baseXml->loadXML($grandstream->generate($nativeGrandstream)['content']);
    $protected=$nativeGrandstream['model']['model']==='DP752' ? ['P47','P130','P4060','P4090','P4120','P4150'] : ['P47','P130','P35','P36','P34','P271'];
    foreach ($protected as $field) { $nativeGrandstream['settings']['gs_'.$field]='conflicting-fixture'; }
    $nativeGrandstream['settings']['gs_P30']='ntp.example.test';
    $nativeGrandstream['settings']['ntp_server']='prior-ntp.example.test';
    $nativeOutput=$grandstream->generate($nativeGrandstream);
    vendorAssertNotContains('conflicting-fixture',$nativeOutput['content']);
    $nativeXml=new DOMDocument();$nativeXml->loadXML($nativeOutput['content']);
    foreach ($protected as $field) {
        if ($nativeXml->getElementsByTagName($field)->length!==1
            || $nativeXml->getElementsByTagName($field)->item(0)->textContent!==$baseXml->getElementsByTagName($field)->item(0)->textContent) {
            throw new RuntimeException('Native settings split the managed Grandstream account: '.$field);
        }
    }
    if ($nativeXml->getElementsByTagName('P30')->length!==1 || $nativeXml->getElementsByTagName('P30')->item(0)->textContent!=='ntp.example.test') {
        throw new RuntimeException('Unrelated native overrides must emit one unambiguous value');
    }
}
foreach (['voicemail_number','ntp_server','yealink_timezone','admin_password','user_password','yealink_phonebook_url','auto_answer','dial_pattern'] as $field) {
    $context=$ylContext;$context['settings'][$field]="value\r\naccount.1.enable = 0";
    vendorAssertNotContains("\naccount.1.enable = 0",$yealink->generate($context)['content']);
}
$context=$ylContext;
$context['lines'][]=['button'=>2,'featureId'=>21,'speedDialNumber'=>"399\naccount.1.enable = 0"];
$context['lines'][3]['speedDialNumber']="399\naccount.1.enable = 0";
vendorAssertNotContains("\naccount.1.enable = 0",$yealink->generate($context)['content']);

echo "Vendor generator fixtures passed\n";
