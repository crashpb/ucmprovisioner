<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/BuildPlanner.php';

use FreePBX\modules\Ucmprovisioner\BuildPlanner;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$phones = [[
    'phone' => [
        'id' => 7,
        'extension_id' => 70,
        'extension' => '307',
        'model_id' => 3,
        'source_revision' => 'ad-r1',
        'revision' => 'override-r1',
    ],
    'model' => [
        'id' => 3,
        'vendor' => 'Cisco',
        'model' => 'CP-8845',
        'family' => 'cisco_xml',
        'catalog_revision' => 'catalog-9',
    ],
    'settings' => [
        '_template_stack' => ['base', 'department'],
        '_template_trace' => [['field' => 'ntp_server', 'winner' => 'department']],
        'firmware_asset_id' => 19,
    ],
]];
$files = [
    ['filename' => 'SEP001122334455.cnf.xml', 'content' => '<device/>', 'phone_id' => 7, 'extension' => '307'],
    ['filename' => 'dial307.xml', 'content' => '<dialTemplate/>', 'phone_id' => 7, 'extension' => '307', 'role' => 'dial-template'],
];
$glue = [
    'sip_custom_post_block' => "[307](+)\nsubscribe=308@ext-local\n",
    'extensions_override_block' => "[ext-local]\nexten => 307,hint,SIP/307&Custom:DND307,SIP/307\n",
    'cross_site_hints_block' => "[from-internal-noxfer-additional](+)\n",
    'requires_patch' => true,
    'managed_extensions' => ['307'],
    'subscribes' => ['307' => ['308']],
    'onetime' => ['cisco_notify' => true],
    'preflight' => ['eligible' => true, 'volatile' => microtime(true)],
];

$planner = new BuildPlanner();
$first = $planner->plan(1, $phones, $files, $glue, [
    'catalog_revision' => 'catalog-9',
    'directory_revisions' => ['corp' => 'ad-r1'],
    'previous_generation_id' => '20260726T010203Z-0123456789abcdef',
]);
$second = $planner->plan(1, $phones, array_reverse($files), $glue, [
    'catalog_revision' => 'catalog-9',
    'directory_revisions' => ['corp' => 'ad-r1'],
    'previous_generation_id' => '20260726T010203Z-0123456789abcdef',
]);

$expect($first['transaction_id'] !== $second['transaction_id'], 'Every preview must receive a unique transaction id');
$expect($first['plan_hash'] === $second['plan_hash'], 'Equivalent plans must hash identically regardless of input file order');
$expect(($first['phones'][0]['asset_ids']['firmware_asset_id'] ?? null) === 19, 'Stable asset ids must be recorded in the plan');
$expect(($first['previous_generation_id'] ?? '') === '20260726T010203Z-0123456789abcdef', 'The prior generation must be recorded');

$changedBase = $planner->plan(1, $phones, $files, $glue, [
    'catalog_revision' => 'catalog-9',
    'directory_revisions' => ['corp' => 'ad-r1'],
    'previous_generation_id' => '20260726T020304Z-fedcba9876543210',
]);
$expect($changedBase['plan_hash'] !== $first['plan_hash'], 'Changing the active base generation must invalidate a preview');

$changedFile = $files;
$changedFile[0]['content'] = '<device changed="1"/>';
$changed = $planner->plan(1, $phones, $changedFile, $glue);
$expect($changed['plan_hash'] !== $first['plan_hash'], 'Changing generated content must invalidate a preview');

$changedGlue = $glue;
$changedGlue['sip_custom_post_block'] = "[307](+)\nsubscribe=309@ext-local\n";
$glueChanged = $planner->plan(1, $phones, $files, $changedGlue, [
    'catalog_revision' => 'catalog-9',
    'directory_revisions' => ['corp' => 'ad-r1'],
    'previous_generation_id' => '20260726T010203Z-0123456789abcdef',
]);
$expect($glueChanged['plan_hash'] !== $first['plan_hash'], 'Changing exact Asterisk glue must invalidate a preview');

$changedOnetime = $glue;
$changedOnetime['onetime']['cisco_notify'] = false;
$onetimeChanged = $planner->plan(1, $phones, $files, $changedOnetime, [
    'catalog_revision' => 'catalog-9',
    'directory_revisions' => ['corp' => 'ad-r1'],
    'previous_generation_id' => '20260726T010203Z-0123456789abcdef',
]);
$expect($onetimeChanged['plan_hash'] !== $first['plan_hash'], 'Changing one-time glue state must invalidate a preview');

$unsafeRejected = false;
try {
    $planner->plan(1, $phones, [['filename' => '../escape.cfg', 'content' => 'x']], $glue);
} catch (InvalidArgumentException $exception) {
    $unsafeRejected = true;
}
$expect($unsafeRejected, 'Unsafe generated paths must be rejected before staging');

$conflictRejected = false;
try {
    $planner->plan(1, $phones, [
        ['filename' => 'same.cfg', 'content' => 'one'],
        ['filename' => 'same.cfg', 'content' => 'two'],
    ], $glue);
} catch (RuntimeException $exception) {
    $conflictRejected = true;
}
$expect($conflictRejected, 'Two different payloads may not claim the same generated filename');

echo "Build planner tests passed\n";
