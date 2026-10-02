<?php
/**
 * Directory authority, synchronization and phonebook compiler.
 *
 * Each configured AD domain is an independent source of truth. Accepted source
 * revisions update logical extension identities, while phonebooks compile those
 * identities plus audited overrides into site-local, phone-facing snapshots.
 * A failed source read leaves the last accepted data intact.
 */
namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/AssetFilename.php';

require_once __DIR__ . '/ServiceFilePermissions.php';
require_once __DIR__ . '/RpcOperationException.php';

class DirectoryService
{
    private $ucm;
    /** @var array<int,string> */
    private array $lastReadWarnings = [];
    private array $lastPublicationResult = [];

    public function lastPublicationResult(): array { return $this->lastPublicationResult; }

    private const DEFAULTS = [
        'ad_enabled' => '0',
        'ad_host' => '',
        'ad_port' => '636',
        'ad_security' => 'ldaps',
        'ad_base_dn' => '',
        'ad_bind_user' => '',
        'ad_user_filter' => '(&(objectCategory=person)(objectClass=user))',
        'ad_attr_display' => 'displayName',
        'ad_attr_extension' => 'telephoneNumber',
        'ad_attr_label' => 'sAMAccountName',
        'ad_attr_department' => 'department',
        'ad_attr_company' => 'company',
        'ad_attr_mail' => 'mail',
        'ad_attr_mobile' => 'mobile',
        'ad_attr_pager' => 'pager',
        'ad_extra_attributes' => '[]',
        'ad_site_rules' => '[]',
        'ad_sync_interval' => '15',
        'ad_sync_schedule_json' => '',
        'ad_sync_names' => '1',
        'ad_sync_labels' => '1',
        'ad_notification_email' => '',
        'directory_title' => 'Company Directory',
        'directory_group_by' => 'department',
        'directory_name_format' => 'display_label',
        'directory_sort_by' => 'display_name',
        'directory_include_mobile' => '1',
        'directory_cisco_page_size' => '32',
    ];

    public function __construct($ucm, private string $cacheDir = '/var/lib/asterisk/ucmprovisioner/directory') { $this->ucm = $ucm; }

    public function settings(bool $includePassword = false): array
    {
        $stored = $this->ucm->getSiteSettingsLocal($this->ucm->localSiteId());
        $out = self::DEFAULTS;
        foreach ($out as $key => $_) {
            if (array_key_exists($key, $stored)) { $out[$key] = (string)$stored[$key]; }
        }
        $out['ad_password_saved'] = !empty($stored['ad_bind_password']);
        if ($includePassword) {
            $out['ad_bind_password'] = $this->ucm->dec((string)($stored['ad_bind_password'] ?? ''));
        }
        return $out;
    }

    /** Runtime-only health; never includes a bind password or filesystem path. */
    public function sourceRuntimeStatus(array $source): array
    {
        $secret = $this->sourceSecretStatus($source);
        $schedule = $this->sourceScheduleState($source);
        $enabled = !empty($source['enabled']);
        $health = !$enabled
            ? 'disabled'
            : (!$secret['configured']
                ? 'credential_missing'
                : (!empty($source['last_error']) && (int)($source['last_attempt'] ?? 0) >= (int)($source['last_success'] ?? 0)
                    ? 'error'
                    : ((int)($source['last_success'] ?? 0) > 0 ? 'healthy' : 'never_run')));
        return [
            'secret_reference_set'=>trim((string)($source['secret_ref'] ?? '')) !== '',
            'secret_configured'=>$secret['configured'],
            'secret_error'=>$secret['error'],
            'credential_updated'=>$secret['updated'],
            'health'=>$health,
            'failure_streak'=>$schedule['failure_streak'],
            'backoff_seconds'=>$schedule['backoff_seconds'],
            'next_run'=>$schedule['next_run'],
            'due'=>$schedule['due'],
        ];
    }

    /** Provision or rotate the external credential object for a registered source. */
    public function saveSourceSecret(int $sourceId, array $input): array
    {
        $db = $this->ucm->getDb();
        $stmt = $db->prepare('SELECT * FROM ucmp_directory_sources WHERE id=? LIMIT 1');
        $stmt->execute([$sourceId]);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$source) { throw new \InvalidArgumentException('Directory source not found'); }
        $sourceKey = (string)$source['source_key'];
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $sourceKey)) {
            throw new \RuntimeException('Directory source key is invalid');
        }
        if ($this->isCompatibilitySource($source)) {
            throw new \InvalidArgumentException('Upgrade legacy-default in place before rotating it as a managed credential');
        }
        if (!hash_equals('file:' . $sourceKey, trim((string)($source['secret_ref'] ?? '')))) {
            throw new \RuntimeException('The source does not reference its managed external credential object');
        }
        $bindUser = trim((string)($input['bind_user'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $saved = $this->writeSourceSecret($sourceKey, $bindUser, $password);
        return ['ok'=>true,'source_id'=>$sourceId,'source_key'=>$sourceKey,'configured'=>true,'updated'=>$saved['updated'],'message'=>'External directory credential was provisioned or rotated'];
    }

    /**
     * Move legacy-default to the managed source workflow without changing its
     * row, identity namespace, accepted revisions or logical bindings.
     */
    public function migrateLegacySource(int $sourceId): array
    {
        $db = $this->ucm->getDb();
        $stmt = $db->prepare('SELECT * FROM ucmp_directory_sources WHERE id=? LIMIT 1');
        $stmt->execute([$sourceId]);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($source && ($source['source_key'] ?? '') === 'legacy-default' && ($source['secret_ref'] ?? '') === 'file:legacy-default') {
            $secret = $this->sourceSecretStatus($source);
            if (empty($secret['configured']) && !empty($source['enabled'])) { throw new \RuntimeException('Managed directory credential needs recovery'); }
            return ['ok'=>true,'source_id'=>$sourceId,'source_key'=>'legacy-default','configured'=>$secret['configured'],'message'=>'Directory credential storage is current'];
        }
        if (!$source || !$this->isCompatibilitySource($source)) {
            throw new \InvalidArgumentException('This directory source is not an upgradeable legacy-default compatibility source');
        }
        // Storage conversion does not connect to AD or change the saved transport.
        // New source creation still enforces LDAPS/StartTLS independently.
        $settings = $this->settings(true);
        $bindUser = trim((string)($settings['ad_bind_user'] ?? ''));
        $password = (string)($settings['ad_bind_password'] ?? '');
        $storedSettings = $this->ucm->getSiteSettingsLocal($this->ucm->localSiteId());
        $storedPassword = (string)($storedSettings['ad_bind_password'] ?? '');
        $unconfigured = empty($source['enabled']) && $bindUser === '' && $storedPassword === '';
        if (!$unconfigured && ($bindUser === '' || $password === '')) {
            throw new \RuntimeException('Existing AD reader credentials are incomplete or cannot be decrypted; original storage retained');
        }
        $nextUpdated = max(time(), (int)($source['updated'] ?? 0) + 1);
        $begun = false;
        try {
            if ($db->inTransaction()) { throw new \RuntimeException('Directory credential upgrade requires its own transaction'); }
            $db->beginTransaction();
            $begun = true;
            $update = $db->prepare(
                "UPDATE ucmp_directory_sources
                    SET secret_ref='file:legacy-default',updated=?
                  WHERE id=? AND source_key='legacy-default'
                    AND secret_ref='legacy-setting:ad_bind_password'"
                . ' AND updated=?'
            );
            $update->execute([$nextUpdated,$sourceId,(int)($source['updated'] ?? 0)]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('The legacy directory source changed in another session; reload before upgrading');
            }
            // An interrupted upgrade can have published the matching file while
            // the database rolled back. Verify and reuse it, never overwrite it.
            $managedSource = array_replace($source, ['secret_ref'=>'file:legacy-default']);
            $path = $this->secretRoot(true) . DIRECTORY_SEPARATOR . 'legacy-default.json';
            if (!$unconfigured) {
                if (!is_file($path) && !is_link($path)) { $this->writeSourceSecret('legacy-default', $bindUser, $password); }
                $saved = $this->readSourceSecret($managedSource);
                if (!hash_equals($bindUser, $saved['bind_user']) || !hash_equals($password, $saved['password'])) {
                    throw new \RuntimeException('Existing directory credential file disagrees with legacy storage; both retained for recovery');
                }
            } elseif (file_exists($path) || is_link($path)) {
                throw new \RuntimeException('Unconfigured legacy source has an unexpected credential file; original storage retained');
            }
            $delete=$db->prepare("DELETE FROM ucmp_settings WHERE site_id=? AND skey='ad_bind_password' AND sval=?");
            $delete->execute([$this->ucm->localSiteId(),$storedPassword]);
            if (!$unconfigured && $delete->rowCount() !== 1) { throw new \RuntimeException('AD credential changed during upgrade; original storage retained'); }
            if (!$db->commit()) { throw new \RuntimeException('Directory credential commit was not confirmed; matching file and recovery backup retained'); }
            $begun = false;
        } catch (\Throwable $e) {
            if ($begun && $db->inTransaction()) { $db->rollBack(); }
            // Keep any verified file even when commit outcome is uncertain. The
            // next upgrade can reconcile either the old or the committed reference.
            throw $e;
        } finally {
            unset($password, $settings, $storedPassword, $storedSettings, $saved);
        }
        return [
            'ok'=>true,
            'source_id'=>$sourceId,
            'source_key'=>'legacy-default',
            'secret_ref'=>'file:legacy-default',
            'configured'=>!$unconfigured,
            'updated'=>$nextUpdated,
            'message'=>'legacy-default was upgraded in place; source history, accepted revisions, mappings and identity overrides were preserved',
        ];
    }

    /** @return array{path:string,updated:int} */
    private function writeSourceSecret(string $sourceKey, string $bindUser, string $password): array
    {
        if ($bindUser === '' || mb_strlen($bindUser, 'UTF-8') > 512 || preg_match('/[\x00-\x1F\x7F]/', $bindUser)) {
            throw new \InvalidArgumentException('Bind user must be between 1 and 512 printable characters');
        }
        if ($password === '' || strlen($password) > 4096 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('Bind password must be between 1 and 4096 bytes and cannot contain NUL');
        }
        $directory = $this->secretRoot(true);
        $path = $directory . DIRECTORY_SEPARATOR . $sourceKey . '.json';
        if (is_link($path)) { throw new \RuntimeException('Refusing to replace a symbolic-link credential object'); }
        $now = time();
        $payload = json_encode([
            'version'=>1,
            'source_key'=>$sourceKey,
            'bind_user'=>$bindUser,
            'password'=>$password,
            'updated'=>$now,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, '.secret-');
        if ($temporary === false) { throw new \RuntimeException('Could not allocate an external credential staging file'); }
        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new \RuntimeException('Could not write the complete external credential object');
            }
            if (!@chmod($temporary, 0600)) {
                throw new \RuntimeException('Could not restrict the external credential object to mode 0600');
            }
            if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0
                && (!@chown($temporary, 'asterisk') || !@chgrp($temporary, 'asterisk'))) {
                throw new \RuntimeException('Could not assign the directory credential to the service account');
            }
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException('Could not atomically publish the external credential object');
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
        return ['path'=>$path,'updated'=>$now];
    }

    /** Test one registered source through its external credential and ordered DC failover list. */
    public function testSource(int $sourceId): array
    {
        $stmt = $this->ucm->getDb()->prepare('SELECT * FROM ucmp_directory_sources WHERE id=? LIMIT 1');
        $stmt->execute([$sourceId]);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$source) { throw new \InvalidArgumentException('Directory source not found'); }
        $settings = $this->sourceSettings($source);
        $entries = $this->readUsers($this->connectSource($settings), $settings, 25);
        return [
            'ok'=>true,
            'source_id'=>$sourceId,
            'source_key'=>(string)$source['source_key'],
            'message'=>sprintf('%s bind and schema-valid sample search succeeded; %d phone-bearing user(s) sampled.', (string)$source['source_key'], count($entries)),
            'warnings'=>$this->lastReadWarnings,
            'sample'=>array_slice(array_map(static fn(array $entry): array => [
                'display_name'=>$entry['display_name'],'label'=>$entry['label'],'extension'=>$entry['extension'],'org_unit'=>$entry['org_unit'],
            ], $entries), 0, 10),
        ];
    }

    public function save(array $input): void
    {
        $values = is_array($input['directory'] ?? null) ? $input['directory'] : $input;
        $siteId = $this->ucm->localSiteId();
        $db = $this->ucm->getDb();
        $compatibility = $db->prepare(
            "SELECT id FROM ucmp_directory_sources
              WHERE source_key='legacy-default'
                AND secret_ref='legacy-setting:ad_bind_password'
              LIMIT 1"
        );
        $compatibility->execute();
        $compatibilitySource = $compatibility->fetchColumn() !== false;
        $updates = [];
        $choices = [
            'ad_enabled'=>['0','1'],
            'ad_security'=>['ldap','starttls','ldaps'],
            'ad_sync_names'=>['0','1'],
            'ad_sync_labels'=>['0','1'],
            'directory_group_by'=>['department','site_name','company','org_unit','none'],
            'directory_name_format'=>['display','display_label','label_display','label','display_extension'],
            'directory_sort_by'=>['display_name','label','extension'],
            'directory_include_mobile'=>['0','1'],
            'directory_cisco_page_size'=>['16','24','32'],
        ];
        $requiredAttributes = ['ad_attr_display', 'ad_attr_extension', 'ad_attr_label'];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (!array_key_exists($key, $values)) { continue; }
            if ($key === 'ad_sync_schedule_json') {
                $schedule = RecurringSchedule::normalize(
                    $values[$key],
                    max(5, (int)($values['ad_sync_interval'] ?? 15)),
                    5
                );
                $value = RecurringSchedule::encode($schedule);
                $updates['ad_sync_interval'] = (string)RecurringSchedule::compatibilityMinutes($schedule);
            } elseif ($key === 'ad_extra_attributes') {
                $value = json_encode($this->normalizeExtraAttributes($values[$key]),JSON_UNESCAPED_SLASHES);
            } elseif ($key === 'ad_site_rules') {
                $siteKeys = array_values(array_filter(array_map(
                    static fn(array $site): string => (string)($site['site_key'] ?? ''),
                    $this->ucm->getSites()
                )));
                $rules = DirectorySiteMapper::normalizeRules($values[$key], $siteKeys);
                foreach ($rules as &$rule) { unset($rule['_order']); }
                unset($rule);
                $value = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } else {
                $value = trim((string)$values[$key]);
            }
            if (isset($choices[$key]) && !in_array($value, $choices[$key], true)) { throw new \InvalidArgumentException("Invalid $key value"); }
            if (in_array($key, $requiredAttributes, true) && $value === '') { throw new \InvalidArgumentException("$key cannot be empty"); }
            if (strpos($key, 'ad_attr_') === 0 && $value !== '' && !$this->validAttribute($value)) { throw new \InvalidArgumentException("Invalid LDAP attribute name for $key"); }
            if ($key === 'ad_port' && ((int)$value < 1 || (int)$value > 65535)) { throw new \InvalidArgumentException('LDAP port must be between 1 and 65535'); }
            if ($key === 'ad_sync_interval' && ((int)$value !== 0 && ((int)$value < 5 || (int)$value > 10080))) { throw new \InvalidArgumentException('Directory interval must be manual or between 5 minutes and 7 days'); }
            if ($key === 'directory_title' && ($value === '' || mb_strlen($value, 'UTF-8') > 64)) { throw new \InvalidArgumentException('Phonebook title must be between 1 and 64 characters'); }
            $updates[$key] = $value;
        }
        $encryptedPassword = null;
        if (!empty($values['ad_bind_password'])) {
            if (!$compatibilitySource) {
                throw new \InvalidArgumentException('Rotate managed directory credentials from the selected domain authority, not the shared identity-policy form');
            }
            $encryptedPassword = $this->ucm->enc((string)$values['ad_bind_password']);
        }
        $current = $this->settings(false);
        foreach ($updates as $key => $value) { $current[$key] = $value; }
        $sourceValues = null;
        if ($compatibilitySource) {
            $domain = $this->dnsDomain((string)$current['ad_base_dn']) ?: 'legacy-directory';
            $endpoint = trim((string)$current['ad_host']) !== ''
                ? [[
                    'host'=>trim((string)$current['ad_host']),
                    'port'=>(int)$current['ad_port'],
                ]]
                : [];
            $attributeMap = [
                'display_name'=>$current['ad_attr_display'],
                'extension'=>$current['ad_attr_extension'],
                'label'=>$current['ad_attr_label'],
                'department'=>$current['ad_attr_department'],
                'company'=>$current['ad_attr_company'],
                'mail'=>$current['ad_attr_mail'],
                'mobile'=>$current['ad_attr_mobile'],
                'pager'=>$current['ad_attr_pager'],
            ];
            $sourceValues = [
                $domain,
                $current['ad_enabled'] === '1' ? 1 : 0,
                json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $current['ad_security'],
                $current['ad_base_dn'],
                $current['ad_user_filter'],
                json_encode($attributeMap, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $current['ad_site_rules'],
                (int)$current['ad_sync_interval'],
                $current['ad_sync_schedule_json'],
                time(),
            ];
        }
        $db->beginTransaction();
        try {
            foreach ($updates as $key => $value) {
                $this->ucm->setSetting($key, $value, $siteId);
            }
            if ($encryptedPassword !== null) {
                $this->ucm->setSetting('ad_bind_password', $encryptedPassword, $siteId);
            }
            if ($sourceValues !== null) {
                $db->prepare(
                    "UPDATE ucmp_directory_sources
                        SET domain=?,enabled=?,endpoints_json=?,tls_mode=?,base_dn=?,filter=?,
                            attribute_map_json=?,site_rules_json=?,schedule_minutes=?,schedule_json=?,updated=?
                      WHERE source_key='legacy-default'
                        AND secret_ref='legacy-setting:ad_bind_password'"
                )->execute($sourceValues);
            }
            if (!$db->commit()) { throw new \RuntimeException('Directory settings commit was not confirmed; reload before retrying'); }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    public function test(array $input = []): array
    {
        $settings = $this->mergedSettings($input);
        $conn = $this->connect($settings, (string)($input['ad_admin_user'] ?? ''), (string)($input['ad_admin_pass'] ?? ''));
        $entries = $this->readUsers($conn, $settings, 25);
        return [
            'ok' => true,
            'message' => sprintf('LDAP bind and search succeeded; %d enabled phone-bearing user(s) sampled.', count($entries)),
            'sample' => array_slice(array_map(static fn($e) => ['display_name'=>$e['display_name'], 'label'=>$e['label'], 'extension'=>$e['extension'], 'org_unit'=>$e['org_unit']], $entries), 0, 10),
        ];
    }

    /** Read the AD container hierarchy for the visual base/container picker. */
    public function tree(array $input = []): array
    {
        $settings = $this->mergedSettings($input);
        $conn = $this->connect($settings, (string)($input['ad_admin_user'] ?? ''), (string)($input['ad_admin_pass'] ?? ''), false);
        $base = trim((string)($settings['ad_base_dn'] ?? ''));
        if ($base === '') {
            $rootSearch = @ldap_read($conn, '', '(objectClass=*)', ['defaultNamingContext', 'namingContexts']);
            $rootRows = $rootSearch ? ldap_get_entries($conn, $rootSearch) : [];
            $base = trim((string)($rootRows[0]['defaultnamingcontext'][0] ?? $rootRows[0]['namingcontexts'][0] ?? ''));
        }
        if ($base === '') { throw new \RuntimeException('AD did not return a default naming context; enter the domain base DN once'); }
        $filter = '(|(objectClass=organizationalUnit)(objectClass=container)(objectClass=domainDNS))';
        $search = @ldap_search($conn, $base, $filter, ['ou','cn','name','objectClass'], 0, 2000, 20);
        if (!$search) { throw new \RuntimeException('Could not browse the AD hierarchy: ' . ldap_error($conn)); }
        $entries = ldap_get_entries($conn, $search);
        $nodes = [];
        for ($i = 0; $i < (int)($entries['count'] ?? 0); $i++) {
            $entry = $entries[$i];
            $dn = trim((string)($entry['dn'] ?? ''));
            if ($dn === '') { continue; }
            $classes = array_map('strtolower', array_values(array_filter((array)($entry['objectclass'] ?? []), 'is_string')));
            $type = in_array('organizationalunit', $classes, true) ? 'OU' : (in_array('domaindns', $classes, true) ? 'Domain' : 'Container');
            $label = trim((string)($entry['ou'][0] ?? $entry['cn'][0] ?? $entry['name'][0] ?? $dn));
            $nodes[] = ['dn'=>$dn, 'parent'=>$this->parentDn($dn), 'label'=>$label, 'type'=>$type, 'depth'=>substr_count($dn, ',')];
        }
        usort($nodes, static fn($a, $b) => ($a['depth'] <=> $b['depth']) ?: strcasecmp($a['dn'], $b['dn']));
        return ['ok'=>true, 'message'=>sprintf('Loaded %d AD container(s). Select one instead of typing a DN.', count($nodes)), 'base_dn'=>$base, 'nodes'=>$nodes];
    }

    public function sync(bool $manual = false, int $sourceId = 0): array
    {
        $db = $this->ucm->getDb();
        $driver = (string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $runnerLock = 'ucmp-directory-' . max(1, (int)$this->ucm->localSiteId()) . '-runner';
        if ($driver !== 'sqlite') {
            $lock = $db->prepare('SELECT GET_LOCK(?,0)');
            $lock->execute([$runnerLock]);
            if ((int)$lock->fetchColumn() !== 1) {
                return ['ok'=>true,'skipped'=>true,'status'=>'busy','message'=>'Another authoritative AD sync is already running','sources'=>[]];
            }
        }
        try {
            $this->recoverStaleRuns();
            if ($sourceId > 0) {
                $stmt = $db->prepare('SELECT * FROM ucmp_directory_sources WHERE id=? LIMIT 1');
                $stmt->execute([$sourceId]);
                $sources = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } else {
                $sql = $manual
                    ? "SELECT * FROM ucmp_directory_sources WHERE enabled=1 OR source_key='legacy-default' ORDER BY id"
                    : 'SELECT * FROM ucmp_directory_sources WHERE enabled=1 ORDER BY id';
                $sources = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
            if (!$sources) {
                return ['ok'=>true,'skipped'=>true,'status'=>'idle','message'=>'No enabled directory source is due for execution','sources'=>[]];
            }

            $results = [];
            foreach ($sources as $source) {
                try {
                    $results[] = $this->syncOneSource($source, $manual);
                } catch (\Throwable $e) {
                    $error = mb_substr(trim($e->getMessage()), 0, 4000, 'UTF-8');
                    $results[] = [
                        'ok'=>false,
                        'accepted'=>false,
                        'status'=>'failed',
                        'source_id'=>(int)($source['id'] ?? 0),
                        'source_key'=>(string)($source['source_key'] ?? ''),
                        'previous_revision'=>(string)($source['last_accepted_revision'] ?? ''),
                        'message'=>$error,
                    ];
                    try {
                        $db->prepare(
                            'UPDATE ucmp_directory_sources
                                SET last_attempt=?,last_error=?,updated=?
                              WHERE id=?'
                        )->execute([time(), $error, time(), (int)($source['id'] ?? 0)]);
                    } catch (\Throwable $ignored) {
                        // The per-source result still records the failure for this invocation.
                    }
                }
            }
            $accepted = count(array_filter($results, static fn(array $result): bool => !empty($result['accepted'])));
            $unknown = count(array_filter($results, static fn(array $result): bool => ($result['status'] ?? '') === 'unknown'));
            $failed = count(array_filter($results, static fn(array $result): bool => empty($result['ok']) && empty($result['skipped']) && ($result['status'] ?? '') !== 'unknown'));
            $skipped = count(array_filter($results, static fn(array $result): bool => !empty($result['skipped'])));
            $deliveryPartial = count(array_filter($results, static fn(array $result): bool => !empty($result['partial'])));
            $partial = $failed > 0 || $deliveryPartial > 0 || $unknown > 0;
            $status = $unknown > 0 ? ($accepted > 0 ? 'partial' : 'unknown') : ($failed > 0
                ? ($accepted > 0 ? 'partial' : 'failed')
                : ($deliveryPartial > 0 ? 'accepted_partial' : ($accepted > 0 ? 'accepted' : 'idle')));
            return [
                'ok'=>$failed === 0 && $unknown === 0,
                'partial'=>$partial,
                'status'=>$status,
                'accepted_sources'=>$accepted,
                'failed_sources'=>$failed,
                'unknown_sources'=>$unknown,
                'skipped_sources'=>$skipped,
                'sources'=>$results,
                'message'=>sprintf(
                    'Directory run %s: %d source revision(s) accepted, %d failed, %d skipped%s.',
                    $status,
                    $accepted,
                    $failed,
                    $skipped,
                    ($deliveryPartial > 0 ? ", {$deliveryPartial} accepted revision(s) have queued or warning delivery results" : '')
                        . ($unknown > 0 ? ", {$unknown} acceptance outcome(s) require reconciliation" : '')
                ),
            ];
        } finally {
            if ($driver !== 'sqlite') {
                try {
                    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$runnerLock]);
                } catch (\Throwable $ignored) {
                    // Connection teardown releases the named lock.
                }
            }
        }
    }

    /** Compatibility entry point retained for the migrated source and older callers/tests. */
    private function syncAuthoritative(bool $manual): array
    {
        $source = $this->ucm->getDb()->query(
            "SELECT * FROM ucmp_directory_sources WHERE source_key='legacy-default' LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$source) {
            throw new \RuntimeException('The migrated AD directory source is missing; run the module schema upgrade first');
        }
        return $this->syncOneSource($source, $manual);
    }

    protected function syncOneSource(array $source, bool $manual): array
    {
        $sourceKey = (string)($source['source_key'] ?? '');
        if (empty($source['enabled']) && !($manual && $sourceKey === 'legacy-default')) {
            return ['ok'=>true,'skipped'=>true,'status'=>'disabled','source_id'=>(int)$source['id'],'source_key'=>$sourceKey,'message'=>'Directory source is disabled'];
        }
        $schedule = $this->sourceScheduleState($source);
        if (!$manual && empty($schedule['due'])) {
            return [
                'ok'=>true,'skipped'=>true,'status'=>'scheduled','source_id'=>(int)$source['id'],'source_key'=>$sourceKey,
                'next_run'=>$schedule['next_run'],'backoff_seconds'=>$schedule['backoff_seconds'],
                'message'=>$schedule['next_run'] > 0 ? 'Directory source is not due until ' . date('c', $schedule['next_run']) : 'Directory source is manual-only',
            ];
        }

        $db = $this->ucm->getDb();
        $driver = (string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $lockName = 'ucmp-directory-' . max(1, (int)$this->ucm->localSiteId()) . '-source-' . (int)$source['id'];
        if ($driver !== 'sqlite') {
            $lock = $db->prepare('SELECT GET_LOCK(?,0)');
            $lock->execute([$lockName]);
            if ((int)$lock->fetchColumn() !== 1) {
                return ['ok'=>true,'skipped'=>true,'status'=>'busy','source_id'=>(int)$source['id'],'source_key'=>$sourceKey,'message'=>'This directory source is already running'];
            }
        }
        try {
            return $this->acceptSourceRevision($source + ['_force_identity_refresh'=>$manual]);
        } finally {
            if ($driver !== 'sqlite') {
                try {
                    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$lockName]);
                } catch (\Throwable $ignored) {
                    // Connection teardown releases the source lock.
                }
            }
        }
    }

    protected function acceptSourceRevision(array $source): array
    {
        $db = $this->ucm->getDb();
        $sourceId = (int)$source['id'];
        $sourceKey = (string)$source['source_key'];
        $started = time();
        $db->prepare(
            "INSERT INTO ucmp_directory_sync_runs (source_id,started,status) VALUES (?,?,'running')"
        )->execute([$sourceId, $started]);
        $runId = (int)$db->lastInsertId();
        try {
            $settings = $this->sourceSettings($source);
            $conn = $this->connectSource($settings);
            $users = $this->readUsers($conn, $settings);
            $readWarnings = $this->lastReadWarnings;
        } catch (\Throwable $e) {
            $error = mb_substr(trim($e->getMessage()), 0, 4000, 'UTF-8');
            $db->prepare(
                "UPDATE ucmp_directory_sync_runs SET finished=?,status='failed',error=? WHERE id=?"
            )->execute([time(), $error, $runId]);
            $db->prepare(
                'UPDATE ucmp_directory_sources SET last_attempt=?,last_error=?,updated=? WHERE id=?'
            )->execute([time(), $error, time(), $sourceId]);
            return [
                'ok'=>false,'accepted'=>false,'status'=>'failed','source_id'=>$sourceId,'source_key'=>$sourceKey,
                'previous_revision'=>(string)($source['last_accepted_revision'] ?? ''),
                'message'=>$error,
            ];
        }

        try {
        $sites = $this->ucm->getSites();
        $siteIdByKey = [];
        $siteKeyById = [];
        foreach ($sites as $site) {
            $key = trim((string)($site['site_key'] ?? ''));
            if ($key === '') { continue; }
            $siteIdByKey[$key] = (int)$site['id'];
            $siteKeyById[(int)$site['id']] = $key;
        }
        $rules = DirectorySiteMapper::normalizeRules(
            $settings['ad_site_rules'] ?? '[]',
            array_keys($siteIdByKey)
        );
        $legacySiteMap = ($sourceKey === 'legacy-default' && !$rules)
            ? $this->logicalExtensionSiteMap()
            : [];

        $revisionRows = [];
        foreach ($users as $user) {
            $revisionRows[(string)$user['source_key']] = $this->identityDigest($user);
        }
        ksort($revisionRows, SORT_STRING);
        $sourceRevision = hash(
            'sha256',
            json_encode($revisionRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        foreach ($users as &$user) {
            $user['_object_key'] = (string)$user['source_key'];
        }
        unset($user);
        $currentRows = $db->prepare(
            'SELECT object_key,display_name,label,extension,department,company,org_unit,mail,mobile,pager,source_dn,attributes
               FROM ucmp_directory_entries WHERE source_id=?'
        );
        $currentRows->execute([$sourceId]);
        $currentDigests = [];
        foreach ($currentRows->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $attributes = json_decode((string)($row['attributes'] ?? ''), true);
            $row['attributes'] = is_array($attributes) ? $attributes : [];
            $row['dn'] = (string)($row['source_dn'] ?? '');
            $currentDigests[(string)$row['object_key']] = $this->identityDigest($row);
        }
        $added = count(array_diff_key($revisionRows, $currentDigests));
        $deleted = count(array_diff_key($currentDigests, $revisionRows));
        $changed = 0;
        foreach (array_intersect_key($revisionRows, $currentDigests) as $key => $digest) {
            if (!hash_equals((string)$currentDigests[$key], (string)$digest)) { $changed++; }
        }

        $existingResolutions = [];
        $resolutionRows = $db->prepare(
            'SELECT conflict_key,resolution_key FROM ucmp_directory_conflicts WHERE source_id=?'
        );
        $resolutionRows->execute([$sourceId]);
        foreach ($resolutionRows->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $existingResolutions[(string)$row['conflict_key']] = (string)($row['resolution_key'] ?? '');
        }

        $conflicts = [];
        foreach ($users as &$user) {
            $retainedSiteId = (int)($user['_retained_site_id'] ?? 0);
            $mapping = $retainedSiteId > 0 && isset($siteKeyById[$retainedSiteId])
                ? [
                    'status'=>'mapped',
                    'site_key'=>$siteKeyById[$retainedSiteId],
                    'priority'=>null,
                    'matched_rules'=>['retained-accepted-identity'],
                    'message'=>'Retained from the last complete accepted identity snapshot',
                ]
                : DirectorySiteMapper::resolve($user, $rules);
            if ($mapping['status'] === 'unmapped' && $sourceKey === 'legacy-default' && !$rules) {
                $mappedIds = array_values(array_unique($legacySiteMap[(string)$user['extension']] ?? []));
                if (count($mappedIds) === 1 && isset($siteKeyById[(int)$mappedIds[0]])) {
                    $mapping = [
                        'status'=>'mapped',
                        'site_key'=>$siteKeyById[(int)$mappedIds[0]],
                        'priority'=>null,
                        'matched_rules'=>['legacy-logical-extension'],
                        'message'=>'Mapped by the migration-safe logical extension binding',
                    ];
                } elseif (count($mappedIds) > 1) {
                    $mapping = [
                        'status'=>'conflict',
                        'site_key'=>null,
                        'priority'=>null,
                        'matched_rules'=>['legacy-logical-extension'],
                        'message'=>'The legacy logical extension exists at more than one site; add an explicit AD rule',
                    ];
                }
            }
            $user['_site_mapping'] = $mapping;
            $user['_site_id'] = $mapping['status'] === 'mapped'
                ? ($siteIdByKey[(string)$mapping['site_key']] ?? null)
                : null;
            $user['_mapping_conflict'] = $mapping['status'] === 'conflict';
            if ($user['_mapping_conflict']) {
                $key = sha1('site_mapping|' . $sourceId . '|' . $user['source_key']);
                $conflicts[$key] = [
                    'key'=>$key,
                    'type'=>'site_mapping',
                    'value'=>(string)$user['extension'],
                    'candidates'=>[['source_key'=>$user['source_key'],'label'=>$user['label'],'mapping'=>$mapping]],
                    'resolution_key'=>'',
                    'status'=>'open',
                ];
            }
        }
        unset($user);

        $byExtension = [];
        foreach ($users as $user) {
            if (trim((string)$user['extension']) === '') { continue; }
            $bucket = ($user['_site_id'] ?? 'unmapped') . '|' . $user['extension'];
            $byExtension[$bucket][] = $user;
        }
        foreach ($byExtension as $bucket => $candidates) {
            if (count($candidates) < 2) { continue; }
            $extension = (string)$candidates[0]['extension'];
            $key = sha1('duplicate_extension|' . $sourceId . '|' . $bucket);
            $winner = $existingResolutions[$key] ?? '';
            if ($winner !== '' && !array_filter($candidates, static fn($c) => $c['source_key'] === $winner)) { $winner = ''; }
            $conflicts[$key] = [
                'key'=>$key,
                'type'=>'duplicate_extension',
                'value'=>$extension,
                'candidates'=>$candidates,
                'resolution_key'=>$winner,
                'status'=>$winner !== '' ? 'resolved_local' : 'open',
            ];
        }
        } catch (\Throwable $e) {
            $error = mb_substr(trim($e->getMessage()), 0, 4000, 'UTF-8');
            $candidate = isset($sourceRevision) ? (string)$sourceRevision : null;
            $db->prepare(
                "UPDATE ucmp_directory_sync_runs
                    SET finished=?,status='failed',candidate_revision=?,error=?
                  WHERE id=?"
            )->execute([time(), $candidate, $error, $runId]);
            $db->prepare(
                'UPDATE ucmp_directory_sources SET last_attempt=?,last_error=?,updated=? WHERE id=?'
            )->execute([time(), $error, time(), $sourceId]);
            return [
                'ok'=>false,'accepted'=>false,'status'=>'failed','source_id'=>$sourceId,'source_key'=>$sourceKey,
                'previous_revision'=>(string)($source['last_accepted_revision'] ?? ''),
                'candidate_revision'=>$candidate,
                'message'=>$error,
            ];
        }

        $now = time();
        $seen = [];
        $db->beginTransaction();
        $acceptCommitAttempted = false;
        try {
            // Classify only proven existing bindings against the still-accepted
            // snapshot. Acceptance failure rolls this back with the directory.
            $logical = new LogicalExtensionService($db);
            $legacyPlan = $logical->planLegacyIdentityAdoption(5000, $sourceId);
            $logical->adoptLegacyIdentityRelationships($legacyPlan['eligible'], $now);
            $upsert = $db->prepare(
                'INSERT INTO ucmp_directory_entries
                    (source_key,source_id,object_key,source_revision,last_seen_at,
                     display_name,label,extension,department,company,org_unit,mail,mobile,pager,
                     source_dn,attributes,site_id,conflicted,updated)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    source_id=VALUES(source_id),object_key=VALUES(object_key),
                    source_revision=VALUES(source_revision),last_seen_at=VALUES(last_seen_at),
                    display_name=VALUES(display_name),label=VALUES(label),extension=VALUES(extension),
                    department=VALUES(department),company=VALUES(company),org_unit=VALUES(org_unit),
                    mail=VALUES(mail),mobile=VALUES(mobile),pager=VALUES(pager),source_dn=VALUES(source_dn),
                    attributes=VALUES(attributes),site_id=VALUES(site_id),
                    conflicted=VALUES(conflicted),updated=VALUES(updated)'
            );
            foreach ($users as &$user) {
                $seen[] = $user['_object_key'];
                $bucket = ($user['_site_id'] ?? 'unmapped') . '|' . $user['extension'];
                $dups = $byExtension[$bucket] ?? [];
                $conflicted = !empty($user['_mapping_conflict']) ? 1 : 0;
                if (count($dups) > 1) {
                    $key = sha1('duplicate_extension|' . $sourceId . '|' . $bucket);
                    $winner = (string)($conflicts[$key]['resolution_key'] ?? '');
                    $conflicted = ($conflicted || $winner === '' || $winner !== $user['source_key']) ? 1 : 0;
                }
                $user['_conflicted'] = $conflicted;
                $upsert->execute([
                    $user['source_key'],$sourceId,$user['_object_key'],$sourceRevision,
                    !empty($user['_retained_incomplete'])
                        ? max(0,(int)($user['_retained_last_seen_at'] ?? 0))
                        : $now,
                    $user['display_name'],$user['label'],$user['extension'],$user['department'],
                    $user['company'],$user['org_unit'],$user['mail'],$user['mobile'],$user['pager'],$user['dn'],
                    json_encode($user['attributes'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                    $user['_site_id'],$conflicted,$now,
                ]);
            }
            unset($user);
            if ($seen) {
                $marks = implode(',', array_fill(0, count($seen), '?'));
                $db->prepare(
                    "DELETE FROM ucmp_directory_entries WHERE source_id=? AND object_key NOT IN ($marks)"
                )->execute(array_merge([$sourceId], $seen));
            } else {
                $db->prepare('DELETE FROM ucmp_directory_entries WHERE source_id=?')->execute([$sourceId]);
            }
            $conflictUpsert = $db->prepare(
                'INSERT INTO ucmp_directory_conflicts (source_id,conflict_key,conflict_type,conflict_value,candidates,resolution_key,status,updated) VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE candidates=VALUES(candidates),resolution_key=VALUES(resolution_key),status=VALUES(status),updated=VALUES(updated)'
            );
            foreach ($conflicts as $c) {
                $conflictUpsert->execute([$sourceId,$c['key'],$c['type'],$c['value'],json_encode($c['candidates'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$c['resolution_key'] ?: null,$c['status'],$now]);
            }
            if ($conflicts) {
                $marks = implode(',', array_fill(0, count($conflicts), '?'));
                $db->prepare(
                    "DELETE FROM ucmp_directory_conflicts WHERE source_id=? AND conflict_key NOT IN ($marks)"
                )->execute(array_merge([$sourceId], array_keys($conflicts)));
            } else {
                $db->prepare('DELETE FROM ucmp_directory_conflicts WHERE source_id=?')->execute([$sourceId]);
            }
            $db->prepare(
                'UPDATE ucmp_directory_sources
                    SET last_accepted_revision=?,last_attempt=?,last_success=?,last_error=NULL,updated=?
                  WHERE id=?'
            )->execute([$sourceRevision,$now,$now,$now,$sourceId]);
            $db->prepare(
                "UPDATE ucmp_directory_sync_runs
                    SET finished=?,status='accepted',candidate_revision=?,accepted_revision=?,
                        object_count=?,added=?,changed=?,deleted=?,error=NULL
                  WHERE id=?"
            )->execute([$now,$sourceRevision,$sourceRevision,count($users),$added,$changed,$deleted,$runId]);
            $acceptCommitAttempted = true;
            if (!$db->commit()) { throw new \RuntimeException('Directory acceptance commit was not confirmed'); }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            if ($acceptCommitAttempted) {
                return ['ok'=>false,'accepted'=>null,'status'=>'unknown','source_id'=>$sourceId,'source_key'=>$sourceKey,
                    'run_id'=>$runId,'candidate_revision'=>$sourceRevision,
                    'message'=>'Directory acceptance commit is unconfirmed; reconcile the recorded run and accepted revision before retrying. No publication or endpoint update was started.'];
            }
            $error = mb_substr(trim($e->getMessage()), 0, 4000, 'UTF-8');
            $db->prepare(
                "UPDATE ucmp_directory_sync_runs SET finished=?,status='failed',candidate_revision=?,error=? WHERE id=?"
            )->execute([time(), $sourceRevision, $error, $runId]);
            $db->prepare(
                'UPDATE ucmp_directory_sources SET last_attempt=?,last_error=?,updated=? WHERE id=?'
            )->execute([time(), $error, time(), $sourceId]);
            return [
                'ok'=>false,'accepted'=>false,'status'=>'failed','source_id'=>$sourceId,'source_key'=>$sourceKey,
                'previous_revision'=>(string)($source['last_accepted_revision'] ?? ''),
                'candidate_revision'=>$sourceRevision,
                'message'=>$error,
            ];
        }

        $warnings = [];
        $warnings = array_merge($warnings, $readWarnings ?? []);
        $autoReconciled = 0;
        $identityBindingsReady = true;
        $bindingTransaction = !$db->inTransaction();
        try {
            if ($bindingTransaction) { $db->beginTransaction(); }
            $logical = new LogicalExtensionService($db);
            $bindingsBefore = $logical->enumerateBindings($sourceId);
            $logical->reconcileAcceptedSourceBindings($sourceId, $sourceRevision, $users, true, $now);
            $authoritativeMoves = [];
            foreach ($users as $user) {
                if (empty($user['_site_id']) || !empty($user['_conflicted']) || trim((string)$user['extension']) === '') { continue; }
                $authoritativeMoves[] = [
                    'source_id'=>$sourceId,
                    'object_key'=>(string)$user['_object_key'],
                    'source_revision'=>$sourceRevision,
                    'site_id'=>(int)$user['_site_id'],
                    'identity'=>$user + ['ad_source_id'=>$sourceId,'ad_source_revision'=>$sourceRevision],
                ];
            }
            $automatic = $logical->reconcileAuthoritativeAdIdentityMoves($authoritativeMoves, $now);
            $autoReconciled = count($automatic['applied']);
            foreach ($users as &$user) {
                if (empty($user['_site_id']) || !empty($user['_conflicted']) || trim((string)$user['extension']) === '') { continue; }
                try {
                    $logical->bindAdIdentity(
                        (int)$user['_site_id'],
                        $sourceId,
                        (string)$user['_object_key'],
                        $sourceRevision,
                        $user + ['ad_source_id'=>$sourceId,'ad_source_revision'=>$sourceRevision],
                        $now
                    );
                } catch (\Throwable $e) {
                    $user['_conflicted'] = 1;
                    $db->prepare(
                        'UPDATE ucmp_directory_entries SET conflicted=1 WHERE source_id=? AND object_key=?'
                    )->execute([$sourceId, $user['_object_key']]);
                    $key = sha1('logical_binding|' . $sourceId . '|' . $user['source_key']);
                    $conflicts[$key] = [
                        'key'=>$key,
                        'type'=>'logical_binding',
                        'value'=>(string)$user['extension'],
                        'candidates'=>[['source_key'=>$user['source_key'],'label'=>$user['label'],'message'=>$e->getMessage()]],
                        'resolution_key'=>'',
                        'status'=>'open',
                    ];
                    $db->prepare(
                        'INSERT INTO ucmp_directory_conflicts
                            (source_id,conflict_key,conflict_type,conflict_value,candidates,resolution_key,status,updated)
                         VALUES (?,?,?,?,?,NULL,\'open\',?)
                         ON DUPLICATE KEY UPDATE candidates=VALUES(candidates),status=\'open\',updated=VALUES(updated)'
                    )->execute([
                        $sourceId,$key,'logical_binding',(string)$user['extension'],
                        json_encode($conflicts[$key]['candidates'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        $now,
                    ]);
                }
            }
            unset($user);
            $this->rememberIdentityReleases($bindingsBefore, $sourceId, $sourceRevision, $now);
            if ($bindingTransaction && !$db->commit()) { throw new \RuntimeException('Identity reconciliation commit is unverified'); }
        } catch (\Throwable $e) {
            if ($bindingTransaction && $db->inTransaction()) { $db->rollBack(); }
            unset($user);
            $identityBindingsReady = false;
            $warnings[] = 'Accepted AD revision, but logical identity binding did not complete: ' . $e->getMessage();
        }

        // Duplicate phone values are an identity-management conflict, not necessarily a
        // phonebook error (for example several guards may legitimately share one desk line).
        // Only PBX identity writes require a selected winner; named phonebooks apply their
        // own inclusion rules and explicit exclusions to the complete AD catalog.
        $catalogLoaded = true;
        try {
            $identityEntries = $this->entries(false, 0);
            $directoryEntries = $this->entries(true, 0);
        } catch (\Throwable $e) {
            $catalogLoaded = false;
            $identityEntries = [];
            $directoryEntries = [];
            $warnings[] = 'Accepted AD revision, but the combined accepted catalog could not be loaded: ' . $e->getMessage();
        }
        $identity = ['changed'=>0,'queued'=>0,'warnings'=>[]];
        if ($catalogLoaded && $identityBindingsReady) {
            try {
                $identity = $this->applyIdentities($identityEntries, $settings, !empty($source['_force_identity_refresh']));
            } catch (\Throwable $e) {
                $warnings[] = 'Accepted AD revision, but PBX identity delivery did not complete: ' . $e->getMessage();
            }
        }
        $published = ['ok_count'=>0,'queued'=>0,'warnings'=>[]];
        if ($catalogLoaded) {
            try {
                $published = $this->publishToSites($directoryEntries);
            } catch (\Throwable $e) {
                $error = 'Accepted AD revision, but phonebook publication did not complete: ' . $e->getMessage();
                $warnings[] = $error;
                $desiredHash = hash(
                    'sha256',
                    json_encode($directoryEntries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
                );
                foreach ($this->ucm->getSites() as $site) {
                    if (empty($site['enabled'])) { continue; }
                    try {
                        $this->queueDirectoryPublishJob((int)$site['id'], $desiredHash, $error);
                        $published['queued']++;
                    } catch (\Throwable $queueError) {
                        $warnings[] = (string)($site['name'] ?? $site['site_key'] ?? 'site')
                            . ': publication could not be queued: ' . $queueError->getMessage();
                    }
                }
            }
        }
        $open = count(array_filter($conflicts, static fn($c) => $c['status'] === 'open'));
        try {
            $this->ucm->setSetting('ad_last_sync', (string)$now, $this->ucm->localSiteId());
            $this->ucm->setSetting('ad_last_count', (string)count($directoryEntries), $this->ucm->localSiteId());
            $this->ucm->setSetting('ad_last_conflicts', (string)$open, $this->ucm->localSiteId());
            $this->notify($conflicts, $settings);
        } catch (\Throwable $e) {
            $warnings[] = 'Accepted AD revision, but status/notification finalization failed: ' . $e->getMessage();
        }
        $warnings = array_values(array_filter(array_merge($warnings, $identity['warnings'], $published['warnings']), 'strlen'));
        $partial = $identity['queued'] > 0 || $published['queued'] > 0 || $warnings;
        if ($partial) {
            try {
                $db->prepare(
                    "UPDATE ucmp_directory_sync_runs SET status='accepted_partial',error=? WHERE id=?"
                )->execute([mb_substr(implode("\n", $warnings), 0, 4000, 'UTF-8') ?: 'Accepted AD revision has queued delivery work', $runId]);
            } catch (\Throwable $e) {
                $warnings[] = 'Accepted partial run status could not be recorded: ' . $e->getMessage();
            }
        }
        $message = sprintf(
            '%s accepted revision %s: %d source object(s), %d total catalog object(s), %d unambiguous AD assignment move(s) auto-reconciled, %d unresolved source conflict(s), %d identity update(s), %d queued identity job(s), %d PBX cache(s) published, %d queued phonebook publication job(s).',
            $sourceKey,
            substr($sourceRevision, 0, 12),
            count($users),
            count($directoryEntries),
            $autoReconciled,
            $open,
            $identity['changed'],
            $identity['queued'],
            $published['ok_count'],
            $published['queued']
        );
        try {
            $this->ucm->logOperation($this->ucm->localSiteId(), 'ad-sync', ($open || $partial) ? 'warning' : 'success', $message, implode("\n", $warnings));
        } catch (\Throwable $ignored) {
            // The accepted revision and delivery state remain the durable result.
        }
        return [
            'ok'=>true,'accepted'=>true,'partial'=>(bool)$partial,'status'=>$partial?'accepted_partial':'accepted',
            'source_id'=>$sourceId,'source_key'=>$sourceKey,'accepted_revision'=>$sourceRevision,
            'source_entries'=>count($users),'entries'=>count($directoryEntries),'conflicts'=>$open,
            'auto_reconciled_moves'=>$autoReconciled,
            'diff'=>['added'=>$added,'changed'=>$changed,'deleted'=>$deleted],
            'identity'=>$identity,'published'=>$published,'warnings'=>$warnings,'message'=>$message,
        ];
    }

    public function entries(bool $includeConflicted = true, int $limit = 5000): array
    {
        // The unique key is the primary defence. Selecting only the newest source row makes
        // reads idempotent even during the narrow window before an integrity repair completes.
        $sql = 'SELECT d.*, ds.source_key AS directory_source_key, ds.domain AS source_domain, s.name AS site_name,
                       x.id AS extension_id,x.revision AS extension_revision,
                       x.desired_identity_hash,x.applied_identity_hash,x.apply_status
                  FROM ucmp_directory_entries d
                INNER JOIN (
                    SELECT source_id,object_key,MAX(id) id
                      FROM ucmp_directory_entries
                     GROUP BY source_id,object_key
                ) latest ON latest.id=d.id
                LEFT JOIN ucmp_directory_sources ds ON ds.id=d.source_id
                LEFT JOIN ucmp_sites s ON s.id=d.site_id
                LEFT JOIN ucmp_extensions x
                  ON x.ad_source_id=d.source_id AND x.ad_object_key=d.object_key';
        if (!$includeConflicted) { $sql .= ' WHERE d.conflicted=0'; }
        $sql .= ' ORDER BY COALESCE(s.name,\'Unmapped\'), COALESCE(NULLIF(d.department,\'\'),NULLIF(d.org_unit,\'\'),\'Other\'), d.display_name';
        if ($limit > 0) { $sql .= ' LIMIT ' . min(10000, $limit); }
        try {
            $rows = $this->ucm->getDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            // Older accepted-revision fixtures and partially upgraded installs
            // predate the optional source-domain column. Preserve directory
            // reads while the normal module upgrade adds it.
            if (!preg_match('/(?:no such column|unknown column).*domain/i', $e->getMessage())) {
                throw $e;
            }
            $legacySql = str_replace(
                'ds.domain AS source_domain',
                'NULL AS source_domain',
                $sql
            );
            $rows = $this->ucm->getDb()->query($legacySql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        foreach ($rows as &$row) {
            // object_key remains the exact per-domain database identity. Only
            // the public/contact key is namespaced so equal AD GUIDs from two
            // configured domains cannot overwrite one another in PHP maps.
            $row['source_key'] = self::publicDirectorySourceKey(
                (int)($row['source_id'] ?? 0),
                (string)($row['directory_source_key'] ?? ''),
                (string)($row['object_key'] ?? $row['source_key'] ?? '')
            );
            $row['ou_path'] = $this->ouPath((string)($row['source_dn'] ?? ''));
            $row['attributes'] = $this->normalizeAttributeMap($row['attributes'] ?? []);
            $row['groups'] = $row['attributes']['groups'] ?? $this->groupNames($row['attributes']['memberof'] ?? []);
        }
        unset($row);
        return $rows;
    }

    /**
     * Bind accepted pre-identity-v2 directory rows to their existing logical
     * extensions. New LDAP synchronizations already do this inline; this
     * bounded migration makes the real-extension preview and audited overrides
     * immediately usable after upgrading an older installation.
     *
     * @return array{examined:int,bound:int,failed:int,errors:array<int,string>}
     */
    public function backfillAcceptedIdentityBindings(int $limit = 10000): array
    {
        $limit = max(1, min(10000, $limit));
        $sql = "SELECT d.*,ds.domain
                  FROM ucmp_directory_entries d
                INNER JOIN (
                    SELECT source_id,object_key,MAX(id) id
                      FROM ucmp_directory_entries
                     GROUP BY source_id,object_key
                ) latest ON latest.id=d.id
                LEFT JOIN ucmp_directory_sources ds ON ds.id=d.source_id
                LEFT JOIN ucmp_extensions x
                  ON x.site_id=d.site_id AND x.extension=d.extension
                 WHERE d.conflicted=0
                   AND d.site_id IS NOT NULL
                   AND d.source_id IS NOT NULL
                   AND d.object_key IS NOT NULL AND d.object_key<>''
                   AND d.source_revision IS NOT NULL AND d.source_revision<>''
                   AND d.extension IS NOT NULL AND d.extension<>''
                   AND (x.id IS NULL OR x.ad_source_id IS NULL OR x.ad_object_key IS NULL)
                 ORDER BY d.id
                 LIMIT $limit";
        $rows = $this->ucm->getDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $logical = new LogicalExtensionService($this->ucm->getDb());
        $result = ['examined'=>count($rows), 'bound'=>0, 'failed'=>0, 'errors'=>[]];
        foreach ($rows as $row) {
            try {
                $logical->bindAdIdentity(
                    (int)$row['site_id'],
                    (int)$row['source_id'],
                    (string)$row['object_key'],
                    (string)$row['source_revision'],
                    $row + [
                        'ad_source_id'=>(int)$row['source_id'],
                        'ad_source_revision'=>(string)$row['source_revision'],
                    ]
                );
                $result['bound']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                if (count($result['errors']) < 20) {
                    $result['errors'][] = (string)$row['extension'] . ': ' . $e->getMessage();
                }
            }
        }
        return $result;
    }

    public function conflicts(): array
    {
        $rows = $this->ucm->getDb()->query('SELECT * FROM ucmp_directory_conflicts ORDER BY status, conflict_value')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['candidate_rows'] = json_decode((string)$row['candidates'], true) ?: [];
            if ((string)($row['conflict_type'] ?? '') === 'logical_binding') {
                try {
                    $row['reconciliation'] = $this->logicalConflictMove($row);
                } catch (\Throwable $error) {
                    $row['reconciliation'] = [
                        'available'=>false,
                        'message'=>$error->getMessage(),
                    ];
                }
            }
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function logicalConflictMove(array $conflict): array
    {
        $sourceId = (int)($conflict['source_id'] ?? 0);
        $candidate = (array)($conflict['candidate_rows'][0] ?? []);
        $candidateKey = trim((string)($candidate['source_key'] ?? ''));
        if ($sourceId < 1 || $candidateKey === '') {
            throw new \RuntimeException('The conflict no longer identifies one accepted AD object');
        }
        $db = $this->ucm->getDb();
        $entry = null;
        foreach (['source_key', 'object_key'] as $field) {
            $stmt = $db->prepare(
                "SELECT d.*,s.name AS desired_site_name
                   FROM ucmp_directory_entries d
                   LEFT JOIN ucmp_sites s ON s.id=d.site_id
                  WHERE d.source_id=? AND d.$field=? LIMIT 1"
            );
            $stmt->execute([$sourceId, $candidateKey]);
            $entry = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($entry) { break; }
        }
        if (!$entry || (int)($entry['site_id'] ?? 0) < 1 || trim((string)($entry['extension'] ?? '')) === '') {
            throw new \RuntimeException('The accepted AD row has no unambiguous destination site and extension');
        }
        $objectKey = trim((string)($entry['object_key'] ?? ''));
        $logical = new LogicalExtensionService($db);
        $current = $logical->findByAdObject($sourceId, $objectKey);
        if (!$current) {
            throw new \RuntimeException('The previous logical binding no longer exists; synchronize again to refresh this conflict');
        }
        $siteName = $db->prepare('SELECT name FROM ucmp_sites WHERE id=?');
        $siteName->execute([(int)$current['site_id']]);
        $currentSiteName = (string)($siteName->fetchColumn() ?: ('Site #' . (int)$current['site_id']));
        $target = $logical->findBySiteExtension((int)$entry['site_id'], (string)$entry['extension']);
        $occupant = null;
        if ($target && !empty($target['ad_source_id']) && !empty($target['ad_object_key'])
            && ((int)$target['ad_source_id'] !== $sourceId || (string)$target['ad_object_key'] !== $objectKey)
        ) {
            $occupantStmt = $db->prepare(
                'SELECT display_name,label,source_key,object_key
                   FROM ucmp_directory_entries
                  WHERE source_id=? AND object_key=? LIMIT 1'
            );
            $occupantStmt->execute([(int)$target['ad_source_id'], (string)$target['ad_object_key']]);
            $occupantEntry = $occupantStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $occupant = [
                'source_id'=>(int)$target['ad_source_id'],
                'object_key'=>(string)$target['ad_object_key'],
                'display_name'=>(string)($occupantEntry['display_name'] ?? $occupantEntry['label'] ?? 'Another AD object'),
            ];
        }
        return [
            'available'=>true,
            'conflict_id'=>(int)$conflict['id'],
            'source_id'=>$sourceId,
            'object_key'=>$objectKey,
            'source_revision'=>(string)($entry['source_revision'] ?? ''),
            'identity'=>$entry,
            'current'=>[
                'extension_id'=>(int)$current['id'],
                'site_id'=>(int)$current['site_id'],
                'site_name'=>$currentSiteName,
                'extension'=>(string)$current['extension'],
            ],
            'desired'=>[
                'site_id'=>(int)$entry['site_id'],
                'site_name'=>(string)($entry['desired_site_name'] ?? ('Site #' . (int)$entry['site_id'])),
                'extension'=>(string)$entry['extension'],
            ],
            'occupant'=>$occupant,
        ];
    }

    public function stats(): array
    {
        $db = $this->ucm->getDb();
        return [
            'entries'=>(int)$db->query('SELECT COUNT(*) FROM ucmp_directory_entries')->fetchColumn(),
            'conflicts'=>(int)$db->query("SELECT COUNT(*) FROM ucmp_directory_conflicts WHERE status='open'")->fetchColumn(),
            'unmapped'=>(int)$db->query('SELECT COUNT(*) FROM ucmp_directory_entries WHERE conflicted=0 AND site_id IS NULL')->fetchColumn(),
            'last_sync'=>(int)$this->ucm->getSetting('ad_last_sync', $this->ucm->localSiteId(), 0),
            'identity_skips'=>$db->query("SELECT x.site_id,x.extension,x.apply_error AS reason,s.name AS site_name
                FROM ucmp_extensions x LEFT JOIN ucmp_sites s ON s.id=x.site_id
                WHERE x.apply_status='skipped' ORDER BY s.name,x.extension")->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function identityOverrides(): array
    {
        $rows = $this->ucm->getDb()->query(
            "SELECT o.*,x.extension,x.site_id,s.name AS site_name,
                    d.display_name AS ad_display_name,d.label AS ad_label
               FROM ucmp_identity_overrides o
               JOIN ucmp_extensions x ON x.id=o.extension_id
               LEFT JOIN ucmp_sites s ON s.id=x.site_id
               LEFT JOIN ucmp_directory_entries d
                 ON d.source_id=x.ad_source_id AND d.object_key=x.ad_object_key
              WHERE o.scope_type='extension'
              ORDER BY COALESCE(s.name,''),x.extension,o.mode,o.field_name"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            try {
                $row['value'] = json_decode((string)$row['value_json'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $row['value'] = null;
                $row['decode_error'] = true;
            }
        }
        unset($row);
        return $rows;
    }

    public function saveIdentityOverride(array $input): array
    {
        $extensionId = (int)($input['extension_id'] ?? 0);
        $field = trim((string)($input['field_name'] ?? 'phone_label'));
        $modes = [
            'display_name'=>'canonical',
            'label'=>'canonical',
            'phone_label'=>'final',
            'line_label'=>'final',
            'blf_label'=>'final',
            'directory_label'=>'final',
        ];
        $fields = $input['overrides'] ?? [$field=>(string)($input['override_value'] ?? '')];
        if ($extensionId < 1 || !is_array($fields) || !$fields || array_diff(array_keys($fields), array_keys($modes))) {
            throw new \InvalidArgumentException('Choose a synchronized logical extension and supported identity field');
        }
        $logical = new LogicalExtensionService($this->ucm->getDb());
        $extension = $logical->findById($extensionId);
        if (!$extension || empty($extension['ad_source_id']) || empty($extension['ad_object_key'])) {
            throw new \InvalidArgumentException('Identity overrides require a logical extension bound to an accepted AD object');
        }
        $overrides = $logical->saveExtensionOverrides(
            $extensionId,
            $fields,
            (string)($input['reason'] ?? ''),
            $this->currentActor()
        );
        $delivery = $this->refreshIdentityOutputs();
        return [
            'ok'=>true,
            'message'=>count($overrides) . ' identity field override(s) saved without modifying AD.',
            'override'=>$overrides[0],
            'overrides'=>$overrides,
            'delivery'=>$delivery,
        ];
    }

    public function revertIdentityOverride(array $input): array
    {
        $id = (int)($input['override_id'] ?? 0);
        $stmt = $this->ucm->getDb()->prepare(
            "SELECT * FROM ucmp_identity_overrides WHERE id=? AND scope_type='extension'"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row || empty($row['extension_id'])) {
            throw new \InvalidArgumentException('Identity override not found');
        }
        $logical = new LogicalExtensionService($this->ucm->getDb());
        if (!$logical->revertExtensionOverride(
            (int)$row['extension_id'],
            (string)$row['field_name'],
            (string)$row['mode']
        )) {
            throw new \RuntimeException('Identity override was already absent');
        }
        $delivery = $this->refreshIdentityOutputs();
        return [
            'ok'=>true,
            'message'=>'Identity field reverted to the accepted AD/template result.',
            'delivery'=>$delivery,
        ];
    }

    private function refreshIdentityOutputs(): array
    {
        $settings = $this->settings(false);
        $identity = $this->applyIdentities($this->entries(false, 0), $settings);
        $published = $this->publishToSites($this->entries(true, 0));
        $this->ucm->logOperation(
            $this->ucm->localSiteId(),
            'identity-override',
            ($identity['warnings'] || $published['warnings']) ? 'warning' : 'success',
            'Identity override outputs refreshed',
            implode("\n", array_merge($identity['warnings'], $published['warnings']))
        );
        return ['identity'=>$identity,'published'=>$published];
    }

    /** Named, centrally-authored phonebooks. Definitions live in MySQL; XML is rendered on demand. */
    public function phonebooks(): array
    {
        $rows = $this->ucm->getDb()->query('SELECT * FROM ucmp_phonebooks ORDER BY is_default DESC,name')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) { $row = $this->decodePhonebook($row); }
        return $rows;
    }

    public function phonebook(int $id): ?array
    {
        $st = $this->ucm->getDb()->prepare('SELECT * FROM ucmp_phonebooks WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->decodePhonebook($row) : null;
    }

    public function importPhonebook(array $input, bool $publish = true): int
    {
        $v = is_array($input['phonebook'] ?? null) ? $input['phonebook'] : $input;
        $key = trim((string)($v['book_key'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key)) { throw new \InvalidArgumentException('Imported phonebook requires a stable safe book key of at most 64 characters'); }
        $stmt = $this->ucm->getDb()->prepare('SELECT id FROM ucmp_phonebooks WHERE book_key=?');
        $stmt->execute([$key]);
        $v['id'] = (int)$stmt->fetchColumn();
        return $this->savePhonebook($v, $key, $publish);
    }

    public function savePhonebook(array $input, ?string $importKey = null, bool $publish = true): int
    {
        $this->lastPublicationResult = [];
        $v = is_array($input['phonebook'] ?? null) ? $input['phonebook'] : $input;
        $id = (int)($v['id'] ?? $input['book_id'] ?? 0);
        $existing = $id > 0 ? $this->phonebook($id) : null;
        if ($id > 0 && !$existing) { throw new \InvalidArgumentException('Phonebook not found'); }
        $expectedUpdated = (int)($v['expected_updated'] ?? $existing['updated'] ?? 0);
        if ($existing && $expectedUpdated !== (int)$existing['updated']) { throw new \RuntimeException('Phonebook changed after it was opened; reload before saving'); }
        $name = trim((string)($v['name'] ?? ''));
        $title = trim((string)($v['title'] ?? $name));
        if ($name === '' || $title === '') { throw new \InvalidArgumentException('Phonebook name and phone-screen title are required'); }
        $key = (string)($existing['book_key'] ?? $importKey ?? '');
        if ($importKey !== null && (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $importKey) || $key !== $importKey)) { throw new \InvalidArgumentException('Invalid imported book identity'); }
        if ($key === '') {
            $key = trim((string)preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name)), '-') ?: 'phonebook';
            $key = substr($key, 0, 60);
            $base = $key; $n = 2;
            $check = $this->ucm->getDb()->prepare('SELECT COUNT(*) FROM ucmp_phonebooks WHERE book_key=?');
            do { $check->execute([$key]); if (!(int)$check->fetchColumn()) { break; } $key = $base . '-' . $n++; } while ($n < 1000);
        }
        $filename = trim((string)($v['filename'] ?? ($existing['filename'] ?? AssetFilename::content('p', $key, 'xml'))));
        if ($filename === '') { $filename = AssetFilename::content('p', $key, 'xml'); }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.xml$/i', $filename)) {
            throw new \InvalidArgumentException('Managed phonebook filename must be a safe .xml filename');
        }
        if ($existing === null && $importKey === null && strlen($filename) > AssetFilename::MAX_LENGTH) {
            $filename = AssetFilename::content('p', $key, 'xml');
        }
        $includeMode = in_array((string)($v['include_mode'] ?? 'all'), ['all','matching'], true) ? (string)($v['include_mode'] ?? 'all') : 'all';
        $rulesDecoded = is_string($v['rules'] ?? null) ? json_decode($v['rules'], true) : ($v['rules'] ?? []);
        if (!is_array($rulesDecoded)) { $rulesDecoded = []; }
        if (isset($v['hide_uncategorised'])) { $rulesDecoded['hide_uncategorised'] = !empty($v['hide_uncategorised']); }
        if (isset($v['search_enabled'])) { $rulesDecoded['search_enabled'] = !empty($v['search_enabled']); }
        if (isset($v['search_position'])) { $rulesDecoded['search_position'] = (string)$v['search_position']; }
        if (isset($v['search_scope'])) { $rulesDecoded['search_scope'] = (string)$v['search_scope']; }
        foreach (['search_extension','search_mobile','search_pager','mobile_editdial'] as $searchFlag) {
            if (isset($v[$searchFlag])) { $rulesDecoded[$searchFlag] = !empty($v[$searchFlag]); }
        }
        if (isset($v['numeric_min_length'])) { $rulesDecoded['numeric_min_length'] = (int)$v['numeric_min_length']; }
        if (isset($v['mobile_result_mode'])) { $rulesDecoded['mobile_result_mode'] = (string)$v['mobile_result_mode']; }
        if (isset($v['cisco_softkeys'])) { $rulesDecoded['cisco_softkeys'] = $v['cisco_softkeys']; }
        $rules = $this->normalizeRules($rulesDecoded);
        $exclusions = $this->decodeArray($v['exclusions'] ?? $existing['exclusions'] ?? []);
        $exclusions = array_values(array_unique(array_filter(array_map('strval', $exclusions), static fn($key) => (bool)preg_match('/^[A-Fa-f0-9]{16,128}$/', $key))));
        $manualInput = $v['manual_entries'] ?? $existing['manual_entries'] ?? [];
        if (is_string($manualInput)) { $manualInput = json_decode($manualInput, true, 512, JSON_THROW_ON_ERROR); }
        $existingManual = array_column((array)($existing['manual_entries'] ?? []), null, 'source_key');
        if (is_array($manualInput)) {
            foreach ($manualInput as &$entry) {
                if (!is_array($entry) || !is_string($entry['source_key'] ?? null)) { continue; }
                $savedEntry = $existingManual[$entry['source_key']] ?? [];
                foreach (['attributes','groups'] as $field) {
                    if (!array_key_exists($field, $entry) && array_key_exists($field, $savedEntry)) { $entry[$field] = $savedEntry[$field]; }
                }
            }
            unset($entry);
        }
        $manual = $this->normalizeManualEntries($manualInput, true);
        $hierarchy = $this->normalizeHierarchy($v['hierarchy'] ?? [], (string)($v['group_by'] ?? 'department'));
        $publishSites = $this->normalizePublishSites($v['publish_sites'] ?? ['*']);
        if (!in_array('*', $publishSites, true)) {
            foreach ($this->phonebookReferences($key, true) as $reference) {
                if (!in_array($reference['source_site'], $publishSites, true)) {
                    throw new \RuntimeException(sprintf(
                        'Cannot stop publishing this phonebook to %s: template "%s" on %s still selects it',
                        $reference['source_name'], $reference['template'], $reference['owner_name']
                    ));
                }
            }
        }
        $layout = $this->normalizePhonebookLayout([
            'directory_title'=>$title,
            'directory_group_by'=>(string)($v['group_by'] ?? 'department'),
            'directory_name_format'=>(string)($v['name_format'] ?? 'display_label'),
            'directory_sort_by'=>(string)($v['sort_by'] ?? 'display_name'),
            'directory_include_mobile'=>!empty($v['include_mobile'])?'1':'0',
            'directory_cisco_page_size'=>(string)($v['cisco_page_size'] ?? '32'),
            'directory_hide_uncategorised'=>!empty($v['hide_uncategorised'])?'1':'0',
            'directory_search_enabled'=>!empty($v['search_enabled'])?'1':'0',
            'directory_search_position'=>(string)($v['search_position'] ?? 'first'),
            'directory_search_scope'=>(string)($v['search_scope'] ?? 'root'),
            'directory_search_extension'=>!empty($v['search_extension'])?'1':'0',
            'directory_search_mobile'=>!empty($v['search_mobile'])?'1':'0',
            'directory_search_pager'=>!empty($v['search_pager'])?'1':'0',
            'directory_numeric_min_length'=>(string)($v['numeric_min_length'] ?? 1),
            'directory_mobile_result_mode'=>(string)($v['mobile_result_mode'] ?? 'find_extension'),
            'directory_mobile_editdial'=>!empty($v['mobile_editdial'])?'1':'0',
            'directory_cisco_softkeys'=>$v['cisco_softkeys'] ?? [],
        ]);
        $default = !empty($v['is_default']) || (!$existing && !$this->phonebooks()) || (!empty($existing['is_default']) && !array_filter($this->phonebooks(), static fn($book) => !empty($book['is_default']) && (int)$book['id'] !== $id));
        $db = $this->ucm->getDb();
        $started = !$db->inTransaction();
        if (!$started && $publish) { throw new \RuntimeException('Phonebook publication must follow its enclosing transaction commit'); }
        if ($started && !$db->beginTransaction()) { throw new \RuntimeException('Cannot begin phonebook save'); }
        $commitAttempted = false;
        try {
            if ($default) { $db->prepare('UPDATE ucmp_phonebooks SET is_default=0,updated=CASE WHEN updated>=? THEN updated+1 ELSE ? END WHERE id<>? AND is_default<>0')->execute([time(),time(),$id]); }
            $values = [$key,mb_substr($name,0,64,'UTF-8'),$filename,$layout['title'],$includeMode,
                json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($exclusions),
                json_encode($manual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$layout['group_by'],
                json_encode($hierarchy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($publishSites),$layout['name_format'],$layout['sort_by'],
                $layout['include_mobile']?1:0,$layout['cisco_page_size'],$default?1:0,max(time(),$expectedUpdated+1)];
            if ($id > 0) {
                $values[] = $id;
                $values[] = $expectedUpdated;
                $save = $db->prepare('UPDATE ucmp_phonebooks SET book_key=?,name=?,filename=?,title=?,include_mode=?,rules=?,exclusions=?,manual_entries=?,group_by=?,hierarchy=?,publish_sites=?,name_format=?,sort_by=?,include_mobile=?,cisco_page_size=?,is_default=?,updated=? WHERE id=? AND updated=?');
                $save->execute($values);
                if ($save->rowCount() !== 1) { throw new \RuntimeException('Phonebook changed during save; reload before retrying'); }
            } else {
                $db->prepare('INSERT INTO ucmp_phonebooks (book_key,name,filename,title,include_mode,rules,exclusions,manual_entries,group_by,hierarchy,publish_sites,name_format,sort_by,include_mobile,cisco_page_size,is_default,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
                $id = (int)$db->lastInsertId();
            }
            $commitAttempted = $started;
            if ($started && !$db->commit()) { throw new \RuntimeException('Phonebook save commit was not confirmed'); }
        } catch (\Throwable $e) {
            if ($started && $db->inTransaction()) { $db->rollBack(); }
            if ($commitAttempted) { throw new RpcOperationException('Phonebook save commit was not confirmed; reconcile the saved definition before retrying.', ['ok'=>false,'status'=>'unknown','failed_stage'=>'phonebook_commit','phonebook_id'=>$id]); }
            throw str_contains(strtolower($e->getMessage()), 'duplicate')
                ? new \InvalidArgumentException('Another phonebook already uses that filename') : $e;
        }
        if ($publish) { $this->republishPhonebooks(); }
        return $id;
    }

    public function deletePhonebook(int $id, bool $publish = true, ?int $expectedUpdated = null): void
    {
        $this->lastPublicationResult = [];
        $book = $this->phonebook($id);
        if (!$book) { return; }
        $expectedUpdated ??= (int)$book['updated'];
        if ($expectedUpdated !== (int)$book['updated']) { throw new \RuntimeException('Phonebook changed after it was opened; reload before deleting'); }
        if (count($this->phonebooks()) <= 1) { throw new \RuntimeException('At least one phonebook must remain'); }
        $references = $this->phonebookReferences((string)$book['book_key'], true);
        if ($references) {
            $sample = array_slice(array_map(static fn(array $row): string => $row['owner_name'] . ' / ' . $row['template'], $references), 0, 5);
            throw new \RuntimeException('This phonebook is still selected by ' . count($references) . ' template(s): ' . implode(', ', $sample) . '. Reassign those templates before deleting it.');
        }
        $db = $this->ucm->getDb();
        $started = !$db->inTransaction();
        if (!$started && $publish) { throw new \RuntimeException('Phonebook publication must follow its enclosing transaction commit'); }
        if ($started && !$db->beginTransaction()) { throw new \RuntimeException('Cannot begin phonebook deletion'); }
        $commitAttempted = false;
        try {
            $remaining = $db->query('SELECT id FROM ucmp_phonebooks ORDER BY id' . ((string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE'))->fetchAll(\PDO::FETCH_COLUMN);
            if (count($remaining) <= 1) { throw new \RuntimeException('At least one phonebook must remain'); }
            $delete = $db->prepare('DELETE FROM ucmp_phonebooks WHERE id=? AND updated=?');
            $delete->execute([$id,$expectedUpdated]);
            if ($delete->rowCount() !== 1) { throw new \RuntimeException('Phonebook changed during deletion; reload before retrying'); }
            if (!empty($book['is_default'])) {
                $next = (int)$db->query('SELECT id FROM ucmp_phonebooks ORDER BY id LIMIT 1')->fetchColumn();
                $db->prepare('UPDATE ucmp_phonebooks SET is_default=1,updated=CASE WHEN updated>=? THEN updated+1 ELSE ? END WHERE id=?')->execute([time(),time(),$next]);
            }
            $commitAttempted = $started;
            if ($started && !$db->commit()) { throw new \RuntimeException('Phonebook deletion commit was not confirmed'); }
        } catch (\Throwable $error) {
            if ($started && $db->inTransaction()) { $db->rollBack(); }
            if ($commitAttempted) { throw new RpcOperationException('Phonebook deletion commit is unconfirmed; reconcile before retrying.', ['ok'=>false,'status'=>'unknown','failed_stage'=>'phonebook_delete_commit','phonebook_id'=>$id]); }
            throw $error;
        }
        if ($publish) { $this->republishPhonebooks(); }
    }

    /** Find explicit template consumers on every PBX before removing publication data. */
    private function phonebookReferences(string $bookKey, bool $failOnUnavailable = false): array
    {
        if ($bookKey === '') { return []; }
        $sites = array_values(array_filter($this->ucm->getSites(), static fn(array $site): bool => !empty($site['enabled'])));
        $byKey = [];
        foreach ($sites as $site) { $byKey[(string)($site['site_key'] ?? '')] = $site; }
        $out = [];
        foreach ($sites as $owner) {
            try { $templates = method_exists($this->ucm,'getTemplatesForReferenceCheck')
                ? $this->ucm->getTemplatesForReferenceCheck((int)$owner['id']) : $this->ucm->getTemplates((int)$owner['id']); }
            catch (\Throwable $e) {
                if ($failOnUnavailable) { throw new \RuntimeException('Cannot verify template references on ' . $owner['name'] . ': ' . $e->getMessage()); }
                continue;
            }
            foreach ($templates as $template) {
                if (!hash_equals($bookKey, (string)($template['phonebook_key'] ?? ''))) { continue; }
                $sourceKey = trim((string)($template['phonebook_source_site'] ?? '')) ?: (string)($owner['site_key'] ?? '');
                $source = $byKey[$sourceKey] ?? ['name'=>$sourceKey ?: 'unknown source'];
                $out[] = [
                    'template'=>(string)($template['name'] ?? ('#' . ($template['id'] ?? '?'))),
                    'owner_name'=>(string)($owner['name'] ?? 'unknown site'),
                    'source_site'=>$sourceKey,
                    'source_name'=>(string)($source['name'] ?? $sourceKey),
                ];
            }
        }
        return $out;
    }

    public function duplicatePhonebook(int $id): int
    {
        $book = $this->phonebook($id);
        if (!$book) { throw new \InvalidArgumentException('Phonebook not found'); }
        unset($book['id'],$book['book_key']);
        $book['name'] .= ' (copy)';
        unset($book['filename']);
        $book['is_default'] = 0;
        return $this->savePhonebook(['phonebook'=>$book]);
    }

    public function previewPhonebook(int $id): array
    {
        $book = $this->phonebook($id) ?: ($this->phonebooks()[0] ?? null);
        if (!$book) { return ['book'=>null,'entries'=>[],'groups'=>[]]; }
        $compiled = $this->compilePhonebook($book, $this->resolveDirectoryIdentities($this->entries(true, 0)));
        $groups = [];
        foreach ($this->leafContactGroups($compiled) as $group => $ids) {
            foreach ($ids as $id) { if (isset($compiled['contacts'][$id])) { $groups[$group][] = $compiled['contacts'][$id]; } }
        }
        return $compiled + ['book'=>$book,'groups'=>$groups];
    }

    /** Compile an unsaved designer payload against the current catalog. */
    public function previewDraft(array $input): array
    {
        $book = is_array($input['phonebook'] ?? null) ? $input['phonebook'] : $input;
        $id = (int)($book['id'] ?? $input['book_id'] ?? 0);
        $existing = $id > 0 ? $this->phonebook($id) : null;
        $book = array_replace($existing ?: [
            'book_key'=>'draft','name'=>'Draft','filename'=>'draft.xml','title'=>'Phonebook preview','include_mode'=>'all',
            'rules'=>[],'exclusions'=>[],'manual_entries'=>[],'group_by'=>'none','hierarchy'=>['version'=>2,'children'=>[]],
            'publish_sites'=>['*'],'name_format'=>'display_label','sort_by'=>'display_name','include_mobile'=>1,'cisco_page_size'=>32,
            'search_extension'=>true,'search_mobile'=>true,'search_pager'=>true,'numeric_min_length'=>3,
            'mobile_result_mode'=>'find_extension','mobile_editdial'=>false,'is_default'=>0,
        ], $book);
        $book['book_key'] = (string)($existing['book_key'] ?? 'draft');
        $book['name'] = trim((string)($book['name'] ?? '')) ?: 'Draft';
        $book['filename'] = trim((string)($book['filename'] ?? '')) ?: 'draft.xml';
        $book['title'] = trim((string)($book['title'] ?? '')) ?: $book['name'];
        return $this->compilePhonebook($book, $this->resolveDirectoryIdentities($this->entries(true, 0)));
    }

    private function decodePhonebook(array $row): array
    {
        $row['rules'] = $this->normalizeRules($row['rules'] ?? []);
        $row['hide_uncategorised'] = $row['hide_uncategorised'] ?? !empty($row['rules']['hide_uncategorised']);
        $row['search_enabled'] = $row['search_enabled'] ?? !empty($row['rules']['search_enabled']);
        $row['search_position'] = $row['search_position'] ?? (string)($row['rules']['search_position'] ?? 'first');
        $row['search_scope'] = $row['search_scope'] ?? (string)($row['rules']['search_scope'] ?? 'root');
        $row['search_extension'] = $row['search_extension'] ?? !empty($row['rules']['search_extension']);
        $row['search_mobile'] = $row['search_mobile'] ?? !empty($row['rules']['search_mobile']);
        $row['search_pager'] = $row['search_pager'] ?? !empty($row['rules']['search_pager']);
        $row['numeric_min_length'] = $row['numeric_min_length'] ?? (int)($row['rules']['numeric_min_length'] ?? 1);
        $row['mobile_result_mode'] = $row['mobile_result_mode'] ?? (string)($row['rules']['mobile_result_mode'] ?? 'find_extension');
        $row['mobile_editdial'] = $row['mobile_editdial'] ?? !empty($row['rules']['mobile_editdial']);
        $row['cisco_softkeys'] = $row['cisco_softkeys'] ?? $this->normalizeCiscoSoftkeys($row['rules']['cisco_softkeys'] ?? []);
        $row['exclusions'] = $this->decodeArray($row['exclusions'] ?? []);
        $row['manual_entries'] = $this->normalizeManualEntries($row['manual_entries'] ?? []);
        $row['hierarchy'] = $this->normalizeHierarchy($row['hierarchy'] ?? [], (string)($row['group_by'] ?? 'department'));
        $row['publish_sites'] = $this->normalizePublishSites($row['publish_sites'] ?? ['*']);
        $row['include_mobile'] = !empty($row['include_mobile']);
        $row['is_default'] = !empty($row['is_default']);
        return $row;
    }

    private function decodeArray($value): array
    {
        if (is_array($value)) { return $value; }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** V2 navigation rule tree. V1 ordered levels are upgraded in memory. */
    private function normalizeHierarchy($value, string $legacyGroup = 'department'): array
    {
        $rows = $this->decodeArray($value);
        if (array_is_list($rows) && ($rows === [] || isset($rows[0]['field']))) {
            if (!$rows && $legacyGroup !== 'none') { $rows = [['field'=>$legacyGroup,'fallback'=>'Other']]; }
            $children = [];
            foreach (array_reverse(array_slice($rows,0,8)) as $level) {
                if (!is_array($level)) { continue; }
                $source = $this->normalizeSource((string)($level['field'] ?? ''));
                if ($source === 'none') { $children = []; continue; }
                if ($source === '') { continue; }
                $children = [[
                    'type'=>'group','source'=>$source,'fallback'=>$this->cleanLabel($level['fallback'] ?? 'Other','Other'),
                    'aliases'=>[],'children'=>$children,
                ]];
            }
            return ['version'=>2,'children'=>$children];
        }
        if (array_is_list($rows) && isset($rows[0]['type'])) { $rows = ['version'=>2,'children'=>$rows]; }
        if (($rows['type'] ?? '') === 'branch' || ($rows['type'] ?? '') === 'group') { $rows = ['version'=>2,'children'=>[$rows]]; }
        $count = 0;
        return ['version'=>2,'children'=>$this->normalizeTreeNodes($rows['children'] ?? [],0,$count)];
    }

    private function normalizeTreeNodes($value, int $depth, int &$count): array
    {
        if ($depth >= 8 || !is_array($value)) { return []; }
        $out = [];
        foreach (array_slice(array_values($value),0,100) as $node) {
            if (!is_array($node) || $count >= 500) { continue; }
            $type = (string)($node['type'] ?? '');
            if ($type === 'branch') {
                $label = $this->cleanLabel($node['label'] ?? '', '');
                if ($label === '') { continue; }
                $count++;
                $out[] = ['type'=>'branch','label'=>$label,'when'=>$this->normalizeWhen($node['when'] ?? []),'children'=>$this->normalizeTreeNodes($node['children'] ?? [],$depth+1,$count)];
            } elseif ($type === 'group') {
                $source = $this->normalizeSource((string)($node['source'] ?? ''));
                if ($source === '' || $source === 'none') { continue; }
                $count++;
                $aliases = [];
                $rawAliases = $this->decodeArray($node['aliases'] ?? []);
                if (array_is_list($rawAliases)) {
                    foreach ($rawAliases as $alias) { if (is_array($alias)) { $aliases[(string)($alias['value'] ?? $alias['from'] ?? '')] = (string)($alias['label'] ?? $alias['to'] ?? ''); } }
                } else { $aliases = $rawAliases; }
                $cleanAliases = [];
                foreach (array_slice($aliases,0,200,true) as $from=>$to) {
                    $from = $this->cleanLabel($from,''); $to = $this->cleanLabel($to,'');
                    if ($from !== '' && $to !== '') { $cleanAliases[$from] = $to; }
                }
                $out[] = ['type'=>'group','source'=>$source,'fallback'=>$this->cleanLabel($node['fallback'] ?? 'Other','Other'),'aliases'=>$cleanAliases,'children'=>$this->normalizeTreeNodes($node['children'] ?? [],$depth+1,$count)];
            }
        }
        return $out;
    }

    private function normalizeWhen($value): array
    {
        $value = $this->decodeArray($value);
        if (isset($value['source'])) { $value = ['match'=>'all','items'=>[$value]]; }
        $items = is_array($value['items'] ?? null) ? $value['items'] : (array_is_list($value) ? $value : []);
        $out = [];
        foreach (array_slice($items,0,20) as $item) {
            if (!is_array($item)) { continue; }
            $source = $this->normalizeSource((string)($item['source'] ?? $item['field'] ?? ''));
            $operator = (string)($item['operator'] ?? 'is');
            if ($operator === 'present') { $operator = 'exists'; }
            if ($operator === 'not_present') { $operator = 'not_exists'; }
            if ($source === '' || !in_array($operator,['is','is_not','contains','starts_with','ends_with','exists','not_exists','under'],true)) { continue; }
            if ($operator === 'under' && $source !== 'ou_path') { continue; }
            $raw = $item['value'] ?? '';
            if ($operator === 'under') {
                $path = $this->normalizeMenuPath(is_array($raw) ? implode(' / ',$raw) : (string)$raw);
                $values = $path ? [implode(' / ',$path)] : [];
            } else {
                $values = is_array($raw) ? $raw : preg_split('/\s*[|\r\n]+\s*/u',(string)$raw,-1,PREG_SPLIT_NO_EMPTY);
                $values = array_values(array_unique(array_filter(array_map(fn($v) => $this->cleanLabel($v,''),(array)$values),static fn($v)=>$v!=='')));
            }
            if (!in_array($operator,['exists','not_exists'],true) && !$values) { continue; }
            $out[] = ['source'=>$source,'operator'=>$operator,'value'=>$values];
        }
        return ['match'=>(($value['match'] ?? 'all') === 'any' ? 'any' : 'all'),'items'=>$out];
    }

    private function normalizeSource(string $source): string
    {
        $source = trim($source);
        $fixed = ['display_name','label','extension','department','company','org_unit','ou_path','site_name','mail','mobile','pager','groups','memberof','memberOf','none'];
        if (in_array($source,$fixed,true)) { return $source === 'memberOf' || $source === 'memberof' ? 'groups' : $source; }
        if (preg_match('/^ou:([0-7])$/',$source)) { return $source; }
        if (preg_match('/^attr:([A-Za-z][A-Za-z0-9-]*)$/',$source,$m)) { return 'attr:' . strtolower($m[1]); }
        return '';
    }

    private function normalizePublishSites($value): array
    {
        $sites = array_values(array_unique(array_filter(array_map('strval', $this->decodeArray($value)), static fn($key) => $key === '*' || (bool)preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key))));
        return in_array('*', $sites, true) || !$sites ? ['*'] : array_slice($sites, 0, 100);
    }

    private function normalizeRules($value): array
    {
        $decoded = $this->decodeArray($value);
        $hideUncat = !empty($decoded['hide_uncategorised']);
        $searchEnabled = !array_key_exists('search_enabled', $decoded) || !empty($decoded['search_enabled']);
        $searchPosition = in_array((string)($decoded['search_position'] ?? ''), ['first','after_uncategorised','last'], true)
            ? (string)$decoded['search_position'] : 'first';
        $searchScope = in_array((string)($decoded['search_scope'] ?? ''), ['root','all'], true)
            ? (string)$decoded['search_scope'] : 'root';
        $searchExtension = !array_key_exists('search_extension',$decoded) || !empty($decoded['search_extension']);
        $searchMobile = !array_key_exists('search_mobile',$decoded) || !empty($decoded['search_mobile']);
        $searchPager = !array_key_exists('search_pager',$decoded) || !empty($decoded['search_pager']);
        $numericMinLength = max(1,min(10,(int)($decoded['numeric_min_length'] ?? 1)));
        $mobileResultMode = in_array((string)($decoded['mobile_result_mode'] ?? ''),['find_extension','extension_and_mobile'],true)
            ? (string)$decoded['mobile_result_mode'] : 'find_extension';
        $mobileEditDial = !empty($decoded['mobile_editdial']);
        $softkeys = $this->normalizeCiscoSoftkeys($decoded['cisco_softkeys'] ?? []);
        $match = in_array((string)($decoded['match'] ?? ''), ['all','any'], true) ? (string)$decoded['match'] : 'all';
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : (array_is_list($decoded) ? $decoded : []);
        $out = [];
        $operators = ['is','contains','starts_with','ends_with'];
        foreach (array_slice($items,0,50) as $rule) {
            if (!is_array($rule)) { continue; }
            $field = $this->normalizeSource((string)($rule['field'] ?? '')); $operator = (string)($rule['operator'] ?? ''); $needle = trim((string)($rule['value'] ?? ''));
            if ($field !== '' && $field !== 'none' && in_array($operator,$operators,true) && $needle !== '') {
                $out[] = ['field'=>$field,'operator'=>$operator,'value'=>mb_substr($needle,0,255,'UTF-8')];
            }
        }
        return ['match'=>$match,'items'=>$out,'hide_uncategorised'=>$hideUncat,'search_enabled'=>$searchEnabled,
            'search_position'=>$searchPosition,'search_scope'=>$searchScope,
            'search_extension'=>$searchExtension,'search_mobile'=>$searchMobile,'search_pager'=>$searchPager,
            'numeric_min_length'=>$numericMinLength,'mobile_result_mode'=>$mobileResultMode,'mobile_editdial'=>$mobileEditDial,
            'cisco_softkeys'=>$softkeys];
    }

    private function normalizeCiscoSoftkeys($value): array
    {
        $value = $this->decodeArray($value);
        $definitions = [
            'menu' => [
                'default'=>['Select','Exit','Previous','Next'],
                'allowed'=>['Select','Search','Exit','Previous','Next','None'],
                'required'=>['Select','Exit'],
            ],
            'list' => [
                'default'=>['Dial','EditDial','Exit','Next'],
                'allowed'=>['Dial','EditDial','Search','Exit','Previous','Next','None'],
                'required'=>['Dial','Exit'],
            ],
        ];
        $result = [];
        foreach ($definitions as $view => $definition) {
            $raw = is_array($value[$view] ?? null) ? array_values($value[$view]) : $definition['default'];
            $keys = array_fill(0,4,'None');
            $seen = [];
            foreach (array_slice($raw,0,4) as $position => $action) {
                $action = (string)$action;
                if (!in_array($action,$definition['allowed'],true) || $action === 'None' || isset($seen[$action])) { continue; }
                $keys[$position] = $action; $seen[$action] = true;
            }
            foreach ($definition['required'] as $required) {
                if (isset($seen[$required])) { continue; }
                $position = array_search('None',$keys,true);
                if ($position === false) {
                    foreach (array_reverse(array_keys($keys)) as $candidate) {
                        if (!in_array($keys[$candidate], $definition['required'], true)) { $position = $candidate; break; }
                    }
                    unset($seen[$keys[$position]]);
                }
                $keys[$position] = $required; $seen[$required] = true;
            }
            $result[$view] = $keys;
        }
        return $result;
    }

    private function normalizeManualEntries($value, bool $strict = false): array
    {
        if ($strict) {
            if (is_string($value)) { $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
            if (!is_array($value) || !array_is_list($value) || count($value) > 500) {
                throw new \InvalidArgumentException('Manual contacts must be a list of at most 500 entries');
            }
        }
        $rows = $this->decodeArray($value); $out = [];
        $fields = ['display_name','label','extension','department','company','org_unit','site_name','mail','mobile','pager'];
        foreach (array_slice($rows,0,500) as $index => $row) {
            if ($strict) {
                if (!is_array($row)) { throw new \InvalidArgumentException('Manual contact ' . ($index + 1) . ' must be an object'); }
                foreach ($fields as $field) {
                    $text = $row[$field] ?? '';
                    $limit = in_array($field, ['extension','mobile','pager'], true) ? 64 : 255;
                    if (!is_scalar($text) || !mb_check_encoding((string)$text, 'UTF-8') || mb_strlen(trim((string)$text), 'UTF-8') > $limit) {
                        throw new \InvalidArgumentException('Manual contact ' . ($index + 1) . ' has an invalid or overlong ' . $field);
                    }
                }
                if (trim((string)($row['display_name'] ?? '')) === '' || trim((string)($row['extension'] ?? '')) === '') {
                    throw new \InvalidArgumentException('Manual contact ' . ($index + 1) . ' requires a display name and extension; complete or remove the row');
                }
            }
            if (!is_array($row)) { continue; }
            $clean = [];
            foreach ($fields as $field) { $clean[$field] = mb_substr(trim((string)($row[$field] ?? '')),0,in_array($field,['extension','mobile','pager'],true)?64:255,'UTF-8'); }
            if ($clean['display_name'] === '' || $clean['extension'] === '') { continue; }
            if ($clean['label'] === '') { $clean['label'] = $clean['display_name']; }
            $clean['attributes'] = $this->normalizeAttributeMap($row['attributes'] ?? []);
            $clean['groups'] = $this->normalizeStringList($row['groups'] ?? ($clean['attributes']['groups'] ?? []),64,100);
            $clean['menu_path'] = $this->normalizeMenuPath($row['menu_path'] ?? []);
            $clean['source_key'] = 'manual:' . sha1(json_encode([$clean['display_name'],$clean['extension'],$clean['label'],$clean['menu_path']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $out[] = $clean;
        }
        return $out;
    }

    public function resolve(array $input): array
    {
        $mode = (string)($input['mode'] ?? 'local');
        if ($mode === 'reconcile') {
            $rawIds = $input['conflict_ids'] ?? ($input['conflict_id'] ?? []);
            if (!is_array($rawIds)) {
                $rawIds = preg_split('/[\s,]+/', trim((string)$rawIds), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0)));
            if (!$ids || count($ids) > 500) {
                throw new \InvalidArgumentException('Choose between one and five hundred logical-binding conflicts');
            }
            $db = $this->ucm->getDb();
            $moves = [];
            $conflictRows = [];
            foreach ($ids as $id) {
                $stmt = $db->prepare('SELECT * FROM ucmp_directory_conflicts WHERE id=?');
                $stmt->execute([$id]);
                $conflict = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$conflict || (string)$conflict['conflict_type'] !== 'logical_binding' || (string)$conflict['status'] !== 'open') {
                    throw new \InvalidArgumentException('One selected logical-binding conflict is missing or no longer open; refresh the page');
                }
                $conflict['candidate_rows'] = json_decode((string)$conflict['candidates'], true) ?: [];
                $move = $this->logicalConflictMove($conflict);
                if (empty($move['available'])) {
                    throw new \RuntimeException((string)($move['message'] ?? 'The selected binding cannot be reconciled'));
                }
                $moves[] = [
                    'source_id'=>$move['source_id'],
                    'object_key'=>$move['object_key'],
                    'source_revision'=>$move['source_revision'],
                    'site_id'=>$move['desired']['site_id'],
                    'extension'=>$move['desired']['extension'],
                    'identity'=>$move['identity'],
                ];
                $conflictRows[] = ['row'=>$conflict, 'move'=>$move];
            }

            $db->beginTransaction();
            try {
                $applied = (new LogicalExtensionService($db))->reconcileAdIdentityMoves($moves);
                $resolve = $db->prepare(
                    "UPDATE ucmp_directory_conflicts
                        SET resolution_key=?,status='resolved_reconciled',updated=?
                      WHERE id=? AND status='open'"
                );
                $clearEntry = $db->prepare(
                    'UPDATE ucmp_directory_entries SET conflicted=0 WHERE source_id=? AND object_key=?'
                );
                foreach ($conflictRows as $item) {
                    $move = $item['move'];
                    $resolve->execute([$move['object_key'], time(), (int)$item['row']['id']]);
                    if ($resolve->rowCount() !== 1) {
                        throw new \RuntimeException('A selected conflict changed while reconciliation was in progress');
                    }
                    $clearEntry->execute([$move['source_id'], $move['object_key']]);
                }
                if (!$db->commit()) { throw new \RuntimeException('Directory conflict resolution commit was not confirmed; inspect accepted identities before retrying'); }
            } catch (\Throwable $error) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $error;
            }

            $sourceIds = array_values(array_unique(array_map(
                static fn(array $move): int => (int)$move['source_id'],
                $moves
            )));
            $warnings = [];
            foreach ($sourceIds as $sourceId) {
                try {
                    $sync = $this->sync(true, $sourceId);
                    if (empty($sync['ok'])) { $warnings[] = (string)($sync['message'] ?? "Source #$sourceId did not synchronize"); }
                } catch (\Throwable $error) {
                    $warnings[] = $error->getMessage();
                }
            }
            return [
                'ok'=>true,
                'reconciled'=>count($applied),
                'moves'=>$applied,
                'warnings'=>$warnings,
                'message'=>'Reconciled ' . count($applied) . ' AD identity binding move(s) without moving phones or extension-scoped policy'
                    . ($warnings ? '; the accepted bindings are saved, but a follow-up AD synchronization reported: ' . implode('; ', $warnings) : ''),
            ];
        }

        $id = (int)($input['conflict_id'] ?? 0);
        $st = $this->ucm->getDb()->prepare('SELECT * FROM ucmp_directory_conflicts WHERE id=?');
        $st->execute([$id]);
        $conflict = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$conflict) { throw new \InvalidArgumentException('Conflict not found'); }
        if ((string)$conflict['conflict_type'] !== 'duplicate_extension') {
            throw new \InvalidArgumentException(
                'This conflict cannot be resolved by choosing a local identity. Correct the AD site rule or reconcile the logical extension binding.'
            );
        }
        if ($mode === 'clear') {
            $this->ucm->getDb()->prepare("UPDATE ucmp_directory_conflicts SET resolution_key=NULL, status='open', updated=? WHERE id=?")
                ->execute([time(),$id]);
            return $this->sync(true, (int)$conflict['source_id']);
        }
        if ($mode === 'local') {
            $winner = trim((string)($input['winner'] ?? ''));
            $candidates = json_decode((string)$conflict['candidates'], true) ?: [];
            if (!array_filter($candidates, static fn($c) => (string)$c['source_key'] === $winner)) { throw new \InvalidArgumentException('Choose a valid winner'); }
            $this->ucm->getDb()->prepare("UPDATE ucmp_directory_conflicts SET resolution_key=?, status='resolved_local', updated=? WHERE id=?")->execute([$winner,time(),$id]);
            return $this->sync(true, (int)$conflict['source_id']);
        }
        if ($mode === 'writeback') {
            throw new \InvalidArgumentException(
                'Active Directory writeback is disabled. Correct the authoritative AD object, then run synchronization again.'
            );
        }
        throw new \InvalidArgumentException('Unknown conflict resolution mode');
    }

    public function createServiceAccount(array $input): array
    {
        $settings = $this->mergedSettings($input);
        $sam = trim((string)($input['service_sam'] ?? 'svc_ucmprovisioner'));
        $container = trim((string)($input['service_container_dn'] ?? ''));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,31}$/', $sam) || $container === '') { throw new \InvalidArgumentException('Enter a valid service login and target container DN'); }
        $dnsName = $this->dnsDomain((string)$settings['ad_base_dn']);
        if ($dnsName === '') { throw new \InvalidArgumentException('Select the AD domain/search base before creating the reader'); }
        $password = $this->randomPassword(28);
        if (!in_array($settings['ad_security'], ['ldaps', 'starttls'], true)) {
            return [
                'ok'=>true,
                'requires_local_run'=>true,
                'message'=>'Plain LDAP cannot set an Active Directory password. Run the generated PowerShell once on a domain controller or an RSAT workstation, then save and test the prefilled reader credentials.',
                'bind_user'=>$sam,
                'generated_password'=>$password,
                'bootstrap_script'=>$this->serviceAccountPowerShell($sam, $container, $dnsName, $password),
            ];
        }
        $adminUser = trim((string)($input['ad_admin_user'] ?? ''));
        $adminPass = (string)($input['ad_admin_pass'] ?? '');
        if ($adminUser === '' || $adminPass === '') { throw new \InvalidArgumentException('One-time AD administrator credentials are required'); }
        $conn = $this->connect($settings, $adminUser, $adminPass);
        $cn = 'UCM Provisioner LDAP Reader';
        $dn = 'CN=' . ldap_escape($cn, '', LDAP_ESCAPE_DN) . ',' . $container;
        $attrs = ['objectClass'=>['top','person','organizationalPerson','user'],'cn'=>$cn,'displayName'=>$cn,'sAMAccountName'=>$sam,'userPrincipalName'=>$sam . '@' . $dnsName,'userAccountControl'=>'514'];
        if (!ldap_add($conn, $dn, $attrs)) { throw new \RuntimeException('Could not create the AD user: ' . ldap_error($conn)); }
        $encoded = mb_convert_encoding('"' . $password . '"', 'UTF-16LE', 'UTF-8');
        if (!ldap_mod_replace($conn, $dn, ['unicodePwd'=>$encoded]) || !ldap_mod_replace($conn, $dn, ['userAccountControl'=>'512'])) {
            $error = ldap_error($conn);
            @ldap_delete($conn, $dn);
            throw new \RuntimeException('AD rejected the password/enable step, so the incomplete account was removed: ' . $error);
        }
        $this->ucm->setSetting('ad_bind_user', $sam, $this->ucm->localSiteId());
        $this->ucm->setSetting('ad_bind_password', $this->ucm->enc($password), $this->ucm->localSiteId());
        return ['ok'=>true,'message'=>'Read-only directory account created and saved. It was not added to any privileged group.','bind_user'=>$sam,'generated_password'=>$password];
    }

    private function compilePhonebook(array $book, array $entries): array
    {
        $book = $this->decodePhonebook($book);
        $excluded = array_fill_keys(array_map('strval', $book['exclusions']), true);
        $unique = [];
        foreach ($entries as $entry) {
            $source = trim((string)($entry['source_key'] ?? ''));
            if ($source === '') { $source = sha1(strtolower((string)($entry['display_name'] ?? '')) . "\0" . (string)($entry['extension'] ?? '')); }
            // Last row wins if a malformed pre-migration payload still contains one source twice.
            $unique[$source] = $entry + ['source_key'=>$source];
        }
        $contacts = [];
        foreach ($unique as $source => $entry) {
            // Current phonebooks store the namespaced public key. Before
            // multi-domain namespacing, exclusions stored the raw AD object
            // key; continue honoring that value so an upgrade cannot silently
            // re-include a deliberately hidden contact.
            $legacyObjectKey = trim((string)($entry['object_key'] ?? ''));
            if (isset($excluded[$source])
                || ($legacyObjectKey !== '' && isset($excluded[$legacyObjectKey]))) {
                continue;
            }
            if (($book['include_mode'] ?? 'all') === 'matching' && !$this->matchesRules($entry, $book['rules'])) { continue; }
            if (trim((string)($entry['display_name'] ?? '')) === '' || trim((string)($entry['extension'] ?? '')) === '') { continue; }
            $contacts[$source] = $this->safeContact($entry + ['source_key'=>$source]);
        }
        foreach ($book['manual_entries'] as $entry) {
            $contact = $this->safeContact($entry + ['manual'=>1]);
            if (!empty($contact['source_key'])) { $contacts[(string)$contact['source_key']] = $contact; }
        }
        $layout = $this->normalizePhonebookLayout([
            'directory_title'=>$book['title'] ?? $book['name'] ?? 'Company Directory',
            'directory_group_by'=>$book['group_by'] ?? 'department',
            'directory_name_format'=>$book['name_format'] ?? 'display_label',
            'directory_sort_by'=>$book['sort_by'] ?? 'display_name',
            'directory_include_mobile'=>!empty($book['include_mobile'])?'1':'0',
            'directory_cisco_page_size'=>(string)($book['cisco_page_size'] ?? 32),
            'directory_hide_uncategorised'=>!empty($book['hide_uncategorised'])?'1':'0',
            'directory_search_enabled'=>!empty($book['search_enabled'])?'1':'0',
            'directory_search_position'=>(string)($book['search_position'] ?? 'first'),
            'directory_search_scope'=>(string)($book['search_scope'] ?? 'root'),
            'directory_search_extension'=>!empty($book['search_extension'])?'1':'0',
            'directory_search_mobile'=>!empty($book['search_mobile'])?'1':'0',
            'directory_search_pager'=>!empty($book['search_pager'])?'1':'0',
            'directory_numeric_min_length'=>(string)($book['numeric_min_length'] ?? 1),
            'directory_mobile_result_mode'=>(string)($book['mobile_result_mode'] ?? 'find_extension'),
            'directory_mobile_editdial'=>!empty($book['mobile_editdial'])?'1':'0',
            'directory_cisco_softkeys'=>$book['cisco_softkeys'] ?? [],
        ]);
        $layout['hierarchy'] = $book['hierarchy'];
        foreach ($contacts as &$contact) {
            $contact['shown_name'] = $this->phonebookContactName($contact, $layout['name_format']);
        }
        unset($contact);
        uasort($contacts, static function (array $a, array $b) use ($layout): int {
            $field = $layout['sort_by'];
            $left = (string)($a[$field] ?? ''); $right = (string)($b[$field] ?? '');
            $sort = $field === 'extension' ? strnatcasecmp($left,$right) : strcasecmp($left,$right);
            return $sort ?: strcmp((string)($a['source_key'] ?? ''),(string)($b['source_key'] ?? ''));
        });
        $nodes = ['root'=>['id'=>'root','parent'=>null,'label'=>$layout['title'],'path'=>[],'children'=>[],'contacts'=>[],'count'=>0]];
        foreach ($contacts as $contactId => $contact) {
            $paths = !empty($contact['menu_path']) ? [$contact['menu_path']] : $this->contactPaths($contact,$book['hierarchy']['children'] ?? []);
            if (!$paths) { $paths = [[]]; }
            $seenPaths = [];
            foreach ($paths as $path) {
                $path = $this->normalizeMenuPath($path);
                $pathKey = json_encode(array_map('mb_strtolower',$path),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if (isset($seenPaths[$pathKey])) { continue; }
                $seenPaths[$pathKey] = true;
                $parent = 'root'; $walk = [];
                foreach ($path as $label) {
                    $walk[] = $label;
                    $nodeId = 'n-' . substr(hash('sha256',$parent . "\0" . mb_strtolower($label,'UTF-8')),0,24);
                    if (!isset($nodes[$nodeId])) { $nodes[$nodeId] = ['id'=>$nodeId,'parent'=>$parent,'label'=>$label,'path'=>$walk,'children'=>[],'contacts'=>[],'count'=>0]; }
                    if (!in_array($nodeId,$nodes[$parent]['children'],true)) { $nodes[$parent]['children'][] = $nodeId; }
                    $parent = $nodeId;
                }
                if (!in_array($contactId,$nodes[$parent]['contacts'],true)) { $nodes[$parent]['contacts'][] = $contactId; }
            }
        }
        foreach ($nodes as &$node) {
            usort($node['children'],static fn($a,$b) => strnatcasecmp((string)$nodes[$a]['label'],(string)$nodes[$b]['label']));
        }
        unset($node);
        $this->setNodeCounts($nodes,'root');
        return [
            'schema_version'=>2,
            'book_key'=>(string)$book['book_key'],'name'=>(string)$book['name'],'filename'=>(string)$book['filename'],
            'is_default'=>!empty($book['is_default']),'layout'=>$layout,'entries'=>array_values($contacts),'contacts'=>$contacts,
            'contact_order'=>array_keys($contacts),'root'=>'root','nodes'=>$nodes,
        ];
    }

    private function matchesRules(array $entry, array $rules): bool
    {
        $items = (array)($rules['items'] ?? []);
        if (!$items) { return false; }
        $results = [];
        foreach ($items as $rule) {
            $values = $this->sourceValues($entry,(string)$rule['field']);
            $needle = mb_strtolower(trim((string)$rule['value']),'UTF-8');
            $results[] = (bool)array_filter($values,static function ($value) use ($rule,$needle): bool {
                $value = mb_strtolower($value,'UTF-8');
                return match ($rule['operator']) {
                    'is' => $value === $needle,
                    'starts_with' => str_starts_with($value,$needle),
                    'ends_with' => str_ends_with($value,$needle),
                    default => str_contains($value,$needle),
                };
            });
        }
        return ($rules['match'] ?? 'all') === 'any' ? in_array(true,$results,true) : !in_array(false,$results,true);
    }

    private function safeContact(array $entry): array
    {
        $safe = [];
        foreach (['source_key','display_name','given_name','initial','surname','label','canonical_label','extension','department','company','org_unit','domain','site_name','mail','mobile','pager'] as $key) {
            $value = trim((string)($entry[$key] ?? ''));
            if ($value !== '') { $safe[$key] = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u','',$value),0,in_array($key,['extension','mobile','pager'],true)?64:255,'UTF-8'); }
        }
        if (!empty($entry['directory_label_override_set'])) {
            $safe['directory_label_override_set'] = 1;
            $safe['directory_label_override'] = mb_substr(
                preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string)($entry['directory_label_override'] ?? ''))),
                0,
                255,
                'UTF-8'
            );
        }
        foreach (['manual','conflicted'] as $key) { if (!empty($entry[$key])) { $safe[$key] = 1; } }
        $safe['ou_path'] = $this->normalizeStringList($entry['ou_path'] ?? [],64,8);
        $safe['attributes'] = $this->normalizeAttributeMap($entry['attributes'] ?? []);
        $safe['groups'] = $this->normalizeStringList($entry['groups'] ?? ($safe['attributes']['groups'] ?? []),64,100);
        $safe['menu_path'] = $this->normalizeMenuPath($entry['menu_path'] ?? []);
        foreach (['ou_path','attributes','groups','menu_path'] as $key) { if (!$safe[$key]) { unset($safe[$key]); } }
        return $safe;
    }

    /**
     * Apply audited canonical/final identity overrides before both preview and
     * publication so the designer shows the same names that phones receive.
     */
    private function resolveDirectoryIdentities(array $entries): array
    {
        $logical = new LogicalExtensionService($this->ucm->getDb());
        foreach ($entries as &$entry) {
            $extensionId = (int)($entry['extension_id'] ?? 0);
            if ($extensionId <= 0) { continue; }
            $attributes = $this->normalizeAttributeMap($entry['attributes'] ?? []);
            $first = static function (array $values, string $key): string {
                $value = $values[strtolower($key)] ?? '';
                if (is_array($value)) { $value = reset($value); }
                return is_scalar($value) ? trim((string)$value) : '';
            };
            $entry['given_name'] = $first($attributes, 'givenName');
            $entry['surname'] = $first($attributes, 'sn');
            $entry['initial'] = $first($attributes, 'initials');
            $entry['domain'] = (string)($entry['source_domain'] ?? '');
            $resolved = LogicalExtensionService::resolveIdentity(
                $entry + [
                    'ad_source_id'=>$entry['source_id'] ?? null,
                    'ad_source_revision'=>$entry['source_revision'] ?? null,
                ],
                $logical->overridesForExtension($extensionId)
            );
            foreach (LogicalExtensionService::CANONICAL_FIELDS as $field) {
                if (array_key_exists($field, $resolved['canonical'])) {
                    $entry[$field] = $resolved['canonical'][$field];
                }
            }
            $entry['canonical_label'] = (string)($resolved['canonical']['label'] ?? '');
            $entry['label'] = (string)$resolved['outputs']['directory_label'];
            if (($resolved['trace']['outputs']['directory_label']['source'] ?? '') === 'override') {
                $entry['directory_label_override_set'] = 1;
                $entry['directory_label_override'] = (string)$resolved['outputs']['directory_label'];
            }
        }
        unset($entry);
        return $entries;
    }

    private function phonebookContactName(array $entry, string $format): string
    {
        $display = trim((string)($entry['display_name'] ?? ''));
        $label = trim((string)($entry['label'] ?? ''));
        $extension = trim((string)($entry['extension'] ?? ''));
        return match ($format) {
            'display' => $display,
            'label_display' => $label !== '' ? $label . ($display !== '' ? ' — ' . $display : '') : $display,
            'label' => $label !== '' ? $label : $display,
            'display_extension' => $display . ($extension !== '' ? ' (' . $extension . ')' : ''),
            default => $display . ($label !== '' && strcasecmp($label, $display) !== 0 ? ' (' . $label . ')' : ''),
        };
    }

    private static function publicDirectorySourceKey(
        int $sourceId,
        string $sourceNamespace,
        string $objectKey
    ): string {
        $sourceNamespace = trim($sourceNamespace);
        if ($sourceNamespace === '') { $sourceNamespace = 'source-id:' . max(0, $sourceId); }
        // Keep the public identifier as a plain 64-character SHA-256 value.
        // Existing phonebook validation and exclusion storage deliberately
        // accept opaque hexadecimal identifiers, while the source namespace
        // prevents equal objectGUID values in two domains from colliding.
        return hash('sha256', $sourceNamespace . "\0" . $objectKey);
    }

    private function contactPaths(array $entry, array $rules): array
    {
        if (!$rules) { return [[]]; }
        $paths = [];
        foreach ($rules as $rule) {
            if (($rule['type'] ?? '') === 'branch') {
                if (!$this->whenMatches($entry,$rule['when'] ?? [])) { continue; }
                $tails = $this->contactPaths($entry,(array)($rule['children'] ?? []));
                foreach ($tails as $tail) { $paths[] = array_merge([(string)$rule['label']],$tail); }
            } elseif (($rule['type'] ?? '') === 'group') {
                $values = $this->sourceValues($entry,(string)($rule['source'] ?? ''));
                if (!$values) { $values = [(string)($rule['fallback'] ?? 'Other')]; }
                $aliases = (array)($rule['aliases'] ?? []); $lowerAliases = [];
                foreach ($aliases as $from=>$to) { $lowerAliases[mb_strtolower((string)$from,'UTF-8')] = (string)$to; }
                $tails = $this->contactPaths($entry,(array)($rule['children'] ?? []));
                foreach ($values as $value) {
                    $label = $lowerAliases[mb_strtolower($value,'UTF-8')] ?? $value;
                    $label = $this->cleanLabel($label,(string)($rule['fallback'] ?? 'Other'));
                    foreach ($tails as $tail) { $paths[] = array_merge([$label],$tail); }
                }
            }
        }
        $unique = [];
        foreach ($paths as $path) { $unique[json_encode($path,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)] = $path; }
        return array_values($unique);
    }

    private function whenMatches(array $entry, array $when): bool
    {
        $items = (array)($when['items'] ?? []);
        if (!$items) { return true; }
        $results = [];
        foreach ($items as $item) {
            $source = (string)($item['source'] ?? '');
            $needles = array_map(static fn($v)=>mb_strtolower((string)$v,'UTF-8'),(array)($item['value'] ?? []));
            $operator = (string)($item['operator'] ?? 'is');
            if ($source === 'ou_path' && $operator === 'under') {
                $match = false;
                $path = array_map(static fn($v)=>mb_strtolower($v,'UTF-8'),$this->normalizeMenuPath($entry['ou_path'] ?? []));
                foreach ($needles as $needle) {
                    $wanted = array_reverse(array_map(static fn($v)=>mb_strtolower($v,'UTF-8'),$this->normalizeMenuPath($needle)));
                    for ($i=0,$last=count($path)-count($wanted); $wanted && $i<=$last; $i++) {
                        if (array_slice($path,$i,count($wanted)) === $wanted) { $match = true; break 2; }
                    }
                }
                $results[] = $match;
                continue;
            }
            $values = array_map(static fn($v)=>mb_strtolower($v,'UTF-8'),$this->sourceValues($entry,$source));
            if ($operator === 'exists' || $operator === 'not_exists') { $match = (bool)$values; $results[] = $operator === 'exists' ? $match : !$match; continue; }
            $match = false;
            foreach ($values as $value) {
                foreach ($needles as $needle) {
                    $match = match ($operator) {
                        'contains' => str_contains($value,$needle),
                        'starts_with' => str_starts_with($value,$needle),
                        'ends_with' => str_ends_with($value,$needle),
                        default => $value === $needle,
                    };
                    if ($match) { break 2; }
                }
            }
            $results[] = $operator === 'is_not' ? !$match : $match;
        }
        return ($when['match'] ?? 'all') === 'any' ? in_array(true,$results,true) : !in_array(false,$results,true);
    }

    private function sourceValues(array $entry, string $source): array
    {
        $source = $this->normalizeSource($source);
        if ($source === '') { return []; }
        if ($source === 'ou_path') { return [implode(' / ',array_reverse($this->normalizeMenuPath($entry['ou_path'] ?? [])))]; }
        if (str_starts_with($source,'ou:')) {
            $path = (array)($entry['ou_path'] ?? []);
            return $this->normalizeStringList([$path[(int)substr($source,3)] ?? ''],64,1);
        }
        if ($source === 'groups') { return $this->normalizeStringList($entry['groups'] ?? (($entry['attributes']['groups'] ?? [])),64,100); }
        if (str_starts_with($source,'attr:')) {
            $attributes = $this->normalizeAttributeMap($entry['attributes'] ?? []);
            return $attributes[substr($source,5)] ?? [];
        }
        return $this->normalizeStringList([$entry[$source] ?? ''],255,1);
    }

    private function setNodeCounts(array &$nodes, string $id): array
    {
        if (!isset($nodes[$id]) || !empty($nodes[$id]['_counting'])) { return []; }
        $nodes[$id]['_counting'] = true;
        $ids = array_fill_keys((array)$nodes[$id]['contacts'],true);
        foreach ((array)$nodes[$id]['children'] as $child) { foreach ($this->setNodeCounts($nodes,$child) as $contactId) { $ids[$contactId] = true; } }
        $nodes[$id]['count'] = count($ids);
        unset($nodes[$id]['_counting']);
        return array_keys($ids);
    }

    private function leafContactGroups(array $compiled): array
    {
        $nodes = (array)($compiled['nodes'] ?? []); $out = [];
        foreach ($nodes as $id=>$node) {
            if (!empty($node['children'])) { continue; }
            $label = $id === 'root' || empty($node['path']) ? 'All contacts' : implode(' / ',(array)$node['path']);
            if (!empty($node['contacts'])) { $out[$label] = array_values(array_unique(array_map('strval',$node['contacts']))); }
        }
        if (!$out && !empty($nodes['root']['contacts'])) { $out['All contacts'] = (array)$nodes['root']['contacts']; }
        return $out;
    }

    private function normalizeCompiledPhonebook(array $book, array $catalog = []): ?array
    {
        if ((int)($book['schema_version'] ?? 0) !== 2 || !is_array($book['nodes'] ?? null)) { return null; }
        $rawContacts = is_array($book['contacts'] ?? null) ? $book['contacts'] : [];
        if (!$rawContacts && is_array($book['entries'] ?? null)) {
            foreach ($book['entries'] as $contact) { if (is_array($contact) && !empty($contact['source_key'])) { $rawContacts[(string)$contact['source_key']] = $contact; } }
        }
        $referenced = [];
        foreach ($book['nodes'] as $node) { if (is_array($node)) { foreach ((array)($node['contacts'] ?? []) as $id) { $referenced[(string)$id] = true; } } }
        if ($catalog) { $rawContacts = array_replace(array_intersect_key($catalog,$referenced),$rawContacts); }
        if (count($rawContacts) > 10000 || count($book['nodes']) > 50000) {
            throw new \LengthException('Compiled phonebook exceeds the supported publication capacity; previous cache retained');
        }
        $contacts = [];
        foreach (array_slice($rawContacts,0,10000,true) as $id=>$contact) {
            if (!is_array($contact)) { continue; }
            $id = trim((string)$id);
            if ($id === '' || strlen($id) > 128) { continue; }
            $safe = $this->safeContact($contact + ['source_key'=>$id]);
            if (!empty($safe['display_name']) && !empty($safe['extension'])) { $contacts[$id] = $safe; }
        }
        $ordered = [];
        foreach ((array)($book['contact_order'] ?? []) as $id) { if (!is_scalar($id)) { continue; } $id=(string)$id; if (isset($contacts[$id])) { $ordered[$id]=$contacts[$id]; } }
        foreach ($contacts as $id=>$contact) { if (!isset($ordered[$id])) { $ordered[$id]=$contact; } }
        $contacts = $ordered;
        $nodes = [];
        foreach (array_slice($book['nodes'],0,50000,true) as $id=>$node) {
            $id = (string)$id;
            if (!is_array($node) || !preg_match('/^(?:root|n-[a-f0-9]{24})$/',$id)) { continue; }
            if (count((array)($node['contacts'] ?? [])) > 10000 || count((array)($node['children'] ?? [])) > 500) {
                throw new \LengthException('Phonebook node exceeds the supported publication capacity; previous cache retained');
            }
            $parent = $id === 'root' ? null : (string)($node['parent'] ?? 'root');
            $nodes[$id] = [
                'id'=>$id,'parent'=>$parent,'label'=>$this->cleanLabel($node['label'] ?? ($id==='root'?'Company Directory':''),$id==='root'?'Company Directory':'Other'),
                'path'=>$this->normalizeMenuPath($node['path'] ?? []),'children'=>$this->normalizeStringList($node['children'] ?? [],64,500),
                'contacts'=>$this->normalizeStringList($node['contacts'] ?? [],128,10000),'count'=>0,
            ];
        }
        if (!isset($nodes['root'])) { return null; }
        foreach ($nodes as &$node) {
            $node['children'] = array_values(array_filter($node['children'],static fn($id)=>isset($nodes[$id])));
            $node['contacts'] = array_values(array_filter($node['contacts'],static fn($id)=>isset($contacts[$id])));
            if ($node['parent'] !== null && !isset($nodes[$node['parent']])) { $node['parent'] = 'root'; }
        }
        unset($node);
        $this->setNodeCounts($nodes,'root');
        $layoutValues = is_array($book['layout'] ?? null) ? $book['layout'] : [];
        $layout = $this->normalizePhonebookLayout([
            'directory_title'=>$layoutValues['title'] ?? $book['name'] ?? 'Company Directory',
            'directory_group_by'=>$layoutValues['group_by'] ?? 'none','directory_name_format'=>$layoutValues['name_format'] ?? 'display_label',
            'directory_sort_by'=>$layoutValues['sort_by'] ?? 'display_name','directory_include_mobile'=>!empty($layoutValues['include_mobile'])?'1':'0',
            'directory_cisco_page_size'=>(string)($layoutValues['cisco_page_size'] ?? 32),
            'directory_hide_uncategorised'=>!empty($layoutValues['hide_uncategorised'])?'1':'0',
            'directory_search_enabled'=>!empty($layoutValues['search_enabled'])?'1':'0',
            'directory_search_position'=>(string)($layoutValues['search_position'] ?? 'first'),
            'directory_search_scope'=>(string)($layoutValues['search_scope'] ?? 'root'),
            'directory_search_extension'=>!empty($layoutValues['search_extension'])?'1':'0',
            'directory_search_mobile'=>!empty($layoutValues['search_mobile'])?'1':'0',
            'directory_search_pager'=>!empty($layoutValues['search_pager'])?'1':'0',
            'directory_numeric_min_length'=>(string)($layoutValues['numeric_min_length'] ?? 1),
            'directory_mobile_result_mode'=>(string)($layoutValues['mobile_result_mode'] ?? 'find_extension'),
            'directory_mobile_editdial'=>!empty($layoutValues['mobile_editdial'])?'1':'0',
            'directory_cisco_softkeys'=>$layoutValues['cisco_softkeys'] ?? [],
        ]);
        $layout['hierarchy'] = $this->normalizeHierarchy($layoutValues['hierarchy'] ?? [],(string)$layout['group_by']);
        foreach ($contacts as &$contact) {
            $contact['shown_name'] = $this->phonebookContactName($contact, $layout['name_format']);
        }
        unset($contact);
        return [
            'schema_version'=>2,'book_key'=>mb_substr(trim((string)($book['book_key'] ?? 'company')) ?: 'company',0,64,'UTF-8'),
            'name'=>mb_substr(trim((string)($book['name'] ?? 'Company Directory')) ?: 'Company Directory',0,64,'UTF-8'),
            'filename'=>basename((string)($book['filename'] ?? 'company-directory.xml')),'is_default'=>!empty($book['is_default']),
            'layout'=>$layout,'entries'=>array_values($contacts),'contacts'=>$contacts,'contact_order'=>array_keys($contacts),'root'=>'root','nodes'=>$nodes,
        ];
    }

    public function acceptedSourceFreshness(): array
    {
        $rows=$this->ucm->getDb()->query('SELECT last_success,last_accepted_revision FROM ucmp_directory_sources WHERE enabled=1 ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => ['last_success'=>max(0,(int)($row['last_success'] ?? 0)),
            'last_accepted_revision'=>hash('sha256',(string)($row['last_accepted_revision'] ?? ''))],$rows);
    }

    public function publishSnapshotLocal(array $entries, string $token): array
    {
        return $this->withPublicationLock(fn(): array => $this->publishSnapshotLocked($entries, $token));
    }

    private function withPublicationLock(callable $publish): array
    {
        if ($this->ucm->getDb()->inTransaction()) { throw new \RuntimeException('Directory publication requires committed database state'); }
        $lock = ServiceFilePermissions::lock($this->cacheDir . '/.publication.lock');
        if ($lock === false) { throw new \RuntimeException('Another directory publication is in progress; retry after it finishes'); }
        try { return $publish(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function publishSnapshotLocked(array $entries, string $token): array
    {
        if (!preg_match('/^[A-Fa-f0-9]{8,128}$/', $token)) { throw new \InvalidArgumentException('Invalid directory token'); }
        $legacyLayout = $this->phonebookLayout();
        $definitions = [];
        $compiledDefinitions = [];
        $source = [];
        $sourceFreshness = [];
        $catalogVersion = 0;
        $contacts = [];
        $contactCount = 0;
        foreach ($entries as $entry) {
            $metadata = false;
            if (is_array($entry['__ucmp_layout'] ?? null)) {
                $legacyLayout = $this->normalizePhonebookLayout($entry['__ucmp_layout']);
                $metadata = true;
            }
            if (is_array($entry['__ucmp_phonebooks'] ?? null)) {
                $definitions = $entry['__ucmp_phonebooks'];
                $metadata = true;
            }
            if (is_array($entry['__ucmp_compiled_phonebooks'] ?? null)) {
                $compiledDefinitions = $entry['__ucmp_compiled_phonebooks'];
                $metadata = true;
            }
            if (is_array($entry['__ucmp_source'] ?? null)) {
                $source = [
                    'site_key'=>mb_substr(trim((string)($entry['__ucmp_source']['site_key'] ?? '')),0,64,'UTF-8'),
                    'site_name'=>mb_substr(trim((string)($entry['__ucmp_source']['site_name'] ?? '')),0,128,'UTF-8'),
                ];
                $metadata = true;
            }
            if (is_array($entry['__ucmp_source_freshness'] ?? null)) {
                foreach (array_slice($entry['__ucmp_source_freshness'],0,100) as $freshness) {
                    if (!is_array($freshness)) { continue; }
                    $sourceFreshness[]=['last_success'=>max(0,(int)($freshness['last_success'] ?? 0)),
                        'last_accepted_revision'=>preg_match('/^[a-f0-9]{64}$/D',(string)($freshness['last_accepted_revision'] ?? '')) ? $freshness['last_accepted_revision'] : ''];
                }
                $metadata=true;
            }
            if (isset($entry['__ucmp_version'])) { $catalogVersion = (int)$entry['__ucmp_version']; $metadata = true; }
            if ($metadata) { continue; }
            if (empty($entry['display_name']) || empty($entry['extension'])) { continue; }
            if ($contactCount >= 10000) { throw new \LengthException('Directory exceeds the supported publication capacity; previous cache retained'); }
            $contacts[] = $this->safeContact($entry);
            $contactCount++;
        }
        $deduped = [];
        foreach ($contacts as $contact) {
            $key = (string)($contact['source_key'] ?? sha1(strtolower((string)($contact['display_name'] ?? '')) . "\0" . (string)($contact['extension'] ?? '')));
            $deduped[$key] = $contact;
        }
        $contactCatalog = $deduped;
        $contacts = array_values($deduped);
        $books = [];
        foreach ($compiledDefinitions as $compiled) {
            if (is_array($compiled) && ($clean = $this->normalizeCompiledPhonebook($compiled,$contactCatalog))) { $books[] = $clean; }
        }
        if (!$books) {
            foreach ($definitions as $definition) {
                if (is_array($definition)) { $books[] = $this->compilePhonebook($definition, $contacts); }
            }
        }
        if (!$books && $catalogVersion < 2) {
            $books[] = ['book_key'=>'company','name'=>$legacyLayout['title'],'filename'=>'company-directory.xml','is_default'=>true,'layout'=>$legacyLayout,'entries'=>array_values($contacts)];
        }
        $default = $books ? $books[0] : ['layout'=>$legacyLayout,'entries'=>[]];
        foreach ($books as $book) {
            if (!empty($book['is_default'])) {
                $default = $book;
                break;
            }
        }
        $configuredDefault = method_exists($this->ucm, 'getSetting')
            ? trim((string)$this->ucm->getSetting('default_phonebook_key', $this->ucm->localSiteId(), ''))
            : '';
        $bookKeys = array_fill_keys(array_map(static fn(array $book): string => (string)($book['book_key'] ?? ''), $books), true);
        $defaultKey = $configuredDefault !== '' && isset($bookKeys[$configuredDefault])
            ? $configuredDefault
            : (string)($default['book_key'] ?? '');
        $generated = time();
        $payloadJson = (string)json_encode([
            'generated'=>$generated,'catalog_version'=>$catalogVersion,'source'=>$source,'source_freshness'=>$sourceFreshness,
            'token_sha256'=>hash('sha256',$token),
            'default_book_key'=>$defaultKey,'phonebooks'=>$books,'layout'=>$default['layout'],'entries'=>$default['entries']
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $this->writeDirectoryCacheFile('directory.json', $payloadJson);
        $warnings = [];
        try { $this->writeDirectoryCacheFile('token', $token . "\n"); }
        catch (\Throwable $error) { $warnings[] = 'Directory snapshot published; legacy token companion needs repair: ' . $error->getMessage(); }
        // The token digest and contact bytes have one atomic publication point.
        // The bearer token remains separate for compatibility and never enters the registry.
        $phonebookAsset = null;
        $registryWarning = null;
        try {
            $phonebookAsset = $this->registerPublishedPhonebook($payloadJson, $catalogVersion);
        } catch (\Throwable $e) {
            // The phone-facing cache is already complete. Inventory is
            // advisory and must not turn this successful delivery into a retry.
            $registryWarning = 'Directory cache published, but managed asset inventory was not updated: '
                . mb_substr(trim($e->getMessage()), 0, 500, 'UTF-8');
        }
        $base = $this->ucm->directoryBaseUrl($this->ucm->localSiteId()) . '?token=' . rawurlencode($token);
        // Legacy 7940/7960 SIP firmware corrupts '&' query separators. Keep its
        // initial service URL query-free; directory.php carries navigation in PATH_INFO.
        $legacyBase = preg_replace('#/ucmprovisioner-directory\.php$#', '/ucmp-d.php/' . rawurlencode($token), $this->ucm->directoryBaseUrl($this->ucm->localSiteId()));
        if ($registryWarning !== null) { $warnings[] = $registryWarning; }
        foreach (['directory_token'=>$token, 'cisco_directory_url'=>$base . '&format=cisco',
            'cisco_legacy_directory_url'=>(string)$legacyBase, 'yealink_phonebook_url'=>$base . '&format=yealink',
            'grandstream_phonebook_url'=>$base . '&format=grandstream'] as $key=>$value) {
            try { $this->ucm->setSetting($key, $value, $this->ucm->localSiteId()); }
            catch (\Throwable $e) { $warnings[] = 'Directory cache published, but URL setting ' . $key . ' could not be saved'; }
        }
        $result = [
            'ok'=>true,
            'message'=>count($books) . ' phonebook(s) cached locally; ' . count($default['entries']) . ' contacts in the default book',
            'count'=>count($default['entries']),
            'books'=>count($books),
            'base_url'=>$base,
        ];
        if ($phonebookAsset !== null) {
            $result['asset'] = [
                'asset_key'=>$phonebookAsset['asset_key'],
                'content_sha256'=>$phonebookAsset['content_sha256'],
                'filename'=>$phonebookAsset['filename'],
                'size_bytes'=>$phonebookAsset['size_bytes'],
                'state'=>$phonebookAsset['state'],
            ];
        }
        if ($warnings) {
            $result['warning'] = implode('; ', $warnings);
            $result['warnings'] = $warnings;
        }
        return $result;
    }

    private function writeDirectoryCacheFile(string $name, string $content): void
    {
        if (!in_array($name, ['directory.json','token'], true)) { throw new \InvalidArgumentException('Invalid directory cache filename'); }
        $dir = $this->cacheDir;
        if (strlen($content) > 67108864) { throw new \LengthException('Directory cache exceeds the supported size'); }
        ServiceFilePermissions::directory($dir);
        ServiceFilePermissions::assertNoLinks($dir . '/' . $name);
        $tmp = @tempnam($dir, '.ucmp-directory-');
        if ($tmp === false) { throw new \RuntimeException('Cannot create a temporary PBX directory cache file'); }
        try {
            if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) { throw new \RuntimeException('Cannot write the complete PBX directory cache file'); }
            ServiceFilePermissions::apply($tmp, 0640);
            if (!@rename($tmp, $dir . '/' . $name)) { throw new \RuntimeException('Cannot replace the PBX directory cache file'); }
        } finally {
            if (is_file($tmp)) { @unlink($tmp); }
        }
    }

    public function setPublishedDefaultPhonebook(string $bookKey): array
    {
        return $this->withPublicationLock(fn(): array => $this->setPublishedDefaultPhonebookLocked($bookKey));
    }

    private function setPublishedDefaultPhonebookLocked(string $bookKey): array
    {
        $bookKey = strtolower(trim($bookKey));
        if ($bookKey !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/D', $bookKey)) { throw new \InvalidArgumentException('Invalid default phonebook key'); }
        if (method_exists($this->ucm,'getSetting') && $bookKey !== trim((string)$this->ucm->getSetting('default_phonebook_key',$this->ucm->localSiteId(),''))) {
            throw new \RuntimeException('The saved default changed before publication; reload its current value');
        }
        $payload = $this->readPublishedCache();
        if (!is_array($payload)) { throw new \RuntimeException('No published phonebook cache exists on this PBX'); }
        $publisherDefault = '';
        $available = [];
        foreach ((array)($payload['phonebooks'] ?? []) as $book) {
            if (!is_array($book)) { continue; }
            $key = strtolower(trim((string)($book['book_key'] ?? '')));
            if ($key === '') { continue; }
            $available[$key] = true;
            if (!empty($book['is_default'])) { $publisherDefault = $key; }
        }
        if ($bookKey !== '' && !isset($available[$bookKey])) { throw new \RuntimeException('The selected phonebook is not present in this PBX published cache'); }
        $payload['default_book_key'] = $bookKey !== '' ? $bookKey : $publisherDefault;
        $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $this->writeDirectoryCacheFile('directory.json', $json);
        $result = ['ok'=>true,'default_book_key'=>$payload['default_book_key'],'message'=>'Default published phonebook updated'];
        try {
            $this->registerPublishedPhonebook($json, (int)($payload['catalog_version'] ?? 4));
        } catch (\Throwable $error) {
            // The cache is already active. Keep phone service available, but do
            // not hide the fact that Asset Index could not record its new hash.
            $result['warning'] = 'Default phonebook updated, but managed asset inventory was not updated: '
                . mb_substr(trim($error->getMessage()), 0, 500, 'UTF-8');
            $result['warnings'] = [$result['warning']];
        }
        return $result;
    }

    public function copyPublishedPhonebookPolicy(string $bookKey): array
    {
        $bookKey = strtolower(trim($bookKey));
        if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $bookKey)) { throw new \InvalidArgumentException('Choose a published phonebook'); }
        $payload = $this->readPublishedCache();
        if (!is_array($payload)) { throw new \RuntimeException('No published phonebook cache exists on this PBX'); }
        $selected = null;
        foreach ((array)($payload['phonebooks'] ?? []) as $book) {
            if (is_array($book) && hash_equals($bookKey, strtolower((string)($book['book_key'] ?? '')))) { $selected = $book; break; }
        }
        if (!$selected) { throw new \RuntimeException('Published phonebook was not found in this PBX cache'); }
        $layout = is_array($selected['layout'] ?? null) ? $selected['layout'] : [];
        $localSite = method_exists($this->ucm, 'getSite') ? (array)($this->ucm->getSite($this->ucm->localSiteId()) ?? []) : [];
        $localKey = trim((string)($localSite['site_key'] ?? ''));
        $id = $this->savePhonebook([
            'name'=>mb_substr(trim((string)($selected['name'] ?? $bookKey)) . ' (local AD)',0,64,'UTF-8'),
            'title'=>(string)($layout['title'] ?? $selected['name'] ?? 'Company Directory'),
            'include_mode'=>'all','rules'=>$layout,'exclusions'=>[],'manual_entries'=>[],
            'group_by'=>(string)($layout['group_by'] ?? 'department'),'hierarchy'=>$layout['hierarchy'] ?? [],
            'publish_sites'=>$localKey !== '' ? [$localKey] : ['*'],
            'name_format'=>(string)($layout['name_format'] ?? 'display_label'),'sort_by'=>(string)($layout['sort_by'] ?? 'display_name'),
            'include_mobile'=>!empty($layout['include_mobile']),'cisco_page_size'=>(int)($layout['cisco_page_size'] ?? 32),
            'hide_uncategorised'=>!empty($layout['hide_uncategorised']),'search_enabled'=>!empty($layout['search_enabled']),
            'search_position'=>(string)($layout['search_position'] ?? 'first'),'search_scope'=>(string)($layout['search_scope'] ?? 'root'),
            'search_extension'=>!empty($layout['search_extension']),'search_mobile'=>!empty($layout['search_mobile']),
            'search_pager'=>!empty($layout['search_pager']),'numeric_min_length'=>(int)($layout['numeric_min_length'] ?? 1),
            'mobile_result_mode'=>(string)($layout['mobile_result_mode'] ?? 'find_extension'),'mobile_editdial'=>!empty($layout['mobile_editdial']),
            'cisco_softkeys'=>$layout['cisco_softkeys'] ?? [],'is_default'=>false,
        ]);
        return ['ok'=>true,'phonebook_id'=>$id,'message'=>'Copied the presentation and search policy into a new local AD-backed phonebook; contacts and credentials were not copied'];
    }

    /** @return array<string,mixed> */
    private function registerPublishedPhonebook(string $payloadJson, int $catalogVersion): array
    {
        $siteId = $this->ucm->localSiteId();
        $contentSha256 = hash('sha256', $payloadJson);
        $sizeBytes = strlen($payloadJson);
        $assetRegistry = new ManagedAssetRegistry($this->ucm->getDb());
        $asset = $assetRegistry->upsert([
            'site_id'=>$siteId,
            'kind'=>'phonebook',
            'content_sha256'=>$contentSha256,
            'filename'=>'directory.json',
            'size_bytes'=>$sizeBytes,
            'content_type'=>'application/json',
            'role'=>'catalog',
            'profile'=>'managed-directory',
            'asset_version'=>(string)max(1, $catalogVersion),
            'bundle_key'=>'phonebook-cache',
            'bundle_role'=>'catalog',
            'relative_path'=>'directory/directory.json',
            'validation_status'=>'verified',
            'provenance'=>'managed_write',
            'manifest'=>[[
                'role'=>'catalog',
                'relative_path'=>'directory/directory.json',
                'filename'=>'directory.json',
                'size_bytes'=>$sizeBytes,
                'content_sha256'=>$contentSha256,
                'content_type'=>'application/json',
                'required'=>true,
                'status'=>'present',
            ]],
            'validated_at'=>time(),
            'signature_status'=>'not_applicable',
            'metadata'=>['format'=>'json'],
        ]);
        $purge = $this->ucm->getDb()->prepare(
            "DELETE FROM ucmp_assets
              WHERE site_id=? AND kind='phonebook' AND profile='managed-directory'
                AND bundle_key='phonebook-cache' AND asset_key<>?"
        );
        $purge->execute([$siteId,(string)$asset['asset_key']]);
        return $asset;
    }

    private function readPublishedCache(): ?array
    {
        $path = $this->cacheDir . '/directory.json';
        ServiceFilePermissions::assertNoLinks($path);
        if (!file_exists($path)) { return null; }
        $bytes = @file_get_contents($path,false,null,0,67108865);
        if ($bytes === false || strlen($bytes)>67108864) { throw new \RuntimeException('Directory cache could not be read within its size limit'); }
        $payload = json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new \RuntimeException('Directory cache is malformed'); }
        return $payload;
    }

    public function publishedPhonebooks(): array
    {
        $payload = $this->readPublishedCache();
        if (!is_array($payload)) { return ['ok'=>false,'phonebooks'=>[],'message'=>'No published phonebook cache exists on this PBX']; }
        $out = [];
        $defaultKey = strtolower(trim((string)($payload['default_book_key'] ?? '')));
        foreach ((array)($payload['phonebooks'] ?? []) as $book) {
            if (!is_array($book) || empty($book['book_key'])) { continue; }
            $bookKey = (string)$book['book_key'];
            $out[] = [
                'book_key'=>$bookKey,'name'=>(string)($book['name'] ?? $bookKey),
                'filename'=>(string)($book['filename'] ?? ''),'is_default'=>$defaultKey !== '' ? hash_equals($defaultKey, strtolower($bookKey)) : !empty($book['is_default']),
                'publisher_default'=>!empty($book['is_default']),
                'contacts'=>count((array)($book['entries'] ?? [])),
            ];
        }
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $localSite = method_exists($this->ucm, 'getSite') ? (array)($this->ucm->getSite($this->ucm->localSiteId()) ?? []) : [];
        $replicated = trim((string)($source['site_key'] ?? '')) !== ''
            && !hash_equals((string)($source['site_key'] ?? ''), (string)($localSite['site_key'] ?? ''));
        return ['ok'=>true,'phonebooks'=>$out,'default_book_key'=>$defaultKey,'generated'=>(int)($payload['generated'] ?? 0),'source'=>$source,'replicated'=>$replicated,'message'=>count($out) . ' published phonebook(s) available'];
    }

    /** Resolve directly from accepted source/person identity, including people without a number. */
    public function resolvePerson(int $sourceId, string $objectKey): ?array
    {
        $stmt = $this->ucm->getDb()->prepare('SELECT d.* FROM ucmp_directory_entries d JOIN ucmp_directory_sources s ON s.id=d.source_id AND s.last_accepted_revision=d.source_revision WHERE d.source_id=? AND d.object_key=?');
        $stmt->execute([$sourceId,$objectKey]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== 1) { return null; }
        $rows[0]['attributes'] = $this->normalizeAttributeMap($rows[0]['attributes'] ?? []);
        return $rows[0];
    }

    public function resolveExtensionIdentity(int $siteId, string $extension, ?callable $formatter = null): ?array
    {
        $logical = new LogicalExtensionService($this->ucm->getDb());
        $row = $logical->findBySiteExtension($siteId, $extension);
        $binding = $row ? LogicalExtensionService::identityBinding($row) : null;
        if (!$row || ($binding['mode']==='ad_primary' && $binding['source_id']===0)) {
            $cached = IdentityDeliveryService::resolveCached($this->ucm,$siteId,$extension,$formatter);
            if ($cached) { return $cached; }
        }
        if (!$row) { return null; }
        $person = $binding['source_id'] > 0 ? $this->resolvePerson($binding['source_id'], $binding['object_key']) : null;
        return LogicalExtensionService::resolveBoundIdentity($row, $person, $logical->overridesForExtension((int)$row['id'], true), $formatter)
            + ['extension_id'=>(int)$row['id'],'extension_revision'=>(int)$row['revision'],
                'accepted_at'=>(int)($person['last_seen_at'] ?? 0),'source_revision'=>(string)($person['source_revision'] ?? '')];
    }

    /** Uses the same destination read/check path even when the accepted source hash is unchanged. */
    public function refreshIdentities(bool $force = false, array $scope = []): array
    {
        $identity = $this->applyIdentities($this->entries(false, 0), $this->settings(), $force, $scope);
        $published = $this->publishToSites($this->entries(true, 0));
        $identity['published'] = $published;
        $identity['warnings'] = array_merge($identity['warnings'], $published['warnings']);
        $identity['queued'] += $published['queued'];
        return $identity;
    }

    /** Review uses only the accepted local catalog and predicts, but does not persist, delivery revisions. */
    public function planIdentityPackets(array $scope = []): array
    {
        return $this->applyIdentities($this->entries(false, 0), $this->settings(), true, $scope, true);
    }

    private function applyIdentities(array $entries, array $settings, bool $force = false, array $scope = [], bool $dryRun = false): array
    {
        $db = $this->ucm->getDb();
        $logical = new LogicalExtensionService($db);
        $origin = (array)$this->ucm->getSite($this->ucm->localSiteId());
        if (empty($origin['site_key'])) { throw new \RuntimeException('Directory origin site identity is unavailable'); }
        $sites = [];
        foreach ($this->ucm->getSites() as $site) { $sites[(int)$site['id']]=$site; }
        $sourceStates = [];
        foreach ($db->query('SELECT id,last_accepted_revision,last_success FROM ucmp_directory_sources')->fetchAll(\PDO::FETCH_ASSOC) as $sourceState) {
            $sourceStates[(int)$sourceState['id']]=$sourceState;
        }
        $bindings = $logical->enumerateBindings();
        $skipped = [];
        foreach ($bindings as $row) {
            $binding = LogicalExtensionService::identityBinding($row);
            if ($binding['mode'] !== 'linked_additional' && $binding['mode'] !== 'manual_shared') { continue; }
            $person = $binding['source_id'] > 0 ? $this->resolvePerson($binding['source_id'], $binding['object_key']) : [];
            if ($person === null) {
                $skipped[] = ['extension_id'=>(int)$row['id'],'reason'=>'Stable linked person is absent from the accepted source; previous destination identity retained'];
                continue;
            }
            $entries[] = array_merge($person, ['site_id'=>$row['site_id'],'extension'=>$row['extension'],'extension_id'=>$row['id'],'extension_revision'=>$row['revision'],'desired_identity_hash'=>$row['desired_identity_hash'],'applied_identity_hash'=>$row['applied_identity_hash'],'source_id'=>$binding['source_id'],'object_key'=>$binding['object_key'],'source_key'=>$binding['source_id'] . ':' . $binding['object_key']]);
        }
        $bySite = [];
        foreach ($entries as $entry) {
            if (empty($entry['site_id']) || empty($entry['extension'])) { continue; }
            if ((!empty($scope['site_id']) && (int)$scope['site_id'] !== (int)$entry['site_id'])
                || (!empty($scope['source_id']) && (int)$scope['source_id'] !== (int)($entry['source_id'] ?? 0))
                || (!empty($scope['object_key']) && $scope['object_key'] !== ($entry['object_key'] ?? ''))
                || (!empty($scope['extension_ids']) && !in_array((int)($entry['extension_id'] ?? 0), array_map('intval', $scope['extension_ids']), true))) { continue; }
            $row = !empty($entry['extension_id']) ? $logical->findById((int)$entry['extension_id'])
                : $logical->findBySiteExtension((int)$entry['site_id'], (string)$entry['extension']);
            if ($row && empty($entry['extension_id'])) {
                // A source row may remain at this number after an operator opts
                // the slot into manual/additional mode. Its join is intentionally
                // gone; absence of that join is not permission to bypass ownership.
                $skipped[] = ['extension_id'=>(int)$row['id'],'reason'=>'Directory row has no current primary binding; explicit slot ownership retained'];
                continue;
            }
            if (!$row || (int)$row['site_id'] !== (int)$entry['site_id'] || (string)$row['extension'] !== (string)$entry['extension']) {
                $skipped[] = ['extension_id'=>(int)($entry['extension_id'] ?? 0),'reason'=>'Accepted person has no matching owner-site logical slot'];
                continue;
            }
            if (empty($sites[(int)$row['site_id']]['enabled'])) {
                $skipped[] = ['extension_id'=>(int)$row['id'],'reason'=>'Owner site is disabled or unavailable'];
                continue;
            }
            if ($row && ($row['identity_adoption'] ?? 'managed') !== 'managed') {
                $skipped[] = ['extension_id'=>(int)$row['id'],'reason'=>'Legacy identity fields require adoption review'];
                continue;
            }
            $overrides = !empty($entry['extension_id'])
                ? $logical->overridesForExtension((int)$entry['extension_id'])
                : [];
            $resolved = $row ? LogicalExtensionService::resolveBoundIdentity($row, $entry, $overrides) : LogicalExtensionService::resolveIdentity(
                $entry + [
                    'ad_source_id'=>$entry['source_id'] ?? null,
                    'ad_source_revision'=>$entry['source_revision'] ?? null,
                ],
                $overrides
            );
            if (empty($resolved['resolved'])) {
                $skipped[] = ['extension_id'=>(int)$row['id'],'reason'=>'The accepted person or manual identity is unresolved'];
                continue;
            }
            $hash = hash(
                'sha256',
                'identity-v3' . "\0" . $resolved['effective_hash']
                . "\0" . $settings['ad_sync_names'] . $settings['ad_sync_labels']
            );
            $extensionRevision = (int)$row['revision'];
            $baseRevision = $extensionRevision;
            if (!empty($entry['extension_id'])
                && !hash_equals($hash, (string)($entry['desired_identity_hash'] ?? ''))
            ) {
                $extensionRevision++;
                if (!$dryRun) { $update = $db->prepare(
                    "UPDATE ucmp_extensions
                        SET desired_identity_hash=?,apply_status='pending',apply_error=NULL,
                            revision=?,updated=?
                      WHERE id=? AND revision=?"
                );
                $update->execute([$hash,$extensionRevision,time(),(int)$entry['extension_id'],$baseRevision]);
                if ($update->rowCount() !== 1) { throw new \DomainException('Identity relationship changed during delivery planning'); }
                }
            }
            if (!$dryRun && !$force && ($row['apply_status'] ?? '')!=='skipped' && hash_equals($hash, (string)($row['applied_identity_hash'] ?? $entry['applied_hash'] ?? ''))) {
                if (!empty($entry['extension_id']) && ($row['apply_status'] ?? '') !== 'applied') {
                    $logical->markApplied((int)$entry['extension_id'], $extensionRevision, $hash);
                }
                continue;
            }
            $bySite[(int)$entry['site_id']][] = [
                'site_id'=>(int)$entry['site_id'],
                'extension'=>$entry['extension'],
                'display_name'=>$resolved['canonical']['display_name'],
                'label'=>$resolved['outputs']['phone_label'],
                'sync_name'=>$settings['ad_sync_names']==='1',
                'sync_label'=>$settings['ad_sync_labels']==='1',
                'source_key'=>$entry['source_key'],
                'source_id'=>(int)($entry['source_id'] ?? 0),
                'object_key'=>(string)($entry['object_key'] ?? $entry['source_key']),
                'extension_id'=>(int)($entry['extension_id'] ?? 0),
                'extension_revision'=>$extensionRevision,
                'base_revision'=>$baseRevision,
                'hash'=>$hash,
                'force'=>$force,
                'caller_name_mode'=>'managed',
                'binding_mode'=>$row['binding_mode'] ?? 'ad_primary',
                'adoption'=>'managed',
                'origin_site_key'=>(string)$origin['site_key'],
                'identity_site_key'=>(string)$sites[(int)$row['site_id']]['site_key'],
                'canonical'=>$resolved['canonical'],
                'final_overrides'=>array_values(array_filter($overrides, static fn(array $override): bool => ($override['mode'] ?? '') === 'final')),
                'source_revision'=>(string)($sourceStates[(int)($entry['source_id'] ?? 0)]['last_accepted_revision'] ?? ''),
                'accepted_at'=>(int)($sourceStates[(int)($entry['source_id'] ?? 0)]['last_success'] ?? $row['updated']),
            ];
        }
        foreach ($this->identityReleases() as $release) {
            $row = $logical->findById((int)($release['extension_id'] ?? 0));
            if (!$row || !empty($row['ad_source_id']) || ($row['binding_mode'] ?? '') !== 'ad_primary'
                || ($row['identity_adoption'] ?? '') !== 'managed'
                || !self::identityScopeMatches($release,$scope) || empty($sites[(int)$row['site_id']]['enabled'])) { continue; }
            if ((int)$row['revision'] !== (int)$release['extension_revision']) { continue; }
            if (!$force && !$dryRun && ($row['apply_status'] ?? '')!=='skipped' && hash_equals((string)($row['applied_identity_hash'] ?? ''),$release['hash'])) { continue; }
            if (!$dryRun) {
                $db->prepare("UPDATE ucmp_extensions SET desired_identity_hash=?,apply_status='pending',updated=? WHERE id=? AND revision=?")
                    ->execute([$release['hash'],time(),$row['id'],$row['revision']]);
            }
            $release['force']=$force;
            $bySite[(int)$row['site_id']][]=$release;
        }
        $targets = array_merge([], ...array_values($bySite));
        if ($dryRun) { return ['targets'=>$targets,'skipped'=>$skipped,'force'=>$force]; }
        $changed = 0; $queued = 0; $warnings = [];
        $batches = [];
        foreach ($bySite as $siteId=>$items) { foreach (array_chunk($items,128) as $batch) { $batches[]=['site_id'=>$siteId,'items'=>$batch]; } }
        foreach ($batches as $deliveryBatch) {
            $siteId = (int)$deliveryBatch['site_id']; $batch=$deliveryBatch['items'];
            try {
                $result = $this->ucm->applyDirectoryIdentities($siteId, $batch);
            } catch (\Throwable $e) {
                $warnings[] = 'Site #' . $siteId . ': ' . $e->getMessage();
                $result = ['results'=>[]];
                foreach ($batch as $item) { $result['results'][$item['extension']] = ['ok'=>false,'message'=>$e->getMessage()]; }
            }
            $siteNeedsUpgrade = false;
            foreach ($batch as $item) {
                try {
                    $r = $result['results'][$item['extension']] ?? ['ok'=>false,'message'=>'No result returned'];
                    if (!empty($r['skipped'])) {
                        $reason=(string)($r['message'] ?? 'Identity target excluded by destination review');
                        if (!$this->recordIdentitySkip($item,$reason)) { throw new \DomainException('Identity relationship changed before exclusion could be recorded'); }
                        $skipped[]=['extension_id'=>$item['extension_id'],'site_id'=>$siteId,'extension'=>$item['extension'],'reason'=>$reason];
                        $warnings[]='Site #'.$siteId.' / '.$item['extension'].': skipped - '.$reason;
                        continue;
                    }
                    $callerIdConfirmed = empty($item['sync_name']) || !empty($r['callerid_checked']);
                    if (!empty($r['ok']) && $callerIdConfirmed) {
                        if (!empty($r['changed'])) { $changed++; }
                        if ($item['extension_id'] > 0 && !$logical->markApplied($item['extension_id'],$item['extension_revision'],$item['hash'])) {
                            $warnings[] = $item['extension'] . ': delivery completed for an older relationship; current desired state was not acknowledged';
                            continue;
                        }
                        $db->prepare(
                            'UPDATE ucmp_directory_entries SET applied_hash=?
                              WHERE source_id=? AND object_key=?'
                        )->execute([$item['hash'],$item['source_id'],$item['object_key']]);
                        $db->prepare(
                            "UPDATE ucmp_site_jobs
                                SET state='completed',updated=?,last_error=NULL
                              WHERE site_id=? AND job_type='directory_identity'
                                AND object_key=? AND desired_hash=? AND state<>'completed'"
                        )->execute([time(),$siteId,self::identityJobKey($item),$item['hash']]);
                    } elseif (!empty($r['ok'])) {
                        $siteNeedsUpgrade = true;
                        $error = 'The remote module did not confirm the FreePBX caller-ID update';
                        if ($item['extension_id'] > 0) {
                            $logical->markApplyFailed(
                                $item['extension_id'],
                                $item['extension_revision'],
                                $item['hash'],
                                $error
                            );
                        }
                        $this->queueIdentityJob($siteId, $item, $error);
                        $queued++;
                    } else {
                        $error = (string)($r['message'] ?? 'identity update failed');
                        $warnings[] = $item['extension'] . ': ' . $error;
                        if ($item['extension_id'] > 0) {
                            $logical->markApplyFailed(
                                $item['extension_id'],
                                $item['extension_revision'],
                                $item['hash'],
                                $error
                            );
                        }
                        $this->queueIdentityJob($siteId, $item, $error);
                        $queued++;
                    }
                } catch (\Throwable $e) {
                    $warnings[] = 'Site #' . $siteId . ' / ' . $item['extension']
                        . ': delivery receipt or retry could not be recorded: ' . $e->getMessage();
                }
            }
            if ($siteNeedsUpgrade) { $warnings[] = 'Site #' . $siteId . ': update the remote module to apply AD names to the FreePBX device caller-ID field'; }
        }
        return ['changed'=>$changed,'queued'=>$queued,'warnings'=>$warnings,'skipped'=>$skipped,'targets'=>$targets,'force'=>$force];
    }

    private static function identityScopeMatches(array $item, array $scope): bool
    {
        return (empty($scope['site_id']) || (int)$scope['site_id'] === (int)($item['site_id'] ?? 0))
            && (empty($scope['source_id']) || (int)$scope['source_id'] === (int)($item['source_id'] ?? 0))
            && (empty($scope['object_key']) || $scope['object_key'] === ($item['object_key'] ?? ''))
            && (empty($scope['extension_ids']) || in_array((int)($item['extension_id'] ?? 0), array_map('intval',$scope['extension_ids']), true));
    }

    /** Retained delivery tombstones are not an alternate person authority. */
    private function identityReleases(): array
    {
        $raw = (string)$this->ucm->getSetting('identity_delivery_releases',$this->ucm->localSiteId(),'');
        if ($raw === '') { return []; }
        if (strlen($raw)>8388608) { throw new \LengthException('Identity release receipts exceed their bound'); }
        $rows = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($rows) || count($rows)>10000) { throw new \RuntimeException('Identity release receipts are invalid'); }
        return $rows;
    }

    /** Called only after complete accepted-source binding reconciliation. */
    private function rememberIdentityReleases(array $before, int $sourceId, string $revision, int $acceptedAt): void
    {
        $db=$this->ucm->getDb(); $logical=new LogicalExtensionService($db); $add=[];
        $source=$db->prepare('SELECT last_accepted_revision FROM ucmp_directory_sources WHERE id=?');$source->execute([$sourceId]);
        if ($revision==='' || !hash_equals((string)$source->fetchColumn(),$revision)) { throw new \DomainException('Release requires the complete accepted source revision'); }
        $origin=(array)$this->ucm->getSite($this->ucm->localSiteId());
        foreach ($before as $prior) {
            if (($prior['binding_mode'] ?? '')!=='ad_primary' || ($prior['identity_adoption'] ?? '')!=='managed') { continue; }
            $row=$logical->findById((int)$prior['id']);
            if (!$row || !empty($row['ad_source_id']) || ($row['binding_mode'] ?? '')!=='ad_primary' || (int)$row['revision']<=(int)$prior['revision']) { continue; }
            $site=(array)$this->ucm->getSite((int)$row['site_id']);
            $packet=['origin_site_key'=>(string)($origin['site_key'] ?? ''),'identity_site_key'=>(string)($site['site_key'] ?? ''),
                'site_id'=>(int)$row['site_id'],'extension'=>(string)$row['extension'],'extension_id'=>(int)$row['id'],
                'extension_revision'=>(int)$row['revision'],'base_revision'=>(int)$row['revision'],
                'source_id'=>$sourceId,'object_key'=>(string)$prior['ad_object_key'],'source_key'=>$sourceId.':'.(string)$prior['ad_object_key'],
                'source_revision'=>$revision,'accepted_at'=>$acceptedAt,'binding_mode'=>'ad_primary','adoption'=>'managed','released'=>true,
                'canonical'=>[],'final_overrides'=>[],'sync_name'=>false,'sync_label'=>true,'display_name'=>'','label'=>''];
            $packet['hash']=IdentityDeliveryService::normalize($packet)['delivery_hash'];
            $add[$packet['identity_site_key'].':'.$packet['extension']]=$packet;
        }
        if (!$add) { return; }
        $localId=(int)$this->ucm->localSiteId();$key='identity_delivery_releases';$sqlite=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite';
        $started=!$db->inTransaction();if ($started) { $db->beginTransaction(); }
        try {
            $db->prepare($sqlite ? 'INSERT OR IGNORE INTO ucmp_settings (site_id,skey,sval) VALUES (?,?,?)'
                : 'INSERT IGNORE INTO ucmp_settings (site_id,skey,sval) VALUES (?,?,?)')->execute([$localId,$key,'{}']);
            $locked=$db->prepare('SELECT sval FROM ucmp_settings WHERE site_id=? AND skey=?'.($sqlite?'':' FOR UPDATE'));
            $locked->execute([$localId,$key]);$raw=(string)$locked->fetchColumn();
            if (strlen($raw)>8388608) { throw new \LengthException('Identity release receipts exceed their bound'); }
            $current=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($current)) { throw new \RuntimeException('Identity release receipt is invalid'); }
            foreach ($add as $identityKey=>$packet) { $current[$identityKey]=$packet; }
            ksort($current);$encoded=json_encode($current,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (count($current)>10000 || strlen($encoded)>8388608) { throw new \LengthException('Identity release receipt capacity exceeded; no entries were discarded'); }
            $db->prepare('UPDATE ucmp_settings SET sval=? WHERE site_id=? AND skey=?')->execute([$encoded,$localId,$key]);
            if ($started && !$db->commit()) { throw new \RuntimeException('Identity release receipt commit is unverified'); }
        } catch (\Throwable $e) { if ($started && $db->inTransaction()) { $db->rollBack(); }throw $e; }
    }

    /** CAS the exact reviewed accepted relationships before fixed-target execution. */
    public function commitIdentityPackets(array $packets): void
    {
        if (!$packets || count($packets)>5000) { throw new \InvalidArgumentException('Choose a bounded nonempty identity plan'); }
        $ids=array_values(array_unique(array_map(static fn(array $p): int => (int)($p['extension_id'] ?? 0),$packets)));
        if (min($ids)<1 || count($ids)!==count($packets)) { throw new \InvalidArgumentException('Identity plan contains duplicate or missing logical targets'); }
        $db=$this->ucm->getDb();$started=!$db->inTransaction();if ($started) { $db->beginTransaction(); }
        try {
            sort($ids,SORT_NUMERIC);
            $locked=$db->prepare('SELECT id FROM ucmp_extensions WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'
                .($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));
            $locked->execute($ids);$locked->fetchAll(\PDO::FETCH_COLUMN);
            $fresh=$this->planIdentityPackets(['extension_ids'=>$ids]);$current=[];
            foreach ($fresh['targets'] as $packet) { $current[(int)$packet['extension_id']]=$packet; }
            foreach ($packets as $packet) {
                $candidate=$current[(int)$packet['extension_id']] ?? null;
                if (!$candidate || IdentityDeliveryService::hash($candidate)!==IdentityDeliveryService::hash($packet)) {
                    throw new \DomainException('Accepted identity or ownership changed after review; preview again');
                }
            }
            $update=$db->prepare("UPDATE ucmp_extensions SET desired_identity_hash=?,revision=?,apply_status='pending',apply_error=NULL,updated=? WHERE id=? AND revision=?");
            foreach ($packets as $packet) {
                $update->execute([$packet['hash'],$packet['extension_revision'],time(),$packet['extension_id'],$packet['base_revision']]);
                if ($update->rowCount()!==1) { throw new \DomainException('Reviewed identity revision is no longer current'); }
            }
            if ($started && !$db->commit()) { throw new \RuntimeException('Identity desired-state commit is unverified'); }
        } catch (\Throwable $e) { if ($started && $db->inTransaction()) { $db->rollBack(); }throw $e; }
    }

    /** A fixed operator run reports only the exact approved logical revisions. */
    public function recordIdentityPacketResults(array $packets, array $results): array
    {
        $logical=new LogicalExtensionService($this->ucm->getDb());$applied=0;$queued=0;$stale=[];$skipped=[];
        $counts=array_count_values(array_map(static fn(array $packet): string => (string)$packet['extension'],$packets));
        foreach ($packets as $packet) {
            $result=(array)($results[$packet['identity_site_key'].':'.$packet['extension']] ?? (($counts[$packet['extension']] ?? 0)===1 ? ($results[$packet['extension']] ?? []) : []));
            if (!empty($result['skipped'])) {
                $reason=(string)($result['message'] ?? 'Identity target excluded by destination review');
                if ($this->recordIdentitySkip($packet,$reason)) { $skipped[]=['site_id'=>$packet['site_id'],'extension'=>$packet['extension'],'reason'=>$reason]; }
                else { $stale[]=(int)$packet['extension_id']; }
                continue;
            }
            if (!empty($result['ok']) && (empty($packet['sync_name']) || !empty($result['callerid_checked']))) {
                if ($logical->markApplied((int)$packet['extension_id'],(int)$packet['extension_revision'],$packet['hash'])) {
                    $applied++;
                    $this->ucm->getDb()->prepare("UPDATE ucmp_site_jobs SET state='completed',updated=?,last_error=NULL WHERE site_id=? AND job_type='directory_identity' AND object_key=? AND desired_hash=?")
                        ->execute([time(),$packet['site_id'],self::identityJobKey($packet),$packet['hash']]);
                } else { $stale[]=(int)$packet['extension_id']; }
                continue;
            }
            $error=(string)($result['message'] ?? 'Fixed identity delivery is unverified or missing an owner/consumer receipt');
            if ($logical->markApplyFailed((int)$packet['extension_id'],(int)$packet['extension_revision'],$packet['hash'],$error)) {
                $this->queueIdentityJob((int)$packet['site_id'],$packet,$error);$queued++;
            } else { $stale[]=(int)$packet['extension_id']; }
        }
        return ['ok'=>$queued===0 && !$stale,'applied'=>$applied,'queued'=>$queued,'skipped'=>$skipped,'stale_extension_ids'=>$stale];
    }

    private function recordIdentitySkip(array $packet,string $reason): bool
    {
        $db=$this->ucm->getDb();$logical=new LogicalExtensionService($db);
        $ownsTransaction=!$db->inTransaction();
        if ($ownsTransaction) { $db->beginTransaction(); }
        try {
            $recorded=$logical->markApplySkipped((int)$packet['extension_id'],(int)$packet['extension_revision'],$packet['hash'],$reason);
            if ($recorded) {
                // Hold the relationship lock while retiring older retries, never a newer revision's job.
                $db->prepare("UPDATE ucmp_site_jobs SET state='skipped',updated=?,last_error=?
                    WHERE site_id=? AND job_type='directory_identity' AND object_key=? AND state IN ('pending','failed')")
                    ->execute([time(),mb_substr($reason,0,4000,'UTF-8'),$packet['site_id'],self::identityJobKey($packet)]);
            }
            if ($ownsTransaction) { $db->commit(); }
            return $recorded;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) { $db->rollBack(); }
            throw $error;
        }
    }

    private function queueIdentityJob(int $siteId, array $item, string $error): void
    {
        $error = mb_substr(trim($error), 0, 4000, 'UTF-8');
        $objectKey = self::identityJobKey($item);
        $now = time();
        $db=$this->ucm->getDb();
        $upsert=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? " ON CONFLICT(site_id,job_type,object_key,desired_hash) DO UPDATE SET state='pending',attempts=attempts+1,next_attempt=excluded.next_attempt,updated=excluded.updated,last_error=excluded.last_error"
            : " ON DUPLICATE KEY UPDATE state='pending',attempts=attempts+1,next_attempt=VALUES(next_attempt),updated=VALUES(updated),last_error=VALUES(last_error)";
        $db->prepare(
            "INSERT INTO ucmp_site_jobs
                (site_id,job_type,object_key,desired_hash,payload_ref,state,attempts,next_attempt,created,updated,last_error)
             VALUES (?,'directory_identity',?,?,?,'pending',1,?,?,?,?)".$upsert
        )->execute([
            $siteId,
            $objectKey,
            (string)$item['hash'],
            'directory:' . $objectKey,
            $now + 60,
            $now,
            $now,
            $error,
        ]);
    }

    private static function identityJobKey(array $item): string
    {
        return !empty($item['extension_id']) ? 'extension:' . (int)$item['extension_id']
            : (int)($item['source_id'] ?? 0) . ':' . (string)($item['object_key'] ?? $item['source_key'] ?? '');
    }

    /** Internal immutable payload; never return this employee-bearing snapshot to a browser. */
    private function acceptedPublicationSnapshot(array $entries, ?array $sites = null): array
    {
        $entries = $this->resolveDirectoryIdentities($entries);
        $wireContacts = [];
        foreach ($entries as $entry) {
            if (empty($entry['display_name']) || empty($entry['extension'])) { continue; }
            $contact = [];
            foreach (['source_key','display_name','given_name','initial','surname','label','canonical_label','directory_label_override','directory_label_override_set','extension','department','company','org_unit','domain','ou_path','site_name','mail','mobile','pager','attributes','groups','conflicted'] as $key) {
                $value = $entry[$key] ?? null;
                if ($key === 'attributes') { $value = $this->normalizeAttributeMap($value); if ($value) { $contact[$key] = $value; } }
                elseif (is_array($value)) { if ($value) { $contact[$key] = array_values(array_map('strval',$value)); } }
                elseif (trim((string)$value) !== '') { $contact[$key] = trim((string)$value); }
            }
            $wireContacts[] = $contact;
        }
        $allBooks = $this->phonebooks();
        $compiledBooks = [];
        foreach ($allBooks as $book) { $compiledBooks[(string)$book['book_key']] = $this->compilePhonebook($book,$entries); }
        $wireContactIds = array_fill_keys(array_map(static fn($contact)=>(string)($contact['source_key']??''),$wireContacts),true);
        $sites ??= array_values(array_filter($this->ucm->getSites(),static fn(array $site):bool=>!empty($site['enabled'])));
        $sourceSite = method_exists($this->ucm, 'getSite')
            ? (array)($this->ucm->getSite($this->ucm->localSiteId()) ?? [])
            : [];
        $source = ['site_key'=>(string)($sourceSite['site_key'] ?? ''),'site_name'=>(string)($sourceSite['name'] ?? '')];
        return ['wire_contacts'=>$wireContacts,'books'=>$allBooks,'compiled_books'=>$compiledBooks,
            'wire_contact_ids'=>$wireContactIds,'sites'=>$sites,'source'=>$source,
            'layout'=>$this->phonebookLayout(),'freshness'=>$this->acceptedSourceFreshness()];
    }

    private static function acceptedPublicationPlan(array $snapshot): array
    {
        $destinations=array_map(static fn(array $site):array=>['site_id'=>(int)$site['id'],'site_key'=>(string)$site['site_key']],$snapshot['sites']);
        usort($destinations,static fn(array $a,array $b):int=>$a['site_id']<=>$b['site_id']);
        $bound=$snapshot;unset($bound['sites']);$bound['destinations']=$destinations;
        return ['plan_hash'=>hash('sha256',json_encode($bound,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'destinations'=>$destinations];
    }

    private function publishToSites(array $entries, ?array $snapshot = null): array
    {
        $snapshot ??= $this->acceptedPublicationSnapshot($entries);
        $wireContacts=$snapshot['wire_contacts'];$allBooks=$snapshot['books'];$compiledBooks=$snapshot['compiled_books'];
        $wireContactIds=$snapshot['wire_contact_ids'];$sites=$snapshot['sites'];$source=$snapshot['source'];
        $ok = 0; $queued = 0; $warnings = []; $sourceCatalog = []; $outcomes = [];
        foreach ($sites as $site) {
            if (empty($site['enabled'])) { continue; }
            $siteId = (int)$site['id'];
            $outcomes[$siteId] = ['site_id'=>$siteId,'site_name'=>(string)$site['name'],'published'=>false,'queued'=>false];
            $desiredHash = hash(
                'sha256',
                json_encode(
                    ['site_key'=>$site['site_key'] ?? '', 'contacts'=>$wireContacts, 'books'=>$allBooks],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
            try {
                $siteKey = (string)($site['site_key'] ?? '');
                $token = $this->ucm->directoryTokenForSite($site);
                $books = array_values(array_filter($allBooks, static function (array $book) use ($siteKey): bool {
                    $targets = (array)($book['publish_sites'] ?? ['*']);
                    return in_array('*',$targets,true) || in_array($siteKey,$targets,true);
                }));
                $compiled = array_values(array_map(static function (array $book) use ($wireContactIds): array {
                    unset($book['entries']);
                    $book['contacts'] = array_diff_key((array)($book['contacts']??[]),$wireContactIds);
                    return $book;
                },array_intersect_key($compiledBooks,array_fill_keys(array_column($books,'book_key'),true))));
                $wireEntries = array_merge([[
                    '__ucmp_version'=>4,'__ucmp_source'=>$source,'__ucmp_layout'=>$snapshot['layout'],'__ucmp_phonebooks'=>$books,'__ucmp_compiled_phonebooks'=>$compiled,
                    '__ucmp_source_freshness'=>$snapshot['freshness'],
                ]], $wireContacts);
                $desiredHash = hash(
                    'sha256',
                    json_encode($wireEntries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
                $result = $this->ucm->importDirectorySnapshot((int)$site['id'], $wireEntries, $token);
                if (!empty($result['ok'])) {
                    $ok++;
                    $outcomes[$siteId]['published'] = true;
                    $outcomes[$siteId]['message'] = (string)($result['message'] ?? 'Phonebook cache published');
                    foreach ((array)($result['warnings'] ?? (!empty($result['warning']) ? [$result['warning']] : [])) as $warning) {
                        if (trim((string)$warning) !== '') {
                            $warnings[] = $site['name'] . ': ' . trim((string)$warning);
                        }
                    }
                    $sourceCatalog[$siteKey] = $this->ucm->directoryBaseUrl((int)$site['id']) . '?token=' . rawurlencode($token);
                    $this->ucm->getDb()->prepare(
                        "UPDATE ucmp_site_jobs
                            SET state='completed',updated=?,last_error=NULL
                          WHERE site_id=? AND job_type='directory_publish'
                            AND object_key='accepted-directory' AND desired_hash=?"
                    )->execute([time(),(int)$site['id'],$desiredHash]);
                } else {
                    throw new \RuntimeException((string)($result['message'] ?? 'cache publish failed'));
                }
            } catch (\Throwable $e) {
                $warnings[] = $site['name'] . ': ' . $e->getMessage();
                if ($outcomes[$siteId]['published']) {
                    $outcomes[$siteId]['reporting_error'] = $e->getMessage();
                    continue;
                }
                $outcomes[$siteId]['message'] = $e->getMessage();
                try {
                    $this->queueDirectoryPublishJob($siteId, $desiredHash, $e->getMessage());
                    $outcomes[$siteId]['queued'] = true;
                    $queued++;
                } catch (\Throwable $queueError) {
                    $outcomes[$siteId]['reporting_error'] = 'Retry could not be recorded: ' . $queueError->getMessage();
                    $warnings[] = $site['name'] . ': ' . $outcomes[$siteId]['reporting_error'];
                }
            }
        }
        // Templates may deliberately fetch a source PBX instead of their local cache.
        // Refresh their stored URL after every successful publication so per-source
        // bearer tokens remain correct without asking the operator to reopen templates.
        if ($sourceCatalog) {
            foreach ($sites as $site) {
                if (empty($site['enabled'])) { continue; }
                try {
                    $result = $this->ucm->refreshDirectorySourceTokens((int)$site['id'], $sourceCatalog);
                    if (empty($result['ok'])) { $warnings[] = $site['name'] . ': ' . ($result['message'] ?? 'source-token refresh failed'); }
                } catch (\Throwable $e) { $warnings[] = $site['name'] . ': ' . $e->getMessage(); }
            }
        }
        return ['ok_count'=>$ok,'queued'=>$queued,'warnings'=>$warnings,'sites'=>$outcomes];
    }

    private function queueDirectoryPublishJob(int $siteId, string $desiredHash, string $error): void
    {
        $now = time();
        $error = mb_substr(trim($error), 0, 4000, 'UTF-8');
        $db=$this->ucm->getDb();
        $upsert=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? " ON CONFLICT(site_id,job_type,object_key,desired_hash) DO UPDATE SET state='pending',attempts=attempts+1,next_attempt=excluded.next_attempt,updated=excluded.updated,last_error=excluded.last_error"
            : " ON DUPLICATE KEY UPDATE state='pending',attempts=attempts+1,next_attempt=VALUES(next_attempt),updated=VALUES(updated),last_error=VALUES(last_error)";
        $db->prepare(
            "INSERT INTO ucmp_site_jobs
                (site_id,job_type,object_key,desired_hash,payload_ref,state,attempts,next_attempt,created,updated,last_error)
             VALUES (?,'directory_publish','accepted-directory',?,'directory:accepted-current','pending',1,?,?,?,?)".$upsert
        )->execute([$siteId,$desiredHash,$now+60,$now,$now,$error]);
    }

    /** Safe review identity only: no contacts, source attributes or bearer tokens. */
    public function planAcceptedPhonebooks(): array
    {
        return self::acceptedPublicationPlan($this->acceptedPublicationSnapshot($this->entries(true,0)));
    }

    public function publishAcceptedPhonebooks(?array $reviewedPlan = null): array
    {
        $sites=null;
        if ($reviewedPlan!==null) {
            if (!preg_match('/^[a-f0-9]{64}$/D',(string)($reviewedPlan['plan_hash']??'')) || !isset($reviewedPlan['destinations']) || !is_array($reviewedPlan['destinations'])) {
                throw new \InvalidArgumentException('A valid fixed phonebook publication review is required');
            }
            $current=[];foreach ($this->ucm->getSites() as $site) {$current[(int)$site['id']]=$site;}
            $sites=[];$seen=[];
            foreach ($reviewedPlan['destinations'] as $destination) {
                $id=(int)($destination['site_id']??0);$site=$current[$id]??null;
                if ($id<1 || isset($seen[$id]) || !$site || empty($site['enabled']) || (string)$site['site_key']!==(string)($destination['site_key']??'')) {
                    throw new \DomainException('A reviewed phonebook destination was removed, disabled, rekeyed or duplicated');
                }
                $seen[$id]=true;$sites[]=$site;
            }
        }
        $snapshot=$this->acceptedPublicationSnapshot($this->entries(true,0),$sites);
        if ($reviewedPlan!==null && !hash_equals($reviewedPlan['plan_hash'],self::acceptedPublicationPlan($snapshot)['plan_hash'])) {
            throw new \DomainException('Accepted contacts, phonebooks, layout or freshness changed after review; preview again');
        }
        return $this->publishToSites([],$snapshot);
    }

    public function republishSavedPhonebooks(): array
    {
        if ($this->ucm->getDb()->inTransaction()) { throw new \RuntimeException('Phonebook publication requires a committed definition'); }
        $this->republishPhonebooks();
        return $this->lastPublicationResult;
    }

    private function republishPhonebooks(): void
    {
        try {
            $this->lastPublicationResult = $this->publishToSites($this->entries(true, 0));
        } catch (\Throwable $e) {
            $this->lastPublicationResult = ['ok_count'=>0,'queued'=>0,'sites'=>[],
                'warnings'=>['Phonebook change saved, but publication could not start: ' . $e->getMessage()]];
        }
        if (!empty($this->lastPublicationResult['warnings'])) {
            try {
                $this->ucm->logOperation($this->ucm->localSiteId(), 'phonebook-publish', 'warning',
                    'Phonebook change saved; publication needs attention', implode("\n", $this->lastPublicationResult['warnings']));
            } catch (\Throwable $e) {
                $this->lastPublicationResult['warnings'][] = 'Publication warning could not be logged: ' . $e->getMessage();
            }
        }
    }

    public function phonebookLayout(): array
    {
        $books = $this->phonebooks();
        if ($books) {
            $book = $books[0];
            return $this->normalizePhonebookLayout([
                'directory_title'=>$book['title'],'directory_group_by'=>$book['group_by'],'directory_name_format'=>$book['name_format'],
                'directory_sort_by'=>$book['sort_by'],'directory_include_mobile'=>$book['include_mobile']?'1':'0','directory_cisco_page_size'=>(string)$book['cisco_page_size'],
                'directory_hide_uncategorised'=>!empty($book['hide_uncategorised'])?'1':'0',
                'directory_search_enabled'=>!empty($book['search_enabled'])?'1':'0',
                'directory_search_position'=>(string)($book['search_position'] ?? 'first'),
                'directory_search_scope'=>(string)($book['search_scope'] ?? 'root'),
                'directory_search_extension'=>!empty($book['search_extension'])?'1':'0',
                'directory_search_mobile'=>!empty($book['search_mobile'])?'1':'0',
                'directory_search_pager'=>!empty($book['search_pager'])?'1':'0',
                'directory_numeric_min_length'=>(string)($book['numeric_min_length'] ?? 1),
                'directory_mobile_result_mode'=>(string)($book['mobile_result_mode'] ?? 'find_extension'),
                'directory_mobile_editdial'=>!empty($book['mobile_editdial'])?'1':'0',
                'directory_cisco_softkeys'=>$book['cisco_softkeys'] ?? [],
            ]) + ['hierarchy'=>$book['hierarchy']];
        }
        return $this->normalizePhonebookLayout($this->settings(false));
    }

    private function normalizePhonebookLayout(array $values): array
    {
        $pick = static function (array $allowed, string $value, string $fallback): string {
            return in_array($value, $allowed, true) ? $value : $fallback;
        };
        $title = trim((string)($values['directory_title'] ?? self::DEFAULTS['directory_title']));
        return [
            'title' => mb_substr($title !== '' ? $title : self::DEFAULTS['directory_title'], 0, 64, 'UTF-8'),
            'group_by' => $pick(['department','site_name','company','org_unit','none'], (string)($values['directory_group_by'] ?? ''), 'department'),
            'name_format' => $pick(['display','display_label','label_display','label','display_extension'], (string)($values['directory_name_format'] ?? ''), 'display_label'),
            'sort_by' => $pick(['display_name','label','extension'], (string)($values['directory_sort_by'] ?? ''), 'display_name'),
            'include_mobile' => (string)($values['directory_include_mobile'] ?? '1') === '1',
            'cisco_page_size' => (int)$pick(['16','24','32'], (string)($values['directory_cisco_page_size'] ?? ''), '32'),
            'hide_uncategorised' => (string)($values['directory_hide_uncategorised'] ?? '0') === '1',
            'search_enabled' => (string)($values['directory_search_enabled'] ?? '1') === '1',
            'search_position' => $pick(['first','after_uncategorised','last'], (string)($values['directory_search_position'] ?? ''), 'first'),
            'search_scope' => $pick(['root','all'], (string)($values['directory_search_scope'] ?? ''), 'root'),
            'search_extension' => (string)($values['directory_search_extension'] ?? '1') === '1',
            'search_mobile' => (string)($values['directory_search_mobile'] ?? '1') === '1',
            'search_pager' => (string)($values['directory_search_pager'] ?? '1') === '1',
            'numeric_min_length' => max(1,min(10,(int)($values['directory_numeric_min_length'] ?? 1))),
            'mobile_result_mode' => $pick(['find_extension','extension_and_mobile'], (string)($values['directory_mobile_result_mode'] ?? ''), 'find_extension'),
            'mobile_editdial' => (string)($values['directory_mobile_editdial'] ?? '0') === '1',
            'cisco_softkeys' => $this->normalizeCiscoSoftkeys($values['directory_cisco_softkeys'] ?? []),
        ];
    }

    private function normalizeExtraAttributes($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value,true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/',trim($value),-1,PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach (array_slice(array_values($value),0,50) as $attribute) {
            $attribute = trim((string)$attribute);
            if ($this->validAttribute($attribute)) { $out[strtolower($attribute)] = $attribute; }
        }
        return array_values($out);
    }

    private function normalizeAttributeMap($value): array
    {
        if (is_string($value)) { $value = json_decode($value,true); }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach (array_slice($value,0,60,true) as $attribute=>$values) {
            $attribute = strtolower(trim((string)$attribute));
            if ($attribute !== 'groups' && !$this->validAttribute($attribute)) { continue; }
            $clean = $this->normalizeStringList($values,255,100);
            if ($clean) { $out[$attribute] = $clean; }
        }
        if (!isset($out['groups']) && isset($out['memberof'])) { $out['groups'] = $this->groupNames($out['memberof']); }
        return $out;
    }

    private function normalizeStringList($value, int $length, int $limit): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value,true);
            $value = is_array($decoded) ? $decoded : preg_split('/\s*[|\r\n]+\s*/u',$value,-1,PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) { $value = [$value]; }
        $out = [];
        foreach (array_slice(array_values($value),0,$limit) as $item) {
            $item = trim((string)$item);
            $item = preg_replace('/[\x00-\x1F\x7F]/u','',$item);
            if ($item !== '') { $out[mb_strtolower($item,'UTF-8')] = mb_substr($item,0,$length,'UTF-8'); }
        }
        return array_values($out);
    }

    private function normalizeMenuPath($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value,true);
            $value = is_array($decoded) ? $decoded : preg_split('~\s*/\s*|\s*>\s*|\r?\n~u',$value,-1,PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach (array_slice(array_values($value),0,8) as $part) {
            $part = $this->cleanLabel($part,'');
            if ($part !== '') { $out[] = $part; }
        }
        return $out;
    }

    private function cleanLabel($value, string $fallback): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u','',$value);
        return mb_substr($value !== '' ? $value : $fallback,0,64,'UTF-8');
    }

    private function groupNames(array $dns): array
    {
        $out = [];
        foreach ($dns as $dn) {
            if (!preg_match('/(?:^|,)CN=((?:\\\\.|[^,])+)/i',(string)$dn,$match)) { continue; }
            $name = preg_replace_callback('/\\\\([0-9A-Fa-f]{2})/',static fn($m)=>chr(hexdec($m[1])),$match[1]);
            $name = str_replace(['\\,','\\+','\\"','\\\\'],[',','+','"','\\'],$name);
            $name = $this->cleanLabel($name,'');
            if ($name !== '') { $out[mb_strtolower($name,'UTF-8')] = $name; }
        }
        return array_values($out);
    }

    /**
     * Compatibility-only mapping used when no explicit AD rules exist yet.
     *
     * These rows are the migrated, persistent deployment bindings. They do not
     * poll a PBX and therefore cannot change merely because a site is offline.
     */
    private function logicalExtensionSiteMap(): array
    {
        $map = [];
        $rows = $this->ucm->getDb()->query(
            "SELECT site_id,extension FROM ucmp_extensions
              WHERE site_id>0 AND extension IS NOT NULL AND TRIM(extension)<>''"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $extension = trim((string)$row['extension']);
            if ($extension !== '') {
                $map[$extension][] = (int)$row['site_id'];
            }
        }
        return $map;
    }

    /** Stable comparison of the AD-owned values in one complete source revision. */
    private function identityDigest(array $entry): string
    {
        $attributes = $this->normalizeAttributeMap($entry['attributes'] ?? []);
        ksort($attributes, SORT_STRING);
        foreach ($attributes as &$values) {
            if (is_array($values)) {
                $values = array_values(array_map('strval', $values));
                sort($values, SORT_STRING);
            }
        }
        unset($values);
        $identity = [];
        foreach ([
            'display_name','label','extension','department','company','org_unit',
            'mail','mobile','pager',
        ] as $field) {
            $identity[$field] = trim((string)($entry[$field] ?? ''));
        }
        $identity['dn'] = trim((string)($entry['dn'] ?? $entry['source_dn'] ?? ''));
        $identity['attributes'] = $attributes;
        return hash(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function mergedSettings(array $input): array
    {
        $settings = $this->settings(true);
        $values = is_array($input['directory'] ?? null) ? $input['directory'] : $input;
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (!isset($values[$key])) { continue; }
            if ($key === 'ad_extra_attributes') { $settings[$key] = json_encode($this->normalizeExtraAttributes($values[$key]),JSON_UNESCAPED_SLASHES); continue; }
            if ($key === 'ad_site_rules') {
                $siteKeys = array_values(array_filter(array_map(
                    static fn(array $site): string => (string)($site['site_key'] ?? ''),
                    $this->ucm->getSites()
                )));
                $rules = DirectorySiteMapper::normalizeRules($values[$key], $siteKeys);
                foreach ($rules as &$rule) { unset($rule['_order']); }
                unset($rule);
                $settings[$key] = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                continue;
            }
            if ((string)$values[$key] !== '') { $settings[$key] = trim((string)$values[$key]); }
        }
        if (!empty($values['ad_bind_password'])) { $settings['ad_bind_password'] = (string)$values['ad_bind_password']; }
        return $settings;
    }

    /** Convert abandoned runner rows into explicit failures before scheduling. */
    private function recoverStaleRuns(): void
    {
        $db = $this->ucm->getDb();
        $cutoff = time() - 3600;
        $message = 'Directory runner stopped before completing; the previous accepted revision remains authoritative';
        try {
            $stale = $db->prepare(
                "SELECT DISTINCT source_id FROM ucmp_directory_sync_runs
                  WHERE status='running' AND started<?"
            );
            $stale->execute([$cutoff]);
            $sourceIds = array_map('intval', $stale->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            $db->prepare(
                "UPDATE ucmp_directory_sync_runs
                    SET finished=?,status='failed',error=?
                  WHERE status='running' AND started<?"
            )->execute([time(), $message, $cutoff]);
            if ($sourceIds) {
                $update = $db->prepare(
                    'UPDATE ucmp_directory_sources
                        SET last_attempt=?,last_error=?,updated=?
                      WHERE id=?'
                );
                $now = time();
                foreach ($sourceIds as $sourceId) {
                    $update->execute([$now, $message, $now, $sourceId]);
                }
            }
        } catch (\PDOException $e) {
            // Upgrade/diagnostic callers may inspect settings before run history exists.
        }
    }

    /** Per-source cadence and bounded failure backoff derived from durable run history. */
    private function sourceScheduleState(array $source): array
    {
        $minutes = max(0, (int)($source['schedule_minutes'] ?? 0));
        $schedule = RecurringSchedule::fromFields([
            'schedule_json'=>(string)($source['schedule_json'] ?? ''),
            'schedule_minutes'=>$minutes,
        ], $minutes, 5);
        $lastAttempt = max(0, (int)($source['last_attempt'] ?? 0));
        $lastSuccess = max(0, (int)($source['last_success'] ?? 0));
        $failureStreak = 0;
        $activeStarted = 0;
        try {
            $runs = $this->ucm->getDb()->prepare(
                'SELECT started,status
                   FROM ucmp_directory_sync_runs
                  WHERE source_id=?
                  ORDER BY started DESC,id DESC
                  LIMIT 32'
            );
            $runs->execute([(int)($source['id'] ?? 0)]);
            foreach ($runs->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $run) {
                $status = (string)($run['status'] ?? '');
                if ($status === 'running') {
                    $activeStarted = max($activeStarted, (int)($run['started'] ?? 0));
                    continue;
                }
                if ($status === 'failed') {
                    $failureStreak++;
                    continue;
                }
                if ($status === 'accepted' || $status === 'accepted_partial') { break; }
            }
        } catch (\PDOException $e) {
            // The source timestamps still provide a safe compatibility fallback.
        }
        if ($failureStreak === 0 && $lastAttempt > $lastSuccess && !empty($source['last_error'])) {
            $failureStreak = 1;
        }
        if ($activeStarted > time() - 3600) {
            return [
                'due'=>false,
                'failure_streak'=>$failureStreak,
                'backoff_seconds'=>0,
                'next_run'=>$activeStarted + 3600,
            ];
        }
        if ($schedule['mode'] === 'manual') {
            return ['due'=>false,'failure_streak'=>$failureStreak,'backoff_seconds'=>0,'next_run'=>0];
        }
        if ($failureStreak > 0) {
            $backoff = min(21600, 300 * (2 ** min(6, $failureStreak - 1)));
            $nextRun = $lastAttempt > 0 ? $lastAttempt + $backoff : 0;
            return [
                'due'=>$nextRun === 0 || $nextRun <= time(),
                'failure_streak'=>$failureStreak,
                'backoff_seconds'=>$backoff,
                'next_run'=>$nextRun,
            ];
        }
        $nextRun = $lastSuccess > 0 ? RecurringSchedule::nextRun($schedule, $lastSuccess) : 0;
        return [
            'due'=>$nextRun === 0 || $nextRun <= time(),
            'failure_streak'=>0,
            'backoff_seconds'=>0,
            'next_run'=>$nextRun,
        ];
    }

    /** Validate a reference without returning its path, bind identity or password. */
    private function sourceSecretStatus(array $source): array
    {
        $sourceKey = trim((string)($source['source_key'] ?? ''));
        if ($this->isCompatibilitySource($source)) {
            $settings = $this->settings(false);
            $configured = !empty($settings['ad_password_saved'])
                && trim((string)($settings['ad_bind_user'] ?? '')) !== '';
            return [
                'configured'=>$configured,
                'error'=>$configured ? '' : 'Compatibility bind user or password is missing',
                'updated'=>0,
            ];
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $sourceKey)) {
            return ['configured'=>false,'error'=>'Directory source key is invalid','updated'=>0];
        }
        if (!hash_equals('file:' . $sourceKey, trim((string)($source['secret_ref'] ?? '')))) {
            return ['configured'=>false,'error'=>'Managed credential reference does not match this source','updated'=>0];
        }
        try {
            $root = $this->secretRoot(false);
            $path = $root . DIRECTORY_SEPARATOR . $sourceKey . '.json';
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                return ['configured'=>false,'error'=>'Managed credential object is missing or unreadable','updated'=>0];
            }
            if (DIRECTORY_SEPARATOR !== '\\') {
                $mode = @fileperms($path);
                if ($mode === false || (($mode & 0077) !== 0)) {
                    return ['configured'=>false,'error'=>'Managed credential object must use mode 0600','updated'=>0];
                }
            }
            $secret = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($secret)
                || (int)($secret['version'] ?? 0) !== 1
                || !isset($secret['source_key'])
                || !hash_equals($sourceKey, (string)$secret['source_key'])
                || trim((string)($secret['bind_user'] ?? '')) === ''
                || (string)($secret['password'] ?? '') === ''
            ) {
                return ['configured'=>false,'error'=>'Managed credential object is invalid','updated'=>0];
            }
            return ['configured'=>true,'error'=>'','updated'=>max(0, (int)($secret['updated'] ?? 0))];
        } catch (\Throwable $e) {
            return ['configured'=>false,'error'=>mb_substr($e->getMessage(), 0, 500, 'UTF-8'),'updated'=>0];
        }
    }

    /** The fixed privileged store may be overridden only by a test harness. */
    private function secretRoot(bool $create): string
    {
        $root = method_exists($this->ucm, 'directorySecretRoot')
            ? (string)$this->ucm->directorySecretRoot()
            : '/var/lib/asterisk/ucmprovisioner/secrets/directory';
        $root = rtrim(trim($root), '/\\');
        if ($root === '' || is_link($root)) {
            throw new \RuntimeException('Managed directory credential root is invalid');
        }
        if (!is_dir($root)) {
            if (!$create) { return $root; }
            if (!@mkdir($root, 0700, true) && !is_dir($root)) {
                throw new \RuntimeException('Could not create the managed directory credential root');
            }
        }
        if (!is_writable($root) && $create) {
            throw new \RuntimeException('Managed directory credential root is not writable');
        }
        if ($create && PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0
            && (!@chown($root, 'asterisk') || !@chgrp($root, 'asterisk'))) {
            throw new \RuntimeException('Could not assign the directory credential root to the service account');
        }
        if ($create && DIRECTORY_SEPARATOR !== '\\' && !@chmod($root, 0700)) {
            throw new \RuntimeException('Could not restrict the managed directory credential root to mode 0700');
        }
        $resolved = realpath($root);
        if ($resolved === false || is_link($resolved)) {
            throw new \RuntimeException('Managed directory credential root could not be resolved safely');
        }
        return rtrim($resolved, '/\\');
    }

    /** Load a validated external object for internal bind use only. */
    private function readSourceSecret(array $source): array
    {
        $status = $this->sourceSecretStatus($source);
        if (empty($status['configured'])) {
            throw new \RuntimeException((string)($status['error'] ?? 'Managed directory credential is unavailable'));
        }
        $sourceKey = (string)$source['source_key'];
        $path = $this->secretRoot(false) . DIRECTORY_SEPARATOR . $sourceKey . '.json';
        $secret = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($secret)
            || (int)($secret['version'] ?? 0) !== 1
            || !isset($secret['source_key'])
            || !hash_equals($sourceKey, (string)$secret['source_key'])
            || trim((string)($secret['bind_user'] ?? '')) === ''
            || (string)($secret['password'] ?? '') === ''
        ) {
            throw new \RuntimeException('Managed credential object changed during validation');
        }
        return ['bind_user'=>(string)$secret['bind_user'],'password'=>(string)$secret['password']];
    }

    /** Translate one registered authority into the existing LDAP reader contract. */
    private function sourceSettings(array $source): array
    {
        $sourceKey = (string)($source['source_key'] ?? '');
        if ($this->isCompatibilitySource($source)) {
            $settings = $this->settings(true);
            $settings['_source_id'] = (int)$source['id'];
            $settings['_source_key'] = $sourceKey;
            $settings['_endpoints'] = [[
                'host'=>trim((string)$settings['ad_host']),
                'port'=>(int)$settings['ad_port'],
                'priority'=>10,
            ]];
            return $settings;
        }
        $endpoints = json_decode((string)($source['endpoints_json'] ?? '[]'), true);
        $attributes = json_decode((string)($source['attribute_map_json'] ?? '{}'), true);
        $rules = json_decode((string)($source['site_rules_json'] ?? '[]'), true);
        if (!is_array($endpoints) || !$endpoints || !is_array($attributes) || !is_array($rules)) {
            throw new \RuntimeException('Directory source metadata is incomplete or invalid');
        }
        $secret = $this->readSourceSecret($source);
        $settings = $this->settings(false);
        $settings['ad_enabled'] = !empty($source['enabled']) ? '1' : '0';
        $settings['ad_host'] = trim((string)($endpoints[0]['host'] ?? ''));
        $settings['ad_port'] = (string)(int)($endpoints[0]['port'] ?? 0);
        $settings['ad_security'] = (string)($source['tls_mode'] ?? 'ldaps');
        $settings['ad_base_dn'] = trim((string)($source['base_dn'] ?? ''));
        $settings['ad_bind_user'] = $secret['bind_user'];
        $settings['ad_bind_password'] = $secret['password'];
        $settings['ad_user_filter'] = (string)($source['filter'] ?? '');
        $settings['ad_attr_display'] = (string)($attributes['display_name'] ?? '');
        $settings['ad_attr_extension'] = (string)($attributes['extension'] ?? '');
        $settings['ad_attr_label'] = (string)($attributes['label'] ?? '');
        $settings['ad_attr_department'] = (string)($attributes['department'] ?? '');
        $settings['ad_attr_company'] = (string)($attributes['company'] ?? '');
        $settings['ad_attr_mail'] = (string)($attributes['mail'] ?? '');
        $settings['ad_attr_mobile'] = (string)($attributes['mobile'] ?? '');
        $settings['ad_attr_pager'] = (string)($attributes['pager'] ?? '');
        foreach (['sam_account_name'=>'sAMAccountName','user_principal_name'=>'userPrincipalName','given_name'=>'givenName','surname'=>'sn'] as $field=>$default) {
            $settings['ad_attr_'.$field] = trim((string)($attributes[$field] ?? '')) ?: $default;
        }
        $settings['ad_extra_attributes'] = json_encode(
            array_values((array)($attributes['extra_attributes'] ?? [])),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $settings['ad_site_rules'] = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $settings['ad_sync_interval'] = (string)max(0, (int)($source['schedule_minutes'] ?? 0));
        $settings['ad_sync_schedule_json'] = RecurringSchedule::encode(RecurringSchedule::fromFields([
            'schedule_json'=>(string)($source['schedule_json'] ?? ''),
            'schedule_minutes'=>(int)($source['schedule_minutes'] ?? 0),
        ], max(0, (int)($source['schedule_minutes'] ?? 0)), 5));
        $settings['_source_id'] = (int)$source['id'];
        $settings['_source_key'] = $sourceKey;
        $settings['_endpoints'] = $endpoints;
        return $settings;
    }

    private function isCompatibilitySource(array $source): bool
    {
        return hash_equals('legacy-default', trim((string)($source['source_key'] ?? '')))
            && hash_equals(
                'legacy-setting:ad_bind_password',
                trim((string)($source['secret_ref'] ?? ''))
            );
    }

    /** Try domain controllers in saved priority order without exposing credentials. */
    private function connectSource(array $settings)
    {
        $errors = [];
        foreach ((array)($settings['_endpoints'] ?? []) as $endpoint) {
            $host = trim((string)($endpoint['host'] ?? ''));
            $port = (int)($endpoint['port'] ?? 0);
            if ($host === '' || $port < 1 || $port > 65535) {
                $errors[] = 'invalid configured endpoint';
                continue;
            }
            $candidate = $settings;
            $candidate['ad_host'] = $host;
            $candidate['ad_port'] = (string)$port;
            try {
                return $this->connect($candidate);
            } catch (\Throwable $e) {
                $errors[] = $host . ':' . $port . ' - ' . mb_substr($e->getMessage(), 0, 500, 'UTF-8');
            }
        }
        throw new \RuntimeException(
            'All configured domain controllers failed: ' . ($errors ? implode('; ', $errors) : 'no usable endpoints')
        );
    }

    private function connect(array $settings, string $overrideUser = '', string $overridePassword = '', bool $requireBase = true)
    {
        if (!extension_loaded('ldap')) { throw new \RuntimeException('PHP LDAP extension is not installed'); }
        $host = trim((string)$settings['ad_host']);
        $base = trim((string)$settings['ad_base_dn']);
        if ($host === '' || ($requireBase && $base === '')) { throw new \InvalidArgumentException($requireBase ? 'LDAP host and base DN are required' : 'LDAP host is required'); }
        $port = (int)$settings['ad_port'];
        $security = (string)$settings['ad_security'];
        $uri = ($security === 'ldaps' ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = ldap_connect($uri);
        if (!$conn) { throw new \RuntimeException('Could not initialize LDAP connection'); }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) { ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 8); }
        if ($security === 'ldaps' || $security === 'starttls') {
            if (!defined('LDAP_OPT_X_TLS_REQUIRE_CERT') || !defined('LDAP_OPT_X_TLS_DEMAND')) {
                throw new \RuntimeException('PHP LDAP cannot enforce server-certificate validation');
            }
            if (!@ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND)) {
                throw new \RuntimeException('Could not require LDAP server-certificate validation');
            }
        }
        if ($security === 'starttls' && !@ldap_start_tls($conn)) { throw new \RuntimeException('LDAP StartTLS failed: ' . ldap_error($conn)); }
        $user = $overrideUser !== '' ? $overrideUser : (string)$settings['ad_bind_user'];
        $password = $overrideUser !== '' ? $overridePassword : (string)($settings['ad_bind_password'] ?? '');
        if ($user === '' || $password === '') { throw new \InvalidArgumentException('LDAP bind user and password are required'); }
        if ($base === '' && $this->isShortLogin($user)) {
            $rootSearch = @ldap_read($conn, '', '(objectClass=*)', ['defaultNamingContext']);
            $rootRows = $rootSearch ? ldap_get_entries($conn, $rootSearch) : [];
            $base = trim((string)($rootRows[0]['defaultnamingcontext'][0] ?? ''));
        }
        $user = $this->normalizedBindUser($user, $base);
        if (!@ldap_bind($conn, $user, $password)) { throw new \RuntimeException('LDAP bind failed: ' . ldap_error($conn)); }
        return $conn;
    }

    private function readUsers($conn, array $settings, int $sizeLimit = 0): array
    {
        $namingAttributes=[];
        foreach (['sam_account_name'=>'sAMAccountName','user_principal_name'=>'userPrincipalName','given_name'=>'givenName','surname'=>'sn'] as $field=>$canonical) {
            $namingAttributes[$canonical]=trim((string)($settings['ad_attr_'.$field] ?? '')) ?: $canonical;
        }
        $storedAttrs = array_values(array_unique(array_merge(
            ['l','st','physicalDeliveryOfficeName','division','memberOf','givenName','sn','initials','sAMAccountName','userPrincipalName'],
            array_values($namingAttributes),
            $this->normalizeExtraAttributes($settings['ad_extra_attributes'] ?? [])
        )));
        $attrs = array_values(array_unique(array_filter(array_merge(['objectGUID','userAccountControl'],$storedAttrs,[$settings['ad_attr_display'],$settings['ad_attr_extension'],$settings['ad_attr_label'],$settings['ad_attr_department'],$settings['ad_attr_company'],$settings['ad_attr_mail'],$settings['ad_attr_mobile'],$settings['ad_attr_pager']??'pager']))));
        foreach ($attrs as $attr) { if (!$this->validAttribute($attr)) { throw new \InvalidArgumentException("Invalid LDAP attribute $attr"); } }
        $pageSize = $sizeLimit > 0 ? min(1000,$sizeLimit) : 1000;
        $cookie = ''; $seenCookies = []; $seenObjects = []; $out = [];
        $this->lastReadWarnings = [];
        $priorRows = [];
        $sourceId = (int)($settings['_source_id'] ?? 0);
        if ($sourceId > 0 && is_object($this->ucm) && method_exists($this->ucm, 'getDb')) {
            $prior = $this->ucm->getDb()->prepare(
                    'SELECT object_key,display_name,label,extension,department,company,org_unit,mail,mobile,pager,source_dn,attributes,site_id,last_seen_at
                       FROM ucmp_directory_entries WHERE source_id=?'
            );
            $prior->execute([$sourceId]);
            foreach ($prior->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $priorRows[(string)$row['object_key']] = $row;
            }
        }
        $skippedNameless = 0;
        $retainedNameless = 0;
        do {
            $controls = [[
                'oid'=>'1.2.840.113556.1.4.319','iscritical'=>true,
                'value'=>['size'=>$pageSize,'cookie'=>$cookie],
            ]];
            $search = @ldap_search($conn,$settings['ad_base_dn'],$settings['ad_user_filter'],$attrs,0,0,20,defined('LDAP_DEREF_NEVER')?LDAP_DEREF_NEVER:0,$controls);
            if (!$search) { throw new \RuntimeException('LDAP paged search failed: ' . ldap_error($conn)); }
            $error = 0; $matched = ''; $message = ''; $referrals = []; $responseControls = [];
            if (!@ldap_parse_result($conn,$search,$error,$matched,$message,$referrals,$responseControls) || $error !== 0) {
                throw new \RuntimeException('LDAP search was incomplete (' . $error . '): ' . ($message ?: ldap_error($conn)) . '. Existing directory users were left untouched.');
            }
            $rows = ldap_get_entries($conn,$search);
            for ($i=0; $i<(int)($rows['count'] ?? 0); $i++) {
                $row = $rows[$i];
                $uac = (int)$this->value($row,'userAccountControl');
                if (($uac & 2) === 2) { continue; }
                $guid = $this->value($row,'objectGUID',true); $dn = (string)($row['dn'] ?? '');
                if ($guid === '') { throw new \RuntimeException('Directory person has no objectGUID; incomplete stable identity snapshot rejected'); }
                $sourceKey = bin2hex($guid);
                if (isset($seenObjects[$sourceKey])) { throw new \RuntimeException('Directory repeated an objectGUID; ambiguous snapshot rejected'); }
                $seenObjects[$sourceKey] = true;
                $extension = trim($this->value($row,$settings['ad_attr_extension']));
                if ($extension !== '') { $extension = LogicalExtensionService::normalizeExtension($extension); }
                $display = trim($this->value($row,$settings['ad_attr_display']));
                $label = trim($this->value($row,$settings['ad_attr_label']));
                if ($display === '') {
                    $previous = $priorRows[$sourceKey] ?? null;
                    if (!is_array($previous) || trim((string)($previous['display_name'] ?? '')) === '') {
                        $skippedNameless++;
                        continue;
                    }
                    // A stable object with a temporarily incomplete display name is
                    // still the same accepted person. Keep its last good identity and
                    // timestamp, but let the current source revision carry it so
                    // stable bindings remain resolvable without claiming it was seen.
                    $retainedNameless++;
                    $previousAttributes = $this->normalizeAttributeMap($previous['attributes'] ?? []);
                    $out[$sourceKey] = [
                        'source_key'=>$sourceKey,
                        'display_name'=>trim((string)$previous['display_name']),
                        'label'=>trim((string)($previous['label'] ?? '')),
                        'extension'=>trim((string)($previous['extension'] ?? '')),
                        'department'=>trim((string)($previous['department'] ?? '')),
                        'company'=>trim((string)($previous['company'] ?? '')),
                        'org_unit'=>trim((string)($previous['org_unit'] ?? '')),
                        'mail'=>trim((string)($previous['mail'] ?? '')),
                        'mobile'=>trim((string)($previous['mobile'] ?? '')),
                        'pager'=>trim((string)($previous['pager'] ?? '')),
                        'dn'=>(string)($previous['source_dn'] ?? ''),
                        'attributes'=>$previousAttributes,
                        '_retained_incomplete'=>true,
                        '_retained_last_seen_at'=>max(0,(int)($previous['last_seen_at'] ?? 0)),
                        '_retained_site_id'=>max(0,(int)($previous['site_id'] ?? 0)),
                    ];
                    continue;
                }
                $sam = trim($this->value($row, $namingAttributes['sAMAccountName']));
                $upn = explode('@', trim($this->value($row, $namingAttributes['userPrincipalName'])), 2)[0];
                $label = $sam !== '' ? $sam : ($upn !== '' ? $upn : $label);
                $attributeMap = [];
                foreach ($storedAttrs as $attribute) {
                    $values = $this->values($row,$attribute);
                    if ($values) { $attributeMap[strtolower($attribute)] = $values; }
                }
                foreach ($namingAttributes as $canonical=>$attribute) {
                    $attributeMap[strtolower($canonical)]=$this->values($row,$attribute);
                }
                $attributeMap['groups'] = $this->groupNames($attributeMap['memberof'] ?? []);
                if (!$attributeMap['groups']) { unset($attributeMap['groups']); }
                $out[$sourceKey] = [
                    'source_key'=>$sourceKey,'display_name'=>$display,'label'=>$label,'extension'=>$extension,
                    'department'=>trim($this->value($row,$settings['ad_attr_department'])),'company'=>trim($this->value($row,$settings['ad_attr_company'])),'org_unit'=>$this->orgUnit($dn),
                    'mail'=>trim($this->value($row,$settings['ad_attr_mail'])),'mobile'=>trim($this->value($row,$settings['ad_attr_mobile'])),'pager'=>trim($this->value($row,$settings['ad_attr_pager']??'pager')),'dn'=>$dn,
                    'attributes'=>$this->normalizeAttributeMap($attributeMap),
                ];
                if ($sizeLimit > 0 && count($out) >= $sizeLimit) { return array_slice(array_values($out),0,$sizeLimit); }
            }
            $control = $responseControls['1.2.840.113556.1.4.319']['value'] ?? null;
            if (!is_array($control)) {
                throw new \RuntimeException('LDAP server did not confirm completion of the paged search. Existing directory users were left untouched.');
            } else { $cookie = (string)($control['cookie'] ?? ''); }
            if ($cookie !== '') {
                $cookieHash = bin2hex($cookie);
                if (isset($seenCookies[$cookieHash])) { throw new \RuntimeException('LDAP server repeated a paging cookie. Existing directory users were left untouched.'); }
                $seenCookies[$cookieHash] = true;
            }
        } while ($cookie !== '');
        if ($skippedNameless > 0) {
            $this->lastReadWarnings[] = sprintf(
                '%d enabled directory account(s) without %s were skipped because no accepted identity exists yet',
                $skippedNameless,
                (string)$settings['ad_attr_display']
            );
        }
        if ($retainedNameless > 0) {
            $this->lastReadWarnings[] = sprintf(
                '%d accepted directory identity/identities were retained because %s was temporarily empty; last-seen timestamps were preserved',
                $retainedNameless,
                (string)$settings['ad_attr_display']
            );
        }
        return array_values($out);
    }

    private function value(array $row, string $attribute, bool $binary = false): string
    {
        return (string)($this->values($row,$attribute,$binary)[0] ?? '');
    }

    private function values(array $row, string $attribute, bool $binary = false): array
    {
        $values = $row[strtolower($attribute)] ?? [];
        if (!is_array($values)) { return []; }
        $out = [];
        foreach ($values as $key=>$value) {
            if ($key === 'count' || !is_int($key)) { continue; }
            $value = $binary ? (string)$value : trim((string)$value);
            if ($value !== '') { $out[] = $value; }
        }
        return array_values(array_unique($out));
    }

    private function orgUnit(string $dn): string
    {
        return (string)($this->ouPath($dn)[0] ?? '');
    }

    /** Meaningful OU names ordered from the user upward. Generic Users containers are skipped. */
    private function ouPath(string $dn): array
    {
        if (!preg_match_all('/(?:^|,)OU=((?:\\\\.|[^,])+)/i', $dn, $m)) { return []; }
        $out = [];
        foreach ($m[1] as $ou) {
            $ou = str_replace(['\\,','\\+','\\"','\\\\'], [',','+','"','\\'], $ou);
            if ($ou !== '' && strcasecmp($ou, 'Users') !== 0) { $out[] = $ou; }
        }
        return array_values(array_unique($out));
    }

    private function parentDn(string $dn): string
    {
        $escaped = false;
        $length = strlen($dn);
        for ($i = 0; $i < $length; $i++) {
            $char = $dn[$i];
            if ($char === '\\' && !$escaped) { $escaped = true; continue; }
            if ($char === ',' && !$escaped) { return ltrim(substr($dn, $i + 1)); }
            $escaped = false;
        }
        return '';
    }

    private function notify(array $conflicts, array $settings): void
    {
        $open = array_values(array_filter($conflicts, static fn($c) => $c['status'] === 'open'));
        try {
            $notifications = \FreePBX::Notifications();
            if ($open) {
                $notifications->add_warning('ucmprovisioner','ad-conflicts',count($open) . ' AD identity conflict(s) require review','No conflicting identity was applied to a PBX. Open UCM Provisioner > Directory to correct duplicate values, site rules, or logical bindings.','config.php?display=ucmprovisioner&tab=directory',true,true);
            } elseif (method_exists($notifications, 'delete')) {
                $notifications->delete('ucmprovisioner','ad-conflicts');
            }
        } catch (\Throwable $e) {
            // The FreePBX dashboard notice is advisory. Directory publication and
            // the independently configured email notification must still proceed.
        }
        $hash = hash('sha256', json_encode(array_map(static fn($c) => [$c['value'],array_column($c['candidates'],'source_key')], $open)));
        $previous = (string)$this->ucm->getSetting('ad_conflict_notice_hash', $this->ucm->localSiteId(), '');
        $email = trim((string)$settings['ad_notification_email']);
        if ($open && $email !== '' && $hash !== $previous) {
            $lines = array_map(static fn($c) => $c['value'] . ': ' . implode(', ', array_column($c['candidates'],'label')), $open);
            @mail($email, '[UCM Provisioner] AD identity conflicts', "The AD sync found identity conflicts. No conflicting identity was applied.\n\n" . implode("\n", $lines));
        }
        $this->ucm->setSetting('ad_conflict_notice_hash', $hash, $this->ucm->localSiteId());
    }

    private function currentActor(): string
    {
        $actor = trim((string)($_SERVER['REMOTE_USER'] ?? ''));
        $ampUser = $_SESSION['AMP_user'] ?? null;
        if ($actor === '' && is_object($ampUser)) {
            foreach (['getUsername','getUserName'] as $method) {
                if (method_exists($ampUser, $method)) {
                    $actor = trim((string)$ampUser->{$method}());
                    if ($actor !== '') { break; }
                }
            }
            if ($actor === '' && isset($ampUser->username)) {
                $actor = trim((string)$ampUser->username);
            }
        } elseif ($actor === '' && is_array($ampUser)) {
            $actor = trim((string)($ampUser['username'] ?? $ampUser['user'] ?? ''));
        }
        return mb_substr($actor !== '' ? $actor : 'freepbx-admin', 0, 128, 'UTF-8');
    }

    private function validAttribute(string $value): bool { return (bool)preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $value); }
    private function isShortLogin(string $user): bool
    {
        return strpos($user, '@') === false && strpos($user, '\\') === false && strpos($user, '=') === false;
    }
    private function normalizedBindUser(string $user, string $baseDn): string
    {
        $user = trim($user);
        $domain = $this->dnsDomain($baseDn);
        return $this->isShortLogin($user) && $domain !== '' ? $user . '@' . $domain : $user;
    }
    private function dnsDomain(string $baseDn): string
    {
        $labels = [];
        foreach (array_map('trim', explode(',', $baseDn)) as $part) {
            if (stripos($part, 'DC=') === 0 && strlen($part) > 3) { $labels[] = substr($part, 3); }
        }
        return implode('.', $labels);
    }
    private function serviceAccountPowerShell(string $sam, string $container, string $dnsName, string $password): string
    {
        $q = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
        $name = 'UCM Provisioner LDAP Reader';
        return implode("\r\n", [
            'Import-Module ActiveDirectory',
            '$existing = Get-ADUser -LDAPFilter ' . $q('(sAMAccountName=' . $sam . ')') . ' -ErrorAction SilentlyContinue',
            'if ($existing) { throw ' . $q('AD user ' . $sam . ' already exists; choose another login or configure that account manually.') . ' }',
            '$password = ConvertTo-SecureString ' . $q($password) . ' -AsPlainText -Force',
            'New-ADUser -Name ' . $q($name) . ' -DisplayName ' . $q($name) . ' -SamAccountName ' . $q($sam) . ' -UserPrincipalName ' . $q($sam . '@' . $dnsName) . ' -Path ' . $q($container) . ' -AccountPassword $password -Enabled $true -Description ' . $q('Read-only LDAP account for UCM Provisioner'),
            'Write-Host ' . $q('Created ' . $sam . ' with normal domain-user read access and no privileged group membership.'),
        ]);
    }
    private function randomPassword(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%+-_';
        $out = '';
        for ($i=0; $i<$length; $i++) { $out .= $alphabet[ord(random_bytes(1)) % strlen($alphabet)]; }
        return $out;
    }
}
