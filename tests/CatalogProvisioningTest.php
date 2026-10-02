<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/CiscoXmlGenerator.php';
require_once dirname(__DIR__) . '/lib/CiscoCnfGenerator.php';
require_once dirname(__DIR__) . '/lib/YealinkGenerator.php';
require_once dirname(__DIR__) . '/lib/GrandstreamGenerator.php';

use FreePBX\modules\Ucmprovisioner\CiscoCnfGenerator;
use FreePBX\modules\Ucmprovisioner\CiscoXmlGenerator;
use FreePBX\modules\Ucmprovisioner\GrandstreamGenerator;
use FreePBX\modules\Ucmprovisioner\ModelCatalog;
use FreePBX\modules\Ucmprovisioner\YealinkGenerator;

function simulationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function simulationContains(string $needle, string $haystack, string $message): void
{
    simulationAssert(strpos($haystack, $needle) !== false, $message . "\nMissing: " . $needle);
}

function simulationNotContains(string $needle, string $haystack, string $message): void
{
    simulationAssert(strpos($haystack, $needle) === false, $message . "\nUnexpected: " . $needle);
}

/** @return array<string,mixed> */
function simulationModel(array $row, array $capabilities): array
{
    return [
        'vendor'=>(string)$row[0], 'model'=>(string)$row[1], 'family'=>(string)$row[2],
        'line_keys'=>(int)$row[3], 'supports_softkeys'=>(int)$row[4],
        'supports_featurepolicy'=>(int)$row[5], 'kem_support'=>(int)$row[6],
    ] + $capabilities;
}

/** @return array<string,mixed> */
function simulationContext(array $model): array
{
    return [
        'phone'=>[
            'mac'=>'001122AABBCC', 'extension'=>'6101', 'label'=>'Catalog Test',
            'family'=>(string)$model['family'],
        ],
        'model'=>$model,
        'settings'=>[
            'pbx_addr'=>'192.0.2.10', 'sip_port'=>'5060', 'transport'=>'udp',
            'voicemail_number'=>'*97', 'ntp_server'=>'192.0.2.10',
            'directory_url'=>'http://192.0.2.10/directory.php',
        ],
        'lines'=>[
            [
                'button'=>1, 'feature'=>'line', 'featureId'=>9, 'lineIndex'=>1,
                'accountIndex'=>1, 'label'=>'Catalog Test', 'name'=>'6101',
                'authName'=>'6101', 'secret'=>'test-secret', 'contact'=>'6101',
            ],
        ],
    ];
}

function simulationXml(string $xml, string $rootName, string $key): DOMDocument
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadXML($xml);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    simulationAssert($loaded, "$key generated invalid XML" . ($errors ? ': ' . trim($errors[0]->message) : ''));
    simulationAssert($doc->documentElement !== null && $doc->documentElement->nodeName === $rootName, "$key generated the wrong XML root");
    return $doc;
}

function simulationRole(string $key): string
{
    if (in_array($key, [
        'GRANDSTREAM|DP720', 'GRANDSTREAM|DP722', 'GRANDSTREAM|DP725',
        'GRANDSTREAM|DP730', 'GRANDSTREAM|DP735',
        'YEALINK|W53H', 'YEALINK|W56H', 'YEALINK|W57R', 'YEALINK|W59R',
        'YEALINK|W71H', 'YEALINK|W73H', 'YEALINK|W74H', 'YEALINK|W78H',
    ], true)) {
        return 'dect_handset';
    }
    if (in_array($key, ['YEALINK|W80B', 'YEALINK|W90B'], true)) {
        return 'dect_radio';
    }
    if ($key === 'YEALINK|W90DM') {
        return 'dect_manager';
    }
    if (in_array($key, ['GRANDSTREAM|DP750', 'GRANDSTREAM|DP752', 'GRANDSTREAM|DP755', 'YEALINK|W60B', 'YEALINK|W70B'], true)) {
        return 'dect_base';
    }
    return 'desk';
}

$requiredCapabilities = [
    'role', 'sip_accounts', 'line_appearances', 'programmable_keys',
    'programmable_key_namespace', 'programmable_key_pages', 'handsets_max',
    'simultaneous_calls', 'expansion_types', 'max_expansion_modules',
    'keys_per_expansion', 'config_profile', 'firmware_profile',
    'directory_profile', 'media_profile', 'auth_profile',
];
$numericCapabilities = [
    'sip_accounts', 'line_appearances', 'programmable_keys',
    'programmable_key_pages', 'handsets_max', 'simultaneous_calls',
    'max_expansion_modules', 'keys_per_expansion',
];

simulationAssert(count(ModelCatalog::CATALOG) === 96, 'The supported model catalog must contain exactly 96 audited rows');
simulationAssert(count(ModelCatalog::CAPABILITIES) === 96, 'The capability matrix must contain exactly 96 audited rows');

$seen = [];
$generated = 0;
$failClosed = 0;
foreach (ModelCatalog::CATALOG as $row) {
    simulationAssert(count($row) === 9, 'Every catalog row must retain the documented nine-column shape');
    $vendor = (string)$row[0];
    $modelName = (string)$row[1];
    $family = (string)$row[2];
    $key = strtoupper($vendor) . '|' . strtoupper($modelName);
    simulationAssert(!isset($seen[$key]), "Duplicate catalog row: $key");
    $seen[$key] = true;

    $capabilities = ModelCatalog::capabilitiesFor($vendor, $modelName);
    simulationAssert($capabilities !== null, "Missing resolved capabilities for $key");
    simulationAssert(isset(ModelCatalog::CAPABILITIES[$key]), "Missing static capabilities for $key");
    foreach ($requiredCapabilities as $field) {
        simulationAssert(array_key_exists($field, $capabilities), "$key is missing capability '$field'");
    }
    foreach ($numericCapabilities as $field) {
        simulationAssert(is_int($capabilities[$field]) && $capabilities[$field] >= 0, "$key capability '$field' must be a non-negative integer");
    }
    simulationAssert(is_array($capabilities['expansion_types']), "$key expansion_types must be an array");
    simulationAssert($capabilities['role'] === simulationRole($key), "$key has an incorrect endpoint role");
    simulationAssert($row[7] === null, "$key must not ship a guessed firmware load");
    simulationAssert(ModelCatalog::unsupportedReason($vendor, $modelName) === null, "$key is both supported and quarantined");

    $role = (string)$capabilities['role'];
    if ($role === 'desk') {
        simulationAssert($capabilities['sip_accounts'] > 0, "$key desk phone has no SIP-account capacity");
        simulationAssert($capabilities['handsets_max'] === 0, "$key desk phone has a DECT handset capacity");
    } elseif ($role === 'dect_base') {
        simulationAssert($capabilities['sip_accounts'] > 0 && $capabilities['handsets_max'] > 0, "$key DECT base needs both account and handset capacities");
        simulationAssert($capabilities['programmable_keys'] === 0, "$key DECT base must not expose desk-phone keys");
    } else {
        simulationAssert($capabilities['sip_accounts'] === 0 && $capabilities['programmable_keys'] === 0, "$key system component must not expose direct accounts or keys");
    }

    $hasExpansion = $capabilities['max_expansion_modules'] > 0;
    simulationAssert($hasExpansion === ($capabilities['expansion_types'] !== []), "$key expansion types/module limit disagree");
    simulationAssert((bool)$row[6] === $hasExpansion, "$key legacy KEM flag disagrees with normalized expansion capabilities");
    if (!$hasExpansion) {
        simulationAssert($capabilities['keys_per_expansion'] === 0, "$key has expansion key capacity without expansion support");
    }

    if ($family === 'cisco_xml' || $family === 'cisco_cnf') {
        simulationAssert($vendor === 'Cisco', "$key has a Cisco family under another vendor");
        simulationAssert($capabilities['config_profile'] === $family, "$key config profile must match its Cisco syntax family");
    } elseif ($family === 'yealink') {
        simulationAssert($vendor === 'Yealink', "$key has a Yealink family under another vendor");
    } elseif ($family === 'grandstream') {
        simulationAssert($vendor === 'Grandstream', "$key has a Grandstream family under another vendor");
    } else {
        throw new RuntimeException("$key uses unknown provisioning family '$family'");
    }

    $firmwareProfile = ModelCatalog::firmwareProfile($vendor, $modelName);
    simulationAssert($capabilities['firmware_profile'] === $firmwareProfile, "$key firmware profile drifted between capability APIs");
    $expectsFirmware = in_array($role, ['desk', 'dect_base'], true);
    simulationAssert(($firmwareProfile !== null) === $expectsFirmware, "$key firmware-profile availability does not match its role");
    if ($firmwareProfile !== null) {
        $profileModels = ModelCatalog::modelsForFirmwareProfile($firmwareProfile);
        $roundTrip = array_filter($profileModels, static fn(array $candidate): bool =>
            strcasecmp((string)$candidate['vendor'], $vendor) === 0
            && strcasecmp((string)$candidate['model'], $modelName) === 0
        );
        simulationAssert($roundTrip !== [], "$key cannot round-trip through firmware profile '$firmwareProfile'");
        if ($vendor === 'Cisco') {
            simulationAssert(isset(ModelCatalog::FIRMWARE_PROFILES[$firmwareProfile]), "$key lacks an explicit Cisco load-family definition");
            simulationAssert(@preg_match((string)ModelCatalog::FIRMWARE_PROFILES[$firmwareProfile]['pattern'], '') !== false, "$key firmware profile has an invalid filename pattern");
        } else {
            simulationAssert($firmwareProfile === strtolower($vendor) . ':' . ModelCatalog::firmwareModelToken($modelName), "$key vendor firmware profile is not model-specific");
        }
    }

    $model = simulationModel($row, $capabilities);
    $ctx = simulationContext($model);

    if ($family === 'cisco_xml') {
        $accounts = (int)$capabilities['sip_accounts'];
        $keyCapacity = (int)$capabilities['programmable_keys'];
        for ($index = 2; $index <= $accounts; $index++) {
            $ctx['lines'][] = [
                'button'=>$index, 'feature'=>'line', 'featureId'=>9,
                'lineIndex'=>$index, 'accountIndex'=>$index,
                'label'=>"Account $index", 'name'=>(string)(6200 + $index),
                'authName'=>(string)(6200 + $index), 'secret'=>'secondary-secret',
                'contact'=>(string)(6200 + $index),
            ];
        }
        $result = (new CiscoXmlGenerator())->generate($ctx);
        simulationAssert($result['filename'] === 'SEP001122AABBCC.cnf.xml', "$key generated an incorrect SEP filename");
        $doc = simulationXml((string)$result['xml'], 'device', $key);
        simulationContains('<transportLayerProtocol>2</transportLayerProtocol>', (string)$result['xml'], "$key did not preserve UDP transport");
        simulationContains('<directoryURL>http://192.0.2.10/directory.php</directoryURL>', (string)$result['xml'], "$key omitted its Cisco XML directory service");
        simulationAssert($doc->getElementsByTagName('sipLines')->length === 1, "$key omitted sipLines");
        simulationAssert($doc->getElementsByTagName('line')->length === $accounts, "$key did not render its full SIP-account capacity");
        if ($keyCapacity > 1) {
            simulationContains('<line button="' . $keyCapacity . '" lineIndex="' . $accounts . '">', (string)$result['xml'], "$key lost the last base-phone key");
        }
        $generated++;
        continue;
    }

    if ($family === 'cisco_cnf') {
        $capacity = (int)$capabilities['sip_accounts'];
        for ($index = 2; $index <= $capacity; $index++) {
            $ctx['lines'][] = [
                'button'=>$index, 'feature'=>'line', 'featureId'=>9,
                'lineIndex'=>$index, 'label'=>"Account $index",
                'name'=>$index === $capacity ? '6198' : (string)(6200 + $index),
                'secret'=>'secondary-secret',
            ];
        }
        $ctx['lines'][] = ['button'=>$capacity + 1, 'feature'=>'line', 'featureId'=>9, 'lineIndex'=>$capacity + 1, 'label'=>'Overflow', 'name'=>'6199', 'secret'=>'overflow-secret'];
        $result = (new CiscoCnfGenerator())->generate($ctx);
        $content = (string)$result['content'];
        simulationAssert($result['filename'] === 'SIP001122AABBCC.cnf', "$key generated an incorrect SIP<MAC>.cnf filename");
        simulationContains('line1_name: "6101"', $content, "$key omitted the primary account");
        simulationContains('directory_url: "http://192.0.2.10/directory.php"', $content, "$key omitted its XML directory URL");
        simulationNotContains('line' . ($capacity + 1) . '_name', $content, "$key emitted an account beyond its capacity");
        simulationAssert(str_ends_with($content, "\n"), "$key generated a truncated CNF file");
        $generated++;
        continue;
    }

    $accounts = (int)$capabilities['sip_accounts'];
    for ($index = 2; $index <= $accounts; $index++) {
        $extension = $index === $accounts ? '6198' : (string)(6200 + $index);
        $ctx['lines'][] = [
            'button'=>0, 'feature'=>'line', 'featureId'=>9,
            'lineIndex'=>$index, 'accountIndex'=>$index,
            'label'=>"Account $index", 'name'=>$extension,
            'authName'=>$extension, 'secret'=>'secondary-secret',
        ];
    }
    if ($accounts > 0) {
        $ctx['lines'][] = ['button'=>0, 'feature'=>'line', 'featureId'=>9, 'lineIndex'=>$accounts + 1, 'accountIndex'=>$accounts + 1, 'label'=>'Overflow', 'name'=>'6199', 'authName'=>'6199', 'secret'=>'overflow-secret'];
    }

    if ($family === 'yealink') {
        $generator = new YealinkGenerator();
        if (!in_array($role, ['desk', 'dect_base'], true)) {
            $result = $generator->generate($ctx);
            simulationAssert(($result['filename'] ?? null) === null && ($result['content'] ?? '') === '' && !empty($result['error']), "$key must fail closed instead of producing a standalone endpoint cfg");
            $failClosed++;
            continue;
        }

        $baseKeys = (int)$capabilities['programmable_keys'];
        $maxModules = (int)$capabilities['max_expansion_modules'];
        $keysPerModule = (int)$capabilities['keys_per_expansion'];
        if ($role === 'desk' && $baseKeys > 1) {
            $ctx['lines'][] = ['button'=>$baseKeys, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Last Base BLF', 'speedDialNumber'=>'88001'];
        }
        if ($role === 'desk') {
            $lastPosition = $baseKeys + ($maxModules * $keysPerModule);
            if ($maxModules > 0 && $keysPerModule > 0) {
                $ctx['lines'][] = ['button'=>$lastPosition, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Last Expansion BLF', 'speedDialNumber'=>'88003'];
            }
            $ctx['lines'][] = ['button'=>$lastPosition + 1, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Overflow BLF', 'speedDialNumber'=>'88002'];
        }
        $result = $generator->generate($ctx);
        $content = (string)$result['content'];
        simulationAssert($result['filename'] === '001122aabbcc.cfg', "$key generated an incorrect Yealink filename");
        simulationContains('#!version:1.0.0.1', $content, "$key omitted the Yealink cfg header");
        simulationContains('account.1.transport = 0', $content, "$key did not preserve UDP transport");
        simulationNotContains('6199', $content, "$key emitted an account beyond its capacity");
        if ($accounts > 1) {
            simulationContains("account.{$accounts}.user_name = 6198", $content, "$key lost the final valid SIP account");
        }
        if ($role === 'dect_base') {
            simulationNotContains('linekey.', $content, "$key DECT base received desk-phone keys");
        } else {
            simulationNotContains('88002', $content, "$key emitted a key beyond base/expansion capacity");
            if ($baseKeys > 1) {
                simulationContains("linekey.{$baseKeys}.value = 88001", $content, "$key lost the final base-phone key");
            }
            if ($maxModules > 0 && $keysPerModule > 0) {
                simulationContains("expansion_module.{$maxModules}.key.{$keysPerModule}.value = 88003", $content, "$key lost the final expansion-module key");
            }
        }
        $generated++;
        continue;
    }

    $generator = new GrandstreamGenerator();
    if ($role !== 'desk' && $role !== 'dect_base') {
        $result = $generator->generate($ctx);
        simulationAssert(($result['filename'] ?? null) === null && ($result['content'] ?? '') === '' && !empty($result['error']), "$key must fail closed instead of producing a standalone endpoint cfg");
        $failClosed++;
        continue;
    }

    $baseKeys = (int)$capabilities['programmable_keys'];
    $maxModules = (int)$capabilities['max_expansion_modules'];
    $keysPerModule = (int)$capabilities['keys_per_expansion'];
    if ($role === 'desk' && str_starts_with(strtoupper($modelName), 'GHP63')) {
        $ctx['lines'][] = ['button'=>7, 'feature'=>'2', 'featureId'=>2, 'label'=>'Last Context Key', 'speedDialNumber'=>'88001'];
        $ctx['lines'][] = ['button'=>8, 'feature'=>'2', 'featureId'=>2, 'label'=>'Overflow Key', 'speedDialNumber'=>'88002'];
        $ctx['settings']['ghp_speed_dials'] = [['digit'=>'0', 'mode'=>'0', 'account'=>0, 'label'=>'Digit Zero', 'target'=>'88003']];
    } elseif ($role === 'desk') {
        if ($maxModules > 0 && $keysPerModule > 0) {
            $ctx['settings']['grandstream_expansion_count'] = $maxModules;
        }
        $lastPosition = $baseKeys + ($maxModules * $keysPerModule);
        if ($baseKeys > 1) {
            $ctx['lines'][] = ['button'=>$baseKeys, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Last Base BLF', 'speedDialNumber'=>'88001'];
        }
        if ($maxModules > 0 && $keysPerModule > 0) {
            $ctx['lines'][] = ['button'=>$lastPosition, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Last Expansion BLF', 'speedDialNumber'=>'88003'];
        }
        $ctx['lines'][] = ['button'=>$lastPosition + 1, 'feature'=>'blf', 'featureId'=>21, 'label'=>'Overflow BLF', 'speedDialNumber'=>'88002'];
    }
    $result = $generator->generate($ctx);
    $content = (string)$result['content'];
    simulationAssert($result['filename'] === 'cfg001122aabbcc.xml', "$key generated an incorrect Grandstream filename");
    $doc = simulationXml($content, 'gs_provision', $key);
    simulationAssert($doc->getElementsByTagName('mac')->item(0)?->textContent === '001122aabbcc', "$key omitted the normalized MAC");
    simulationNotContains('6199', $content, "$key emitted an account beyond its capacity");
    if ($accounts > 1) {
        simulationContains('6198', $content, "$key lost the final valid SIP account");
    }
    if ($role === 'desk') {
        simulationNotContains('88002', $content, "$key emitted a key beyond base/expansion capacity");
        if (str_starts_with(strtoupper($modelName), 'GHP63')) {
            simulationContains('88001', $content, "$key lost the sixth context key");
            simulationContains('88003', $content, "$key lost the digit-key speed dial");
        } elseif ($baseKeys > 1) {
            simulationContains('88001', $content, "$key lost the final base-phone key");
        }
        if ($maxModules > 0 && $keysPerModule > 0) {
            simulationContains('88003', $content, "$key lost the final expansion-module key");
        }
    }
    $generated++;
}

simulationAssert(array_keys(ModelCatalog::CAPABILITIES) === array_keys($seen), 'The capability matrix has missing, orphaned, or differently ordered model keys');
simulationAssert(ModelCatalog::capabilitiesFor('Cisco', '7950') === null, 'Quarantined Cisco 7950 unexpectedly became provisionable');
simulationAssert($generated === 80, "Expected 80 directly generated models, simulated $generated");
simulationAssert($failClosed === 16, "Expected sixteen role-only components to fail closed, simulated $failClosed");

echo "Catalog provisioning simulation passed: 96 models (80 generated, 16 fail-closed role components)\n";
