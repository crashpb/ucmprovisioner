<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/DefaultTemplateResolver.php';
require_once dirname(__DIR__) . '/lib/ModelCatalog.php';

use FreePBX\modules\Ucmprovisioner\DefaultTemplateResolver;
use FreePBX\modules\Ucmprovisioner\ModelCatalog;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$templates = [
    ['id'=>10,'name'=>'Cisco base','target_family'=>'cisco_xml'],
    ['id'=>20,'name'=>'Cisco 88xx','target_family'=>'cisco_xml','target_series'=>'cisco_88xx'],
    ['id'=>30,'name'=>'Cisco 8845','target_family'=>'cisco_xml','target_model'=>'Cisco|8845'],
    ['id'=>40,'name'=>'DECT base','target_family'=>'yealink','target_role'=>'dect_base'],
];
$rules = [
    ['id'=>1,'template_id'=>10,'target_family'=>'cisco_xml','enabled'=>1],
    ['id'=>2,'template_id'=>20,'target_family'=>'cisco_xml','target_series'=>'cisco_88xx','enabled'=>1],
    ['id'=>3,'template_id'=>30,'target_model'=>'Cisco|8845','enabled'=>1],
    ['id'=>4,'template_id'=>40,'target_family'=>'yealink','target_role'=>'dect_base','enabled'=>1],
];
$resolver = new DefaultTemplateResolver();
$device = ['vendor'=>'Cisco','family'=>'cisco_xml','series_key'=>'cisco_88xx','model'=>'8845','role'=>'desk'];
$result = $resolver->resolve($rules, $templates, $device);
$expect($result['template_id'] === 30, 'Exact model must override series and family defaults');
$expect(array_values(array_filter($result['trace'], static fn(array $t): bool => $t['status'] === 'applied'))[0]['rule_id'] === 3, 'Winning rule must be visible in trace');

$series = $resolver->resolve(array_slice($rules, 0, 2), $templates, $device);
$expect($series['template_id'] === 20, 'Series default must override family default');
$dect = $resolver->resolve($rules, $templates, [
    'vendor'=>'Yealink','family'=>'yealink','series_key'=>'yealink_dect','model'=>'W70B','role'=>'dect_base',
]);
$expect($dect['template_id'] === 40, 'Family plus role default must match a DECT base');

$ambiguous = false;
try {
    $resolver->resolve([
        ['id'=>8,'template_id'=>10,'target_family'=>'cisco_xml'],
        ['id'=>9,'template_id'=>10,'target_family'=>'cisco_xml'],
    ], $templates, $device);
} catch (RuntimeException $exception) {
    $ambiguous = str_contains($exception->getMessage(), 'Ambiguous');
}
$expect($ambiguous, 'Equal-specificity matching defaults must fail closed');

$expect(ModelCatalog::seriesKey('Cisco', '7841', 'cisco_xml') === 'cisco_78xx', 'Cisco 78xx series mismatch');
$expect(ModelCatalog::seriesKey('Cisco', '8845', 'cisco_xml') === 'cisco_88xx', 'Cisco 88xx series mismatch');
$expect(ModelCatalog::seriesKey('Cisco', '7960', 'cisco_cnf') === 'cisco_cnf', 'Cisco CNF series mismatch');
$expect(ModelCatalog::seriesKey('Yealink', 'T46U', 'yealink') === 'yealink_t4u', 'Yealink T4U series mismatch');
$expect(ModelCatalog::seriesKey('Yealink', 'W70B', 'yealink') === 'yealink_dect', 'Yealink DECT series mismatch');
$expect(ModelCatalog::seriesKey('Grandstream', 'GRP2614', 'grandstream') === 'grandstream_grp26xx', 'Grandstream GRP series mismatch');

$compatTemplates = [
    ['id'=>50,'name'=>'All XML 79xx','target_family'=>'cisco_xml','target_series'=>'cisco_79xx'],
    ['id'=>60,'name'=>'SIP41 base','target_family'=>'cisco_xml','target_series'=>'cisco:sip41'],
    ['id'=>70,'name'=>'Exact 7941','target_family'=>'cisco_xml','target_model'=>'Cisco|7941'],
];
$compatRules = [
    ['id'=>50,'template_id'=>50,'target_family'=>'cisco_xml','target_series'=>'cisco_79xx'],
    ['id'=>60,'template_id'=>60,'target_family'=>'cisco_xml','target_series'=>'cisco:sip41'],
];
$sip41Device = ['vendor'=>'Cisco','family'=>'cisco_xml','series_key'=>'cisco_79xx','model'=>'7941','role'=>'desk'];
$expect($resolver->resolve($compatRules, $compatTemplates, $sip41Device)['template_id'] === 60, 'SIP41 compatibility default must outrank the broad 79xx policy default');
$sip42Device = ['vendor'=>'Cisco','family'=>'cisco_xml','series_key'=>'cisco_79xx','model'=>'7942','role'=>'desk'];
$expect($resolver->resolve($compatRules, $compatTemplates, $sip42Device)['template_id'] === 50, '7942 must not receive a SIP41 compatibility default');
$compatRules[] = ['id'=>70,'template_id'=>70,'target_model'=>'Cisco|7941'];
$expect($resolver->resolve($compatRules, $compatTemplates, $sip41Device)['template_id'] === 70, 'Exact model default must outrank compatibility and broad defaults');

$scopeOptions = ModelCatalog::templateScopeOptions([
    ['vendor'=>'Cisco','model'=>'7941','family'=>'cisco_xml','series_key'=>'cisco_79xx'],
    ['vendor'=>'Cisco','model'=>'7961','family'=>'cisco_xml','series_key'=>'cisco_79xx'],
    ['vendor'=>'Cisco','model'=>'7942','family'=>'cisco_xml','series_key'=>'cisco_79xx'],
    ['vendor'=>'Cisco','model'=>'7975','family'=>'cisco_xml','series_key'=>'cisco_79xx'],
]);
$expect(isset($scopeOptions['cisco_79xx']), 'Existing broad Cisco 79xx policy scope must remain selectable');
$expect(isset($scopeOptions['cisco:sip41']) && $scopeOptions['cisco:sip41']['models'] === ['7941','7961'], 'SIP41 scope must expose exactly 7941/7961');
$expect(isset($scopeOptions['cisco:sip75']) && $scopeOptions['cisco:sip75']['models'] === ['7975'], 'SIP75 scope must expose 7975');

echo "Default template resolver assertions passed\n";
