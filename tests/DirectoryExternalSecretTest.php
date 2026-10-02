<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/DirectoryService.php';

use FreePBX\modules\Ucmprovisioner\DirectoryService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$throws = static function (callable $call, string $message): void {
    try { $call(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ucmp-directory-secret-' . bin2hex(random_bytes(8));
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ucmp_directory_sources (
        id INTEGER PRIMARY KEY,
        source_key TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 0,
        secret_ref TEXT,
        schedule_minutes INTEGER NOT NULL DEFAULT 0,
        last_attempt INTEGER NOT NULL DEFAULT 0,
        last_success INTEGER NOT NULL DEFAULT 0,
        last_error TEXT
    )'
);
$db->exec(
    'CREATE TABLE ucmp_directory_sync_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_id INTEGER NOT NULL,
        started INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL
    )'
);
$db->exec(
    "INSERT INTO ucmp_directory_sources
        (id,source_key,enabled,secret_ref,schedule_minutes)
     VALUES
        (1,'domain-a',0,'file:domain-a',15),
        (2,'legacy-default',0,'legacy-setting:ad_bind_password',15),
        (3,'wrong-ref',0,'file:other-source',15)"
);

$ucm = new class($db, $root) {
    public function __construct(private PDO $db, private string $root) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function directorySecretRoot(): string { return $this->root; }
    public function getSiteSettingsLocal(int $siteId): array { return []; }
};
$service = new DirectoryService($ucm);

try {
    $saved = $service->saveSourceSecret(1, [
        'bind_user'=>'svc-directory@example.test',
        'password'=>'not-returned-secret',
    ]);
    $expect(
        $saved['ok'] === true
        && $saved['configured'] === true
        && !array_key_exists('password', $saved)
        && !array_key_exists('bind_user', $saved)
        && !array_key_exists('path', $saved)
        && !str_contains(json_encode($saved, JSON_THROW_ON_ERROR), 'not-returned-secret'),
        'Credential provisioning must never return the bind identity, password, or filesystem path'
    );

    $path = $root . DIRECTORY_SEPARATOR . 'domain-a.json';
    $expect(is_file($path), 'Credential provisioning must create the external JSON object');
    $json = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $expect(
        $json['version'] === 1
        && $json['source_key'] === 'domain-a'
        && $json['bind_user'] === 'svc-directory@example.test'
        && $json['password'] === 'not-returned-secret'
        && (int)$json['updated'] > 0,
        'The external JSON object must be complete and bound to its exact source namespace'
    );
    $expect(
        $db->query("SELECT secret_ref FROM ucmp_directory_sources WHERE id=1")->fetchColumn() === 'file:domain-a',
        'Only the opaque reference may remain in the directory-source row'
    );

    $source = $db->query('SELECT * FROM ucmp_directory_sources WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $healthy = $service->sourceRuntimeStatus($source);
    $expect(
        $healthy['secret_configured'] === true
        && $healthy['secret_error'] === ''
        && $healthy['credential_updated'] === $json['updated'],
        'A valid external object must project as configured without exposing its contents'
    );

    file_put_contents($path, '{broken-json', LOCK_EX);
    $corrupt = $service->sourceRuntimeStatus($source);
    $expect(
        $corrupt['secret_configured'] === false && $corrupt['secret_error'] !== '',
        'A corrupt external credential object must fail closed'
    );

    file_put_contents($path, json_encode([
        'version'=>1,
        'source_key'=>'other-domain',
        'bind_user'=>'svc-directory@example.test',
        'password'=>'not-returned-secret',
        'updated'=>time(),
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    $mismatched = $service->sourceRuntimeStatus($source);
    $expect(
        $mismatched['secret_configured'] === false
        && str_contains($mismatched['secret_error'], 'invalid'),
        'A credential object for another source namespace must fail closed'
    );

    $wrongReference = $db->query('SELECT * FROM ucmp_directory_sources WHERE id=3')->fetch(PDO::FETCH_ASSOC);
    $wrongReferenceStatus = $service->sourceRuntimeStatus($wrongReference);
    $expect(
        $wrongReferenceStatus['secret_configured'] === false
        && str_contains($wrongReferenceStatus['secret_error'], 'does not match'),
        'A mismatched opaque reference must fail closed before reading an object'
    );

    $throws(
        fn() => $service->saveSourceSecret(2, [
            'bind_user'=>'legacy@example.test',
            'password'=>'must-not-be-written',
        ]),
        'The legacy-default compatibility credential must reject external-object provisioning'
    );
    $expect(
        !is_file($root . DIRECTORY_SEPARATOR . 'legacy-default.json'),
        'Rejecting legacy-default must not write an external credential object'
    );
} finally {
    if (is_file($root . DIRECTORY_SEPARATOR . 'domain-a.json')) {
        unlink($root . DIRECTORY_SEPARATOR . 'domain-a.json');
    }
    if (is_dir($root)) { rmdir($root); }
}

echo "Directory external secret assertions passed\n";
