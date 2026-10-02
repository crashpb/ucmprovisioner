<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

use PDO;

require_once __DIR__ . '/ServiceFilePermissions.php';
require_once __DIR__ . '/ReplicationActivityService.php';

/**
 * Copy Firmware-tab assets between sites, manually or on a recurring schedule.
 * Firmware packages, Cisco timezone payloads and locale companions retain their
 * hashes and compatibility metadata; activation is an explicit destination step.
 */
final class FirmwareReplicationService
{
    private const MIN_INTERVAL = 5;
    private const MAX_INTERVAL = 10080;
    private const MAX_TARGETS = 20;
    private const MAX_PACKAGES = 200;
    private const MAX_DUE_JOBS = 10;

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
                'CREATE TABLE IF NOT EXISTS ucmp_firmware_replication_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    source_site_id INTEGER NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    interval_minutes INTEGER NOT NULL DEFAULT 60,
                    schedule_json TEXT,
                    packages_json TEXT NOT NULL,
                    timezone_bundle_key TEXT,
                    activate_timezone INTEGER NOT NULL DEFAULT 0,
                    include_all_firmware INTEGER NOT NULL DEFAULT 0,
                    include_all_timezones INTEGER NOT NULL DEFAULT 0,
                    include_all_locales INTEGER NOT NULL DEFAULT 0,
                    locale_user_locale TEXT,
                    locale_network_locale TEXT,
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
            $db->exec('CREATE INDEX IF NOT EXISTS idx_ucmp_firmware_replication_due ON ucmp_firmware_replication_jobs (enabled,next_run)');
            self::ensureColumns($db);
            return;
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_firmware_replication_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                source_site_id INT NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                interval_minutes INT NOT NULL DEFAULT 60,
                schedule_json LONGTEXT NULL,
                packages_json LONGTEXT NOT NULL,
                timezone_bundle_key CHAR(64) NULL,
                activate_timezone TINYINT(1) NOT NULL DEFAULT 0,
                include_all_firmware TINYINT(1) NOT NULL DEFAULT 0,
                include_all_timezones TINYINT(1) NOT NULL DEFAULT 0,
                include_all_locales TINYINT(1) NOT NULL DEFAULT 0,
                locale_user_locale VARCHAR(64) NULL,
                locale_network_locale VARCHAR(64) NULL,
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
                UNIQUE KEY uniq_ucmp_firmware_replication_name (source_site_id,name),
                KEY idx_ucmp_firmware_replication_due (enabled,next_run)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::ensureColumns($db);
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->db->query('SELECT * FROM ucmp_firmware_replication_jobs ORDER BY enabled DESC,name,id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $editLock = $this->lock('firmware-replication-definitions.lock');
        if ($editLock === false) { throw new \RuntimeException('Another replication job edit is in progress; retry after it finishes'); }
        $jobLock = null;
        try {
            $id = max(0, (int)($input['id'] ?? 0));
            $jobLock = $id > 0 ? $this->lock('firmware-replication-job-' . $id . '.lock') : null;
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
            throw new \InvalidArgumentException('Firmware replication job name is required and must be at most 80 characters');
        }
        $source = (int)($input['source_site_id'] ?? 0);
        $targets = $this->integerList($input['target_site_ids'] ?? ($input['targets'] ?? []));
        $packages = $this->packageList(
            $input['packages'] ?? ($input['firmware_packages'] ?? ($input['selections'] ?? ($input['bundles'] ?? [])))
        );
        $timezone = $this->timezoneKey(
            $input['timezone_bundle_key'] ?? ($input['timezone_version'] ?? ($input['timezone_bundle'] ?? ''))
        );
        $includeAllFirmware = !empty($input['include_all_firmware']) ? 1 : 0;
        $includeAllTimezones = !empty($input['include_all_timezones']) ? 1 : 0;
        $includeAllLocales = !empty($input['include_all_locales']) ? 1 : 0;
        $activateTimezone = ($timezone !== '' || $includeAllTimezones) && !empty($input['activate_timezone']) ? 1 : 0;
        $localeUser = $this->localeDirectory($input['locale_user_locale'] ?? 'English_United_States', false);
        $localeNetwork = $this->localeDirectory($input['locale_network_locale'] ?? '', true);
        $fallbackInterval = (int)($input['interval_minutes'] ?? 60);
        $schedule = RecurringSchedule::fromFields($input, $fallbackInterval, self::MIN_INTERVAL, self::MAX_INTERVAL);
        $interval = RecurringSchedule::compatibilityMinutes($schedule);
        $scheduleJson = RecurringSchedule::encode($schedule);
        if (!$packages && $timezone === '' && !$includeAllFirmware && !$includeAllTimezones && !$includeAllLocales) {
            throw new \InvalidArgumentException('Choose firmware packages, retained timezone versions, locale bundles, or a complete Firmware-tab inventory scope');
        }
        if (!$targets || count($targets) > self::MAX_TARGETS) {
            throw new \InvalidArgumentException('Choose between one and twenty target sites');
        }
        if (in_array($source, $targets, true)) {
            throw new \InvalidArgumentException('The source site cannot also be a firmware replication target');
        }
        $this->assertSites($source, $targets);

        $enabled = !empty($input['enabled']) ? 1 : 0;
        $changedOnly = array_key_exists('changed_only', $input) ? (!empty($input['changed_only']) ? 1 : 0) : 1;
        $retry = array_key_exists('retry_unreachable', $input) ? (!empty($input['retry_unreachable']) ? 1 : 0) : 1;
        $packagesJson = (string)json_encode($packages, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $targetsJson = (string)json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $existing = $id > 0 ? $this->find($id) : null;
        if ($id > 0 && $existing === null) {
            throw new \InvalidArgumentException('Firmware replication job was not found');
        }
        $scheduleChanged = !$existing
            || (int)$existing['enabled'] !== $enabled
            || (string)($existing['schedule_json'] ?? '') !== $scheduleJson;
        $nextRun = $enabled
            ? ($scheduleChanged || (int)($existing['next_run'] ?? 0) < 1 ? RecurringSchedule::nextRun($schedule, $now) : (int)$existing['next_run'])
            : 0;

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE ucmp_firmware_replication_jobs
                    SET name=?,source_site_id=?,enabled=?,interval_minutes=?,schedule_json=?,packages_json=?,timezone_bundle_key=?,activate_timezone=?,
                        include_all_firmware=?,include_all_timezones=?,include_all_locales=?,locale_user_locale=?,locale_network_locale=?,targets_json=?,
                        changed_only=?,retry_unreachable=?,next_run=?,updated=?
                  WHERE id=?'
            );
            $stmt->execute([$name,$source,$enabled,$interval,$scheduleJson,$packagesJson,$timezone !== '' ? $timezone : null,$activateTimezone,
                $includeAllFirmware,$includeAllTimezones,$includeAllLocales,$localeUser,$localeNetwork !== '' ? $localeNetwork : null,
                $targetsJson,$changedOnly,$retry,$nextRun,$now,$id]);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO ucmp_firmware_replication_jobs
                    (name,source_site_id,enabled,interval_minutes,schedule_json,packages_json,timezone_bundle_key,activate_timezone,
                     include_all_firmware,include_all_timezones,include_all_locales,locale_user_locale,locale_network_locale,targets_json,changed_only,retry_unreachable,
                     next_run,last_attempt,last_success,last_status,created,updated)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,\'never\',?,?)'
            );
            $stmt->execute([$name,$source,$enabled,$interval,$scheduleJson,$packagesJson,$timezone !== '' ? $timezone : null,$activateTimezone,
                $includeAllFirmware,$includeAllTimezones,$includeAllLocales,$localeUser,$localeNetwork !== '' ? $localeNetwork : null,
                $targetsJson,$changedOnly,$retry,$nextRun,$now,$now]);
            $id = (int)$this->db->lastInsertId();
        }
        return ['ok'=>true, 'job'=>$this->decodeRow($this->find($id) ?? []), 'message'=>'Firmware replication job saved'];
    }

    /** @return array<string,mixed> */
    public function delete(int $id): array
    {
        $editLock = $this->lock('firmware-replication-definitions.lock');
        if ($editLock === false) { throw new \RuntimeException('Another replication job edit is in progress; retry after it finishes'); }
        $jobLock = null;
        try {
            $jobLock = $this->lock('firmware-replication-job-' . max(0,$id) . '.lock');
            if ($jobLock === false) { throw new \RuntimeException('This replication job is running; delete it after it finishes'); }
            return $this->deleteUnlocked($id);
        } finally {
            if (is_resource($jobLock)) { $this->unlock($jobLock); }
            $this->unlock($editLock);
        }
    }

    private function deleteUnlocked(int $id): array
    {
        if ($id < 1) { throw new \InvalidArgumentException('Choose a firmware replication job'); }
        $stmt = $this->db->prepare('DELETE FROM ucmp_firmware_replication_jobs WHERE id=?');
        $stmt->execute([$id]);
        return ['ok'=>$stmt->rowCount() === 1, 'message'=>$stmt->rowCount() === 1 ? 'Firmware replication job deleted' : 'Firmware replication job was not found'];
    }

    /** Run one job without moving its recurring cadence. */
    public function runNow(int $id): array
    {
        $job = $this->find($id);
        if (!$job) { throw new \InvalidArgumentException('Firmware replication job was not found'); }
        return $this->execute($this->decodeRow($job), false);
    }

    /** One-minute scheduler tick; only a bounded number of due jobs run. */
    public function runDue(): array
    {
        $runnerLock = $this->lock('firmware-replication-runner.lock');
        if ($runnerLock === false) {
            return ['ok'=>true,'status'=>'busy','skipped'=>true,'jobs'=>[],'message'=>'Another firmware replication scheduler is already running'];
        }
        try {
            $now = ($this->clock)();
            $stmt = $this->db->prepare(
                'SELECT * FROM ucmp_firmware_replication_jobs
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
                'message'=>$results ? count($results) . ' scheduled firmware replication job(s) processed' : 'No firmware replication jobs are due',
            ];
        } finally {
            $this->unlock($runnerLock);
        }
    }

    /** One-off exact copy used by configuration workflows. */
    public function replicateSelection(int $sourceSiteId, array $targetSiteIds, array $packages = [], string $timezoneBundleKey = '', bool $changedOnly = true, bool $activateTimezone = false, array $inventoryScope = []): array
    {
        $targets = $this->integerList($targetSiteIds);
        if (!$targets || count($targets) > self::MAX_TARGETS) { throw new \InvalidArgumentException('Choose between one and twenty target sites'); }
        if (in_array($sourceSiteId, $targets, true)) { throw new \InvalidArgumentException('The source site cannot also be a firmware replication target'); }
        $this->assertSites($sourceSiteId, $targets);
        $packages = $this->packageList($packages);
        $timezoneBundleKey = $this->timezoneKey($timezoneBundleKey);
        $allFirmware = !empty($inventoryScope['include_all_firmware']);
        $allTimezones = !empty($inventoryScope['include_all_timezones']);
        $allLocales = !empty($inventoryScope['include_all_locales']);
        if (!$packages && $timezoneBundleKey === '' && !$allFirmware && !$allTimezones && !$allLocales) { return ['ok'=>true,'status'=>'skipped','message'=>'No Firmware-tab inventory selections were made']; }
        return $this->replicate([
            'source_site_id'=>$sourceSiteId,
            'target_site_ids'=>$targets,
            'packages'=>$packages,
            'timezone_bundle_key'=>$timezoneBundleKey,
            'changed_only'=>$changedOnly,
            'activate_timezone'=>$activateTimezone,
            'include_all_firmware'=>$allFirmware,
            'include_all_timezones'=>$allTimezones,
            'include_all_locales'=>$allLocales,
            'locale_user_locale'=>$this->localeDirectory($inventoryScope['locale_user_locale'] ?? 'English_United_States', false),
            'locale_network_locale'=>$this->localeDirectory($inventoryScope['locale_network_locale'] ?? '', true),
        ]);
    }

    /** @return array<string,mixed> */
    private function execute(array $job, bool $scheduled): array
    {
        $id = (int)$job['id'];
        $jobLock = $this->lock('firmware-replication-job-' . $id . '.lock');
        if ($jobLock === false) {
            return ['ok'=>true,'busy'=>true,'status'=>'busy','job_id'=>$id,'message'=>'This firmware replication job is already running'];
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
                ? $this->ucm->trackedReplication('firmware', 'Firmware replication: ' . $job['name'],
                    ['site_id'=>$job['source_site_id'],'target_site_ids'=>$job['target_site_ids'],'id'=>$id], fn():array=>$this->replicate($job))
                : $this->replicate($job);
            $status = (string)($result['status'] ?? (!empty($result['ok']) ? 'success' : 'error'));
            $message = substr((string)($result['message'] ?? 'Firmware replication finished'), 0, 2000);
            $nextRun = (int)($job['next_run'] ?? 0);
            if ($scheduled) {
                $nextRun = empty($result['ok']) && !empty($job['retry_unreachable'])
                    ? $now + (self::MIN_INTERVAL * 60)
                    : RecurringSchedule::nextRun($job['schedule'], $now);
            }
            $encoded = (string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->db->prepare(
                'UPDATE ucmp_firmware_replication_jobs
                    SET last_attempt=?,last_success=?,last_status=?,last_message=?,last_result_json=?,next_run=?,updated=?
                  WHERE id=?'
            )->execute([$now,!empty($result['ok']) ? $now : (int)($job['last_success'] ?? 0),substr($status,0,24),$message,$encoded,$nextRun,$now,$id]);
            if (method_exists($this->ucm, 'logOperation')) {
                $this->ucm->logOperation((int)$job['source_site_id'],$scheduled ? 'firmware-replication-schedule' : 'firmware-replication-run',!empty($result['ok']) ? ($status === 'warning' ? 'warning' : 'success') : 'error',$message,$encoded);
            }
            return ['job_id'=>$id,'job_name'=>$job['name'],'scheduled'=>$scheduled] + $result;
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
                ? (!empty($job['retry_unreachable']) ? $now + (self::MIN_INTERVAL * 60) : RecurringSchedule::nextRun($job['schedule'], $now))
                : (int)($job['next_run'] ?? 0);
            $this->db->prepare(
                'UPDATE ucmp_firmware_replication_jobs
                    SET last_attempt=?,last_status=\'error\',last_message=?,last_result_json=?,next_run=?,updated=? WHERE id=?'
            )->execute([$now,substr($e->getMessage(),0,2000),json_encode(['ok'=>false,'message'=>$e->getMessage()]),$nextRun,$now,$id]);
            return ['ok'=>false,'status'=>'error','job_id'=>$id,'job_name'=>$job['name'],'scheduled'=>$scheduled,'message'=>$e->getMessage()];
        } finally {
            $this->unlock($jobLock);
        }
    }

    /** @return array<string,mixed> */
    private function replicate(array $job): array
    {
        $source = (int)$job['source_site_id'];
        $targets = (array)$job['target_site_ids'];
        $packages = (array)($job['packages'] ?? []);
        $timezone = (string)($job['timezone_bundle_key'] ?? '');
        $includeAllFirmware = !empty($job['include_all_firmware']);
        $includeAllTimezones = !empty($job['include_all_timezones']);
        $includeAllLocales = !empty($job['include_all_locales']);
        $activateTimezone = ($timezone !== '' || $includeAllTimezones) && !empty($job['activate_timezone']);
        $localeUser = $this->localeDirectory($job['locale_user_locale'] ?? 'English_United_States', false);
        $localeNetwork = $this->localeDirectory($job['locale_network_locale'] ?? '', true);
        $overwrite = empty($job['changed_only']);
        // Read each selected inventory once, before transfer, so the GUI can
        // distinguish the complete selection from one package's byte counter.
        $inventory = ($includeAllFirmware || $packages) ? $this->tftpRequest(['op'=>'firmware_inventory','site_id'=>$source]) : ['ok'=>true,'bundles'=>[]];
        $locale = ($includeAllFirmware || $packages || $includeAllLocales) ? $this->tftpRequest(['op'=>'cisco_locale_inventory','site_id'=>$source,'user_locale'=>$localeUser,'network_locale'=>$localeNetwork]) : ['ok'=>true,'profiles'=>[]];
        $versions = ($timezone !== '' || $includeAllTimezones) ? $this->tftpRequest(['op'=>'timezone_versions','site_id'=>$source]) : ['ok'=>true,'bundles'=>[]];
        if (method_exists($this->ucm,'replicationPlan')) { $this->ucm->replicationPlan($this->selectionPlan($job,$inventory,$locale,$versions)); }
        $operations = [];
        $errors = [];
        $completed = 0;
        $record = static function (array $result, array $context, bool $readOnly = false, bool $count = true) use (&$operations, &$errors, &$completed, $targets): bool {
            if (empty($result['ok']) && !isset($result['sites']) && empty($context['target_site_id'])) {
                $result += ['sites'=>array_fill_keys($context['target_site_ids'] ?? $targets, ['ok'=>false,'status'=>$readOnly ? 'error' : 'unknown','message'=>$result['message'] ?? 'No destination completion was returned'])];
            }
            $operations[] = $context + $result;
            if (!empty($result['ok'])) { if ($count) { $completed++; } return true; }
            $errors[] = ($context['scope'] ?? '') . ': ' . (string)($result['message'] ?? 'Synchronization failed');
            return false;
        };
        $sourceFailure = static function (string $scope, string $message) use ($record): void {
            $record(['ok'=>false,'message'=>$message], ['scope'=>$scope], true, false);
        };

        $copiedFirmwareProfiles = [];
        if ($includeAllFirmware || $packages) {
            if (empty($inventory['ok'])) {
                $sourceFailure('firmware inventory', (string)($inventory['message'] ?? 'Source firmware inventory is unavailable'));
            } else {
                $available = [];
                foreach ((array)($inventory['bundles'] ?? []) as $bundle) {
                    if (!is_array($bundle)) { continue; }
                    $profile = (string)($bundle['profile'] ?? '');
                    $load = (string)($bundle['load_name'] ?? '');
                    $available[strtolower($profile . "\0" . $load)] = $bundle;
                    if ($includeAllFirmware && ($bundle['status'] ?? '') === 'complete') {
                        $packages[] = ['profile'=>$profile,'load_name'=>$load];
                    }
                }
                foreach ($this->packageList($packages) as $package) {
                    $profile = (string)$package['profile'];
                    $load = (string)$package['load_name'];
                    $bundle = $available[strtolower($profile . "\0" . $load)] ?? null;
                    if (!$bundle || (string)($bundle['profile'] ?? '') !== $profile || !hash_equals((string)($bundle['load_name'] ?? ''), $load) || ($bundle['status'] ?? '') !== 'complete') {
                        $sourceFailure("$profile / $load", 'Exact complete source package is not available');
                        continue;
                    }
                    $copy = $this->tftpRequest([
                        'op'=>'sync_firmware_bundle','site_id'=>$source,'profile'=>$profile,'load_name'=>$load,
                        'target_site_ids'=>$targets,'overwrite'=>$overwrite ? 1 : 0,
                    ]);
                    $record($copy, ['scope'=>'firmware','profile'=>$profile,'load_name'=>$load]);
                    foreach ($targets as $targetId) {
                        if (!empty($copy['sites'][$targetId]['ok'])) {
                            $copiedFirmwareProfiles[$profile][$targetId] = true;
                        }
                    }
                }
            }
        }

        if ($copiedFirmwareProfiles || $includeAllLocales) {
            if (empty($locale['ok'])) {
                $sourceFailure('Cisco locale inventory', (string)($locale['message'] ?? 'Source locale inventory is unavailable'));
            } else {
                foreach ((array)($locale['profiles'] ?? []) as $localeProfile=>$state) {
                    if (!is_array($state)) { continue; }
                    $firmwareProfile = (string)($state['firmware_profile'] ?? '');
                    if ((!$includeAllLocales && !isset($copiedFirmwareProfiles[$firmwareProfile])) || ($state['status'] ?? '') !== 'complete') { continue; }
                    $copy = $this->tftpRequest([
                        'op'=>'sync_cisco_locale_bundle','site_id'=>$source,'profile'=>$localeProfile,
                        'user_locale'=>$localeUser,'network_locale'=>$localeNetwork,
                        'target_site_ids'=>$includeAllLocales ? $targets : array_keys($copiedFirmwareProfiles[$firmwareProfile]),
                        'overwrite'=>$overwrite ? 1 : 0,
                    ]);
                    $record($copy, ['scope'=>'cisco_locale','profile'=>$localeProfile,'dependency_of'=>$firmwareProfile,
                        'target_site_ids'=>$includeAllLocales ? $targets : array_keys($copiedFirmwareProfiles[$firmwareProfile])]);
                }
            }
        }

        if ($timezone !== '' || $includeAllTimezones) {
            $selectedTimezones = [];
            $sourceActiveTimezone = '';
            if (empty($versions['ok'])) {
                $sourceFailure('Cisco timezone inventory', (string)($versions['message'] ?? 'Source timezone inventory is unavailable'));
            } else {
                foreach ((array)($versions['bundles'] ?? []) as $candidate) {
                    if (!is_array($candidate)) { continue; }
                    $candidateKey = strtolower(trim((string)($candidate['bundle_key'] ?? ($candidate['fingerprint'] ?? ''))));
                    if ($candidateKey === '') { continue; }
                    if (!empty($candidate['detected_active'])) { $sourceActiveTimezone = $candidateKey; }
                    elseif ($sourceActiveTimezone === '' && !empty($candidate['active'])) { $sourceActiveTimezone = $candidateKey; }
                    if (($includeAllTimezones && $this->selectableTimezoneBundle($candidate, $candidateKey))
                        || ($timezone !== '' && hash_equals($timezone, $candidateKey))) { $selectedTimezones[$candidateKey] = $candidate; }
                }
                if ($timezone !== '' && !isset($selectedTimezones[$timezone])) { $sourceFailure('Cisco timezone', 'The exact selected Cisco timezone version is unavailable on the source site'); }
            }
            $activationTimezone = $timezone !== '' ? $timezone : $sourceActiveTimezone;
            if ($activateTimezone && ($activationTimezone === '' || !isset($selectedTimezones[$activationTimezone]))) {
                $sourceFailure('Cisco timezone activation', 'The source site has no detected active Cisco timezone set to activate on destinations');
            }
            foreach ($selectedTimezones as $timezoneKey=>$bundle) {
                $bundleId = (int)($bundle['id'] ?? 0);
                if (!$this->selectableTimezoneBundle($bundle, $timezoneKey) || $bundleId < 1) {
                    $sourceFailure('Cisco timezone ' . $timezoneKey, 'The exact retained Cisco timezone version is quarantined, contradictory, or not importable on the source site');
                    continue;
                }
                $copy = $this->tftpRequest(['op'=>'sync_timezone_version','site_id'=>$source,'bundle_id'=>$bundleId,'target_site_ids'=>$targets]);
                $record($copy, ['scope'=>'cisco_timezone','bundle_key'=>$timezoneKey,'served_version'=>(string)($bundle['served_version'] ?? '')]);
                if (!$activateTimezone || $activationTimezone === '' || !hash_equals($activationTimezone, $timezoneKey)) { continue; }
                foreach ($targets as $targetId) {
                    $target = (array)(($copy['sites'] ?? [])[(string)$targetId] ?? []);
                    // Only an explicitly successful destination copy can authorize activation.
                    if (empty($target['ok'])) { continue; }
                    try {
                        $targetBundleId = (int)($target['timezone_bundle']['bundle']['id'] ?? 0);
                        if ($targetBundleId < 1) {
                            $targetVersions = $this->tftpRequest(['op'=>'timezone_versions','site_id'=>(int)$targetId]);
                            if (empty($targetVersions['ok'])) { throw new \RuntimeException((string)($targetVersions['message'] ?? 'Destination timezone inventory is unavailable')); }
                            foreach ((array)($targetVersions['bundles'] ?? []) as $targetBundle) {
                                if (hash_equals($timezoneKey, strtolower((string)($targetBundle['bundle_key'] ?? '')))) { $targetBundleId = (int)($targetBundle['id'] ?? 0); break; }
                            }
                        }
                        if ($targetBundleId < 1) { throw new \RuntimeException("Site #$targetId did not retain the copied timezone version"); }
                        $activated = (array)$this->ucm->activateTimezoneVersion((int)$targetId, $targetBundleId);
                    } catch (\Throwable $error) {
                        $activated = ReplicationActivityService::failedOperation($error, [(int)$targetId], 'cisco_timezone_activation');
                    }
                    $record($activated, ['scope'=>'cisco_timezone_activation','target_site_id'=>(int)$targetId,'bundle_key'=>$timezoneKey], false, false);
                }
            }
        }

        $ok = !$errors;
        $status = $errors ? ($completed > 0 ? 'partial' : 'error') : ($completed > 0 ? 'success' : 'skipped');
        $notes = ['Target template firmware overrides and site defaults are never changed'];
        if (!empty($job['changed_only'])) {
            $notes[] = 'Changed-only preserves existing target package bytes and reports conflicts instead of replacing them';
            if ($status === 'success') { $status = 'warning'; }
        }
        if ($timezone !== '') { $notes[] = $activateTimezone ? 'Selected timezone activation is reported separately for each destination' : 'Selected timezone scope preserves active target files'; }
        if ($includeAllTimezones) { $notes[] = $activateTimezone ? 'Scope includes retained Cisco runtime revisions and canonical activation; see individual results' : 'Scope includes retained Cisco runtime revisions without changing destination defaults or template selections'; }
        if ($includeAllLocales) { $notes[] = 'Scope includes complete Cisco locale bundles for ' . $localeUser; }
        if ($includeAllFirmware) { $notes[] = 'Scope includes complete firmware packages in the source inventory'; }
        $summary = $errors ? implode('; ', array_values(array_unique($errors))) : ($completed > 0 ? 'Firmware replication completed' : 'No firmware selections were available');
        if ($notes) { $summary .= '. ' . implode('. ', $notes); }
        return ['ok'=>$ok,'status'=>$status,'completed_selections'=>$completed,'operations'=>$operations,'limitations'=>$notes,'message'=>$summary];
    }

    /** Inventory sizes are a selected-payload ceiling; identical destination files are skipped later. */
    private function selectionPlan(array $job, array $firmware, array $locale, array $timezones): array
    {
        $plan=['firmware_packages'=>0,'locale_packages'=>0,'timezone_packages'=>0,'selected_bytes'=>0,'sizes_complete'=>!empty($firmware['ok']) && !empty($locale['ok']) && !empty($timezones['ok']),
            'destinations'=>count($job['target_site_ids'])];
        $add=static function(array $bundle,string $kind)use(&$plan):void{
            $plan[$kind]++;$files=(array)($bundle['files']??[]);
            if (!$files) { $plan['sizes_complete']=false; }
            foreach($files as $file){$size=is_array($file)?($file['size']??$file['bytes']??null):null;if(!is_numeric($size)||(int)$size<0){$plan['sizes_complete']=false;}else{$plan['selected_bytes']+=(int)$size;}}
        };
        $selected=[];foreach((array)($job['packages']??[]) as $package){$selected[$package['profile']."\0".$package['load_name']]=true;}
        $profiles=[];$found=[];
        foreach((array)($firmware['bundles']??[]) as $bundle){
            if(!is_array($bundle)){$plan['sizes_complete']=false;continue;}
            if(($bundle['status']??'')!=='complete')continue;
            $key=($bundle['profile']??'')."\0".($bundle['load_name']??'');
            if(empty($job['include_all_firmware'])&&!isset($selected[$key]))continue;
            $found[$key]=true;$profiles[$bundle['profile']]=true;$add($bundle,'firmware_packages');
        }
        if(array_diff_key($selected,$found))$plan['sizes_complete']=false;
        foreach((array)($locale['profiles']??[]) as $bundle){
            if(!is_array($bundle)){$plan['sizes_complete']=false;continue;}
            if(($bundle['status']??'')==='complete' && (!empty($job['include_all_locales'])||isset($profiles[$bundle['firmware_profile']??''])))$add($bundle,'locale_packages');
        }
        foreach((array)($timezones['bundles']??[]) as $bundle){
            if(!is_array($bundle)){$plan['sizes_complete']=false;continue;}
            $key=strtolower(trim((string)($bundle['bundle_key']??$bundle['fingerprint']??'')));
            if($key!==''&&$this->selectableTimezoneBundle($bundle,$key)&&(!empty($job['include_all_timezones'])||($job['timezone_bundle_key']??'')===$key))$add($bundle,'timezone_packages');
        }
        $plan['selected_bytes_all_sites']=$plan['selected_bytes']*$plan['destinations'];
        return $plan;
    }

    /** Keep one failed request from discarding prior package or destination results. */
    private function tftpRequest(array $request): array
    {
        try { return (array)$this->ucm->tftpOp($request); }
        catch (\Throwable $error) {
            return ReplicationActivityService::failedOperation($error, (array)($request['target_site_ids'] ?? []),
                (string)$request['op'], !str_starts_with((string)$request['op'], 'sync_'));
        }
    }

    /** A retained archive must be non-quarantined and structurally importable. */
    private function selectableTimezoneBundle(array $bundle, string $expectedKey): bool
    {
        $status = strtolower(trim((string)($bundle['status'] ?? '')));
        if (!in_array($status, ['complete', 'partial'], true)) { return false; }
        $key = strtolower(trim((string)($bundle['bundle_key'] ?? ($bundle['fingerprint'] ?? ''))));
        if ($key === '' || !hash_equals($expectedKey, $key)) { return false; }
        $storage = trim((string)($bundle['storage_path'] ?? ''), '/');
        if (preg_match('#^\.ucmprovisioner/timezone-bundles/[a-f0-9]{64}$#D', $storage) !== 1) { return false; }
        $files = (array)($bundle['files'] ?? []);
        if (!$files) { return false; }
        foreach ($files as $file) {
            if (!is_array($file)
                || preg_match('/^[a-f0-9]{64}\.blob$/D', (string)($file['stored_name'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', strtolower((string)($file['sha256'] ?? ''))) !== 1
                || trim((string)($file['name'] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    private function assertSites(int $source, array $targets): void
    {
        $enabled = [];
        foreach ((array)$this->ucm->getSites() as $site) {
            if (!empty($site['enabled'])) { $enabled[(int)($site['id'] ?? 0)] = true; }
        }
        if ($source < 1 || empty($enabled[$source])) { throw new \InvalidArgumentException('Choose an enabled source site'); }
        foreach ($targets as $target) {
            if (empty($enabled[$target])) { throw new \InvalidArgumentException("Target site #$target is missing or disabled"); }
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

    /** @return array<int,array{profile:string,load_name:string}> */
    private function packageList($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_array($value) && array_key_exists('profile', $value)) { $value = [$value]; }
        $out = [];
        foreach ((array)$value as $item) {
            if (is_string($item)) {
                $parts = explode('|', $item, 2);
                $item = count($parts) === 2 ? ['profile'=>$parts[0], 'load_name'=>$parts[1]] : [];
            }
            if (!is_array($item)) { throw new \InvalidArgumentException('Each firmware selection must include an exact profile and load name'); }
            $profile = trim((string)($item['profile'] ?? ''));
            $load = trim(str_replace('\\', '/', (string)($item['load_name'] ?? ($item['load'] ?? ''))));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:_.-]{0,79}$/D', $profile) !== 1
                || $load === '' || str_starts_with($load, '/') || str_contains($load, '../') || str_contains($load, '/..')
                || preg_match('/[\x00-\x1f]/', $load) === 1 || strlen($load) > 255) {
                throw new \InvalidArgumentException('Each firmware selection must use a safe exact profile and load name');
            }
            $out[strtolower($profile . "\0" . $load)] = ['profile'=>$profile,'load_name'=>$load];
            if (count($out) > self::MAX_PACKAGES) { throw new \InvalidArgumentException('Choose at most two hundred exact firmware packages'); }
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int => strnatcasecmp($a['profile'] . '|' . $a['load_name'], $b['profile'] . '|' . $b['load_name']));
        return $out;
    }

    private function timezoneKey($value): string
    {
        if (is_array($value)) { $value = $value['bundle_key'] ?? ($value['fingerprint'] ?? ''); }
        $value = strtolower(trim((string)$value));
        if ($value !== '' && preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Retained Cisco timezone version must use its 64-character fingerprint');
        }
        return $value;
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_firmware_replication_jobs WHERE id=?');
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
        $row['packages'] = $this->packageList((string)($row['packages_json'] ?? '[]'));
        $row['firmware_packages'] = $row['packages'];
        $row['timezone_bundle_key'] = $this->timezoneKey($row['timezone_bundle_key'] ?? '');
        $row['activate_timezone'] = (int)($row['activate_timezone'] ?? 0);
        foreach (['include_all_firmware','include_all_timezones','include_all_locales'] as $flag) { $row[$flag] = (int)($row[$flag] ?? 0); }
        $row['locale_user_locale'] = $this->localeDirectory($row['locale_user_locale'] ?? 'English_United_States', false);
        $row['locale_network_locale'] = $this->localeDirectory($row['locale_network_locale'] ?? '', true);
        $row['target_site_ids'] = $this->integerList((string)$row['targets_json']);
        $decodedResult = json_decode((string)($row['last_result_json'] ?? ''), true);
        $row['last_result'] = is_array($decodedResult) ? (new ReplicationActivityService($this->db))->describeResult($decodedResult, $row['target_site_ids']) : null;
        unset($row['packages_json'], $row['targets_json'], $row['last_result_json']);
        return $row;
    }

    private static function ensureColumns(PDO $db): void
    {
        $sqlite = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $columns = $sqlite
            ? array_column($db->query('PRAGMA table_info(ucmp_firmware_replication_jobs)')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'name')
            : $db->query('SHOW COLUMNS FROM ucmp_firmware_replication_jobs')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('schedule_json', $columns, true)) {
            $db->exec($sqlite
                ? 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN schedule_json TEXT NULL'
                : 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN schedule_json LONGTEXT NULL AFTER interval_minutes');
        }
        if (!in_array('activate_timezone', $columns, true)) {
            $db->exec($sqlite
                ? 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN activate_timezone INTEGER NOT NULL DEFAULT 0'
                : 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN activate_timezone TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone_bundle_key');
        }
        foreach (['include_all_firmware','include_all_timezones','include_all_locales'] as $column) {
            if (!in_array($column, $columns, true)) {
                $db->exec($sqlite
                    ? "ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN $column INTEGER NOT NULL DEFAULT 0"
                    : "ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN $column TINYINT(1) NOT NULL DEFAULT 0 AFTER activate_timezone");
            }
        }
        if (!in_array('locale_user_locale', $columns, true)) {
            $db->exec($sqlite
                ? 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN locale_user_locale TEXT NULL'
                : 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN locale_user_locale VARCHAR(64) NULL AFTER include_all_locales');
        }
        if (!in_array('locale_network_locale', $columns, true)) {
            $db->exec($sqlite
                ? 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN locale_network_locale TEXT NULL'
                : 'ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN locale_network_locale VARCHAR(64) NULL AFTER locale_user_locale');
        }
    }

    private function localeDirectory($value, bool $allowEmpty): string
    {
        $value = trim((string)$value);
        if ($value === '' && $allowEmpty) { return ''; }
        if ($value === '') { $value = 'English_United_States'; }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Cisco locale directory may contain only letters, numbers, underscore and hyphen');
        }
        return $value;
    }

    /** @return resource|false */
    private function lock(string $name)
    {
        if ($this->stateDir === '') { throw new \RuntimeException('Firmware replication state directory is missing'); }
        return ServiceFilePermissions::lock($this->stateDir . DIRECTORY_SEPARATOR . $name);
    }

    /** @param resource $lock */
    private function unlock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
