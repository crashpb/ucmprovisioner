<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/DirectoryService.php';

use FreePBX\modules\Ucmprovisioner\DirectoryService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ucmp_directory_sources (id INTEGER PRIMARY KEY, source_key TEXT NOT NULL)');
$db->exec(
    'CREATE TABLE ucmp_directory_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_key TEXT, source_id INTEGER, object_key TEXT, source_revision TEXT,
        last_seen_at INTEGER, display_name TEXT, label TEXT, extension TEXT,
        department TEXT, company TEXT, org_unit TEXT, mail TEXT, mobile TEXT,
        attributes TEXT, source_dn TEXT, site_id INTEGER, conflicted INTEGER,
        applied_hash TEXT, updated INTEGER
    )'
);
$db->exec('CREATE TABLE ucmp_sites (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec(
    'CREATE TABLE ucmp_extensions (
        id INTEGER PRIMARY KEY, ad_source_id INTEGER, ad_object_key TEXT,
        revision INTEGER, desired_identity_hash TEXT, applied_identity_hash TEXT,
        apply_status TEXT
    )'
);
$db->exec(
    'CREATE TABLE ucmp_directory_conflicts (
        id INTEGER PRIMARY KEY, conflict_type TEXT, candidates TEXT,
        resolution_key TEXT, status TEXT, updated INTEGER
    )'
);
$db->exec("INSERT INTO ucmp_directory_sources VALUES (1,'domain-a'),(2,'domain-b')");
$db->exec("INSERT INTO ucmp_sites VALUES (10,'Tehran')");
$sameObjectKey = '00112233445566778899aabbccddeeff';
$insert = $db->prepare(
    'INSERT INTO ucmp_directory_entries
        (source_key,source_id,object_key,display_name,label,extension,department,
         attributes,source_dn,site_id,conflicted,updated)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
);
$insert->execute([$sameObjectKey,1,$sameObjectKey,'Old Domain A Name','old.a','300','IT','{}','CN=Old,DC=a',10,0,1]);
$insert->execute([$sameObjectKey,1,$sameObjectKey,'Alice Domain A','alice.a','301','IT','{}','CN=Alice,DC=a',10,0,2]);
$insert->execute([$sameObjectKey,2,$sameObjectKey,'Bob Domain B','bob.b','302','IT','{}','CN=Bob,DC=b',10,0,2]);
$db->exec(
    "INSERT INTO ucmp_extensions
        (id,ad_source_id,ad_object_key,revision,desired_identity_hash,applied_identity_hash,apply_status)
     VALUES (101,1,'$sameObjectKey',1,'','','applied'),
            (102,2,'$sameObjectKey',1,'','','applied')"
);

$ucm = new class($db) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
};
$service = new DirectoryService($ucm);
$entries = $service->entries();
$expect(count($entries) === 2, 'Latest reads must retain one row per source_id and object_key');
$expect(
    array_column($entries, 'display_name') === ['Alice Domain A', 'Bob Domain B'],
    'The newest row for each domain object must be selected independently'
);
$publicKeys = array_column($entries, 'source_key');
$expect(
    count(array_unique($publicKeys)) === 2
    && !array_filter($publicKeys, static fn(string $key): bool => preg_match('/^[a-f0-9]{64}$/D', $key) !== 1),
    'Equal GUID strings from different directory sources need distinct 64-hex namespaced public keys'
);
$expect(
    array_column($entries, 'object_key') === [$sameObjectKey, $sameObjectKey],
    'Namespacing the public key must not alter the database object key'
);
$expect(
    array_column($entries, 'extension_id') === [101, 102],
    'Logical extension joins must remain scoped by source ID and object key'
);

$compile = new ReflectionMethod(DirectoryService::class, 'compilePhonebook');
$compile->setAccessible(true);
$compiled = $compile->invoke($service, [
    'book_key'=>'company','name'=>'Company','filename'=>'company.xml','title'=>'Company',
    'include_mode'=>'all','rules'=>[],'exclusions'=>[],'manual_entries'=>[],
    'group_by'=>'none','hierarchy'=>['version'=>2,'children'=>[]],
    'publish_sites'=>['*'],'name_format'=>'display_label','sort_by'=>'display_name',
    'include_mobile'=>1,'cisco_page_size'=>32,'is_default'=>1,
], $entries);
$expect(count($compiled['contacts']) === 2, 'Namespaced keys must prevent cross-domain loss in phonebook contact maps');

$legacyExcluded = $compile->invoke($service, [
    'book_key'=>'company','name'=>'Company','filename'=>'company.xml','title'=>'Company',
    'include_mode'=>'all','rules'=>[],'exclusions'=>[$sameObjectKey],'manual_entries'=>[],
    'group_by'=>'none','hierarchy'=>['version'=>2,'children'=>[]],
    'publish_sites'=>['*'],'name_format'=>'display_label','sort_by'=>'display_name',
    'include_mobile'=>1,'cisco_page_size'=>32,'is_default'=>1,
], $entries);
$expect(
    count($legacyExcluded['contacts']) === 0,
    'A pre-migration raw object-key exclusion must remain effective after namespacing'
);

$db->prepare(
    "INSERT INTO ucmp_directory_conflicts
        (id,conflict_type,candidates,resolution_key,status,updated)
     VALUES (1,'duplicate_extension',?,NULL,'open',0)"
)->execute([json_encode([
    ['source_key'=>'candidate-a','label'=>'Alice'],
    ['source_key'=>'candidate-b','label'=>'Bob'],
])]);
$writebackRejected = false;
try {
    $service->resolve([
        'conflict_id'=>1,
        'mode'=>'writeback',
        'changes'=>json_encode(['candidate-a'=>'401']),
        'ad_admin_user'=>'should-not-be-used',
        'ad_admin_pass'=>'should-not-be-used',
    ]);
} catch (InvalidArgumentException $e) {
    $writebackRejected = str_contains($e->getMessage(), 'writeback is disabled');
}
$expect($writebackRejected, 'Conflict resolution must fail closed for AD writeback');
$expect(
    $db->query('SELECT status FROM ucmp_directory_conflicts WHERE id=1')->fetchColumn() === 'open',
    'Rejected AD writeback must not mutate conflict state'
);

echo "Multi-domain directory identity fixtures passed\n";
