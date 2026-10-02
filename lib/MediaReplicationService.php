<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

use PDO;

require_once __DIR__ . '/ServiceFilePermissions.php';
require_once __DIR__ . '/ReplicationActivityService.php';

/**
 * Copy managed wallpaper catalogs, special backgrounds and ringtone libraries
 * between sites. Every run is bounded, independently tracked and hash checked;
 * device policy is synchronized separately from the media bytes it references.
 */
final class MediaReplicationService
{
    private const SCOPES = ['wallpapers', 'special', 'ringtones'];
    private const MIN_INTERVAL = 5;
    private const MAX_INTERVAL = 10080;
    private const MAX_TARGETS = 20;
    private const MAX_DUE_JOBS = 10;
    private const MAX_HOSTED_LIBRARIES = 250;

    private PDO $db;
    private object $ucm;
    private string $stateDir;
    private \Closure $clock;

    public function __construct(PDO $db, object $ucm, string $stateDir = '/var/lib/asterisk/ucmprovisioner', ?callable $clock = null)
    {
        $this->db = $db;
        $this->ucm = $ucm;
        $this->stateDir = rtrim($stateDir, '/\\');
        $this->clock = $clock instanceof \Closure ? $clock : \Closure::fromCallable($clock ?? 'time');
    }

    public static function ensureSchema(PDO $db): void
    {
        if ((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ucmp_media_replication_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    source_site_id INTEGER NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    interval_minutes INTEGER NOT NULL DEFAULT 60,
                    schedule_json TEXT,
                    scopes_json TEXT NOT NULL,
                    targets_json TEXT NOT NULL,
                    changed_only INTEGER NOT NULL DEFAULT 1,
                    retry_unreachable INTEGER NOT NULL DEFAULT 1,
                    next_run INTEGER NOT NULL DEFAULT 0,
                    last_attempt INTEGER NOT NULL DEFAULT 0,
                    last_success INTEGER NOT NULL DEFAULT 0,
                    last_status TEXT NOT NULL DEFAULT \'never\',
                    last_message TEXT,
                    last_result_json TEXT,
                    created INTEGER NOT NULL DEFAULT 0,
                    updated INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (source_site_id,name)
                )'
            );
            $db->exec('CREATE INDEX IF NOT EXISTS idx_ucmp_media_replication_due ON ucmp_media_replication_jobs (enabled,next_run)');
            self::ensureScheduleColumn($db);
            return;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_media_replication_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                source_site_id INT NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                interval_minutes INT NOT NULL DEFAULT 60,
                schedule_json LONGTEXT NULL,
                scopes_json LONGTEXT NOT NULL,
                targets_json LONGTEXT NOT NULL,
                changed_only TINYINT(1) NOT NULL DEFAULT 1,
                retry_unreachable TINYINT(1) NOT NULL DEFAULT 1,
                next_run INT NOT NULL DEFAULT 0,
                last_attempt INT NOT NULL DEFAULT 0,
                last_success INT NOT NULL DEFAULT 0,
                last_status VARCHAR(24) NOT NULL DEFAULT 'never',
                last_message TEXT NULL,
                last_result_json LONGTEXT NULL,
                created INT NOT NULL DEFAULT 0,
                updated INT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_ucmp_media_replication_name (source_site_id,name),
                KEY idx_ucmp_media_replication_due (enabled,next_run)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::ensureScheduleColumn($db);
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->db->query('SELECT * FROM ucmp_media_replication_jobs ORDER BY enabled DESC,name,id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sites = [];
        foreach ((array)$this->ucm->getSites() as $site) {
            $sites[(int)($site['id'] ?? 0)] = (string)($site['name'] ?? ('Site #' . (int)($site['id'] ?? 0)));
        }
        foreach ($rows as &$row) {
            $row = $this->decodeRow($row);
            $row['source_site_name'] = $sites[(int)$row['source_site_id']] ?? ('Site #' . (int)$row['source_site_id']);
            $row['target_site_names'] = array_map(
                static fn(int $id): string => $sites[$id] ?? ('Site #' . $id),
                $row['target_site_ids']
            );
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function save(array $input): array
    {
        $editLock = $this->lock('media-replication-definitions.lock');
        if ($editLock === false) { throw new \RuntimeException('Another replication job edit is in progress; retry after it finishes'); }
        $jobLock = null;
        try {
            $id = max(0, (int)($input['id'] ?? 0));
            $jobLock = $id > 0 ? $this->lock('media-replication-job-' . $id . '.lock') : null;
            if ($jobLock === false) { throw new \RuntimeException('This replication job is running; save changes after it finishes'); }
            return $this->saveUnlocked($input);
        } finally {
            if (is_resource($jobLock)) { $this->unlock($jobLock); }
            $this->unlock($editLock);
        }
    }

    private function saveUnlocked(array $input): array
    {
        $now = ($this->clock)();
        $id = max(0, (int)($input['id'] ?? 0));
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 80) {
            throw new \InvalidArgumentException('Replication job name is required and must be at most 80 characters');
        }
        $sourceSiteId = (int)($input['source_site_id'] ?? 0);
        $targets = $this->integerList($input['target_site_ids'] ?? ($input['targets'] ?? []));
        $scopes = $this->scopeList($input['scopes'] ?? []);
        $fallbackInterval = (int)($input['interval_minutes'] ?? 60);
        $schedule = RecurringSchedule::fromFields($input, $fallbackInterval, self::MIN_INTERVAL, self::MAX_INTERVAL);
        $interval = RecurringSchedule::compatibilityMinutes($schedule);
        $scheduleJson = RecurringSchedule::encode($schedule);
        if (!$scopes) {
            throw new \InvalidArgumentException('Choose at least one media replication scope');
        }
        if (!$targets || count($targets) > self::MAX_TARGETS) {
            throw new \InvalidArgumentException('Choose between one and twenty target sites');
        }
        if (in_array($sourceSiteId, $targets, true)) {
            throw new \InvalidArgumentException('The source site cannot also be a replication target');
        }
        $this->assertSites($sourceSiteId, $targets);

        $enabled = !empty($input['enabled']) ? 1 : 0;
        $changedOnly = !empty($input['changed_only']) ? 1 : 0;
        $retry = !empty($input['retry_unreachable']) ? 1 : 0;
        $scopesJson = (string)json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $targetsJson = (string)json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $existing = $id > 0 ? $this->find($id) : null;
        if ($id > 0 && $existing === null) {
            throw new \InvalidArgumentException('Media replication job was not found');
        }
        $scheduleChanged = !$existing
            || (int)$existing['enabled'] !== $enabled
            || (string)($existing['schedule_json'] ?? '') !== $scheduleJson;
        $nextRun = $enabled
            ? ($scheduleChanged || (int)($existing['next_run'] ?? 0) < 1 ? RecurringSchedule::nextRun($schedule, $now) : (int)$existing['next_run'])
            : 0;

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE ucmp_media_replication_jobs
                    SET name=?,source_site_id=?,enabled=?,interval_minutes=?,schedule_json=?,scopes_json=?,targets_json=?,
                        changed_only=?,retry_unreachable=?,next_run=?,updated=?
                  WHERE id=?'
            );
            $stmt->execute([$name,$sourceSiteId,$enabled,$interval,$scheduleJson,$scopesJson,$targetsJson,$changedOnly,$retry,$nextRun,$now,$id]);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO ucmp_media_replication_jobs
                    (name,source_site_id,enabled,interval_minutes,schedule_json,scopes_json,targets_json,changed_only,
                     retry_unreachable,next_run,last_attempt,last_success,last_status,created,updated)
                 VALUES (?,?,?,?,?,?,?,?,?,?,0,0,\'never\',?,?)'
            );
            $stmt->execute([$name,$sourceSiteId,$enabled,$interval,$scheduleJson,$scopesJson,$targetsJson,$changedOnly,$retry,$nextRun,$now,$now]);
            $id = (int)$this->db->lastInsertId();
        }

        return ['ok'=>true, 'job'=>$this->decodeRow($this->find($id) ?? []), 'message'=>'Media replication job saved'];
    }

    /** @return array<string,mixed> */
    public function delete(int $id): array
    {
        $editLock = $this->lock('media-replication-definitions.lock');
        if ($editLock === false) { throw new \RuntimeException('Another replication job edit is in progress; retry after it finishes'); }
        $jobLock = null;
        try {
            $jobLock = $this->lock('media-replication-job-' . max(0,$id) . '.lock');
            if ($jobLock === false) { throw new \RuntimeException('This replication job is running; delete it after it finishes'); }
            return $this->deleteUnlocked($id);
        } finally {
            if (is_resource($jobLock)) { $this->unlock($jobLock); }
            $this->unlock($editLock);
        }
    }

    private function deleteUnlocked(int $id): array
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Choose a media replication job');
        }
        $stmt = $this->db->prepare('DELETE FROM ucmp_media_replication_jobs WHERE id=?');
        $stmt->execute([$id]);
        return ['ok'=>$stmt->rowCount() === 1, 'message'=>$stmt->rowCount() === 1 ? 'Media replication job deleted' : 'Media replication job was not found'];
    }

    /** Run one job without moving its recurring cadence. */
    public function runNow(int $id): array
    {
        $job = $this->find($id);
        if (!$job) {
            throw new \InvalidArgumentException('Media replication job was not found');
        }
        return $this->execute($this->decodeRow($job), false);
    }

    /** One-minute cron tick. Only a bounded number of due jobs run per tick. */
    public function runDue(): array
    {
        $runnerLock = $this->lock('media-replication-runner.lock');
        if ($runnerLock === false) {
            return ['ok'=>true, 'status'=>'busy', 'skipped'=>true, 'jobs'=>[], 'message'=>'Another media replication scheduler is already running'];
        }
        try {
            $now = ($this->clock)();
            $stmt = $this->db->prepare(
                'SELECT * FROM ucmp_media_replication_jobs
                  WHERE enabled=1 AND next_run>0 AND next_run<=?
                  ORDER BY next_run,id LIMIT ' . self::MAX_DUE_JOBS
            );
            $stmt->execute([$now]);
            $results = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $results[] = $this->execute($this->decodeRow($row), true);
            }
            $failed = array_filter($results, static fn(array $result): bool => empty($result['ok']) && empty($result['busy']));
            return [
                'ok'=>!$failed,
                'status'=>$failed ? 'partial' : 'success',
                'jobs'=>$results,
                'message'=>$results
                    ? (count($results) . ' scheduled media replication job(s) processed')
                    : 'No media replication jobs are due',
            ];
        } finally {
            $this->unlock($runnerLock);
        }
    }

    /** One-off copy used by configuration sync; no temporary scheduler row is created. */
    public function replicateSelection(int $sourceSiteId, array $targetSiteIds, array $scopes, bool $changedOnly = true): array
    {
        $targets = $this->integerList($targetSiteIds);
        $selected = $this->scopeList($scopes);
        if (!$selected) { return ['ok'=>true,'status'=>'skipped','message'=>'No media scopes selected']; }
        if (!$targets || count($targets) > self::MAX_TARGETS) { throw new \InvalidArgumentException('Choose between one and twenty target sites'); }
        if (in_array($sourceSiteId, $targets, true)) { throw new \InvalidArgumentException('The source site cannot also be a replication target'); }
        $this->assertSites($sourceSiteId, $targets);
        return $this->replicate([
            'source_site_id'=>$sourceSiteId,
            'target_site_ids'=>$targets,
            'scopes'=>$selected,
            'changed_only'=>$changedOnly,
        ]);
    }

    /** @return array<string,mixed> */
    private function execute(array $job, bool $scheduled): array
    {
        $id = (int)$job['id'];
        $jobLock = $this->lock('media-replication-job-' . $id . '.lock');
        if ($jobLock === false) {
            return ['ok'=>true, 'busy'=>true, 'status'=>'busy', 'job_id'=>$id, 'message'=>'This media replication job is already running'];
        }
        try {
            $now = ($this->clock)();
            // A save can finish between scheduler selection and lock acquisition.
            $current = $this->find($id);
            if (!$current) { return ['ok'=>true,'status'=>'skipped','job_id'=>$id,'message'=>'The job was deleted before execution']; }
            $job = $this->decodeRow($current);
            if ($scheduled && (empty($job['enabled']) || (int)$job['next_run'] < 1 || (int)$job['next_run'] > $now)) {
                return ['ok'=>true,'status'=>'skipped','job_id'=>$id,'message'=>'The job is no longer due'];
            }
            $result = method_exists($this->ucm, 'trackedReplication')
                ? $this->ucm->trackedReplication('media', 'Media replication: ' . $job['name'],
                    ['site_id'=>$job['source_site_id'],'target_site_ids'=>$job['target_site_ids'],'id'=>$id], fn():array=>$this->replicate($job))
                : $this->replicate($job);
            $status = (string)($result['status'] ?? (!empty($result['ok']) ? 'success' : 'error'));
            $message = substr((string)($result['message'] ?? 'Media replication finished'), 0, 2000);
            $nextRun = (int)$job['next_run'];
            if ($scheduled) {
                $nextRun = empty($result['ok']) && !empty($job['retry_unreachable'])
                    ? $now + (self::MIN_INTERVAL * 60)
                    : RecurringSchedule::nextRun($job['schedule'], $now);
            }
            $encoded = (string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $stmt = $this->db->prepare(
                'UPDATE ucmp_media_replication_jobs
                    SET last_attempt=?,last_success=?,last_status=?,last_message=?,last_result_json=?,next_run=?,updated=?
                  WHERE id=?'
            );
            $stmt->execute([
                $now,
                !empty($result['ok']) ? $now : (int)$job['last_success'],
                substr($status, 0, 24),
                $message,
                $encoded,
                $nextRun,
                $now,
                $id,
            ]);
            if (method_exists($this->ucm, 'logOperation')) {
                $this->ucm->logOperation(
                    (int)$job['source_site_id'],
                    $scheduled ? 'media-replication-schedule' : 'media-replication-run',
                    !empty($result['ok']) ? ($status === 'warning' ? 'warning' : 'success') : 'error',
                    $message,
                    $encoded
                );
            }
            return ['job_id'=>$id, 'job_name'=>$job['name'], 'scheduled'=>$scheduled] + $result;
        } catch (\Throwable $e) {
            if (isset($result)) {
                // Transfer outcomes survive a later database/audit-log failure.
                $result['reporting_error'] = $e->getMessage();
                $result['warnings'][] = 'Saving or logging the completed run failed: ' . $e->getMessage();
                $result['message'] .= '; saving or logging the completed run failed';
                if (!empty($result['ok'])) { $result['status'] = 'warning'; }
                return ['job_id'=>$id,'job_name'=>$job['name'],'scheduled'=>$scheduled] + $result;
            }
            $now = ($this->clock)();
           $nextRun = $scheduled
                ? (!empty($job['retry_unreachable'])
                    ? $now + (self::MIN_INTERVAL * 60)
                    : RecurringSchedule::nextRun($job['schedule'], $now))
                : (int)$job['next_run'];
            $this->db->prepare(
                'UPDATE ucmp_media_replication_jobs
                    SET last_attempt=?,last_status=\'error\',last_message=?,last_result_json=?,next_run=?,updated=? WHERE id=?'
            )->execute([$now,substr($e->getMessage(),0,2000),json_encode(['ok'=>false,'message'=>$e->getMessage()]),$nextRun,$now,$id]);
            return ['ok'=>false, 'status'=>'error', 'job_id'=>$id, 'job_name'=>$job['name'], 'scheduled'=>$scheduled, 'message'=>$e->getMessage()];
        } finally {
            $this->unlock($jobLock);
        }
    }

    /** @return array<string,mixed> */
    private function replicate(array $job): array
    {
        $source = (int)$job['source_site_id'];
        $targets = (array)$job['target_site_ids'];
        $scopes = (array)$job['scopes'];
        // TFTP preserve-existing now rejects same-name/different-byte conflicts.
        // Hosted-library copies still preserve existing names because that remote
        // API does not expose hashes.
        $overwrite = empty($job['changed_only']);
        $operations = [];
        $errors = [];
        $completed = 0;
        $skipped = 0;

        $tftpScopes = array_values(array_intersect(['wallpapers','ringtones'], $scopes));
        if ($tftpScopes) {
            $scope = count($tftpScopes) === 2 ? 'all' : $tftpScopes[0];
            try { $sync = (array)$this->ucm->tftpOp([
                'op'=>'sync_wallpaper_media',
                'site_id'=>$source,
                'scope'=>$scope,
                'target_site_ids'=>$targets,
                'overwrite'=>$overwrite ? 1 : 0,
            ]); } catch (\Throwable $error) {
                $sync = ReplicationActivityService::failedOperation($error, $targets, $scope);
            }
            $operations[] = ['scope'=>$scope, 'engine'=>'tftp'] + $sync;
            if (!empty($sync['ok'])) {
                $completed++;
            } elseif ($this->emptySourceMessage((string)($sync['message'] ?? ''))) {
                $skipped++;
            } else {
                $errors[] = (string)($sync['message'] ?? 'TFTP media synchronization failed');
            }
        }

        if (in_array('ringtones', $scopes, true)) {
            try { $audio = $this->replicateHostedAudioLibraries($source, $targets, $overwrite); }
            catch (\Throwable $error) { $audio = ReplicationActivityService::failedOperation($error, $targets, 'hosted-audio', true); }
            $operations[] = $audio;
            if (!empty($audio['ok']) && empty($audio['skipped'])) {
                $completed++;
            } elseif (!empty($audio['skipped'])) {
                $skipped++;
            } else {
                $errors[] = (string)($audio['message'] ?? 'Hosted audio synchronization failed');
            }
        }

        if (in_array('special', $scopes, true)) {
            try { $special = $this->replicateSpecialLibraries($source, $targets, $overwrite); }
            catch (\Throwable $error) { $special = ReplicationActivityService::failedOperation($error, $targets, 'special', true); }
            $operations[] = $special;
            if (!empty($special['ok']) && empty($special['skipped'])) {
                $completed++;
            } elseif (!empty($special['skipped'])) {
                $skipped++;
            } else {
                $errors[] = (string)($special['message'] ?? 'Special media synchronization failed');
            }
        }

        $ok = !$errors;
        $status = $errors ? ($completed > 0 ? 'partial' : 'error') : ($completed > 0 ? 'success' : 'skipped');
        $notes = [];
        if (!empty($job['changed_only'])) {
            $notes[] = 'Changed-only rejects different TFTP bytes and preserves existing hosted-media filenames where remote hashes are unavailable';
            if ($status === 'success') { $status = 'warning'; }
        }
        $notes[] = 'Source deletions are not propagated automatically';
        $summary = $errors
            ? implode('; ', array_values(array_unique($errors)))
            : ($completed > 0 ? 'Media replication completed' : 'No source media matched the selected scopes');
        if ($notes) { $summary .= '. ' . implode('. ', $notes); }
        return [
            'ok'=>$ok,
            'status'=>$status,
            'completed_scopes'=>$completed,
            'skipped_scopes'=>$skipped,
            'operations'=>$operations,
            'limitations'=>$notes,
            'message'=>$summary,
        ];
    }

    /** @return array<string,mixed> */
    private function replicateSpecialLibraries(int $source, array $targets, bool $overwrite): array
    {
        $synced = [];
        $errors = [];
        $libraries = $this->hostedLibraries(['cisco_cnf','yealink','grandstream']);
        $catalogs = $this->mediaRequest([
            'op'=>'special_catalog_list', 'site_id'=>$source,
            'family'=>'cisco_cnf', 'model'=>'all',
        ]);
        $retainedNames = [];
        if (empty($catalogs['ok'])) {
            $errors[] = 'cisco_cnf catalogs: ' . (string)($catalogs['message'] ?? 'source catalog library could not be read');
            $synced[] = ReplicationActivityService::failedOperation(new \RuntimeException(end($errors)), $targets, 'cisco_cnf catalogs', true);
        } elseif (!empty($catalogs['catalogs'])) {
            $retainedNames = array_fill_keys(array_map(
                static fn(array $catalog): string => (string)($catalog['published_name'] ?? ''),
                (array)$catalogs['catalogs']
            ), true);
            unset($retainedNames['']);
            $catalogSync = $this->mediaRequest([
                'op'=>'special_catalog_sync', 'site_id'=>$source,
                'target_site_ids'=>$targets, 'overwrite'=>$overwrite ? 1 : 0,
            ]);
            $synced[] = ['library'=>'cisco_cnf|retained-catalogs'] + $catalogSync;
            if (empty($catalogSync['ok'])) {
                $errors[] = 'cisco_cnf catalogs: ' . (string)($catalogSync['message'] ?? 'catalog synchronization failed');
            }
        }
        foreach ($libraries as $key=>$library) {
            $listed = $this->mediaRequest([
                'op'=>'list',
                'site_id'=>$source,
                'family'=>$library['family'],
                'model'=>$library['model'],
            ]);
            if (empty($listed['ok'])) {
                $errors[] = $key . ': ' . (string)($listed['message'] ?? 'source library could not be read');
                $synced[] = ReplicationActivityService::failedOperation(new \RuntimeException(end($errors)), $targets, $key, true);
                break; // one unavailable source must not trigger hundreds of identical probes
            }
            $items = array_values(array_filter(
                (array)($listed['items'] ?? []),
                static fn(array $item): bool => !self::isAudioItem($item)
            ));
            if ($key === 'cisco_cnf|all' && $retainedNames) {
                $items = array_values(array_filter(
                    $items,
                    static fn(array $item): bool => !isset($retainedNames[(string)($item['name'] ?? '')])
                ));
            }
            if (!$items) { continue; }
            $syncRequest = [
                'op'=>'sync',
                'site_id'=>$source,
                'family'=>$library['family'],
                'model'=>$library['model'],
                'target_site_ids'=>$targets,
                'overwrite'=>$overwrite ? 1 : 0,
            ];
            $syncRequest['names'] = array_values(array_map(
                static fn(array $item): string => (string)$item['name'],
                $items
            ));
            $result = $this->mediaRequest($syncRequest);
            $synced[] = ['library'=>$key] + $result;
            if (empty($result['ok'])) {
                $errors[] = $key . ': ' . (string)($result['message'] ?? 'synchronization failed');
            }
        }
        if (!$synced && !$errors) {
            return ['scope'=>'special', 'engine'=>'hosted-media', 'ok'=>true, 'skipped'=>true, 'libraries'=>[], 'message'=>'No prepared or special media libraries contain files'];
        }
        return [
            'scope'=>'special',
            'engine'=>'hosted-media',
            'ok'=>!$errors,
            'skipped'=>false,
            'libraries'=>$synced,
            'message'=>$errors ? implode('; ', $errors) : count($synced) . ' prepared/special media library(s) synchronized',
        ];
    }

    /** @return array<string,mixed> */
    private function replicateHostedAudioLibraries(int $source, array $targets, bool $overwrite): array
    {
        $synced = [];
        $errors = [];
        foreach ($this->hostedLibraries(['cisco_xml','yealink','grandstream']) as $key=>$library) {
            $listed = $this->mediaRequest([
                'op'=>'list',
                'site_id'=>$source,
                'family'=>$library['family'],
                'model'=>$library['model'],
            ]);
            if (empty($listed['ok'])) {
                $errors[] = $key . ': ' . (string)($listed['message'] ?? 'source library could not be read');
                $synced[] = ReplicationActivityService::failedOperation(new \RuntimeException(end($errors)), $targets, $key, true);
                break;
            }
            $items = array_values(array_filter(
                (array)($listed['items'] ?? []),
                static fn(array $item): bool => self::isAudioItem($item)
            ));
            if (!$items) { continue; }
            $result = $this->mediaRequest([
                'op'=>'sync',
                'site_id'=>$source,
                'family'=>$library['family'],
                'model'=>$library['model'],
                'names'=>array_values(array_map(static fn(array $item): string => (string)$item['name'], $items)),
                'target_site_ids'=>$targets,
                'overwrite'=>$overwrite ? 1 : 0,
            ]);
            $synced[] = ['library'=>$key] + $result;
            if (empty($result['ok'])) {
                $errors[] = $key . ': ' . (string)($result['message'] ?? 'synchronization failed');
            }
        }
        if (!$synced && !$errors) {
            return ['scope'=>'ringtones', 'engine'=>'hosted-audio', 'ok'=>true, 'skipped'=>true, 'libraries'=>[], 'message'=>'No Cisco XML, Yealink, or Grandstream hosted audio libraries contain files'];
        }
        return [
            'scope'=>'ringtones',
            'engine'=>'hosted-audio',
            'ok'=>!$errors,
            'skipped'=>false,
            'libraries'=>$synced,
            'message'=>$errors ? implode('; ', $errors) : count($synced) . ' hosted audio library(s) synchronized',
        ];
    }

    /** Preserve a library's completed outcome across source/API exceptions. */
    private function mediaRequest(array $request): array
    {
        try { return (array)$this->ucm->mediaAssetOp($request); }
        catch (\Throwable $error) {
            return ReplicationActivityService::failedOperation($error, (array)($request['target_site_ids'] ?? []),
                (string)($request['family'] ?? 'catalogs') . '|' . (string)($request['model'] ?? 'all'),
                in_array($request['op'], ['list','special_catalog_list'], true));
        }
    }

    /** @return array<string,array{family:string,model:string}> */
    private function hostedLibraries(array $families): array
    {
        $families = array_fill_keys($families, true);
        $libraries = [];
        foreach (array_keys($families) as $family) {
            $libraries[$family . '|all'] = ['family'=>$family, 'model'=>'all'];
        }
        foreach ((array)$this->ucm->getModels() as $model) {
            $family = strtolower(trim((string)($model['family'] ?? '')));
            $name = strtolower(trim((string)($model['model'] ?? '')));
            if (!isset($families[$family]) || $name === '' || !preg_match('/^[a-z0-9_.-]+$/D', $name)) {
                continue;
            }
            $libraries[$family . '|' . $name] = ['family'=>$family, 'model'=>$name];
        }
        ksort($libraries, SORT_NATURAL | SORT_FLAG_CASE);
        return array_slice($libraries, 0, self::MAX_HOSTED_LIBRARIES, true);
    }

    private static function isAudioItem(array $item): bool
    {
        if (strtolower((string)($item['media_kind'] ?? '')) === 'audio') { return true; }
        return in_array(strtolower(pathinfo((string)($item['name'] ?? ''), PATHINFO_EXTENSION)), ['wav','mp3','ogg'], true);
    }

    private function assertSites(int $sourceSiteId, array $targets): void
    {
        $enabled = [];
        foreach ((array)$this->ucm->getSites() as $site) {
            if (!empty($site['enabled'])) { $enabled[(int)($site['id'] ?? 0)] = true; }
        }
        if ($sourceSiteId < 1 || empty($enabled[$sourceSiteId])) {
            throw new \InvalidArgumentException('Choose an enabled source site');
        }
        foreach ($targets as $target) {
            if (empty($enabled[$target])) {
                throw new \InvalidArgumentException("Target site #$target is missing or disabled");
            }
        }
    }

    /** @return array<int,int> */
    private function integerList($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        $values = array_values(array_unique(array_filter(array_map('intval', (array)$value), static fn(int $id): bool => $id > 0)));
        sort($values, SORT_NUMERIC);
        return $values;
    }

    /** @return array<int,string> */
    private function scopeList($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);
        }
        $scopes = array_values(array_unique(array_map(static fn($scope): string => strtolower(trim((string)$scope)), (array)$value)));
        foreach ($scopes as $scope) {
            if (!in_array($scope, self::SCOPES, true)) {
                throw new \InvalidArgumentException("Unsupported media replication scope $scope");
            }
        }
        sort($scopes, SORT_STRING);
        return $scopes;
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_media_replication_jobs WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed> */
    private function decodeRow(array $row): array
    {
        if (!$row) { return []; }
        $row['id'] = (int)$row['id'];
        $row['source_site_id'] = (int)$row['source_site_id'];
        $row['enabled'] = (int)$row['enabled'];
        $row['interval_minutes'] = (int)$row['interval_minutes'];
        $row['schedule'] = RecurringSchedule::fromFields([
            'schedule_json'=>(string)($row['schedule_json'] ?? ''),
            'schedule_minutes'=>$row['interval_minutes'],
        ], max(self::MIN_INTERVAL, $row['interval_minutes']), self::MIN_INTERVAL, self::MAX_INTERVAL);
        $row['schedule_json'] = RecurringSchedule::encode($row['schedule']);
        $row['schedule_description'] = RecurringSchedule::describe($row['schedule']);
        $row['changed_only'] = (int)$row['changed_only'];
        $row['retry_unreachable'] = (int)$row['retry_unreachable'];
        foreach (['next_run','last_attempt','last_success','created','updated'] as $field) { $row[$field] = (int)$row[$field]; }
        $row['scopes'] = $this->scopeList((string)$row['scopes_json']);
        $row['target_site_ids'] = $this->integerList((string)$row['targets_json']);
        $decodedResult = json_decode((string)($row['last_result_json'] ?? ''), true);
        $row['last_result'] = is_array($decodedResult) ? (new ReplicationActivityService($this->db))->describeResult($decodedResult, $row['target_site_ids']) : null;
        unset($row['scopes_json'], $row['targets_json'], $row['last_result_json']);
        return $row;
    }

    private static function ensureScheduleColumn(PDO $db): void
    {
        $sqlite = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $columns = $sqlite
            ? array_column($db->query('PRAGMA table_info(ucmp_media_replication_jobs)')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'name')
            : $db->query('SHOW COLUMNS FROM ucmp_media_replication_jobs')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('schedule_json', $columns, true)) {
            $db->exec($sqlite
                ? 'ALTER TABLE ucmp_media_replication_jobs ADD COLUMN schedule_json TEXT NULL'
                : 'ALTER TABLE ucmp_media_replication_jobs ADD COLUMN schedule_json LONGTEXT NULL AFTER interval_minutes');
        }
    }

    /** @return resource|false */
    private function lock(string $name)
    {
        if ($this->stateDir === '') { throw new \RuntimeException('Media replication state directory is missing'); }
        return ServiceFilePermissions::lock($this->stateDir . DIRECTORY_SEPARATOR . $name);
    }

    /** @param resource $lock */
    private function unlock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function emptySourceMessage(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'no managed wallpaper or ringtone files')
            || str_contains($message, 'no files were selected')
            || str_contains($message, 'source media library is empty');
    }
}
