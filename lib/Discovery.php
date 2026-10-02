<?php
/**
 * Reconcile live chan_sip/PJSIP registrations with the physical-phone inventory.
 *
 * A registration's user agent is matched to ModelCatalog using the longest
 * model token, and a reported device name supplies the MAC when available.
 * Discovery preserves operator-owned assignment and override fields. A phone
 * that does not report a MAC remains editable and can be bound manually.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class Discovery
{
    private $ucm;

    public function __construct($ucm)
    {
        $this->ucm = $ucm;
    }

    public function run(int $siteId): array
    {
        $site = $this->ucm->getSite($siteId, true);
        if (!$site) {
            return ['ok' => false, 'message' => "Unknown site $siteId"];
        }

        $exts = [];
        try {
            $conn = $this->ucm->siteConnection($siteId);
            foreach ($this->endpointDevices($siteId) as $d) {
                $exts[] = $d;
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $models = $this->ucm->getModels();
        $existing = [];
        foreach ($this->ucm->getPhones(['site_id' => $siteId]) as $p) {
            $existing[(string) $p['extension']] = $p;
        }
        $suppressedMacs = [];
        try {
            $stmt = $this->ucm->getDb()->prepare(
                'SELECT mac FROM ucmp_phone_mac_suppressions WHERE site_id=?'
            );
            $stmt->execute([$siteId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $mac) {
                $suppressedMacs[strtoupper((string)$mac)] = true;
            }
        } catch (\Throwable $e) {
            // Upgrade/install fallback: absence of the table means no suppressions yet.
        }

        $updated = 0;
        $created = 0;
        $unregistered = 0;
        $suppressed = 0;
        $unmatched = [];
        $macConflicts = [];

        foreach ($exts as $d) {
            $ext = (string) $d['id'];
            if ($ext === '') {
                continue;
            }
            $driver = strtolower((string)($d['driver'] ?? 'sip')) === 'pjsip' ? 'pjsip' : 'sip';
            $parsed = $driver === 'pjsip'
                ? $this->pjsipEndpointDetail($conn, $ext)
                : $this->parsePeerDetail($conn->asterisk("sip show peer $ext"));

            $curRow = $existing[$ext] ?? null;

            if ($parsed['useragent'] === '') {
                $unregistered++;
                // Still sync the label (extension name may have changed in FreePBX) even though
                // there's no live registration to pull vendor/model/mac/ip from.
                $this->upsertPhoneSafe($siteId, $ext, (string) ($d['description'] ?? ''), $curRow, null, null, null, null, $driver, $macConflicts);
                if (!isset($existing[$ext])) {
                    $created++;
                }
                continue;
            }

            $vendor = $this->vendorFromUseragent($parsed['useragent']);
            $model = $vendor ? $this->matchModel($models, $vendor, $parsed['useragent']) : null;
            $observedMac = strtoupper((string)($parsed['mac'] ?? ''));
            if ($observedMac !== '' && isset($suppressedMacs[$observedMac])) {
                // Keep the FreePBX extension visible as unprovisioned, but do not
                // resurrect a physical phone the operator explicitly deleted.
                $this->upsertPhoneSafe(
                    $siteId,
                    $ext,
                    (string)($d['description'] ?? ''),
                    $curRow,
                    null,
                    null,
                    null,
                    null,
                    $driver,
                    $macConflicts
                );
                $suppressed++;
                if (isset($existing[$ext])) { $updated++; } else { $created++; }
                continue;
            }
            if ($vendor && !$model) {
                $unmatched[] = "$ext: '{$parsed['useragent']}' — no catalog entry matches this model (add it under Settings, or the generator will fall back to a default)";
            }

            $this->upsertPhoneSafe(
                $siteId,
                $ext,
                (string) ($d['description'] ?? ''),
                $curRow,
                $vendor,
                $model,
                $parsed['mac'] ?: null,
                $parsed['ip'] ?: null,
                $driver,
                $macConflicts
            );
            if (isset($existing[$ext])) {
                $updated++;
            } else {
                $created++;
            }
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                'Discovery on %s: %d updated, %d newly added, %d unregistered (left as-is)%s%s',
                $site['name'],
                $updated,
                $created,
                $unregistered,
                $unmatched ? ('; ' . count($unmatched) . ' model(s) not in catalog') : '',
                ($macConflicts ? ('; ' . count($macConflicts) . ' MAC conflict(s) skipped (see details) — usually a transient/duplicate SIP registration') : '')
                    . ($suppressed ? "; $suppressed intentionally deleted physical phone(s) left unprovisioned" : '')
            ),
            'updated'      => $updated,
            'created'      => $created,
            'unregistered' => $unregistered,
            'suppressed'   => $suppressed,
            'unmatched'    => $unmatched,
            'macConflicts' => $macConflicts,
        ];
    }

    /** All SIP/PJSIP devices via the official Core API or Asterisk CLI fallback. */
    private function endpointDevices(int $siteId): array
    {
        if ($siteId === $this->ucm->localSiteId()) {
            try {
                $core = \FreePBX::Core();
                if (is_object($core) && method_exists($core, 'getAllDevicesByType')) {
                    $out = [];
                    foreach (EndpointProfile::DRIVERS as $driver) {
                        foreach ((array) $core->getAllDevicesByType($driver) as $d) {
                            if (!empty($d['id'])) {
                                $out[] = ['id'=>(string)$d['id'], 'description'=>(string)($d['description'] ?? ''), 'driver'=>$driver];
                            }
                        }
                    }
                    return $out;
                }
            } catch (\Throwable $e) {
                // fall through to CLI-based enumeration below
            }
        }
        // Remote site (or local Core API unavailable): enumerate via the peers list.
        $conn = $this->ucm->siteConnection($siteId);
        $raw = $conn->asterisk('sip show peers');
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^(\S+)\/\S*\s/', $line, $m)) {
                $out[] = ['id'=>$m[1], 'description'=>'', 'driver'=>'sip'];
            }
        }
        $pjsipRaw = $conn->asterisk('pjsip show endpoints');
        foreach (preg_split('/\r?\n/', $pjsipRaw) as $line) {
            if (preg_match('/^\s*Endpoint:\s+(\S+)/', $line, $match)) {
                $id = preg_replace('#/.*$#', '', (string)$match[1]);
                if ($id !== '' && $id !== '<Endpoint/CID') { $out[] = ['id'=>$id, 'description'=>'', 'driver'=>'pjsip']; }
            }
        }
        return $out;
    }

    /** Resolve a PJSIP endpoint's current contact, user-agent and source address. */
    private function pjsipEndpointDetail(SiteConnection $conn, string $ext): array
    {
        $endpoint = $conn->asterisk("pjsip show endpoint $ext");
        $field = static function (string $detail, string $label): string {
            return preg_match('/^[ \t]*' . preg_quote($label, '/') . '[ \t]*:[ \t]*([^\r\n]*)/mi', $detail, $match)
                ? trim((string)$match[1]) : '';
        };
        $listed = [];
        if (preg_match_all('/^[ \t]*Contact:[ \t]+(\S+\/sips?:\S+)[ \t]+\S+[ \t]+(\S+)/mi', $endpoint, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) { $listed[$row[1]] = in_array(strtolower($row[2]), ['avail','nonqual'], true) ? 0 : 1; }
        }
        // Endpoint tables truncate long URIs. The AoR's contact field contains
        // the complete CLI identifier, including transport and URI parameters.
        $contacts = [];
        foreach (array_slice(explode(',', $field($endpoint, 'aors')), 0, 16) as $aor) {
            $aor = trim($aor);
            if ($aor === '' || !preg_match('/^[A-Za-z0-9_.:*#+-]+$/D', $aor)) { continue; }
            $aorDetail = $conn->asterisk('pjsip show aor ' . $aor);
            if (preg_match('/^[ \t]*contact[ \t]+:[ \t]*([^\r\n]*)/mi', $aorDetail, $row)) {
                foreach (explode(',', $row[1]) as $contact) {
                    $contact = trim($contact);
                    if (preg_match('/^[^\s\/]+\/sips?:[^\s]+$/D', $contact)) { $contacts[$contact] = true; }
                }
            }
        }
        $contacts = $contacts ? array_keys($contacts) : array_keys($listed);
        $rank = static function (string $contact) use ($listed): int {
            foreach ($listed as $prefix => $status) { if (str_starts_with($contact, $prefix)) { return $status; } }
            return 2;
        };
        usort($contacts, static fn(string $a, string $b): int => ($rank($a) <=> $rank($b)) ?: strcmp($a, $b));
        $detail = '';
        $userAgent = '';
        foreach (array_slice($contacts, 0, 32) as $contactId) {
            $detail = $conn->asterisk('pjsip show contact ' . $contactId);
            $userAgent = $field($detail, 'user_agent') ?: $field($detail, 'UserAgent');
            if ($userAgent !== '') { break; }
        }
        if ($userAgent === '') { return ['useragent'=>'', 'ip'=>'', 'mac'=>'']; }
        $uri = $field($detail, 'uri');
        $via = $field($detail, 'via_addr') ?: $field($detail, 'ViaAddr');
        $ip = '';
        $uriHost = preg_match('/@(?:\[([^\]]+)\]|([^:;>\s]+))/', $uri, $match) ? ($match[1] !== '' ? $match[1] : $match[2]) : '';
        foreach ([trim($via, '[]'), $uriHost] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) { $ip = $candidate; break; }
        }
        $mac = '';
        if (preg_match('/SEP([0-9A-F]{12})/i', $uri . ' ' . $userAgent, $match)) { $mac = strtoupper($match[1]); }
        return ['useragent'=>$userAgent, 'ip'=>$ip, 'mac'=>$mac];
    }

    /** Parse the relevant fields out of `sip show peer <ext>` output. */
    private function parsePeerDetail(string $text): array
    {
        $get = function (string $label) use ($text): string {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(.*)$/mi', $text, $m)) {
                return trim($m[1]);
            }
            return '';
        };
        $ua = $get('Useragent');
        $addr = $get('Addr->IP');
        $ip = '';
        if ($addr !== '' && strtolower($addr) !== '(null)') {
            $ip = preg_replace('/:\d+$/', '', $addr);
        }
        $deviceName = $get('Device Name');
        $mac = '';
        if (preg_match('/SEP([0-9A-Fa-f]{12})/i', $deviceName, $m)) {
            $mac = strtoupper($m[1]);
        }
        return ['useragent' => $ua, 'ip' => $ip, 'mac' => $mac];
    }

    private function vendorFromUseragent(string $ua): ?string
    {
        if (stripos($ua, 'cisco') === 0) {
            return 'Cisco';
        }
        if (stripos($ua, 'grandstream') === 0) {
            return 'Grandstream';
        }
        if (stripos($ua, 'yealink') === 0) {
            return 'Yealink';
        }
        return null;
    }

    /** Best (longest, most specific) model-catalog match for this vendor + Useragent string. */
    private function matchModel(array $models, string $vendor, string $ua): ?array
    {
        $best = null;
        foreach ($models as $m) {
            if (strcasecmp((string) $m['vendor'], $vendor) !== 0) {
                continue;
            }
            $tok = (string) $m['model'];
            if ($tok !== '' && stripos($ua, $tok) !== false) {
                if ($best === null || strlen($tok) > strlen((string) $best['model'])) {
                    $best = $m;
                }
            }
        }
        return $best;
    }

    /** Keep one conflicting registration observation from aborting the complete discovery run. */
    private function upsertPhoneSafe(int $siteId, string $ext, string $freepbxDesc, ?array $curRow, ?string $vendor, ?array $model, ?string $mac, ?string $ip, string $driver, array &$macConflicts): void
    {
        try {
            $this->upsertPhone($siteId, $ext, $freepbxDesc, $curRow, $vendor, $model, $mac, $ip, $driver);
        } catch (RpcOperationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($mac && stripos($e->getMessage(), 'mac') !== false) {
                $macConflicts[] = "$ext: MAC $mac is already recorded on another extension right now — left $ext's MAC as-is ({$e->getMessage()})";
                // Retry once without the conflicting MAC so the rest of this extension's data
                // (vendor/model/ip/label) still gets recorded.
                try {
                    $this->upsertPhone($siteId, $ext, $freepbxDesc, $curRow, $vendor, $model, null, $ip, $driver);
                } catch (RpcOperationException $e2) {
                    throw $e2;
                } catch (\Throwable $e2) {
                    $macConflicts[] = "$ext: retry without MAC also failed: {$e2->getMessage()}";
                }
            } else {
                $macConflicts[] = "$ext: $e->getMessage()";
            }
        }
    }

    /**
     * Insert or refresh discovered facts without overwriting operator-managed state.
     *
     * A label follows the current FreePBX description only while it is blank or still equal to
     * that description; once customized, discovery preserves it. The updated timestamp changes
     * only when a configuration-relevant field changes, so routine scans do not create false
     * rebuild-pending status.
     */
    private function upsertPhone(int $siteId, string $ext, string $freepbxDesc, ?array $curRow, ?string $vendor, ?array $model, ?string $mac, ?string $ip, string $driver): void
    {
        $this->ucm->withConfigurationSyncLock(function () use ($siteId,$ext,$freepbxDesc,$curRow,$vendor,$model,$mac,$ip,$driver): void {
            $db = $this->ucm->getDb();
            if ($db->inTransaction() || !$db->beginTransaction()) {
                throw new \RuntimeException('Discovery requires its own phone transaction');
            }
            $commitAttempted = false;
            try {
                $query = $db->prepare('SELECT * FROM ucmp_phones WHERE (site_id=? OR site_id IS NULL) AND '
                    . ($curRow ? 'id=?' : 'extension=?')
                    . ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE'));
                $query->execute([$siteId,$curRow['id'] ?? $ext]);
                $fresh = $query->fetch(\PDO::FETCH_ASSOC) ?: null;
                if (($curRow === null) !== ($fresh === null)) {
                    throw new \DomainException('Phone inventory changed during discovery; run a fresh scan');
                }
                foreach (['extension','extension_id','mac','model_id','family'] as $field) {
                    if ((string)($curRow[$field] ?? '') !== (string)($fresh[$field] ?? '')) {
                        throw new \DomainException('Phone binding changed during discovery; observation skipped');
                    }
                }
                // Deletion may have happened after the scan loaded its suppression list.
                if ($mac) {
                    $suppressed = $db->prepare('SELECT 1 FROM ucmp_phone_mac_suppressions WHERE site_id=? AND mac=?');
                    $suppressed->execute([$siteId,strtoupper($mac)]);
                    if ($suppressed->fetchColumn()) { $mac = null; $vendor = null; $model = null; $ip = null; }
                }
                $this->writeObservedPhone($siteId,$ext,$freepbxDesc,$fresh,$vendor,$model,$mac,$ip,$driver);
                $commitAttempted = true;
                if (!$db->commit()) { throw new \RuntimeException('Discovery commit was not confirmed'); }
            } catch (\Throwable $error) {
                try {
                    if ($db->inTransaction() && !$db->rollBack()) { $commitAttempted = true; }
                } catch (\Throwable $rollbackError) { $commitAttempted = true; }
                if ($commitAttempted) {
                    throw new RpcOperationException('Discovery save is unconfirmed; reconcile before another scan',
                        ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'failed_stage'=>'discovery_commit']);
                }
                throw $error;
            }
        });
    }

    private function writeObservedPhone(int $siteId, string $ext, string $freepbxDesc, ?array $curRow, ?string $vendor, ?array $model, ?string $mac, ?string $ip, string $driver): void
    {
        $db = $this->ucm->getDb();
        $id = $curRow['id'] ?? null;

        $curLabel = (string) ($curRow['label'] ?? '');
        $labelIsCustomized = ($curLabel !== '' && $curLabel !== $freepbxDesc);
        $label = $labelIsCustomized ? $curLabel : $freepbxDesc;

        $set = [];
        $args = [];
        $configChanged = false;
        if ($label !== '' && !$labelIsCustomized && $label !== $curLabel) { $set[] = 'label = ?'; $args[] = $label; $configChanged = true; }
        if ($vendor !== null && $vendor !== ($curRow['vendor'] ?? null)) { $set[] = 'vendor = ?'; $args[] = $vendor; $configChanged = true; }
        if ($model !== null && (int) $model['id'] !== (int) ($curRow['model_id'] ?? 0)) {
            $set[] = 'model_id = ?'; $args[] = (int) $model['id'];
            $set[] = 'family = ?';   $args[] = (string) $model['family'];
            $configChanged = true;
        }
        if ($mac && $mac !== ($curRow['mac'] ?? null)) { $set[] = 'mac = ?'; $args[] = $mac; $configChanged = true; }
        if ($ip && $ip !== ($curRow['ip'] ?? null)) { $set[] = 'ip = ?'; $args[] = $ip; }
        // `driver` is desired policy (Inherit or a phone override), not a live
        // observation. The actual Core driver is joined as extension_driver.
        // Re-discovery must never turn an inherited phone into an override or
        // undo a deliberate override; only a newly imported endpoint is seeded
        // with its current FreePBX driver below.
        if ($configChanged || !$id) { $set[] = 'updated = ?'; $args[] = LogicalExtensionService::nextPhoneUpdate($curRow ?? []); }

        if ($id) {
            if ($set) {
                $args[] = $id;
                $db->prepare('UPDATE ucmp_phones SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
            }
            (new LogicalExtensionService($db))->linkPhone((int)$id, $siteId, $ext);
            return;
        }

        $insert = $db->prepare(
            'INSERT INTO ucmp_phones (site_id, extension, label, vendor, model_id, family, mac, ip, status, driver, transport, sip_port, updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $siteId,
            $ext,
            $label,
            $vendor,
            $model ? (int) $model['id'] : null,
            $model ? (string) $model['family'] : null,
            $mac ?: null,
            $ip ?: null,
            'discovered',
            $driver,
            'inherit',
            0,
            time(),
        ]);
        (new LogicalExtensionService($db))->linkPhone((int)$db->lastInsertId(), $siteId, $ext);
    }
}
