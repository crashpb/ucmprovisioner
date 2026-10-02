<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/MediaReplicationService.php';

use FreePBX\modules\Ucmprovisioner\MediaReplicationService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$rejects = static function (callable $operation, string $message) use ($expect): void {
    try { $operation(); } catch (InvalidArgumentException) { return; }
    $expect(false, $message);
};

final class MediaReplicationFakeModule
{
    public array $tftpCalls = [];
    public array $mediaCalls = [];
    public array $logs = [];
    public bool $throwLog = false;
    public bool $failTftp = false;
    public bool $throwAudioList = false;

    public function getSites(): array
    {
        return [
            ['id'=>1, 'name'=>'SITE-A', 'enabled'=>1],
            ['id'=>2, 'name'=>'SITE-B', 'enabled'=>1],
            ['id'=>3, 'name'=>'SITE-C', 'enabled'=>1],
            ['id'=>4, 'name'=>'Disabled', 'enabled'=>0],
        ];
    }

    public function getModels(): array
    {
        return [
            ['vendor'=>'Cisco', 'family'=>'cisco_cnf', 'model'=>'7940'],
            ['vendor'=>'Cisco', 'family'=>'cisco_xml', 'model'=>'8845'],
            ['vendor'=>'Yealink', 'family'=>'yealink', 'model'=>'T46U'],
            ['vendor'=>'Grandstream', 'family'=>'grandstream', 'model'=>'GHP630'],
        ];
    }

    public function tftpOp(array $request): array
    {
        $this->tftpCalls[] = $request;
        if ($this->failTftp) {
            return ['ok'=>false, 'message'=>'SITE-B is unreachable', 'sites'=>['2'=>['ok'=>false]]];
        }
        return ['ok'=>true, 'message'=>'TFTP media synchronized', 'sites'=>[
            '2'=>['ok'=>true, 'copied'=>2, 'skipped'=>0],
            '3'=>['ok'=>true, 'copied'=>2, 'skipped'=>0],
        ]];
    }

    public function mediaAssetOp(array $request): array
    {
        $this->mediaCalls[] = $request;
        if ($this->throwAudioList && ($request['op'] ?? '') === 'list' && ($request['family'] ?? '') === 'yealink') {
            throw new RuntimeException('Fixture later audio source unavailable');
        }
        $key = (string)($request['family'] ?? '') . '|' . (string)($request['model'] ?? '');
        if (($request['op'] ?? '') === 'list') {
            $items = [
                'cisco_cnf|all'=>[['name'=>'legacy-logo.bmp']],
                'cisco_xml|8845'=>[
                    ['name'=>'cisco-tone.wav', 'media_kind'=>'audio'],
                    ['name'=>'cisco-screen.png', 'media_kind'=>'image'],
                ],
                'yealink|t46u'=>[
                    ['name'=>'yealink-tone.mp3', 'media_kind'=>'audio'],
                    ['name'=>'fleet-wallpaper.png', 'media_kind'=>'image'],
                ],
                'grandstream|ghp630'=>[['name'=>'grandstream-tone.ogg', 'media_kind'=>'audio']],
            ];
            return ['ok'=>true, 'items'=>$items[$key] ?? []];
        }
        return ['ok'=>true, 'message'=>'Hosted media synchronized', 'sites'=>[
            '2'=>['ok'=>true, 'copied'=>1, 'skipped'=>0],
            '3'=>['ok'=>true, 'copied'=>1, 'skipped'=>0],
        ]];
    }

    public function logOperation(?int $siteId, string $operation, string $level, string $message, string $detail = ''): void
    {
        if ($this->throwLog) { throw new RuntimeException('Fixture audit log unavailable'); }
        $this->logs[] = compact('siteId','operation','level','message','detail');
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
MediaReplicationService::ensureSchema($db);
$module = new MediaReplicationFakeModule();
$now = 1000;
$stateDir = sys_get_temp_dir() . '/ucmp-media-replication-' . bin2hex(random_bytes(6));
$service = new MediaReplicationService($db, $module, $stateDir, static function () use (&$now): int { return $now; });

try {
    $saved = $service->save([
        'name'=>'Nightly fleet media',
        'source_site_id'=>1,
        'enabled'=>1,
        'interval_minutes'=>60,
        'scopes'=>['wallpapers','special','ringtones'],
        'target_site_ids'=>[3,2,2],
        'changed_only'=>1,
        'retry_unreachable'=>1,
    ]);
    $id = (int)($saved['job']['id'] ?? 0);
    $expect($id > 0, 'Saving a replication schedule must return its stable ID');
    $expect($saved['job']['target_site_ids'] === [2,3], 'Target IDs must be de-duplicated and stored deterministically');
    $expect($saved['job']['scopes'] === ['ringtones','special','wallpapers'], 'Scopes must be validated and stored deterministically');
    $expect((int)$saved['job']['next_run'] === 4600, 'A new enabled schedule must start one interval after save');

    $rejects(static fn() => $service->save([
        'name'=>'Bad target', 'source_site_id'=>1, 'enabled'=>1, 'interval_minutes'=>60,
        'scopes'=>['wallpapers'], 'target_site_ids'=>[1],
    ]), 'The source site must never be accepted as its own target');
    $rejects(static fn() => $service->save([
        'name'=>'Bad scope', 'source_site_id'=>1, 'enabled'=>1, 'interval_minutes'=>60,
        'scopes'=>['firmware'], 'target_site_ids'=>[2],
    ]), 'Unlisted replication scopes must be rejected server-side');
    $rejects(static fn() => $service->save([
        'name'=>'Too frequent', 'source_site_id'=>1, 'enabled'=>1, 'interval_minutes'=>4,
        'scopes'=>['wallpapers'], 'target_site_ids'=>[2],
    ]), 'Media replication must retain its five-minute lower safety bound');

    $manual = $service->runNow($id);
    $expect(!empty($manual['ok']) && $manual['status'] === 'warning', 'Changed-only preserve-existing mode must report its limitation honestly');
    $expect((int)$db->query("SELECT next_run FROM ucmp_media_replication_jobs WHERE id=$id")->fetchColumn() === 4600, 'Run now must not shift recurring cadence');
    $expect(($module->tftpCalls[0]['scope'] ?? '') === 'all', 'Wallpaper and ringtone scopes should share one existing bounded TFTP sync');
    $expect((int)($module->tftpCalls[0]['overwrite'] ?? -1) === 0, 'Changed-only mode must preserve existing target filenames instead of falsely claiming a hash match');
    $hostedSyncs = array_values(array_filter($module->mediaCalls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync'));
    $expect(count($hostedSyncs) === 5, 'Prepared media and all three non-empty hosted-audio brand libraries must be synchronized');
    $syncNames = [];
    foreach ($hostedSyncs as $call) {
        $syncNames[(string)$call['family'] . '|' . (string)$call['model']][] = (array)($call['names'] ?? []);
    }
    $expect(
        ($syncNames['cisco_cnf|all'] ?? []) === [["legacy-logo.bmp"]]
        && ($syncNames['cisco_xml|8845'] ?? []) === [["cisco-tone.wav"]]
        && ($syncNames['grandstream|ghp630'] ?? []) === [["grandstream-tone.ogg"]]
        && ($syncNames['yealink|t46u'] ?? []) === [["yealink-tone.mp3"], ["fleet-wallpaper.png"]],
        'Recurring replication must keep special media separate while selecting exact Cisco XML, Yealink, and Grandstream audio files'
    );

    $module->tftpCalls = [];
    $module->mediaCalls = [];
    $audioOnly = $service->save([
        'name'=>'Hosted audio only',
        'source_site_id'=>1,
        'enabled'=>1,
        'interval_minutes'=>60,
        'scopes'=>['ringtones'],
        'target_site_ids'=>[2],
        'changed_only'=>1,
        'retry_unreachable'=>1,
    ]);
    $audioId = (int)($audioOnly['job']['id'] ?? 0);
    $audioRun = $service->runNow($audioId);
    $audioSyncs = array_values(array_filter($module->mediaCalls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync'));
    $audioKeys = array_map(static fn(array $call): string => (string)$call['family'] . '|' . (string)$call['model'], $audioSyncs);
    sort($audioKeys, SORT_STRING);
    $expect(
        !empty($audioRun['ok'])
        && ($module->tftpCalls[0]['scope'] ?? '') === 'ringtones'
        && $audioKeys === ['cisco_xml|8845','grandstream|ghp630','yealink|t46u']
        && !array_filter($audioSyncs, static fn(array $call): bool => in_array('fleet-wallpaper.png', (array)($call['names'] ?? []), true)),
        'A ringtones-only recurring job must synchronize hosted audio for every supported brand without requiring the special scope or copying wallpapers'
    );
    $service->delete($audioId);

    $module->throwAudioList = true;
    $partial = $service->runNow($id);
    $expect(empty($partial['ok']) && !empty($partial['operations'][0]['sites'][2]['ok']), 'Later source exception must preserve earlier committed scope receipts');
    $audioResults = $partial['operations'][1]['libraries'];
    $expect(count($audioResults) >= 3 && !empty($audioResults[0]['ok']) && empty(end($audioResults)['ok']), 'Later library exception must retain successful audio libraries and the failed read');
    $stored = json_decode((string)$db->query("SELECT last_result_json FROM ucmp_media_replication_jobs WHERE id=$id")->fetchColumn(),true);
    $expect($stored['operations'] === $partial['operations'], 'Partial results must survive job persistence');
    $module->throwAudioList = false;

    $module->throwLog = true;
    $logRun = $service->runNow($id);
    $logStored = json_decode((string)$db->query("SELECT last_result_json FROM ucmp_media_replication_jobs WHERE id=$id")->fetchColumn(),true);
    $expect(!empty($logRun['operations']) && isset($logRun['reporting_error']) && $logRun['operations'] === $logStored['operations'], 'A post-transfer logging exception must preserve returned and persisted destination outcomes');
    $module->throwLog = false;
    $beforeSchedule = $db->query("SELECT enabled,next_run,last_result_json FROM ucmp_media_replication_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $db->exec("UPDATE ucmp_media_replication_jobs SET enabled=0,next_run=0 WHERE id=$id");
    $staleRun = (new ReflectionMethod(MediaReplicationService::class, 'execute'))->invoke($service, $saved['job'], true);
    $afterSchedule = $db->query("SELECT enabled,next_run,last_result_json FROM ucmp_media_replication_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $expect(($staleRun['status'] ?? '') === 'skipped' && $afterSchedule['last_result_json'] === $beforeSchedule['last_result_json'], 'A job disabled after due selection must not execute stale work or overwrite the prior result');
    $db->prepare("UPDATE ucmp_media_replication_jobs SET enabled=?,next_run=? WHERE id=?")->execute([$beforeSchedule['enabled'],$beforeSchedule['next_run'],$id]);

    $lockPath = $stateDir . '/media-replication-job-' . $id . '.lock';
    $externalLock = fopen($lockPath, 'c');
    flock($externalLock, LOCK_EX | LOCK_NB);
    $busy = $service->runNow($id);
    $expect(!empty($busy['busy']) && !empty($busy['ok']), 'A concurrent run must exit as a non-destructive busy skip');
    flock($externalLock, LOCK_UN);
    // Repeat under the same execution lock: edits and deletion must not race a run.
    flock($externalLock, LOCK_EX | LOCK_NB);
    foreach ([static fn() => $service->save(['id'=>$id] + $saved['job']), static fn() => $service->delete($id)] as $mutation) {
        $rejected = false;
        try { $mutation(); } catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'running'); }
        $expect($rejected, 'Saving or deleting an executing schedule must fail before mutation');
    }
    flock($externalLock, LOCK_UN);
    fclose($externalLock);

    $db->exec("UPDATE ucmp_media_replication_jobs SET next_run=1000 WHERE id=$id");
    $module->failTftp = true;
    $now = 2000;
    $scheduled = $service->runDue();
    $expect(empty($scheduled['ok']) && count($scheduled['jobs']) === 1, 'A failed due job must be reported without hiding successful scopes');
    $row = $db->query("SELECT next_run,last_status,last_success FROM ucmp_media_replication_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $expect((int)$row['next_run'] === 2300, 'Retry-enabled failures must retain the bounded five-minute retry delay');
    $expect($row['last_status'] === 'partial', 'Mixed scope outcomes must persist an honest partial status');
    $expect((int)$row['last_success'] === 1000, 'A partial failure must not replace the last fully successful timestamp');

    $listed = $service->list();
    $expect(count($listed) === 1 && $listed[0]['source_site_name'] === 'SITE-A', 'List must return decoded jobs with site labels');
    $expect($listed[0]['target_site_names'] === ['SITE-B','SITE-C'], 'List must expose target labels in stable target-ID order');
    $deleted = $service->delete($id);
    $expect(!empty($deleted['ok']) && $service->list() === [], 'Deleting a replication schedule must remove only that schedule');

    echo "Media replication service assertions passed\n";
} finally {
    if (is_dir($stateDir)) {
        foreach (glob($stateDir . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
        rmdir($stateDir);
    }
}
