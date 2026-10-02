<?php

/**

 * PhoneCommand — send control commands to a provisioned phone.

 *

 * Restart and reboot routing is family-specific (see docs/DEVICE-SUPPORT.md):

 *   cisco_xml  -> cisco-restart / cisco-reset (service-control NOTIFY)

 *   cisco_cnf  -> cisco-check-cfg + syncinfo.xml bump (check-sync NOTIFY)

 *   yealink    -> reboot-yealink (check-sync NOTIFY)

 *   grandstream -> not available via SIP NOTIFY

 *

 * @package FreePBX\modules\Ucmprovisioner

 */



namespace FreePBX\modules\Ucmprovisioner;



class PhoneCommand

{

    private $ucm;



    public function __construct($ucm)

    {

        $this->ucm = $ucm;

    }



    public function send(int $phoneId, string $cmd, bool $legacySyncReady = false): array

    {

        $phone = null;
        $noteFallback = '';
        try {
        $phone = $this->ucm->getPhone($phoneId);

        if (!$phone) {

            return ['ok' => false, 'message' => "Unknown phone #$phoneId"];

        }

        $ext = (string)($phone['extension'] ?? '');

        if ($ext === '') {

            return ['ok' => false, 'message' => 'Phone has no extension bound'];

        }

        $siteId = (int)($phone['site_id'] ?: $this->ucm->localSiteId());

        $conn = $this->ucm->siteConnection($siteId);

        $family = $this->familyOf($phone);
        $driver = $this->driverOf($phone, $ext, $siteId);

        // Saving a reassignment does not change the current SIP registration. Until the handset
        // fetches its new file, restart/reboot must target the previous live endpoint so the
        // command can reach the phone that needs to re-register.
        if (in_array($cmd, ['restart', 'reboot'], true)) {
            $prevExt = (string) ($phone['prev_extension'] ?? '');
            $prevDriver = $prevExt !== '' ? $this->driverOf([], $prevExt, $siteId) : $driver;
            if ($prevExt !== '' && $prevExt !== $ext
                && !$this->isLiveRegistered($conn, $ext, $driver)
                && $this->isLiveRegistered($conn, $prevExt, $prevDriver)) {
                $noteFallback = " (still registered as $prevExt — hasn't fetched its new config yet; sent there instead of $ext)";
                $ext = $prevExt;
                $driver = $prevDriver;
            }
        }

        switch ($cmd) {

            case 'restart':

                $r = $this->restart($phone, $ext, $conn, $family, $siteId, $driver, $legacySyncReady);
                break;

            case 'reboot':

                $r = $this->reboot($phone, $ext, $conn, $family, $siteId, $driver, $legacySyncReady);
                break;

            case 'factory':

                $r = $this->factory($phone, $conn, $family);
                break;

            default:

                return ['ok' => false, 'message' => "Unknown command '$cmd'"];

        }

        } catch (\Throwable $e) {
            $r=['ok'=>false,'message'=>$e->getMessage()];
        }
        $r['device_observed']=null;
        $r['command_at']=time();
        // Track what was last sent, regardless of success/failure, so the Phones list can show it.
        try {
            if ($phone !== null) { $this->ucm->getDb()->prepare('UPDATE ucmp_phones SET last_command = ?, last_command_at = ? WHERE id = ?')
                ->execute([$cmd . ($r['ok'] ? '' : ':failed'), time(), (int) $phone['id']]);
            }
        } catch (\Throwable $e) {
            // non-fatal — command result itself still returned below
        }

        if ($noteFallback !== '' && isset($r['message'])) {
            $r['message'] .= $noteFallback;
        }

        return $r;

    }

    /**
     * Batch commands deliberately share one legacy Cisco syncinfo.xml bump. Concurrent
     * per-phone bumps race on the same TFTP file and can leave the fleet on mixed versions.
     */
    public function sendBatch(array $phoneIds, string $cmd): array
    {
        $phoneIds = array_values(array_unique(array_map('intval', $phoneIds)));
        $results = [];
        $legacySites = [];
        $legacyPhones = [];
        foreach ($phoneIds as $phoneId) {
            try {
                $phone = $this->ucm->getPhone($phoneId);
                if (!$phone) { continue; }
                if (in_array($cmd, ['restart', 'reboot'], true) && !empty($phone['extension'])
                    && $this->familyOf($phone) === 'cisco_cnf') {
                    $siteId = (int)($phone['site_id'] ?: $this->ucm->localSiteId());
                    $legacySites[$siteId] = null;
                    $legacyPhones[$phoneId] = $siteId;
                }
            } catch (\Throwable $e) {
                $results[$phoneId] = ['ok'=>false, 'message'=>$e->getMessage(), 'stage'=>'prepare'];
            }
        }
        foreach ($legacySites as $siteId => $_) {
            try { $this->bumpLegacySync($siteId, $cmd === 'reboot'); }
            catch (\Throwable $e) { $legacySites[$siteId] = $e->getMessage(); }
        }

        $succeeded = 0;
        foreach ($phoneIds as $phoneId) {
            if (isset($results[$phoneId])) { continue; }
            $legacySite = $legacyPhones[$phoneId] ?? null;
            if ($legacySite !== null && $legacySites[$legacySite] !== null) {
                $result = ['ok'=>false, 'message'=>$legacySites[$legacySite], 'stage'=>'legacy_sync'];
            } else {
                try { $result = $this->send($phoneId, $cmd, $legacySite !== null); }
                catch (\Throwable $e) { $result = ['ok'=>false, 'message'=>$e->getMessage(), 'stage'=>'command']; }
            }
            $results[(string)(int)$phoneId] = $result;
            if (!empty($result['ok'])) { $succeeded++; }
        }
        $failed = count($results) - $succeeded;
        return [
            'ok'=>$failed === 0,
            'message'=>sprintf('%s sent to %d phone(s); %d failed', ucfirst($cmd), $succeeded, $failed),
            'succeeded'=>$succeeded,
            'failed'=>$failed,
            'results'=>$results,
        ];
    }



    /** Cheap live-registration check through the extension's actual channel driver. */
    private function isLiveRegistered(SiteConnection $conn, string $ext, string $driver): bool
    {
        if ($ext === '') {
            return false;
        }
        if ($driver === 'pjsip') {
            $raw = $conn->asterisk('pjsip show endpoint ' . $ext);
            return (bool)preg_match('/^\s*Contact:\s+\S+.*\b(?:Avail|NonQual)\b/mi', $raw);
        }
        $raw = $conn->asterisk('sip show peer ' . $ext);
        return (bool)preg_match('/Status\s*:\s*(OK|LAGGED)\b/i', $raw);
    }



    private function restart(array $phone, string $ext, SiteConnection $conn, string $family, int $siteId, string $driver, bool $legacySyncReady = false): array

    {

        switch ($family) {

            case 'cisco_xml':

                $out = $this->notify($conn, 'cisco-restart', $ext, $driver);

                return ['ok' => true, 'message' => "Sent config reload (cisco-restart) to $ext", 'detail' => trim($out)];

            case 'cisco_cnf':

                if (!$legacySyncReady) { $this->bumpLegacySync($siteId); }

                $out = $this->notify($conn, 'cisco-check-cfg', $ext, $driver);

                return ['ok' => true, 'message' => "Sent check-sync to legacy Cisco $ext (config reload)", 'detail' => trim($out)];

            case 'yealink':

                $out = $this->notify($conn, 'reboot-yealink', $ext, $driver);

                return ['ok' => true, 'message' => "Sent provisioning sync to Yealink $ext", 'detail' => trim($out)];

            case 'grandstream':

                return ['ok' => false, 'message' => 'Remote restart is not available for Grandstream via SIP NOTIFY. Reboot the base from its web UI or power-cycle.'];

            default:

                return ['ok' => false, 'message' => "Restart not supported for family '$family'"];

        }

    }



    private function reboot(array $phone, string $ext, SiteConnection $conn, string $family, int $siteId, string $driver, bool $legacySyncReady = false): array

    {

        switch ($family) {

            case 'cisco_xml':

                $out = $this->notify($conn, 'cisco-reset', $ext, $driver);

                return ['ok' => true, 'message' => "Sent warm reboot (cisco-reset) to $ext", 'detail' => trim($out)];

            case 'cisco_cnf':

                if (!$legacySyncReady) { $this->bumpLegacySync($siteId, true); }

                $out = $this->notify($conn, 'cisco-check-cfg', $ext, $driver);

                return ['ok' => true, 'message' => "Sent check-sync to legacy Cisco $ext (reboot when idle)", 'detail' => trim($out)];

            case 'yealink':

                $out = $this->notify($conn, 'reboot-yealink', $ext, $driver);

                return ['ok' => true, 'message' => "Sent reboot to Yealink $ext", 'detail' => trim($out)];

            case 'grandstream':

                return ['ok' => false, 'message' => 'Remote reboot is not available for Grandstream via SIP NOTIFY. Reboot the base from its web UI or power-cycle.'];

            default:

                return ['ok' => false, 'message' => "Reboot not supported for family '$family'"];

        }

    }

    /** Send through the endpoint's current live driver, before a FreePBX reload changes it. */
    private function notify(SiteConnection $conn, string $type, string $ext, string $driver): string
    {
        if ($driver === 'pjsip') {
            $output=$conn->asterisk("pjsip send notify $type endpoint $ext");
        } else {
            $output=$conn->asterisk('sip notify ' . $type . ' ' . $this->sipNotifyTarget($conn, $ext));
        }
        self::assertCommandResponse($output);
        return $output;
    }

    public static function assertCommandResponse(string $output): void
    {
        if (preg_match('/Could not create address for/i', $output)) {
            throw new \RuntimeException('Asterisk has no routable address for this endpoint. Check its registration before retrying the config-reload request.');
        }
        if (preg_match('/Unable to find notify type|No .*notify.*file found/i', $output)) {
            throw new \RuntimeException('The requested NOTIFY type is unavailable in Asterisk; check the loaded notify configuration.');
        }
        if (preg_match('/Unable to retrieve endpoint|Endpoint not found|No endpoint/i', $output)) {
            throw new \RuntimeException('The endpoint is unavailable in Asterisk; check its channel driver and loaded endpoint configuration.');
        }
        if (preg_match('/\b(?:no such|not found|unable|cannot|could not|failed|failure|error|does not exist|not available|no endpoint|no peer)\b/i',$output)
            || !preg_match('/^\s*Sending NOTIFY\b/mi', $output)) {
            throw new \RuntimeException('Asterisk did not confirm dispatch of the NOTIFY request. Its outcome is unknown; check Asterisk and endpoint status before retrying.');
        }
    }

    /**
     * chan_sip normally routes by peer name. If an earlier bad config left the
     * peer set to TCP while its surviving registration is UDP, target that
     * registered UDP socket directly so the phone can still fetch the repair.
     */
    private function sipNotifyTarget(SiteConnection $conn, string $ext): string
    {
        $detail = $conn->asterisk('sip show peer ' . $ext);
        $primary = preg_match('/^\s*Prim\.Transp\s*:\s*(\S+)/mi', $detail, $match)
            ? strtolower((string)$match[1]) : '';
        if (!preg_match('/^\s*Reg\. Contact\s*:\s*<?sip:[^@>]+@(\[[^\]]+\]|[^:;>\s]+):(\d+)([^>\r\n]*)/mi', $detail, $contact)) {
            return $ext;
        }
        $params = strtolower((string)$contact[3]);
        $registeredTransport = preg_match('/;transport=([a-z]+)/', $params, $transport)
            ? strtolower((string)$transport[1]) : 'udp';
        if ($registeredTransport === 'udp' && $primary !== '' && $primary !== 'udp') {
            return trim((string)$contact[1], '[]') . ':' . (int)$contact[2];
        }
        return $ext;
    }

    private function driverOf(array $phone, string $ext, int $siteId): string
    {
        $driver = strtolower(trim((string)($phone['extension_driver'] ?? '')));
        if (in_array($driver, EndpointProfile::DRIVERS, true)) { return $driver; }
        try {
            $device = $this->ucm->getBoundDevice($ext, $siteId);
            $driver = strtolower(trim((string)($device['tech'] ?? '')));
        } catch (\Throwable $e) {
            $driver = '';
        }
        if (in_array($driver, EndpointProfile::DRIVERS, true)) { return $driver; }
        return strtolower((string)($phone['driver'] ?? '')) === 'pjsip' ? 'pjsip' : 'sip';
    }



    private function factory(array $phone, SiteConnection $conn, string $family): array

    {

        if ($family === 'cisco_xml' || $family === 'cisco_cnf') {

            return [

                'ok' => false,

                'message' => 'Factory reset is not available remotely for Cisco SIP-firmware phones. '

                    . 'Reset at the handset: Settings → Admin Settings → Reset Settings.',

            ];

        }

        if ($family === 'grandstream') {

            return [

                'ok' => false,

                'message' => 'Grandstream factory reset requires HTTP access to the device web UI on the voice VLAN.',

            ];

        }

        if ($family === 'yealink') {

            return [

                'ok' => false,

                'message' => 'Yealink factory reset requires HTTP access to the device web UI on the voice VLAN.',

            ];

        }

        return ['ok' => false, 'message' => 'Factory reset not supported for this device type.'];

    }



    private function bumpLegacySync(int $siteId, bool $forceReboot = false): void

    {

        if (!class_exists('\\FreePBX\\modules\\Ucmprovisioner\\TftpStore')) {

            throw new \RuntimeException('TFTP store is unavailable; legacy sync was not published');

        }

        $store = new TftpStore($this->ucm);

        $store->bumpLegacySync($siteId);

    }



    private function familyOf(array $phone): string

    {

        if (!empty($phone['family'])) {

            return (string) $phone['family'];

        }

        if (!empty($phone['model_id'])) {

            $model = $this->ucm->getModel((int) $phone['model_id']);

            if ($model && !empty($model['family'])) {

                return (string) $model['family'];

            }

        }

        $vendor = strtolower((string) ($phone['vendor'] ?? ''));

        return match ($vendor) {

            'yealink' => 'yealink',

            'grandstream' => 'grandstream',

            default => 'cisco_xml',

        };

    }

}
