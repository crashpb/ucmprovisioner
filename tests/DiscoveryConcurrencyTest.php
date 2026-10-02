<?php
declare(strict_types=1);
require_once __DIR__ . '/LogicalExtensionPersistenceTest.php';
require_once __DIR__ . '/ConfigurationWriteLockTest.php';

use FreePBX\modules\Ucmprovisioner\{Discovery,RpcOperationException};

foreach (['label TEXT','vendor TEXT','model_id INTEGER','family TEXT','mac TEXT','ip TEXT','status TEXT','driver TEXT','transport TEXT','sip_port INTEGER'] as $column) {
    $db->exec('ALTER TABLE ucmp_phones ADD COLUMN ' . $column);
}
$db->exec('CREATE TABLE ucmp_phone_mac_suppressions (site_id INTEGER,mac TEXT)');
$schema = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name LIKE 'ucmp_%'")->fetchAll(PDO::FETCH_COLUMN);
final class DiscoveryCommitPdo extends PDO {
    public bool $loseCommit = false;
    public function commit(): bool { $result = parent::commit(); return $this->loseCommit ? false : $result; }
}
$make = static function () use ($schema): array {
    $database = new DiscoveryCommitPdo('sqlite::memory:');
    $database->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    foreach ($schema as $sql) { $database->exec($sql); }
    $module = new class($database) {
        private ConfigurationLockModule $lock;
        public function __construct(private PDO $db) { $this->lock = new ConfigurationLockModule(); }
        public function getDb(): PDO { return $this->db; }
        public function withConfigurationSyncLock(callable $operation): mixed { return $this->lock->withConfigurationSyncLock($operation); }
    };
    return [$database, new Discovery($module)];
};
$write = new ReflectionMethod(Discovery::class,'upsertPhone');
$safeWrite = new ReflectionMethod(Discovery::class,'upsertPhoneSafe');
$read = static fn(PDO $database): array => $database->query('SELECT * FROM ucmp_phones WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
foreach (['reassignment','deletion','creation','custom_label','logical_failure','uncertain_commit','suppression'] as $scenario) {
    [$database,$discovery] = $make();
    $database->exec("INSERT INTO ucmp_phones(id,site_id,extension,label,mac,updated,last_applied) VALUES(1,10,'900','Observed label','001122334455',1000,1000)");
    $cached = $read($database);
    if ($scenario === 'reassignment') { $database->exec("UPDATE ucmp_phones SET extension='901',label='Operator label' WHERE id=1"); }
    if ($scenario === 'deletion') { $database->exec('DELETE FROM ucmp_phones'); }
    if ($scenario === 'creation') { $cached = null; }
    if ($scenario === 'custom_label') { $database->exec("UPDATE ucmp_phones SET label='Operator label' WHERE id=1"); }
    if ($scenario === 'logical_failure') { $database->exec("CREATE TRIGGER fail_link BEFORE INSERT ON ucmp_extensions BEGIN SELECT RAISE(ABORT,'fixture logical failure'); END"); }
    if ($scenario === 'uncertain_commit') { $database->loseCommit = true; }
    if ($scenario === 'suppression') {
        $database->exec('DELETE FROM ucmp_phones'); $cached = null;
        $database->exec("INSERT INTO ucmp_phone_mac_suppressions VALUES(10,'001122334455')");
    }
    $before = $read($database); $failure = null;
    try {
        if ($scenario === 'uncertain_commit') {
            $conflicts = [];
            $safeWrite->invokeArgs($discovery,[10,'900','Observed label',$cached,'Cisco',null,'001122334455','192.0.2.10','sip',&$conflicts]);
        } else {
            $write->invoke($discovery,10,'900','Observed label',$cached,'Cisco',null,'001122334455','192.0.2.10','sip');
        }
    } catch (Throwable $error) { $failure = $error; }
    $after = $read($database);
    if (in_array($scenario,['reassignment','deletion','creation','logical_failure'],true)) {
        $expect($failure !== null && $after === $before,'Stale discovery or failed logical write changed physical state: ' . $scenario);
        $expect((int)$database->query('SELECT COUNT(*) FROM ucmp_extensions')->fetchColumn() === 0,'Rejected observation changed logical state');
    } elseif ($scenario === 'custom_label') {
        $expect($failure === null && $after['label'] === 'Operator label' && $after['ip'] === '192.0.2.10','Fresh label was overwritten or valid observation lost');
        $expect($after['updated'] > $after['last_applied'],'Configuration discovery change is not pending');
        $write->invoke($discovery,10,'900','Observed label',$after,'Cisco',null,'001122334455','192.0.2.11','sip');
        $expect($read($database)['updated'] === $after['updated'],'IP-only scan incorrectly dirtied configuration');
    } elseif ($scenario === 'uncertain_commit') {
        $expect($failure instanceof RpcOperationException && $failure->receipt['recovery_required'] && $after['vendor'] === 'Cisco','Lost discovery commit was swallowed or replayed');
    } else {
        $expect($failure === null && empty($after['mac']) && empty($after['vendor']),'Fresh suppression did not prevent resurrecting deleted hardware');
    }
    $expect(!$database->inTransaction(),'Discovery left an active database transaction');
}
echo "Discovery stale binding, fresh label, atomicity, suppression and uncertain commit passed\n";
