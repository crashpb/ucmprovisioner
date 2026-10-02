<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$moduleXml = (string)file_get_contents($root . '/module.xml');
$expect((bool)preg_match('/<version>([0-9A-Za-z.+~-]+)<\/version>/', $moduleXml, $versionMatch), 'module.xml has no valid release version');
$version = $versionMatch[1];
$expect(str_contains((string)file_get_contents($root . '/CHANGELOG.md'), '## ' . $version . ' - '), 'Current release has no changelog entry');
$expect((bool)preg_match('/<publisher>crashpb<\/publisher>/', $moduleXml), 'module.xml publisher is not the public project owner');
$expect((bool)preg_match('/<license>GPLv3\+<\/license>/', $moduleXml), 'module.xml license is not GPLv3+');
$expect(
    (bool)preg_match(
        '/<key\s+name="idx_ucmp_phones_mac"\s+type="unique">\s*<column\s+name="site_id"\s*\/>\s*<column\s+name="mac"\s*\/>\s*<\/key>/s',
        $moduleXml
    ),
    'Fresh-install phone MAC identity must be a unique (site_id, mac) index'
);

$composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$expect(($composer['name'] ?? '') === 'crashpb/ucmprovisioner', 'Composer package name is incorrect');
$expect(($composer['license'] ?? '') === 'GPL-3.0-or-later', 'Composer license is incorrect');
$expect(($composer['authors'][0]['name'] ?? '') === 'crashpb', 'Composer author is not the public project owner');

$license = (string)file_get_contents($root . '/LICENSE');
$expect(str_contains($license, 'GNU GENERAL PUBLIC LICENSE'), 'Complete GNU GPL text is missing');
$expect(str_contains($license, 'Version 3, 29 June 2007'), 'LICENSE is not GPL version 3');

$required = [
    'README.md', 'CHANGELOG.md', 'NOTICE.md', 'SECURITY.md', 'CONTRIBUTING.md',
    'THIRD-PARTY-NOTICES.md', 'docs/ADMINISTRATOR-MANUAL.md',
    'docs/ARCHITECTURE.md', 'docs/DEVICE-SUPPORT.md', 'docs/FRESH-INSTALL.md',
    'docs/TROUBLESHOOTING.md', 'docs/cisco-enterprise-softkeys.md',
    'docs/FEATURES.md', 'docs/EXAMPLES.md', 'docs/DEVELOPER-REFERENCE.md',
];
foreach ($required as $relative) {
    $path = $root . '/' . $relative;
    $expect(is_file($path) && filesize($path) > 100, "Required release document is missing or empty: $relative");
}
foreach ([
    'docs/media-tab-v3-acceptance.html',
    'docs/MEDIA-V3-ACCEPTANCE.md',
    'docs/service-manager-plan.md',
] as $internalArtifact) {
    $expect(!file_exists($root . '/' . $internalArtifact), "Internal design artifact is present: $internalArtifact");
}

$readme = (string)file_get_contents($root . '/README.md');
$notice = (string)file_get_contents($root . '/NOTICE.md');
$expect(str_contains($readme, 'UCM Provisioner ' . $version), 'README does not identify the current release');
$expect(str_contains($readme, 'crashpb') && str_contains($notice, 'crashpb'), 'Release attribution is incomplete');

$page = (string)file_get_contents($root . '/page.ucmprovisioner.php');
$expect(
    str_contains($page, '$active = is_string($requestedTab) ? $requestedTab : \'dashboard\';'),
    'Page routing must reject non-scalar tab input'
);

$builder = (string)file_get_contents($root . '/tools/build-release.py');
foreach (['FORBIDDEN_NAMES', 'FORBIDDEN_SUFFIXES', 'path.is_symlink()', 'module.sig'] as $releaseGuard) {
    $expect(str_contains($builder, $releaseGuard), "Release builder guard is missing: $releaseGuard");
}

$markdown = [
    ...(glob($root . '/*.md') ?: []),
    ...(glob($root . '/docs/*.md') ?: []),
];
foreach ($markdown as $document) {
    $contents = (string)file_get_contents($document);
    preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $contents, $matches);
    foreach ($matches[1] as $target) {
        $target = trim((string)$target, " <>\t\r\n");
        if ($target === '' || $target[0] === '#'
            || preg_match('#^(?:https?|mailto):#i', $target)) {
            continue;
        }
        $pathPart = rawurldecode(explode('#', $target, 2)[0]);
        $expect(
            is_file(dirname($document) . '/' . $pathPart),
            basename($document) . " links to a missing file: $target"
        );
    }
}

echo "Release metadata, attribution, documentation and package guards are coherent\n";
