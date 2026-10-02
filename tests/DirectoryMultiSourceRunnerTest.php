<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/DirectoryService.php';

use FreePBX\modules\Ucmprovisioner\DirectoryService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ucmp_directory_sources (
        id INTEGER PRIMARY KEY,
        source_key TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 0,
        schedule_minutes INTEGER NOT NULL DEFAULT 0,
        last_attempt INTEGER NOT NULL DEFAULT 0,
        last_success INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        updated INTEGER NOT NULL DEFAULT 0
    )'
);
$db->exec(
    'CREATE TABLE ucmp_directory_sync_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_id INTEGER NOT NULL,
        started INTEGER NOT NULL DEFAULT 0,
        finished INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL,
        error TEXT
    )'
);

$now = time();
$insert = $db->prepare(
    'INSERT INTO ucmp_directory_sources
        (id,source_key,enabled,schedule_minutes,last_attempt,last_success,last_error)
     VALUES (?,?,?,?,?,?,?)'
);
$insert->execute([1, 'legacy-default', 1, 15, 0, 0, '']);
$insert->execute([2, 'domain-due', 1, 15, 0, 0, '']);
$insert->execute([3, 'domain-backoff', 1, 15, $now - 30, $now - 3600, 'DC unavailable']);
$insert->execute([4, 'domain-manual', 1, 0, 0, 0, '']);
$insert->execute([5, 'domain-disabled', 0, 15, 0, 0, '']);
$db->prepare(
    "INSERT INTO ucmp_directory_sync_runs (source_id,started,finished,status,error)
     VALUES (?, ?, ?, 'failed', ?)"
)->execute([3, $now - 30, $now - 20, 'DC unavailable']);

$ucm = new class($db) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
};

$service = new class($ucm) extends DirectoryService {
    public array $acceptedIds = [];
    public array $failIds = [];

    protected function acceptSourceRevision(array $source): array
    {
        $id = (int)$source['id'];
        $this->acceptedIds[] = $id;
        if (in_array($id, $this->failIds, true)) {
            return [
                'ok'=>false, 'accepted'=>false, 'status'=>'failed',
                'source_id'=>$id, 'source_key'=>(string)$source['source_key'],
                'message'=>'fixture failure',
            ];
        }
        return [
            'ok'=>true, 'accepted'=>true, 'status'=>'accepted',
            'source_id'=>$id, 'source_key'=>(string)$source['source_key'],
            'message'=>'fixture accepted',
        ];
    }
};

$automatic = $service->sync(false);
$expect(
    $service->acceptedIds === [1, 2],
    'Automatic execution must run every due enabled source, including legacy-default'
);
$expect(
    array_column(array_filter($automatic['sources'], static fn(array $row): bool => !empty($row['skipped'])), 'source_key')
        === ['domain-backoff', 'domain-manual'],
    'Automatic execution must skip a source in failure backoff and a manual-only source'
);
$expect(
    !in_array('domain-disabled', array_column($automatic['sources'], 'source_key'), true)
    && $automatic['accepted_sources'] === 2
    && $automatic['skipped_sources'] === 2,
    'Disabled sources must not enter an automatic run'
);

$service->acceptedIds = [];
$targetedAutomatic = $service->sync(false, 4);
$expect(
    $service->acceptedIds === []
    && $targetedAutomatic['status'] === 'idle'
    && $targetedAutomatic['skipped_sources'] === 1,
    'A targeted automatic run must still honor a manual-only schedule'
);

$service->acceptedIds = [];
$targetedManual = $service->sync(true, 4);
$expect(
    $service->acceptedIds === [4]
    && $targetedManual['status'] === 'accepted'
    && $targetedManual['accepted_sources'] === 1,
    'A targeted manual run must bypass schedule cadence for that source only'
);

$service->acceptedIds = [];
$service->failIds = [2];
$manualAll = $service->sync(true);
$expect(
    $service->acceptedIds === [1, 2, 3, 4]
    && !in_array(5, $service->acceptedIds, true),
    'Manual all must run every enabled source, bypassing cadence and backoff without running disabled sources'
);
$expect(
    $manualAll['ok'] === false
    && $manualAll['partial'] === true
    && $manualAll['status'] === 'partial'
    && $manualAll['accepted_sources'] === 3
    && $manualAll['failed_sources'] === 1,
    'Mixed accepted and failed source revisions must return an explicit partial result'
);

$service->acceptedIds = [];
$targetedDisabled = $service->sync(true, 5);
$expect(
    $service->acceptedIds === []
    && $targetedDisabled['status'] === 'idle'
    && $targetedDisabled['skipped_sources'] === 1,
    'A targeted manual request must not silently enable a disabled non-legacy source'
);

echo "Directory multi-source runner assertions passed\n";
