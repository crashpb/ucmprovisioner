<?php
/**
 * Maintains bidirectional res_pjsip_publish_asterisk device-state links.
 *
 * Existing publication sections are repaired in place. Missing sections are
 * generated only when an administrator selected an exact existing PJSIP trunk
 * endpoint; this class references that endpoint and its auth object but never
 * creates trunks or copies trunk credentials.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/ServiceFilePermissions.php';

class PublishMesh
{
    private const ASTDIR = '/etc/asterisk';
    private const STATE_DIR = '/var/lib/asterisk/ucmprovisioner/publish-mesh';
    private const TARGET_FILE = 'pjsip_custom.conf';
    private const MAX_FILE_BYTES = 4194304;
    private const MAX_PARTICIPANTS = 64;
    private const BEGIN = '; ===== ucmprovisioner publish-mesh managed BEGIN =====';
    private const END = '; ===== ucmprovisioner publish-mesh managed END =====';
    private const RELEVANT_TYPES = ['outbound-publish', 'asterisk-publication', 'inbound-publication'];

    private $ucm;
    private string $astDir;
    private string $stateDir;
    /** @var callable|null */
    private $commandRunner;

    public function __construct($ucm, ?string $astDir = null, ?string $stateDir = null, ?callable $commandRunner = null)
    {
        $this->ucm = $ucm;
        $this->astDir = rtrim($astDir ?: self::ASTDIR, '/');
        $this->stateDir = rtrim($stateDir ?: self::STATE_DIR, '/');
        $this->commandRunner = $commandRunner;
    }

    public function sync(bool $apply = false, array $siteIds = [], ?string $expectedPlanHash = null): array
    {
        $preview = $this->preview($siteIds);
        if (!$apply || empty($preview['ok'])) {
            return $preview;
        }
        $transactionId = self::newTransactionId();
        $prepared = $this->prepare($siteIds, (string)$expectedPlanHash, $transactionId);
        if (empty($prepared['ok'])) {
            return $prepared;
        }
        return $this->commit($transactionId);
    }

    /** Used by the forced-command agent to configure the return side of one link. */
    public function syncExplicitPeer(array $peer, bool $apply = false, ?string $expectedPlanHash = null): array
    {
        $preview = $this->previewExplicitPeer($peer);
        if (!$apply || empty($preview['ok'])) {
            return $preview;
        }
        $transactionId = self::newTransactionId();
        $prepared = $this->prepareExplicitPeer($peer, (string)$expectedPlanHash, $transactionId);
        if (empty($prepared['ok'])) {
            return $prepared;
        }
        return $this->commit($transactionId);
    }

    public function preview(array $siteIds = []): array
    {
        return $this->previewPlan($this->plan($siteIds));
    }

    public function previewExplicitPeer(array $peer): array
    {
        return $this->previewPlan($this->planExplicitPeer($peer));
    }

    public function prepare(array $siteIds, string $expectedPlanHash, string $transactionId): array
    {
        return $this->preparePlan($this->plan($siteIds), $expectedPlanHash, $transactionId);
    }

    public function prepareExplicitPeer(array $peer, string $expectedPlanHash, string $transactionId): array
    {
        return $this->preparePlan($this->planExplicitPeer($peer), $expectedPlanHash, $transactionId);
    }

    /** Plan all links represented by this PBX's local Sites registry. */
    public function plan(array $siteIds = []): array
    {
        $localId = $this->ucm->localSiteId();
        $local = $this->ucm->getSite($localId);
        if (!$local) {
            return ['ok' => false, 'message' => 'No local site configured'];
        }

        $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds))));
        $peers = [];
        foreach ($this->ucm->getSites() as $site) {
            if ((int)$site['id'] === $localId || empty($site['enabled'])) {
                continue;
            }
            if ($siteIds && !in_array((int)$site['id'], $siteIds, true)) {
                continue;
            }
            $peers[] = [
                'key' => (string)($site['site_key'] ?: $site['id']),
                'name' => (string)$site['name'],
                'endpoint' => trim((string)($site['trunk_endpoint'] ?? '')),
                'peer_resource' => trim((string)($site['remote_trunk_endpoint'] ?? '')),
                'local_addr' => (string)($local['pbx_addr'] ?? ''),
                'local_port' => (int)($local['pbx_sip_port'] ?? 5160),
                'peer_addr' => (string)($site['pbx_addr'] ?? ''),
                'peer_port' => (int)($site['pbx_sip_port'] ?? 5160),
            ];
        }
        if (!$peers) {
            return $this->emptyPlan(['No other enabled sites are registered.']);
        }

        return $this->buildPlan($peers, true);
    }

    /** Plan one peer from explicit, already-normalized federation data. */
    private function planExplicitPeer(array $peer): array
    {
        try {
            $normalized = $this->normalizePeer([
                'key' => (string)($peer['key'] ?? 'peer'),
                'name' => (string)($peer['name'] ?? 'peer'),
                'endpoint' => (string)($peer['endpoint'] ?? ''),
                'peer_resource' => (string)($peer['peer_resource'] ?? ''),
                'local_addr' => (string)($peer['local_addr'] ?? ''),
                'local_port' => (int)($peer['local_port'] ?? 5160),
                'peer_addr' => (string)($peer['peer_addr'] ?? ''),
                'peer_port' => (int)($peer['peer_port'] ?? 5160),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return $this->buildPlan([$normalized], false);
    }

    /** @param array<int,array<string,mixed>> $peers */
    private function buildPlan(array $peers, bool $allowStaged): array
    {
        $plan = $this->emptyPlan();
        $custom = $this->readFile('pjsip_custom.conf');
        $customSections = $this->splitSections($custom);
        $stagedSections = $allowStaged ? $this->splitSections($this->readFile('pjsip_staged_mesh_blf.conf')) : [];
        $found = [];

        foreach ($customSections as $section) {
            if (!in_array($section['type'], self::RELEVANT_TYPES, true)) {
                continue;
            }
            $peer = $this->matchPeer($section, $peers);
            if (!$peer) {
                $plan['warnings'][] = "[{$section['name']}] ({$section['type']}) was not matched to a configured site and was left untouched.";
                continue;
            }
            $found[$peer['key']][$section['type']] = true;
            $newKv = $this->repairUriLines($section['kv'], $peer);
            if ($newKv !== $section['kv']) {
                $plan['repairs'][] = [
                    'name' => $section['name'],
                    'type' => $section['type'],
                    'peer' => $peer['name'],
                    'old' => $this->render($section['name'], $section['type'], $section['kv']),
                    'new' => $this->render($section['name'], $section['type'], $newKv),
                ];
            }
        }

        foreach ($stagedSections as $section) {
            if (!in_array($section['type'], self::RELEVANT_TYPES, true)
                || $this->findSection($customSections, $section['name'], $section['type'])) {
                continue;
            }
            $peer = $this->matchPeer($section, $peers);
            if (!$peer) {
                $plan['warnings'][] = "[{$section['name']}] ({$section['type']}) remains staged because no site matched it.";
                continue;
            }
            $found[$peer['key']][$section['type']] = true;
            $plan['promotions'][] = $this->render(
                $section['name'],
                $section['type'],
                $this->repairUriLines($section['kv'], $peer)
            );
        }

        foreach ($peers as $rawPeer) {
            try {
                $peer = $this->normalizePeer($rawPeer);
            } catch (\Throwable $e) {
                $plan['warnings'][] = "Site '{$rawPeer['name']}': " . $e->getMessage();
                continue;
            }
            foreach ($this->buildPeerSections($peer) as $type => $text) {
                if (empty($found[$peer['key']][$type])) {
                    $plan['creations'][] = $text;
                }
            }
        }

        $plan['peers'] = array_values(array_map(static fn($p) => (string)$p['name'], $peers));
        $plan['message'] = sprintf(
            'Plan: %d repair(s), %d staged promotion(s), %d new object(s).',
            count($plan['repairs']), count($plan['promotions']), count($plan['creations'])
        );
        return $plan;
    }

    private function emptyPlan(array $warnings = []): array
    {
        return [
            'ok' => true,
            'repairs' => [],
            'promotions' => [],
            'creations' => [],
            'warnings' => $warnings,
            'peers' => [],
        ];
    }

    private function previewPlan(array $plan): array
    {
        if (empty($plan['ok'])) {
            return $plan + ['mode' => 'error'];
        }
        try {
            $current = $this->readTarget();
            $desired = $this->renderDesired($plan, $current);
            $targetExists = is_file($this->path(self::TARGET_FILE));
            $identity = [
                'schema' => 1,
                'target' => self::TARGET_FILE,
                'current_sha256' => hash('sha256', $current),
                'desired_sha256' => hash('sha256', $desired),
                'current_exists' => $targetExists,
                'desired_exists' => $targetExists || $desired !== $current,
                'peers' => array_values($plan['peers']),
                'repairs' => array_map(
                    static fn(array $row): string => (string)$row['name'] . '|' . (string)$row['type'],
                    $plan['repairs']
                ),
                'promotions_sha256' => hash('sha256', implode("\n\n", $plan['promotions'])),
                'creations_sha256' => hash('sha256', implode("\n\n", $plan['creations'])),
            ];
            sort($identity['peers'], SORT_STRING);
            sort($identity['repairs'], SORT_STRING);
            return array_merge($plan, [
                'mode' => 'dry-run',
                'plan_hash' => self::hashCanonical($identity),
                'current_sha256' => $identity['current_sha256'],
                'desired_sha256' => $identity['desired_sha256'],
                'current_exists' => $identity['current_exists'],
                'desired_exists' => $identity['desired_exists'],
                'changed' => !hash_equals($identity['current_sha256'], $identity['desired_sha256']),
                'message' => 'DRY-RUN: ' . (string)($plan['message'] ?? 'no files were changed.'),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function preparePlan(array $plan, string $expectedPlanHash, string $transactionId): array
    {
        return $this->withWriterLock($transactionId, 'prepare', fn(): array => $this->preparePlanLocked($plan, $expectedPlanHash, $transactionId));
    }

    private function preparePlanLocked(array $plan, string $expectedPlanHash, string $transactionId): array
    {
        try {
            $this->assertTransactionId($transactionId);
            if ($expectedPlanHash === '' || preg_match('/^[a-f0-9]{64}$/D', $expectedPlanHash) !== 1) {
                throw new \InvalidArgumentException('Apply requires the exact 64-character mesh preview hash');
            }
            $preview = $this->previewPlan($plan);
            if (empty($preview['ok'])) {
                return $preview;
            }
            if (!hash_equals((string)$preview['plan_hash'], $expectedPlanHash)) {
                return [
                    'ok' => false,
                    'mode' => 'prepare',
                    'conflict' => true,
                    'plan_hash' => $preview['plan_hash'],
                    'message' => 'The publication mesh changed after preview; preview it again before applying.',
                ];
            }

            $this->ensureStateDirectory();
            $existing = $this->readState($transactionId, false);
            if ($existing !== null) {
                if (($existing['phase'] ?? '') === 'prepared'
                    && hash_equals((string)($existing['plan_hash'] ?? ''), $expectedPlanHash)
                ) {
                    return $this->stateResult($existing, true, 'prepare', 'Mesh participant was already prepared.');
                }
                throw new \RuntimeException('The mesh transaction identifier is already in use');
            }

            $target = $this->path(self::TARGET_FILE);
            if (is_link($target)) {
                throw new \RuntimeException('Refusing to replace a symlinked pjsip_custom.conf');
            }
            $before = $this->readTarget();
            if (!hash_equals((string)$preview['current_sha256'], hash('sha256', $before))) {
                throw new \RuntimeException('pjsip_custom.conf changed while the mesh transaction was being prepared');
            }
            $desired = $this->renderDesired($plan, $before);
            $stat = is_file($target) ? (@stat($target) ?: []) : [];
            $state = [
                'schema' => 1,
                'transaction_id' => $transactionId,
                'phase' => 'prepared',
                'created_at' => time(),
                'plan_hash' => $expectedPlanHash,
                'before_sha256' => hash('sha256', $before),
                'desired_sha256' => hash('sha256', $desired),
                'before_b64' => base64_encode($before),
                'desired_b64' => base64_encode($desired),
                'file_existed' => is_file($target),
                'desired_existed' => is_file($target) || $desired !== $before,
                'mode' => isset($stat['mode']) ? ((int)$stat['mode'] & 0777) : 0660,
                'uid' => isset($stat['uid']) ? (int)$stat['uid'] : null,
                'gid' => isset($stat['gid']) ? (int)$stat['gid'] : null,
                'changed' => !hash_equals(hash('sha256', $before), hash('sha256', $desired)),
                'counts' => [
                    'repairs' => count($plan['repairs']),
                    'promotions' => count($plan['promotions']),
                    'creations' => count($plan['creations']),
                ],
            ];
            $this->writeState($state);
            return $this->stateResult($state, true, 'prepare', 'Mesh participant prepared; live Asterisk state is unchanged.');
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => 'prepare', 'transaction_id' => $transactionId, 'message' => $e->getMessage()];
        }
    }

    public function commit(string $transactionId): array
    {
        return $this->withWriterLock($transactionId, 'commit', fn(): array => $this->commitLocked($transactionId));
    }

    private function commitLocked(string $transactionId): array
    {
        $writeAttempted = false;
        $verified = false;
        $reloadStarted = false;
        $reloadReceiptKnown = false;
        try {
            $this->assertTransactionId($transactionId);
            $state = $this->readState($transactionId);
            $phase = (string)($state['phase'] ?? '');
            if ($phase === 'committed'
                && $this->targetMatches((string)$state['desired_sha256'], !empty($state['desired_existed']))
            ) {
                return $this->stateResult($state, true, 'commit', 'Mesh participant was already committed.');
            }
            if ($phase !== 'prepared') {
                return $this->stateResult($state, false, 'commit', "Mesh transaction cannot commit from phase '$phase'; inspect its retained evidence")
                    + ['recovery_required'=>in_array($phase, ['committing','rollback_failed'], true)];
            }
            if (!$this->targetMatches((string)$state['before_sha256'], !empty($state['file_existed']))) {
                throw new \RuntimeException('pjsip_custom.conf changed after prepare; no mesh data was written');
            }

            if (empty($state['changed'])) {
                $state['phase'] = 'committed';
                $state['committed_at'] = time();
                $this->writeState($state);
                return $this->stateResult($state, true, 'commit', 'Mesh participant already matched the approved plan; no reload was needed.');
            }

            $this->writeJson($this->stateDir . '/.writer-owner', ['transaction_id'=>$transactionId]);
            $state['phase'] = 'committing';
            $this->writeState($state);
            $desired = $this->decodeStateContent($state, 'desired_b64', 'desired_sha256');
            $writeAttempted = true;
            $this->atomicWriteTarget($desired, $state);
            if (!$this->targetMatches((string)$state['desired_sha256'], !empty($state['desired_existed']))) {
                throw new \RuntimeException('Atomic mesh replacement did not produce the approved content hash');
            }
            $reloadStarted = true;
            $reload = $this->reloadPjsip();
            $reloadReceiptKnown = !in_array((int)($reload['status'] ?? 1), [124,137], true);
            if (empty($reload['ok'])) {
                throw new \RuntimeException((string)$reload['message']);
            }
            $verified = true;
            $state['phase'] = 'committed';
            $state['committed_at'] = time();
            $state['reload'] = $reload;
            $this->writeState($state);
            return $this->stateResult($state, true, 'commit', 'Mesh participant committed and PJSIP reload succeeded.');
        } catch (\Throwable $e) {
            if ($verified) {
                return $this->stateResult($state, true, 'commit', 'Mesh files and PJSIP reload succeeded; completion receipt could not be saved.')
                    + ['recovery_required'=>true, 'reporting_error'=>'The writer reservation and transaction evidence were retained; reconcile the receipt before another mesh change.'];
            }
            if ($reloadStarted && !$reloadReceiptKnown) {
                return $this->stateResult($state, false, 'commit', 'Mesh files were published, but the PJSIP reload outcome is uncertain. The writer reservation was retained; inspect runtime and transaction evidence.')
                    + ['recovery_required'=>true, 'files_published'=>true, 'pbx_activated'=>null];
            }
            if (!$writeAttempted) {
                return ['ok'=>false, 'mode'=>'commit', 'transaction_id'=>$transactionId,
                    'recovery_required'=>is_file($this->stateDir . '/.writer-owner'), 'message'=>$e->getMessage()];
            }
            $rollback = $this->rollbackLocked($transactionId);
            return [
                'ok' => false,
                'mode' => 'commit',
                'transaction_id' => $transactionId,
                'rollback' => $rollback,
                'recovery_required' => empty($rollback['ok']),
                'message' => $e->getMessage()
                    . (!empty($rollback['ok']) ? ' The participant was restored.' : ' Automatic restore requires attention.'),
            ];
        }
    }

    public function rollback(string $transactionId): array
    {
        return $this->withWriterLock($transactionId, 'rollback', fn(): array => $this->rollbackLocked($transactionId));
    }

    private function rollbackLocked(string $transactionId): array
    {
        try {
            $this->assertTransactionId($transactionId);
            $state = $this->readState($transactionId);
            $phase = (string)($state['phase'] ?? '');
            if (in_array($phase, ['aborted', 'rolled_back'], true)) {
                return $this->stateResult($state, true, 'rollback', 'Mesh participant was already restored.');
            }
            if ($phase === 'prepared') {
                $state['phase'] = 'aborted';
                $state['rolled_back_at'] = time();
                $this->writeState($state);
                return $this->stateResult($state, true, 'rollback', 'Prepared mesh participant was aborted before commit.');
            }
            if (!in_array($phase, ['committing', 'committed', 'rollback_failed'], true)) {
                throw new \RuntimeException("Mesh transaction cannot roll back from phase '$phase'");
            }

            $desiredMatches = $this->targetMatches((string)$state['desired_sha256'], !empty($state['desired_existed']));
            $beforeMatches = $this->targetMatches((string)$state['before_sha256'], !empty($state['file_existed']));
            if (!$desiredMatches && !$beforeMatches
            ) {
                return [
                    'ok' => false,
                    'mode' => 'rollback',
                    'conflict' => true,
                    'phase' => $phase,
                    'transaction_id' => $transactionId,
                    'message' => 'pjsip_custom.conf changed after commit; rollback refused to overwrite that newer state.',
                ];
            }

            $contentRestored = $beforeMatches;
            $this->writeJson($this->stateDir . '/.writer-owner', ['transaction_id'=>$transactionId]);
            if (!$contentRestored) {
                $this->restoreTarget($state);
                $contentRestored = $this->targetMatches((string)$state['before_sha256'], !empty($state['file_existed']));
            }
            if (!$contentRestored) {
                throw new \RuntimeException('The exact pre-transaction pjsip_custom.conf snapshot could not be restored');
            }

            $reload = empty($state['changed']) ? ['ok' => true, 'message' => 'No reload required'] : $this->reloadPjsip();
            $state['phase'] = !empty($reload['ok']) ? 'rolled_back' : 'rollback_failed';
            $state['rolled_back_at'] = time();
            $state['rollback_reload'] = $reload;
            $this->writeState($state);
            return $this->stateResult(
                $state,
                !empty($reload['ok']),
                'rollback',
                !empty($reload['ok'])
                    ? 'Exact mesh snapshot restored and PJSIP reload succeeded.'
                    : 'Exact mesh snapshot was restored, but the PJSIP reload failed.'
            ) + ['content_restored' => true, 'reload_restored' => !empty($reload['ok'])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => 'rollback', 'transaction_id' => $transactionId, 'message' => $e->getMessage()];
        }
    }

    /**
     * Execute one bounded two-phase transaction across local/remote participant callbacks.
     *
     * Each participant exposes only preview, prepare, commit and rollback. Remote commit
     * and rollback receive the opaque transaction ID, never a site ID or peer address.
     *
     * @param array<int,array{id:string,preview:callable,prepare:callable,commit:callable,rollback:callable}> $participants
     */
    public static function coordinate(array $participants, bool $apply = false, ?string $expectedPlanHash = null): array
    {
        if (!$participants || count($participants) > self::MAX_PARTICIPANTS) {
            return ['ok' => false, 'mode' => 'error', 'message' => 'Choose between one and ' . self::MAX_PARTICIPANTS . ' mesh participants'];
        }
        $normalized = [];
        foreach ($participants as $participant) {
            $id = trim((string)($participant['id'] ?? ''));
            if ($id === '' || strlen($id) > 96 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $id) !== 1) {
                return ['ok' => false, 'mode' => 'error', 'message' => 'A mesh participant has an invalid stable identifier'];
            }
            if (isset($normalized[$id])) {
                return ['ok' => false, 'mode' => 'error', 'message' => "Duplicate mesh participant '$id'"];
            }
            foreach (['preview', 'prepare', 'commit', 'rollback'] as $operation) {
                if (!isset($participant[$operation]) || !is_callable($participant[$operation])) {
                    return ['ok' => false, 'mode' => 'error', 'message' => "Mesh participant '$id' is missing $operation"];
                }
            }
            $normalized[$id] = $participant;
        }
        $previews = [];
        $identity = [];
        foreach ($normalized as $id => $participant) {
            $preview = self::callParticipant($participant['preview']);
            $previews[$id] = $preview;
            if (empty($preview['ok']) || !preg_match('/^[a-f0-9]{64}$/D', (string)($preview['plan_hash'] ?? ''))) {
                return [
                    'ok' => false,
                    'mode' => 'dry-run',
                    'participants' => $previews,
                    'message' => "Mesh participant '$id' could not produce a valid preview: " . (string)($preview['message'] ?? 'unknown error'),
                ];
            }
            $identity[$id] = (string)$preview['plan_hash'];
        }
        ksort($identity, SORT_STRING);
        $planHash = self::hashCanonical(['schema' => 1, 'participants' => $identity]);
        $base = [
            'ok' => true,
            'mode' => 'dry-run',
            'plan_hash' => $planHash,
            'participants' => $previews,
            'message' => sprintf('DRY-RUN: %d mesh participant(s) prepared for two-phase apply; no live files were changed.', count($normalized)),
        ];
        if (!$apply) {
            return $base;
        }
        if ($expectedPlanHash === null || !hash_equals($planHash, $expectedPlanHash)) {
            return array_merge($base, [
                'ok' => false,
                'mode' => 'prepare',
                'conflict' => true,
                'message' => 'The multi-site mesh changed after preview; preview it again before applying.',
            ]);
        }

        $transactionId = self::newTransactionId();
        $prepared = [];
        $committed = [];
        $status = [];
        foreach ($normalized as $id => $participant) {
            $result = self::callParticipant(
                $participant['prepare'],
                [(string)$previews[$id]['plan_hash'], $transactionId]
            );
            $status[$id] = ['prepare' => $result];
            if (empty($result['ok'])) {
                $rollback = self::compensate($normalized, array_keys($prepared), $transactionId);
                return [
                    'ok' => false,
                    'mode' => 'prepare',
                    'transaction_id' => $transactionId,
                    'failed_participant' => $id,
                    'participants' => $status,
                    'rollback' => $rollback,
                    'partial' => self::hasRollbackFailure($rollback),
                    'message' => "Prepare failed at '$id'; every prepared participant was aborted.",
                ];
            }
            $prepared[$id] = true;
        }

        foreach ($normalized as $id => $participant) {
            $result = self::callParticipant($participant['commit'], [$transactionId]);
            $status[$id]['commit'] = $result;
            if (!empty($result['receipt_unknown']) || !empty($result['recovery_required'])) {
                return [
                    'ok'=>false, 'mode'=>'commit', 'transaction_id'=>$transactionId,
                    'failed_participant'=>$id, 'participants'=>$status, 'partial'=>true, 'recovery_required'=>true,
                    'message'=>"Commit outcome or receipt at '$id' needs reconciliation. Completed participant results were retained; no compensation or transport replay was attempted.",
                ];
            }
            if (empty($result['ok'])) {
                $rollbackIds = array_values(array_unique(array_merge(
                    array_keys($committed),
                    [$id],
                    array_values(array_diff(array_keys($prepared), array_keys($committed), [$id]))
                )));
                $rollback = self::compensate($normalized, array_reverse($rollbackIds), $transactionId);
                return [
                    'ok' => false,
                    'mode' => 'commit',
                    'transaction_id' => $transactionId,
                    'failed_participant' => $id,
                    'participants' => $status,
                    'rollback' => $rollback,
                    'partial' => self::hasRollbackFailure($rollback),
                    'message' => "Commit failed at '$id'; committed participants were compensated in reverse order.",
                ];
            }
            $committed[$id] = true;
        }

        return [
            'ok' => true,
            'mode' => 'apply',
            'plan_hash' => $planHash,
            'transaction_id' => $transactionId,
            'participants' => $status,
            'partial' => false,
            'message' => sprintf('Committed the publication mesh on all %d participant(s).', count($normalized)),
        ];
    }

    /** @return array<string,string> type => rendered section */
    private function buildPeerSections(array $peer): array
    {
        $endpoint = $peer['endpoint'];
        $publishName = strtolower((string)preg_replace('/[^A-Za-z0-9_.-]+/', '-', $endpoint)) . '-ucmp-devicestate';
        $peerUri = 'sip:' . $peer['peer_resource'] . '@' . $this->sipHost($peer['peer_addr']) . ':' . $peer['peer_port'];
        $localUri = 'sip:' . $endpoint . '@' . $this->sipHost($peer['local_addr']) . ':' . $peer['local_port'];

        return [
            'inbound-publication' => $this->render($endpoint, 'inbound-publication', [
                'type' => 'inbound-publication',
                'event_asterisk-devicestate' => $endpoint,
            ]),
            'asterisk-publication' => $this->render($endpoint, 'asterisk-publication', [
                'type' => 'asterisk-publication',
                'devicestate_publish' => $publishName,
                'device_state' => 'yes',
            ]),
            'outbound-publish' => $this->render($publishName, 'outbound-publish', [
                'type' => 'outbound-publish',
                'server_uri' => $peerUri,
                'from_uri' => $localUri,
                'to_uri' => $peerUri,
                'event' => 'asterisk-devicestate',
                'outbound_auth' => $endpoint,
            ]),
        ];
    }

    private function normalizePeer(array $peer): array
    {
        $peer['endpoint'] = trim((string)($peer['endpoint'] ?? ''));
        $peer['peer_resource'] = trim((string)($peer['peer_resource'] ?? ''));
        $peer['local_addr'] = trim((string)($peer['local_addr'] ?? ''));
        $peer['peer_addr'] = trim((string)($peer['peer_addr'] ?? ''));
        $peer['local_port'] = (int)($peer['local_port'] ?? 5160);
        $peer['peer_port'] = (int)($peer['peer_port'] ?? 5160);
        if ($peer['endpoint'] === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $peer['endpoint']) !== 1) {
            throw new \InvalidArgumentException('select a valid PJSIP trunk endpoint');
        }
        if ($peer['peer_resource'] === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $peer['peer_resource']) !== 1) {
            throw new \InvalidArgumentException('select the exact return endpoint on the remote PBX');
        }
        foreach (['local_addr', 'peer_addr'] as $field) {
            if ($peer[$field] === '' || preg_match('/^[A-Za-z0-9.:-]+$/', $peer[$field]) !== 1) {
                throw new \InvalidArgumentException("$field is missing or invalid");
            }
        }
        foreach (['local_port', 'peer_port'] as $field) {
            if ($peer[$field] < 1 || $peer[$field] > 65535) {
                throw new \InvalidArgumentException("$field is outside 1-65535");
            }
        }
        $peer['key'] = (string)($peer['key'] ?? $peer['endpoint']);
        $peer['name'] = (string)($peer['name'] ?? $peer['endpoint']);
        return $peer;
    }

    private function matchPeer(array $section, array $peers): ?array
    {
        foreach ($peers as $rawPeer) {
            try {
                $peer = $this->normalizePeer($rawPeer);
            } catch (\Throwable $e) {
                continue;
            }
            if ($this->sectionMatchesEndpoint($section, $peer['endpoint'])) {
                return $peer;
            }
        }

        $haystack = strtolower($section['name'] . ' ' . implode(' ', $section['kv']));
        foreach ($peers as $rawPeer) {
            $parts = preg_split('/[^a-z0-9]+/i', strtolower((string)$rawPeer['name']));
            $token = (string)($parts[0] ?? '');
            if ($token !== '' && strpos($haystack, $token) !== false) {
                try {
                    return $this->normalizePeer($rawPeer);
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }
        return null;
    }

    private function sectionMatchesEndpoint(array $section, string $endpoint): bool
    {
        if (in_array($section['type'], ['inbound-publication', 'asterisk-publication'], true)
            && strcasecmp($section['name'], $endpoint) === 0) {
            return true;
        }
        if ($section['type'] !== 'outbound-publish') {
            return false;
        }
        if (strcasecmp((string)($section['kv']['outbound_auth'] ?? ''), $endpoint) === 0) {
            return true;
        }
        return stripos(implode(' ', $section['kv']), 'sip:' . $endpoint . '@') !== false;
    }

    private function repairUriLines(array $kv, array $peer): array
    {
        foreach (['server_uri', 'from_uri', 'to_uri'] as $key) {
            if (!isset($kv[$key])) {
                continue;
            }
            $local = $key === 'from_uri';
            $host = $this->sipHost($local ? $peer['local_addr'] : $peer['peer_addr']);
            $port = $local ? $peer['local_port'] : $peer['peer_port'];
            $user = $local ? $peer['endpoint'] : $peer['peer_resource'];
            $kv[$key] = (string)preg_replace(
                '/sip:[^@\s]+@(\[[^\]]+\]|[^:;\s]+)(:\d+)?/i',
                'sip:' . $user . '@' . $host . ':' . $port,
                (string)$kv[$key]
            );
        }
        return $kv;
    }

    private function sipHost(string $address): string
    {
        return strpos($address, ':') !== false && $address[0] !== '[' ? '[' . $address . ']' : $address;
    }

    /** @return array<int,array{name:string,type:string,kv:array<string,string>}> */
    private function splitSections(string $text): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === ';') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]/', $trim, $match)) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['name' => $match[1], 'type' => '', 'kv' => []];
                continue;
            }
            if ($current !== null && preg_match('/^([A-Za-z0-9_@.-]+)\s*=\s*(.*)$/', $trim, $match)) {
                $current['kv'][$match[1]] = $match[2];
                if ($match[1] === 'type') {
                    $current['type'] = $match[2];
                }
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }
        return $sections;
    }

    private function findSection(array $sections, string $name, string $type): ?array
    {
        foreach ($sections as $section) {
            if ($section['name'] === $name && $section['type'] === $type) {
                return $section;
            }
        }
        return null;
    }

    private function render(string $name, string $type, array $kv): string
    {
        $lines = ["[$name]"];
        if ($type !== '' && !isset($kv['type'])) {
            $lines[] = "type=$type";
        }
        foreach ($kv as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        return implode("\n", $lines);
    }

    private function replaceSectionInText(string $text, string $name, string $type, string $replacement): string
    {
        $pattern = '/^\[' . preg_quote($name, '/') . '\]\s*\R(?:(?!^\[).*(?:\R|\z))*/m';
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        foreach ($matches[0] as $match) {
            $parsed = $this->splitSections($match[0]);
            if (($parsed[0]['type'] ?? '') === $type) {
                return substr_replace($text, rtrim($replacement) . "\n\n", $match[1], strlen($match[0]));
            }
        }
        return $text;
    }

    private function renderDesired(array $plan, string $custom): string
    {
        foreach ($plan['repairs'] as $repair) {
            if (strpos($custom, $repair['old']) !== false) {
                $custom = str_replace($repair['old'], $repair['new'], $custom);
            } else {
                $custom = $this->replaceSectionInText($custom, $repair['name'], $repair['type'], $repair['new']);
            }
        }
        $newSections = array_merge($plan['promotions'], $plan['creations']);
        if (!$newSections) {
            return $custom;
        }
        $rendered = implode("\n\n", $newSections);
        if (strpos($custom, self::BEGIN) !== false && strpos($custom, self::END) !== false) {
            return str_replace(self::END, rtrim($rendered) . "\n\n" . self::END, $custom);
        }
        return rtrim($custom) . "\n\n" . self::BEGIN . "\n\n" . $rendered . "\n\n" . self::END . "\n";
    }

    private function readTarget(): string
    {
        return $this->readFile(self::TARGET_FILE);
    }

    private function targetMatches(string $expectedHash, bool $expectedExists): bool
    {
        if (is_link($this->path(self::TARGET_FILE))) { return false; }
        $exists = is_file($this->path(self::TARGET_FILE));
        return $exists === $expectedExists
            && hash_equals($expectedHash, hash('sha256', $this->readTarget()));
    }

    private function atomicWriteTarget(string $content, array $state): void
    {
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Approved mesh content exceeds the 4 MiB transaction limit');
        }
        $path = $this->path(self::TARGET_FILE);
        $tmp = tempnam(dirname($path), '.ucmp-mesh-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create an adjacent mesh transaction file');
        }
        try {
            $written = file_put_contents($tmp, $content, LOCK_EX);
            if ($written === false || $written !== strlen($content)) {
                throw new \RuntimeException('Could not write the complete mesh transaction file');
            }
            if (!chmod($tmp, (int)($state['mode'] ?? 0660))) {
                throw new \RuntimeException('Could not preserve mesh file permissions');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                if (($state['uid'] ?? null) !== null && fileowner($tmp) !== (int)$state['uid'] && !chown($tmp, (int)$state['uid'])) {
                    throw new \RuntimeException('Could not preserve mesh file owner');
                }
                if (($state['gid'] ?? null) !== null && filegroup($tmp) !== (int)$state['gid'] && !chgrp($tmp, (int)$state['gid'])) {
                    throw new \RuntimeException('Could not preserve mesh file group');
                }
            }
            if (is_link($path)) { throw new \RuntimeException('Refusing to replace a linked mesh target'); }
            if (!rename($tmp, $path)) {
                throw new \RuntimeException('Could not atomically replace ' . self::TARGET_FILE);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function restoreTarget(array $state): void
    {
        $path = $this->path(self::TARGET_FILE);
        if (!empty($state['file_existed'])) {
            $this->atomicWriteTarget($this->decodeStateContent($state, 'before_b64', 'before_sha256'), $state);
            return;
        }
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('Could not remove the mesh-created ' . self::TARGET_FILE);
        }
    }

    /** @return array{ok:bool,status:int,output:string,message:string} */
    private function reloadPjsip(): array
    {
        $command = "/usr/bin/timeout --signal=KILL 15 /usr/sbin/asterisk -rx 'pjsip reload'";
        if ($this->commandRunner !== null) {
            $raw = ($this->commandRunner)($command);
            $status = is_array($raw) ? (int)($raw['status'] ?? 1) : ($raw === true ? 0 : 1);
            $output = is_array($raw) ? (string)($raw['output'] ?? '') : '';
        } else {
            $lines = [];
            $status = 1;
            exec($command . ' 2>&1', $lines, $status);
            $output = implode("\n", $lines);
        }
        $badOutput = preg_match('/(?:No such command|Unable to connect|Command .* failed|Failed to)/i', $output) === 1;
        $timedOut = in_array($status, [124, 137], true);
        $ok = $status === 0 && !$badOutput;
        return [
            'ok' => $ok,
            'status' => $status,
            'output' => substr($output, 0, 2000),
            'message' => $ok
                ? 'PJSIP reload succeeded'
                : ($timedOut
                    ? 'PJSIP reload timed out after 15 seconds'
                    : 'PJSIP reload failed' . ($output !== '' ? ': ' . substr($output, 0, 300) : " (exit $status)")),
        ];
    }

    private function ensureStateDirectory(): void
    {
        ServiceFilePermissions::directory($this->stateDir);
    }

    /** One local writer; an interrupted live mutation retains its reservation. */
    private function withWriterLock(string $transactionId, string $mode, callable $operation): array
    {
        $lock = null;
        try {
            $this->assertTransactionId($transactionId);
            $lock = ServiceFilePermissions::lock($this->stateDir . '/writer.lock');
            if ($lock === false) { return ['ok'=>false,'mode'=>$mode,'transaction_id'=>$transactionId,'busy'=>true,'receipt_unknown'=>$mode==='commit','message'=>'Another mesh operation is running']; }
            $ownerPath = $this->stateDir . '/.writer-owner';
            $owner = $this->writerOwner();
            if ($owner !== null && $owner !== $transactionId) {
                return ['ok'=>false,'mode'=>$mode,'transaction_id'=>$transactionId,'recovery_required'=>true,
                    'pending_transaction_id'=>$owner,'message'=>'An earlier mesh transaction retains the writer reservation; inspect its evidence before another change'];
            }
            $result = $operation();
            $terminal = !empty($result['ok']) && in_array($result['phase'] ?? '', ['committed','rolled_back','aborted'], true);
            $terminal = $terminal || (!empty($result['rollback']['ok']) && in_array($result['rollback']['phase'] ?? '', ['rolled_back','aborted'], true));
            if ($terminal && empty($result['recovery_required'])) {
                try {
                    if ($this->writerOwner() === $transactionId && !unlink($ownerPath)) { throw new \RuntimeException('Writer reservation release failed'); }
                } catch (\Throwable) {
                    $result['operation_ok']=!empty($result['ok']);$result['ok']=false;$result['recovery_required']=true;
                    $result['reporting_error']='Known mesh stages were retained, but writer reservation release was not confirmed';
                }
            }
            return $result;
        } catch (\Throwable $error) {
            return ['ok'=>false,'mode'=>$mode,'transaction_id'=>$transactionId,'recovery_required'=>true,'message'=>$error->getMessage()];
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function writerOwner(): ?string
    {
        $path=$this->stateDir . '/.writer-owner';
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new \RuntimeException('Mesh writer reservation is not a regular file'); }
        if (!is_file($path)) { return null; }
        $text=file_get_contents($path, false, null, 0, 512);
        if ($text === false) { throw new \RuntimeException('Mesh writer reservation is unreadable'); }
        $owner=json_decode($text,true,4,JSON_THROW_ON_ERROR)['transaction_id'] ?? '';
        $this->assertTransactionId((string)$owner);
        return (string)$owner;
    }

    private function statePath(string $transactionId): string
    {
        return $this->stateDir . '/' . $transactionId . '.json';
    }

    private function writeState(array $state): void
    {
        $transactionId = (string)($state['transaction_id'] ?? '');
        $this->assertTransactionId($transactionId);
        $this->writeJson($this->statePath($transactionId), $state);
    }

    private function writeJson(string $path, array $state): void
    {
        $this->ensureStateDirectory();
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_FILE_BYTES * 3) {
            throw new \RuntimeException('Mesh transaction state exceeds the private storage limit');
        }
        $tmp = tempnam($this->stateDir, '.state-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create mesh transaction state');
        }
        try {
            $written = file_put_contents($tmp, $json, LOCK_EX);
            if ($written === false || $written !== strlen($json)) {
                throw new \RuntimeException('Could not persist complete mesh transaction state');
            }
            ServiceFilePermissions::apply($tmp, 0660);
            if (!rename($tmp, $path)) {
                throw new \RuntimeException('Could not atomically publish mesh transaction state');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function readState(string $transactionId, bool $required = true): ?array
    {
        $this->assertTransactionId($transactionId);
        $path = $this->statePath($transactionId);
        if (!is_file($path)) {
            if ($required) {
                throw new \RuntimeException('Unknown mesh transaction');
            }
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES * 3) {
            throw new \RuntimeException('Mesh transaction state is invalid or oversized');
        }
        $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state)
            || (int)($state['schema'] ?? 0) !== 1
            || !hash_equals($transactionId, (string)($state['transaction_id'] ?? ''))
        ) {
            throw new \RuntimeException('Mesh transaction state failed validation');
        }
        return $state;
    }

    private function decodeStateContent(array $state, string $field, string $hashField): string
    {
        $content = base64_decode((string)($state[$field] ?? ''), true);
        if ($content === false
            || strlen($content) > self::MAX_FILE_BYTES
            || !hash_equals((string)($state[$hashField] ?? ''), hash('sha256', $content))
        ) {
            throw new \RuntimeException('Mesh transaction snapshot failed its content hash');
        }
        return $content;
    }

    private function stateResult(array $state, bool $ok, string $mode, string $message): array
    {
        return [
            'ok' => $ok,
            'mode' => $mode,
            'transaction_id' => (string)$state['transaction_id'],
            'phase' => (string)$state['phase'],
            'plan_hash' => (string)$state['plan_hash'],
            'changed' => !empty($state['changed']),
            'counts' => (array)($state['counts'] ?? []),
            'message' => $message,
        ];
    }

    private function assertTransactionId(string $transactionId): void
    {
        if (strlen($transactionId) < 8
            || strlen($transactionId) > 96
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $transactionId) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid mesh transaction identifier');
        }
    }

    private static function newTransactionId(): string
    {
        return gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(12));
    }

    private static function hashCanonical(array $value): string
    {
        $normalize = static function ($item) use (&$normalize) {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as &$child) {
                $child = $normalize($child);
            }
            unset($child);
            return $item;
        };
        return hash('sha256', json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function callParticipant(callable $operation, array $arguments = []): array
    {
        try {
            $result = call_user_func_array($operation, $arguments);
            return is_array($result)
                ? $result
                : ['ok' => false, 'receipt_unknown'=>true, 'message' => 'Participant returned an invalid response'];
        } catch (\Throwable $e) {
            if ($e instanceof RpcOperationException && $e->receipt) { return $e->receipt; }
            return ['ok' => false, 'receipt_unknown'=>true, 'message' => $e->getMessage()];
        }
    }

    /** @param array<string,array<string,mixed>> $participants */
    private static function compensate(array $participants, array $ids, string $transactionId): array
    {
        $results = [];
        foreach ($ids as $id) {
            if (!isset($participants[$id])) {
                continue;
            }
            $results[$id] = self::callParticipant($participants[$id]['rollback'], [$transactionId]);
        }
        return $results;
    }

    private static function hasRollbackFailure(array $results): bool
    {
        foreach ($results as $result) {
            if (empty($result['ok'])) {
                return true;
            }
        }
        return false;
    }

    private function path(string $file): string
    {
        return $this->astDir . '/' . $file;
    }

    private function readFile(string $file): string
    {
        $path = $this->path($file);
        if (!is_file($path)) { return ''; }
        $content = file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);
        if ($content === false || strlen($content) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException("Could not read $file within the 4 MiB transaction limit");
        }
        return $content;
    }
}
