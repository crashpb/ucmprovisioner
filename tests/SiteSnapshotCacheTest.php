<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/SiteSnapshotCache.php';

use FreePBX\modules\Ucmprovisioner\SiteSnapshotCache;

$expect = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ucm-site-snapshot-' . bin2hex(random_bytes(6));
$now = 1000;
$cache = new SiteSnapshotCache($root, static function () use (&$now): int {
    return $now;
});

$removeTree = static function (string $path): void {
    $real = realpath($path);
    $temp = realpath(sys_get_temp_dir());
    if ($real === false || $temp === false || strpos($real, $temp . DIRECTORY_SEPARATOR . 'ucm-site-snapshot-') !== 0) {
        throw new RuntimeException('Refusing to clean an unexpected test path');
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($real);
};

try {
    $live = $cache->readThrough('read', 'site:12', 'phones.list', 300, static fn(): array => [
        'ok' => true,
        'phones' => [['extension' => '4010']],
    ]);
    $expect($live['data']['phones'][0]['extension'] === '4010', 'Successful live data was not returned');
    $expect($live['meta'] === [
        'site_key' => 'site:12',
        'resource_key' => 'phones.list',
        'source' => 'live',
        'fresh' => true,
        'stale' => false,
        'offline' => false,
        'fetched_at' => 1000,
        'age' => 0,
        'error' => null,
    ], 'Successful live metadata is incorrect');

    $files = glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.json');
    $expect(is_array($files) && count($files) === 1, 'Successful read did not create exactly one snapshot');
    $stored = json_decode((string)file_get_contents($files[0]), true, 32, JSON_THROW_ON_ERROR);
    $expect(array_keys($stored) === ['schema', 'site_key', 'resource_key', 'fetched_at', 'data'], 'Stored snapshot schema changed');
    $expect($stored['schema'] === 1 && $stored['fetched_at'] === 1000, 'Stored snapshot identity is incorrect');

    $now = 1100;
    $freshOffline = $cache->readThrough('read', 'site:12', 'phones.list', 300, static function (): array {
        throw new RuntimeException('remote site unavailable');
    });
    $expect($freshOffline['data'] === $live['data'], 'Fresh last-known-good data was not served');
    $expect($freshOffline['meta']['source'] === 'cache'
        && $freshOffline['meta']['fresh'] === true
        && $freshOffline['meta']['stale'] === false
        && $freshOffline['meta']['offline'] === true
        && $freshOffline['meta']['fetched_at'] === 1000
        && $freshOffline['meta']['age'] === 100
        && $freshOffline['meta']['error'] === 'remote site unavailable',
        'Fresh offline metadata is incorrect');

    $now = 1401;
    $staleOffline = $cache->readThrough('read', 'site:12', 'phones.list', 300, static function (): array {
        throw new RuntimeException('token=abc123 connection refused');
    });
    $expect($staleOffline['meta']['fresh'] === false
        && $staleOffline['meta']['stale'] === true
        && $staleOffline['meta']['offline'] === true
        && strpos((string)$staleOffline['meta']['error'], 'abc123') === false,
        'Stale/offline metadata or error redaction is incorrect');

    $now = 1500;
    foreach (['request failed: password=synthetic-secret', 'HTTP Authorization: Bearer synthetic-secret',
        'response Cookie: session=synthetic-secret; second=also-sensitive', '{"token":"synthetic-secret"}',
        'fetch https://example.test/read?token=synthetic-secret'] as $errorText) {
        $redacted=$cache->readThrough('read','site:12','phones.list',300,static function()use($errorText):array{throw new RuntimeException($errorText);});
        $expect(!str_contains($redacted['meta']['error'],'synthetic-secret')&&!str_contains($redacted['meta']['error'],'also-sensitive'),
            'Embedded credentials and complete authorization/cookie values must not enter cache diagnostics');
    }
    $explicitFailure = $cache->readThrough('read', 'site:12', 'phones.list', 300, static fn(): array => [
        'ok' => false,
        'message' => 'API read failed',
        'phones' => [['extension' => 'SHOULD-NOT-CACHE']],
    ]);
    $expect($explicitFailure['data'] === $live['data']
        && $explicitFailure['meta']['fetched_at'] === 1000,
        'An ok=false response replaced the last-known-good snapshot');

    $invoked = false;
    try {
        $cache->readThrough('mutation', 'site:12', 'phones.list', 300, static function () use (&$invoked): array {
            $invoked = true;
            return ['ok' => true];
        });
        throw new RuntimeException('Mutation intent was accepted');
    } catch (LogicException $e) {
        $expect($invoked === false, 'Mutation callback ran before snapshot rejection');
    }

    try {
        $cache->readThrough('read', 'site:12', 'phones.list', 300, static fn(): array => [
            'ok' => true,
            'site' => ['pbx_api_secret' => 'do-not-store'],
        ]);
        throw new RuntimeException('Secret-bearing read was accepted');
    } catch (InvalidArgumentException $e) {
        $expect(strpos($e->getMessage(), 'site.pbx_api_secret') !== false, 'Sensitive field path was not identified');
    }
    $afterSecret = json_decode((string)file_get_contents($files[0]), true, 32, JSON_THROW_ON_ERROR);
    $expect($afterSecret['fetched_at'] === 1000 && $afterSecret['data'] === $live['data'], 'Secret-bearing data changed the snapshot');

    $filteredLive = $cache->readThrough(
        'read',
        'site:12',
        'templates.list',
        300,
        static fn(): array => ['ok'=>true, 'password'=>'live-only', 'templates'=>[['id'=>7]]],
        static function (array $data): array {
            unset($data['password']);
            return $data;
        }
    );
    $expect(($filteredLive['data']['password'] ?? '') === 'live-only', 'Snapshot filtering changed the live response');
    $filteredOffline = $cache->readThrough(
        'read',
        'site:12',
        'templates.list',
        300,
        static function (): array { throw new RuntimeException('offline'); }
    );
    $expect(!array_key_exists('password', $filteredOffline['data'])
        && (int)$filteredOffline['data']['templates'][0]['id'] === 7,
        'The durable snapshot did not use the secret-free filtered representation');

    $now = 1600;
    $refreshed = $cache->readThrough('read', 'site:12', 'phones.list', 300, static fn(): array => [
        'ok' => true,
        'phones' => [['extension' => '4011']],
    ]);
    $expect($refreshed['meta']['fetched_at'] === 1600
        && $refreshed['data']['phones'][0]['extension'] === '4011',
        'A later successful read did not replace the snapshot');
    $now = 1650;
    $refreshedOffline = $cache->readThrough('read', 'site:12', 'phones.list', 300, static function (): array {
        throw new RuntimeException('pbx_api_secret=abc123 connection refused');
    });
    $expect($refreshedOffline['meta']['fetched_at'] === 1600
        && $refreshedOffline['data']['phones'][0]['extension'] === '4011'
        && strpos((string)$refreshedOffline['meta']['error'], 'abc123') === false,
        'Refreshed snapshot or prefixed-secret error redaction is incorrect');

    $now = 1700;
    $otherSite = $cache->readThrough('read', 'site:13', 'phones.list', 300, static fn(): array => [
        'ok' => true,
        'phones' => [['extension' => '5010']],
    ]);
    $otherResource = $cache->readThrough('read', 'site:12', 'firmware.list', 300, static fn(): array => [
        'ok' => true,
        'firmware' => ['sip88xx.14-2-1.loads'],
    ]);
    $expect($otherSite['data']['phones'][0]['extension'] === '5010'
        && $otherResource['data']['firmware'][0] === 'sip88xx.14-2-1.loads',
        'Site/resource snapshot keys collided');

    $missing = $cache->readThrough('read', 'site:99', 'phones.list', 300, static function (): array {
        throw new RuntimeException('offline with no history');
    });
    $expect($missing['data'] === null
        && $missing['meta']['source'] === 'none'
        && $missing['meta']['fresh'] === false
        && $missing['meta']['stale'] === false
        && $missing['meta']['offline'] === true
        && $missing['meta']['fetched_at'] === null,
        'Offline response without a snapshot is incorrect');
} finally {
    if (is_dir($root)) {
        $removeTree($root);
    }
}

echo "Site snapshot cache tests passed\n";
