<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/ConfigurationSyncService.php';

use FreePBX\modules\Ucmprovisioner\ConfigurationSyncService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$rejects = static function (callable $operation, string $message) use ($expect): void {
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { return; }
    $expect(false, $message);
};

final class ConfigurationSyncFakeModule
{
    public string $controllerKey = 'controller-a';
    public ?array $assetOutcome = null;
    public array $destinationStates = [];
    public function configurationSyncStates(int $site,string $type): array { return $this->destinationStates[$site][$type] ?? []; }
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['site_key'=>$this->controllerKey]; }
    public array $applyCalls = [];
    public array $releaseCalls = [];
    public array $resumeCalls = [];
    public array $assetCalls = [];
    public array $snapshotCalls = [];
    public array $logs = [];
    public bool $throwLog = false;
    public bool $conflict = false;
    public bool $failTarget = false;
    public bool $ignoreObjectFilter = false;
    public bool $throwSnapshot = false;
    public bool $failAssets = false;

    public function getSites(): array
    {
        return [
            ['id'=>1,'name'=>'SITE-A','enabled'=>1],
            ['id'=>2,'name'=>'SITE-B','enabled'=>1],
            ['id'=>3,'name'=>'SITE-C','enabled'=>1],
            ['id'=>4,'name'=>'Off','enabled'=>0],
        ];
    }

    public function configurationSyncSnapshot(int $source, array $scopes, array $objectFilter = []): array
    {
        if ($this->throwSnapshot) { throw new RuntimeException('Fixture source unreachable'); }
        $this->snapshotCalls[] = compact('source','scopes','objectFilter');
        $snapshot = ['ok'=>true,'schema'=>1,'source_site'=>['site_key'=>'site-a','name'=>'SITE-A'],'scopes'=>$scopes,'templates'=>[]];
        if (!$this->ignoreObjectFilter) { $snapshot['object_filter'] = $objectFilter; }
        return $snapshot;
    }

    public function configurationSyncAssets(int $source, array $targets, array $scopes, array $options = []): array
    {
        $this->assetCalls[] = compact('source','targets','scopes','options');
        if ($this->assetOutcome !== null) { return $this->assetOutcome; }
        if ($this->failAssets && in_array(2, $targets, true)) { throw new RuntimeException('Fixture destination asset unavailable'); }
        $items = [];
        foreach ($targets as $target) {
            $items[(string)$target] = [[
                'source_site_key'=>'site-a','source_site_name'=>'SITE-A','object_type'=>'wallpaper_catalog',
                'object_key'=>'wallpaper:base','destination_id'=>0,'source_revision'=>'a','destination_revision'=>'a',
                'status'=>'active','soft_lock'=>!empty($options['soft_lock']),'intended'=>['name'=>'Base'],'missing'=>[],
            ]];
        }
        return ['ok'=>true,'status'=>'success','message'=>'Dependency scope copied','items'=>$items];
    }

    public function configurationSyncApply(int $target, array $snapshot, array $options): array
    {
        $this->applyCalls[] = compact('target','snapshot','options');
        if ($this->failTarget && $target === 2) { throw new RuntimeException('SITE-B unreachable'); }
        $status = $this->conflict && empty($options['force']) ? 'conflict' : 'active';
        return [
            'ok'=>true,
            'status'=>$status === 'active' ? 'success' : 'warning',
            'needs_confirmation'=>$status === 'conflict',
            'warnings'=>$status === 'conflict' ? ['destination changed'] : [],
            'message'=>$status === 'conflict' ? 'Destination changes were preserved' : 'Configuration imported',
            'items'=>[ [
                'source_site_key'=>'site-a','source_site_name'=>'SITE-A','object_type'=>'template','object_key'=>'template:10',
                'destination_id'=>20,'source_revision'=>'5','destination_revision'=>'7','status'=>$status,
                'soft_lock'=>!empty($options['soft_lock']),'intended'=>['name'=>'Base'],'missing'=>[],
            ] ],
        ];
    }

    public function configurationSyncResume(int $target, string $jobKey, string $type, string $key): array
    {
        $this->resumeCalls[] = compact('target','jobKey','type','key');
        return ['ok'=>true,'message'=>'resumed'];
    }

    public function configurationSyncRelease(int $target, string $jobKey): array
    {
        $this->releaseCalls[] = compact('target','jobKey');
        return ['ok'=>true,'message'=>'released'];
    }

    public function logOperation(?int $siteId, string $operation, string $level, string $message, string $detail = ''): void
    {
        if ($this->throwLog) { throw new RuntimeException('Fixture audit log unavailable'); }
        $this->logs[] = compact('siteId','operation','level','message','detail');
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ConfigurationSyncService::ensureSchema($db);
$module = new ConfigurationSyncFakeModule();
$now = 1000;
$stateDir = sys_get_temp_dir() . '/ucmp-configuration-sync-' . bin2hex(random_bytes(6));
$service = new ConfigurationSyncService($db, $module, $stateDir, static function () use (&$now): int { return $now; });

try {
    $saved = $service->save([
        'name'=>'Fleet policy','source_site_id'=>1,'target_site_ids'=>[3,2,2],
        'scopes'=>['templates','site_defaults'],'template_ids'=>[12,10,12],'enabled'=>1,'soft_lock'=>1,
        'retry_unreachable'=>1,'schedule_mode'=>'interval','interval_minutes'=>17,
    ]);
    $id = (int)($saved['job']['id'] ?? 0);
    $expect($id > 0, 'A configuration sync job must receive a stable ID');
    $expect($saved['job']['target_site_ids'] === [2,3], 'Targets must be de-duplicated and deterministic');
    $expect($saved['job']['scopes'] === ['site_defaults','templates'], 'Scopes must be allow-listed and deterministic');
    $expect($saved['job']['template_ids'] === [10,12], 'Selected template IDs must be retained as a deterministic per-job object filter');
    $expect((int)$saved['job']['next_run'] === 2020, 'Minute-granular schedule must be retained');

    $rejects(static fn() => $service->save([
        'name'=>'Self target','source_site_id'=>1,'target_site_ids'=>[1],
        'scopes'=>['templates'],'enabled'=>1,'interval_minutes'=>10,
    ]), 'The source must never be accepted as its own target');
    $rejects(static fn() => $service->save([
        'name'=>'Unknown scope','source_site_id'=>1,'target_site_ids'=>[2],
        'scopes'=>['asterisk_packages'],'enabled'=>1,'interval_minutes'=>10,
    ]), 'Unknown configuration scopes must be rejected');

    $module->ignoreObjectFilter = true;
    $unsupported = $service->runNow($id);
    $expect(
        empty($unsupported['ok']) && str_contains((string)($unsupported['message'] ?? ''), 'did not acknowledge'),
        'An older source that ignores a selected-template filter must fail closed before any destination apply'
    );
    $module->ignoreObjectFilter = false;
    $run = $service->runNow($id);
    $expect(!empty($run['ok']) && $run['status'] === 'success', 'A clean manual synchronization must succeed');
    $expect(
        ($module->snapshotCalls[0]['objectFilter']['templates'] ?? []) === [10,12],
        'A template sync run must request only the templates selected in its durable job definition'
    );
    $listed = $service->list();
    $expect(count($listed) === 1 && count($listed[0]['items']) === 4, 'Policy and catalog ownership must be recorded per target');
    $expect($listed[0]['items'][0]['soft_lock'] === true, 'Soft-lock policy must be visible in tracked destination state');
    $expect(
        count($module->assetCalls) === 2
        && !empty($module->assetCalls[0]['options']['soft_lock'])
        && str_starts_with((string)$module->assetCalls[0]['options']['job_key'],'cfgc:controller-a:'),
        'Catalog replication must receive the same durable job identity and soft-lock policy as configuration objects'
    );

    $module->failAssets = true;
    $priorApplyCount = count($module->applyCalls);
    $assetFailure = $service->runNow($id);
    $expect(empty($assetFailure['ok']) && empty($assetFailure['targets'][2]['ok']) && !empty($assetFailure['targets'][3]['ok']), 'An asset failure at one site must retain and allow another destination completion');
    $expect(array_column(array_slice($module->applyCalls,$priorApplyCount),'target') === [3], 'Policy must not be applied to the site with failed required assets');
    $expect($service->list()[0]['last_result']['targets'] === $assetFailure['targets'], 'Configuration job reload must retain its last per-destination results');
    $lastDestinations = array_column($service->list()[0]['last_result']['destination_results'], 'status', 'site_id');
    $expect($lastDestinations === [2=>'unknown',3=>'success'], 'Job reload must expose independently classified destination outcomes for the GUI');
    $module->failAssets = false;
    $module->assetOutcome = ['ok'=>false,'message'=>'Source dependency has not arrived','operations'=>[]];
    $priorApplyCount = count($module->applyCalls);
    $waiting = $service->runNow($id);
    $expect(!$waiting['ok'] && count($module->applyCalls) === $priorApplyCount + 2
        && str_contains($waiting['targets'][2]['message'],'intent retained'), 'Absent files must still allow policy intent to be retained for automatic later recovery');
    $module->assetOutcome = ['ok'=>false,'operations'=>[['targets'=>[2=>['status'=>'unknown','recovery_required'=>true]]]]];
    $priorApplyCount = count($module->applyCalls);
    $service->runNow($id);
    $expect(count($module->applyCalls) === $priorApplyCount,'Uncertain asset publication must still stop policy application');
    $module->assetOutcome = null;
    $owned = array_values(array_filter($service->list()[0]['items'],static fn(array $item):bool=>$item['target_site_id']===2 && $item['object_type']==='template'))[0];
    $receipt = $db->query("SELECT last_result_json FROM ucmp_configuration_sync_jobs WHERE id=$id")->fetchColumn();
    $db->exec("UPDATE ucmp_configuration_sync_items SET status='missing_dependency' WHERE job_id=$id AND target_site_id=2 AND object_type='template'");
    $module->destinationStates[2]['template'] = [array_replace($owned,['status'=>'active','missing'=>[]])];
    (new ReflectionMethod(ConfigurationSyncService::class,'refreshDependencyStates'))->invoke($service);
    $expect($db->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_id=$id AND target_site_id=2 AND object_type='template'")->fetchColumn()==='active', 'A destination repair must refresh the controller badge without reseeding the job');
    $expect($db->query("SELECT last_result_json FROM ucmp_configuration_sync_jobs WHERE id=$id")->fetchColumn()===$receipt,'Refreshing dependency state must preserve historical run receipts');
    $externalLock = fopen($stateDir . '/configuration-sync-job-' . $id . '.lock', 'c');
    flock($externalLock, LOCK_EX | LOCK_NB);
    foreach ([static fn() => $service->save(['id'=>$id] + $saved['job']), static fn() => $service->delete($id), static fn() => $service->resume($id,2,'template','template:10')] as $mutation) {
        $blocked = false;
        try { $mutation(); } catch (RuntimeException $error) { $blocked = str_contains($error->getMessage(),'running'); }
        $expect($blocked, 'Configuration edit/delete/resume must not race an active run');
    }
    flock($externalLock, LOCK_UN); fclose($externalLock);

    $module->throwLog = true;
    $logRun = $service->runNow($id);
    $logStored = json_decode((string)$db->query("SELECT last_result_json FROM ucmp_configuration_sync_jobs WHERE id=$id")->fetchColumn(),true);
    $expect(!empty($logRun['targets']) && isset($logRun['reporting_error']) && $logRun['targets'] === $logStored['targets'], 'A post-transfer logging exception must preserve returned and persisted destination outcomes');
    $module->throwLog = false;
    $beforeSchedule = $db->query("SELECT enabled,next_run,last_result_json FROM ucmp_configuration_sync_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $db->exec("UPDATE ucmp_configuration_sync_jobs SET enabled=0,next_run=0 WHERE id=$id");
    $staleRun = (new ReflectionMethod(ConfigurationSyncService::class, 'execute'))->invoke($service, $saved['job'], true, false);
    $afterSchedule = $db->query("SELECT enabled,next_run,last_result_json FROM ucmp_configuration_sync_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $expect(($staleRun['status'] ?? '') === 'skipped' && $afterSchedule['last_result_json'] === $beforeSchedule['last_result_json'], 'A job disabled after due selection must not execute stale work or overwrite the prior result');
    $db->prepare("UPDATE ucmp_configuration_sync_jobs SET enabled=?,next_run=? WHERE id=?")->execute([$beforeSchedule['enabled'],$beforeSchedule['next_run'],$id]);

    $module->conflict = true;
    $conflict = $service->runNow($id);
    $expect(!empty($conflict['ok']) && !empty($conflict['needs_confirmation']), 'Destination revision conflicts must be preserved and request confirmation');
    $forced = $service->runNow($id, true);
    $expect(!empty($forced['ok']) && empty($forced['needs_confirmation']) && !empty(end($module->applyCalls)['options']['force']), 'An explicit manual confirmation must be forwarded as force only for that run');
    $module->conflict = false;

    $db->exec("INSERT INTO ucmp_configuration_sync_items
        (job_key,job_id,source_site_key,source_site_name,target_site_id,object_type,object_key,status)
        VALUES ('cfg:another-controller:1',$id,'other-source','Other source',2,'template','template:10','paused')");
    $expect(!array_filter($service->list()[0]['items'],static fn(array $item):bool=>$item['job_key']==='cfg:another-controller:1'),
        'An incoming job with the same numeric ID must not appear in this controller job');
    $db->exec("UPDATE ucmp_configuration_sync_items SET status='paused',pause_reason='local edit' WHERE job_id=$id AND target_site_id=2");
    $resumed = $service->resume($id, 2, 'template', 'template:10');
    $expect(!empty($resumed['ok']) && count($module->resumeCalls) === 1, 'Resume must reconcile destination and controller state');
    $resumeRow = $db->query("SELECT status,destination_revision FROM ucmp_configuration_sync_items WHERE job_id=$id AND job_key<>'cfg:another-controller:1' AND target_site_id=2 AND object_type='template' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $expect(($resumeRow['status'] ?? '') === 'active' && ($resumeRow['destination_revision'] ?? null) === null, 'Resume must discard the stale destination baseline so the next run can reconcile the source');
    $expect($db->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_key='cfg:another-controller:1'")->fetchColumn()==='paused',
        'Resuming this job must preserve another controller soft lock with the same numeric job ID');

    $changed = $service->save([
        'id'=>$id,'name'=>'Fleet policy','source_site_id'=>1,'target_site_ids'=>[2],
        'scopes'=>['templates'],'enabled'=>1,'soft_lock'=>1,'retry_unreachable'=>1,
        'schedule_mode'=>'interval','interval_minutes'=>17,
    ]);
    $expect(!empty($changed['ok']) && count($module->releaseCalls) === 2, 'Changing target or scope must release every previous destination lock before saving');
    $expect((int)$db->query("SELECT COUNT(*) FROM ucmp_configuration_sync_items WHERE job_id=$id AND job_key<>'cfg:another-controller:1'")->fetchColumn() === 0, 'Changed job definitions must discard stale controller-side ownership rows');
    $expect($db->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_key='cfg:another-controller:1'")->fetchColumn()==='paused',
        'Changing this job must preserve another controller ownership row');

    $service->runNow($id);
    $deleted = $service->delete($id);
    $expect(!empty($deleted['ok']) && count($module->releaseCalls) === 3 && $service->list() === [], 'Deleting a job must release locks but preserve synchronized destination content');
    $expect($db->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_key='cfg:another-controller:1'")->fetchColumn()==='paused',
        'Deleting this job must preserve another controller ownership row');

    foreach (['normal_failure','exception_failure'] as $failure) {
        $job=$service->save(['name'=>$failure,'source_site_id'=>1,'target_site_ids'=>[2],
            'scopes'=>['templates'],'enabled'=>1,'retry_unreachable'=>0,'schedule_mode'=>'interval','interval_minutes'=>17]);
        $jobId=(int)$job['job']['id'];
        $module->throwSnapshot=$failure==='exception_failure'; $module->failTarget=$failure==='normal_failure';
        $db->exec("UPDATE ucmp_configuration_sync_jobs SET next_run=$now WHERE id=$jobId");
        $due=$service->runDue();
        $next=(int)$db->query("SELECT next_run FROM ucmp_configuration_sync_jobs WHERE id=$jobId")->fetchColumn();
        $expect(empty($due['ok']) && $next===$now+17*60,'Retry disabled must advance to the next normal schedule on both result and exception failures');
        $expect($service->runDue()['jobs']===[],'Disabled retry left a failed job immediately due');
        $module->throwSnapshot=false; $module->failTarget=false;
    }
    echo "Configuration sync service assertions passed\n";
} finally {
    if (is_dir($stateDir)) {
        foreach (glob($stateDir . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
        rmdir($stateDir);
    }
}
