<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/CiscoTimezoneService.php';
require_once __DIR__ . '/TimezoneRuntimeFixture.php';

use FreePBX\modules\Ucmprovisioner\CiscoTimezoneService;
use FreePBX\modules\Ucmprovisioner\ModelCatalog;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$files = [
    'tzupdater.ver'=>"2022c-2026\n",
    'j9-tzdata.jar'=>timezoneRuntimeFixture('j9-tzdata.jar','j9 embedded tzdata2022c'),
    'tzupdater.jar'=>timezoneRuntimeFixture('tzupdater.jar','legacy Java embedded tzdata2022c'),
    'TzDataCSV.csv'=>timezoneRuntimeFixture('TzDataCSV.csv','tzdata2022c'),
    'tzdb.dat'=>"\0TZDB\0version 2022c\0",
];
$inspect = static fn(array $map): array => CiscoTimezoneService::inspect(
    array_keys($map),
    static fn(string $name) => $map[$name] ?? false
);

$complete = $inspect($files);
$expect(
    $complete['status'] === 'complete'
    && $complete['served_version'] === '2022c-2026'
    && $complete['iana_version'] === '2022c'
    && count($complete['files']) === 5,
    'The canonical five-file Cisco COP bundle must be detected from matching payload evidence'
);

$mismatchedPayload = $files;
$mismatchedPayload['tzupdater.jar'] = timezoneRuntimeFixture('tzupdater.jar','legacy embedded tzdata2020a');
$mismatchedResult = $inspect($mismatchedPayload);
$expect(
    $mismatchedResult['status'] === 'complete'
    && $mismatchedResult['selectable'] === true
    && $mismatchedResult['retainable'] === true
    && $mismatchedResult['capture_allowed'] === true
    && $mismatchedResult['quarantine_required'] === false
    && $mismatchedResult['iana_version'] === '2022c'
    && $mismatchedResult['compatibility']['cisco-79xx-8961-99x1']['status'] === 'legacy_frozen'
    && $mismatchedResult['compatibility']['cisco-79xx-8961-99x1']['configuration_usable'] === true
    && $mismatchedResult['compatibility']['cisco-78xx-88xx']['status'] === 'complete',
    'Cisco post-2021a legacy payload variance must stay profile-scoped while the exact usable set remains retainable'
);

$opaquePayloads = $inspect([
    'tzupdater.ver'=>"2022c-2026\n",
    'j9-tzdata.jar'=>timezoneRuntimeFixture('j9-tzdata.jar','opaque J9 payload bytes'),
    'tzupdater.jar'=>timezoneRuntimeFixture('tzupdater.jar','legacy Java embedded tzdata2020a'),
    'TzDataCSV.csv'=>timezoneRuntimeFixture('TzDataCSV.csv','opaque CSV payload bytes'),
    'tzdb.dat'=>"\0TZDB\0version 2022c\0",
]);
$expect(
    $opaquePayloads['status'] === 'complete'
    && $opaquePayloads['compatibility']['cisco-78xx-88xx']['status'] === 'unverified'
    && $opaquePayloads['compatibility']['cisco-78xx-88xx']['configuration_usable'] === true
    && $opaquePayloads['compatibility']['cisco-79xx-8961-99x1']['status'] === 'legacy_frozen'
    && $opaquePayloads['compatibility']['cisco-79xx-8961-99x1']['configuration_usable'] === true
    && $opaquePayloads['compatibility']['cisco-39xx-69xx-894x']['status'] === 'unverified'
    && $opaquePayloads['compatibility']['cisco-39xx-69xx-894x']['configuration_usable'] === true
    && $opaquePayloads['retainable'] === true
    && $opaquePayloads['capture_allowed'] === true
    && $opaquePayloads['quarantine_required'] === false,
    'The official frozen legacy payload must not quarantine usable opaque J9/CSV profiles'
);

$missing = $files;
unset($missing['tzupdater.ver']);
$missingResult = $inspect($missing);
$expect(
    $missingResult['status'] === 'complete'
    && $missingResult['can_generate_version'] === true
    && $missingResult['capture_allowed'] === true
    && $missingResult['derived_version'] === '2022c',
    'A missing tzupdater.ver must not block independently usable runtime payloads'
);

$case = $files;
$case['tzdatacsv.csv'] = $case['TzDataCSV.csv'];
unset($case['TzDataCSV.csv']);
$caseResult = $inspect($case);
$expect(
    $caseResult['status'] === 'complete'
    && $caseResult['compatibility']['cisco-39xx-69xx-894x']['status'] === 'complete'
    && in_array('case_mismatch', array_column($caseResult['issues'], 'code'), true),
    'The documented tzdatacsv.csv spelling must import case-insensitively while preserving a diagnostic'
);

$mixed = $files;
$mixed['tzdb.dat'] = "\0TZDB\0version 2024a\0";
$mixedResult = $inspect($mixed);
$expect(
    $mixedResult['status'] === 'complete'
    && $mixedResult['retainable'] === true
    && in_array('payload_release_variance', array_column($mixedResult['issues'], 'code'), true),
    'Cross-file release variance must be visible without corrupting otherwise usable family profiles'
);

$internallyConflicting = $files;
$internallyConflicting['tzupdater.jar'] = timezoneRuntimeFixture('tzupdater.jar','legacy tzdata2020a plus tzdata2021a');
$conflictResult = $inspect($internallyConflicting);
$expect(
    $conflictResult['status'] === 'mixed'
    && $conflictResult['retainable'] === false
    && $conflictResult['quarantine_required'] === true
    && $conflictResult['compatibility']['cisco-79xx-8961-99x1']['status'] === 'mismatch',
    'Contradictory releases inside one payload must remain non-activatable quarantine evidence'
);

$javaOnly = $inspect([
    'tzupdater.ver'=>"2022c-2026\n",
    'tzupdater.jar'=>timezoneRuntimeFixture('tzupdater.jar','Java phone payload tzdata2022c'),
]);
$expect(
    $javaOnly['status'] === 'partial'
    && $javaOnly['selectable'] === true
    && $javaOnly['retainable'] === true
    && $javaOnly['capture_allowed'] === true
    && $javaOnly['compatibility']['cisco-79xx-8961-99x1']['status'] === 'complete'
    && $javaOnly['compatibility']['cisco-78xx-88xx']['status'] === 'incomplete',
    'A version plus one documented family payload must form a selectable partial bundle'
);

$opaqueJavaOnly = $inspect(['tzupdater.jar'=>timezoneRuntimeFixture('tzupdater.jar','opaque legacy Java payload bytes')]);
$expect(
    $opaqueJavaOnly['status'] === 'partial'
    && $opaqueJavaOnly['compatibility']['cisco-79xx-8961-99x1']['configuration_usable'] === true
    && preg_match('/^ucmp-[a-f0-9]{12}$/D', (string)$opaqueJavaOnly['served_version']) === 1,
    'A standalone opaque runtime payload must receive a deterministic content version without manual metadata'
);
$expect(
    preg_match('/^[a-f0-9]{64}$/', (string)$javaOnly['fingerprint']) === 1,
    'Every served partial or full bundle must have one deterministic fingerprint'
);

$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '7841') === 'j9-tzdata.jar', '7841 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '8845') === 'j9-tzdata.jar', '8845 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '7945') === 'tzupdater.jar', '7945 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '8945') === 'TzDataCSV.csv', '8945 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '7906') === 'tzupdater.jar', '7906 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '9971') === 'tzupdater.jar', '9971 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '7811') === 'j9-tzdata.jar', '7811 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '8832') === 'j9-tzdata.jar', '8832 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '3905') === 'TzDataCSV.csv', '3905 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '6961') === 'TzDataCSV.csv', '6961 mapping mismatch');
$expect(ModelCatalog::ciscoTimezonePayload('Cisco', '7940', 'cisco_cnf') === null, 'Legacy CNF must not use XML tzdata');

echo "Cisco timezone bundle fixtures passed\n";
