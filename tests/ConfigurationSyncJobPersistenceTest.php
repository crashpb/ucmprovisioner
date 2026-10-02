<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/ConfigurationSyncService.php';

use FreePBX\modules\Ucmprovisioner\ConfigurationSyncService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

final class ConfigurationSyncJobPersistenceFake
{
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['site_key'=>'controller-a']; }
    public function getSites(): array
    {
        return [
            ['id'=>1,'name'=>'SITE-A-PBX','enabled'=>1],
            ['id'=>2,'name'=>'SITE-B-PBX','enabled'=>1],
            ['id'=>3,'name'=>'SITE-C-PBX','enabled'=>1],
        ];
    }

    public function configurationSyncRelease(int $targetSiteId, string $jobKey): array
    {
        return ['ok'=>true, 'message'=>'released'];
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ConfigurationSyncService::ensureSchema($db);
$service = new ConfigurationSyncService($db, new ConfigurationSyncJobPersistenceFake(), sys_get_temp_dir() . '/ucmp-job-persistence-' . bin2hex(random_bytes(5)));
$base = [
    'name'=>'Settings push from SITE-A-PBX',
    'source_site_id'=>1,
    'target_site_ids'=>[2],
    'scopes'=>['settings_clock_date'],
    'settings_categories'=>['settings_clock_date'],
    'enabled'=>1,
    'soft_lock'=>1,
    'retry_unreachable'=>1,
    'schedule_mode'=>'interval',
    'interval_minutes'=>60,
];

$first = $service->save($base);
$firstId = (int)($first['job']['id'] ?? 0);
$expect($firstId > 0, 'Initial Settings job must be created');

// A refreshed Settings form may submit id=0; the source-scoped generated name
// must resolve back to the original job rather than fail on the unique key.
$repeat = $service->save(array_replace($base, ['target_site_ids'=>[3]]));
$expect((int)($repeat['job']['id'] ?? 0) === $firstId, 'Repeated source-scoped save must reuse the existing job');
$expect((int)$db->query('SELECT COUNT(*) FROM ucmp_configuration_sync_jobs')->fetchColumn() === 1, 'Repeated save must not create a duplicate job');
$expect(json_decode((string)$db->query('SELECT targets_json FROM ucmp_configuration_sync_jobs WHERE id=' . $firstId)->fetchColumn(), true) === [3], 'Repeated save must update the intended job definition');

// The same name remains valid for another source site; do not cross-update it.
$otherSource = $service->save(array_replace($base, ['source_site_id'=>2, 'target_site_ids'=>[1]]));
$expect((int)($otherSource['job']['id'] ?? 0) !== $firstId, 'Same name from another source site must remain independent');
$expect((int)$db->query('SELECT COUNT(*) FROM ucmp_configuration_sync_jobs')->fetchColumn() === 2, 'Different source sites may own same-named jobs');

// Reusing a generated name is safe only within the same editor family. A new
// Settings form must never silently convert an unrelated template/group job.
$templateJob = $service->save(array_replace($base, [
    'name'=>'Shared operator name',
    'scopes'=>['templates'],
    'settings_categories'=>[],
    'template_ids'=>[7],
]));
try {
    $service->save(array_replace($base, ['name'=>'Shared operator name']));
    $expect(false, 'A fresh form must not reuse a same-named job from another synchronization editor');
} catch (InvalidArgumentException $e) {
    $expect(str_contains($e->getMessage(), 'different kind'), 'Cross-editor name collision must explain why the existing job was not reused');
}
$expect((int)($templateJob['job']['id'] ?? 0) > 0, 'Unrelated collision fixture must create its template job');

// An explicit edit that collides with another job must be rejected clearly,
// never overwrite the other source-scoped row or leak a raw SQL exception.
try {
    $service->save(array_replace($base, ['id'=>(int)($otherSource['job']['id'] ?? 0), 'source_site_id'=>1]));
    $expect(false, 'Explicit conflicting job edit must be rejected');
} catch (InvalidArgumentException $e) {
    $expect(str_contains($e->getMessage(), 'already exists for the selected source site'), 'Conflict must explain how to resolve the duplicate name');
}

echo "Configuration sync job persistence assertions passed\n";
