<?php
/**
 * ExtensionBinder — edits secret/transport on an EXISTING chan_sip or PJSIP device using
 * only FreePBX's official Core API. No raw DB writes.
 *
 * FreePBX's `sip` table is a per-device keyword/value EAV store and Core's own
 * Core::addDevice() refuses to write over an existing device id. The sanctioned
 * pattern — used by FreePBX core itself in Core::changeDeviceTech() (their own
 * comment literally reads "delete then re add, insanity") — is:
 *   1. getDevice($id)        // merged devices + tech(sip) row as keyword=>value
 *   2. delDevice($id, true)  // editmode=true: keeps user/voicemail linkage
 *   3. addDevice($id, tech, settings, true)  // re-insert with edited values
 *
 * We never create or remove extensions — only ever called against an id that
 * already exists.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class ExtensionBinder
{
    private $ucm;
    private $recoveryRecorder;
    private array $lastRecovery = [];

    public function __construct($ucm = null, ?callable $recoveryRecorder = null)
    {
        $this->ucm = $ucm;
        $this->recoveryRecorder = $recoveryRecorder;
    }

    /** Safe PBX-side ceiling; individual phone families are validated by the caller. */
    public const MAX_SECRET_LEN = 256;

    /** Keys consumed directly by Core::addDevice() rather than passed to the tech driver. */
    private const CORE_KEYS = ['devicetype', 'user', 'description', 'emergency_cid', 'hint_override', 'dial', 'changecdriver'];

    /** Read the current SIP/PJSIP device (devices table + driver settings merged). */
    public function getDevice(string $ext): ?array
    {
        $core = $this->core();
        $dev = $core->getDevice($ext);
        if (empty($dev) || !in_array(strtolower((string)($dev['tech'] ?? '')), EndpointProfile::DRIVERS, true)) {
            return null;
        }
        return $dev;
    }

    /** List all SIP and PJSIP extensions known to FreePBX for the bind dropdown. */
    public function listEndpointExtensions(): array
    {
        $core = $this->core();
        if (!method_exists($core, 'getAllDevicesByType')) {
            return [];
        }
        $names = [];
        if ($this->ucm && method_exists($this->ucm, 'getDb')) {
            try {
                foreach ($this->ucm->getDb()->query('SELECT extension, name FROM users')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $names[(string)$row['extension']] = (string)($row['name'] ?? '');
                }
            } catch (\Throwable $e) {
                $names = [];
            }
        }
        $out = [];
        foreach (EndpointProfile::DRIVERS as $driver) {
            foreach ((array) $core->getAllDevicesByType($driver) as $d) {
                if (!empty($d['id'])) {
                    $id = (string)$d['id'];
                    $description = $names[$id] ?? (string)($d['description'] ?? '');
                    if ($description === '' && !$names) {
                        $user = (array)$core->getUser($id);
                        $description = (string)($user['name'] ?? $d['description'] ?? '');
                    }
                    $out[] = ['id'=>$id, 'description'=>$description, 'driver'=>$driver];
                }
            }
        }
        usort($out, static fn(array $a, array $b): int => strnatcasecmp((string)$a['id'], (string)$b['id']));
        return $out;
    }

    /** Backward-compatible method name used by older federation callers. */
    public function listSipExtensions(): array
    {
        return $this->listEndpointExtensions();
    }

    /** Read-only account/driver/listener gate used before any managed files are staged. */
    public static function deviceAcceptsTransport(array $device, string $driver, string $transport): bool
    {
        if (strtolower((string)($device['tech'] ?? '')) !== $driver) { return false; }
        $configured = strtolower(trim((string)($device['transport'] ?? '')));
        // chan_sip stores a comma-separated set of allowed transports. PJSIP
        // stores one named transport object and must retain exact matching.
        return $driver === 'sip'
            ? in_array(strtolower($transport), array_map('trim', explode(',', $configured)), true)
            : $configured === strtolower($transport);
    }

    public function preflightEndpoint(string $ext, string $transport, string $driver, int $port): array
    {
        $transport = strtolower(trim($transport));
        $driver = strtolower(trim($driver));
        try {
            $device = $this->getDevice($ext);
            if (!$device || strtolower((string)($device['tech'] ?? '')) !== $driver) {
                return ['ok'=>false, 'message'=>"Extension $ext is missing or its FreePBX driver does not match $driver"];
            }
            if (!in_array($transport, EndpointProfile::TRANSPORTS, true) || $port < 1 || $port > 65535) {
                return ['ok'=>false, 'message'=>'A valid SIP transport and listener port are required'];
            }
            if ($driver === 'pjsip') {
                $listener = $this->pjsipTransportId($transport, $port);
                return ['ok'=>$listener !== null, 'driver'=>$driver, 'listener'=>$listener,
                    'message'=>$listener !== null ? 'Account and PJSIP listener verified' : "No PJSIP $transport listener on port $port"];
            }
            if (!$this->ucm || !method_exists($this->ucm, 'siteConnection') || !method_exists($this->ucm, 'localSiteId')) {
                return ['ok'=>false, 'message'=>'Cannot verify the chan_sip listener in this context'];
            }
            $raw = (string)$this->ucm->siteConnection($this->ucm->localSiteId())->asterisk('sip show settings');
            $label = $transport === 'udp' ? 'UDP Bindaddress' : strtoupper($transport) . ' SIP Bindaddress';
            $ready = !preg_match('/not registered|not found|no such|unknown command|unable to connect|connection refused/i', $raw)
                && preg_match('/^\s*' . preg_quote($label, '/') . ':\s*\S+:' . $port . '\s*$/mi', $raw) === 1;
            return ['ok'=>$ready, 'driver'=>$driver, 'message'=>$ready
                ? 'Account and chan_sip listener verified' : "No verified chan_sip $transport listener on port $port"];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'message'=>'Endpoint preflight failed: ' . $e->getMessage()];
        }
    }

    /**
     * Update the FreePBX user name and its still-default device caller-ID. FreePBX 17
     * commonly leaves new chan_sip devices as `device <ext>` even after the user name
     * changes; that literal value is then what another site receives over a trunk.
     * Explicitly customized caller-ID values are preserved.
     */
    public function updateDisplayName(string $ext, string $displayName, string $mode = 'preserve_custom'): array
    {
        $displayName = trim($displayName);
        if ($displayName === '' || preg_match('/[\x00-\x1f\x7f]/', $displayName) || !in_array($mode, ['preserve_custom','managed'], true)) {
            return ['ok' => false, 'changed' => false, 'message' => 'Display name cannot be blank'];
        }
        $core = $this->core();
        $user = (array)$core->getUser($ext);
        if (!$user) {
            return ['ok' => false, 'changed' => false, 'message' => "Extension $ext is not an existing FreePBX user"];
        }
        $oldName = trim((string)($user['name'] ?? ''));
        $originalUser = $user;
        $originalDevice = $this->getDevice($ext);
        $device = $originalDevice;
        $user['name'] = $displayName;
        $messages = [];
        if ($device) {
            $currentCallerId = trim((string)($device['callerid'] ?? ''));
            if ($mode === 'managed' || $this->isDefaultCallerId($currentCallerId, $ext, $oldName)) {
                // Preserve an explicit number component, including an external DID.
                $number = preg_match('/<([^>]*)>\s*$/', $currentCallerId, $match) ? trim($match[1])
                    : (preg_match('/^\+?[0-9]+$/D',$currentCallerId) ? $currentCallerId : $ext);
                $device['callerid'] = $this->formatCallerId($displayName, $number);
                $description = trim((string)($device['description'] ?? ''));
                if ($mode === 'managed' || $description === '' || in_array(strtolower($description), ['device',strtolower($ext),strtolower($oldName)], true)) {
                    $device['description'] = $displayName;
                }
            } else { $messages[] = 'custom device caller-ID preserved'; }
        }
        $changed = !$this->recordsMatch($originalUser,$user) || ($device !== null && !$this->recordsMatch($originalDevice,$device));
        if (!$changed) { return ['ok'=>true,'changed'=>false,'callerid_checked'=>true,'status'=>'verified','message'=>'Already current']; }
        try { $receipt = $this->beginRecovery($ext,$originalUser,$originalDevice); }
        catch (\Throwable) { return ['ok'=>false,'changed'=>false,'callerid_checked'=>false,'status'=>'preflight_failed','message'=>'Could not preserve encrypted Core recovery data; no edit attempted']; }
        try {
            if ($oldName !== $displayName) {
                $this->replaceUser($ext,$user);
                $messages[] = 'display name updated';
            }
            if ($device !== null && !$this->recordsMatch($originalDevice,$device)) {
                $this->replaceDevice($ext,$device);
                $messages[] = 'internal device caller name updated';
            }
        } catch (\Throwable $e) {
            $recovery = $this->restoreOriginal($ext,$originalUser,$originalDevice,$receipt);
            return ['ok'=>false,'changed'=>$recovery['status'] === 'recovery_required','callerid_checked'=>false,'message'=>'FreePBX Core identity edit failed; ' . $recovery['status']] + $recovery;
        }
        $completed = $this->finishRecovery($receipt);
        if (isset($completed['reporting_error'])) { $messages[] = $completed['reporting_error']; }
        return ['ok'=>true,'changed'=>true,'callerid_checked'=>true,'message'=>implode('; ',$messages)] + $completed;
    }

    /**
     * Update secret and/or transport on an existing SIP/PJSIP device, preserving
     * every other field exactly as-is.
     *
     * @return array{ok:bool,message:string}
     */
    public function updateSecretTransport(
        string $ext,
        ?string $secret,
        ?string $transport,
        int $maxSecretLen = self::MAX_SECRET_LEN,
        string $limitLabel = 'selected phone',
        ?string $driver = null,
        ?int $port = null
    ): array
    {
        $dev = $this->getDevice($ext);
        if (!$dev) {
            return ['ok' => false, 'changed'=>false, 'message' => "Extension $ext is not an existing SIP/PJSIP device"];
        }
        $actualDriver = strtolower((string)($dev['tech'] ?? ''));
        $driver = strtolower(trim((string)($driver ?? $actualDriver)));
        if (!in_array($driver, EndpointProfile::DRIVERS, true) || $driver !== $actualDriver) {
            return [
                'ok'=>false,
                'changed'=>false,
                'message'=>sprintf(
                    'Extension %s is %s in FreePBX; the requested phone profile is %s. Change the extension driver in FreePBX or select a matching phone override.',
                    $ext,
                    EndpointProfile::driverLabel($actualDriver),
                    EndpointProfile::driverLabel($driver)
                ),
            ];
        }
        $changed = false;
        if ($secret !== null && $secret !== '') {
            if (strlen($secret) > $maxSecretLen) {
                return ['ok' => false, 'message' => sprintf(
                    'Secret is %d characters; %s supports at most %d. Use a shorter secret.',
                    strlen($secret),
                    $limitLabel,
                    $maxSecretLen
                )];
            }
            if (!hash_equals((string)($dev['secret'] ?? ''), $secret)) {
                $dev['secret'] = $secret;
                $changed = true;
            }
        }
        if ($transport !== null && $transport !== '') {
            $transport = strtolower(trim($transport));
            if (!in_array($transport, EndpointProfile::TRANSPORTS, true)) {
                return ['ok'=>false, 'changed'=>false, 'message'=>"Unsupported SIP transport $transport"];
            }
            $deviceTransport = $transport;
            if ($driver === 'pjsip') {
                $port = (int)$port;
                if ($port < 1 || $port > 65535) {
                    return ['ok'=>false, 'changed'=>false, 'message'=>'PJSIP transport resolution requires a valid listening port'];
                }
                $deviceTransport = $this->pjsipTransportId($transport, $port) ?? '';
                if ($deviceTransport === '') {
                    return [
                        'ok'=>false,
                        'changed'=>false,
                        'message'=>sprintf(
                            'No FreePBX PJSIP %s transport is listening on port %d. Correct SIP Settings or this site/phone endpoint default first.',
                            strtoupper($transport),
                            $port
                        ),
                    ];
                }
            }
            if (strtolower(trim((string)($dev['transport'] ?? ''))) !== strtolower($deviceTransport)) {
                $dev['transport'] = $deviceTransport;
                $changed = true;
            }
        }

        if (!$changed) {
            return ['ok'=>true, 'changed'=>false, 'message'=>"Extension $ext endpoint settings are already current", 'driver'=>$driver];
        }

        try {
            $ok = $this->saveDevice($ext, $dev);
        } catch (\Throwable $e) {
            return ['ok'=>false,'changed'=>($this->lastRecovery['status'] ?? '') === 'recovery_required','message'=>'FreePBX Core endpoint edit failed'] + $this->lastRecovery;
        }
        if (!$ok) {
            return ['ok' => false, 'message' => 'Core::addDevice() returned false'];
        }
        $message = "Updated $ext (secret/transport) via FreePBX Core API";
        if (isset($this->lastRecovery['reporting_error'])) { $message .= '; ' . $this->lastRecovery['reporting_error']; }
        return ['ok'=>true, 'changed'=>true, 'message'=>$message, 'driver'=>$driver] + $this->lastRecovery;
    }

    /** Persist a fully merged getDevice() record through FreePBX Core's edit workflow. */
    private function saveDevice(string $ext, array $dev): bool
    {
        $this->lastRecovery = ['status'=>'preflight_failed'];
        $original = $this->getDevice($ext);
        if (!$original) { throw new \RuntimeException('Existing endpoint disappeared before edit'); }
        $receipt = $this->beginRecovery($ext,null,$original);
        try {
            $this->replaceDevice($ext,$dev);
        } catch (\Throwable $e) {
            $this->lastRecovery = $this->restoreOriginal($ext,null,$original,$receipt);
            throw $e;
        }
        $this->lastRecovery = $this->finishRecovery($receipt);
        return true;
    }

    /** A completion-receipt failure must not reverse an already verified Core edit. */
    private function finishRecovery(array $receipt): array
    {
        $result = ['status'=>'verified','recovery_id'=>$receipt['recovery_id']];
        $warnings = [];
        try { if (function_exists('needreload')) { needreload(); } }
        catch (\Throwable) { $warnings[] = 'Core edit verified; the FreePBX reload marker could not be saved.'; }
        try { $this->recordRecovery($receipt + ['status'=>'verified']); }
        catch (\Throwable) {
            $warnings[] = 'Core edit verified; completion receipt could not be saved. The prepared recovery evidence was retained.';
        }
        if ($warnings) { $result['reporting_error'] = implode(' ', $warnings); }
        return $result;
    }

    private function replaceDevice(string $ext, array $dev): void
    {
        $tech = (string)($dev['tech'] ?? '');
        if (!in_array($tech, EndpointProfile::DRIVERS, true)) {
            throw new \RuntimeException("Extension $ext is not an existing SIP/PJSIP device");
        }
        $settings = [];
        $flag = 2; // matches FreePBX core's own kvArrayifyDeviceValues() convention
        foreach ($dev as $key => $value) {
            if ($key === 'id' || $key === 'tech') {
                continue;
            }
            $settings[$key] = ['value' => $value, 'flag' => $flag++];
        }
        // Required keys Core::addDevice() expects to exist even if unchanged.
        foreach (self::CORE_KEYS as $k) {
            if (!isset($settings[$k])) {
                $settings[$k] = ['value' => $dev[$k] ?? '', 'flag' => 0];
            }
        }

        $core = $this->core();
        if ($core->getDevice($ext) && $core->delDevice($ext, true) === false) { throw new \RuntimeException('Core device removal failed'); }
        $ok = $core->addDevice($ext, $tech, $settings, true);
        if ($ok === false || !$this->recordsMatch((array)$core->getDevice($ext),$dev)) { throw new \RuntimeException('Core device write could not be verified'); }
    }

    private function replaceUser(string $ext,array $user): void
    {
        $core = $this->core();
        if ($core->getUser($ext) && $core->delUser($ext,true) === false) { throw new \RuntimeException('Core user removal failed'); }
        if ($core->addUser($ext,$user,true) === false || !$this->recordsMatch((array)$core->getUser($ext),$user)) { throw new \RuntimeException('Core user write could not be verified'); }
    }

    private function recordsMatch(?array $actual,array $expected): bool
    {
        if ($actual === null) { return false; }
        foreach ($expected as $key=>$value) {
            if (!array_key_exists($key,$actual) || $actual[$key] != $value) { return false; }
        }
        return true;
    }

    private function beginRecovery(string $ext,?array $user,?array $device): array
    {
        $receipt = ['recovery_id'=>bin2hex(random_bytes(16)),'extension'=>$ext,'created_at'=>time(),'original_user'=>$user,'original_device'=>$device];
        $this->recordRecovery($receipt + ['status'=>'prepared']);
        return $receipt;
    }

    private function recordRecovery(array $receipt): void
    {
        if ($this->recoveryRecorder) { ($this->recoveryRecorder)($receipt); return; }
        if (!$this->ucm || !method_exists($this->ucm,'enc')) { throw new \RuntimeException('Encrypted Core recovery storage is unavailable; no edit was attempted'); }
        $root = '/var/lib/asterisk/ucmprovisioner/core-recovery';
        if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) { throw new \RuntimeException('Core recovery directory unavailable'); }
        $payload = $this->ucm->enc(json_encode($receipt,JSON_THROW_ON_ERROR));
        if (!is_string($payload) || $payload === '') { throw new \RuntimeException('Could not encrypt Core recovery record'); }
        $path = $root . '/' . $receipt['recovery_id'] . '.json';
        $temporary = tempnam($root,'.receipt-');
        $public = json_encode(['recovery_id'=>$receipt['recovery_id'],'status'=>$receipt['status'],'updated_at'=>time(),'encrypted_original'=>$payload],JSON_THROW_ON_ERROR);
        if (!$temporary || file_put_contents($temporary,$public,LOCK_EX) !== strlen($public) || !chmod($temporary,0600) || !rename($temporary,$path)) {
            if ($temporary) { @unlink($temporary); }
            throw new \RuntimeException('Could not persist Core recovery evidence');
        }
    }

    private function restoreOriginal(string $ext,?array $user,?array $device,array $receipt): array
    {
        $failed = [];
        foreach (['user'=>$user,'device'=>$device] as $kind=>$original) {
            if ($original === null) { continue; }
            try {
                $current = $kind === 'user' ? (array)$this->core()->getUser($ext) : (array)$this->core()->getDevice($ext);
                if (!$this->recordsMatch($current,$original)) {
                    if ($kind === 'user') { $this->replaceUser($ext,$original); }
                    else { $this->replaceDevice($ext,$original); }
                }
            } catch (\Throwable) { $failed[] = $kind; }
        }
        try { if (function_exists('needreload')) { needreload(); } }
        catch (\Throwable) { $failed[] = 'reload_marker'; }
        $result = ['status'=>$failed ? 'recovery_required' : 'rolled_back','recovery_id'=>$receipt['recovery_id'],'unresolved'=>$failed];
        try { $this->recordRecovery($receipt + $result); }
        catch (\Throwable) { $result['status'] = 'recovery_required'; $result['unresolved'][] = 'receipt'; }
        return $result;
    }

    /** Return the exact Asterisk PJSIP transport object matching protocol and port. */
    private function pjsipTransportId(string $protocol, int $port): ?string
    {
        if (!$this->ucm || !method_exists($this->ucm, 'siteConnection') || !method_exists($this->ucm, 'localSiteId')) {
            return null;
        }
        $raw = $this->ucm->siteConnection($this->ucm->localSiteId())->asterisk('pjsip show transports');
        foreach (preg_split('/\r?\n/', (string)$raw) as $line) {
            if (!preg_match('/^\s*Transport:\s+(\S+)\s+(\S+)\s+\d+\s+\d+\s+.+:(\d+)\s*$/i', $line, $match)) {
                continue;
            }
            if (strtolower($match[2]) === $protocol && (int)$match[3] === $port) {
                return (string)$match[1];
            }
        }
        return null;
    }

    private function isDefaultCallerId(string $callerId, string $ext, string $oldName): bool
    {
        if ($callerId === '') { return true; }
        $name = $callerId;
        $number = '';
        if (preg_match('/^(.*?)\s*<([^>]*)>\s*$/', $callerId, $match)) {
            $name = trim($match[1], " \t\n\r\0\x0B\"");
            $number = trim($match[2]);
        }
        if ($number !== '' && $number !== $ext) { return false; }
        $name = strtolower(trim($name));
        $defaults = array_unique(array_filter(['', 'device', strtolower($ext), strtolower(trim($oldName))], static fn($value) => $value !== ''));
        return $name === '' || in_array($name, $defaults, true);
    }

    private function formatCallerId(string $displayName, string $ext): string
    {
        $safeName = trim(str_replace(['"', '<', '>'], '', $displayName));
        return '"' . $safeName . '" <' . $ext . '>';
    }

    private function core()
    {
        // FreePBX::Core() is dispatched via __callStatic, so method_exists() can't see it —
        // Attempt the Core call and surface its actual failure to the caller.
        if (!class_exists('\\FreePBX')) {
            throw new \Exception('FreePBX Core API not available in this context');
        }
        $core = \FreePBX::Core();
        if (!is_object($core)) {
            throw new \Exception('FreePBX::Core() did not return a usable object');
        }
        return $core;
    }
}
