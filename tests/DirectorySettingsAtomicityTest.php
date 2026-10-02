<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/DirectoryService.php';

use FreePBX\modules\Ucmprovisioner\DirectoryService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ucmp_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        skey TEXT NOT NULL,
        sval TEXT NOT NULL,
        UNIQUE (site_id, skey)
    )'
);
$db->exec(
    'CREATE TABLE ucmp_directory_sources (
        id INTEGER PRIMARY KEY,
        source_key TEXT NOT NULL,
        domain TEXT NOT NULL,
        enabled INTEGER NOT NULL,
        endpoints_json TEXT NOT NULL,
        tls_mode TEXT NOT NULL,
        secret_ref TEXT,
        base_dn TEXT NOT NULL,
        filter TEXT NOT NULL,
        attribute_map_json TEXT NOT NULL,
        site_rules_json TEXT NOT NULL,
        schedule_minutes INTEGER NOT NULL,
        schedule_json TEXT,
        updated INTEGER NOT NULL
    )'
);
$insertSetting = $db->prepare('INSERT INTO ucmp_settings (site_id,skey,sval) VALUES (1,?,?)');
foreach ([
    'ad_host'=>'dc-old.example.com',
    'ad_port'=>'636',
    'ad_security'=>'ldaps',
    'ad_base_dn'=>'DC=old,DC=example,DC=com',
] as $key => $value) {
    $insertSetting->execute([$key, $value]);
}
$db->exec(
    "INSERT INTO ucmp_directory_sources
        (id,source_key,domain,enabled,endpoints_json,tls_mode,secret_ref,base_dn,filter,
         attribute_map_json,site_rules_json,schedule_minutes,updated)
     VALUES
        (1,'legacy-default','old.example.com',0,'[]','ldaps','legacy-setting:ad_bind_password','DC=old,DC=example,DC=com',
         '(objectClass=user)','{}','[]',15,1)"
);

$ucm = new class($db) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function getSiteSettingsLocal(int $siteId): array
    {
        $statement = $this->db->prepare('SELECT skey,sval FROM ucmp_settings WHERE site_id=?');
        $statement->execute([$siteId]);
        return $statement->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
    public function setSetting(string $key, string $value, int $siteId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO ucmp_settings (site_id,skey,sval) VALUES (?,?,?)
             ON CONFLICT (site_id,skey) DO UPDATE SET sval=excluded.sval'
        );
        $statement->execute([$siteId, $key, $value]);
    }
    public function enc(string $value): string { return 'encrypted:' . $value; }
    public function dec(string $value): string { return $value; }
};
$service = new DirectoryService($ucm);
$readSettings = static function () use ($db): array {
    $rows = $db->query('SELECT skey,sval FROM ucmp_settings WHERE site_id=1 ORDER BY skey')
        ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    return $rows;
};
$readSource = static fn(): array => $db->query(
    "SELECT domain,enabled,endpoints_json,tls_mode,base_dn,filter,
            attribute_map_json,site_rules_json,schedule_minutes,updated
       FROM ucmp_directory_sources WHERE source_key='legacy-default'"
)->fetch(PDO::FETCH_ASSOC);

$beforeInvalid = $readSettings();
try {
    $service->save(['directory'=>[
        'ad_host'=>'dc-should-not-stick.example.com',
        'ad_port'=>'70000',
    ]]);
    throw new RuntimeException('Invalid LDAP port was accepted');
} catch (InvalidArgumentException $expected) {
}
$expect($readSettings() === $beforeInvalid, 'A later validation error must not leave an earlier setting changed');

$beforeFailureSettings = $readSettings();
$beforeFailureSource = $readSource();
$db->exec(
    "CREATE TRIGGER reject_legacy_source_update
     BEFORE UPDATE ON ucmp_directory_sources
     BEGIN SELECT RAISE(ABORT, 'forced mirror failure'); END"
);
try {
    $service->save(['directory'=>[
        'ad_host'=>'dc-new.example.com',
        'ad_base_dn'=>'DC=new,DC=example,DC=com',
        'ad_bind_password'=>'new-secret',
    ]]);
    throw new RuntimeException('Forced mirrored-source failure was accepted');
} catch (PDOException $expected) {
}
$expect($readSettings() === $beforeFailureSettings, 'Mirrored-source failure must roll back every legacy setting');
$expect($readSource() === $beforeFailureSource, 'Mirrored-source failure must leave the source row unchanged');

$db->exec('DROP TRIGGER reject_legacy_source_update');
$service->save(['directory'=>[
    'ad_host'=>'dc-new.example.com',
    'ad_base_dn'=>'DC=new,DC=example,DC=com',
    'ad_enabled'=>'1',
    'ad_bind_password'=>'new-secret',
]]);
$after = $readSettings();
$source = $readSource();
$expect(
    ($after['ad_host'] ?? '') === 'dc-new.example.com'
    && ($after['ad_bind_password'] ?? '') === 'encrypted:new-secret',
    'A valid save must commit normalized settings and the encrypted password'
);
$expect(
    $source['domain'] === 'new.example.com'
    && (int)$source['enabled'] === 1
    && json_decode($source['endpoints_json'], true)[0]['host'] === 'dc-new.example.com',
    'A valid save must commit the matching legacy-default source projection'
);

echo "Directory settings atomicity fixtures passed\n";
