<?php
declare(strict_types=1);

namespace {
    interface BMO {}
    class FreePBX_Helpers {}
}

namespace FreePBX\modules {
    require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
    require_once dirname(__DIR__) . '/lib/ConfigurationSyncService.php';
    require_once dirname(__DIR__) . '/lib/WebPhonebook.php';
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

    final class WebDirectorySyncFreePBX
    {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }

    final class WebDirectorySyncUcm extends Ucmprovisioner
    {
        public int $rebuildCalls = 0;
        public bool $denyWebStore = false;

        public function __construct(
            \PDO $db,
            private int $siteId,
            private string $siteKey,
            private string $siteName,
            private string $webRoot
        ) {
            parent::__construct(new WebDirectorySyncFreePBX($db));
        }

        public function localSiteId(): int { return $this->siteId; }

        public function getSite(int $id, bool $withSecrets = false): ?array
        {
            return $id === $this->siteId
                ? ['id'=>$id, 'site_key'=>$this->siteKey, 'name'=>$this->siteName, 'enabled'=>1, 'is_local'=>1]
                : null;
        }

        public function isSiteLocal(int $siteId): bool
        {
            return $siteId === 0 || $siteId === $this->siteId;
        }

        public function getTemplatesLocal(int $siteId = 0, bool $includeGlobal = false): array
        {
            return [];
        }

        public function getGroupsLocal(int $siteId = 0): array
        {
            return [];
        }

        protected function webDirectory(): \FreePBX\modules\Ucmprovisioner\WebPhonebook
        {
            if ($this->denyWebStore) { throw new \RuntimeException('Web policy is inaccessible to this transport'); }
            return new \FreePBX\modules\Ucmprovisioner\WebPhonebook($this->webRoot);
        }

        public function readWebPolicy(): array { return $this->webDirectory()->policy(); }
        public function readWebManagement(): array { return $this->webDirectory()->management(); }
        public function readWebRevision(): string { return $this->webDirectory()->policyRevision(); }
        public function saveWebPolicy(array $policy, string $revision, array $input = []): void
        {
            $this->saveWebDirectoryPolicy($policy, $revision, $input);
        }

        public function rebuild(
            $selection,
            bool $apply = false,
            ?int $siteId = null,
            bool $restartAfter = false,
            bool $allowMesh = true,
            ?string $expectedPlanHash = null,
            ?string $expectedMeshPlanHash = null,
            bool $replaceManual = false
        ): array {
            $this->rebuildCalls++;
            return ['ok'=>false, 'message'=>'rebuild should not be called for web-directory synchronization'];
        }
    }

    final class WebDirectorySyncBridge
    {
        public bool $omitWebCapability = false;
        public array $capabilityCalls = [];
        public array $assetCalls = [];
        public array $applyCalls = [];

        public function __construct(
            private WebDirectorySyncUcm $source,
            private WebDirectorySyncUcm $target
        ) {}

        public function getSites(): array
        {
            return [
                ['id'=>1, 'name'=>'SITE-A', 'site_key'=>'site-a', 'enabled'=>1],
                ['id'=>2, 'name'=>'SITE-B', 'site_key'=>'site-b', 'enabled'=>1],
            ];
        }

        public function getSite(int $id, bool $withSecrets = false): ?array
        {
            foreach ($this->getSites() as $site) {
                if ((int)$site['id'] === $id) { return $site; }
            }
            return null;
        }

        public function localSiteId(): int { return 1; }

        public function configurationSyncSnapshot(int $source, array $scopes, array $objectFilter = []): array
        {
            return $this->source->configurationSyncSnapshot($source, $scopes, $objectFilter);
        }

        public function configurationSyncCapabilities(int $target): array
        {
            $this->capabilityCalls[] = $target;
            $capabilities = $this->target->configurationSyncCapabilities($target);
            if ($this->omitWebCapability) {
                $capabilities['scopes'] = array_values(array_diff((array)$capabilities['scopes'], ['web_directory']));
            }
            return $capabilities;
        }

        public function configurationSyncAssets(int $source, array $targets, array $scopes, array $options = []): array
        {
            $this->assetCalls[] = compact('source', 'targets', 'scopes', 'options');
            return ['ok'=>true, 'status'=>'skipped', 'items'=>[]];
        }

        public function configurationSyncApply(int $target, array $snapshot, array $options): array
        {
            $this->applyCalls[] = compact('target', 'snapshot', 'options');
            return $this->target->configurationSyncApply($target, $snapshot, $options);
        }

        public function configurationSyncResume(int $target, string $jobKey, string $objectType, string $objectKey): array
        {
            return $this->target->configurationSyncResume($target, $jobKey, $objectType, $objectKey);
        }

        public function configurationSyncRelease(int $target, string $jobKey): array
        {
            return $this->target->configurationSyncRelease($target, $jobKey);
        }

        public function logOperation(?int $siteId, string $operation, string $level, string $message, string $detail = ''): void {}
    }

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    };

    $removeTree = static function (string $path) use (&$removeTree): void {
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) { $removeTree($child); }
            else { @unlink($child); }
        }
        @rmdir($path);
    };

    $writeJson = static function (string $path, array $value): void {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json) !== strlen($json)) {
            throw new \RuntimeException('Could not write web-directory fixture');
        }
    };

    $policy = static function (string $book, string $label = 'Directory policy'): array {
        return \FreePBX\modules\Ucmprovisioner\WebPhonebook::normalizePolicy([
            'enabled'=>true,
            'networks'=>['192.0.2.0/24'],
            'books'=>[$book],
            'fields'=>['name','extension','email'],
            'preferred_url'=>'https://directory.example.test/',
            'access_mode'=>'direct',
            'trusted_proxies'=>[],
            'client_header'=>'HTTP_X_REAL_IP',
            'export_enabled'=>true,
            'policy_label'=>$label,
            'help_url'=>'',
            'alternate_field'=>'label',
        ]);
    };

    $makeRoot = static function (array $books) use ($writeJson): string {
        $root = sys_get_temp_dir() . '/ucmp-web-sync-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/directory', 0770, true)) {
            throw new \RuntimeException('Could not create web-directory fixture');
        }
        $writeJson($root . '/directory/directory.json', [
            'generated'=>1,
            'phonebooks'=>array_map(static fn(string $book): array => [
                'book_key'=>$book,
                'name'=>ucfirst($book),
                'contacts'=>[],
            ], $books),
            'source_freshness'=>[],
        ]);
        return $root;
    };

    $fixture = static function () use ($makeRoot, $writeJson, $policy): array {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($db);
        $db->exec('CREATE TABLE ucmp_phones (site_id INTEGER, extension TEXT, updated INTEGER, group_id INTEGER,last_applied INTEGER DEFAULT 0)');
        $db->exec("INSERT INTO ucmp_phones (site_id,extension,updated,group_id) VALUES (2,'200',7,0)");

        $sourceRoot = $makeRoot(['company']);
        $targetRoot = $makeRoot(['company']);
        $writeJson($sourceRoot . '/web-phonebook.json', $policy('company', 'Source policy'));
        $writeJson($targetRoot . '/web-phonebook.json', $policy('company', 'Destination policy'));
        $source = new WebDirectorySyncUcm($db, 1, 'site-a', 'SITE-A', $sourceRoot);
        $target = new WebDirectorySyncUcm($db, 2, 'site-b', 'SITE-B', $targetRoot);
        $bridge = new WebDirectorySyncBridge($source, $target);
        $stateDir = sys_get_temp_dir() . '/ucmp-web-sync-state-' . bin2hex(random_bytes(6));
        $service = new \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService(
            $db,
            $bridge,
            $stateDir,
            static fn(): int => 1700000000
        );
        return compact('db', 'sourceRoot', 'targetRoot', 'source', 'target', 'bridge', 'stateDir', 'service');
    };

    $jobInput = static function (bool $hardLock = false): array {
        return [
            'name'=>'Web directory policy',
            'source_site_id'=>1,
            'target_site_ids'=>[2],
            'scopes'=>['web_directory'],
            'web_directory_access_confirmed'=>1,
            'web_directory_hard_lock'=>$hardLock ? 1 : 0,
            'enabled'=>1,
            'soft_lock'=>1,
            'retry_unreachable'=>1,
            'schedule_mode'=>'interval',
            'interval_minutes'=>5,
        ];
    };

    // Save a scheduled hard-lock job, prove an old destination is rejected
    // before apply, then run the same job through the real source/apply hooks.
    $hard = $fixture();
    try {
        $saved = $hard['service']->save($jobInput(true));
        $job = (array)($saved['job'] ?? []);
        $jobId = (int)($job['id'] ?? 0);
        $expect($jobId > 0, 'Web-directory synchronization job must be saved');
        $expect(($job['object_filter']['web_directory'] ?? []) === ['access_confirmed'=>true, 'hard_lock'=>true], 'Hard-lock and access confirmation must be persisted in the decoded job');
        $rawFilter = json_decode((string)$hard['db']->query('SELECT object_filter_json FROM ucmp_configuration_sync_jobs WHERE id=' . $jobId)->fetchColumn(), true);
        $expect(($rawFilter['web_directory'] ?? []) === ['access_confirmed'=>true, 'hard_lock'=>true], 'Hard-lock option must survive durable schedule persistence');

        $beforeOldDestination = file_get_contents($hard['targetRoot'] . '/web-phonebook.json');
        $hard['bridge']->omitWebCapability = true;
        $hard['db']->exec('UPDATE ucmp_configuration_sync_jobs SET next_run=1700000000 WHERE id=' . $jobId);
        $old = $hard['service']->runDue();
        $expect(empty($old['ok']) && $hard['bridge']->applyCalls === [], 'A destination without web-directory capability must fail before apply');
        $expect(file_get_contents($hard['targetRoot'] . '/web-phonebook.json') === $beforeOldDestination, 'Capability rejection must leave the destination policy untouched');

        $hard['bridge']->omitWebCapability = false;
        $hard['db']->exec('UPDATE ucmp_configuration_sync_jobs SET next_run=1700000000 WHERE id=' . $jobId);
        $run = $hard['service']->runDue();
        $expect(!empty($run['ok']) && ($run['status'] ?? '') === 'success', 'Scheduled web-directory synchronization must succeed');
        $expect(count($hard['bridge']->applyCalls) === 1, 'The successful scheduled run must apply exactly once');
        $expect(($hard['bridge']->applyCalls[0]['options']['web_directory'] ?? []) === ['access_confirmed'=>true, 'hard_lock'=>true], 'Scheduled apply must receive the persisted hard-lock options');
        $expect($hard['target']->readWebPolicy() === $hard['source']->readWebPolicy(), 'Successful apply must copy the source policy');
        $management = $hard['target']->readWebManagement();
        $expect(($management['job_key'] ?? '') === 'cfgw:site-a:' . $jobId && !empty($management['hard_lock']) && ($management['status'] ?? '') === 'active', 'Successful apply must return and retain the controlling origin');
        $item = $hard['db']->query("SELECT status,object_type,object_key FROM ucmp_configuration_sync_items WHERE job_id=$jobId AND target_site_id=2")->fetch(\PDO::FETCH_ASSOC);
        $expect(($item['status'] ?? '') === 'active' && ($item['object_type'] ?? '') === 'web_directory' && ($item['object_key'] ?? '') === 'web-directory-policy', 'Only the web-directory ownership item must be tracked');
        $expect((int)$hard['db']->query("SELECT updated FROM ucmp_phones WHERE site_id=2 AND extension='200'")->fetchColumn() === 7, 'Web-directory synchronization must not mark phones pending');
        $expect($hard['target']->rebuildCalls === 0, 'Web-directory synchronization must not rebuild phones');

        $staleRelease = $hard['target']->configurationSyncReleaseLocal('cfgw:stale-controller:999');
        $expect(!empty($staleRelease['ok']) && (int)$staleRelease['released'] === 0, 'Releasing an unrelated stale job must be an idempotent no-op');
        $expect($hard['target']->readWebManagement() === $management, 'Stale release must preserve the active hard-lock owner');
        $ownerStatus = $hard['db']->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_id=$jobId AND target_site_id=2 AND object_type='web_directory'")->fetchColumn();
        $expect($ownerStatus === 'active', 'Stale release must not release another job tracking row');

        // A published-book dependency failure is a warning, not permission to
        // rewrite either the effective policy or its hard-lock metadata.
        $writeJson($hard['sourceRoot'] . '/web-phonebook.json', $policy('missing', 'Missing source book'));
        $beforeMissing = file_get_contents($hard['targetRoot'] . '/web-phonebook.json');
        $missing = $hard['service']->runNow($jobId);
        $expect(!empty($missing['ok']) && ($missing['status'] ?? '') === 'warning', 'Missing destination phonebook must be reported as a synchronized warning');
        $expect(file_get_contents($hard['targetRoot'] . '/web-phonebook.json') === $beforeMissing, 'Missing dependency must preserve destination policy bytes and ownership');
        $missingItem = $hard['db']->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_id=$jobId AND target_site_id=2 AND object_type='web_directory'")->fetchColumn();
        $expect($missingItem === 'missing_dependency', 'Missing published book must be tracked as missing_dependency');

        $ownerBefore = file_get_contents($hard['targetRoot'] . '/web-phonebook.json');
        $ownerConflict = (new \FreePBX\modules\Ucmprovisioner\WebPhonebook($hard['targetRoot']))->applyManagedPolicy(
            $hard['target']->readWebPolicy(),
            ['job_key'=>'cfgw:site-b:99', 'source_site_key'=>'site-b', 'source_site_name'=>'SITE-B', 'source_revision'=>'revision-b', 'hard_lock'=>false],
            true
        );
        $expect(empty($ownerConflict['ok']) && ($ownerConflict['status'] ?? '') === 'owner_conflict', 'A force apply from a different owner must not steal the policy');
        $expect(file_get_contents($hard['targetRoot'] . '/web-phonebook.json') === $ownerBefore, 'Owner conflict must leave the origin and effective policy unchanged');

        $hardDenied = false;
        try {
            $hard['target']->saveWebPolicy($policy('company', 'Local hard-lock edit'), $hard['target']->readWebRevision(), ['sync_edit_confirmed'=>1]);
        } catch (\RuntimeException $e) {
            $hardDenied = str_contains($e->getMessage(), 'hard-locked');
        }
        $expect($hardDenied, 'A hard-locked synchronized policy must deny local edits');
        $expect(($hard['target']->readWebManagement()['source_site_key'] ?? '') === 'site-a', 'A denied local edit must retain the source origin');
    } finally {
        $removeTree($hard['sourceRoot']);
        $removeTree($hard['targetRoot']);
        $removeTree($hard['stateDir']);
    }

    // A soft owner pauses only after an explicitly confirmed local edit; resume
    // and release alter ownership metadata while preserving the edited policy.
    $soft = $fixture();
    try {
        $saved = $soft['service']->save($jobInput(false));
        $jobId = (int)($saved['job']['id'] ?? 0);
        $run = $soft['service']->runNow($jobId);
        $expect(!empty($run['ok']), 'Soft web-directory synchronization must apply before the local edit test');
        $edited = $policy('company', 'Destination edit');
        $soft['target']->saveWebPolicy($edited, $soft['target']->readWebRevision(), [
            'sync_edit_confirmed'=>1,
            'sync_edit_reason'=>'Web-directory integration test edit',
        ]);
        $afterEdit = $soft['target']->readWebPolicy();
        $expect($afterEdit === $edited && ($soft['target']->readWebManagement()['status'] ?? '') === 'paused', 'A confirmed soft-owner edit must pause ownership and preserve the edited policy');
        $pausedItem = $soft['db']->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_id=$jobId AND target_site_id=2 AND object_type='web_directory'")->fetchColumn();
        $expect($pausedItem === 'paused', 'The local web-directory edit must pause its tracked synchronization item');

        $resumed = $soft['service']->resume($jobId, 2, 'web_directory', 'web-directory-policy');
        $expect(!empty($resumed['ok']) && ($soft['target']->readWebManagement()['status'] ?? '') === 'active', 'Resume must reactivate the matching soft web-directory owner');
        $expect($soft['target']->readWebPolicy() === $edited, 'Resume must leave the effective edited policy unchanged');
        $resumedItem = $soft['db']->query("SELECT status FROM ucmp_configuration_sync_items WHERE job_id=$jobId AND target_site_id=2 AND object_type='web_directory'")->fetchColumn();
        $expect($resumedItem === 'active', 'Resume must reactivate the controller tracking item');

        $released = $soft['service']->delete($jobId);
        $expect(!empty($released['ok']), 'Deleting the synchronization job must release its web-directory owner');
        $expect($soft['target']->readWebPolicy() === $edited && $soft['target']->readWebManagement() === [], 'Release must preserve the effective policy while removing only ownership metadata');
        $expect((int)$soft['db']->query("SELECT COUNT(*) FROM ucmp_configuration_sync_items WHERE job_id=$jobId")->fetchColumn() === 0, 'Released job tracking rows must be removed with the job');
        $expect($soft['target']->rebuildCalls === 0, 'Soft web-directory synchronization must not rebuild phones');

        $soft['target']->denyWebStore = true;
        $ordinaryRelease = $soft['target']->configurationSyncReleaseLocal('cfg:site-a:999');
        $expect(!empty($ordinaryRelease['ok']), 'Releasing an ordinary configuration job must not require access to unrelated private web policy');
    } finally {
        $removeTree($soft['sourceRoot']);
        $removeTree($soft['targetRoot']);
        $removeTree($soft['stateDir']);
    }

    $sitesView = (string)file_get_contents(dirname(__DIR__) . '/views/sites.php');
    $expect(str_contains($sitesView, 'value="web_directory"')
        && str_contains($sitesView, 'id="ucm-cfg-web-confirm"')
        && str_contains($sitesView, 'id="ucm-cfg-web-hard-lock"'), 'Configuration sync UI must expose the web-directory scope, access confirmation and hard-lock controls');
    $expect(str_contains($sitesView, 'web_directory_access_confirmed:$(\'#ucm-cfg-web-confirm\').is(\':checked\')?1:0')
        && str_contains($sitesView, 'web_directory_hard_lock:$(\'#ucm-cfg-web-hard-lock\').is(\':checked\')?1:0'), 'Configuration sync UI payload must persist both web-directory safety options');

    echo "Web-directory configuration sync assertions passed\n";
}
