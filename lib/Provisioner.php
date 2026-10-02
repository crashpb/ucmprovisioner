<?php
/**
 * Provisioner — orchestrates config generation, Asterisk glue and TFTP push.
 *
 * Safety: rebuild() is DRY-RUN by default. It only writes Asterisk config and
 * pushes to TFTP when $apply === true. Generation itself has no side effects.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class Provisioner
{
    private $ucm;
    private TemplateEngine $tpl;
    private CiscoXmlGenerator $ciscoXml;
    private CiscoCnfGenerator $ciscoCnf;
    private YealinkGenerator $yealink;
    private GrandstreamGenerator $grandstream;
    private AsteriskGlue $glue;
    private ExtensionBinder $binder;
    private array $alignedSecrets = [];

    public function __construct($ucm, ?ExtensionBinder $binder = null, ?TemplateEngine $resolver = null)
    {
        $this->ucm = $ucm;
        $this->binder = $binder ?? new ExtensionBinder($ucm);
        $this->tpl = $resolver ?? new TemplateEngine($ucm);
        $this->ciscoXml = new CiscoXmlGenerator();
        $this->ciscoCnf = new CiscoCnfGenerator();
        $this->yealink = new YealinkGenerator();
        $this->grandstream = new GrandstreamGenerator();
        $this->glue = new AsteriskGlue($ucm);
    }

    /** Generate (no writes) and return the config for one phone, for UI preview. */
    public function previewPhone(int $id): array
    {
        $phone = $this->ucm->getPhone($id);
        if (!$phone) {
            return ['ok' => false, 'message' => "Unknown phone #$id"];
        }
        $resolved = $this->tpl->resolvePhone($phone);
        $this->alignedSecrets = [];
        $this->alignSecrets($resolved);
        $gen = $this->generate($resolved);
        $warnings = $this->generationWarnings($gen, $resolved);
        return [
            'ok'       => true,
            'family'   => $gen['family'],
            'filename' => $gen['filename'],
            'xml'      => $gen['content'],
            'content'  => $gen['content'],
            'warnings' => $warnings,
            'lines'    => array_map(fn($l) => $l['button'] . ':' . $l['feature'] . ($l['speedDialNumber'] ?? '' ? ('(' . $l['speedDialNumber'] . ')') : ''), $resolved['lines']),
            'template_stack' => (array)($resolved['settings']['_template_stack'] ?? []),
            'template_trace' => (array)($resolved['settings']['_template_trace'] ?? []),
            'template_group_path' => (array)($resolved['settings']['_template_group_path'] ?? []),
        ];
    }

    /**
     * Rebuild configs for a selection of phones.
     *
     * @param string $selection 'all', comma-separated phone IDs, or explicit ext:<extension> tokens
     * @param bool   $apply      when false (default) DRY-RUN: no Asterisk/TFTP writes
     */
    public function rebuild(
        string $selection,
        bool $apply = false,
        ?int $siteId = null,
        bool $restartAfter = false,
        ?string $expectedPlanHash = null,
        bool $replaceManual = false,
        bool $identityOnly = false
    ): array
    {
        if ($apply) {
            $targetSite = ($siteId !== null && $siteId > 0) ? $siteId : $this->ucm->localSiteId();
            return $this->ucm->withConfigurationSyncLock(fn() =>
                (new TftpStore($this->ucm))->withSiteLock($targetSite,
                    fn() => $this->rebuildLocked($selection, $apply, $siteId, $restartAfter, $expectedPlanHash, $replaceManual, $identityOnly)));
        }
        return $this->rebuildLocked($selection, $apply, $siteId, $restartAfter, $expectedPlanHash, $replaceManual, $identityOnly);
    }

    private function rebuildLocked(string $selection, bool $apply, ?int $siteId, bool $restartAfter, ?string $expectedPlanHash, bool $replaceManual, bool $identityOnly = false): array
    {
        $siteId = ($siteId !== null && $siteId > 0) ? $siteId : $this->ucm->localSiteId();
        $fullSiteBuild = trim($selection) === '' || trim($selection) === 'all';
        if ($replaceManual && !$this->isSinglePhoneSelection($selection)) {
            return [
                'ok'=>false,
                'message'=>'Replacing a manual config is allowed only from one phone\'s explicit Replace config action',
            ];
        }
        $allPhones = $this->buildablePhones($siteId);
        $sitePhones = array_values(array_filter($this->ucm->getPhones(), static fn(array $phone): bool => (int)$phone['site_id'] === $siteId));
        $policyNotices = $this->rebuildPolicyNotices($selection, $sitePhones, $replaceManual);
        $phones = $this->selectPhones($selection, $siteId, $replaceManual);
        if (!$phones) {
            return [
                'ok' => false,
                'message' => $policyNotices
                    ? implode(' ', $policyNotices)
                    : 'No matching provisionable phone IDs. To select by extension, use ext:<extension>.',
                'notices' => $policyNotices,
            ];
        }

        // Rebuilds consume the last completed asset inventory.  Inventory scans
        // are explicit jobs: running one here made preview and apply observe
        // different inputs and caused false "configuration changed" failures.

        // Always resolve the entire fleet for AsteriskGlue to ensure global config blocks
        // like sip_custom_post.conf are generated with all extensions, avoiding wiping
        // other phones when rebuilding a single subset.
        $selectedIds = [];
        foreach ($phones as $phone) {
            $selectedIds[(int)$phone['id']] = true;
        }
        $allResolved = [];
        $this->alignedSecrets = [];
        $resolvedById = [];
        foreach ($allPhones as $p) {
            $phoneId = (int)$p['id'];
            // Selected phones are fully and strictly resolved. Unselected fleet
            // members contribute only registrations, identities, lines and
            // hints to the global Asterisk glue. Their device-only assets and
            // companion policies are unrelated to this targeted build.
            $resolved = $this->tpl->resolvePhone($p, isset($selectedIds[$phoneId]));
            if (isset($selectedIds[$phoneId]) && !$identityOnly) { $this->alignSecrets($resolved); }
            $allResolved[] = $resolved;
            $resolvedById[$phoneId] = $resolved;
        }

        $resolvedList = [];
        foreach ($allResolved as $resolved) {
            if (isset($selectedIds[(int)$resolved['phone']['id']])) { continue; }
            foreach ($resolved['lines'] as $line) {
                if ((int)($line['featureId'] ?? 0) === 9 && isset($this->alignedSecrets[(string)($line['name'] ?? '')])) {
                    throw new \RuntimeException('Password alignment also affects phone #' . (int)$resolved['phone']['id'] . '. Select all phones sharing this extension before applying; no password or configuration was changed.');
                }
            }
        }
        // One account can appear on several selected devices with different
        // length limits. They must all publish the same newly aligned secret.
        foreach ($allResolved as &$resolved) {
            $phoneId = (int)$resolved['phone']['id'];
            if (!isset($selectedIds[$phoneId])) { continue; }
            foreach ($resolved['lines'] as &$line) {
                $account = (string)($line['name'] ?? '');
                if ((int)($line['featureId'] ?? 0) === 9 && isset($this->alignedSecrets[$account])) { $line['secret'] = $this->alignedSecrets[$account]; }
            }
            unset($line);
            $resolvedById[$phoneId] = $resolved;
        }
        unset($resolved);
        $perPhone = [];
        $generatedById = [];
        foreach ($phones as $p) {
            $resolved = $resolvedById[(int)$p['id']] ?? $this->tpl->resolvePhone($p);
            $gen = $this->generate($resolved);
            $resolvedList[] = $resolved;
            $generatedById[(int)$p['id']] = $gen;
            $endpoint = (array)($resolved['settings']['_endpoint_profile'] ?? []);
            $endpointPreviouslyApplied = (int)($p['last_applied'] ?? 0) > 0;
            $endpointReceiptKnown = trim((string)($p['applied_driver'] ?? '')) !== '';
            $perPhone[] = [
                'id'       => (int) $p['id'],
                'input_updated' => (int)($p['updated'] ?? 0),
                'ext'      => $p['extension'],
                'family'   => $gen['family'],
                'filename' => $gen['filename'],
                'bytes'    => strlen((string) $gen['content']),
                'warnings' => $this->generationWarnings($gen, $resolved),
                'driver' => (string)($endpoint['driver'] ?? $resolved['settings']['driver'] ?? 'sip'),
                'transport' => (string)($endpoint['transport'] ?? $resolved['settings']['transport'] ?? 'udp'),
                'port' => (int)($endpoint['port'] ?? $resolved['settings']['sip_port'] ?? 5060),
                'accounts' => array_values(array_unique(array_merge([(string)$p['extension']], array_map(
                    static fn(array $line): string => (string)($line['name'] ?? ''),
                    array_filter((array)($resolved['lines'] ?? []), static fn(array $line): bool => (int)($line['featureId'] ?? 0) === 9)
                )))),
                // Phones provisioned before endpoint receipts were introduced have
                // no reliable previous route. Treat that unknown transition as a
                // change so the result can advise an explicitly requested reload.
                'endpoint_changed' => $endpointPreviouslyApplied && (!$endpointReceiptKnown
                    || strtolower((string)($p['applied_driver'] ?? '')) !== strtolower((string)($endpoint['driver'] ?? ''))
                    || strtolower((string)($p['applied_transport'] ?? '')) !== strtolower((string)($endpoint['transport'] ?? ''))
                    || (int)($p['applied_sip_port'] ?? 0) !== (int)($endpoint['port'] ?? 0)
                ),
            ];
        }

        $gluePlan = $this->glue->plan($allResolved);
        $fileSet = $this->buildFileSet($resolvedList, $generatedById);
        $notices = array_values(array_unique(array_merge($policyNotices, $fileSet['notices'])));
        if ($this->alignedSecrets) {
            $notices[] = 'SIP password alignment will update ' . count($this->alignedSecrets)
                . ' extension(s) and publish the same compatible passwords in their phone configurations.';
        }
        $store = new TftpStore($this->ucm);
        $context = $this->buildContext($siteId);
        $context['previous_generation_id'] = $store->currentGeneration($siteId);
        $manifest = (new BuildPlanner())->plan(
            $siteId,
            $resolvedList,
            $fileSet['files'],
            $gluePlan,
            $context
        );

        if (!$apply) {
            return [
                'ok'       => true,
                'mode'     => 'dry-run',
                'phones'   => $perPhone,
                'glue'     => $gluePlan,
                'manifest'  => $manifest,
                'plan_hash' => $manifest['plan_hash'],
                'files'     => $manifest['files'],
                'notices'   => $notices,
                'message'   => 'DRY-RUN: nothing written. Apply requires this exact preview plan hash.',
            ];
        }

        $expectedPlanHash = strtolower(trim((string)$expectedPlanHash));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $expectedPlanHash)) {
            throw new \RuntimeException('Apply requires the plan hash returned by a fresh preview');
        }
        if (!hash_equals((string)$manifest['plan_hash'], $expectedPlanHash)) {
            throw new \RuntimeException('Configuration changed after preview; review the new plan before applying');
        }

        $ledger = new BuildTransactionService($this->ucm->getDb());
        $binder = $this->binder;
        $accountProfiles = [];
        $identityEndpointSnapshot = [];
        foreach ($perPhone as $built) {
            foreach ($built['accounts'] as $account) {
                $tuple = [$built['transport'], $built['driver'], $built['port']];
                if (isset($accountProfiles[$account])) {
                    if ($accountProfiles[$account] !== $tuple) {
                        return ['ok'=>false, 'mode'=>'preflight_failed',
                            'stages'=>['files_published'=>false, 'pbx_activated'=>null, 'device_action'=>null],
                            'message'=>'Selected phones request incompatible endpoint routes for account ' . $account];
                    }
                    continue;
                }
                $accountProfiles[$account] = $tuple;
                $preflight = $binder->preflightEndpoint($account, $built['transport'], $built['driver'], $built['port']);
                if (empty($preflight['ok'])) {
                    return ['ok'=>false, 'mode'=>'preflight_failed', 'endpoint_preflight'=>$preflight,
                        'stages'=>['files_published'=>false, 'pbx_activated'=>null, 'device_action'=>null],
                        'message'=>(string)($preflight['message'] ?? 'Endpoint preflight failed')];
                }
                if ($identityOnly) {
                    $device = $binder->getDevice($account) ?? [];
                    $expectedTransport = $built['driver'] === 'pjsip' ? (string)($preflight['listener'] ?? '') : (string)$built['transport'];
                    if (!ExtensionBinder::deviceAcceptsTransport($device, $built['driver'], $expectedTransport)) {
                        return ['ok'=>false,'mode'=>'preflight_failed','message'=>'Identity-only refresh cannot change endpoint routing for ' . $account];
                    }
                    foreach ($resolvedList as $resolved) { foreach ($resolved['lines'] as $line) {
                        if ((int)($line['featureId'] ?? 0) === 9 && (string)($line['name'] ?? '') === $account
                            && !hash_equals((string)($device['secret'] ?? ''),(string)($line['secret'] ?? ''))) { return ['ok'=>false,'mode'=>'preflight_failed','message'=>'Identity-only refresh cannot change account credentials']; }
                    } }
                    $identityEndpointSnapshot[$account] = $this->endpointFingerprint($device);
                }
            }
        }
        $transactionId = (string)$manifest['transaction_id'];
        $ledger->begin($manifest);

        $stage = $store->stageGeneration(
            $siteId,
            $transactionId,
            $fileSet['files'],
            $manifest
        );
        if (empty($stage['ok'])) {
            $ledger->transition($transactionId, 'failed', ['stage' => $stage], (string)($stage['message'] ?? 'TFTP stage failed'));
            return [
                'ok' => false,
                'mode' => 'failed',
                'transaction_id' => $transactionId,
                'phones' => $perPhone,
                'glue' => $gluePlan,
                'stage' => $stage,
                'manifest' => $manifest,
                'message' => 'No live file was changed: the complete TFTP generation could not be staged.',
            ];
        }
        $ledger->transition($transactionId, 'staged', ['stage' => $stage]);

        $glueSnapshot = $this->glue->snapshot();
        try {
            $glueResult = $this->glue->apply($allResolved);
            $ledger->transition($transactionId, 'glue_committed', ['stage' => $stage, 'glue' => $glueResult]);
        } catch (\Throwable $e) {
            $ledger->transition($transactionId, 'failed', ['stage' => $stage], $e->getMessage());
            throw $e;
        }

        if ($identityOnly) {
            foreach ($identityEndpointSnapshot as $account=>$expected) {
                if (!hash_equals($expected,$this->endpointFingerprint($binder->getDevice((string)$account) ?? []))) {
                    $this->glue->restoreSnapshot($glueSnapshot, true);
                    $ledger->transition($transactionId,'failed',[],'Endpoint changed after identity-only preflight; staged files were not published');
                    return ['ok'=>false,'mode'=>'preflight_failed','message'=>'Endpoint changed after identity-only preview; no phone files were published'];
                }
            }
        }
        $commit = $store->commitGeneration($siteId, $transactionId);
        if (empty($commit['ok'])) {
            if (!empty($commit['recovery_required'])) {
                $historyWarning='';
                try { $ledger->transition($transactionId,'failed',['commit'=>$commit,'glue'=>$glueResult,'recovery_required'=>true],(string)$commit['message']); }
                catch (\Throwable $error) { $historyWarning=$error->getMessage(); }
                return ['ok'=>false,'mode'=>'recovery_required','status'=>'unknown','recovery_required'=>true,
                    'transaction_id'=>$transactionId,'phones'=>$perPhone,'glue'=>$glueResult,'push'=>$commit,
                    'manifest'=>$manifest,'history_warning'=>$historyWarning,
                    'message'=>'File publication completion is uncertain; Asterisk state, completed files and recovery receipts were retained. Inspect the transaction before retrying.'];
            }
            $ledger->transition($transactionId, 'rolling_back', ['commit' => $commit]);
            $rollbackErrors = [];
            try {
                $this->glue->restoreSnapshot($glueSnapshot, true);
            } catch (\Throwable $e) {
                $rollbackErrors[] = 'Asterisk glue: ' . $e->getMessage();
            }
            $tftpRollback = $store->rollbackGeneration($siteId, $transactionId);
            if (empty($tftpRollback['ok'])) {
                $rollbackErrors[] = 'TFTP: ' . (string)($tftpRollback['message'] ?? 'rollback failed');
            }
            $state = $rollbackErrors ? 'failed' : 'rolled_back';
            $ledger->transition(
                $transactionId,
                $state,
                ['commit' => $commit, 'tftp_rollback' => $tftpRollback, 'rollback_errors' => $rollbackErrors],
                implode('; ', $rollbackErrors)
            );
            return [
                'ok' => false,
                'mode' => $state,
                'transaction_id' => $transactionId,
                'phones' => $perPhone,
                'glue' => $glueResult,
                'push' => $commit,
                'rollback' => ['tftp' => $tftpRollback, 'errors' => $rollbackErrors],
                'manifest' => $manifest,
                'message' => $rollbackErrors
                    ? 'Apply failed and automatic rollback requires operator attention.'
                    : 'Apply failed; the previous Asterisk and TFTP generation was restored.',
            ];
        }

        $appliedIds = array_values(array_map(static fn(array $p): int => (int)$p['id'], $perPhone));
        $db = $this->ucm->getDb();
        $metadataStarted = false;
        $metadataCommitStarted = false;
        try {
            $metadataStarted = $db->beginTransaction();
            if (!$metadataStarted) {
                throw new \RuntimeException('Could not begin build metadata transaction');
            }
            $markApplied = $db->prepare(
                "UPDATE ucmp_phones
                    SET last_applied=?,config_filename=?,config_mode='managed',
                        config_override_sha256=NULL,config_overridden_at=0
                  WHERE id=?"
            );
            $appliedAt = time();
            foreach ($perPhone as $built) {
                $markApplied->execute([
                    max($appliedAt, (int)$built['input_updated']),
                    (string)$built['filename'],
                    (int)$built['id'],
                ]);
            }
            $ledger->markPhonesApplied($manifest);
            $ledger->transition(
                $transactionId,
                'files_published',
                ['stage' => $stage, 'glue' => $glueResult, 'commit' => $commit,
                    'stages'=>['files_published'=>true, 'pbx_activated'=>null, 'device_action'=>null, 'device_observed'=>null]]
            );
            $metadataCommitStarted = true;
            if (!$db->commit()) {
                throw new \RuntimeException('Build metadata commit was not confirmed');
            }
        } catch (\Throwable $metadataError) {
            $metadataUncertain = false;
            if ($metadataStarted) {
                try {
                    if ($db->inTransaction()) {
                        $metadataUncertain = !$db->rollBack();
                    } else {
                        $metadataUncertain = $metadataCommitStarted;
                    }
                } catch (\Throwable $rollbackError) {
                    $metadataUncertain = true;
                }
            }
            if ($metadataUncertain) {
                // A lost commit/rollback receipt cannot authorize reversing the
                // paired files. Retain the generation and its writer lock until
                // the database and filesystem evidence can be reconciled.
                $stages = ['files_published'=>true, 'pbx_activated'=>null, 'device_action'=>null, 'device_observed'=>null];
                $reportingErrors = [];
                try {
                    $ledger->transition($transactionId, 'failed', [
                        'stage'=>$stage, 'glue'=>$glueResult, 'commit'=>$commit,
                        'stages'=>$stages, 'recovery_required'=>true,
                    ], 'Build metadata transaction outcome is uncertain');
                } catch (\Throwable $receiptError) {
                    $reportingErrors[] = 'Could not record metadata recovery state; the file receipt and writer lock were retained';
                }
                return [
                    'ok'=>false, 'mode'=>'recovery_required', 'status'=>'recovery_required', 'recovery_required'=>true,
                    'transaction_id'=>$transactionId, 'phones'=>$perPhone, 'glue'=>$glueResult,
                    'push'=>$commit, 'manifest'=>$manifest, 'stages'=>$stages, 'reporting_errors'=>$reportingErrors,
                    'message'=>'Verified files were published, but the database transaction outcome is uncertain. '
                        . 'The generation and writer lock were retained; reconcile database and file receipts before retrying.',
                ];
            }
            $rollbackErrors = [];
            try {
                $ledger->transition(
                    $transactionId,
                    'rolling_back',
                    ['commit' => $commit, 'metadata_error' => $metadataError->getMessage()]
                );
            } catch (\Throwable $e) {
                $rollbackErrors[] = 'transaction ledger: ' . $e->getMessage();
            }
            try {
                $this->glue->restoreSnapshot($glueSnapshot, true);
            } catch (\Throwable $e) {
                $rollbackErrors[] = 'Asterisk glue: ' . $e->getMessage();
            }
            $tftpRollback = $store->rollbackGeneration($siteId, $transactionId);
            if (empty($tftpRollback['ok'])) {
                $rollbackErrors[] = 'TFTP: ' . (string)($tftpRollback['message'] ?? 'rollback failed');
            }
            $state = $rollbackErrors ? 'failed' : 'rolled_back';
            try {
                $ledger->transition(
                    $transactionId,
                    $state,
                    [
                        'commit' => $commit,
                        'metadata_error' => $metadataError->getMessage(),
                        'tftp_rollback' => $tftpRollback,
                        'rollback_errors' => $rollbackErrors,
                    ],
                    implode('; ', array_merge([$metadataError->getMessage()], $rollbackErrors))
                );
            } catch (\Throwable $e) {
                $rollbackErrors[] = 'transaction ledger finalization: ' . $e->getMessage();
            }
            return [
                'ok' => false,
                'mode' => $rollbackErrors ? 'failed' : 'rolled_back',
                'transaction_id' => $transactionId,
                'phones' => $perPhone,
                'glue' => $glueResult,
                'push' => $commit,
                'rollback' => ['tftp' => $tftpRollback, 'errors' => $rollbackErrors],
                'manifest' => $manifest,
                'message' => $rollbackErrors
                    ? 'Live files were committed, metadata failed, and automatic rollback requires operator attention.'
                    : 'Metadata commit failed; the previous Asterisk and TFTP generation was restored.',
            ];
        }

        $companionCleanup = [
            'ok'=>true,
            'skipped'=>true,
            'retired'=>[],
            'message'=>'Cisco companion cleanup runs only after a committed Rebuild All',
        ];
        if ($fullSiteBuild) {
            $companionCleanup = $store->retireObsoleteCiscoCompanions($siteId, $transactionId);
            if (empty($companionCleanup['ok'])) {
                $notices[] = 'The build is active, but obsolete Cisco companion cleanup needs attention: '
                    . (string)($companionCleanup['message'] ?? 'unknown cleanup error');
            }
        }

        // Keep FreePBX and the generated phone file on one endpoint tuple. Core
        // writes update the database first; Asterisk still has the old live peer,
        // so any required config reload is sent through that old registration
        // before one supported FreePBX reload activates the new tuple.
        $endpointResults = [];
        $endpointReady = [];
        $endpointDbChanged = false;
        $accountResults = [];
        $binder = $this->binder;
        foreach ($perPhone as $built) {
            $endpointReady[(int)$built['id']] = true;
            foreach ($built['accounts'] as $account) {
            try {
            $result = $identityOnly ? ['ok'=>true,'changed'=>false,'status'=>'verified','message'=>'Identity-only endpoint left unchanged'] : ($accountResults[$account] ?? $binder->updateSecretTransport(
                $account,
                $this->alignedSecrets[$account] ?? null,
                (string)$built['transport'],
                ExtensionBinder::MAX_SECRET_LEN,
                'selected phone',
                (string)$built['driver'],
                (int)$built['port']
            ));
            } catch (\Throwable $error) { $result = ['ok'=>false, 'message'=>$error->getMessage()]; }
            $accountResults[$account] = $result;
            $result['id'] = (int)$built['id'];
            $result['ext'] = $account;
            $endpointResults[] = $result;
            if (!empty($result['ok'])) {
                $endpointDbChanged = $endpointDbChanged || !empty($result['changed']);
            } else {
                $endpointReady[(int)$built['id']] = false;
                $notices[] = 'Endpoint synchronization failed for ' . (string)$built['ext'] . ': '
                    . (string)($result['message'] ?? 'unknown error');
            }
            }
        }

        $restarts = [];
        $phoneCommand = new PhoneCommand($this->ucm);
        foreach ($perPhone as $built) {
            if ($identityOnly) { continue; }
            if (!$restartAfter || empty($endpointReady[(int)$built['id']])) {
                if (!$restartAfter && !empty($built['endpoint_changed'])) {
                    $notices[] = 'Phone ' . (string)$built['ext']
                        . ' has new endpoint settings. Restart was not selected; reload its configuration when ready.';
                }
                continue;
            }
            if (in_array((string)$built['family'], ['cisco_xml','cisco_cnf','yealink'], true)) {
                $result = $phoneCommand->send((int)$built['id'], 'restart');
                $result['id'] = (int)$built['id'];
                $result['ext'] = (string)$built['ext'];
                $restarts[] = $result;
                if (empty($result['ok'])) {
                    $notices[] = 'Files and endpoint settings were saved, but the config-reload request for phone ' . (string)$built['ext']
                        . ' failed: ' . (string)($result['message'] ?? 'unknown error');
                }
            } elseif (!empty($built['endpoint_changed'])) {
                $notices[] = 'Phone ' . (string)$built['ext']
                    . ' changed endpoint routing but this family has no safe SIP config-reload command; reboot it manually.';
                $restarts[] = ['ok'=>false, 'id'=>(int)$built['id'], 'ext'=>(string)$built['ext'], 'status'=>'action_required',
                    'message'=>'This model requires an operator-coordinated reload'];
            }
        }

        $reload = ['ok'=>true, 'skipped'=>true, 'message'=>'No FreePBX endpoint reload was required'];
        // AsteriskGlue already performs its narrow dialplan/chan_sip reload.
        // Its needreload() marker must not turn every phone build into a full
        // FreePBX reload; only a Core endpoint mutation requires that work.
        $reloadNeeded = $endpointDbChanged;
        if ($reloadNeeded) {
            try {
                $frameworkResult = \FreePBX::Framework()->doReload();
                $reload = [
                    'ok'=>!empty($frameworkResult['status']),
                    'skipped'=>false,
                    'message'=>(string)($frameworkResult['message'] ?? 'FreePBX reload returned no message'),
                    'detail'=>$frameworkResult,
                ];
            } catch (\Throwable $e) {
                $reload = ['ok'=>false, 'skipped'=>false, 'message'=>$e->getMessage()];
            }
            if (empty($reload['ok'])) {
                $notices[] = 'Generated configs are published, but FreePBX could not activate the endpoint changes: '
                    . (string)$reload['message'];
            }
        }

        $reportingErrors = [];
        try {
        if (!empty($reload['ok']) && $endpointReady) {
            $markEndpoint = $db->prepare(
                'UPDATE ucmp_phones SET applied_driver=?,applied_transport=?,applied_sip_port=? WHERE id=?'
            );
            foreach ($perPhone as $built) {
                if (empty($endpointReady[(int)$built['id']])) { continue; }
                $markEndpoint->execute([
                    (string)$built['driver'],
                    (string)$built['transport'],
                    (int)$built['port'],
                    (int)$built['id'],
                ]);
            }
        }
        } catch (\Throwable $error) {
            $reportingErrors[] = 'Applied endpoint tracking: ' . $error->getMessage();
        }

        $pbxOk = !in_array(false, $endpointReady, true) && !empty($reload['ok']);
        $deviceOk = !array_filter($restarts, static fn(array $row): bool => empty($row['ok']));
        $stages = ['files_published'=>true, 'pbx_activated'=>$pbxOk, 'device_action'=>$restarts ? $deviceOk : null,
            'device_observed'=>null, 'recorded_at'=>time()];
        try {
        $ledger->transition($transactionId, $pbxOk && $deviceOk ? 'committed' : 'failed',
            ['stage'=>$stage, 'glue'=>$glueResult, 'commit'=>$commit, 'stages'=>$stages, 'endpoint_sync'=>$endpointResults, 'reload'=>$reload, 'restarts'=>$restarts]);
        } catch (\Throwable $error) {
            $reportingErrors[] = 'Final build receipt: ' . $error->getMessage();
        }
        foreach ($reportingErrors as $error) { $notices[] = 'The completed stages below are known, but their database record could not be saved. ' . $error; }
        return [
            'ok'      => $pbxOk && $deviceOk,
            'mode'    => $pbxOk && $deviceOk ? 'apply' : 'partial',
            'stages'  => $stages,
            'transaction_id' => $transactionId,
            'phones'  => $perPhone,
            'applied' => count($appliedIds),
            'glue'    => $glueResult,
            'push'    => $commit,
            'manifest'=> $manifest,
            'companion_cleanup'=>$companionCleanup,
            'endpoint_sync'=>['results'=>$endpointResults, 'reload'=>$reload],
            'restarts'=> $restarts,
            'notices' => $notices,
            'reporting_errors' => $reportingErrors,
            'message' => sprintf(
                'Published verified files for %d phone(s)%s. Phone receipt and application of the configuration remain unverified.',
                count($appliedIds),
                $restarts ? sprintf(' and requested %d phone config reload(s)', count($restarts)) : ''
            ),
        ];
    }

    /**
     * Build the exact TFTP file set once. Preview hashes these bytes and apply
     * stages the same bytes; there is no second UI-only approximation.
     *
     * @param array<int,array<string,mixed>> $resolvedList
     * @param array<int,array<string,mixed>> $generatedById
     * @return array{files:array<int,array<string,mixed>>,notices:array<int,string>}
     */
    private function alignSecrets(array &$resolved): void
    {
        $family = (string)($resolved['model']['family'] ?? '');
        foreach ($resolved['lines'] as &$line) {
            if ((int)($line['featureId'] ?? 0) !== 9) { continue; }
            $account = (string)($line['name'] ?? '');
            $secret = (string)($line['secret'] ?? '');
            if (SipSecretPolicy::needsAlignment($secret,$family)) {
                $line['secret'] = $this->alignedSecrets[$account]
                    ??= $this->ucm->alignedSipSecret($account,$secret);
            }
        }
        unset($line);
    }

    private function endpointFingerprint(array $device): string
    {
        return hash('sha256',json_encode([(string)($device['tech'] ?? ''),(string)($device['transport'] ?? ''),(string)($device['secret'] ?? '')],JSON_THROW_ON_ERROR));
    }

    private function buildFileSet(array $resolvedList, array $generatedById): array
    {
        $byName = [];
        $notices = [];
        $legacySites = [];
        $softkeyGenerator = null;
        $add = static function (
            array &$target,
            string $filename,
            string $content,
            string $role,
            ?int $phoneId = null,
            string $extension = ''
        ): void {
            BuildPlanner::assertFilename($filename);
            if (isset($target[$filename])) {
                if (!hash_equals(hash('sha256', (string)$target[$filename]['content']), hash('sha256', $content))) {
                    throw new \RuntimeException("Two build layers generated different content for $filename");
                }
                return;
            }
            $target[$filename] = [
                'filename' => $filename,
                'content' => $content,
                'role' => $role,
                'phone_id' => $phoneId,
                'extension' => $extension,
            ];
        };

        foreach ($resolvedList as $resolved) {
            $phone = (array)($resolved['phone'] ?? []);
            $phoneId = (int)($phone['id'] ?? 0);
            $extension = (string)($phone['extension'] ?? '');
            $family = $this->familyOf($resolved);
            $generated = $generatedById[$phoneId] ?? $this->generate($resolved);
            $filename = (string)($generated['filename'] ?? '');
            if ($filename === '') {
                throw new \RuntimeException("Phone #$phoneId has no generated filename");
            }
            $add($byName, $filename, (string)$generated['content'], 'phone_config', $phoneId, $extension);

            $settings = (array)($resolved['settings'] ?? []);
            if (!empty($settings['_softkey_layout'])) {
                $name = (string)($settings['softkey_file'] ?? '');
                $softkeyGenerator = $softkeyGenerator ?: new SoftkeyPolicyGenerator();
                $add(
                    $byName,
                    $name,
                    $softkeyGenerator->softkeysXml((array)$settings['_softkey_layout']),
                    'softkey_policy'
                );
            }
            if (!empty($settings['_feature_policy_layout'])) {
                $name = (string)($settings['featurepolicy_file'] ?? '');
                $softkeyGenerator = $softkeyGenerator ?: new SoftkeyPolicyGenerator();
                $add(
                    $byName,
                    $name,
                    $softkeyGenerator->featurePolicyXml((array)$settings['_feature_policy_layout']),
                    'feature_policy'
                );
            }
            if (!empty($settings['_template_id']) && $family === 'cisco_xml'
                && (isset($settings['digit_timeout'])
                    || isset($settings['dial_pattern'])
                    || !empty($settings['dial_rules']))) {
                $add(
                    $byName,
                    DialTemplateGenerator::filenameFor($settings),
                    DialTemplateGenerator::fromSettings($settings),
                    'dial_plan'
                );
            }
            if ($family === 'cisco_cnf') {
                $resolvedSite = (int)($phone['site_id'] ?? 0) ?: $this->ucm->localSiteId();
                $legacySites[$resolvedSite][] = $settings;
            }
        }

        foreach (array_keys($legacySites) as $legacySiteId) {
            $settings = $this->tpl->effectiveSettings((int)$legacySiteId);
            $sharedEndpoint = EndpointProfile::resolve([], ['vendor'=>'Cisco', 'family'=>'cisco_cnf'], $settings);
            $settings['sip_port'] = $sharedEndpoint['port'];
            $site = $this->ucm->getSite((int)$legacySiteId) ?: [];
            if (!empty($site['pbx_addr'])) {
                $settings['pbx_addr'] = $site['pbx_addr'];
            }
            $stablePhones = array_map(static function (array $resolved): array {
                return [
                    'id' => (int)($resolved['phone']['id'] ?? 0),
                    'extension' => (string)($resolved['phone']['extension'] ?? ''),
                    'revision' => (string)($resolved['phone']['revision'] ?? ''),
                ];
            }, $resolvedList);
            $token = substr(hash('sha256', json_encode(
                [$legacySiteId, $settings, $stablePhones],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )), 0, 16);
            $settings['legacy_sync_token'] = $token;
            $warnings = [];
            $sipDefault = (new CiscoCnfGenerator())->buildSipDefault($settings, $warnings);
            $sharedPolicy = (new CiscoCnfGenerator())->buildSipDefault($settings, $warnings, false);
            foreach ($legacySites[$legacySiteId] as $phoneSettings) {
                $phoneSettings['legacy_sync_token'] = $token;
                $phoneWarnings = [];
                if ((new CiscoCnfGenerator())->buildSipDefault($phoneSettings, $phoneWarnings, false) !== $sharedPolicy) {
                    throw new \RuntimeException('Cisco CNF shared policy differs from the canonical site policy; remove incompatible phone/template overrides before publishing SIPDefault.cnf');
                }
            }
            foreach ($warnings as $warning) {
                $notices[] = "SIPDefault.cnf (site $legacySiteId): $warning";
            }
            $add($byName, 'SIPDefault.cnf', $sipDefault, 'legacy_site_default');
            $add($byName, 'syncinfo.xml', CiscoCnfGenerator::syncInfoXml($token), 'legacy_sync');
        }

        ksort($byName, SORT_NATURAL | SORT_FLAG_CASE);
        return ['files' => array_values($byName), 'notices' => array_values(array_unique($notices))];
    }

    /** @return array<string,mixed> */
    private function buildContext(int $siteId): array
    {
        $directoryRevisions = [];
        try {
            $stmt = $this->ucm->getDb()->query(
                'SELECT source_key,last_accepted_revision FROM ucmp_directory_sources WHERE enabled=1 ORDER BY source_key'
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = trim((string)($row['source_key'] ?? ''));
                if ($key !== '') {
                    $directoryRevisions[$key] = (string)($row['last_accepted_revision'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            // Older remote modules may not have directory-source tables yet.
        }
        return [
            'catalog_revision' => (string)ModelCatalog::CATALOG_VERSION,
            'directory_revisions' => $directoryRevisions,
            'site_id' => $siteId,
        ];
    }

    /**
     * Surface model-policy omissions in the same preview/apply channel as
     * generator warnings. A policy may deliberately omit an unverified vendor
     * field without making an otherwise valid build fail.
     */
    private function generationWarnings(array $generated, array $resolved): array
    {
        $warnings = array_values(array_filter(array_map(
            static fn($warning): string => trim((string)$warning),
            (array)($generated['warnings'] ?? [])
        )));
        $vlan = $resolved['settings']['_vlan_policy'] ?? null;
        if (is_array($vlan) && str_contains((string)($vlan['status'] ?? ''), 'warning')) {
            $message = trim((string)($vlan['message'] ?? ''));
            if ($message !== '') { $warnings[] = $message; }
        }
        return array_values(array_unique($warnings));
    }

    /** Push generated files to each site's TFTP volume over SFTP (apply path). */
    private function push(array $resolvedList): array
    {
        if (!class_exists('\\FreePBX\\modules\\Ucmprovisioner\\TftpStore')) {
            return ['ok' => false, 'message' => 'TftpStore engine not available'];
        }
        $store = new TftpStore($this->ucm);
        $results = [];
        $legacySites = [];
        $skGen = null;
        $pushedCompanions = []; // "siteId:filename" => true, avoid re-pushing the same template file per phone
        foreach ($resolvedList as $r) {
            $family = $this->familyOf($r);
            $siteId = (int) ($r['phone']['site_id'] ?: $this->ucm->localSiteId());
            $gen = $this->generate($r);
            if (!$gen['filename']) {
                $results[] = ['ext' => $r['phone']['extension'], 'ok' => false, 'message' => 'no MAC/filename'];
                continue;
            }
            $put = $store->putConfig($siteId, $gen['filename'], (string) $gen['content']);
            // TftpStore reports a file result, not its logical owner. Attach the extension here so
            // a failed SFTP write is attributed correctly and can never be counted as applied.
            $put['ext'] = (string) ($r['phone']['extension'] ?? '');
            if (empty($put['ok']) && stripos((string) ($put['message'] ?? ''), 'permission denied') !== false) {
                $put['message'] .= ' — this file has different permissions than the rest of the TFTP directory (likely leftover from before this module managed it); a site admin needs to chmod it once on the docker host.';
            }
            $results[] = $put;
            if ($family === 'cisco_cnf') {
                $legacySites[$siteId] = true;
            }
            // Per-template softkeys.xml / featurepolicy.xml companion files (pushed once per site+file).
            if (!empty($r['settings']['_softkey_layout'])) {
                $fn = $r['settings']['softkey_file'];
                $key = "$siteId:$fn";
                if (empty($pushedCompanions[$key])) {
                    $skGen = $skGen ?: new SoftkeyPolicyGenerator();
                    $results[] = $store->putConfig($siteId, $fn, $skGen->softkeysXml($r['settings']['_softkey_layout']));
                    $pushedCompanions[$key] = true;
                }
            }
            if (!empty($r['settings']['_feature_policy_layout'])) {
                $fn = $r['settings']['featurepolicy_file'];
                $key = "$siteId:$fn";
                if (empty($pushedCompanions[$key])) {
                    $skGen = $skGen ?: new SoftkeyPolicyGenerator();
                    $results[] = $store->putConfig($siteId, $fn, $skGen->featurePolicyXml($r['settings']['_feature_policy_layout']));
                    $pushedCompanions[$key] = true;
                }
            }

            // Custom dialplan XML for templates that define digit timeout or dial patterns
            if (!empty($r['settings']['_template_id']) && $family === 'cisco_xml') {
                if (isset($r['settings']['digit_timeout']) || isset($r['settings']['dial_pattern']) || !empty($r['settings']['dial_rules'])) {
                    $fn = DialTemplateGenerator::filenameFor((array)$r['settings']);
                    $key = "$siteId:$fn";
                    if (empty($pushedCompanions[$key])) {
                        $dpXml = DialTemplateGenerator::fromSettings((array)$r['settings']);
                        $results[] = $store->putConfig($siteId, $fn, $dpXml);
                        $pushedCompanions[$key] = true;
                    }
                }
            }
        }
        // Push SIPDefault.cnf once per site that had legacy Cisco phones rebuilt.
        foreach (array_keys($legacySites) as $siteId) {
            $cnfGen = new CiscoCnfGenerator();
            $cnfWarnings = [];
            // SIPDefault.cnf is one site-global file. Build it only from the site's
            // settings; template-specific firmware/media/directory choices live in
            // SIP<MAC>.cnf and can no longer be chosen by whichever phone ran last.
            $settings = $this->tpl->effectiveSettings((int)$siteId);
            $site = $this->ucm->getSite((int)$siteId) ?: [];
            if (!empty($site['pbx_addr'])) { $settings['pbx_addr'] = $site['pbx_addr']; }
            $token = (string)time();
            $settings['legacy_sync_token'] = $token;
            $sipDefault = $cnfGen->buildSipDefault($settings, $cnfWarnings);
            foreach ($cnfWarnings as $w) {
                $results[] = ['ok' => true, 'message' => "SIPDefault.cnf (site $siteId): $w"];
            }
            $results[] = $store->putLegacyCiscoGlobals($siteId, $sipDefault, $token);
        }
        return $results;
    }

    /** Select the right generator by family and produce the config. */
    private function generate(array $resolved): array
    {
        $family = $this->familyOf($resolved);
        switch ($family) {
            case 'cisco_xml':
                $g = $this->ciscoXml->generate($resolved);
                return ['family' => $family, 'filename' => $g['filename'], 'content' => $g['xml'], 'warnings' => $g['warnings']];
            case 'cisco_cnf':
                $g = $this->ciscoCnf->generate($resolved);
                return ['family' => $family, 'filename' => $g['filename'], 'content' => $g['content'], 'warnings' => $g['warnings']];
            case 'yealink':
                $g = $this->yealink->generate($resolved);
                return ['family' => $family, 'filename' => $g['filename'], 'content' => $g['content'], 'warnings' => $g['warnings']];
            case 'grandstream':
                $g = $this->grandstream->generate($resolved);
                return ['family' => $family, 'filename' => $g['filename'], 'content' => $g['content'], 'warnings' => $g['warnings']];
            default:
                return [
                    'family'   => $family,
                    'filename' => null,
                    'content'  => '',
                    'warnings' => ["No configuration generator is registered for family '$family'"],
                ];
        }
    }

    private function familyOf(array $resolved): string
    {
        if (!empty($resolved['model']['family'])) {
            return (string) $resolved['model']['family'];
        }
        return '';
    }

    /**
     * @return array<int,array> Provisionable physical phones, including manual
     * overrides and bulk exclusions because Asterisk glue remains fleet-wide.
     */
    private function buildablePhones(?int $siteId = null): array
    {
        $all = $this->ucm->getPhones();
        $buildable = array_values(array_filter($all, static fn($p) => !self::missingBuildFields($p)));
        if ($siteId !== null && $siteId > 0) {
            $buildable = array_values(array_filter($buildable, fn($p) => (int)$p['site_id'] === $siteId));
        }
        return $buildable;
    }

    /** Shared eligibility for the inventory and generator. Never infer a model from a MAC. */
    public static function missingBuildFields(array $phone): array
    {
        $missing = [];
        foreach (['extension'=>'extension','mac'=>'MAC address','model_id'=>'phone model'] as $field=>$label) {
            if (empty($phone[$field])) { $missing[]=$label; }
        }
        return $missing;
    }

    /**
     * Rebuild All leaves both bulk exclusions and manual configs untouched. A
     * manual file can rejoin managed generation only through one phone's
     * explicit replacement action.
     *
     * @return array<int,array>
     */
    private function selectPhones(
        string $selection,
        ?int $siteId = null,
        bool $replaceManual = false
    ): array {
        $buildable = $this->buildablePhones($siteId);
        if ($selection === '' || $selection === 'all') {
            return array_values(array_filter($buildable, static fn(array $phone): bool =>
                empty($phone['exclude_bulk_rebuild'])
                && (string)($phone['config_mode'] ?? 'managed') !== 'manual'
            ));
        }
        $tokens = array_values(array_filter(array_map('trim', explode(',', $selection)), static fn(string $v): bool => $v !== ''));
        $selected = array_values(array_filter($buildable, static function (array $phone) use ($tokens): bool {
            return in_array((string)(int)$phone['id'], $tokens, true)
                || in_array('ext:' . (string)$phone['extension'], $tokens, true);
        }));
        if ($replaceManual) {
            return count($selected) === 1 ? $selected : [];
        }
        return array_values(array_filter($selected, static fn(array $phone): bool =>
            (string)($phone['config_mode'] ?? 'managed') !== 'manual'
        ));
    }

    private function isSinglePhoneSelection(string $selection): bool
    {
        if ($selection === '' || $selection === 'all') {
            return false;
        }
        $tokens = array_values(array_filter(
            array_map('trim', explode(',', $selection)),
            static fn(string $value): bool => $value !== ''
        ));
        return count($tokens) === 1;
    }

    /**
     * @param array<int,array<string,mixed>> $allPhones
     * @return array<int,string>
     */
    private function rebuildPolicyNotices(
        string $selection,
        array $allPhones,
        bool $replaceManual
    ): array {
        $isAll = $selection === '' || $selection === 'all';
        $tokens = $isAll ? [] : array_values(array_filter(
            array_map('trim', explode(',', $selection)),
            static fn(string $value): bool => $value !== ''
        ));
        $notices = [];
        foreach ($allPhones as $phone) {
            if (!$isAll
                && !in_array((string)(int)$phone['id'], $tokens, true)
                && !in_array('ext:' . (string)$phone['extension'], $tokens, true)) {
                continue;
            }
            $manual = (string)($phone['config_mode'] ?? 'managed') === 'manual';
            $missing = self::missingBuildFields($phone);
            if ($missing) {
                $notices[] = 'Phone #' . (int)$phone['id'] . (!empty($phone['extension']) ? ' (extension ' . $phone['extension'] . ')' : '')
                    . ' cannot be rebuilt: assign ' . implode(', ', $missing) . ' in Edit first.';
                continue;
            }
            $excluded = $isAll && !empty($phone['exclude_bulk_rebuild']);
            if ((!$manual || $replaceManual) && !$excluded) {
                continue;
            }
            $identity = (string)($phone['extension'] ?? '') !== ''
                ? 'extension ' . (string)$phone['extension']
                : 'phone #' . (int)$phone['id'];
            $reason = $manual
                ? 'has an explicit manual TFTP config'
                : 'is excluded from Rebuild All';
            $notices[] = ucfirst($identity) . " $reason, so its phone config was left untouched. "
                . 'Site-wide Asterisk/BLF glue was still recomputed; its phone-side BLF subscriptions may now be stale.';
        }
        return array_values(array_unique($notices));
    }
}
