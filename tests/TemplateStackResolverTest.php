<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TemplateStackResolver.php';
require_once dirname(__DIR__) . '/lib/ModelCatalog.php';

use FreePBX\modules\Ucmprovisioner\TemplateStackResolver;

function stackExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$groups = [
    ['id' => 1, 'site_id' => 10, 'name' => 'DepA'],
    ['id' => 2, 'site_id' => 10, 'name' => 'DepA_mgmt', 'parent_group_id' => 1],
    ['id' => 3, 'site_id' => 10, 'name' => 'DeptA_Dect', 'parent_group_id' => 2],
];
$templates = [
    ['id' => 10, 'name' => 'Base_cnf', 'target_family' => 'cisco_cnf', 'settings' => ['base' => 'cnf']],
    ['id' => 20, 'name' => 'Base_xml', 'target_family' => 'cisco_xml', 'settings' => ['base' => 'xml', 'wallpaper' => 'base', 'codec' => 'g711']],
    ['id' => 30, 'name' => 'Base_DepA', 'target_family' => 'cisco_xml', 'settings' => ['wallpaper' => 'department', 'vlan' => 120]],
    ['id' => 40, 'name' => 'DepA_mgmt', 'target_family' => 'cisco_xml', 'settings' => ['codec' => 'g722']],
    ['id' => 50, 'name' => 'Base_yealink_Dect', 'target_vendor' => 'Yealink', 'target_role' => 'dect_base', 'settings' => ['base' => 'yealink-dect']],
    [
        'id' => 60,
        'name' => 'Exact_8845',
        'target_vendor' => 'Cisco',
        'target_family' => 'cisco_xml',
        'target_series' => 'cisco_88xx',
        'target_model' => 'Cisco|CP-8845',
        'target_role' => 'desk',
        'settings' => ['wallpaper' => 'management-8845', 'vlan' => null],
    ],
    ['id' => 70, 'name' => 'Generic_last', 'settings' => ['generic' => true]],
    ['id' => 80, 'name' => 'Base_SIP41', 'target_family' => 'cisco_xml', 'target_series' => 'cisco:sip41', 'settings' => ['load_family' => 'SIP41']],
];
$assignments = [
    // Deliberately unsorted input: saved position, not array order, controls the stack.
    ['id' => 3, 'group_id' => 1, 'template_id' => 30, 'position' => 30],
    ['id' => 1, 'group_id' => 1, 'template_id' => 10, 'position' => 10],
    ['id' => 2, 'group_id' => 1, 'template_id' => 20, 'position' => 20],
    ['id' => 4, 'group_id' => 2, 'template_id' => 40, 'position' => 10],
    ['id' => 5, 'group_id' => 3, 'template_id' => 50, 'position' => 10],
    ['id' => 7, 'group_id' => 3, 'template_id' => 70, 'position' => 30],
    ['id' => 6, 'group_id' => 3, 'template_id' => 60, 'position' => 20],
    ['id' => 8, 'group_id' => 3, 'template_id' => 999, 'position' => 40],
    ['id' => 9, 'group_id' => 3, 'template_id' => 70, 'position' => 50, 'enabled' => 0],
    ['id' => 10, 'group_id' => 1, 'template_id' => 80, 'position' => 25],
];

$resolver = new TemplateStackResolver();
$cisco = $resolver->resolve(3, $groups, $assignments, $templates, [
    'vendor' => 'CISCO',
    'family' => 'cisco_xml',
    'series_key' => 'cisco_88xx',
    'model' => 'CP-8845',
    'model_id' => 8845,
    'role' => 'desk',
]);

stackExpect(array_column($cisco['group_path'], 'id') === [1, 2, 3], 'Groups must flatten root-to-leaf');
stackExpect(
    $cisco['applied_template_ids'] === [20, 30, 40, 70, 60],
    'Applicable templates must retain root-to-leaf order while exact-model layers run last inside their group: ' . json_encode($cisco['applied_template_ids'])
);
stackExpect($cisco['settings']['wallpaper'] === 'management-8845', 'A later applicable layer must win');
stackExpect($cisco['settings']['codec'] === 'g722', 'A descendant layer must override an ancestor setting');
stackExpect(!array_key_exists('vlan', $cisco['settings']), 'A later null must explicitly clear an inherited configurable value');
stackExpect($cisco['settings']['generic'] === true, 'A template with no constraints must apply visibly');

$traceByTemplate = [];
foreach ($cisco['trace'] as $trace) {
    $traceByTemplate[$trace['template_id'] . ':' . $trace['position']] = $trace;
}
stackExpect(
    $traceByTemplate['10:10']['status'] === 'skipped'
        && str_contains($traceByTemplate['10:10']['reason'], 'family requires cisco_cnf'),
    'An incompatible inherited template must remain in the trace with its reason'
);
stackExpect(
    $traceByTemplate['999:40']['status'] === 'skipped'
        && str_contains($traceByTemplate['999:40']['reason'], 'not found'),
    'A stale assignment must be visible instead of silently disappearing'
);
stackExpect(
    $traceByTemplate['70:50']['status'] === 'skipped'
        && str_contains($traceByTemplate['70:50']['reason'], 'disabled'),
    'A disabled assignment must be visible in the trace'
);

$yealinkDect = $resolver->resolve(3, $groups, $assignments, $templates, [
    'vendor' => 'Yealink',
    'family' => 'yealink',
    'series_key' => 'yealink_w70',
    'model' => 'W70B',
    'role' => 'dect_base',
]);
stackExpect(
    $yealinkDect['applied_template_ids'] === [50, 70],
    'Yealink DECT must skip inherited Cisco scopes but keep its applicable and generic layers'
);
$exactCiscoTrace = array_values(array_filter(
    $yealinkDect['trace'],
    static fn(array $trace): bool => $trace['template_id'] === 60
))[0];
stackExpect(
    $exactCiscoTrace['status'] === 'skipped'
        && str_contains($exactCiscoTrace['reason'], 'vendor requires Cisco')
        && str_contains($exactCiscoTrace['reason'], 'family requires cisco_xml')
        && str_contains($exactCiscoTrace['reason'], 'series requires cisco_88xx')
        && str_contains($exactCiscoTrace['reason'], 'model requires Cisco|CP-8845')
        && str_contains($exactCiscoTrace['reason'], 'role requires desk'),
    'The visible trace must name every failed applicability constraint'
);

$yealinkDesk = $resolver->resolve(3, $groups, $assignments, $templates, [
    'vendor' => 'Yealink',
    'family' => 'yealink',
    'series_key' => 'yealink_t4u',
    'model' => 'T46U',
    'role' => 'desk',
]);
stackExpect(
    $yealinkDesk['applied_template_ids'] === [70],
    'A Yealink desk phone must not receive a DECT-only layer'
);
$dectTrace = array_values(array_filter(
    $yealinkDesk['trace'],
    static fn(array $trace): bool => $trace['template_id'] === 50
))[0];
stackExpect(str_contains($dectTrace['reason'], 'role requires dect_base'), 'Role mismatch must be explicit');

$sip41 = $resolver->resolve(1, $groups, $assignments, $templates, [
    'vendor' => 'Cisco',
    'family' => 'cisco_xml',
    'series_key' => 'cisco_79xx',
    'model' => '7941',
    'role' => 'desk',
]);
stackExpect(in_array(80, $sip41['applied_template_ids'], true), 'SIP41 compatibility template must apply to 7941');
$sip42 = $resolver->resolve(1, $groups, $assignments, $templates, [
    'vendor' => 'Cisco',
    'family' => 'cisco_xml',
    'series_key' => 'cisco_79xx',
    'model' => '7942',
    'role' => 'desk',
]);
stackExpect(!in_array(80, $sip42['applied_template_ids'], true), 'SIP41 compatibility template must not apply to 7942');

$specificity = $resolver->resolve(20, [['id'=>20,'site_id'=>10,'name'=>'Specificity']], [
    ['group_id'=>20,'template_id'=>103,'position'=>10],
    ['group_id'=>20,'template_id'=>102,'position'=>20],
    ['group_id'=>20,'template_id'=>101,'position'=>30],
], [
    ['id'=>101,'name'=>'Broad 88xx','target_family'=>'cisco_xml','target_series'=>'cisco_88xx'],
    ['id'=>102,'name'=>'Firmware 8845/65','target_family'=>'cisco_xml','target_series'=>'cisco:sip8845_65'],
    ['id'=>103,'name'=>'Exact 8845','target_family'=>'cisco_xml','target_model'=>'Cisco|8845'],
], [
    'vendor'=>'Cisco','family'=>'cisco_xml','series_key'=>'cisco_88xx','model'=>'8845','role'=>'desk',
]);
stackExpect(
    $specificity['applied_template_ids'] === [101,102,103],
    'Within one group, broad policy must precede firmware compatibility and exact-model policy regardless of stale global positions'
);

$cycleRejected = false;
try {
    $resolver->resolve(10, [
        ['id' => 10, 'site_id' => 10, 'name' => 'A', 'parent_group_id' => 11],
        ['id' => 11, 'site_id' => 10, 'name' => 'B', 'parent_group_id' => 10],
    ], [], [], []);
} catch (RuntimeException $exception) {
    $cycleRejected = str_contains($exception->getMessage(), '10 -> 11 -> 10');
}
stackExpect($cycleRejected, 'A parent cycle must be rejected with the traversed cycle');

$missingParentRejected = false;
try {
    $resolver->resolve(12, [
        ['id' => 12, 'site_id' => 10, 'name' => 'Orphan', 'parent_group_id' => 99],
    ], [], [], []);
} catch (RuntimeException $exception) {
    $missingParentRejected = str_contains($exception->getMessage(), 'parent 99 was not found');
}
stackExpect($missingParentRejected, 'A missing parent must fail closed');

echo "Template stack resolver passed\n";
