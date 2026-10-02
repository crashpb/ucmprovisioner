<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/CiscoTimezoneService.php';
require_once dirname(__DIR__) . '/lib/CiscoTimezoneBundleManager.php';
require_once __DIR__ . '/TimezoneRuntimeFixture.php';

use FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager;
use FreePBX\modules\Ucmprovisioner\CiscoTimezoneService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = 100;
$manager = new CiscoTimezoneBundleManager($db, static function () use (&$now): int { return $now; });
$inspect = static function (array $files): array {
    return CiscoTimezoneService::inspect(
        array_keys($files),
        static fn(string $name) => $files[$name] ?? false
    );
};
$stored = static function (array $inspection): array {
    return array_values(array_map(static fn(array $file): array => [
        'name'=>$file['name'],
        'stored_name'=>$file['sha256'] . '.blob',
        'size'=>$file['size'],
        'sha256'=>$file['sha256'],
    ], array_filter(
        $inspection['files'],
        static fn(array $file): bool => !empty($file['readable'])
    )));
};

$firstInspection = $inspect([
    'tzupdater.ver'=>"2022c-2026\n",
    'j9-tzdata.jar'=>timezoneRuntimeFixture('j9-tzdata.jar','j9 tzdata2022c bytes'),
]);
$first = $manager->store(
    1,
    $firstInspection,
    $stored($firstInspection),
    '.ucmprovisioner/timezone-bundles/' . $firstInspection['fingerprint']
);
$expect(
    $first['status'] === 'partial'
    && $first['served_version'] === '2022c-2026'
    && count($first['files']) === 2,
    'A family-complete partial timezone bundle must be retained with its exact manifest'
);

$now = 110;
$first = $manager->activate(1, (int)$first['id']);
$expect($first['active'] === 1 && $first['activated_at'] === 110, 'The selected timezone bundle must become active');

$secondInspection = $inspect([
    'tzupdater.ver'=>"2024a\n",
    'tzupdater.jar'=>timezoneRuntimeFixture('tzupdater.jar','Java tzdata2024a bytes'),
]);
$second = $manager->store(
    1,
    $secondInspection,
    $stored($secondInspection),
    '.ucmprovisioner/timezone-bundles/' . $secondInspection['fingerprint']
);
$now = 120;
$second = $manager->activate(1, (int)$second['id']);
$rows = $manager->list(1);
$expect(
    $second['active'] === 1
    && count(array_filter($rows, static fn(array $row): bool => !empty($row['active']))) === 1
    && (int)$rows[0]['id'] === (int)$second['id'],
    'Activation must retain history while leaving exactly one recorded active version per site'
);
$expect(
    $manager->get(1, (int)$first['id']) !== null
    && $manager->get(2, (int)$first['id']) === null,
    'Timezone bundle history and lookup must remain site scoped'
);

$makeVersion = static function (string $version) use ($inspect, $stored, $manager, &$now): array {
    $now++;
    $inspection = $inspect([
        'tzupdater.ver'=>$version . "\n",
        'TzDataCSV.csv'=>timezoneRuntimeFixture('TzDataCSV.csv','CSV tzdata' . $version),
    ]);
    return $manager->store(
        1,
        $inspection,
        $stored($inspection),
        '.ucmprovisioner/timezone-bundles/' . $inspection['fingerprint']
    );
};
$third = $makeVersion('2024b');
$fourth = $makeVersion('2025a');
$fifth = $makeVersion('2025b');
$sixth = $makeVersion('2025c');
$removed = $manager->prune(1, 3);
$remaining = $manager->list(1);
$expect(
    count($remaining) === 5
    && count($removed) === 1
    && $manager->get(1, (int)$first['id']) !== null
    && $manager->get(1, (int)$second['id']) !== null
    && (int)$removed[0]['id'] === (int)$third['id'],
    'Retention must keep three revisions per runtime without deleting another architecture; remaining='
        . json_encode(array_column($remaining, 'id')) . ' removed=' . json_encode(array_column($removed, 'id'))
);

$seventh = $makeVersion('2026a');
$protectedRemoval = $manager->prune(1, 3, [(int)$fourth['id']]);
$expect(
    $manager->get(1, (int)$fourth['id']) !== null
    && count($protectedRemoval) === 1
    && (int)$protectedRemoval[0]['id'] === (int)$fifth['id'],
    'Retention must preserve an explicitly protected runtime revision while pruning the next oldest peer'
);

$assets = $manager->assets(1);
$j9Assets = array_values(array_filter($assets, static fn(array $asset): bool => $asset['profile'] === 'cisco-78xx-88xx'));
$javaAssets = array_values(array_filter($assets, static fn(array $asset): bool => $asset['profile'] === 'cisco-79xx-8961-99x1'));
$csvAssets = array_values(array_filter($assets, static fn(array $asset): bool => $asset['profile'] === 'cisco-39xx-69xx-894x'));
$expect(
    count($j9Assets) === 1
    && count($javaAssets) === 1
    && count($csvAssets) === 3
    && preg_match('/^j[a-f0-9]{12}\.jar$/D', (string)$j9Assets[0]['published_filename']) === 1,
    'Retained sets must flatten into independent architecture/hash assets and phone-facing aliases'
);

$quarantineInspection = $inspect([
    'tzupdater.ver'=>"2025c\n",
    'j9-tzdata.jar'=>timezoneRuntimeFixture('j9-tzdata.jar','j9 tzdata2025c bytes'),
    'tzupdater.jar'=>timezoneRuntimeFixture('tzupdater.jar','legacy tzdata2020a bytes'),
]);
$quarantineInspection['status'] = 'quarantined';
$quarantine = $manager->store(
    1,
    $quarantineInspection,
    $stored($quarantineInspection),
    '.ucmprovisioner/timezone-bundles/' . $quarantineInspection['fingerprint']
);
$activationRejected = false;
try {
    $manager->activate(1, (int)$quarantine['id']);
} catch (InvalidArgumentException $error) {
    $activationRejected = str_contains($error->getMessage(), 'quarantined');
}
$manager->prune(1, 3);
$retained = $manager->list(1);
$expect(
    $activationRejected
    && count(array_filter($retained, static fn(array $row): bool => ($row['status'] ?? '') === 'quarantined')) === 1
    && count(array_filter($retained, static fn(array $row): bool => ($row['status'] ?? '') !== 'quarantined')) === 5,
    'A quarantine snapshot must be non-activatable and retained outside per-runtime history slots'
);

echo "Cisco timezone version persistence fixtures passed\n";
