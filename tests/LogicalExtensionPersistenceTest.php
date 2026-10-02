<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/LogicalExtensionService.php';

use FreePBX\modules\Ucmprovisioner\LogicalExtensionService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$throws = static function (callable $callable, string $message): void {
    try {
        $callable();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException($message);
};

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('pdo_sqlite is required for logical persistence fixtures');
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ucmp_groups (id INTEGER PRIMARY KEY,site_id INTEGER NOT NULL,name TEXT)');
$db->exec(
    "CREATE TABLE ucmp_extensions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        extension TEXT NOT NULL,
        ad_source_id INTEGER,
        ad_object_key TEXT,
        ad_source_revision TEXT,
        canonical_identity_hash TEXT,
        identity_adoption TEXT NOT NULL DEFAULT 'legacy_review',
        group_id INTEGER,
        pbx_presence INTEGER NOT NULL DEFAULT 0,
        pbx_last_confirmed INTEGER NOT NULL DEFAULT 0,
        desired_identity_hash TEXT,
        applied_identity_hash TEXT,
        apply_status TEXT NOT NULL DEFAULT 'unbound',
        apply_error TEXT,
        revision INTEGER NOT NULL DEFAULT 1,
        updated INTEGER NOT NULL DEFAULT 0,
        UNIQUE(site_id,extension),
        UNIQUE(ad_source_id,ad_object_key)
    )"
);
$db->exec(
    'CREATE TABLE ucmp_phones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER,
        extension_id INTEGER,
        group_id INTEGER,
        extension TEXT,
        last_applied INTEGER NOT NULL DEFAULT 0,
        updated INTEGER NOT NULL DEFAULT 0
    )'
);
$db->exec("INSERT INTO ucmp_groups (id,site_id,name) VALUES (1,10,'Base'),(2,10,'Management'),(3,11,'Wrong site')");
$service = new LogicalExtensionService($db);

$first = $service->synchronizeGroupMembers(10, 1, ['100','101'], ['100','101','102'], 1000);
$expect($first['created'] === 2 && $first['changed_extensions'] === 2, 'Initial membership must create logical rows');
$expect((int)$db->query('SELECT COUNT(*) FROM ucmp_phones')->fetchColumn() === 0, 'Group membership must not create placeholder phones');

$revisionsBefore = $db->query('SELECT extension,revision FROM ucmp_extensions ORDER BY extension')->fetchAll(PDO::FETCH_KEY_PAIR);
$second = $service->synchronizeGroupMembers(10, 1, ['101','100'], ['100','101','102'], 1001);
$revisionsAfter = $db->query('SELECT extension,revision FROM ucmp_extensions ORDER BY extension')->fetchAll(PDO::FETCH_KEY_PAIR);
$expect($second['changed_extensions'] === 0 && $revisionsAfter === $revisionsBefore, 'A no-op group save must not churn logical revisions');

$db->exec("INSERT INTO ucmp_phones (site_id,extension,updated) VALUES (10,'100',0)");
$phone100 = (int)$db->lastInsertId();
$linked100 = $service->linkPhone($phone100, 10, '100', false, 2, 1002);
$phoneRow = $db->query("SELECT extension_id,group_id FROM ucmp_phones WHERE id=$phone100")->fetch(PDO::FETCH_ASSOC);
$expect((int)$phoneRow['extension_id'] === (int)$linked100['id'] && (int)$phoneRow['group_id'] === 1, 'A discovered/replacement phone must inherit the extension group');

$service->synchronizeGroupMembers(10, 2, ['100'], ['100','101','102'], 1003);
$phoneGroup = (int)$db->query("SELECT group_id FROM ucmp_phones WHERE id=$phone100")->fetchColumn();
$expect($phoneGroup === 2, 'Moving a logical extension to another group must update linked hardware');
$service->linkPhone($phone100, 10, '100', false, 1, 1004);
$expect((int)$db->query("SELECT group_id FROM ucmp_phones WHERE id=$phone100")->fetchColumn() === 2, 'A handset swap must preserve destination extension policy unless explicitly changed');
$service->linkPhone($phone100, 10, '100', true, 1, 1005);
$expect((int)$db->query("SELECT group_id FROM ucmp_extensions WHERE extension='100'")->fetchColumn() === 1, 'An explicit operator group change must update the logical extension');

$throws(
    static fn() => $service->synchronizeGroupMembers(10, 3, ['100'], ['100'], 1006),
    'Cross-site group membership must be rejected'
);
$throws(
    static fn() => $service->linkPhone($phone100, 10, '100', true, 3, 1006),
    'Cross-site group assignment must be rejected'
);
$throws(
    static fn() => $service->linkPhone(999, 10, '100', false, null, 1006),
    'A nonexistent phone must be rejected'
);

$db->exec("INSERT INTO ucmp_phones (site_id,extension,updated) VALUES (10,'101',0)");
$phone101 = (int)$db->lastInsertId();
$service->linkPhone($phone101, 10, '101', false, null, 1007);
$expect((int)$db->query("SELECT group_id FROM ucmp_phones WHERE id=$phone101")->fetchColumn() === 1, 'Discovery after group assignment must link to the existing logical policy');
$service->linkPhone($phone101, 10, null, false, null, 1008);
$unassigned = $db->query("SELECT extension_id,group_id FROM ucmp_phones WHERE id=$phone101")->fetch(PDO::FETCH_ASSOC);
$expect($unassigned['extension_id'] === null && $unassigned['group_id'] === null, 'Unassigning hardware must not retain stale logical/group links');

echo "Logical extension persistence fixtures passed\n";
