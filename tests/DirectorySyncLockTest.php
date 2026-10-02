<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/DirectoryService.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($source, 'SELECT GET_LOCK(?,0)')
    && str_contains($source, 'SELECT RELEASE_LOCK(?)')
    && str_contains($source, "'-runner'")
    && str_contains($source, "'-source-' . (int)\$source['id']")
    && str_contains($source, 'Another authoritative AD sync is already running'),
    'Cron and manual AD runs must use a global runner lock plus per-source locks'
);

$accept = strpos($source, 'protected function acceptSourceRevision');
$state = strpos($source, 'SET last_accepted_revision=?', $accept);
$acceptedRun = strpos($source, "status='accepted'", $state);
$commit = strpos($source, '$db->commit()', $acceptedRun);
$delivery = strpos($source, '$this->applyIdentities(', $commit);
$expect(
    $accept !== false
    && $state !== false
    && $acceptedRun !== false
    && $commit !== false
    && $delivery !== false
    && $accept < $state
    && $state < $acceptedRun
    && $acceptedRun < $commit
    && $commit < $delivery,
    'A complete source revision and its accepted run marker must commit atomically before delivery'
);

// Execute the production commit/catch boundary against a real database whose
// commit succeeds but acknowledgement is lost. No LDAP or PBX action is involved.
$begin = strpos($source,'$acceptCommitAttempted = true;', $acceptedRun);
$end = strpos($source,'$warnings = [];',$begin);
$boundary = substr($source,$begin,$end-$begin);
$complete = eval('return static function(PDO $db): array { $sourceId=1;$sourceKey="fixture";$runId=1;$sourceRevision="candidate";$source=[];$acceptCommitAttempted=false;try {' . $boundary . 'return ["ok"=>true]; };');
foreach (['false','throw'] as $fault) {
    $db = new class extends PDO {
        public string $fault = '';
        public function __construct() { parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
        public function commit(): bool { parent::commit(); if ($this->fault==='throw') { throw new RuntimeException('Fixture lost acceptance reply'); } return false; }
    };
    $db->fault=$fault;
    $db->exec('CREATE TABLE ucmp_directory_sync_runs (id INTEGER PRIMARY KEY,status TEXT)');
    $db->exec("INSERT INTO ucmp_directory_sync_runs VALUES (1,'running')");
    $db->beginTransaction();$db->exec("UPDATE ucmp_directory_sync_runs SET status='accepted' WHERE id=1");
    $result=$complete($db);
    $expect($result['status']==='unknown' && $result['accepted']===null && $db->query('SELECT status FROM ucmp_directory_sync_runs')->fetchColumn()==='accepted',
        'Lost acceptance acknowledgement must preserve the accepted record and report uncertainty without delivery');
}
echo "Directory sync lock and lost-commit acceptance assertions passed\n";
