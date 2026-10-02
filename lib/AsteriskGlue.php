<?php
/**
 * AsteriskGlue — manages the sanctioned FreePBX *_custom / *_override files for
 * UCM presence/BLF. Writes ONLY inside a clearly-delimited managed block so it
 * never disturbs hand-maintained config, and skips extensions that already have
 * conflicting config outside the managed region.
 *
 * Files (local site = this PBX; remote sites handled later via SiteConnection):
 *  - sip_custom_post.conf              : [<ext>](+) cisco_usecallmanager / progressinband / subscribe
 *  - extensions_override_freepbx.conf  : [ext-local] independent device/presence hint
 *    exten => <ext>,hint,SIP/<ext>&Custom:DND<ext>,SIP/<ext>
 *  - extensions_custom.conf            : one-time [from-internal-custom] x-cisco-serviceuri handlers
 *  - sip_notify_custom.conf            : one-time [cisco-restart] / [cisco-reset]
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class AsteriskGlue
{
    private const BEGIN = '; ===== ucmprovisioner managed (do not edit by hand) BEGIN =====';
    private const END   = '; ===== ucmprovisioner managed (do not edit by hand) END =====';
    private const CS_BEGIN = '; ===== ucmprovisioner cross-site hints managed BEGIN =====';
    private const CS_END   = '; ===== ucmprovisioner cross-site hints managed END =====';
    private const ASTDIR = '/etc/asterisk';
    private const MANAGED_FILES = [
        'sip_custom_post.conf',
        'extensions_override_freepbx.conf',
        'extensions_custom.conf',
        'sip_notify_custom.conf',
    ];

    private $ucm;

    public function __construct($ucm)
    {
        $this->ucm = $ucm;
    }

    /**
     * Compute the proposed glue for a set of resolved phones (no writes).
     *
     * @param array $resolvedList list of TemplateEngine::resolvePhone() results
     */
    public function plan(array $resolvedList): array
    {
        $sipBlocks = [];
        $subscriptions = [];
        $hintLines = [];
        $crossSite = [];
        $conflicts = [];
        $migrations = [];
        $migrationObjects = ['sip'=>[], 'hints'=>[]];
        $conflictObjects = ['sip'=>[], 'hints'=>[]];

        $existingSip   = $this->readFile('sip_custom_post.conf');
        $existingHints = $this->readFile('extensions_override_freepbx.conf');

        foreach ($resolvedList as $r) {
            $ext = (string) ($r['phone']['extension'] ?? '');
            if ($ext === '') {
                continue;
            }
            $family = $this->familyOf($r);
            if (!in_array($family, ['cisco_xml', 'cisco_cnf'], true)) {
                continue; // Non-Cisco SIP/PJSIP endpoints never own UCM chan_sip glue.
            }
            // Classify matching directives outside the managed block before deciding whether to
            // migrate them. Known UCM directives can be adopted safely; unfamiliar directives
            // remain an explicit hands-off conflict so administrator-owned behavior is preserved.
            $sipClass = $this->classifyExistingSipBlock($existingSip, $ext);
            if ($sipClass === 'conflict') {
                $conflictObjects['sip'][] = "$ext: conflict";
                $conflicts[] = "$ext: existing [$ext](+) in sip_custom_post.conf has directives this module doesn't recognize (outside managed block)";
            } elseif ($sipClass === 'safe') {
                $migrationObjects['sip'][] = $ext;
                $migrations[] = "$ext: existing [$ext](+) in sip_custom_post.conf matches exactly what this module generates — will be consolidated into the managed block (same effective config, now actually managed)";
            }
            $hintClass = $this->classifyExistingHint($existingHints, $ext);
            if ($hintClass === 'conflict') {
                $conflictObjects['hints'][] = "$ext: conflict";
                $conflicts[] = "$ext: existing hint for $ext in extensions_override_freepbx.conf doesn't match this module's format (outside managed block)";
            } elseif ($hintClass === 'safe') {
                $migrationObjects['hints'][] = $ext;
                $migrations[] = "$ext: existing hint for $ext in extensions_override_freepbx.conf matches exactly — will be consolidated into the managed block";
            }

            $subs = $this->subscribesFor($r, $crossSite, $ext);
            $sipBlocks[$ext] = $this->buildExtBlock($ext, $subs, $family, $this->secondaryPeersFor($r));
            $subscriptions[$ext] = $subs;
            $hintLines[$ext] = $this->hintLine($ext);
            foreach ($this->localTargetsFor($r) as $target) {
                $targetHintClass = $this->classifyExistingHint($existingHints, $target);
                if ($targetHintClass === 'conflict') {
                    $conflictObjects['hints'][] = "$target: conflict";
                    $conflicts[] = "$target: existing watched-target hint does not match the USECALLMANAGER two-provider format";
                    continue;
                } elseif ($targetHintClass === 'safe') {
                    $migrationObjects['hints'][] = $target;
                    $migrations[] = "$target: watched-target hint matches and will be consolidated into the managed block";
                }
                $hintLines[$target] = $this->hintLine($target);
            }
        }

        return [
            'sip_custom_post_block'        => $this->wrap($this->renderSip($sipBlocks)),
            'extensions_override_block'    => $this->wrap("[ext-local]\n" . implode("\n", $hintLines)),
            'cross_site_hints_block'       => $this->wrapCrossSite($this->planCrossSiteHints($resolvedList)),
            'requires_patch'               => (bool) $sipBlocks,
            'managed_extensions'           => array_keys($sipBlocks),
            'subscribes'                   => $subscriptions,
            'cross_site_targets'           => $crossSite,
            'conflicts'                    => $conflicts,
            'migrations'                   => $migrations,
            'migration_objects'            => $migrationObjects,
            'conflict_objects'             => $conflictObjects,
            'onetime'                      => $this->oneTimeStatus(),
            'preflight'                    => $this->patchPreflight(),
        ];
    }

    /**
     * Probe the runtime capabilities required before Cisco glue can be applied.
     *
     * This is public so Patch Manager and every write caller can consume the
     * same verdict instead of duplicating looser UI-only checks.
     */
    public function patchPreflight(): array
    {
        $version = $this->asteriskCli('core show version');
        $pageHelp = $this->asteriskCli('core show application SIPCiscoPage');
        $sipSettings = $this->asteriskCli('sip show settings');
        $versionDetected = (bool)preg_match('/\bAsterisk\b/i', $version);
        $chanSipLoaded = stripos($sipSettings, 'Global Settings:') !== false
            && !preg_match('/not registered|not found|no such|unknown command|unable to connect|connection refused/i', $sipSettings);
        $patchDetected = stripos($pageHelp, 'SIPCiscoPage') !== false
            && !preg_match('/not registered|not found|no such|unknown command|unable to connect|connection refused/i', $pageHelp);
        $eligible = $versionDetected && $chanSipLoaded && $patchDetected;
        $problems = [];
        if (!$versionDetected) {
            $problems[] = 'the Asterisk runtime/version could not be verified';
        }
        if (!$chanSipLoaded) {
            $problems[] = 'chan_sip.so is not loaded';
        }
        if (!$patchDetected) {
            $problems[] = 'USECALLMANAGER SIPCiscoPage support was not detected';
        }
        return [
            'asterisk_version' => $version,
            'asterisk_detected' => $versionDetected,
            'chan_sip_loaded' => $chanSipLoaded,
            'usecallmanager_detected' => $patchDetected,
            'eligible' => $eligible,
            'warning' => $eligible
                ? ''
                : 'Cisco Asterisk glue cannot be applied because ' . implode('; ', $problems) . '.',
        ];
    }

    private function asteriskCli(string $command): string
    {
        return trim((string)@shell_exec(
            '/usr/bin/timeout --signal=KILL 5 /usr/sbin/asterisk -rx '
            . escapeshellarg($command)
            . ' 2>/dev/null'
        ));
    }

    /**
     * Capture exact pre-transaction bytes for every file this class may touch.
     *
     * @return array<string,array{exists:bool,content:string,sha256:string}>
     */
    public function snapshot(): array
    {
        $snapshot = [];
        foreach (self::MANAGED_FILES as $file) {
            $path = $this->path($file);
            $exists = is_file($path);
            $content = $this->readFile($file);
            $snapshot[$file] = [
                'exists' => $exists,
                'content' => $content,
                'sha256' => hash('sha256', $content),
            ];
        }
        return $snapshot;
    }

    /**
     * Restore a snapshot after a later TFTP commit failure.
     *
     * @param array<string,array<string,mixed>> $snapshot
     */
    public function restoreSnapshot(array $snapshot, bool $reload = true): array
    {
        foreach (self::MANAGED_FILES as $file) {
            if (!isset($snapshot[$file]) || !is_array($snapshot[$file])) {
                throw new \InvalidArgumentException("Asterisk rollback snapshot is missing $file");
            }
            $row = $snapshot[$file];
            $content = (string)($row['content'] ?? '');
            if (!hash_equals((string)($row['sha256'] ?? ''), hash('sha256', $content))) {
                throw new \RuntimeException("Asterisk rollback snapshot hash failed for $file");
            }
            $path = $this->path($file);
            if (!empty($row['exists'])) {
                $this->atomicWrite($path, $content);
            } elseif (is_file($path) && !unlink($path)) {
                throw new \RuntimeException("Could not remove newly-created Asterisk file $file during rollback");
            }
        }
        if ($reload) {
            $this->reloadManagedAreas();
        }
        return ['ok' => true, 'restored' => count(self::MANAGED_FILES)];
    }

    /**
     * Classify an unmanaged extension block as absent, safe to adopt because
     * every directive is module-owned, or conflicting because it contains
     * operator/third-party directives that must remain untouched.
     */
    private function classifyExistingSipBlock(string $content, string $ext): string
    {
        $outside = $this->stripManaged($content);
        if (!preg_match_all('/^\[' . preg_quote($ext, '/') . '\]\(\+\)\r?\n((?:[^\r\n\[][^\r\n]*\r?\n?)*)/m', $outside, $matches)) {
            return 'none';
        }
        foreach (preg_split('/\r?\n/', trim(implode("\n", $matches[1]))) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (in_array($line, [
                'cisco_usecallmanager=yes', 'progressinband=yes', 'callcounter=yes',
                'allowsubscribe=yes', 'dndbusy=yes', 'sendrpid=rpid', 'rpid_update=yes',
                'rpid_immediate=yes', 'send_diversion=yes'
            ], true)) {
                continue;
            }
            if (preg_match('/^(subscribe|register)=/', $line)) {
                continue; // The managed block always regenerates the complete subscribe/register list.
            }
            return 'conflict';
        }
        return 'safe';
    }

    private function classifyExistingHint(string $content, string $ext): string
    {
        $outside = $this->stripManaged($content);
        if (!preg_match_all('/^exten => ' . preg_quote($ext, '/') . ',hint,[^\r\n]*/m', $outside, $matches)) {
            return 'none';
        }
        $oldFormat = "exten => $ext,hint,SIP/$ext,SIP/$ext";
        $newFormat = "exten => $ext,hint,SIP/$ext&Custom:DND$ext,SIP/$ext";
        foreach ($matches[0] as $line) {
            if (!in_array(trim($line), [$oldFormat, $newFormat], true)) { return 'conflict'; }
        }
        return 'safe';
    }

    /** Remove a specific [ext](+) block from the non-managed portion of sip_custom_post.conf-style content. */
    private function removeExtBlock(string $content, string $ext): string
    {
        return (string) preg_replace('/^\[' . preg_quote($ext, '/') . '\]\(\+\)\r?\n(?:[^\r\n\[][^\r\n]*\r?\n?)*\r?\n?/m', '', $content);
    }

    /** Remove a specific extension's hint line from the non-managed portion of extensions_override_freepbx.conf-style content. */
    private function removeHintLine(string $content, string $ext): string
    {
        return (string) preg_replace('/^exten => ' . preg_quote($ext, '/') . ',hint,.*\r?\n?/m', '', $content);
    }

    /** Subscribe list for a resolved phone's BLF buttons. Records cross-site targets. */
    private function subscribesFor(array $r, array &$crossSite, string $ext): array
    {
        $subs = [];
        foreach ($r['lines'] as $ln) {
            if ((int) ($ln['featureId'] ?? 0) !== 21 && (int) ($ln['featureId'] ?? 0) !== 22) {
                continue;
            }
            $target = (string) ($ln['speedDialNumber'] ?? '');
            if ($target === '') {
                continue;
            }
            $this->assertLiteralPeer($target);
            if (!empty($ln['target_site'])) {
                $subs[] = "$target@from-internal-noxfer-additional";
                $crossSite[] = ['watcher' => $ext, 'target' => $target, 'site' => $ln['target_site']];
            } else {
                $subs[] = "$target@ext-local";
            }
        }
        return array_values(array_unique($subs));
    }

    private function buildExtBlock(string $ext, array $subs, string $family = 'cisco_xml', array $secondaryPeers = []): string
    {
        $this->assertLiteralPeer($ext);
        $b = "[$ext](+)\n";
        if ($subs) {
            $b .= 'subscribe=' . implode(',', $subs) . "\n";
        }
        if ($secondaryPeers) {
            $b .= 'register=' . implode(',', $secondaryPeers) . "\n";
        }
        if ($family === 'cisco_xml') {
            $b .= "cisco_usecallmanager=yes\n";
            $b .= "allowsubscribe=yes\n";
            $b .= "dndbusy=yes\n";
            $b .= "sendrpid=rpid\n";
            $b .= "rpid_update=yes\n";
            $b .= "rpid_immediate=yes\n";
            $b .= "send_diversion=yes\n";
        }
        $b .= "callcounter=yes\n";
        $b .= "progressinband=yes\n";
        return $b;
    }

    private function secondaryPeersFor(array $resolved): array
    {
        if ($this->familyOf($resolved) !== 'cisco_xml') {
            return [];
        }
        $peers = [];
        foreach ($resolved['lines'] ?? [] as $line) {
            if ((int)($line['featureId'] ?? 0) !== 9 || (int)($line['lineIndex'] ?? 1) <= 1) {
                continue;
            }
            $name = trim((string)($line['name'] ?? ''));
            if ($name !== '') {
                $this->assertLiteralPeer($name);
                $peers[] = $name;
            }
        }
        return array_values(array_unique($peers));
    }

    private function localTargetsFor(array $resolved): array
    {
        $targets = [];
        foreach ($resolved['lines'] ?? [] as $line) {
            if (!in_array((int)($line['featureId'] ?? 0), [21, 22], true) || !empty($line['target_site'])) {
                continue;
            }
            $target = trim((string)($line['speedDialNumber'] ?? ''));
            if ($target !== '') {
                $targets[] = $target;
            }
        }
        return array_values(array_unique($targets));
    }

    private function hintLine(string $ext): string
    {
        $this->assertLiteralPeer($ext);
        // USECALLMANAGER consumes two independent providers: device state and presence state.
        // Keep FreePBX's Custom:DND provider in the device side while supplying SIP presence.
        return "exten => $ext,hint,SIP/$ext&Custom:DND$ext,SIP/$ext";
    }

    private function assertLiteralPeer(string $peer): void
    {
        if (!preg_match('/\A[A-Za-z0-9*#+_.-]{1,80}\z/D', $peer)) {
            throw new \InvalidArgumentException('Cisco peer and BLF targets must be literal identifiers without whitespace, context or provider separators');
        }
    }

    private function familyOf(array $resolved): string
    {
        if (!empty($resolved['phone']['family'])) {
            return (string) $resolved['phone']['family'];
        }
        if (!empty($resolved['model']['family'])) {
            return (string) $resolved['model']['family'];
        }
        return 'cisco_xml';
    }

    private function renderSip(array $blocks): string
    {
        return implode("\n", array_values($blocks));
    }

    /* ----------------- apply (write) — safe, managed-block only ------------- */

    /**
     * Apply the plan to the LOCAL PBX. Writes only inside the managed block and
     * skips extensions flagged as conflicts. Triggers a reload.
     *
     * @return array{ok:bool,written:array,skipped:array,message:string}
     */
    public function apply(array $resolvedList): array
    {
        $plan = $this->plan($resolvedList);
        if (!empty($plan['requires_patch']) && empty($plan['preflight']['eligible'])) {
            // Stop the transaction before publishing Cisco phone files that depend
            // on Asterisk glue which this preflight could not safely install.
            throw new \RuntimeException((string)($plan['preflight']['warning']
                ?? 'Cisco Asterisk glue preflight failed.'));
        }
        $snapshot = $this->snapshot();
        try {
            $skip = [];
            foreach ($plan['conflicts'] as $c) {
                $skip[] = $c;
            }

            // Adopt byte-compatible standalone entries by removing their unmanaged
            // copy before writing the equivalent managed block. Active behavior is
            // unchanged, while future BLF subscribe updates gain clear ownership.
            $migrateExts = array_unique(array_merge(...array_values($plan['migration_objects'])));
            $sipRaw = $this->readFile('sip_custom_post.conf');
            foreach ($plan['migration_objects']['sip'] as $ext) {
                $sipRaw = $this->removeExtBlock($sipRaw, $ext);
            }
            $hintsRaw = $this->readFile('extensions_override_freepbx.conf');
            foreach ($plan['migration_objects']['hints'] as $ext) {
                $hintsRaw = $this->removeHintLine($hintsRaw, $ext);
            }

            // Each file replacement is atomic. The enclosing snapshot makes the
            // multi-file set compensating-atomic if any later write/reload fails.
            $this->writeManaged('sip_custom_post.conf', $this->renderSip($this->planSipBlocks($resolvedList, $plan['conflict_objects']['sip'])), $sipRaw);
            $this->writeManaged('extensions_override_freepbx.conf', $this->planHints($resolvedList, $plan['conflict_objects']['hints']), $hintsRaw);
            $crossSiteBlock = $this->planCrossSiteHints($resolvedList);
            $customRaw = $this->readFile('extensions_custom.conf');
            $this->writeCrossSiteManaged('extensions_custom.conf', $crossSiteBlock, $customRaw);
            $this->ensureOneTime();

            $this->reloadManagedAreas();
            return [
                'ok' => true, 'written' => $plan['managed_extensions'], 'skipped' => $skip,
                'migrated' => $migrateExts,
                'cross_site_targets' => $plan['cross_site_targets'],
                'message' => sprintf('Glue committed to atomic managed files (%d extension(s) consolidated); dialplan and chan_sip reloaded.', count($migrateExts)),
            ];
        } catch (\Throwable $e) {
            try {
                $this->restoreSnapshot($snapshot, true);
            } catch (\Throwable $rollbackError) {
                throw new \RuntimeException(
                    $e->getMessage() . '; Asterisk glue rollback also failed: ' . $rollbackError->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    private function planSipBlocks(array $resolvedList, array $skip): array
    {
        $blocks = [];
        $crossSite = [];
        $skipExts = $this->skipExts($skip);
        foreach ($resolvedList as $r) {
            $ext = (string) ($r['phone']['extension'] ?? '');
            if ($ext === '' || in_array($ext, $skipExts, true)) {
                continue;
            }
            $family = $this->familyOf($r);
            if (!in_array($family, ['cisco_xml', 'cisco_cnf'], true)) {
                continue;
            }
            $blocks[$ext] = $this->buildExtBlock($ext, $this->subscribesFor($r, $crossSite, $ext), $family, $this->secondaryPeersFor($r));
        }
        return $blocks;
    }

    private function planHints(array $resolvedList, array $skip): string
    {
        $skipExts = $this->skipExts($skip);
        $lines = ["[ext-local]"];
        foreach ($resolvedList as $r) {
            $ext = (string) ($r['phone']['extension'] ?? '');
            if ($ext === '' || in_array($ext, $skipExts, true)) {
                continue;
            }
            $family = $this->familyOf($r);
            if (!in_array($family, ['cisco_xml', 'cisco_cnf'], true)) {
                continue;
            }
            $lines[$ext] = $this->hintLine($ext);
            foreach ($this->localTargetsFor($r) as $target) {
                if (!in_array($target, $skipExts, true)) {
                    $lines[$target] = $this->hintLine($target);
                }
            }
        }
        return count($lines) > 1 ? implode("\n", array_values($lines)) : '';
    }

    private function skipExts(array $skip): array
    {
        $exts = [];
        foreach ($skip as $s) {
            if (preg_match('/^([A-Za-z0-9*#+_.-]+):/', $s, $m)) {
                $exts[] = $m[1];
            }
        }
        return array_values(array_unique($exts));
    }

    /** Ensure one-time custom contexts + notify defs exist (managed, idempotent). */
    public function ensureOneTime(): void
    {
        $custom = $this->path('extensions_custom.conf');
        $cur = $this->readFile('extensions_custom.conf');
        if (strpos($cur, '; ucm-serviceuri-v2') === false) {
            $baseHandlers = strpos($cur, '_[x]-cisco-serviceuri-.') === false
                ? "exten => _[x]-cisco-serviceuri-.,1,Goto(\${EXTEN:19},1)\n" .
                  "exten => _blfpickup-.,1,PickupChan(SIP/\${EXTEN:10})\n" .
                  "same => next,Hangup(normal_circuit_congestion)\n" .
                  "exten => pickup,1,Pickup()\n" .
                  "same => next,Hangup(normal_circuit_congestion)\n"
                : '';
            $block = $this->wrap(
                "; ucm-serviceuri-v2\n" .
                "[from-internal-custom](+)\n" .
                $baseHandlers .
                "exten => _gpickup-.,1,PickupChan(SIP/\${EXTEN:8})\n" .
                "same => next,Set(CHANNEL(pickupgroup)=\${EXTEN:8})\n" .
                "same => next,Pickup()\n" .
                "same => next,Hangup(normal_circuit_congestion)\n" .
                "exten => opickup,1,Set(CHANNEL(pickupgroup)=\${OTHERPICKUPGROUP})\n" .
                "same => next,Pickup()\n" .
                "same => next,Hangup(normal_circuit_congestion)\n" .
                "exten => _cfwdall-.,1,Answer()\n" .
                "same => next,Set(SIPPEER(\${CHANNEL(peername)},callforward)=\${EXTEN:8})\n" .
                "same => next,Hangup(normal_clearing)\n" .
                "exten => cfwdall,1,Answer()\n" .
                "same => next,Set(SIPPEER(\${CHANNEL(peername)},callforward)=)\n" .
                "same => next,Hangup(normal_clearing)\n"
            );
            $this->appendIfMissing($custom, $block, 'from-internal-custom UCM handlers');
        }
        $notify = $this->path('sip_notify_custom.conf');
        $curN = $this->readFile('sip_notify_custom.conf');
        if (strpos($curN, '[cisco-restart]') === false) {
            $block = $this->wrap($this->ciscoNotifyDefs());
            $this->appendIfMissing($notify, $block, 'cisco-restart/reset notify');
        }
        if (strpos($curN, '[cisco-check-cfg]') === false) {
            $this->appendIfMissing($notify, $this->wrap("[cisco-check-cfg]\nEvent=>check-sync\n"), 'legacy Cisco check-sync');
        }
        if (strpos($curN, '[reboot-yealink]') === false) {
            $this->appendIfMissing($notify, $this->wrap("[reboot-yealink]\nEvent=>check-sync\\;reboot=true\n"), 'Yealink reboot notify');
        }
    }

    private function ciscoNotifyDefs(): string
    {
        $stamp = '{00000000-0000-0000-0000-000000000000}';
        $mk = function (string $name, string $action) use ($stamp) {
            return "[$name]\n" .
                "Event=>service-control\n" .
                "Subscription-State=>active\n" .
                "Content-Type=>text/plain\n" .
                "Content=>action=$action\n" .
                "Content=>RegisterCallId={\${SIPPEER(\${PEERNAME},regcallid)}}\n" .
                "Content=>ConfigVersionStamp=$stamp\n" .
                "Content=>DialplanVersionStamp=$stamp\n" .
                "Content=>SoftkeyVersionStamp=$stamp\n" .
                "Content=>FeatureControlVersionStamp=$stamp\n";
        };
        return $mk('cisco-restart', 'restart') . "\n" . $mk('cisco-reset', 'reset');
    }

    private function oneTimeStatus(): array
    {
        $custom = $this->readFile('extensions_custom.conf');
        $notify = $this->readFile('sip_notify_custom.conf');
        return [
            'from_internal_custom' => strpos($custom, '_[x]-cisco-serviceuri-.') !== false,
            'cisco_notify'         => strpos($notify, '[cisco-restart]') !== false,
            'cisco_check_sync'     => strpos($notify, '[cisco-check-cfg]') !== false,
            'yealink_notify'       => strpos($notify, '[reboot-yealink]') !== false,
        ];
    }

    /* ----------------- file helpers ------------------ */
    private function path(string $f): string
    {
        return self::ASTDIR . '/' . $f;
    }
    private function readFile(string $f): string
    {
        $path = $this->path($f);
        if (!is_file($path)) { return ''; }
        $content = file_get_contents($path);
        if ($content === false) { throw new \RuntimeException("Could not read Asterisk custom file $f; no empty replacement is permitted"); }
        return $content;
    }
    private function wrap(string $body): string
    {
        return self::BEGIN . "\n" . rtrim($body) . "\n" . self::END . "\n";
    }
    private function existsOutsideManaged(string $content, string $needle): bool
    {
        $outside = $this->stripManaged($content);
        return strpos($outside, $needle) !== false;
    }
    private function stripManaged(string $content): string
    {
        return (string) preg_replace('/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '/s', '', $content);
    }
    private function writeManaged(string $f, string $body, ?string $rawOverride = null): void
    {
        $path = $this->path($f);
        $cur = $rawOverride ?? $this->readFile($f);
        $stripped = rtrim($this->stripManaged($cur));
        $new = ($stripped !== '' ? $stripped . "\n\n" : '') . $this->wrap($body);
        $this->atomicWrite($path, $new);
    }
    private function appendIfMissing(string $path, string $block, string $label): void
    {
        $cur = $this->readFile(basename($path));
        $this->atomicWrite($path, rtrim($cur) . "\n\n" . $block);
    }

    private function atomicWrite(string $path, string $content): void
    {
        $directory = dirname($path);
        $owner = fileowner($directory);
        $group = filegroup($directory);
        if ($owner === false || $group === false) {
            throw new \RuntimeException("Could not determine Asterisk directory ownership for $path");
        }
        $tmp = tempnam($directory, '.ucmp-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not allocate an adjacent Asterisk staging file');
        }
        try {
            $written = file_put_contents($tmp, $content, LOCK_EX);
            if ($written !== strlen($content)) {
                throw new \RuntimeException("Could not fully stage $path");
            }
            if (fileowner($tmp) !== $owner && !chown($tmp, $owner)) {
                throw new \RuntimeException("Could not preserve Asterisk owner for $path");
            }
            if (filegroup($tmp) !== $group && !chgrp($tmp, $group)) {
                throw new \RuntimeException("Could not preserve Asterisk group for $path");
            }
            if (!chmod($tmp, 0660)) {
                throw new \RuntimeException("Could not secure staged Asterisk file $path");
            }
            if (!rename($tmp, $path)) {
                throw new \RuntimeException("Could not atomically replace $path");
            }
            $tmp = '';
        } finally {
            if ($tmp !== '' && is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function reloadManagedAreas(): void
    {
        foreach (['dialplan reload', 'sip reload'] as $command) {
            $output = [];
            $status = 0;
            exec('/usr/bin/timeout --signal=KILL 15 /usr/sbin/asterisk -rx ' . escapeshellarg($command) . ' >/dev/null 2>&1', $output, $status);
            if ($status !== 0) {
                if (in_array($status, [124, 137], true)) {
                    throw new \RuntimeException("Asterisk command timed out after 15 seconds: $command");
                }
                throw new \RuntimeException("Asterisk command failed: $command");
            }
        }
    }

    public function planCrossSiteHints(array $resolvedList): string
    {
        $crossSite = [];
        foreach ($resolvedList as $r) {
            $ext = (string) ($r['phone']['extension'] ?? '');
            if ($ext === '') {
                continue;
            }
            $family = $this->familyOf($r);
            if (!in_array($family, ['cisco_xml', 'cisco_cnf'], true)) {
                continue;
            }
            $this->subscribesFor($r, $crossSite, $ext);
        }
        
        $lines = ['[from-internal-noxfer-additional](+)'];
        $targets = [];
        foreach ($crossSite as $cs) {
            $targets[] = $cs['target'];
        }
        $targets = array_values(array_unique($targets));
        foreach ($targets as $target) {
            $lines[] = $this->hintLine($target);
        }
        return implode("\n", $lines);
    }

    private function wrapCrossSite(string $body): string
    {
        return self::CS_BEGIN . "\n" . rtrim($body) . "\n" . self::CS_END . "\n";
    }

    private function stripCrossSiteManaged(string $content): string
    {
        return (string) preg_replace('/' . preg_quote(self::CS_BEGIN, '/') . '.*?' . preg_quote(self::CS_END, '/') . '/s', '', $content);
    }

    private function writeCrossSiteManaged(string $f, string $body, ?string $rawOverride = null): void
    {
        $path = $this->path($f);
        $cur = $rawOverride ?? $this->readFile($f);
        $stripped = rtrim($this->stripCrossSiteManaged($cur));
        $new = ($stripped !== '' ? $stripped . "\n\n" : '') . $this->wrapCrossSite($body);
        $this->atomicWrite($path, $new);
    }
}
