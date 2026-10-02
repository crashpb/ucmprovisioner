<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use FreePBX\modules\Ucmprovisioner\OperatorWorkflowService;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE ucmp_directory_sources (
    id INTEGER PRIMARY KEY, source_key TEXT NOT NULL, domain TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1, last_success INTEGER NOT NULL DEFAULT 0,
    last_accepted_revision TEXT
)");
$db->exec("INSERT INTO ucmp_directory_sources
    (id,source_key,domain,enabled,last_success,last_accepted_revision)
    VALUES (7,'corp','ad.example.test',1,1700000000,'accepted-1')");
$module = new class($db) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
};
$result = (new OperatorWorkflowService($module))->handle(['op' => 'identity_sources']);
if (!$result['ok'] || $result['sources'][0]['source_key'] !== 'corp' || $result['sources'][0]['domain'] !== 'ad.example.test') {
    throw new RuntimeException('Identity source picker must use source_key/domain, not a nonexistent name column');
}
echo "Operator source picker query passed\n";
