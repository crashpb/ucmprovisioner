<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/DirectoryService.php';

use FreePBX\modules\Ucmprovisioner\DirectoryService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$throws = static function (callable $call, string $contains, string $message): void {
    try {
        $call();
    } catch (Throwable $e) {
        if ($contains === '' || str_contains($e->getMessage(), $contains)) { return; }
        throw new RuntimeException($message . ': ' . $e->getMessage());
    }
    throw new RuntimeException($message);
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ucmp-legacy-migration-' . bin2hex(random_bytes(8));
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ucmp_directory_sources (
        id INTEGER PRIMARY KEY,
        source_key TEXT NOT NULL,
        domain TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 0,
        endpoints_json TEXT NOT NULL,
        tls_mode TEXT NOT NULL,
        secret_ref TEXT,
        base_dn TEXT NOT NULL,
        filter TEXT NOT NULL,
        attribute_map_json TEXT NOT NULL,
        site_rules_json TEXT NOT NULL,
        schedule_minutes INTEGER NOT NULL DEFAULT 0,
        schedule_json TEXT,
        last_accepted_revision TEXT,
        last_attempt INTEGER NOT NULL DEFAULT 0,
        last_success INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        created INTEGER NOT NULL DEFAULT 0,
        updated INTEGER NOT NULL DEFAULT 0
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
    'CREATE TABLE ucmp_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        skey TEXT NOT NULL,
        sval TEXT
    )'
);
$insert = $db->prepare(
    'INSERT INTO ucmp_directory_sources
        (id,source_key,domain,enabled,endpoints_json,tls_mode,secret_ref,base_dn,filter,
         attribute_map_json,site_rules_json,schedule_minutes,last_accepted_revision,
         last_attempt,last_success,last_error,created,updated)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$insert->execute([
    7,
    'legacy-default',
    'example.test',
    1,
    '[{"host":"dc01.example.test","port":636,"priority":10}]',
    'ldap',
    'legacy-setting:ad_bind_password',
    'DC=example,DC=test',
    '(&(objectCategory=person)(objectClass=user))',
    '{"display_name":"displayName","extension":"telephoneNumber","label":"sAMAccountName","department":"department","company":"company","mail":"mail","mobile":"mobile"}',
    '[]',
    15,
    'accepted-revision-42',
    100,
    99,
    '',
    10,
    20,
]);
$settings = $db->prepare('INSERT INTO ucmp_settings (site_id,skey,sval) VALUES (1,?,?)');
foreach ([
    'ad_bind_user'=>'svc-directory@example.test',
    'ad_bind_password'=>'ENC:legacy-password',
    'ad_security'=>'ldaps',
] as $key=>$value) {
    $settings->execute([$key,$value]);
}

$ucm = new class($db, $root) {
    public function __construct(private PDO $db, private string $root) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function directorySecretRoot(): string { return $this->root; }
    public function getSiteSettingsLocal(int $siteId): array
    {
        $stmt = $this->db->prepare('SELECT skey,sval FROM ucmp_settings WHERE site_id=?');
        $stmt->execute([$siteId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
    public function dec(string $value): string { return str_starts_with($value, 'ENC:') ? substr($value, 4) : $value; }
};
$service = new DirectoryService($ucm);
$path = $root . DIRECTORY_SEPARATOR . 'legacy-default.json';

try {
    $before = $db->query(
        'SELECT id,source_key,domain,last_accepted_revision,last_attempt,last_success,created
           FROM ucmp_directory_sources WHERE id=7'
    )->fetch(PDO::FETCH_ASSOC);
    $result = $service->migrateLegacySource(7);
    $expect($db->query('SELECT tls_mode FROM ucmp_directory_sources WHERE id=7')->fetchColumn()==='ldap', 'Storage migration must preserve an existing transport and must not perform a network bind');
    $expect(
        $result['ok'] === true
        && $result['source_id'] === 7
        && $result['source_key'] === 'legacy-default'
        && $result['secret_ref'] === 'file:legacy-default'
        && !array_key_exists('password', $result)
        && !array_key_exists('bind_user', $result)
        && !array_key_exists('path', $result),
        'Migration response must identify the same source without exposing credential material or its path'
    );
    $after = $db->query(
        'SELECT id,source_key,domain,last_accepted_revision,last_attempt,last_success,created,secret_ref
           FROM ucmp_directory_sources WHERE id=7'
    )->fetch(PDO::FETCH_ASSOC);
    foreach (['id','source_key','domain','last_accepted_revision','last_attempt','last_success','created'] as $field) {
        $expect($after[$field] === $before[$field], "In-place migration must preserve source field $field");
    }
    $expect($after['secret_ref'] === 'file:legacy-default', 'In-place migration must switch only to the matching managed reference');
    $expect(
        $db->query("SELECT COUNT(*) FROM ucmp_settings WHERE site_id=1 AND skey='ad_bind_password'")->fetchColumn() === 0,
        'The old encrypted password row must be retired only after the managed object is published'
    );
    $expect(
        $db->query("SELECT sval FROM ucmp_settings WHERE site_id=1 AND skey='ad_bind_user'")->fetchColumn() === 'svc-directory@example.test',
        'Non-secret compatibility settings must remain available for audit and shared policy'
    );
    $secret = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $expect(
        $secret['source_key'] === 'legacy-default'
        && $secret['bind_user'] === 'svc-directory@example.test'
        && $secret['password'] === 'legacy-password',
        'Managed object must contain the decrypted current reader credential under the unchanged source namespace'
    );
    $runtimeSource = $db->query('SELECT * FROM ucmp_directory_sources WHERE id=7')->fetch(PDO::FETCH_ASSOC);
    $runtime = $service->sourceRuntimeStatus($runtimeSource);
    $expect(
        $runtime['secret_configured'] === true && $runtime['secret_error'] === '',
        'Migrated legacy-default must immediately use the normal managed credential validator'
    );

    $rotation = $service->saveSourceSecret(7, [
        'bind_user'=>'svc-rotated@example.test',
        'password'=>'rotated-password',
    ]);
    $expect(
        $rotation['configured'] === true
        && !str_contains(json_encode($rotation, JSON_THROW_ON_ERROR), 'rotated-password'),
        'Migrated legacy-default must support the normal safe credential-rotation workflow'
    );
    $expect($service->migrateLegacySource(7)['ok']===true, 'A completed in-place migration must be an idempotent no-op');
} finally {
    if (is_file($path)) { unlink($path); }
    if (is_dir($root)) { rmdir($root); }
}

$view = (string)file_get_contents(dirname(__DIR__) . '/views/directory.php');
$module = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$expect(
    !str_contains($view, 'value="migratedirectorysource"')
    && str_contains($view, 'upgraded automatically during installation'),
    'Directory UI must not require a separate credential migration'
);
$expect(
    !str_contains($module, "case 'migratedirectorysource'")
    && str_contains((string)file_get_contents(dirname(__DIR__) . '/install.php'), '->upgradeCredentialStorage()'),
    'The module installer, not a page action, must own credential upgrade'
);

echo "Legacy directory source migration assertions passed\n";
