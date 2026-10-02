<?php
/**
 * UCM Provisioner — install hook.
 *
 * Tables are auto-created from module.xml <database>. Here we seed the model
 * catalog and default settings, ensure a "local" site row, and perform the
 * initial discovery import of existing CHAN_SIP/PJSIP extensions into ucmp_phones.
 *
 * Runs in the FreePBX framework scope (\FreePBX::Database() available).
 */

if (!defined('FREEPBX_IS_AUTH')) {
    if (!class_exists('\\FreePBX')) {
        // Not in FreePBX context; nothing to do.
        return;
    }
}

// Do not assign to `$db`: FreePBX's module installer owns that global and still
// uses it after this hook returns. Replacing it with PDO corrupts moduleadmin's
// version-update query quoting.
/** @var \PDO $ucmpDb */
$ucmpDb = \FreePBX::Database();

// module.xml owns the schema. Keep an idempotent fallback here because this
// hook also runs during module upgrades and later inventory writers must never
// observe a half-upgraded installation.
require_once __DIR__ . '/lib/ManagedAssetRegistry.php';
\FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry::ensureSchema($ucmpDb);
require_once __DIR__ . '/lib/BuildPlanner.php';
require_once __DIR__ . '/lib/BuildTransactionService.php';
\FreePBX\modules\Ucmprovisioner\BuildTransactionService::ensureSchema($ucmpDb);

/* ------------------------------------------------------------------ */
/* 1. Seed the phone model catalog (idempotent). Shared with           */
/*    Ucmprovisioner::ensureModelCatalog() so future catalog additions */
/*    reach already-installed sites without a reinstall.               */
/* ------------------------------------------------------------------ */
require_once __DIR__ . '/lib/ModelCatalog.php';
$modelsAdded = \FreePBX\modules\Ucmprovisioner\ModelCatalog::seed($ucmpDb);

/* ------------------------------------------------------------------ */
/* 2. Define provisioning defaults. Existing global rows are retained */
/*    until the site-local migration below can assign them safely.    */
/* ------------------------------------------------------------------ */
// Snapshot the former shared endpoint defaults before this release seeds any
// new keys.  On upgrade they become the starting point for both vendor
// classes; on a fresh install the snapshot is empty and the modern defaults
// below apply unchanged.
$legacyEndpointRows = $ucmpDb->query(
    "SELECT site_id,skey,sval FROM ucmp_settings WHERE skey IN ('transport','sip_port')"
)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
$defaults = [
    'transport'              => 'udp',
    'sip_port'               => '5060',
    'cisco_transport'        => 'udp',
    'cisco_sip_port'         => '5060',
    'cisco_cnf_transport'    => 'udp',
    'cisco_cnf_sip_port'     => '5060',
    'non_cisco_driver'       => 'pjsip',
    'non_cisco_transport'    => 'udp',
    'non_cisco_sip_port'     => '5160',
    'phone_rebuild_schedule_enabled' => '0',
    'phone_rebuild_interval_minutes' => '1440',
    'phone_rebuild_schedule_json' => '{"mode":"daily","interval_minutes":1440,"time":"03:00","weekdays":[1,2,3,4,5,6,7]}',
    'phone_rebuild_reboot' => '0',
    'proxy'                  => 'USECALLMANAGER',
    'date_template'          => 'D/M/Y',
    'ntp_server'             => '',
    'timezone'               => '',
    'cisco_xml_timezone'     => 'GMT Standard/Daylight Time',
    'cisco_xml_olson_timezone' => '',
    'cisco_legacy_timezone'  => 'GMT',
    'cisco_legacy_dst_auto_adjust' => '1',
    'yealink_timezone'       => '+0',
    'dial_template_file'     => '',
    'voicemail_number'       => '*97',
    'device_security'        => '1',
    'blf_mesh_schedule_enabled' => '0',
    'blf_mesh_interval_minutes' => '60',
    'models_catalog_version' => (string) \FreePBX\modules\Ucmprovisioner\ModelCatalog::CATALOG_VERSION,
];

// An earlier schema seeded this 60-minute schedule independently from the
// installation's existing BLF interval. Repair only that exact untouched seed.
// A schedule saved by an operator updates both fields and cannot match this
// conflict signature.
$seededMeshSchedule = '{"mode":"interval","interval_minutes":60,"time":"02:00","weekdays":[1,2,3,4,5,6,7]}';
$meshSettingRows = $ucmpDb->query(
    "SELECT id,site_id,skey,sval FROM ucmp_settings
      WHERE skey IN ('blf_mesh_interval_minutes','blf_mesh_schedule_json')"
)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
$meshSettingsByScope = [];
foreach ($meshSettingRows as $settingRow) {
    $scopeKey = $settingRow['site_id'] === null ? 'global' : 'site:' . (int)$settingRow['site_id'];
    $meshSettingsByScope[$scopeKey][(string)$settingRow['skey']] = $settingRow;
}
$repairMeshSchedule = $ucmpDb->prepare('UPDATE ucmp_settings SET sval=? WHERE id=? AND sval=?');
foreach ($meshSettingsByScope as $scopeSettings) {
    $intervalRow = $scopeSettings['blf_mesh_interval_minutes'] ?? null;
    $scheduleRow = $scopeSettings['blf_mesh_schedule_json'] ?? null;
    if (!$intervalRow || !$scheduleRow || (string)$scheduleRow['sval'] !== $seededMeshSchedule) { continue; }
    $legacyInterval = max(15, min(10080, (int)$intervalRow['sval']));
    if ($legacyInterval === 60) { continue; }
    $repairedSchedule = json_encode([
        'mode'=>'interval', 'interval_minutes'=>$legacyInterval, 'time'=>'02:00',
        'weekdays'=>[1,2,3,4,5,6,7],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $repairMeshSchedule->execute([$repairedSchedule, (int)$scheduleRow['id'], $seededMeshSchedule]);
}
$haveSetting = $ucmpDb->prepare("SELECT id FROM ucmp_settings WHERE site_id IS NULL AND skey = ?");
$addSetting  = $ucmpDb->prepare("INSERT INTO ucmp_settings (site_id, skey, sval) VALUES (NULL, ?, ?)");
foreach ($defaults as $k => $v) {
    $haveSetting->execute([$k]);
    if (!$haveSetting->fetch()) {
        $addSetting->execute([$k, $v]);
    }
}

/* ------------------------------------------------------------------ */
/* 3. Ensure a "local" site row for this PBX.                          */
/* ------------------------------------------------------------------ */
$localId = null;
$row = $ucmpDb->query("SELECT id FROM ucmp_sites WHERE is_local = 1 LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
if ($row) {
    $localId = (int) $row['id'];
} else {
    $hostname = trim((string)gethostname()) ?: 'local';
    $addressOutput = trim((string)@shell_exec('/usr/bin/timeout --signal=KILL 2 /bin/hostname -I 2>/dev/null'));
    $primaryIp = '';
    foreach (preg_split('/\s+/', $addressOutput, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $primaryIp = $address;
            break;
        }
    }
    $st = $ucmpDb->prepare(
        "INSERT INTO ucmp_sites (site_key, name, is_local, enabled, pbx_addr, pbx_sip_port, pbx_host, pbx_ssh_port, pbx_agent_user, tftp_root_path, tftp_bind_path, tftp_mode, pbx_tftp_service_host, pbx_tftp_root_path, pbx_tftp_bind_path, pbx_tftp_daemon)
         VALUES (?, ?, 1, 1, ?, 5160, ?, 22, 'ucmp-pbx', '/tftpboot', '/opt/tftp-server/tftpboot', 'pbx', ?, '/tftpboot', '/srv/tftp/tftpboot', 'tftpd-hpa')"
    );
    $siteKey = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $hostname ?: 'local')) . '-' . bin2hex(random_bytes(4));
    $st->execute([$siteKey, $hostname ?: 'local', $primaryIp, $primaryIp, $primaryIp]);
    $localId = (int) $ucmpDb->lastInsertId();
}
foreach (['/var/lib/asterisk/ucmprovisioner','/var/lib/asterisk/ucmprovisioner/media','/var/lib/asterisk/ucmprovisioner/directory','/var/lib/asterisk/ucmprovisioner/api-nonces'] as $mediaPath) {
    if (!is_dir($mediaPath) && !@mkdir($mediaPath, 0770, true) && !is_dir($mediaPath)) {
        out("UCM Provisioner: could not create required data directory $mediaPath");
        continue;
    }
    if (!@chown($mediaPath, 'asterisk') || !@chgrp($mediaPath, 'asterisk') || !@chmod($mediaPath, 02770)) {
        out("UCM Provisioner: could not fully apply asterisk ownership to $mediaPath");
    }
}

// Only the settings document and its empty stable lock need service-group read
// access for constrained SSH synchronization. AD reader secrets stay owner-only.
require_once __DIR__ . '/lib/WebPhonebook.php';
(new \FreePBX\modules\Ucmprovisioner\WebPhonebook())->installPolicyPermissions();

// FreePBX deliberately denies unauthenticated HTTP access below /admin/modules.
// Phones still need two narrow read-only endpoints, so install tiny web-root wrappers;
// all validation and file access remain in the signed module code they require.
$webRoot = dirname(__DIR__, 3);
$publicEndpoints = [
    // Separately configured, network-restricted employee web directory.
    'ucmprovisioner-phonebook.php' => __DIR__ . '/web_phonebook.php',
    'ucmprovisioner-directory.php' => __DIR__ . '/directory.php',
    // Query-free PATH_INFO endpoint for Cisco 7940/7960 firmware. Those clients
    // corrupt ampersand separators in External Directory service URLs.
    'ucmp-d.php' => __DIR__ . '/directory.php',
    // Query-free, token-protected phone service catalog for legacy and current phones.
    'ucmp-s.php' => __DIR__ . '/phone_services.php',
    'ucmprovisioner-services.php' => __DIR__ . '/phone_services.php',
    // Query-free media endpoint. Required by the legacy Cisco HTTP client,
    // retained for every family so one managed URL grammar is shown in the UI.
    'ucmp-m.php' => __DIR__ . '/media_asset.php',
    'ucmprovisioner-media.php' => __DIR__ . '/media_asset.php',
    'ucmprovisioner-api.php' => __DIR__ . '/api.php',
];
foreach ($publicEndpoints as $publicName => $target) {
    $publicPath = $webRoot . '/' . $publicName;
    $marker = 'UCM Provisioner public endpoint';
    $existing = is_file($publicPath) ? (string)@file_get_contents($publicPath) : '';
    if ($existing !== '' && strpos($existing, $marker) === false) {
        out("UCM Provisioner: refusing to replace unrelated web-root file $publicPath");
        continue;
    }
    $wrapper = "<?php\n/** $marker — generated during module install. */\nrequire " . var_export($target, true) . ";\n";
    if (@file_put_contents($publicPath, $wrapper, LOCK_EX) === false) {
        out("UCM Provisioner: could not install phone-facing endpoint $publicPath");
        continue;
    }
    @chown($publicPath, 'asterisk'); @chgrp($publicPath, 'asterisk'); @chmod($publicPath, 0644);
}
$cron = "* * * * * asterisk /usr/bin/php /var/www/html/admin/modules/ucmprovisioner/cli_ad_sync.php >/dev/null 2>&1\n"
    . "* * * * * asterisk /usr/bin/php /var/www/html/admin/modules/ucmprovisioner/cli_mesh_sync.php >/dev/null 2>&1\n"
    . "* * * * * asterisk /usr/bin/php /var/www/html/admin/modules/ucmprovisioner/cli_phone_rebuild.php >/dev/null 2>&1\n"
    . "* * * * * asterisk /usr/bin/php /var/www/html/admin/modules/ucmprovisioner/cli_media_sync.php >/dev/null 2>&1\n";
$cronPath = '/etc/cron.d/ucmprovisioner-ad'; // Historical filename; the file schedules all module jobs.
if (@file_put_contents($cronPath, $cron, LOCK_EX) !== strlen($cron)) {
    out("UCM Provisioner: could not install the scheduled-job file $cronPath");
} elseif (!@chmod($cronPath, 0644)) {
    out("UCM Provisioner: could not secure the scheduled-job file $cronPath");
}

/* ------------------------------------------------------------------ */
/* 3a. Enrol root-owned, argument-free privileged helpers.             */
/* ------------------------------------------------------------------ */
$ucmpInstallRootFile = static function (string $source, string $target, int $mode): void {
    $content = @file_get_contents($source);
    if (!is_string($content)) { throw new \RuntimeException("Unable to read $source"); }
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { throw new \RuntimeException("Unable to create $dir"); }
    $dirStat = @lstat($dir);
    if (!is_array($dirStat) || is_link($dir) || (((int)$dirStat['mode'] & 0170000) !== 0040000)
        || (int)($dirStat['uid'] ?? -1) !== 0 || (((int)$dirStat['mode'] & 0022) !== 0)
    ) { throw new \RuntimeException("Unsafe privileged-helper directory $dir"); }
    $tmp = @tempnam($dir, '.ucmp-install-');
    if (!$tmp || @file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) { if ($tmp) { @unlink($tmp); } throw new \RuntimeException("Unable to stage $target"); }
    if (!@chown($tmp, 'root') || !@chgrp($tmp, 'root') || !@chmod($tmp, $mode)) {
        @unlink($tmp); throw new \RuntimeException("Unable to secure staged helper $target");
    }
    if (!@rename($tmp, $target)) { @unlink($tmp); throw new \RuntimeException("Unable to install $target"); }
    clearstatcache(true, $target);
    $installed = @lstat($target);
    if (!is_array($installed) || is_link($target) || (((int)$installed['mode'] & 0170000) !== 0100000)
        || (int)($installed['uid'] ?? -1) !== 0 || (int)($installed['gid'] ?? -1) !== 0
        || (((int)$installed['mode'] & 07777) !== $mode)
    ) { throw new \RuntimeException("Installed helper failed ownership or mode verification: $target"); }
};
$ucmpEnsureRootDirectory = static function (string $path, int $mode): void {
    $existing = @lstat($path);
    if ($existing === false) {
        if (!@mkdir($path, $mode, false)) { throw new \RuntimeException("Unable to create $path"); }
    } elseif (is_link($path) || (((int)$existing['mode'] & 0170000) !== 0040000) || (int)($existing['uid'] ?? -1) !== 0) {
        throw new \RuntimeException("Refusing unsafe privileged state directory $path");
    }
    if (!@chown($path, 'root') || !@chgrp($path, 'root') || !@chmod($path, $mode)) {
        throw new \RuntimeException("Unable to secure privileged state directory $path");
    }
    clearstatcache(true, $path);
    $verified = @lstat($path);
    if (!is_array($verified) || is_link($path) || (((int)$verified['mode'] & 0170000) !== 0040000)
        || (int)($verified['uid'] ?? -1) !== 0 || (int)($verified['gid'] ?? -1) !== 0
        || (((int)$verified['mode'] & 07777) !== $mode)
    ) { throw new \RuntimeException("Privileged state directory verification failed: $path"); }
};
$ucmpRun = static function (array $command): array {
    $pipes = [];
    $proc = @proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, ['LC_ALL'=>'C','PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin'], ['bypass_shell'=>true]);
    if (!is_resource($proc)) { return [127, '', 'unable to start command']; }
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($proc), (string)$stdout, (string)$stderr];
};
$ucmpInstallSudoers = static function (string $content, string $target) use ($ucmpRun): void {
    $dir = dirname($target);
    $tmp = $dir . '/.' . basename($target) . '.tmp';
    clearstatcache(true, $tmp);
    $stale = @lstat($tmp);
    if ($stale !== false) {
        if (is_link($tmp) || (((int)$stale['mode'] & 0170000) !== 0100000) || (int)($stale['uid'] ?? -1) !== 0 || !@unlink($tmp)) {
            throw new \RuntimeException("Refusing unsafe stale sudoers staging file $tmp");
        }
    }
    if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) {
        @unlink($tmp); throw new \RuntimeException("Unable to stage sudoers policy $target");
    }
    if (!@chown($tmp, 'root') || !@chgrp($tmp, 'root') || !@chmod($tmp, 0440)) {
        @unlink($tmp); throw new \RuntimeException("Unable to secure sudoers policy $target");
    }
    [$visudoCode,, $visudoError] = $ucmpRun(['/usr/sbin/visudo','-cf',$tmp]);
    if ($visudoCode !== 0) { @unlink($tmp); throw new \RuntimeException('Invalid sudoers policy: ' . trim($visudoError)); }
    if (!@rename($tmp, $target)) { @unlink($tmp); throw new \RuntimeException("Unable to install sudoers policy $target"); }
    clearstatcache(true, $target);
    $installed = @lstat($target);
    if (!is_array($installed) || is_link($target) || (((int)$installed['mode'] & 0170000) !== 0100000)
        || (int)($installed['uid'] ?? -1) !== 0 || (int)($installed['gid'] ?? -1) !== 0
        || (((int)$installed['mode'] & 07777) !== 0440)
    ) { throw new \RuntimeException("Installed sudoers policy failed verification: $target"); }
};
if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
    try {
        $ucmpInstallRootFile(__DIR__ . '/lib/Fail2banHelper.php', '/usr/local/libexec/ucmp-fail2ban-helper.php', 0644);
        $ucmpInstallRootFile(__DIR__ . '/resources/ucmp-fail2ban', '/usr/local/sbin/ucmp-fail2ban', 0755);
        $sudoers = "# UCM Provisioner Fail2ban helper; helper accepts JSON on stdin and no argv.\nasterisk ALL=(root) NOPASSWD: /usr/local/sbin/ucmp-fail2ban \"\"\n";
        $ucmpInstallSudoers($sudoers, '/etc/sudoers.d/ucmp-fail2ban');
        @unlink('/etc/sudoers.d/ucmprovisioner-fail2ban'); // Remove the obsolete helper-rule filename used by early installations.
        out('UCM Provisioner: enrolled the monitor-and-unban-only Fail2ban helper.');
    } catch (\Throwable $e) {
        out('UCM Provisioner: Fail2ban helper enrollment failed: ' . $e->getMessage());
    }
    try {
        $ucmpInstallRootFile(__DIR__ . '/lib/PatchCatalog.php', '/usr/local/libexec/PatchCatalog.php', 0644);
        $ucmpInstallRootFile(__DIR__ . '/lib/AsteriskVersionManager.php', '/usr/local/libexec/AsteriskVersionManager.php', 0644);
        $ucmpInstallRootFile(__DIR__ . '/lib/PatchManagerHelper.php', '/usr/local/libexec/PatchManagerHelper.php', 0644);
        $ucmpInstallRootFile(__DIR__ . '/resources/ucmp-patch-manager', '/usr/local/sbin/ucmp-patch-manager', 0755);
        $ucmpEnsureRootDirectory('/var/lib/ucmp-patch-manager', 0700);
        $patchDataParent = '/var/lib/asterisk/ucmprovisioner';
        if (!is_dir($patchDataParent) && !@mkdir($patchDataParent, 0770, true) && !is_dir($patchDataParent)) {
            throw new \RuntimeException("Unable to create $patchDataParent");
        }
        @chown($patchDataParent, 'asterisk'); @chgrp($patchDataParent, 'asterisk'); @chmod($patchDataParent, 02770);
        foreach (['/var/lib/asterisk/ucmprovisioner/asterisk-bundles'] as $patchDataDirectory) {
            if (!is_dir($patchDataDirectory) && !@mkdir($patchDataDirectory, 0750, true) && !is_dir($patchDataDirectory)) {
                throw new \RuntimeException("Unable to create $patchDataDirectory");
            }
            @chown($patchDataDirectory, 'root'); @chgrp($patchDataDirectory, 'asterisk'); @chmod($patchDataDirectory, 0750);
        }
        $patchIncomingDirectory = '/var/lib/asterisk/ucmprovisioner/asterisk-incoming';
        if (!is_dir($patchIncomingDirectory) && !@mkdir($patchIncomingDirectory, 0770, true) && !is_dir($patchIncomingDirectory)) {
            throw new \RuntimeException("Unable to create $patchIncomingDirectory");
        }
        @chown($patchIncomingDirectory, 'asterisk'); @chgrp($patchIncomingDirectory, 'asterisk'); @chmod($patchIncomingDirectory, 0770);
        $patchSudoers = @file_get_contents(__DIR__ . '/resources/ucmp-patch-manager.sudoers');
        if (!is_string($patchSudoers) || $patchSudoers === '') { throw new \RuntimeException('Unable to read Patch Manager sudoers policy'); }
        $ucmpInstallSudoers($patchSudoers, '/etc/sudoers.d/ucmp-patch-manager');
        @unlink('/usr/local/libexec/ucmp-patch-catalog.php');
        @unlink('/usr/local/libexec/ucmp-patch-manager-helper.php');
        out('UCM Provisioner: enrolled the FreePBX 17 Asterisk source-build and version-switch helper.');
    } catch (\Throwable $e) {
        out('UCM Provisioner: Patch Manager helper enrollment failed: ' . $e->getMessage());
    }
} else {
    out('UCM Provisioner: module hook was not root; privileged helper enrollment was skipped. Run fwconsole ma install ucmprovisioner as root.');
}

/* Every PBX owns one complete local settings set. Nothing is inherited */
/* from the controller or another site.                                 */
$localDefaults = $defaults;
$legacyEndpointByScope = [];
foreach ($legacyEndpointRows as $legacyEndpointRow) {
    $scope = $legacyEndpointRow['site_id'] === null ? 'global' : 'site:' . (int)$legacyEndpointRow['site_id'];
    $legacyEndpointByScope[$scope][(string)$legacyEndpointRow['skey']] = (string)$legacyEndpointRow['sval'];
}
$legacyEndpoint = $legacyEndpointByScope['site:' . $localId] ?? ($legacyEndpointByScope['global'] ?? []);
if ($legacyEndpoint !== []) {
    $legacyTransport = strtolower(trim((string)($legacyEndpoint['transport'] ?? 'udp')));
    if (!in_array($legacyTransport, ['udp','tcp','tls'], true)) { $legacyTransport = 'udp'; }
    $legacyPort = (int)($legacyEndpoint['sip_port'] ?? 5060);
    if ($legacyPort < 1 || $legacyPort > 65535) { $legacyPort = 5060; }
    $localDefaults['cisco_transport'] = $legacyTransport;
    $localDefaults['cisco_sip_port'] = (string)$legacyPort;
    // 7940/7960 SIP firmware cannot signal over TCP/TLS. Keep its own
    // explicit route even when the wider Cisco fleet inherited TCP.
    $localDefaults['cisco_cnf_transport'] = 'udp';
    $localDefaults['cisco_cnf_sip_port'] = (string)$legacyPort;
    // The legacy shared route was CHAN_SIP-only. Preserve the complete route
    // on upgrade; PJSIP remains a fresh-install default and explicit choice.
    $localDefaults['non_cisco_driver'] = 'sip';
    $localDefaults['non_cisco_transport'] = $legacyTransport;
    $localDefaults['non_cisco_sip_port'] = (string)$legacyPort;
}
$haveLocalSetting = $ucmpDb->prepare("SELECT id FROM ucmp_settings WHERE site_id = ? AND skey = ?");
$addLocalSetting = $ucmpDb->prepare("INSERT INTO ucmp_settings (site_id, skey, sval) VALUES (?, ?, ?)");
foreach ($localDefaults as $k => $v) {
    $haveLocalSetting->execute([$localId, $k]);
    if (!$haveLocalSetting->fetch()) {
        $addLocalSetting->execute([$localId, $k, $v]);
    }
}

/* ------------------------------------------------------------------ */
/* 4. Credential storage: automatic, local and backup-verified.        */
/*    BMO install() runs first; the local site/defaults now exist.      */
/* ------------------------------------------------------------------ */
$ucmpCredentialUpgrade = \FreePBX::Ucmprovisioner()->upgradeCredentialStorage();
out('UCM Provisioner: ' . $ucmpCredentialUpgrade['message']);

/* ------------------------------------------------------------------ */
/* 5. Initial discovery import: existing SIP/PJSIP extensions, with    */
/*    vendor/model/MAC identification from live registration data      */
/*    (Useragent / Device Name) via the Discovery engine. Falls back   */
/*    to a bare import (extension + description only) if the module's  */
/*    own BMO accessor isn't ready yet at install time.                 */
/* ------------------------------------------------------------------ */
$discoveryResult = null;
try {
    $ucm = \FreePBX::Ucmprovisioner();
    if (is_object($ucm)) {
        $discoveryResult = $ucm->discover($localId);
    }
} catch (\Throwable $e) {
    $discoveryResult = null;
}

if (!$discoveryResult || empty($discoveryResult['ok'])) {
    $devs = [];
    try {
        $core = \FreePBX::Core();
        if (is_object($core) && method_exists($core, 'getAllDevicesByType')) {
            foreach (['sip','pjsip'] as $driver) {
                foreach ((array) $core->getAllDevicesByType($driver) as $dev) {
                    if (!empty($dev['id'])) {
                        $devs[] = ['id'=>$dev['id'], 'description'=>$dev['description'] ?? '', 'driver'=>$driver];
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $devs = [];
    }
    $havePhone = $ucmpDb->prepare("SELECT id FROM ucmp_phones WHERE extension = ? AND (site_id = ? OR site_id IS NULL)");
    $addPhone  = $ucmpDb->prepare(
        "INSERT INTO ucmp_phones (site_id, extension, label, vendor, status, driver, transport, sip_port, updated)
         VALUES (?, ?, ?, NULL, 'discovered', ?, 'inherit', 0, ?)"
    );
    $now = time();
    foreach ($devs as $d) {
        $ext = (string) $d['id'];
        if ($ext === '') {
            continue;
        }
        $havePhone->execute([$ext, $localId]);
        if (!$havePhone->fetch()) {
            $addPhone->execute([$localId, $ext, (string) ($d['description'] ?? ''), (string)($d['driver'] ?? 'inherit'), $now]);
        }
    }
    out("UCM Provisioner: seeded " . count(\FreePBX\modules\Ucmprovisioner\ModelCatalog::CATALOG) . " models (+$modelsAdded new), imported " . count($devs) . " SIP/PJSIP extensions (bare import — model identification pending first Discover click).");
} else {
    out("UCM Provisioner: seeded " . count(\FreePBX\modules\Ucmprovisioner\ModelCatalog::CATALOG) . " models (+$modelsAdded new). " . $discoveryResult['message']);
}
