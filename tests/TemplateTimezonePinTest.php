<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ModelCatalog.php';
require_once __DIR__ . '/../lib/TemplateEngine.php';

use FreePBX\modules\Ucmprovisioner\TemplateEngine;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$j9Default = str_repeat('a', 64);
$j9Override = str_repeat('b', 64);
$javaDefault = str_repeat('c', 64);
$legacyBundle = str_repeat('d', 64);
$csvDefault = str_repeat('4', 64);
$ucm = new class($j9Default, $j9Override, $javaDefault, $csvDefault, $legacyBundle) {
    public function __construct(
        private string $j9Default,
        private string $j9Override,
        private string $javaDefault,
        private string $csvDefault,
        private string $legacyBundle
    ) {}
    public function tftpOp(array $request): array
    {
        if (($request['op'] ?? '') !== 'timezone_versions') {
            throw new RuntimeException('Template must resolve timezone policy from the version inventory');
        }
        return [
            'ok'=>true,
            'current'=>[
                'ok'=>true, 'status'=>'partial', 'served_version'=>'2022c',
                'compatibility'=>[],
            ],
            'assets'=>[
                [
                    'asset_key'=>$this->j9Default, 'profile'=>'cisco-78xx-88xx',
                    'payload'=>'j9-tzdata.jar', 'version'=>'2022c', 'actual_version'=>'2022c',
                    'sha256'=>str_repeat('1', 64), 'selectable'=>true,
                    'published_filename'=>'ucmp-tz-j9-' . $this->j9Default . '.jar',
                    'source_bundle_key'=>$this->legacyBundle, 'status'=>'complete',
                ],
                [
                    'asset_key'=>$this->j9Override, 'profile'=>'cisco-78xx-88xx',
                    'payload'=>'j9-tzdata.jar', 'version'=>'2024a', 'actual_version'=>'',
                    'sha256'=>str_repeat('2', 64), 'selectable'=>true,
                    'published_filename'=>'ucmp-tz-j9-' . $this->j9Override . '.jar',
                    'source_bundle_key'=>str_repeat('e', 64), 'status'=>'unverified',
                ],
                [
                    'asset_key'=>$this->javaDefault, 'profile'=>'cisco-79xx-8961-99x1',
                    'payload'=>'tzupdater.jar', 'version'=>'2025b', 'actual_version'=>'2025b',
                    'sha256'=>str_repeat('3', 64), 'selectable'=>true,
                    'published_filename'=>'ucmp-tz-java-' . $this->javaDefault . '.jar',
                    'source_bundle_key'=>str_repeat('f', 64), 'status'=>'complete',
                ],
                [
                    'asset_key'=>$this->csvDefault, 'profile'=>'cisco-39xx-69xx-894x',
                    'payload'=>'TzDataCSV.csv', 'version'=>'2025b', 'actual_version'=>'2025b',
                    'sha256'=>str_repeat('4', 64), 'selectable'=>true,
                    'published_filename'=>'ucmp-tz-csv-' . $this->csvDefault . '.csv',
                    'source_bundle_key'=>str_repeat('8', 64), 'status'=>'complete',
                ],
            ],
            'bundles'=>[],
        ];
    }
};

$resolve = new ReflectionMethod(TemplateEngine::class, 'resolveCiscoTimezone');
$resolve->setAccessible(true);
$model = ['vendor'=>'Cisco','model'=>'8845','family'=>'cisco_xml'];

$settings = [
    'cisco_xml_olson_timezone'=>'Asia/Tehran',
    'cisco_timezone_defaults'=>['cisco-78xx-88xx'=>$j9Default],
];
$engine = new TemplateEngine($ucm);
$resolve->invokeArgs($engine, [1, $model, &$settings]);
$expect(($settings['_cisco_tzdata_version'] ?? '') === '2022c', 'The matching runtime site default must be emitted');
$expect(($settings['_cisco_tzdata_file'] ?? '') === 'ucmp-tz-j9-' . $j9Default . '.jar', 'The generated config must request the retained hash alias');
$expect(($settings['_cisco_tzdata_asset_key'] ?? '') === $j9Default, 'Generated diagnostics must retain the architecture asset identity');
$expect(empty($settings['_cisco_tzdata_warning']), 'A complete site-default runtime asset must not warn');

$override = [
    'cisco_xml_olson_timezone'=>'Asia/Tehran',
    'cisco_timezone_defaults'=>['cisco-78xx-88xx'=>$j9Default],
    'cisco_timezone_by_profile'=>['cisco-78xx-88xx'=>$j9Override],
];
$engine = new TemplateEngine($ucm);
$resolve->invokeArgs($engine, [1, $model, &$override]);
$expect(
    ($override['_cisco_tzdata_file'] ?? '') === 'ucmp-tz-j9-' . $j9Override . '.jar'
    && ($override['_cisco_tzdata_version'] ?? '') === '2024a'
    && str_contains((string)($override['_cisco_tzdata_warning'] ?? ''), 'opaque'),
    'A template may select a different retained revision only for its matching runtime'
);

$missingOverride = [
    'cisco_xml_olson_timezone'=>'Asia/Tehran',
    'cisco_timezone_defaults'=>['cisco-78xx-88xx'=>$j9Default],
    'cisco_timezone_by_profile'=>['cisco-78xx-88xx'=>str_repeat('9', 64)],
];
$resolve->invokeArgs(new TemplateEngine($ucm), [1, $model, &$missingOverride]);
$expect(
    ($missingOverride['_cisco_tzdata_asset_key'] ?? '') === $j9Default
    && str_contains((string)($missingOverride['_cisco_tzdata_warning'] ?? ''), 'destination site’s runtime default'),
    'A missing pushed template dependency must fall back to the destination runtime default and report it'
);

$javaSettings = [
    'cisco_xml_olson_timezone'=>'Asia/Tehran',
    'cisco_timezone_defaults'=>[
        'cisco-78xx-88xx'=>$j9Default,
        'cisco-79xx-8961-99x1'=>$javaDefault,
    ],
];
$resolve->invokeArgs(new TemplateEngine($ucm), [1, ['vendor'=>'Cisco','model'=>'7941','family'=>'cisco_xml'], &$javaSettings]);
$expect(
    ($javaSettings['_cisco_tzdata_asset_key'] ?? '') === $javaDefault
    && str_contains((string)($javaSettings['_cisco_tzdata_file'] ?? ''), 'ucmp-tz-java-'),
    'The same defaults map must resolve Standard JVM phones independently from J9 phones'
);

$csvSettings = [
    'cisco_xml_olson_timezone'=>'Asia/Tehran',
    'cisco_timezone_defaults'=>['cisco-39xx-69xx-894x'=>$csvDefault],
];
$resolve->invokeArgs(new TemplateEngine($ucm), [1, ['vendor'=>'Cisco','model'=>'8945','family'=>'cisco_xml'], &$csvSettings]);
$expect(
    ($csvSettings['_cisco_tzdata_asset_key'] ?? '') === $csvDefault
    && ($csvSettings['_cisco_tzdata_file'] ?? '') === 'ucmp-tz-csv-' . $csvDefault . '.csv',
    'Lodown / RTL / Gumbo phones must emit their independently selected CSV runtime revision'
);

$legacySettings = [
    'cisco_xml_olson_timezone'=>'Asia/Tehran',
    'cisco_timezone_bundle_key'=>$legacyBundle,
];
$resolve->invokeArgs(new TemplateEngine($ucm), [1, $model, &$legacySettings]);
$expect(
    ($legacySettings['_cisco_tzdata_asset_key'] ?? '') === $j9Default,
    'A pre-profile bundle pin must migrate to the matching runtime asset without requiring every architecture'
);

echo "Template timezone pin fixtures passed\n";
