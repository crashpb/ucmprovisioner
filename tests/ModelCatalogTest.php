<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';

use FreePBX\modules\Ucmprovisioner\ModelCatalog;

function catalogExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$fields = [
    'role', 'sip_accounts', 'line_appearances', 'programmable_keys',
    'programmable_key_namespace', 'programmable_key_pages', 'handsets_max',
    'simultaneous_calls', 'expansion_types', 'max_expansion_modules',
    'keys_per_expansion', 'config_profile', 'firmware_profile',
    'directory_profile', 'media_profile', 'auth_profile',
];

catalogExpect(count(ModelCatalog::CAPABILITIES) === count(ModelCatalog::CATALOG), 'Every catalog row needs exactly one capability row');
foreach (ModelCatalog::CATALOG as $row) {
    $key = strtoupper((string)$row[0]) . '|' . strtoupper((string)$row[1]);
    $capabilities = ModelCatalog::capabilitiesFor((string)$row[0], (string)$row[1]);
    catalogExpect(isset(ModelCatalog::CAPABILITIES[$key]), "Missing static capability row for $key");
    catalogExpect($capabilities !== null, "Missing resolved capabilities for $key");
    catalogExpect(array_keys($capabilities) === $fields, "Capability fields/order changed for $key");
    catalogExpect(
        $capabilities['firmware_profile'] === ModelCatalog::firmwareProfile((string)$row[0], (string)$row[1]),
        "Firmware profile drift for $key"
    );
    catalogExpect($row[7] === null, "$key must not ship a guessed default firmware");
}

$w60 = ModelCatalog::capabilitiesFor('Yealink', 'W60B');
$w70 = ModelCatalog::capabilitiesFor('Yealink', 'W70B');
$dp755 = ModelCatalog::capabilitiesFor('Grandstream', 'DP755');
$t48u = ModelCatalog::capabilitiesFor('Yealink', 'T48U');
$gxp2170 = ModelCatalog::capabilitiesFor('Grandstream', 'GXP2170');
$c7962 = ModelCatalog::capabilitiesFor('Cisco', '7962');
$c8865 = ModelCatalog::capabilitiesFor('Cisco', '8865');
catalogExpect($w60['role'] === 'dect_base' && $w60['sip_accounts'] === 8 && $w60['handsets_max'] === 8, 'W60B capacity mismatch');
catalogExpect($w70['role'] === 'dect_base' && $w70['sip_accounts'] === 10 && $w70['handsets_max'] === 10, 'W70B capacity mismatch');
catalogExpect($dp755['role'] === 'dect_base' && $dp755['sip_accounts'] === 20 && $dp755['handsets_max'] === 10, 'DP755 capacity mismatch');
catalogExpect($t48u['programmable_keys'] === 29 && $t48u['keys_per_expansion'] === 60, 'T48U/EXP43 capacity mismatch');
catalogExpect($gxp2170['sip_accounts'] === 6 && $gxp2170['programmable_keys'] === 48, 'GXP2170 capacity mismatch');
catalogExpect($c7962['max_expansion_modules'] === 2 && $c7962['expansion_types'] === ['7914','7915','7916'], '7962 expansion compatibility mismatch');
catalogExpect($c8865['max_expansion_modules'] === 3 && $c8865['expansion_types'] === ['BEKEM','CP-8800-Video'], '8865 expansion compatibility mismatch');
catalogExpect(ModelCatalog::capabilitiesFor('Cisco', '7950') === null, 'Cisco 7950 must remain quarantined');
catalogExpect(ModelCatalog::firmwareProfile('Cisco','7940') === ModelCatalog::firmwareProfile('Cisco','7960'), '7940 and 7960 must share the P0S3 package profile');
catalogExpect(ModelCatalog::firmwareProfile('Cisco','8841') !== ModelCatalog::firmwareProfile('Cisco','8845'), '88xx audio and 8845/8865 video firmware must remain isolated');
catalogExpect(ModelCatalog::backgroundProfileKey('Cisco','8841') === ModelCatalog::backgroundProfileKey('Cisco','8845'), '8841 and 8845 must share their documented wallpaper profile');
catalogExpect(ModelCatalog::backgroundDir('Cisco','8941') === '640x480x24' && ModelCatalog::backgroundDir('Cisco','8945') === '640x480x24', '8941/8945 must use Cisco official 640x480x24 wallpaper directory');
catalogExpect(ModelCatalog::backgroundProfileKey('Cisco','7841') === null, '7841 must not receive an invented List.xml wallpaper profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Cisco','7940') === ModelCatalog::hostedMediaProfileKey('Cisco','7960'), '7940 and 7960 must share their 90x56 HTTP logo profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GHP630') === ModelCatalog::hostedMediaProfileKey('Grandstream','GHP631'), 'GHP630/631 must share their documented screen profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Yealink','T48U') !== ModelCatalog::hostedMediaProfileKey('Yealink','T33G'), 'Different Yealink screen resolutions must remain isolated');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Yealink','T48U') === ModelCatalog::hostedMediaProfileKey('Yealink','T57W'), 'Verified 800x480 Yealink models must share their hosted media profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Yealink','T46S') === 'yealink:480x272', 'T46S must use the verified shared 480x272 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GXP2140') === 'grandstream:480x272', 'GXP2140 must use the verified 480x272 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GXP2135') === 'grandstream:320x240', 'GXP2135 must use its documented 320x240 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GXP2160') === 'grandstream:480x272', 'GXP2160 must use its documented 480x272 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GRP2612') === 'grandstream:320x240', 'GRP2612 family must use its documented 320x240 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GRP2616') === 'grandstream:480x272', 'GRP2616 must use its documented 480x272 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GRP2636') === 'grandstream:480x272', 'GRP2636 must use its documented 480x272 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GRP2650') === 'grandstream:1280x720', 'GRP2650 must use its documented 1280x720 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GRP2670') === 'grandstream:1024x600', 'GRP2670 must use its documented 1024x600 profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Grandstream','GRP2604') === null, 'Monochrome GRP2604 must never enter the standard wallpaper generator');
catalogExpect(
    ModelCatalog::hostedMediaProfileKey('Yealink','W73H') === null
    && ModelCatalog::hostedMediaProfileKey('Grandstream','DP730') === null,
    'A color DECT screen must not be mistaken for provisioned custom-wallpaper support'
);
catalogExpect(ModelCatalog::hostedMediaProfileKey('Yealink','T58A') === 'yealink:1024x600', 'T58A must use its documented 1024x600 managed wallpaper profile');
catalogExpect(ModelCatalog::hostedMediaProfileKey('Yealink','VP59') === 'yealink:1280x800', 'VP59 must use its documented 1280x800 managed wallpaper profile');
$c7906Wallpaper = ModelCatalog::wallpaperProfileForModel('Cisco', '7906');
$c7941Wallpaper = ModelCatalog::wallpaperProfileForModel('Cisco', '7941');
catalogExpect(
    ($c7906Wallpaper['dir'] ?? '') === '95x34x1'
    && ($c7906Wallpaper['thumbnail_width'] ?? 0) === 23
    && ($c7906Wallpaper['thumbnail_height'] ?? 0) === 8
    && ($c7906Wallpaper['grayscale_tones'] ?? 0) === 2,
    '7906/7911 must use the documented 95x34 one-bit grayscale profile'
);
catalogExpect(
    ($c7941Wallpaper['thumbnail_width'] ?? 0) === 80
    && ($c7941Wallpaper['thumbnail_height'] ?? 0) === 49
    && ($c7941Wallpaper['grayscale_tones'] ?? 0) === 16,
    '7941/7942/7961/7962 must use the documented 80x49 four-bit grayscale thumbnail'
);
$wallpaperProfiles = ModelCatalog::wallpaperProfiles();
catalogExpect(count($wallpaperProfiles) >= 12, 'Unified wallpaper profiles must include Cisco, Yealink and Grandstream standard outputs');
catalogExpect(
    (ModelCatalog::wallpaperProfileForModel('Grandstream','GHP631W')['format'] ?? '') === 'jpg'
    && (ModelCatalog::wallpaperProfileForModel('Grandstream','GHP631W')['max_bytes'] ?? 0) === 512000,
    'GHP63x catalog output must retain the documented JPEG-compatible 500 KB ceiling'
);
$xml7841Labels = ModelCatalog::identityLabelPolicy('Cisco', '7841');
$xml7962Labels = ModelCatalog::identityLabelPolicy('Cisco', '7962');
$cnf7960Labels = ModelCatalog::identityLabelPolicy('Cisco', '7960');
$yealinkT48Labels = ModelCatalog::identityLabelPolicy('Yealink', 'T48U');
$yealinkW70Labels = ModelCatalog::identityLabelPolicy('Yealink', 'W70B');
$grandstreamGrpLabels = ModelCatalog::identityLabelPolicy('Grandstream', 'GRP2634');
$grandstreamDp752Labels = ModelCatalog::identityLabelPolicy('Grandstream', 'DP752');
$grandstreamDp755Labels = ModelCatalog::identityLabelPolicy('Grandstream', 'DP755');
catalogExpect(
    $xml7841Labels['phone_field'] === 'phoneLabel'
    && $xml7841Labels['phone_max_chars'] === 12
    && $xml7841Labels['line_field'] === 'featureLabel'
    && $xml7841Labels['line_max_chars'] === 30
    && $xml7841Labels['phone_spaces'] === true,
    'Cisco 7841 device and line label surfaces must remain distinct'
);
catalogExpect(
    $xml7962Labels['phone_max_chars'] === 12 && $xml7962Labels['phone_spaces'] === false,
    'Enterprise XML 7900 phoneLabel must retain its no-spaces model constraint'
);
catalogExpect(
    $cnf7960Labels['phone_field'] === 'phone_label'
    && $cnf7960Labels['phone_max_chars'] === 11
    && $cnf7960Labels['line_field'] === 'lineX_shortname'
    && $cnf7960Labels['line_limit_source'] === 'module_policy'
    && $cnf7960Labels['firmware_status_text'] === 'SIP',
    'Legacy Cisco phone, line and firmware status labels must not be conflated'
);
catalogExpect(
    $yealinkT48Labels['phone_field'] === null
    && $yealinkT48Labels['line_field'] === 'account.X.label'
    && $yealinkT48Labels['line_max_chars'] === 99
    && $yealinkT48Labels['caller_field'] === 'account.X.display_name'
    && $yealinkT48Labels['caller_max_chars'] === 99,
    'Yealink account label and caller identity must have independent documented fields'
);
catalogExpect(
    $yealinkW70Labels['handset_field'] === 'handset.X.name'
    && $yealinkW70Labels['handset_max_chars'] === 24,
    'Yealink DECT base must expose the documented handset-name field and limit'
);
catalogExpect(
    $grandstreamGrpLabels['line_field'] === 'Account Name (P270/P417/...)'
    && $grandstreamGrpLabels['caller_field'] === 'Name (P3/P407/...)'
    && $grandstreamGrpLabels['line_max_chars'] === null,
    'Grandstream desk Account Name and caller Name must remain separate without an invented universal limit'
);
catalogExpect(
    $grandstreamDp752Labels['line_field'] === null
    && $grandstreamDp752Labels['caller_max_chars'] === 256
    && $grandstreamDp752Labels['handset_max_chars'] === 10,
    'Grandstream DP750/DP752 must expose profile-specific caller and handset fields without inventing an account label'
);
catalogExpect(
    $grandstreamDp755Labels['line_field'] === 'account.X.name'
    && $grandstreamDp755Labels['line_max_chars'] === 512
    && $grandstreamDp755Labels['caller_field'] === 'account.X.sip.subscriber.name'
    && $grandstreamDp755Labels['handset_max_chars'] === 10,
    'Grandstream DP755 alias-v2 must use its own account, caller and handset limits'
);

$sip41Policy = ModelCatalog::ciscoXmlBranchPolicy('Cisco', '7941');
$sip42Policy = ModelCatalog::ciscoXmlBranchPolicy('Cisco', '7942');
$sip78Policy = ModelCatalog::ciscoXmlBranchPolicy('Cisco', '7841');
$sip8961Policy = ModelCatalog::ciscoXmlBranchPolicy('Cisco', '8961');
catalogExpect(
    ($sip41Policy['firmware_profile'] ?? '') === 'cisco:sip41'
    && ($sip41Policy['timezone_payload'] ?? '') === 'tzupdater.jar'
    && ($sip41Policy['softkey_file_status'] ?? '') === 'verified'
    && ($sip41Policy['locale_profile'] ?? '') === 'cisco:sip41-locale'
    && array_column($sip41Policy['locale_files'] ?? [], 'path') === ['{user_locale}/mk-sip.jar','{tone_path}/g3-tones.xml'],
    'SIP41 companion and softkey policy must be explicit'
);
catalogExpect(
    ($sip42Policy['firmware_profile'] ?? '') === 'cisco:sip42'
    && ($sip42Policy['locale_profile'] ?? '') === 'cisco:sip42-locale'
    && array_column($sip42Policy['locale_files'] ?? [], 'path') === ['{user_locale}/mk-sip.jar','{tone_path}/g3-tones.xml']
    && ($sip42Policy['softkey_file_status'] ?? '') === 'experimental',
    'SIP42 softkey support must remain visible but unverified'
);
catalogExpect(
    ($sip78Policy['timezone_payload'] ?? '') === 'j9-tzdata.jar'
    && ($sip78Policy['softkey_file_status'] ?? '') === 'verified',
    '78xx must remain on the separate J9 timezone and verified softkey path'
);
catalogExpect(
    ($sip8961Policy['timezone_payload'] ?? '') === 'tzupdater.jar'
    && ($sip8961Policy['softkey_file_status'] ?? '') === 'feature_policy_preferred'
    && !empty($sip8961Policy['feature_policy_preferred']),
    '8961 must prefer feature policy without losing its timezone payload mapping'
);

$localeExpectations = [
    '7906'=>['cisco:sip11-locale','tc-sip.jar','g3-tones.xml'],
    '7941'=>['cisco:sip41-locale','mk-sip.jar','g3-tones.xml'],
    '7942'=>['cisco:sip42-locale','mk-sip.jar','g3-tones.xml'],
    '7945'=>['cisco:sip45-locale','be-sip.jar','g3-tones.xml'],
    '7970'=>['cisco:sip70-locale','td-sip.jar','g3-tones.xml'],
    '7975'=>['cisco:sip75-locale','td-sip.jar','g3-tones.xml'],
    '7841'=>['cisco:sip78xx-locale','sp-sip.jar','g3-tones.xml'],
    '8841'=>['cisco:sip88xx-locale','sl-be-sip.jar','g3-tones.xml'],
    '8845'=>['cisco:sip8845_65-locale','sl-bev-sip.jar','g3-tones.xml'],
    '8945'=>['cisco:sip894x-locale','gb-sccp-sip.jar','g3-tones.xml'],
    '8961'=>['cisco:sip8961-locale','gh-sip.jar','g3-tones.xml'],
    '9951'=>['cisco:sip9951-locale','lk-sip.jar','g4-tones.xml'],
    '9971'=>['cisco:sip9971-locale','gd-sip.jar','g4-tones.xml'],
];
foreach ($localeExpectations as $model=>$expected) {
    $policy = ModelCatalog::ciscoXmlBranchPolicy('Cisco', (string)$model);
    catalogExpect(
        ($policy['locale_profile'] ?? '') === $expected[0]
        && array_map('basename', array_column($policy['locale_files'] ?? [], 'path')) === [$expected[1],$expected[2]],
        "Cisco $model locale companion mapping drifted"
    );
}
foreach (['8821','8831','8832'] as $unmappedModel) {
    catalogExpect(
        (ModelCatalog::ciscoXmlBranchPolicy('Cisco', $unmappedModel)['locale_profile'] ?? null) === null,
        "$unmappedModel must remain unmapped until a documented locale bundle is available"
    );
}

$timezoneRuntimeExpectations = [
    'cisco-79xx-8961-99x1'=>[
        'payload'=>'tzupdater.jar', 'architecture'=>'Standard JVM',
        'models'=>['7906','7911','7941','7942','7945','7961','7962','7965','7970','7971','7975','8961','9951','9971'],
    ],
    'cisco-78xx-88xx'=>[
        'payload'=>'j9-tzdata.jar', 'architecture'=>'IBM J9 JVM',
        'models'=>['7811','7821','7841','7861','8811','8821','8831','8832','8841','8845','8851','8861','8865'],
    ],
    'cisco-39xx-69xx-894x'=>[
        'payload'=>'TzDataCSV.csv', 'architecture'=>'Lodown / RTL / Gumbo',
        'models'=>['3905','3911','3951','6901','6911','6921','6941','6945','6961','8941','8945'],
    ],
];
foreach ($timezoneRuntimeExpectations as $profile=>$expected) {
    $definition = ModelCatalog::CISCO_TIMEZONE_PROFILES[$profile] ?? [];
    catalogExpect(
        ($definition['payload'] ?? '') === $expected['payload']
        && ($definition['architecture'] ?? '') === $expected['architecture']
        && ($definition['models'] ?? []) === $expected['models'],
        "$profile timezone runtime definition drifted"
    );
    foreach ($expected['models'] as $runtimeModel) {
        catalogExpect(
            ModelCatalog::ciscoTimezoneProfile('Cisco', $runtimeModel) === $profile
            && ModelCatalog::ciscoTimezonePayload('Cisco', $runtimeModel) === $expected['payload'],
            "$runtimeModel must resolve through the canonical timezone runtime map"
        );
    }
    foreach ((array)($definition['firmware_profiles'] ?? []) as $firmwareProfile) {
        foreach (ModelCatalog::modelsForFirmwareProfile((string)$firmwareProfile) as $firmwareModel) {
            catalogExpect(
                ModelCatalog::ciscoTimezoneProfile(
                    (string)$firmwareModel['vendor'],
                    (string)$firmwareModel['model']
                ) === $profile,
                "$firmwareProfile contains a model outside its timezone runtime"
            );
        }
    }
}

$wallpaperOld = ModelCatalog::ciscoDefaultWallpaperSupport('Cisco', '7941', 'SIP41.9-3-1SR3-1S');
$wallpaperMinimum = ModelCatalog::ciscoDefaultWallpaperSupport('Cisco', '7941', 'SIP41.9-3-1SR4-1S');
$wallpaperNew = ModelCatalog::ciscoDefaultWallpaperSupport('Cisco', '7941', 'SIP41.9-4-2SR3-1S');
$wallpaperUnknown = ModelCatalog::ciscoDefaultWallpaperSupport('Cisco', '7941', '');
catalogExpect($wallpaperOld['supported'] === false, 'SIP41 before 9.3(1)SR4 must not force defaultWallpaperFile');
catalogExpect($wallpaperMinimum['supported'] === true, 'SIP41 9.3(1)SR4 must support defaultWallpaperFile');
catalogExpect($wallpaperNew['supported'] === true, 'Newer SIP41 loads must support defaultWallpaperFile');
catalogExpect($wallpaperUnknown['supported'] === null, 'Unknown SIP41 load must remain an explicit unknown, not a false incompatibility');

$seedDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seedDb->exec('CREATE TABLE ucmp_models (id INTEGER PRIMARY KEY, vendor TEXT, model TEXT, family TEXT, line_keys INTEGER, supports_softkeys INTEGER, supports_featurepolicy INTEGER, kem_support INTEGER, default_firmware TEXT, notes TEXT, sip_accounts INTEGER)');
$seedDb->exec("INSERT INTO ucmp_models (vendor,model,notes,sip_accounts) VALUES ('Yealink','W70B','Operator note',1)");
ModelCatalog::seed($seedDb);
$seeded = $seedDb->query("SELECT sip_accounts,notes FROM ucmp_models WHERE vendor='Yealink' AND model='W70B'")->fetch(PDO::FETCH_ASSOC);
catalogExpect((int)$seeded['sip_accounts'] === 10 && $seeded['notes'] === 'Operator note', 'Seeding must use actual available capability columns and preserve operator notes');
catalogExpect(ModelCatalog::seed($seedDb) === 0, 'Repeated catalog seeding must not duplicate rows');
$seedDb->exec("UPDATE ucmp_models SET sip_accounts=777");
$seedBefore=$seedDb->query('SELECT * FROM ucmp_models ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$seedDb->exec("CREATE TRIGGER reject_later_model BEFORE UPDATE ON ucmp_models WHEN OLD.vendor='Yealink' BEGIN SELECT RAISE(ABORT,'later model failure'); END");
try{ModelCatalog::seed($seedDb);throw new LogicException('Partial model catalog was accepted');}catch(RuntimeException $expected){}
catalogExpect($seedBefore===$seedDb->query('SELECT * FROM ucmp_models ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'A late model failure must preserve every earlier capability row');
$failedMetadata = new class('sqlite::memory:') extends PDO {
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { throw new RuntimeException('Fixture metadata read failed'); }
};
$metadataRejected = false;
try { ModelCatalog::seed($failedMetadata); } catch (RuntimeException $error) { $metadataRejected = $error->getMessage() === 'Fixture metadata read failed'; }
catalogExpect($metadataRejected, 'A failed metadata read must not be guessed to mean a legacy schema');

echo "Model catalog fixtures passed\n";
