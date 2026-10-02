<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

use PDO;

require_once __DIR__ . '/ServiceFilePermissions.php';
require_once __DIR__ . '/ReplicationActivityService.php';

/**
 * Synchronize named configuration objects between independently owned sites.
 *
 * This service moves policy and preserves destination-specific inheritance. It
 * records revisions, missing dependencies, destination edits and soft locks.
 * Firmware and media byte transfer remain the responsibility of their dedicated
 * replication services.
 */
final class ConfigurationSyncService
{
    public const SCOPES = [
        'templates', 'groups', 'default_templates', 'site_defaults',
        'settings_sip_service', 'settings_blf_federation', 'settings_phone_automation', 'settings_clock_date',
        'settings_network_vlan', 'settings_default_template_selectors',
        'settings_assets', 'settings_cisco_policy',
        'wallpaper_catalogs', 'firmware', 'phonebooks', 'web_directory',
    ];
    /** Settings-tab sections with stable scope names for job definitions. */
    public const SETTINGS_SCOPES = [
        'settings_sip_service', 'settings_blf_federation', 'settings_phone_automation', 'settings_clock_date',
        'settings_network_vlan', 'settings_default_template_selectors',
        'settings_assets', 'settings_cisco_policy',
    ];
    public const OBJECT_TYPES = [
        'template', 'group', 'phonebook', 'site_defaults', 'default_templates',
        'settings_sip_service', 'settings_blf_federation', 'settings_phone_automation', 'settings_clock_date',
        'settings_network_vlan', 'settings_default_template_selectors',
        'settings_assets', 'settings_cisco_policy',
        'wallpaper_catalog', 'special_catalog', 'web_directory',
    ];
    public const OBJECT_TYPE_MAX_LENGTH = 64;
    /** One field inventory for Settings save, sender, receiver and selection UI. */
    public const SETTINGS_FIELDS = [
        'settings_sip_service'=>['transport','sip_port','cisco_transport','cisco_sip_port','cisco_cnf_transport','cisco_cnf_sip_port','non_cisco_driver','non_cisco_transport','non_cisco_sip_port','proxy','voicemail_number','services_url','media_base_url','phone_services_base_url'],
        'settings_blf_federation'=>['blf_mesh_schedule_enabled','blf_mesh_interval_minutes','blf_mesh_schedule_json'],
        'settings_phone_automation'=>['phone_rebuild_schedule_enabled','phone_rebuild_interval_minutes','phone_rebuild_schedule_json','phone_rebuild_reboot'],
        'settings_clock_date'=>['ntp_server','date_template','cisco_xml_timezone','cisco_xml_olson_timezone','cisco_legacy_timezone','cisco_legacy_dst_auto_adjust','yealink_timezone'],
        'settings_network_vlan'=>['vlan_mode','vlan_voice_vid','vlan_voice_pcp','vlan_pc_port','vlan_pc_vid','vlan_pc_pcp','vlan_discovery'],
        'settings_assets'=>['firmware_defaults','cisco_timezone_defaults','cisco_wallpaper_defaults','hosted_image_defaults','default_phonebook_key','legacy_logo_url'],
        'settings_cisco_policy'=>['device_security','network_locale','background_access_disabled','phone_personalization','dial_template_file','softkey_file','featurepolicy_file'],
    ];
    public const ADDRESS_FIELDS = ['services_url','media_base_url','phone_services_base_url','legacy_logo_url'];

    public static function settingKeys(mixed $keys): array
    {
        if (!is_array($keys) || array_filter($keys, static fn($key): bool => !is_string($key))) {
            throw new \InvalidArgumentException('Settings fields must be a list of field names');
        }
        $known = array_merge(...array_values(self::SETTINGS_FIELDS));
        if (array_diff($keys, $known)) { throw new \InvalidArgumentException('Unsupported Settings field selection'); }
        $keys = array_values(array_unique($keys)); sort($keys, SORT_STRING); return $keys;
    }
    public const OBJECT_KEY_MAX_LENGTH = 160;
    public const JOB_KEY_MAX_LENGTH = 128;
    public const SOURCE_SITE_KEY_MAX_LENGTH = 64;
    public const SOURCE_SITE_NAME_MAX_LENGTH = 80;
    public const REVISION_MAX_LENGTH = 64;
    public const STATUS_MAX_LENGTH = 24;
    public const ACTOR_MAX_LENGTH = 128;
    public const ITEM_STATUSES = [
        'active', 'paused', 'conflict', 'owner_conflict',
        'missing_dependency', 'released',
    ];
    private const MAX_GROUPS = 500;
    private const MIN_INTERVAL = 5;
    private const MAX_INTERVAL = 10080;
    private const MAX_TARGETS = 20;
    private const MAX_DUE_JOBS = 5;

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
        $sqlite = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite) {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ucmp_configuration_sync_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    source_site_id INTEGER NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    interval_minutes INTEGER NOT NULL DEFAULT 60,
                    schedule_json TEXT,
                    scopes_json TEXT NOT NULL,
                    targets_json TEXT NOT NULL,
                    object_filter_json TEXT,
                    soft_lock INTEGER NOT NULL DEFAULT 1,
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
            $columns = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $db->query('PRAGMA table_info(ucmp_configuration_sync_jobs)')->fetchAll(PDO::FETCH_ASSOC) ?: []);
            if (!in_array('object_filter_json', $columns, true)) {
                $db->exec('ALTER TABLE ucmp_configuration_sync_jobs ADD COLUMN object_filter_json TEXT');
            }
            $db->exec('CREATE INDEX IF NOT EXISTS idx_ucmp_configuration_sync_due ON ucmp_configuration_sync_jobs (enabled,next_run)');
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ucmp_configuration_sync_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_key TEXT NOT NULL,
                    job_id INTEGER NOT NULL DEFAULT 0,
                    source_site_key TEXT NOT NULL,
                    source_site_name TEXT NOT NULL,
                    target_site_id INTEGER NOT NULL DEFAULT 0,
                    object_type TEXT NOT NULL,
                    object_key TEXT NOT NULL,
                    destination_id INTEGER,
                    source_revision TEXT,
                    destination_revision TEXT,
                    status TEXT NOT NULL DEFAULT \'active\',
                    soft_lock INTEGER NOT NULL DEFAULT 1,
                    intended_json TEXT,
                    missing_json TEXT,
                    paused_at INTEGER NOT NULL DEFAULT 0,
                    paused_by TEXT,
                    pause_reason TEXT,
                    last_sync INTEGER NOT NULL DEFAULT 0,
                    updated INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (job_key,target_site_id,object_type,object_key)
                )'
            );
            $db->exec('CREATE INDEX IF NOT EXISTS idx_ucmp_configuration_sync_destination ON ucmp_configuration_sync_items (target_site_id,object_type,destination_id,status)');
            $itemColumns = array_column($db->query('PRAGMA table_info(ucmp_configuration_sync_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('phone_marker_pending', $itemColumns, true)) {
                $db->exec('ALTER TABLE ucmp_configuration_sync_items ADD COLUMN phone_marker_pending INTEGER NOT NULL DEFAULT 0');
            }

            return;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_configuration_sync_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                source_site_id INT NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                interval_minutes INT NOT NULL DEFAULT 60,
                schedule_json LONGTEXT NULL,
                scopes_json LONGTEXT NOT NULL,
                targets_json LONGTEXT NOT NULL,
                object_filter_json LONGTEXT NULL,
                soft_lock TINYINT(1) NOT NULL DEFAULT 1,
                retry_unreachable TINYINT(1) NOT NULL DEFAULT 1,
                next_run INT NOT NULL DEFAULT 0,
                last_attempt INT NOT NULL DEFAULT 0,
                last_success INT NOT NULL DEFAULT 0,
                last_status VARCHAR(24) NOT NULL DEFAULT 'never',
                last_message TEXT NULL,
                last_result_json LONGTEXT NULL,
                created INT NOT NULL DEFAULT 0,
                updated INT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_ucmp_configuration_sync_name (source_site_id,name),
                KEY idx_ucmp_configuration_sync_due (enabled,next_run)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $column = $db->query("SHOW COLUMNS FROM ucmp_configuration_sync_jobs LIKE 'object_filter_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $db->exec('ALTER TABLE ucmp_configuration_sync_jobs ADD COLUMN object_filter_json LONGTEXT NULL AFTER targets_json');
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_configuration_sync_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_key VARCHAR(128) NOT NULL,
                job_id INT NOT NULL DEFAULT 0,
                source_site_key VARCHAR(64) NOT NULL,
                source_site_name VARCHAR(80) NOT NULL,
                target_site_id INT NOT NULL DEFAULT 0,
                object_type VARCHAR(64) NOT NULL,
                object_key VARCHAR(160) NOT NULL,
                destination_id INT NULL,
                source_revision VARCHAR(64) NULL,
                destination_revision VARCHAR(64) NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'active',
                soft_lock TINYINT(1) NOT NULL DEFAULT 1,
                intended_json LONGTEXT NULL,
                missing_json LONGTEXT NULL,
                paused_at INT NOT NULL DEFAULT 0,
                paused_by VARCHAR(128) NULL,
                pause_reason TEXT NULL,
                last_sync INT NOT NULL DEFAULT 0,
                updated INT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_ucmp_configuration_sync_item (job_key,target_site_id,object_type,object_key),
                KEY idx_ucmp_configuration_sync_destination (target_site_id,object_type,destination_id,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!$db->query("SHOW COLUMNS FROM ucmp_configuration_sync_items LIKE 'phone_marker_pending'")->fetch(PDO::FETCH_ASSOC)) {
            $db->exec('ALTER TABLE ucmp_configuration_sync_items ADD COLUMN phone_marker_pending TINYINT(1) NOT NULL DEFAULT 0');
        }
        $objectTypeLength = (int)$db->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ucmp_configuration_sync_items' AND COLUMN_NAME='object_type'"
        )->fetchColumn();
        if ($objectTypeLength < self::OBJECT_TYPE_MAX_LENGTH) {
            $db->exec('ALTER TABLE ucmp_configuration_sync_items MODIFY object_type VARCHAR(' . self::OBJECT_TYPE_MAX_LENGTH . ') NOT NULL');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->db->query('SELECT * FROM ucmp_configuration_sync_jobs ORDER BY enabled DESC,name,id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sites = [];
        foreach ((array)$this->ucm->getSites() as $site) {
            $sites[(int)($site['id'] ?? 0)] = (string)($site['name'] ?? ('Site #' . (int)($site['id'] ?? 0)));
        }
        foreach ($rows as &$row) {
            $row = $this->decodeJob($row);
            $row['source_site_name'] = $sites[(int)$row['source_site_id']] ?? ('Site #' . (int)$row['source_site_id']);
            $row['target_site_names'] = array_map(static fn(int $id): string => $sites[$id] ?? ('Site #' . $id), $row['target_site_ids']);
            if (is_array($row['last_result'])) {
                $row['last_result'] = (new ReplicationActivityService($this->db))->describeResult($row['last_result'], $row['target_site_ids']);
            }
            $item = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_items WHERE job_id=? AND job_key=? ORDER BY target_site_id,object_type,object_key');
            $item->execute([(int)$row['id'],$this->jobKey($row)]);
            $row['items'] = array_map([$this, 'decodeItem'], $item->fetchAll(PDO::FETCH_ASSOC) ?: []);
            // Numeric legacy keys never proved which controller created them.
            // Retain the evidence separately, without granting this job control.
            $item->execute([(int)$row['id'], 'cfg:' . (int)$row['source_site_id'] . ':' . (int)$row['id']]);
            $row['legacy_items'] = array_map([$this, 'decodeItem'], $item->fetchAll(PDO::FETCH_ASSOC) ?: []);
            $row['ownership_warning'] = $row['legacy_items'] ? 'Legacy ownership is ambiguous; recorded rows are retained read-only and require administrator reconciliation.' : '';

        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function save(array $input): array
    {
        $editLock = $this->lock('configuration-sync-definitions.lock');
        if ($editLock === false) { throw new \RuntimeException('Another replication job edit is in progress; retry after it finishes'); }
        $jobLock = null;
        try {
            $id = max(0, (int)($input['id'] ?? 0));
            if ($id === 0) { $owner = $this->findJobBySourceAndName((int)($input['source_site_id'] ?? 0), trim((string)($input['name'] ?? ''))); $id = (int)($owner['id'] ?? 0); }
            $jobLock = $id > 0 ? $this->lock('configuration-sync-job-' . $id . '.lock') : null;
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
            throw new \InvalidArgumentException('Configuration sync job name is required and must be at most 80 characters');
        }
        $source = (int)($input['source_site_id'] ?? 0);
        $targets = $this->integerList($input['target_site_ids'] ?? ($input['targets'] ?? []));
        $scopes = $this->scopeList($input['scopes'] ?? []);
        if (!$scopes) { throw new \InvalidArgumentException('Choose at least one configuration sync scope'); }
        if (!$targets || count($targets) > self::MAX_TARGETS) { throw new \InvalidArgumentException('Choose between one and twenty target sites'); }
        if (in_array($source, $targets, true)) { throw new \InvalidArgumentException('The source site cannot also be a configuration sync target'); }
        $this->assertSites($source, $targets);
        $templateIds = in_array('templates', $scopes, true)
            ? $this->integerList($input['template_ids'] ?? [])
            : [];
        if (count($templateIds) > 200) { throw new \InvalidArgumentException('Choose at most 200 templates per synchronization job'); }
        $groupIds = in_array('groups', $scopes, true)
            ? $this->integerList($input['group_ids'] ?? ($input['groups'] ?? []))
            : [];
        if (count($groupIds) > self::MAX_GROUPS) { throw new \InvalidArgumentException('Choose at most 500 groups per synchronization job'); }
        $settings = $this->settingScopeList($input['settings_categories'] ?? ($input['setting_categories'] ?? []));
        $selectedSettings = array_values(array_intersect(self::SETTINGS_SCOPES, $scopes));
        $settingsScopeSelected = (bool)$selectedSettings;
        if ($settings && !$settingsScopeSelected) {
            throw new \InvalidArgumentException('Settings categories require at least one Settings synchronization scope');
        }
        if ($settings && array_diff($settings, $selectedSettings)) {
            throw new \InvalidArgumentException('Each selected Settings category must also be enabled as a synchronization scope');
        }
        $objectFilter = [];
        if ((array_intersect(array_keys(self::SETTINGS_FIELDS),$scopes) || in_array('site_defaults',$scopes,true))
            && (array_key_exists('setting_keys', $input) || !empty($input['setting_keys_present']))) {
            $objectFilter['setting_keys'] = self::settingKeys($input['setting_keys'] ?? []);
        }
        if ($templateIds) { $objectFilter['templates'] = $templateIds; }
        if ($groupIds) { $objectFilter['groups'] = $groupIds; }
        if ($settings) { $objectFilter['settings'] = $settings; }
        elseif ($selectedSettings) { $objectFilter['settings'] = $selectedSettings; }
        if (in_array('web_directory', $scopes, true)) {
            if (empty($input['web_directory_access_confirmed'])) {
                throw new \InvalidArgumentException('Confirm that the source web-directory access policy, visible fields and export permission will apply to the selected sites');
            }
            $objectFilter['web_directory'] = ['access_confirmed'=>true, 'hard_lock'=>!empty($input['web_directory_hard_lock'])];
        }

        $fallback = (int)($input['interval_minutes'] ?? 60);
        $schedule = RecurringSchedule::fromFields($input, $fallback, self::MIN_INTERVAL, self::MAX_INTERVAL);
        $scheduleJson = RecurringSchedule::encode($schedule);
        $interval = RecurringSchedule::compatibilityMinutes($schedule);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $softLock = !empty($input['soft_lock']) ? 1 : 0;
        $retry = !empty($input['retry_unreachable']) ? 1 : 0;
        // Settings and other replication editors can submit a fresh form after
        // their saved-job list has been refreshed.  The generated source-scoped
        // name is still the durable identity in that case; reuse that row
        // instead of letting the database turn a repeat save into a raw 1062.
        $existingById = $id > 0 ? $this->findJob($id) : null;
        if ($id > 0 && !$existingById) { throw new \InvalidArgumentException('Configuration sync job was not found'); }
        $nameOwner = $this->findJobBySourceAndName($source, $name);
        if ($id > 0 && $nameOwner && (int)$nameOwner['id'] !== $id) {
            throw new \InvalidArgumentException('A configuration sync job with this name already exists for the selected source site; choose that saved job or use a different name');
        }
        if ($id === 0 && $nameOwner) {
            $ownerScopes = $this->scopeKinds($this->decodeJob($nameOwner)['scopes'] ?? []);
            if ($ownerScopes !== $this->scopeKinds($scopes)) {
                throw new \InvalidArgumentException('A different kind of configuration sync job already uses this name for the selected source site; choose that saved job or use a different name');
            }
        }
        $existing = $id > 0 ? $existingById : $nameOwner;
        if ($existing) { $id = (int)$existing['id']; }
        if ($existing) {
            $old = $this->decodeJob($existing);
            $definitionChanged = (int)$old['source_site_id'] !== $source
                || (array)$old['target_site_ids'] !== $targets
                || (array)$old['scopes'] !== $scopes
                || (array)$old['object_filter'] !== $objectFilter;
            if ($definitionChanged) {
                $oldJobKey = $this->jobKey($old);
                foreach ((array)$old['target_site_ids'] as $oldTarget) {
                    $released = (array)$this->ucm->configurationSyncRelease((int)$oldTarget, $oldJobKey);
                    if (empty($released['ok'])) {
                        throw new \RuntimeException('Cannot change the job while Site #' . (int)$oldTarget . ' still owns its previous synchronized-object locks: ' . (string)($released['message'] ?? 'release failed'));
                    }
                }
                $this->db->prepare('DELETE FROM ucmp_configuration_sync_items WHERE job_id=? AND job_key=?')->execute([$id,$oldJobKey]);
            }
        }
        $scheduleChanged = !$existing || (int)$existing['enabled'] !== $enabled || (string)($existing['schedule_json'] ?? '') !== $scheduleJson;
        $nextRun = $enabled
            ? ($scheduleChanged || (int)($existing['next_run'] ?? 0) < 1 ? RecurringSchedule::nextRun($schedule, $now) : (int)$existing['next_run'])
            : 0;
        $scopesJson = (string)json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $targetsJson = (string)json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $filterJson = (string)json_encode((object)$objectFilter, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($existing) {
            $stmt = $this->db->prepare('UPDATE ucmp_configuration_sync_jobs SET name=?,source_site_id=?,enabled=?,interval_minutes=?,schedule_json=?,scopes_json=?,targets_json=?,object_filter_json=?,soft_lock=?,retry_unreachable=?,next_run=?,updated=? WHERE id=?');
            $stmt->execute([$name,$source,$enabled,$interval,$scheduleJson,$scopesJson,$targetsJson,$filterJson,$softLock,$retry,$nextRun,$now,$id]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO ucmp_configuration_sync_jobs (name,source_site_id,enabled,interval_minutes,schedule_json,scopes_json,targets_json,object_filter_json,soft_lock,retry_unreachable,next_run,last_attempt,last_success,last_status,created,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,'never',?,?)");
            $stmt->execute([$name,$source,$enabled,$interval,$scheduleJson,$scopesJson,$targetsJson,$filterJson,$softLock,$retry,$nextRun,$now,$now]);
            $id = (int)$this->db->lastInsertId();
        }
        return ['ok'=>true, 'job'=>$this->decodeJob($this->findJob($id) ?: []), 'message'=>'Configuration sync job saved'];
    }

    /** @return array<string,mixed> */
    public function delete(int $id): array
    {
        $editLock = $this->lock('configuration-sync-definitions.lock');
        if ($editLock === false) { throw new \RuntimeException('Another replication job edit is in progress; retry after it finishes'); }
        $jobLock = null;
        try {
            $jobLock = $this->lock('configuration-sync-job-' . max(0,$id) . '.lock');
            if ($jobLock === false) { throw new \RuntimeException('This replication job is running; delete it after it finishes'); }
            return $this->deleteUnlocked($id);
        } finally {
            if (is_resource($jobLock)) { $this->unlock($jobLock); }
            $this->unlock($editLock);
        }
    }

    private function deleteUnlocked(int $id): array
    {
        if ($id < 1) { throw new \InvalidArgumentException('Choose a configuration sync job'); }
        $job = $this->findJob($id);
        if (!$job) { return ['ok'=>false, 'message'=>'Configuration sync job was not found']; }
        $decoded = $this->decodeJob($job);
        $jobKey = $this->jobKey($decoded);
        foreach ((array)$decoded['target_site_ids'] as $targetSiteId) {
            $released = (array)$this->ucm->configurationSyncRelease((int)$targetSiteId, $jobKey);
            if (empty($released['ok'])) {
                throw new \RuntimeException('Cannot delete the job until Site #' . (int)$targetSiteId . ' releases its synchronized-object locks: ' . (string)($released['message'] ?? 'release failed'));
            }
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ucmp_configuration_sync_items WHERE job_id=? AND job_key=?')->execute([$id,$jobKey]);
            $stmt = $this->db->prepare('DELETE FROM ucmp_configuration_sync_jobs WHERE id=?');
            $stmt->execute([$id]);
            $this->db->commit();
            return ['ok'=>$stmt->rowCount() === 1, 'message'=>$stmt->rowCount() === 1 ? 'Configuration sync job deleted; destination content was preserved' : 'Configuration sync job was not found'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function runNow(int $id, bool $force = false): array
    {
        $job = $this->findJob($id);
        if (!$job) { throw new \InvalidArgumentException('Configuration sync job was not found'); }
        return $this->execute($this->decodeJob($job), false, $force);
    }

    /** @return array<string,mixed> */
    public function runDue(): array
    {
        $runner = $this->lock('configuration-sync-runner.lock');
        if ($runner === false) { return ['ok'=>true,'status'=>'busy','jobs'=>[],'message'=>'Another configuration sync scheduler is already running']; }
        try {
            $now = ($this->clock)();
            $stmt = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_jobs WHERE enabled=1 AND next_run>0 AND next_run<=? ORDER BY next_run,id LIMIT ' . self::MAX_DUE_JOBS);
            $stmt->execute([$now]);
            $results = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) { $results[] = $this->execute($this->decodeJob($row), true, false); }
            $recovered = $this->refreshDependencyStates();
            $failed = array_filter($results, static fn(array $result): bool => empty($result['ok']));
            return ['ok'=>!$failed,'status'=>$failed?'partial':'success','jobs'=>$results,'dependency_states'=>$recovered,'message'=>$results ? count($results) . ' scheduled configuration sync job(s) processed' : 'No configuration sync jobs are due'];
        } finally {
            $this->unlock($runner);
        }
    }

    /** @return array<string,mixed> */
    public function resume(int $jobId, int $targetSiteId, string $objectType, string $objectKey): array
    {
        $jobLock = $this->lock('configuration-sync-job-' . max(0,$jobId) . '.lock');
        if ($jobLock === false) { throw new \RuntimeException('This replication job is running; resume the item after it finishes'); }
        try { return $this->resumeUnlocked($jobId, $targetSiteId, $objectType, $objectKey); }
        finally { $this->unlock($jobLock); }
    }

    private function resumeUnlocked(int $jobId, int $targetSiteId, string $objectType, string $objectKey): array
    {
        $objectType = self::normalizeObjectType($objectType);
        $objectKey = self::normalizeObjectKey($objectKey);
        $job = $this->findJob($jobId);
        if (!$job) { throw new \InvalidArgumentException('Configuration sync job was not found'); }
        $decoded = $this->decodeJob($job);
        if (!in_array($targetSiteId, $decoded['target_site_ids'], true)) { throw new \InvalidArgumentException('Target is not part of this configuration sync job'); }
        $jobKey = $this->jobKey($decoded);
        $result = (array)$this->ucm->configurationSyncResume($targetSiteId, $jobKey, $objectType, $objectKey);
        if (!empty($result['ok'])) {
            $stmt = $this->db->prepare("UPDATE ucmp_configuration_sync_items SET status='active',destination_revision=NULL,paused_at=0,paused_by=NULL,pause_reason=NULL,updated=? WHERE job_id=? AND job_key=? AND target_site_id=? AND object_type=? AND object_key=? AND status='paused'");
            $stmt->execute([($this->clock)(),$jobId,$jobKey,$targetSiteId,$objectType,$objectKey]);
        }
        return $result;
    }

    public function legacyOwnership(int $id, int $targetSiteId, bool $apply, string $reviewHash): array
    {
        $lock = $this->lock('configuration-sync-job-' . max(0,$id) . '.lock');
        if ($lock === false) { throw new \RuntimeException('This job is running; review ownership after it finishes'); }
        try {
            $raw = $this->findJob($id);
            if (!$raw) { throw new \InvalidArgumentException('Configuration sync job was not found'); }
            $job = $this->decodeJob($raw);
            if (!in_array($targetSiteId,$job['target_site_ids'],true)) { throw new \InvalidArgumentException('Target is not part of this job'); }
            $source = $this->ucm->getSite((int)$job['source_site_id']) ?: [];
            $key = $this->jobKey($job);
            $legacy = 'cfg:' . (int)$job['source_site_id'] . ':' . $id;
            $result = (array)$this->ucm->configurationSyncLegacyOwnership($targetSiteId, [
                'legacy_key'=>$legacy,'job_key'=>$key,'source_site_key'=>(string)($source['site_key'] ?? ''),
                'apply'=>$apply,'review_hash'=>$reviewHash,
            ]);
            if ($apply && !empty($result['ok'])) {
                // These are cached controller receipts only. Destination ownership
                // was committed first; a cache failure must retain that outcome.
                try {
                    $this->db->prepare('DELETE FROM ucmp_configuration_sync_items WHERE job_key=? AND target_site_id=? AND job_id=?')
                        ->execute([$legacy,$targetSiteId,$id]);
                } catch (\Throwable $error) { $result['reporting_error'] = 'Ownership was adopted; cached legacy receipts could not be cleared: ' . $error->getMessage(); }
            }
            return $result;
        } finally { $this->unlock($lock); }
    }

    /** @return array<string,mixed> */
    private function execute(array $job, bool $scheduled, bool $force): array
    {
        $id = (int)$job['id'];
        $jobLock = $this->lock('configuration-sync-job-' . $id . '.lock');
        if ($jobLock === false) { return ['ok'=>true,'status'=>'busy','job_id'=>$id,'message'=>'This configuration sync job is already running']; }
        try {
            $now = ($this->clock)();
            // A save can finish between scheduler selection and lock acquisition.
            $current = $this->findJob($id);
            if (!$current) { return ['ok'=>true,'status'=>'skipped','job_id'=>$id,'message'=>'The job was deleted before execution']; }
            $job = $this->decodeJob($current);
            if ($scheduled && (empty($job['enabled']) || (int)$job['next_run'] < 1 || (int)$job['next_run'] > $now)) {
                return ['ok'=>true,'status'=>'skipped','job_id'=>$id,'message'=>'The job is no longer due'];
            }
            $result = $this->synchronize($job, $force && !$scheduled);
            $result = (new ReplicationActivityService($this->db))->describeResult($result, $job['target_site_ids']);
            $status = (string)($result['status'] ?? (!empty($result['ok']) ? 'success' : 'error'));
            $message = substr((string)($result['message'] ?? 'Configuration synchronization finished'), 0, 2000);
            $next = (int)$job['next_run'];
            if ($scheduled) {
                $next = empty($result['ok']) && !empty($job['retry_unreachable'])
                    ? $now + (self::MIN_INTERVAL * 60)
                    : RecurringSchedule::nextRun($job['schedule'], $now);
            }
            $this->db->prepare('UPDATE ucmp_configuration_sync_jobs SET last_attempt=?,last_success=?,last_status=?,last_message=?,last_result_json=?,next_run=?,updated=? WHERE id=?')->execute([
                $now, !empty($result['ok']) ? $now : (int)$job['last_success'], substr($status,0,24), $message,
                json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $next, $now, $id,
            ]);
            if (method_exists($this->ucm, 'logOperation')) {
                $this->ucm->logOperation((int)$job['source_site_id'], $scheduled?'configuration-sync-schedule':'configuration-sync-run', !empty($result['ok'])?'success':'error', $message, (string)json_encode($result, JSON_UNESCAPED_SLASHES));
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
           $next = $scheduled
                ? (!empty($job['retry_unreachable']) ? $now + (self::MIN_INTERVAL * 60) : RecurringSchedule::nextRun($job['schedule'],$now))
                : (int)$job['next_run'];
            $this->db->prepare("UPDATE ucmp_configuration_sync_jobs SET last_attempt=?,last_status='error',last_message=?,last_result_json=?,next_run=?,updated=? WHERE id=?")->execute([$now,substr($e->getMessage(),0,2000),json_encode(['ok'=>false,'message'=>$e->getMessage()]),$next,$now,$id]);
            return ['ok'=>false,'status'=>'error','job_id'=>$id,'job_name'=>$job['name'],'scheduled'=>$scheduled,'message'=>$e->getMessage()];
        } finally {
            $this->unlock($jobLock);
        }
    }

    /** @return array<string,mixed> */
    private function synchronize(array $job, bool $force): array
    {
        $sourceId = (int)$job['source_site_id'];
        $scopes = (array)$job['scopes'];
        $targets = (array)$job['target_site_ids'];
        try {
            $snapshot = (array)$this->ucm->configurationSyncSnapshot($sourceId, $scopes, (array)($job['object_filter'] ?? []));
            if (empty($snapshot['ok'])) { throw new \RuntimeException((string)($snapshot['message'] ?? 'Source configuration could not be read')); }
            $this->assertSnapshotFilters($job, $snapshot);
        } catch (\Throwable $error) {
            return ReplicationActivityService::failedOperation($error, $targets, 'source configuration', true);
        }
        $jobKey = $this->jobKey($job);
        $newScopes = array_values(array_intersect(array_merge(['groups','web_directory'], self::SETTINGS_SCOPES), $scopes));
        $requiresAcknowledgement = $newScopes || array_key_exists('setting_keys',(array)($snapshot['object_filter'] ?? []));
        $options = [
            'job_key'=>$jobKey,
            'job_id'=>(int)$job['id'],
            'source_site_key'=>(string)($snapshot['source_site']['site_key'] ?? $sourceId),
            'source_site_name'=>(string)($snapshot['source_site']['name'] ?? ('Site #' . $sourceId)),
            'soft_lock'=>!empty($job['soft_lock']),
            'force'=>$force,
        ];
        $assetScopes = array_intersect(['wallpaper_catalogs','firmware','settings_assets','site_defaults'], $scopes);
        $assets = ['ok'=>true,'operations'=>[],'items'=>[],'warnings'=>[],'targets'=>[],'needs_confirmation'=>false];
        $targetResults = [];
        $errors = [];
        $warnings = [];
        foreach ($targets as $targetId) {
            $stage = 'capabilities';
            $assetResult = null;
            try {
                // Qualify each destination before changing it. One unavailable site
                // must not prevent the other qualified sites from completing.
                if ($requiresAcknowledgement) {
                    if (!method_exists($this->ucm, 'configurationSyncCapabilities')) {
                        throw new \RuntimeException('Destination cannot acknowledge group/granular Settings synchronization; upgrade it before copying policy');
                    }
                    $capabilities = (array)$this->ucm->configurationSyncCapabilities((int)$targetId);
                    if (array_key_exists('setting_keys', (array)($snapshot['object_filter'] ?? []))
                        && empty($capabilities['object_filter']['setting_keys'])) {
                        throw new \RuntimeException('Destination needs an update to acknowledge individual Settings fields; no settings were copied');
                    }
                    $missing = array_values(array_diff($newScopes, $this->scopeList($capabilities['scopes'] ?? [])));
                    if ((int)($capabilities['schema'] ?? 0) !== 1 || $missing) {
                        throw new \RuntimeException('Destination does not acknowledge requested group/granular Settings scopes'
                            . ($missing ? ': ' . implode(', ', $missing) : '') . '; upgrade it before copying policy');
                    }
                }
                $stage = 'assets';
                $assetResult = (array)$this->ucm->configurationSyncAssets($sourceId, [(int)$targetId], $scopes, $options + ['snapshot'=>$snapshot]);
                $assets['targets'][(string)$targetId] = $assetResult;
                $assets['operations'] = array_merge($assets['operations'], (array)($assetResult['operations'] ?? []));
                $assets['items'][(string)$targetId] = (array)(($assetResult['items'] ?? [])[(string)$targetId] ?? []);
                $assets['ok'] = $assets['ok'] && !empty($assetResult['ok']);
                $assets['needs_confirmation'] = $assets['needs_confirmation'] || !empty($assetResult['needs_confirmation']);
                if (!$assetScopes && empty($assetResult['ok'])) {
                    $warnings[] = 'Site #' . $targetId . ': ' . (string)($assetResult['message'] ?? 'One or more managed assets could not be copied');
                }
                foreach ((array)($assetResult['warnings'] ?? []) as $warning) {
                    $warnings[] = 'Site #' . $targetId . ': ' . (string)$warning;
                }
                $uncertainAssets = false;
                foreach (new \RecursiveIteratorIterator(new \RecursiveArrayIterator((array)($assetResult['operations'] ?? []))) as $key=>$value) {
                    if (($key === 'status' && in_array($value,['unknown','unresolved','recovery_required'],true))
                        || ($key === 'recovery_required' && $value)) { $uncertainAssets = true; break; }
                }
                if ($assetScopes && ($uncertainAssets || (!empty($assetResult['needs_confirmation']) && !$force))) {
                    $confirmation = !empty($assetResult['needs_confirmation']);
                    $applied = [
                        'ok'=>$confirmation && !$uncertainAssets,'status'=>$uncertainAssets ? 'unknown' : ($confirmation ? 'conflict' : 'error'),
                        'recovery_required'=>$uncertainAssets,
                        'needs_confirmation'=>$confirmation,
                        'message'=>(string)($assetResult['message'] ?? 'Managed dependencies could not be synchronized') . '; destination policy was not changed',
                    ];
                } else {
                    $stage = 'policy';
                    $applied = (array)$this->ucm->configurationSyncApply((int)$targetId, $snapshot, $options + [
                        'web_directory'=>(array)($job['object_filter']['web_directory'] ?? []),
                    ]);
                    if ($requiresAcknowledgement) {
                        try { $this->assertDestinationAcknowledgement((int)$targetId, $snapshot, $applied); }
                        catch (\Throwable $error) {
                            $applied = ['ok'=>false,'status'=>'unknown','message'=>$error->getMessage(),'unacknowledged_result'=>$applied];
                        }
                    }
                    if ($assetScopes && empty($assetResult['ok']) && !empty($applied['ok'])) {
                        // Retain safe destination fallbacks and intended policy even
                        // when files are absent, so a later copy can satisfy it.
                        $applied['ok'] = false;
                        $applied['status'] = 'partial';
                        $applied['message'] = 'Configuration intent retained; asset copy incomplete: '
                            . (string)($assetResult['message'] ?? 'Dependencies are unavailable')
                            . '. Waiting dependencies retry automatically when they arrive.';
                    }
                }
                $applied['assets'] = $assetResult;
                $targetResults[(string)$targetId] = $applied;
                // Tracking is controller bookkeeping; failure cannot undo a receipt.
                try {
                    $this->recordItems((int)$job['id'], (int)$targetId, $jobKey,
                        array_merge($assets['items'][(string)$targetId], (array)($applied['items'] ?? [])));
                } catch (\Throwable $error) {
                    $warning = 'Site #' . $targetId . ': saving ownership records failed: ' . $error->getMessage();
                    $warnings[] = $warning;
                    $targetResults[(string)$targetId]['reporting_error'] = $error->getMessage();
                }
                if (empty($applied['ok'])) { $errors[] = 'Site #' . $targetId . ': ' . (string)($applied['message'] ?? 'apply failed'); }
                elseif (!empty($applied['warnings']) || !empty($applied['needs_confirmation'])) {
                    $warnings[] = 'Site #' . $targetId . ': ' . (string)($applied['message'] ?? 'completed with warnings');
                }
            } catch (\Throwable $error) {
                $failure = ReplicationActivityService::failedOperation($error, [(int)$targetId], $stage, $stage === 'capabilities');
                if ($assetResult !== null) { $failure['assets'] = $assetResult; }
                $targetResults[(string)$targetId] = $failure;
                $errors[] = 'Site #' . $targetId . ': ' . $error->getMessage();
            }
        }
        $assets['warnings'] = $warnings;
        $ok = !$errors;
        $status = $errors ? (count($errors) < count($targets) ? 'partial' : 'error') : ($warnings ? 'warning' : 'success');
        return [
            'ok'=>$ok,'status'=>$status,
            'needs_confirmation'=>$assets['needs_confirmation']
                || (bool)array_filter($targetResults, static fn(array $row): bool => !empty($row['needs_confirmation'])),
            'assets'=>$assets,'targets'=>$targetResults,'warnings'=>$warnings,
            'message'=>$errors ? implode('; ', $errors) : ($warnings ? 'Configuration synchronized with destination fallbacks or paused items' : 'Configuration synchronized; destination-specific PBX settings were preserved'),
        ];
    }

    /** Ensure a new-protocol destination applied exactly the requested view. */
    private function assertDestinationAcknowledgement(int $targetId, array $snapshot, array $applied): void
    {
        $expectedScopes = $this->scopeList($snapshot['scopes'] ?? []);
        $actualScopes = $this->scopeList($applied['scopes'] ?? []);
        if ($actualScopes !== $expectedScopes) {
            throw new \RuntimeException('Site #' . $targetId . ' did not acknowledge all requested configuration sync scopes; no destination result was accepted');
        }
        $expectedFilter = is_array($snapshot['object_filter'] ?? null) ? $snapshot['object_filter'] : [];
        $actualFilter = is_array($applied['object_filter'] ?? null) ? $applied['object_filter'] : [];
        if (array_key_exists('setting_keys', $expectedFilter)
            && (!array_key_exists('setting_keys', $actualFilter) || self::settingKeys($expectedFilter['setting_keys']) !== self::settingKeys($actualFilter['setting_keys']))) {
            throw new \RuntimeException('Destination did not acknowledge the selected Settings fields');
        }
        $expectedTemplates = $this->integerList($expectedFilter['templates'] ?? []);
        $actualTemplates = $this->integerList($actualFilter['templates'] ?? []);
        $expectedGroups = $this->integerList($expectedFilter['groups'] ?? ($expectedFilter['group_ids'] ?? []));
        $actualGroups = $this->integerList($actualFilter['groups'] ?? ($actualFilter['group_ids'] ?? []));
        $expectedSettings = $this->settingScopeList($expectedFilter['settings'] ?? ($expectedFilter['settings_categories'] ?? []));
        $actualSettings = $this->settingScopeList($actualFilter['settings'] ?? ($actualFilter['settings_categories'] ?? []));
        if ($actualTemplates !== $expectedTemplates || $actualGroups !== $expectedGroups || $actualSettings !== $expectedSettings) {
            throw new \RuntimeException('Site #' . $targetId . ' did not acknowledge the selected configuration sync filters; no destination result was accepted');
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    /** Refresh current waiting badges; historical run receipts remain historical. */
    private function refreshDependencyStates(): array
    {
        if (!method_exists($this->ucm,'configurationSyncStates') || !method_exists($this->ucm,'localSiteId')) { return []; }
        $query = $this->db->prepare("SELECT * FROM ucmp_configuration_sync_items WHERE status='missing_dependency' AND target_site_id<>? ORDER BY updated,id LIMIT 100");
        $query->execute([$this->ucm->localSiteId()]);
        $cache = []; $errors = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $waiting) {
            $site = (int)$waiting['target_site_id']; $type = (string)$waiting['object_type'];
            $key = $site . ':' . $type;
            try {
                if (!array_key_exists($key,$cache)) {
                    $cache[$key] = [];
                    $cache[$key] = (array)$this->ucm->configurationSyncStates($site,$type);
                }
                foreach ($cache[$key] as $item) {
                    if (($item['job_key'] ?? '') === $waiting['job_key'] && ($item['object_key'] ?? '') === $waiting['object_key']) {
                        $this->recordItems((int)$waiting['job_id'],$site,(string)$waiting['job_key'],[$item]);
                        break;
                    }
                }
            } catch (\Throwable $error) { $errors[$key] = 'Site #' . $site . ': dependency state unavailable'; }
            finally {
                $this->db->prepare('UPDATE ucmp_configuration_sync_items SET updated=? WHERE id=?')->execute([($this->clock)(),(int)$waiting['id']]);
            }
        }
        return ['errors'=>array_values($errors)];
    }

    private function recordItems(int $jobId, int $targetSiteId, string $jobKey, array $items): void
    {
        $now = ($this->clock)();
        foreach ($items as $item) {
            $item['job_key'] = $jobKey;
            $tracked = self::normalizeTrackingItem($item);
            $type = $tracked['object_type'];
            $key = $tracked['object_key'];
            $values = [
                'job_key'=>$tracked['job_key'], 'job_id'=>$jobId, 'source_site_key'=>$tracked['source_site_key'],
                'source_site_name'=>$tracked['source_site_name'], 'target_site_id'=>$targetSiteId,
                'object_type'=>$type, 'object_key'=>$key, 'destination_id'=>(int)($item['destination_id'] ?? 0) ?: null,
                'source_revision'=>$tracked['source_revision'], 'destination_revision'=>$tracked['destination_revision'],
                'status'=>$tracked['status'], 'soft_lock'=>!empty($item['soft_lock'])?1:0,
                'intended_json'=>json_encode($item['intended'] ?? null, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'missing_json'=>json_encode($item['missing'] ?? [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'paused_at'=>(int)($item['paused_at'] ?? 0), 'paused_by'=>$tracked['paused_by'],
                'pause_reason'=>(string)($item['pause_reason'] ?? ''), 'last_sync'=>(int)($item['last_sync'] ?? $now), 'updated'=>$now,
            ];
            $existing = $this->db->prepare('SELECT id FROM ucmp_configuration_sync_items WHERE job_key=? AND target_site_id=? AND object_type=? AND object_key=?');
            $existing->execute([$jobKey,$targetSiteId,$type,$key]);
            $id = (int)$existing->fetchColumn();
            if ($id > 0) {
                $this->db->prepare('UPDATE ucmp_configuration_sync_items SET job_id=?,source_site_key=?,source_site_name=?,destination_id=?,source_revision=?,destination_revision=?,status=?,soft_lock=?,intended_json=?,missing_json=?,paused_at=?,paused_by=?,pause_reason=?,last_sync=?,updated=? WHERE id=?')->execute([
                    $jobId,$values['source_site_key'],$values['source_site_name'],$values['destination_id'],$values['source_revision'],$values['destination_revision'],$values['status'],$values['soft_lock'],$values['intended_json'],$values['missing_json'],$values['paused_at'],$values['paused_by'],$values['pause_reason'],$values['last_sync'],$now,$id,
                ]);
            } else {
                $this->db->prepare('INSERT INTO ucmp_configuration_sync_items (job_key,job_id,source_site_key,source_site_name,target_site_id,object_type,object_key,destination_id,source_revision,destination_revision,status,soft_lock,intended_json,missing_json,paused_at,paused_by,pause_reason,last_sync,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($values));
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function findJob(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_jobs WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findJobBySourceAndName(int $sourceSiteId, string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_jobs WHERE source_site_id=? AND name=? LIMIT 1');
        $stmt->execute([$sourceSiteId, $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed> */
    private function decodeJob(array $row): array
    {
        if (!$row) { return []; }
        $row['id'] = (int)$row['id'];
        $row['source_site_id'] = (int)$row['source_site_id'];
        $row['enabled'] = !empty($row['enabled']);
        $row['soft_lock'] = !empty($row['soft_lock']);
        $row['retry_unreachable'] = !empty($row['retry_unreachable']);
        $row['target_site_ids'] = $this->integerList((string)($row['targets_json'] ?? '[]'));
        $row['scopes'] = $this->scopeList((string)($row['scopes_json'] ?? '[]'));
        $filter = json_decode((string)($row['object_filter_json'] ?? '{}'), true);
        $row['object_filter'] = is_array($filter) ? $filter : [];
        $row['setting_keys'] = array_key_exists('setting_keys', $row['object_filter']) ? self::settingKeys($row['object_filter']['setting_keys']) : null;
        $row['web_directory_hard_lock'] = !empty($row['object_filter']['web_directory']['hard_lock']);
        $row['web_directory_access_confirmed'] = !empty($row['object_filter']['web_directory']['access_confirmed']);
        $row['template_ids'] = $this->integerList($row['object_filter']['templates'] ?? []);
        $row['group_ids'] = $this->integerList($row['object_filter']['groups'] ?? ($row['object_filter']['group_ids'] ?? []));
        $row['settings_categories'] = $this->settingScopeList($row['object_filter']['settings'] ?? ($row['object_filter']['settings_categories'] ?? []));
        $row['schedule'] = RecurringSchedule::fromFields(['schedule_json'=>(string)($row['schedule_json'] ?? ''),'schedule_minutes'=>(int)($row['interval_minutes'] ?? 60)], (int)($row['interval_minutes'] ?? 60), self::MIN_INTERVAL, self::MAX_INTERVAL);
        $row['schedule_json'] = RecurringSchedule::encode($row['schedule']);
        $row['schedule_description'] = RecurringSchedule::describe($row['schedule']);
        $decodedResult = json_decode((string)($row['last_result_json'] ?? ''), true);
        $row['last_result'] = is_array($decodedResult) ? $decodedResult : null;
        unset($row['targets_json'],$row['scopes_json'],$row['object_filter_json'],$row['last_result_json']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function decodeItem(array $row): array
    {
        foreach (['id','job_id','target_site_id','destination_id','paused_at','last_sync','updated'] as $key) { $row[$key] = (int)($row[$key] ?? 0); }
        $row['soft_lock'] = !empty($row['soft_lock']);
        $row['intended'] = json_decode((string)($row['intended_json'] ?? ''), true);
        $row['missing'] = json_decode((string)($row['missing_json'] ?? ''), true) ?: [];
        unset($row['intended_json'],$row['missing_json']);
        return $row;
    }

    private function jobKey(array $job): string
    {
        $site = $this->ucm->getSite($this->ucm->localSiteId()) ?: [];
        $key = (string)($site['site_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $key)) {
            throw new \RuntimeException('The controlling PBX requires a stable site key before configuration synchronization');
        }
        $prefix = in_array('web_directory', (array)($job['scopes'] ?? []), true) ? 'cfgw:' : 'cfgc:';
        return $prefix . $key . ':' . (int)$job['id'];
    }

    public static function normalizeObjectType(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::OBJECT_TYPE_MAX_LENGTH || !in_array($value, self::OBJECT_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported synchronized object type');
        }
        return $value;
    }

    public static function normalizeObjectKey(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::OBJECT_KEY_MAX_LENGTH) {
            throw new \InvalidArgumentException('Synchronized object key is empty or exceeds ' . self::OBJECT_KEY_MAX_LENGTH . ' characters');
        }
        return $value;
    }

    /** Normalize every bounded string before a tracking row reaches SQL. */
    public static function normalizeTrackingItem(array $item): array
    {
        $jobKey = self::boundedText((string)($item['job_key'] ?? ''), self::JOB_KEY_MAX_LENGTH, 'job key');
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/D', $jobKey)) {
            throw new \InvalidArgumentException('Synchronized job key contains unsupported characters');
        }
        $siteKey = self::boundedText((string)($item['source_site_key'] ?? ''), self::SOURCE_SITE_KEY_MAX_LENGTH, 'source site key');
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $siteKey)) {
            throw new \InvalidArgumentException('Synchronized source site key contains unsupported characters');
        }
        $siteName = trim((string)($item['source_site_name'] ?? '')) ?: $siteKey;
        $siteName = self::boundedText($siteName, self::SOURCE_SITE_NAME_MAX_LENGTH, 'source site name');
        $sourceRevision = self::boundedText((string)($item['source_revision'] ?? ''), self::REVISION_MAX_LENGTH, 'source revision', true);
        $destinationRevision = self::boundedText((string)($item['destination_revision'] ?? ''), self::REVISION_MAX_LENGTH, 'destination revision', true);
        $status = self::boundedText((string)($item['status'] ?? 'active'), self::STATUS_MAX_LENGTH, 'status');
        if (!in_array($status, self::ITEM_STATUSES, true)) {
            throw new \InvalidArgumentException('Unsupported synchronized item status');
        }
        $pausedBy = self::boundedText((string)($item['paused_by'] ?? ''), self::ACTOR_MAX_LENGTH, 'paused-by identity', true);
        return [
            'job_key'=>$jobKey,
            'source_site_key'=>$siteKey,
            'source_site_name'=>$siteName,
            'object_type'=>self::normalizeObjectType((string)($item['object_type'] ?? '')),
            'object_key'=>self::normalizeObjectKey((string)($item['object_key'] ?? '')),
            'source_revision'=>$sourceRevision,
            'destination_revision'=>$destinationRevision,
            'status'=>$status,
            'paused_by'=>$pausedBy,
        ];
    }

    public static function assertControllableJobKey(string $value): void
    {
        if (preg_match('/^cfg:[0-9]+:[0-9]+$/D', $value)) {
            throw new \RuntimeException('Legacy numeric configuration ownership is ambiguous; upgrade the controller and reconcile ownership before applying, resuming or releasing it');
        }
    }

    public static function normalizeJobKey(string $value): string
    {
        $value = self::boundedText($value, self::JOB_KEY_MAX_LENGTH, 'job key');
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/D', $value)) {
            throw new \InvalidArgumentException('Synchronized job key contains unsupported characters');
        }
        return $value;
    }

    private static function boundedText(string $value, int $limit, string $label, bool $allowEmpty = false): string
    {
        $value = trim($value);
        if ((!$allowEmpty && $value === '') || strlen($value) > $limit) {
            throw new \InvalidArgumentException("Synchronized $label is empty or exceeds $limit characters");
        }
        return $value;
    }

    /** @return array<int,string> Stable editor families used when safely reusing a source-scoped job name. */
    private function scopeKinds(array $scopes): array
    {
        $kinds = [];
        foreach ($scopes as $scope) {
            if (in_array($scope, self::SETTINGS_SCOPES, true)) { $kinds[] = 'settings'; continue; }
            $kinds[] = (string)$scope;
        }
        $kinds = array_values(array_unique($kinds));
        sort($kinds, SORT_STRING);
        return $kinds;
    }

    /** @return array<int,int> */
    private function integerList(mixed $value): array
    {
        if (is_string($value)) { $value = json_decode($value, true) ?: preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY); }
        $out = array_values(array_unique(array_filter(array_map('intval', is_array($value)?$value:[]), static fn(int $id): bool => $id > 0)));
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /** @return array<int,string> */
    private function scopeList(mixed $value): array
    {
        if (is_string($value)) { $value = json_decode($value, true) ?: preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY); }
        $aliases = [
            'settings_general'=>'settings_sip_service',
            'settings_sip_service_defaults'=>'settings_sip_service',
            'settings_sip'=>'settings_sip_service',
            'settings_blf'=>'settings_blf_federation',
            'settings_blf_federation_schedule'=>'settings_blf_federation',
            'settings_federation'=>'settings_blf_federation',
            'settings_phones'=>'settings_phone_automation',
            'settings_rebuild'=>'settings_phone_automation',
            'settings_clock'=>'settings_clock_date',
            'settings_clock_and_date'=>'settings_clock_date',
            'settings_time'=>'settings_clock_date',
            'settings_network'=>'settings_network_vlan',
            'settings_default_network_vlan_intent'=>'settings_network_vlan',
            'settings_vlan'=>'settings_network_vlan',
            'settings_defaults'=>'settings_assets',
            'settings_default_templates'=>'settings_default_template_selectors',
            'settings_firmware_wallpaper_phonebook_defaults'=>'settings_assets',
            'settings_media'=>'settings_assets',
            'settings_device'=>'settings_cisco_policy',
            'settings_cisco_xml_device_policy'=>'settings_cisco_policy',
            'settings_cisco'=>'settings_cisco_policy',
        ];
        $normalized = array_map(static fn(string $scope): string => $aliases[$scope] ?? $scope, array_map('strval', is_array($value)?$value:[]));
        $out = array_values(array_unique(array_intersect(self::SCOPES, $normalized)));
        sort($out, SORT_STRING);
        return $out;
    }

    /** @return array<int,string> */
    private function settingScopeList(mixed $value): array
    {
        if (is_string($value)) { $value = json_decode($value, true) ?: preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY); }
        $aliases = [
            'general'=>'settings_sip_service', 'settings_general'=>'settings_sip_service', 'sip'=>'settings_sip_service', 'settings_sip'=>'settings_sip_service', 'sip_service'=>'settings_sip_service', 'settings_sip_service_defaults'=>'settings_sip_service',
            'blf'=>'settings_blf_federation', 'settings_blf'=>'settings_blf_federation', 'federation'=>'settings_blf_federation', 'settings_federation'=>'settings_blf_federation', 'blf_federation'=>'settings_blf_federation', 'settings_blf_federation_schedule'=>'settings_blf_federation',
            'phones'=>'settings_phone_automation', 'phone_automation'=>'settings_phone_automation', 'settings_phones'=>'settings_phone_automation', 'settings_rebuild'=>'settings_phone_automation',
            'clock'=>'settings_clock_date', 'settings_clock'=>'settings_clock_date', 'time'=>'settings_clock_date', 'settings_time'=>'settings_clock_date', 'clock_date'=>'settings_clock_date', 'settings_clock_and_date'=>'settings_clock_date',
            'network'=>'settings_network_vlan', 'settings_network'=>'settings_network_vlan', 'vlan'=>'settings_network_vlan', 'settings_vlan'=>'settings_network_vlan', 'network_vlan'=>'settings_network_vlan', 'settings_default_network_vlan_intent'=>'settings_network_vlan',
            'default_template_selectors'=>'settings_default_template_selectors', 'settings_default_templates'=>'settings_default_template_selectors', 'selectors'=>'settings_default_template_selectors',
            'assets'=>'settings_assets', 'settings_assets'=>'settings_assets', 'media'=>'settings_assets', 'settings_media'=>'settings_assets', 'firmware_wallpaper_phonebook'=>'settings_assets', 'settings_firmware_wallpaper_phonebook_defaults'=>'settings_assets',
            'cisco'=>'settings_cisco_policy', 'settings_cisco'=>'settings_cisco_policy', 'device_policy'=>'settings_cisco_policy', 'settings_device'=>'settings_cisco_policy', 'cisco_policy'=>'settings_cisco_policy', 'settings_cisco_xml_device_policy'=>'settings_cisco_policy',
        ];
        $normalized = [];
        foreach (array_map('strval', is_array($value)?$value:[]) as $scope) {
            $normalized[] = $aliases[$scope] ?? $scope;
        }
        $out = array_values(array_unique(array_intersect(self::SETTINGS_SCOPES, $normalized)));
        sort($out, SORT_STRING);
        return $out;
    }

    /** Fail closed when an older source silently ignores a selected object filter. */
    private function assertSnapshotFilters(array $job, array $snapshot): void
    {
        $expectedScopes = $this->scopeList($job['scopes'] ?? []);
        $actualScopes = $this->scopeList($snapshot['scopes'] ?? []);
        if ($actualScopes !== $expectedScopes) {
            throw new \RuntimeException(
                'The source PBX did not acknowledge all requested configuration sync scopes. Upgrade that site before using this synchronization job; no destination was changed.'
            );
        }
        $filter = is_array($snapshot['object_filter'] ?? null) ? $snapshot['object_filter'] : [];
        $requested = is_array($job['object_filter'] ?? null) ? $job['object_filter'] : [];
        if (array_key_exists('setting_keys', $requested)
            && (!array_key_exists('setting_keys', $filter) || self::settingKeys($requested['setting_keys']) !== self::settingKeys($filter['setting_keys']))) {
            throw new \RuntimeException('Source needs an update to acknowledge individual Settings fields; no destination was changed');
        }
        foreach (['templates'=>'selected-template', 'groups'=>'selected-group'] as $key=>$label) {
            $expected = $this->integerList($requested[$key] ?? ($key === 'groups' ? ($requested['group_ids'] ?? []) : []));
            if (!$expected) { continue; }
            $actual = $this->integerList($filter[$key] ?? ($key === 'groups' ? ($filter['group_ids'] ?? []) : []));
            if ($actual !== $expected) {
                throw new \RuntimeException(
                    'The source PBX did not acknowledge the ' . $label . ' filter. Upgrade that site before using a selected-' . ($key === 'templates' ? 'template' : 'group') . ' push; no destination was changed.'
                );
            }
        }
        $expectedSettings = $this->settingScopeList($requested['settings'] ?? ($requested['settings_categories'] ?? []));
        if ($expectedSettings) {
            $actualSettings = $this->settingScopeList($filter['settings'] ?? ($filter['settings_categories'] ?? []));
            if ($actualSettings !== $expectedSettings) {
                throw new \RuntimeException(
                    'The source PBX did not acknowledge the selected Settings categories. Upgrade that site before using granular Settings synchronization; no destination was changed.'
                );
            }
        }
    }

    private function assertSites(int $source, array $targets): void
    {
        $enabled = [];
        foreach ((array)$this->ucm->getSites() as $site) { if (!empty($site['enabled'])) { $enabled[(int)($site['id'] ?? 0)] = true; } }
        if (!isset($enabled[$source])) { throw new \InvalidArgumentException('Choose an enabled source site'); }
        foreach ($targets as $target) { if (!isset($enabled[$target])) { throw new \InvalidArgumentException("Target site #$target is missing or disabled"); } }
    }

    /** @return resource|false */
    private function lock(string $name)
    {
        if ($this->stateDir === '') { throw new \RuntimeException('Configuration sync state directory is missing'); }
        return ServiceFilePermissions::lock($this->stateDir . '/' . $name);
    }

    /** @param resource|false $handle */
    private function unlock($handle): void
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }
}
