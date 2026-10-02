<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/FirmwareService.php';

use FreePBX\modules\Ucmprovisioner\FirmwareService;

function firmwareExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function firmwareEntries(array $names): array
{
    return array_map(static fn(string $name): array => ['path'=>$name, 'name'=>$name, 'size'=>100, 'mtime'=>1], $names);
}

firmwareExpect(FirmwareService::modelProfile('Cisco', '7941') === 'cisco:sip41', '7941 must use only the SIP41 profile');
firmwareExpect(FirmwareService::compatible('cisco:sip41', 'Cisco', '7961'), '7961 should share SIP41');
firmwareExpect(!FirmwareService::compatible('cisco:sip42', 'Cisco', '7941'), 'SIP42 must never be accepted for a 7941');
firmwareExpect(FirmwareService::modelProfile('Cisco', '8845') === 'cisco:sip8845_65', '8845 needs the video profile');
firmwareExpect(FirmwareService::modelProfile('Cisco', '8861') === 'cisco:sip88xx', '8861 is an audio SIP88xx phone');
firmwareExpect(!FirmwareService::compatible('cisco:sip8845_65', 'Cisco', '8861'), 'The 8845/8865 video load is dangerous on 8861');
firmwareExpect(FirmwareService::modelProfile('Cisco', '7950') === null, 'Unsupported 7950 must stay quarantined');
$profileMatrix = [
    '7940'=>'cisco:p0s3','7960'=>'cisco:p0s3','7906'=>'cisco:sip11','7911'=>'cisco:sip11',
    '7941'=>'cisco:sip41','7961'=>'cisco:sip41','7942'=>'cisco:sip42','7962'=>'cisco:sip42',
    '7945'=>'cisco:sip45','7965'=>'cisco:sip45','7970'=>'cisco:sip70','7971'=>'cisco:sip70','7975'=>'cisco:sip75',
    '7811'=>'cisco:sip78xx','7821'=>'cisco:sip78xx','7841'=>'cisco:sip78xx','7861'=>'cisco:sip78xx',
    '8811'=>'cisco:sip88xx','8841'=>'cisco:sip88xx','8851'=>'cisco:sip88xx','8861'=>'cisco:sip88xx',
    '8845'=>'cisco:sip8845_65','8865'=>'cisco:sip8845_65','8821'=>'cisco:sip8821',
    '8831'=>'cisco:sip8831','8832'=>'cisco:sip8832','8941'=>'cisco:sip894x','8945'=>'cisco:sip894x',
    '8961'=>'cisco:sip8961','9951'=>'cisco:sip9951','9971'=>'cisco:sip9971',
];
foreach ($profileMatrix as $model => $profile) {
    firmwareExpect(FirmwareService::modelProfile('Cisco', (string)$model) === $profile, "Cisco $model firmware profile mismatch");
}
firmwareExpect(FirmwareService::modelProfile('Yealink', 'T48U') === 'yealink:t48u', 'Yealink firmware profiles should be model-specific');
firmwareExpect(FirmwareService::modelProfile('Grandstream', 'GHP631') === 'grandstream:ghp631', 'Grandstream firmware profiles should be model-specific');
$sharedYealink = FirmwareService::analyzeFiles(
    [['path'=>'firmware/yealink/t48u/shared-release.rom','name'=>'shared-release.rom','size'=>100,'mtime'=>1]],
    static fn(string $path) => false
);
firmwareExpect(($sharedYealink[0]['profile'] ?? '') === 'yealink:t48u' && ($sharedYealink[0]['status'] ?? '') === 'complete', 'A model-specific manager folder must classify a shared Yealink filename');
$sharedGrandstream = FirmwareService::analyzeFiles(
    [['path'=>'firmware/grandstream/grp2614/phonefw.bin','name'=>'phonefw.bin','size'=>100,'mtime'=>1]],
    static fn(string $path) => false
);
firmwareExpect(($sharedGrandstream[0]['profile'] ?? '') === 'grandstream:grp2614' && ($sharedGrandstream[0]['status'] ?? '') === 'complete', 'A model-specific manager folder must classify a shared Grandstream filename');

$declaredYealinkManifest = FirmwareService::compatibilityManifest(
    'Yealink',
    'vendor-release-42.rom',
    ['T46U','T48U'],
    100
);
$declaredYealinkPath = 'firmware/yealink/yealink_t4u/vendor-release-42.rom';
$declaredYealinkSidecar = $declaredYealinkPath . FirmwareService::DECLARATION_SUFFIX;
$declaredYealink = FirmwareService::analyzeFiles([
    ['path'=>$declaredYealinkPath,'name'=>'vendor-release-42.rom','size'=>100,'mtime'=>1],
    ['path'=>$declaredYealinkSidecar,'name'=>'vendor-release-42.rom'.FirmwareService::DECLARATION_SUFFIX,'size'=>200,'mtime'=>1],
], static fn(string $path) => $path === $declaredYealinkSidecar ? json_encode($declaredYealinkManifest, JSON_THROW_ON_ERROR) : false);
firmwareExpect(
    array_column($declaredYealink, 'profile') === ['yealink:t46u','yealink:t48u']
    && !array_filter($declaredYealink, static fn(array $bundle): bool => $bundle['status'] !== 'complete'),
    'A user declaration must assign an arbitrary Yealink ROM filename to every explicitly selected model'
);
firmwareExpect(
    count($declaredYealink[0]['files'] ?? []) === 2
    && ($declaredYealink[0]['files'][1]['role'] ?? '') === 'compatibility_manifest',
    'The compatibility sidecar must travel with the declared firmware package'
);

$declaredGrandstreamManifest = FirmwareService::compatibilityManifest(
    'Grandstream',
    'hotel-current.bin',
    ['GHP630','GHP631W'],
    101
);
$declaredGrandstreamPath = 'firmware/grandstream/grandstream_ghp63x/hotel-current.bin';
$declaredGrandstreamSidecar = $declaredGrandstreamPath . FirmwareService::DECLARATION_SUFFIX;
$declaredGrandstream = FirmwareService::analyzeFiles([
    ['path'=>$declaredGrandstreamPath,'name'=>'hotel-current.bin','size'=>100,'mtime'=>1],
    ['path'=>$declaredGrandstreamSidecar,'name'=>'hotel-current.bin'.FirmwareService::DECLARATION_SUFFIX,'size'=>200,'mtime'=>1],
], static fn(string $path) => $path === $declaredGrandstreamSidecar ? json_encode($declaredGrandstreamManifest, JSON_THROW_ON_ERROR) : false);
firmwareExpect(
    array_column($declaredGrandstream, 'profile') === ['grandstream:ghp630','grandstream:ghp631w'],
    'A Grandstream binary filename without a model token must follow its explicit operator declaration'
);

$legacyExpected = ['P0S3-8-12-00.loads','P0S3-8-12-00.sb2','P003-8-12-00.sbn','P003-8-12-00.bin'];
firmwareExpect(FirmwareService::legacyP0s3Files('P0S3-8-12-00.loads') === $legacyExpected, 'P0S3 must require the complete four-file loose bundle');
$legacyContent = "P0S3-8-12-00.loads\0P003-8-12-00.sbn\nP0S3-8-12-00.sb2\n";
$legacy = FirmwareService::analyzeFiles(firmwareEntries($legacyExpected), static fn(string $path) => str_ends_with($path, '.loads') ? $legacyContent : false);
firmwareExpect(count($legacy) === 1 && $legacy[0]['status'] === 'complete' && count($legacy[0]['files']) === 4, 'All four P0S3 files should form one complete bundle');

$sipFiles = ['SIP41.9-4-2SR3-1S.loads','apps41.9-4-2.sbn','dsp41.9-4-2.sbn'];
$sipManifest = implode("\0", $sipFiles);
$sip = FirmwareService::analyzeFiles(firmwareEntries($sipFiles), static fn(string $path) => str_ends_with($path, '.loads') ? $sipManifest : false);
firmwareExpect($sip[0]['profile'] === 'cisco:sip41' && $sip[0]['status'] === 'complete', 'Embedded signed-manifest filenames should produce a complete SIP41 bundle');
firmwareExpect(count(FirmwareService::manifestDependencies($sipManifest)) === 3, 'Manifest parser must recover every plaintext dependency filename');

$caseFiles = ['SIP41.9-4-2SR3-1S.loads','APPS41.9-4-2.SBN','dsp41.9-4-2.sbn'];
$case = FirmwareService::analyzeFiles(firmwareEntries($caseFiles), static fn(string $path) => str_ends_with(strtolower($path), '.loads') ? $sipManifest : false);
firmwareExpect($case[0]['status'] === 'case_mismatch', 'A case-insensitive-only dependency match must be reported, not silently accepted');
firmwareExpect(($case[0]['missing'][0]['reason'] ?? '') === 'case_mismatch', 'Case mismatch must include the expected and observed names');

$missing = FirmwareService::analyzeFiles(firmwareEntries(array_slice($sipFiles, 0, 2)), static fn(string $path) => str_ends_with($path, '.loads') ? $sipManifest : false);
firmwareExpect($missing[0]['status'] === 'incomplete', 'A missing manifest dependency must invalidate the bundle');
$validation = FirmwareService::validateRecord($missing[0], ['vendor'=>'Cisco','model'=>'7941']);
firmwareExpect(!$validation['ok'], 'An incomplete bundle must not validate for a compatible model');

foreach (['Yealink-T48U.rom', 'S001-test.sbn'] as $emptyName) {
    $empty = FirmwareService::analyzeFiles(
        [['path'=>$emptyName,'name'=>$emptyName,'size'=>0,'mtime'=>1]],
        static fn(string $path) => false
    );
    firmwareExpect(($empty[0]['status'] ?? '') === 'incomplete', 'An empty standalone or accessory file must not be selectable');
}
$emptyDependencies = firmwareEntries($sipFiles);
$emptyDependencies[1]['size'] = 0;
$emptyDependencyBundle = FirmwareService::analyzeFiles($emptyDependencies, static fn(string $path) => $sipManifest);
firmwareExpect(($emptyDependencyBundle[0]['status'] ?? '') === 'incomplete'
    && !FirmwareService::validateRecord($emptyDependencyBundle[0], ['vendor'=>'Cisco','model'=>'7941'])['ok'],
    'An existing but empty Cisco dependency must invalidate the bundle');
$emptyDeclared = FirmwareService::analyzeFiles([
    ['path'=>$declaredYealinkPath,'name'=>basename($declaredYealinkPath),'size'=>0,'mtime'=>1],
    ['path'=>$declaredYealinkSidecar,'name'=>basename($declaredYealinkSidecar),'size'=>200,'mtime'=>1],
], static fn(string $path) => json_encode($declaredYealinkManifest, JSON_THROW_ON_ERROR));
firmwareExpect(!array_filter($emptyDeclared, static fn(array $bundle): bool => $bundle['status'] === 'complete'),
    'An explicit model declaration must not make an empty binary complete');

$archive = FirmwareService::analyzeFiles(firmwareEntries(['cmterm-7941-sip.zip']), static fn(string $path) => false);
firmwareExpect($archive[0]['status'] === 'archive', 'Vendor archives must be flagged as inert rather than treated as TFTP firmware');

$scanSftp = new class {
    public array $calls = [];
    private array $tree = [
        '/srv/tftp'=>[
            'SIP41.9-4-2SR3-1S.loads'=>['type'=>1,'size'=>100,'mtime'=>1],
            'linked-T48U.rom'=>['type'=>3,'size'=>100,'mtime'=>1],
            'fifo-T48U.rom'=>['type'=>7,'size'=>100,'mtime'=>1],
            'unknown-T48U.rom'=>['size'=>100,'mtime'=>1],
            'Firmware'=>['type'=>2],
            '.ucmprovisioner'=>['type'=>2],
            'Desktops'=>['type'=>2],
            'unrelated'=>['type'=>2],
        ],
        '/srv/tftp/Firmware'=>[
            '7940-60'=>['type'=>2],
            'yealink'=>['type'=>2],
            'grandstream'=>['type'=>2],
            'history'=>['type'=>2],
            'wallpapers'=>['type'=>2],
        ],
        '/srv/tftp/Firmware/7940-60'=>[
            'P0S3-8-12-00.loads'=>['type'=>1,'size'=>100,'mtime'=>1],
        ],
        '/srv/tftp/Firmware/yealink'=>['t48u'=>['type'=>2]],
        '/srv/tftp/Firmware/yealink/t48u'=>[
            'shared-release.rom'=>['type'=>1,'size'=>100,'mtime'=>1],
        ],
        '/srv/tftp/Firmware/grandstream'=>['ghp630'=>['type'=>2]],
        '/srv/tftp/Firmware/grandstream/ghp630'=>[
            'hotel-current.bin'=>['type'=>1,'size'=>100,'mtime'=>1],
        ],
    ];
    public function rawlist(string $path, bool $recursive = false): array
    {
        if ($recursive) { throw new RuntimeException('Firmware scan must never request an eager recursive rawlist'); }
        $this->calls[] = $path;
        if (!isset($this->tree[$path])) { throw new RuntimeException('Unexpected firmware scan path ' . $path); }
        return $this->tree[$path];
    }
};
$scanService = (new ReflectionClass(FirmwareService::class))->newInstanceWithoutConstructor();
$scanMethod = new ReflectionMethod(FirmwareService::class, 'scanFiles');
$scanMethod->setAccessible(true);
$scannedFiles = $scanMethod->invoke($scanService, $scanSftp, '/srv/tftp');
firmwareExpect(
    array_column($scannedFiles, 'path') === [
        'SIP41.9-4-2SR3-1S.loads',
        'Firmware/7940-60/P0S3-8-12-00.loads',
        'Firmware/yealink/t48u/shared-release.rom',
        'Firmware/grandstream/ghp630/hotel-current.bin',
    ],
    'Bounded inventory must retain root Cisco and managed Cisco/Yealink/Grandstream firmware'
);
firmwareExpect(
    !array_filter($scanSftp->calls, static fn(string $path): bool =>
        str_contains($path, '.ucmprovisioner')
        || str_contains($path, 'Desktops')
        || str_contains($path, 'unrelated')
        || str_contains($path, 'history')
        || str_contains($path, 'wallpapers')
    ),
    'Firmware inventory must not traverse internal, history, media or unrelated TFTP directories'
);

echo "Firmware service fixtures passed\n";
