<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/SoftkeyPolicy.php';
require_once dirname(__DIR__) . '/lib/SoftkeyPolicyGenerator.php';

use FreePBX\modules\Ucmprovisioner\SoftkeyPolicy;
use FreePBX\modules\Ucmprovisioner\SoftkeyPolicyGenerator;
use FreePBX\modules\Ucmprovisioner\TemplateEngine;

function skAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return DOMXPath */
function skXpath(string $xml): DOMXPath
{
    $document = new DOMDocument();
    if (!$document->loadXML($xml)) {
        throw new RuntimeException('Generated softkeys.xml is not valid XML');
    }
    return new DOMXPath($document);
}

/** @return array<int,string> */
function skAttributeList(DOMXPath $xpath, string $expression, string $attribute): array
{
    $values = [];
    foreach ($xpath->query($expression) ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $values[] = $node->getAttribute($attribute);
        }
    }
    return $values;
}

function skExpectInvalid(array $policy, string $expectedMessage): void
{
    try {
        SoftkeyPolicy::normalizeV2($policy);
    } catch (InvalidArgumentException $exception) {
        skAssert(
            strpos($exception->getMessage(), $expectedMessage) !== false,
            'Unexpected validation message: ' . $exception->getMessage()
        );
        return;
    }
    throw new RuntimeException('Expected invalid policy: ' . $expectedMessage);
}

$policy = [
    'schema' => 2,
    'states' => [
        'On Hook' => ['Redial', 'Undefined', 'Redial', 'CFwdAll', 'NewCall'],
        'Custom Phase' => ['CustomAction', 'Undefined'],
        'Connected' => null,
    ],
    'definitions' => [
        'CustomAction' => [
            'tag' => 9001,
            'event_id' => 9002,
            'help_id' => 9003,
            'label' => 'Custom action',
        ],
    ],
    'annotations' => [
        'On Hook' => [[
            'label' => 'Recents',
            'note' => 'Firmware display observation only.',
        ]],
    ],
    'unresolved' => [],
];

$normalized = SoftkeyPolicy::normalizeV2($policy);
skAssert(SoftkeyPolicy::isV2($normalized), 'Normalized policy must remain schema 2');
skAssert(
    $normalized['states']['On Hook'] === ['Redial', 'Undefined', 'Redial', 'CFwdAll', 'NewCall'],
    'Schema 2 must preserve exact slot order and duplicates'
);
skAssert(
    !array_key_exists('VidMode', SoftkeyPolicy::BUILTIN_DEFINITIONS)
        && in_array('Remote In Use', SoftkeyPolicy::DOCUMENTED_STATES, true)
        && SoftkeyPolicy::STATE_COMPATIBILITY['Remote In Use'] === [],
    'Reviewed documentation catalog must be canonical and exclude untrusted VidMode'
);
skAssert(
    !defined(TemplateEngine::class . '::SOFTKEY_FIRMWARE_KEYS')
        && !defined(TemplateEngine::class . '::SOFTKEY_NATIVE_PROFILES')
        && !defined(TemplateEngine::class . '::SOFTKEY_HELP')
        && !method_exists(TemplateEngine::class, 'unsupportedSoftkeysForModel'),
    'Backend must not restore inferred firmware rows, permanent keys, or model filtering'
);

$generator = new SoftkeyPolicyGenerator();
$xml = $generator->softkeysXml($normalized);
skAssert($xml === $generator->softkeysXml($normalized), 'Schema-2 XML must be deterministic');
$xpath = skXpath($xml);

skAssert(
    skAttributeList($xpath, '/softKeyCfg/softKeySets/softKeySet', 'id')
        === ['On Hook', 'Custom Phase'],
    'Custom states must emit in exact order and null states must not emit'
);
skAssert(
    skAttributeList(
        $xpath,
        '/softKeyCfg/softKeySets/softKeySet[@id="On Hook"]/softKey',
        'keyID'
    ) === ['Redial', 'Undefined', 'Redial', 'CFwdAll', 'NewCall'],
    'Generated state must preserve exact slot order, duplicates, Undefined, and wire case'
);
skAssert(
    skAttributeList($xpath, '/softKeyCfg/typeSoftKey/softKeyDef', 'keyID')
        === ['Redial', 'Undefined', 'CFwdAll', 'NewCall', 'CustomAction'],
    'Definitions must follow first-use order without duplicates'
);
skAssert(
    (string)$xpath->evaluate(
        'string(/softKeyCfg/typeSoftKey/softKeyDef[@keyID="CustomAction"]/eventID)'
    ) === '9002',
    'Custom action triplets must be emitted'
);
skAssert(strpos($xml, 'Recents') === false, 'Annotations must never emit into softkeys.xml');

$noInjectionXml = $generator->softkeysXml([
    'schema' => 2,
    'states' => ['Ring In' => ['iDivert']],
    'definitions' => [],
]);
$noInjectionXpath = skXpath($noInjectionXml);
skAssert(
    skAttributeList(
        $noInjectionXpath,
        '/softKeyCfg/softKeySets/softKeySet[@id="Ring In"]/softKey',
        'keyID'
    ) === ['iDivert'],
    'Schema 2 must not inject Answer or any other recommended key'
);

skExpectInvalid([
    'schema' => '2',
    'states' => [],
], 'integer 2');
skExpectInvalid([
    'schema' => 2,
    'states' => ['On Hook' => ['CFwdALL']],
], 'CFwdAll');
skExpectInvalid([
    'schema' => 2,
    'states' => ['On Hook' => ['MissingDefinition']],
], 'undefined keyID');
skExpectInvalid([
    'schema' => 2,
    'states' => ['On Hold' => []],
], 'requires Resume');
skExpectInvalid([
    'schema' => 2,
    'states' => ['On Hook' => ['Redial']],
], 'requires NewCall');
skExpectInvalid([
    'schema' => 2,
    'states' => ['0' => []],
], 'states must be an object');
skExpectInvalid([
    'schema' => 2,
    'states' => [],
    'definitions' => [
        '__proto__' => ['tag' => 1, 'event_id' => 2, 'help_id' => 3],
    ],
], 'reserved object name');
skExpectInvalid([
    'schema' => 2,
    'states' => [],
    'definitions' => [
        'Redial' => ['tag' => 1, 'event_id' => 1, 'help_id' => 301],
    ],
], 'may not replace built-in');
skExpectInvalid([
    'schema' => 2,
    'states' => ['Connected' => ['VidMode']],
], 'undefined keyID');
skExpectInvalid([
    'schema' => 2,
    'states' => ['Custom' => ['MyKey']],
    'definitions' => [
        'MyKey' => ['tag' => '1', 'event_id' => 2, 'help_id' => 3],
    ],
], 'must be an integer');
skExpectInvalid([
    'schema' => 2,
    'states' => ['On Hook' => array_fill(0, 17, 'Redial')],
], 'too many slots');
SoftkeyPolicy::normalizeV2([
    'schema' => 2,
    'states' => ['On Hook' => array_merge(array_fill(0, 15, 'Redial'), ['NewCall'])],
]);

$legacy = [
    'On Hook' => ['Redial', 'Redial', 'Recents', 'CFwdALL', 'Bogus'],
    'Connected' => ['VidMode', 'EndCall'],
    'Mystery State' => ['Answer'],
];
$migrated = SoftkeyPolicy::migrateLegacy($legacy);
skAssert(
    $migrated['states']['On Hook'] === ['Redial', 'CFwdAll', 'NewCall'],
    'Migration must preserve exact legacy emitted order, first-occurrence dedupe, and mandatory materialization'
);
skAssert(
    $migrated['states']['Connected'] === ['EndCall'],
    'Migration must retain trusted keys and make untrusted VidMode unresolved'
);
$reasons = array_column($migrated['unresolved'], 'reason');
foreach (['unknown_key', 'untrusted_definition', 'unknown_state'] as $reason) {
    skAssert(in_array($reason, $reasons, true), 'Migration must retain unresolved reason ' . $reason);
}
skAssert(
    count($migrated['annotations']['On Hook'] ?? []) === 2,
    'Dropped legacy On Hook tokens must remain visible as annotations'
);
$migratedXml = $generator->softkeysXml($migrated);
skAssert(
    strpos($migratedXml, 'Recents') === false
        && strpos($migratedXml, 'Bogus') === false
        && strpos($migratedXml, 'VidMode') === false
        && strpos($migratedXml, 'Mystery State') === false,
    'Migration metadata must not leak into generated XML'
);

$legacyXml = $generator->softkeysXml($legacy);
$legacyXpath = skXpath($legacyXml);
skAssert(
    skAttributeList(
        $legacyXpath,
        '/softKeyCfg/softKeySets/softKeySet[@id="On Hook"]/softKey',
        'keyID'
    ) === ['Redial', 'CFwdAll', 'NewCall'],
    'Schema-less legacy rendering must retain its historical filter/dedupe/mandatory behavior'
);
skAssert(
    $legacyXpath->query('/softKeyCfg/typeSoftKey/softKeyDef[@keyID="VidMode"]')->length === 1,
    'Legacy VidMode behavior must remain frozen until an operator migrates to schema 2'
);

$emptyMigration = SoftkeyPolicy::migrateLegacy([]);
foreach ([str_repeat('a', 127) . 'نام', str_repeat('a', 127) . ' ' . 'b', "bad\xFF"] as $metadata) {
    $unicodeMigration = SoftkeyPolicy::migrateLegacy(['On Hook'=>[$metadata]]);
    skAssert(is_string(json_encode($unicodeMigration, JSON_THROW_ON_ERROR)), 'Legacy metadata must remain valid UTF-8 after byte limiting');
}
skAssert(
    $emptyMigration['states'] === [],
    'Empty legacy/inherit map must migrate to an empty sparse schema-2 policy'
);
$emptyMerge = SoftkeyPolicy::merge([], [
    'schema' => 2,
    'states' => [],
]);
skAssert(
    $emptyMerge['states'] === [],
    'Empty merge base must not inject legacy mandatory states'
);

$base = [
    'schema' => 2,
    'states' => [
        'On Hook' => ['Redial', 'NewCall'],
        'Custom State' => ['BaseAction'],
    ],
    'definitions' => [
        'BaseAction' => ['tag' => 8001, 'event_id' => 8002, 'help_id' => 8003],
    ],
    'annotations' => [
        'On Hook' => [['label' => 'Base note', 'note' => 'Base annotation']],
    ],
];
$patch = [
    'schema' => 2,
    'states' => [
        'On Hook' => ['Undefined', 'Undefined', 'NewCall'],
        'Custom State' => null,
        'Patch State' => ['PatchAction'],
    ],
    'definitions' => [
        'BaseAction' => ['tag' => 8051, 'event_id' => 8052, 'help_id' => 8053],
        'PatchAction' => ['tag' => 8101, 'event_id' => 8102, 'help_id' => 8103],
    ],
    'annotations' => [
        'On Hook' => [['label' => 'Patch note', 'note' => 'Patch annotation']],
    ],
];
$merged = SoftkeyPolicy::merge($base, $patch);
skAssert(
    $merged['states']['On Hook'] === ['Undefined', 'Undefined', 'NewCall']
        && $merged['states']['Custom State'] === null
        && $merged['states']['Patch State'] === ['PatchAction'],
    'Tier merge must replace exact states, preserve duplicate slots, and retain null tombstones'
);
skAssert(
    ($merged['definitions']['BaseAction']['tag'] ?? 0) === 8051
        && isset($merged['definitions']['PatchAction']),
    'Tier merge must preserve new definitions and allow an explicit override of an inherited definition'
);
skAssert(
    count($merged['annotations']['On Hook']) === 2,
    'Tier merge must preserve non-emitting annotations from both levels'
);

$mergeTemplateLayers = new ReflectionMethod(TemplateEngine::class, 'mergeTemplateLayers');
$mergeTemplateLayers->setAccessible(true);
$templateEngine = new TemplateEngine(new stdClass());
$stack = $mergeTemplateLayers->invoke($templateEngine, [
    [
        'id' => 100,
        'name' => 'Base Cisco',
        'softkeys' => json_encode($base, JSON_THROW_ON_ERROR),
    ],
    [
        'id' => 101,
        'name' => 'Department override',
        'softkeys' => json_encode($patch, JSON_THROW_ON_ERROR),
    ],
]);
$stackPolicy = json_decode((string)$stack['softkeys'], true, 512, JSON_THROW_ON_ERROR);
skAssert(
    $stackPolicy['states']['On Hook'] === ['Undefined', 'Undefined', 'NewCall']
        && $stackPolicy['states']['Custom State'] === null
        && $stackPolicy['states']['Patch State'] === ['PatchAction'],
    'TemplateEngine must use schema-2 replace/inherit/clear semantics without hidden additions'
);
$stackXml = $generator->softkeysXml($stackPolicy);
$stackXpath = skXpath($stackXml);
skAssert(
    skAttributeList(
        $stackXpath,
        '/softKeyCfg/softKeySets/softKeySet[@id="On Hook"]/softKey',
        'keyID'
    ) === ['Undefined', 'Undefined', 'NewCall']
        && $stackXpath->query('/softKeyCfg/softKeySets/softKeySet[@id="Custom State"]')->length === 0,
    'The final merged file must match the template stack exactly'
);

$invalidUnrelatedLayer = [[
    'id' => 102,
    'name' => 'Unrelated stale policy',
    'softkeys' => json_encode([
        'schema' => 2,
        'states' => ['On Hold' => []],
        'definitions' => [],
        'annotations' => [],
        'unresolved' => [],
    ], JSON_THROW_ON_ERROR),
]];
$strictRejected = false;
try {
    $mergeTemplateLayers->invoke($templateEngine, $invalidUnrelatedLayer);
} catch (InvalidArgumentException $exception) {
    $strictRejected = strpos($exception->getMessage(), 'requires Resume') !== false;
}
skAssert($strictRejected, 'A selected phone must still reject an unusable explicit On Hold state');
$glueOnlyStack = $mergeTemplateLayers->invoke($templateEngine, $invalidUnrelatedLayer, false);
skAssert(
    json_decode((string)$glueOnlyStack['softkeys'], true, 512, JSON_THROW_ON_ERROR) === [],
    'An unselected fleet member used only for Asterisk glue must not validate or emit template companions'
);

echo "SoftkeyPolicyV2Test OK\n";
