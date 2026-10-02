<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CiscoXmlGenerator.php';

use FreePBX\modules\Ucmprovisioner\CiscoXmlGenerator;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$context = [
    'phone'=>['mac'=>'001122AABBCC', 'label'=>'Test phone'],
    'model'=>['model'=>'7841', 'supports_softkeys'=>1],
    'settings'=>[
        'pbx_addr'=>'192.0.2.10', 'sip_port'=>'5160', 'transport'=>'tcp',
        'voicemail_number'=>'*97', 'cisco_display_refresh_rate'=>'30', 'cisco_dndCallAlert'=>'0',
        'softkey_file'=>'test-softkeys.xml', '_softkey_layout'=>['On Hook'=>['NewCall']],
        'cisco_xml_olson_timezone'=>'Etc/UTC', '_cisco_tzdata_version'=>'2025a',
        '_cisco_tzdata_file'=>'test-timezone.jar', 'dial_template_file'=>'test-dialplan.xml',
        'services_url'=>'https://pbx.example.test/services?a=1&b=2',
    ],
    'lines'=>[
        ['button'=>1, 'featureId'=>9, 'lineIndex'=>1, 'name'=>'301', 'label'=>'Test line', 'secret'=>'synthetic&<secret>'],
        ['button'=>2, 'featureId'=>9, 'lineIndex'=>2, 'name'=>'302', 'label'=>'Second', 'secret'=>str_repeat('x', 40)],
        ['button'=>3, 'featureId'=>21, 'label'=>'BLF', 'speedDialNumber'=>'303', 'featureOptionMask'=>1],
    ],
];
$generator = new CiscoXmlGenerator();
$result = $generator->generate($context);
$doc = new DOMDocument();
$expect($doc->loadXML($result['xml']), 'Generated XML must parse');
$xpath = new DOMXPath($doc);

// Contract derived from the reference SIP profile, independent of editor state.
foreach ([
    '/device/sipProfile/sipStack/timerRegisterExpires'=>'3600',
    '/device/sipProfile/sipStack/timerT1'=>'500',
    '/device/sipProfile/sipStack/timerT2'=>'4000',
    '/device/sipProfile/startMediaPort'=>'16384',
    '/device/sipProfile/stopMediaPort'=>'32766',
    '/device/sipProfile/voipControlPort'=>'5060',
    '/device/devicePool/callManagerGroup/members/member/callManager/ports/sipPort'=>'5160',
    '/device/sipProfile/sipLines/line[@button="1"]/port'=>'5160',
    '/device/sipProfile/dtmfAvtPayload'=>'101',
    '/device/sipProfile/dtmfOutofBand'=>'avt',
    '/device/sipProfile/preferredCodec'=>'g711ulaw',
    '/device/encrConfig'=>'false',
    '/device/capfAuthMode'=>'0',
    '/device/vendorConfig/displayRefreshRate'=>'0',
    '/device/dndCallAlert'=>'0',
    '/device/transportLayerProtocol'=>'1',
    '/device/sipProfile/sipLines/line[@button="1"]/authPassword'=>'synthetic&<secret>',
    '/device/sipProfile/sipLines/line[@button="1"]/autoAnswer/autoAnswerEnabled'=>'0',
    '/device/sipProfile/sipLines/line[@button="1"]/sharedLine'=>'false',
    '/device/sipProfile/sipLines/line[@button="3"]/speedDialNumber'=>'303',
    '/device/sipProfile/softKeyFile'=>'test-softkeys.xml',
    '/device/sipProfile/dialTemplate'=>'test-dialplan.xml',
    '/device/tzdata/tzupdater'=>'test-timezone.jar',
    '/device/devicePool/dateTimeSetting/olsonTimeZone'=>'Etc/UTC',
    '/device/servicesURL'=>'https://pbx.example.test/services?a=1&b=2',
] as $path => $expected) {
    $expect($xpath->query($path)->length === 1, 'Missing or duplicate field: ' . $path);
    $expect($xpath->evaluate('string(' . $path . ')') === $expected, 'Wrong value: ' . $path);
}
$expect($xpath->query('//dndCallAlert')->length === 1, 'DND must occur only once, at the device root');
$expect($xpath->query('/device/sipProfile/sipLines/line[@button="2"]/authPassword')->length === 0, 'Secondary bulk lines must not gain independent authentication');
$expect($xpath->query('/device/sipProfile/sipLines/line[@button="3"]/autoAnswer')->length === 0, 'BLF buttons must not gain SIP account fields');
$expect(count($result['warnings']) === 1 && str_contains($result['warnings'][0], 'display refresh'), 'Legacy enum repair must be visible');
$expect($generator->generate($context)['xml'] === $result['xml'], 'Generation must remain deterministic');

foreach (['0','1'] as $refresh) {
    $context['settings']['cisco_display_refresh_rate'] = $refresh;
    $out = $generator->generate($context);
    $expect(str_contains($out['xml'], '<displayRefreshRate>' . $refresh . '</displayRefreshRate>'), 'Valid refresh enum must survive');
    $expect($out['warnings'] === [], 'Valid enum must not warn');
}
unset($context['settings']['cisco_dndCallAlert']);
$context['settings']['dnd_call_alert'] = '5';
$expect(str_contains($generator->generate($context)['xml'], '<dndCallAlert>5</dndCallAlert>'), 'Legacy DND alias must retain its value');
$context['lines'][0]['secret'] = str_repeat('x', 30);
$generator->generate($context);
$context['lines'][0]['secret'] .= 'x';
try {
    $generator->generate($context);
    throw new RuntimeException('An overlong primary secret must stop generation');
} catch (InvalidArgumentException $e) {
    $expect(!str_contains($e->getMessage(), $context['lines'][0]['secret']), 'Error must not expose a secret');
}
echo "Cisco XML base profile, feature retention and validation passed.\n";
