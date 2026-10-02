<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/FirmwareReplicationService.php';

use FreePBX\modules\Ucmprovisioner\FirmwareReplicationService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$rejects = static function (callable $operation, string $message) use ($expect): void {
    try { $operation(); } catch (InvalidArgumentException) { return; }
    $expect(false, $message);
};

final class FirmwareReplicationFakeModule
{
    public array $calls = [];
    public array $logs = [];
    public array $plans = [];
    public function replicationPlan(array $plan):void { $this->plans[]=$plan; }
    public bool $throwLog = false;
    public bool $failCopy = false;
    public bool $secondComplete = false;
    public bool $throwSecond = false;
    public bool $failLocaleInventory = false;
    public bool $failActivation = false;
    public bool $quarantinedTimezone = false;
    public string $timezoneKey = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function getSites(): array
    {
        return [
            ['id'=>1,'name'=>'SITE-A','enabled'=>1],
            ['id'=>2,'name'=>'SITE-B','enabled'=>1],
            ['id'=>3,'name'=>'SITE-C','enabled'=>1],
            ['id'=>4,'name'=>'Disabled','enabled'=>0],
        ];
    }

    public function tftpOp(array $request): array
    {
        $this->calls[] = $request;
        if (($request['op'] ?? '') === 'firmware_inventory') {
            return ['ok'=>true,'bundles'=>[
                ['id'=>10,'profile'=>'cisco:sip88xx','load_name'=>'sip88xx.14-2-1','status'=>'complete','files'=>[['size'=>1000]]],
                ['id'=>11,'profile'=>'cisco:sip78xx','load_name'=>'sip78xx.12-8-1','status'=>$this->secondComplete ? 'complete' : 'incomplete'],
            ]];
        }
        if (($request['op'] ?? '') === 'timezone_versions') {
            return ['ok'=>true,'bundles'=>[
                ['id'=>21,'bundle_key'=>$this->timezoneKey,'served_version'=>'2024a','status'=>$this->quarantinedTimezone?'quarantined':'complete','detected_active'=>!$this->quarantinedTimezone,
                 'storage_path'=>'.ucmprovisioner/timezone-bundles/'.str_repeat('b',64),
                 'files'=>[['name'=>'tzupdater.ver','stored_name'=>str_repeat('c',64).'.blob','sha256'=>str_repeat('d',64),'size'=>200]]],
            ]];
        }
        if (($request['op'] ?? '') === 'cisco_locale_inventory') {
            if ($this->failLocaleInventory) { return ['ok'=>false,'message'=>'Fixture locale inventory failed']; }
            return ['ok'=>true,'profiles'=>['cisco:sip88xx-locale'=>[
                'status'=>'complete','firmware_profile'=>'cisco:sip88xx','files'=>[['bytes'=>300]],
            ]]];
        }
        if ($this->failCopy && in_array(($request['op'] ?? ''), ['sync_firmware_bundle','sync_timezone_version'], true)) {
            return ['ok'=>false,'message'=>'SITE-B is unreachable','sites'=>['2'=>['ok'=>false]]];
        }
        if ($this->throwSecond && ($request['op'] ?? '') === 'sync_firmware_bundle' && ($request['load_name'] ?? '') === 'sip88xx.14-2-1') {
            throw new RuntimeException('Fixture second package outcome unavailable');
        }
        if (($request['op'] ?? '') === 'sync_timezone_version') {
            return ['ok'=>true,'message'=>'Exact selection copied','sites'=>[
                '2'=>['ok'=>true,'timezone_bundle'=>['bundle'=>['id'=>31]]],
                '3'=>['ok'=>true,'timezone_bundle'=>['bundle'=>['id'=>32]]],
            ]];
        }
        return ['ok'=>true,'message'=>'Exact selection copied','sites'=>['2'=>['ok'=>true,'copied'=>2]]];
    }

    public function activateTimezoneVersion(int $siteId, int $bundleId): array
    {
        $this->calls[] = ['op'=>'timezone_activate','site_id'=>$siteId,'bundle_id'=>$bundleId];
        if ($this->failActivation && $siteId === 2) { throw new RuntimeException('Fixture activation outcome unavailable'); }
        return ['ok'=>true,'message'=>'activated'];
    }

    public function logOperation(?int $siteId, string $operation, string $level, string $message, string $detail = ''): void
    {
        if ($this->throwLog) { throw new RuntimeException('Fixture audit log unavailable'); }
        $this->logs[] = compact('siteId','operation','level','message','detail');
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
FirmwareReplicationService::ensureSchema($db);
$module = new FirmwareReplicationFakeModule();
$now = 1000;
$stateDir = sys_get_temp_dir() . '/ucmp-firmware-replication-' . bin2hex(random_bytes(6));
$service = new FirmwareReplicationService($db, $module, $stateDir, static function () use (&$now): int { return $now; });

try {
    $saved = $service->save([
        'name'=>'Fleet firmware releases', 'source_site_id'=>1, 'enabled'=>1,
        'schedule_mode'=>'weekly', 'schedule_time'=>'03:15', 'schedule_weekdays'=>[1,3,5],
        'packages'=>[['profile'=>'cisco:sip88xx','load_name'=>'sip88xx.14-2-1']],
        'timezone_bundle_key'=>$module->timezoneKey, 'target_site_ids'=>[3,2,2],
        'activate_timezone'=>1,
        'changed_only'=>1, 'retry_unreachable'=>1,
    ]);
    $id = (int)($saved['job']['id'] ?? 0);
    $expect($id > 0, 'Saving a firmware replication schedule must return its stable ID');
    $expect(($saved['job']['packages'][0]['load_name'] ?? '') === 'sip88xx.14-2-1', 'Exact load name must be persisted');
    $expect(($saved['job']['timezone_bundle_key'] ?? '') === $module->timezoneKey, 'Exact retained timezone fingerprint must be persisted');
    $expect((int)($saved['job']['activate_timezone'] ?? 0) === 1, 'Timezone activation intent must be persisted');
    $expect(($saved['job']['target_site_ids'] ?? []) === [2,3], 'Target sites must be de-duplicated and sorted');
    $expect(($saved['job']['schedule']['mode'] ?? '') === 'weekly', 'Weekly schedule must use the shared schedule grammar');

    $rejects(static fn() => $service->save([
        'name'=>'Self target','source_site_id'=>1,'enabled'=>1,
        'packages'=>[['profile'=>'cisco:sip88xx','load_name'=>'sip88xx.14-2-1']], 'target_site_ids'=>[1],
    ]), 'The source site must never be accepted as its own target');
    $rejects(static fn() => $service->save([
        'name'=>'Unsafe package','source_site_id'=>1,'enabled'=>1,
        'packages'=>[['profile'=>'cisco:sip88xx','load_name'=>'../sip88xx']], 'target_site_ids'=>[2],
    ]), 'Unsafe package paths must be rejected server-side');
    $rejects(static fn() => $service->save([
        'name'=>'Bad timezone','source_site_id'=>1,'enabled'=>1,
        'timezone_bundle_key'=>'not-a-fingerprint','target_site_ids'=>[2],
    ]), 'Timezone selections must use an exact retained fingerprint');

    $run = $service->runNow($id);
    $plan=end($module->plans);
    $expect($plan['firmware_packages']===1 && $plan['locale_packages']===1 && $plan['timezone_packages']===1
        && $plan['selected_bytes_all_sites']===3000 && $plan['sizes_complete'], 'Whole-selection plan must include all selected package types and both destinations');
    $expect(!empty($run['ok']) && ($run['status'] ?? '') === 'warning', 'Changed-only exact replication should report its preserve-existing limitation');
    $firmwareCopies = array_values(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_firmware_bundle'));
    $timezoneCopies = array_values(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_timezone_version'));
    $expect(count($firmwareCopies) === 1 && ($firmwareCopies[0]['load_name'] ?? '') === 'sip88xx.14-2-1' && (int)($firmwareCopies[0]['overwrite'] ?? -1) === 0, 'Only the selected exact firmware package may be copied in changed-only mode');
    $expect(count($timezoneCopies) === 1 && (int)($timezoneCopies[0]['bundle_id'] ?? 0) === 21, 'Timezone replication must copy the selected retained bundle ID');
    $expect(count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_cisco_locale_bundle')) === 1, 'A complete locale companion bundle for the selected Cisco firmware profile must travel with it');
    $activations = array_values(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'timezone_activate'));
    $expect(count($activations) === 2 && (int)$activations[0]['bundle_id'] === 31 && (int)$activations[1]['bundle_id'] === 32, 'Requested timezone activation must use the copied destination bundle IDs');

    $module->quarantinedTimezone = true;
    $quarantined = $service->save([
        'name'=>'Quarantined timezone must fail', 'source_site_id'=>1, 'enabled'=>1,
        'timezone_bundle_key'=>$module->timezoneKey, 'target_site_ids'=>[2],
    ]);
    $quarantineId = (int)($quarantined['job']['id'] ?? 0);
    $quarantineRun = $service->runNow($quarantineId);
    $expect(empty($quarantineRun['ok']) && str_contains((string)($quarantineRun['message'] ?? ''), 'quarantined'), 'Quarantined timezone evidence must never be selected for replication');
    $expect(count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_timezone_version')) === 1, 'Rejected quarantined timezone evidence must not reach the sync operation');
    $module->quarantinedTimezone = false;
    $service->delete($quarantineId);

    $all = $service->save([
        'name'=>'Complete Firmware tab', 'source_site_id'=>1, 'enabled'=>1,
        'include_all_firmware'=>1, 'include_all_timezones'=>1, 'include_all_locales'=>1,
        'activate_timezone'=>1,
        'locale_user_locale'=>'English_United_States', 'locale_network_locale'=>'United_States',
        'target_site_ids'=>[2], 'changed_only'=>0,
    ]);
    $allId = (int)($all['job']['id'] ?? 0);
    $expect($allId > 0 && (int)$all['job']['include_all_firmware'] === 1
        && (int)$all['job']['include_all_timezones'] === 1
        && (int)$all['job']['include_all_locales'] === 1,
        'A saved job must retain each independent Firmware-tab inventory scope');
    $expect(($all['job']['locale_network_locale'] ?? '') === 'United_States', 'Locale directories must be durable job context');
    $beforeFirmware = count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_firmware_bundle'));
    $beforeTimezone = count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_timezone_version'));
    $beforeLocale = count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_cisco_locale_bundle'));
    $beforeActivation = count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'timezone_activate'));
    $allRun = $service->runNow($allId);
    $expect(!empty($allRun['ok']), 'Complete Firmware-tab replication must run without an exact per-file selection');
    $expect(count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_firmware_bundle')) === $beforeFirmware + 1, 'All-firmware scope must enumerate every complete source package');
    $expect(count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_timezone_version')) === $beforeTimezone + 1, 'All-timezone scope must copy every usable retained rollback version');
    $expect(count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_cisco_locale_bundle')) === $beforeLocale + 1, 'All-locale scope must copy every complete companion bundle');
    $expect(count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'timezone_activate')) === $beforeActivation + 1, 'All-timezone scope with activation must publish the source active set on the destination');
    $module->secondComplete = true; $module->throwSecond = true;
    $later = $service->runNow($allId);
    $packageResults = array_values(array_filter($later['operations'], static fn(array $row): bool => ($row['scope'] ?? '') === 'firmware'));
    $expect(empty($later['ok']) && count($packageResults) === 2 && !empty($packageResults[0]['ok']) && ($packageResults[1]['sites'][2]['status'] ?? '') === 'unknown', 'A later package exception must retain prior successful package and explicit uncertain destination');
    $module->secondComplete = false; $module->throwSecond = false;
    $module->failLocaleInventory = true;
    $localeFailure = $service->runNow($allId);
    $expect(empty($localeFailure['ok']) && count($localeFailure['operations']) >= 3 && str_contains($localeFailure['message'], 'locale inventory failed'), 'A failed locale inventory must be recorded after earlier firmware completion');
    $module->failLocaleInventory = false;
    $module->failActivation = true;
    $activationRun = $service->runNow($id);
    $activationResults = array_values(array_filter($activationRun['operations'], static fn(array $row): bool => ($row['scope'] ?? '') === 'cisco_timezone_activation'));
    $expect(empty($activationRun['ok']) && count($activationResults) === 2 && empty($activationResults[0]['ok']) && !empty($activationResults[1]['ok']), 'One failed activation must not suppress a later destination activation or its receipt');
    $module->failActivation = false;
    $service->delete($allId);

    $db->exec("UPDATE ucmp_firmware_replication_jobs SET next_run=1000 WHERE id=$id");
    $module->failCopy = true;
    $now = 2000;
    $scheduled = $service->runDue();
    $expect(empty($scheduled['ok']) && count($scheduled['jobs']) === 1, 'A failed due job must be reported');
    $row = $db->query("SELECT next_run,last_status,last_success FROM ucmp_firmware_replication_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $expect((int)$row['next_run'] === 2300, 'Retry-enabled failures must retain the five-minute retry delay');
    $expect((int)$row['last_success'] === 1000, 'A failed run must retain the previous successful timestamp');

    $module->throwLog = true;
    $logRun = $service->runNow($id);
    $logStored = json_decode((string)$db->query("SELECT last_result_json FROM ucmp_firmware_replication_jobs WHERE id=$id")->fetchColumn(),true);
    $expect(!empty($logRun['operations']) && isset($logRun['reporting_error']) && $logRun['operations'] === $logStored['operations'], 'A post-transfer logging exception must preserve returned and persisted destination outcomes');
    $module->throwLog = false;
    $beforeSchedule = $db->query("SELECT enabled,next_run,last_result_json FROM ucmp_firmware_replication_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $db->exec("UPDATE ucmp_firmware_replication_jobs SET enabled=0,next_run=0 WHERE id=$id");
    $staleRun = (new ReflectionMethod(FirmwareReplicationService::class, 'execute'))->invoke($service, $saved['job'], true);
    $afterSchedule = $db->query("SELECT enabled,next_run,last_result_json FROM ucmp_firmware_replication_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $expect(($staleRun['status'] ?? '') === 'skipped' && $afterSchedule['last_result_json'] === $beforeSchedule['last_result_json'], 'A job disabled after due selection must not execute stale work or overwrite the prior result');
    $db->prepare("UPDATE ucmp_firmware_replication_jobs SET enabled=?,next_run=? WHERE id=?")->execute([$beforeSchedule['enabled'],$beforeSchedule['next_run'],$id]);

    $lockPath = $stateDir . '/firmware-replication-job-' . $id . '.lock';
    $externalLock = fopen($lockPath, 'c');
    flock($externalLock, LOCK_EX | LOCK_NB);
    $busy = $service->runNow($id);
    $expect(!empty($busy['busy']) && !empty($busy['ok']), 'A concurrent run must exit as a non-destructive busy result');
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

    $deleted = $service->delete($id);
    $expect(!empty($deleted['ok']) && $service->list() === [], 'Deleting a firmware replication schedule must remove only that schedule');
    echo "Firmware replication service assertions passed\n";
} finally {
    if (is_dir($stateDir)) {
        foreach (glob($stateDir . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
        rmdir($stateDir);
    }
}
