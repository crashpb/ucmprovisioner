<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/LogicalExtensionService.php';
require_once __DIR__ . '/../lib/DirectoryService.php';

use FreePBX\modules\Ucmprovisioner\DirectoryService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ucmp_directory_sources (id INTEGER PRIMARY KEY, domain TEXT)');
$db->exec(
    'CREATE TABLE ucmp_directory_entries (
        id INTEGER PRIMARY KEY, source_id INTEGER, object_key TEXT, source_revision TEXT,
        display_name TEXT, label TEXT, extension TEXT, department TEXT, company TEXT,
        org_unit TEXT, mail TEXT, mobile TEXT, site_id INTEGER, conflicted INTEGER
    )'
);
$db->exec(
    "CREATE TABLE ucmp_extensions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, extension TEXT NOT NULL,
        ad_source_id INTEGER, ad_object_key TEXT, ad_source_revision TEXT, group_id INTEGER,
        canonical_identity_hash TEXT, identity_adoption TEXT NOT NULL DEFAULT 'legacy_review',
        pbx_presence INTEGER NOT NULL DEFAULT 0, pbx_last_confirmed INTEGER NOT NULL DEFAULT 0,
        desired_identity_hash TEXT, applied_identity_hash TEXT,
        apply_status TEXT NOT NULL DEFAULT 'unbound', apply_error TEXT,
        revision INTEGER NOT NULL DEFAULT 1, updated INTEGER NOT NULL DEFAULT 0,
        UNIQUE(site_id,extension), UNIQUE(ad_source_id,ad_object_key)
    )"
);
$db->exec("INSERT INTO ucmp_directory_sources (id,domain) VALUES (7,'example.test')");
$db->exec(
    "INSERT INTO ucmp_directory_entries
        (id,source_id,object_key,source_revision,display_name,label,extension,department,
         company,org_unit,mail,mobile,site_id,conflicted)
     VALUES
        (1,7,'guid-4310','rev-20260727','Akbar Kheyri','a.kheyri','4310','Sales',
         'Example','Tehran','a.kheyri@example.test','09120000000',1,0)"
);
$db->exec("INSERT INTO ucmp_extensions (site_id,extension) VALUES (1,'4310')");

$ucm = new class($db) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
};

$result = (new DirectoryService($ucm))->backfillAcceptedIdentityBindings();
$row = $db->query("SELECT * FROM ucmp_extensions WHERE site_id=1 AND extension='4310'")
    ->fetch(PDO::FETCH_ASSOC);
$expect($result['examined'] === 1 && $result['bound'] === 1 && $result['failed'] === 0, 'Accepted legacy identity must be backfilled');
$expect((int)$row['ad_source_id'] === 7 && $row['ad_object_key'] === 'guid-4310', 'Logical extension must bind to its accepted AD object');
$expect($row['ad_source_revision'] === 'rev-20260727' && $row['apply_status'] === 'pending', 'Backfill must preserve source revision and stage identity application');

$again = (new DirectoryService($ucm))->backfillAcceptedIdentityBindings();
$expect($again['examined'] === 0 && $again['bound'] === 0, 'Identity backfill must be idempotent');

echo "Directory identity backfill fixtures passed\n";
