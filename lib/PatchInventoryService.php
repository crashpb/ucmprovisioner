<?php
/**
 * Read-only Asterisk / USECALLMANAGER inventory.
 *
 * Keeping this class read-only makes it safe to expose through RPC and cached
 * site snapshots. Privileged build/switch actions stay in the local helper.
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/PatchCatalog.php';

final class PatchInventoryService
{
    public const UPSTREAM_PAGE = PatchCatalog::UPSTREAM_PAGE;
    public const MANIFEST_DATE = PatchCatalog::MANIFEST_DATE;

    /**
     * Explicit FreePBX 17 Asterisk package inventory. Do not replace this with
     * an asterisk* query: sounds and unrelated packages are not one ABI set.
     */
    private const ASTERISK_PACKAGE_NAMES = [
        'asterisk-version-switch',
        'asterisk20',
        'asterisk20-addons',
        'asterisk20-addons-bluetooth',
        'asterisk20-addons-core',
        'asterisk20-addons-mysql',
        'asterisk20-addons-ooh323',
        'asterisk20-alsa',
        'asterisk20-configs',
        'asterisk20-core',
        'asterisk20-curl',
        'asterisk20-dahdi',
        'asterisk20-devel',
        'asterisk20-doc',
        'asterisk20-odbc',
        'asterisk20-ogg',
        'asterisk20-pgsql',
        'asterisk20-resample',
        'asterisk20-snmp',
        'asterisk20-speex',
        'asterisk20-sqlite3',
        'asterisk20-tds',
        'asterisk20-voicemail',
        'asterisk20-voicemail-imapstorage',
        'asterisk20-voicemail-odbcstorage',
        'asterisk20-flite',
        'asterisk20-g729',
        'asterisk20-res-digium-phone',
        'asterisk20.0-freepbx-asterisk-modules',
    ];

    /** @var null|callable */
    private $runner;

    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner;
    }

    /**
     * Exact upstream patch releases known to this module build.
     *
     * A patch is never offered for a merely similar Asterisk version. The
     * upstream filename embeds the required source version and must match it.
     */
    public static function candidates(): array
    {
        return PatchCatalog::entries();
    }

    public function inventory(): array
    {
        $versionOutput = $this->probe('asterisk_version', [
            '/usr/bin/timeout', '--signal=KILL', '5',
            '/usr/sbin/asterisk', '-rx', 'core show version',
        ]);
        $installedVersionOutput = $this->probe('installed_asterisk_version', [
            '/usr/sbin/asterisk', '-V',
        ]);
        $chanSipOutput = $this->probe('chan_sip', [
            '/usr/bin/timeout', '--signal=KILL', '5',
            '/usr/sbin/asterisk', '-rx', 'sip show settings',
        ]);
        $patchOutput = $this->probe('usecallmanager', [
            '/usr/bin/timeout', '--signal=KILL', '5',
            '/usr/sbin/asterisk', '-rx', 'core show application SIPCiscoPage',
        ]);
        $packageOutput = $this->probe('packages', array_merge(
            ['/usr/bin/dpkg-query', '-W', '-f=${binary:Package}\t${Version}\n'],
            self::ASTERISK_PACKAGE_NAMES
        ));
        $holdsOutput = $this->probe('holds', ['/usr/bin/apt-mark', 'showhold']);
        $policyOutput = $this->probe('apt_policy', array_merge(
            ['/usr/bin/apt-cache', 'policy'],
            self::ASTERISK_PACKAGE_NAMES
        ));
        $binaryPath = trim($this->probe('binary_path', [
            '/usr/bin/readlink', '-f', '/usr/sbin/asterisk',
        ]));
        $binaryHashOutput = $this->probe('binary_hash', [
            '/usr/bin/sha256sum', '/usr/sbin/asterisk',
        ]);

        $runningVersion = self::parseAsteriskVersion($versionOutput);
        $installedVersion = self::parseAsteriskVersion($installedVersionOutput);
        $version = $runningVersion !== '' ? $runningVersion : $installedVersion;
        $major = $version !== '' ? (int)explode('.', $version, 2)[0] : 0;
        $chanSipLoaded = self::chanSipLoaded($chanSipOutput);
        $patchDetected = self::patchDetected($patchOutput);
        $runtimeEligible = $version !== '' && $chanSipLoaded && $patchDetected;
        $packages = array_values(array_filter(
            self::parsePackages($packageOutput),
            static fn(array $package): bool => self::isInventoryPackageName((string)$package['name'])
        ));
        $holds = self::parseLines($holdsOutput);
        $asteriskPackages = array_column($packages, 'name');
        $heldAsteriskPackages = array_values(array_filter(
            $asteriskPackages,
            static fn(string $name): bool => self::containsPackageName($holds, $name)
        ));
        $versionSwitchHeld = self::containsPackageName($holds, 'asterisk-version-switch');
        $aptPolicy = self::parseAptPolicy($policyOutput);
        $policyByPackage = [];
        foreach ($aptPolicy as $policy) {
            $policyByPackage[self::basePackageName((string)$policy['name'])] = $policy;
        }
        $pinnedAsteriskPackages = [];
        foreach ($packages as $package) {
            $policy = $policyByPackage[self::basePackageName((string)$package['name'])] ?? null;
            if (
                $policy
                && !empty($policy['exact_version_pin'])
                && hash_equals((string)$package['version'], (string)$policy['installed'])
            ) {
                $pinnedAsteriskPackages[] = (string)$package['name'];
            }
        }
        $binaryHash = '';
        if (preg_match('/^([a-f0-9]{64})(?:\s|$)/i', trim($binaryHashOutput), $match)) {
            $binaryHash = strtolower($match[1]);
        }

        $candidate = null;
        foreach (self::candidates() as $known) {
            if ($known['asterisk_version'] === $version) {
                $candidate = $known;
                break;
            }
        }
        $assessment = self::assess($version, $major, $runtimeEligible, $candidate);
        $manager = $this->managerInventory();
        $managedBundles = is_array($manager['bundles'] ?? null) ? $manager['bundles'] : [];
        $managedIncoming = $this->incomingInventory();
        $packageRecipeGate = [
            'code'=>'SIMPLE_SOURCE_BUILD',
            'passed'=>true,
            'message'=>'FreePBX 17 source build uses the selected exact Asterisk archive and USECALLMANAGER patch.',
        ];
        $rollbackGate = [
            'code'=>$managedBundles ? 'MANAGED_BUNDLES_AVAILABLE' : 'CAPTURE_CURRENT_REQUIRED',
            'passed'=>(bool)$managedBundles,
            'message'=>$managedBundles
                ? count($managedBundles) . ' managed Asterisk bundle(s) are available.'
                : 'Capture the current installation before the first activation.',
        ];

        return [
            'captured_at' => time(),
            'manifest_date' => self::MANIFEST_DATE,
            'catalog_schema' => PatchCatalog::SCHEMA_VERSION,
            'catalog_digest' => PatchCatalog::digest(),
            'upstream_page' => self::UPSTREAM_PAGE,
            'asterisk_version' => $version,
            'running_version' => $runningVersion,
            'installed_version' => $installedVersion,
            'asterisk_version_output' => trim($versionOutput),
            'asterisk_major' => $major,
            'binary_path' => $binaryPath,
            'binary_sha256' => $binaryHash,
            'packages' => $packages,
            'held_packages' => $holds,
            'held_asterisk_packages' => $heldAsteriskPackages,
            'version_switch_held' => $versionSwitchHeld,
            'package_hold_detected' => $versionSwitchHeld || (bool)$heldAsteriskPackages,
            'apt_policy' => $aptPolicy,
            'pinned_asterisk_packages' => $pinnedAsteriskPackages,
            'exact_version_pin_detected' => (bool)$pinnedAsteriskPackages,
            'chan_sip_loaded' => $chanSipLoaded,
            'usecallmanager_detected' => $patchDetected,
            'runtime_eligible' => $runtimeEligible,
            'candidate' => $candidate,
            'assessment' => $assessment,
            'package_recipe_gate' => $packageRecipeGate,
            'rollback_gate' => $rollbackGate,
            'candidates' => self::candidates(),
            'managed_bundles'=>$managedBundles,
            'managed_incoming'=>$managedIncoming ?: (is_array($manager['incoming'] ?? null) ? $manager['incoming'] : []),
            'active_bundle_id'=>(string)($manager['active_bundle_id'] ?? ''),
            'pinned_bundle_id'=>(string)($manager['pinned_bundle_id'] ?? ''),
            'pin_protected'=>!empty($manager['pin_protected']),
            'pin_drift'=>!empty($manager['pin_drift']),
            'history'=>is_array($manager['history'] ?? null) ? $manager['history'] : [],
            'actions_enabled'=>true,
            'actions_note'=>'Compatible older releases remain selectable. Newer upstream availability is a warning, never an automatic blocker.',
        ];
    }

    public static function parseAsteriskVersion(string $output): string
    {
        return preg_match('/\bAsterisk\s+([0-9]+(?:\.[0-9]+){1,3}(?:[-+~][A-Za-z0-9._-]+)?)/i', $output, $match)
            ? $match[1]
            : '';
    }

    public static function parsePackages(string $output): array
    {
        $packages = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9+.-]*(?::[A-Za-z0-9_-]+)?)\s+(.+)$/', trim($line), $match)) {
                continue;
            }
            $packages[$match[1]] = ['name' => $match[1], 'version' => trim($match[2])];
        }
        ksort($packages, SORT_STRING);
        return array_values($packages);
    }

    /**
     * Parse apt-cache policy output without inferring the originating
     * preferences stanza. "exact_version_pin" means only that the exact
     * installed package/version currently has effective priority > 1000.
     */
    public static function parseAptPolicy(string $output): array
    {
        $policies = [];
        $current = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9+.-]*(?::[A-Za-z0-9_-]+)?):\s*$/', $line, $match)) {
                $current = $match[1];
                $policies[$current] = [
                    'name' => $current,
                    'installed' => '',
                    'candidate' => '',
                    'installed_priority' => null,
                    'exact_version_pin' => false,
                ];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^\s+Installed:\s+(\S.*)$/', $line, $match)) {
                $policies[$current]['installed'] = trim($match[1]) === '(none)' ? '' : trim($match[1]);
                continue;
            }
            if (preg_match('/^\s+Candidate:\s+(\S.*)$/', $line, $match)) {
                $policies[$current]['candidate'] = trim($match[1]) === '(none)' ? '' : trim($match[1]);
                continue;
            }
            if (
                $policies[$current]['installed'] !== ''
                && preg_match('/^\s*(?:\*{3}\s+)?(\S+)\s+(-?\d+)\s*$/', $line, $match)
                && hash_equals($policies[$current]['installed'], $match[1])
            ) {
                $policies[$current]['installed_priority'] = (int)$match[2];
            }
        }
        foreach ($policies as &$policy) {
            $policy['exact_version_pin'] = is_int($policy['installed_priority'])
                && $policy['installed_priority'] > 1000;
        }
        unset($policy);
        ksort($policies, SORT_STRING);
        return array_values($policies);
    }

    private static function parseLines(string $output): array
    {
        $lines = array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/\R/', trim($output)) ?: []
        ))));
        sort($lines, SORT_STRING);
        return $lines;
    }

    private static function chanSipLoaded(string $output): bool
    {
        return stripos($output, 'Global Settings:') !== false
            && !preg_match('/not registered|not found|no such|unknown command|unable to connect|connection refused/i', $output);
    }

    private static function patchDetected(string $output): bool
    {
        return stripos($output, 'SIPCiscoPage') !== false
            && !preg_match('/not registered|not found|no such|unknown command|unable to connect|connection refused/i', $output);
    }

    private static function assess(string $version, int $major, bool $runtimeEligible, ?array $candidate): array
    {
        if ($version === '') {
            return [
                'code' => 'runtime_unavailable',
                'severity' => 'danger',
                'compatible' => false,
                'message' => 'The Asterisk runtime version could not be read.',
            ];
        }
        if ($candidate && !empty($candidate['module_compatible'])) {
            $older = empty($candidate['recommended']);
            return [
                'code' => $runtimeEligible ? 'exact_supported_patched' : 'exact_supported_unpatched',
                'severity' => $runtimeEligible && !$older ? 'success' : 'warning',
                'compatible' => true,
                'production_installable' => !empty($candidate['production_installable']),
                'message' => $runtimeEligible
                    ? "Asterisk $version exactly matches a selectable UCM patch and the required runtime features are present."
                    : "Asterisk $version exactly matches a selectable UCM patch source, but the running process does not expose every UCM feature.",
            ];
        }
        if ($candidate) {
            return [
                'code' => 'upstream_candidate_module_blocked',
                'severity' => 'danger',
                'compatible' => false,
                'production_installable' => false,
                'message' => "Asterisk $version has an upstream USECALLMANAGER patch, but this module is not compatible with that driver grammar.",
            ];
        }
        if ($major === 20 && $runtimeEligible) {
            return [
                'code' => 'runtime_patched_untracked',
                'severity' => 'warning',
                'compatible' => true,
                'production_installable' => false,
                'message' => "Asterisk $version has the required runtime features, but it does not match the exact tracked patch release. Treat it as an existing untracked build until a verified build receipt exists.",
            ];
        }
        if ($major === 20) {
            return [
                'code' => 'version_without_exact_patch',
                'severity' => 'danger',
                'compatible' => false,
                'production_installable' => false,
                'message' => "Asterisk $version does not match one of the four selectable exact patch releases. It can still be captured as an existing untracked build.",
            ];
        }
        if ($major === 22) {
            return [
                'code' => 'asterisk_22_module_blocked',
                'severity' => 'danger',
                'compatible' => false,
                'production_installable' => false,
                'message' => "Asterisk $version uses the Asterisk 22 driver generation, which the current module does not support.",
            ];
        }
        return [
            'code' => 'unsupported_series',
            'severity' => 'danger',
            'compatible' => false,
            'production_installable' => false,
            'message' => "Asterisk $version is outside the module's tracked USECALLMANAGER compatibility matrix.",
        ];
    }

    private static function isInventoryPackageName(string $name): bool
    {
        return in_array(self::basePackageName($name), self::ASTERISK_PACKAGE_NAMES, true);
    }

    private static function basePackageName(string $name): string
    {
        return preg_replace('/:[A-Za-z0-9_-]+$/', '', $name) ?: $name;
    }

    private static function containsPackageName(array $names, string $package): bool
    {
        $package = self::basePackageName($package);
        foreach ($names as $name) {
            if (self::basePackageName((string)$name) === $package) {
                return true;
            }
        }
        return false;
    }

    private function probe(string $name, array $command): string
    {
        if ($this->runner) {
            return (string)($this->runner)($name, $command);
        }
        if (!function_exists('proc_open')) {
            return '';
        }
        $pipes = [];
        $process = @proc_open(
            $command,
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ['LC_ALL' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin'],
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return '';
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return $exit === 0 ? (string)$stdout : trim((string)$stdout . "\n" . (string)$stderr);
    }

    /** @return array<string,mixed> */
    private function managerInventory(): array
    {
        $path = '/var/lib/asterisk/ucmprovisioner/asterisk-bundles/inventory.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 1048576) { return []; }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /** @return array<int,array<string,mixed>> */
    private function incomingInventory(): array
    {
        $root = '/var/lib/asterisk/ucmprovisioner/asterisk-incoming';
        $items = [];
        foreach (glob($root . '/*.upload') ?: [] as $path) {
            $id = basename($path, '.upload');
            if (!preg_match('/^[a-f0-9]{32}$/D', $id) || is_link($path) || !is_file($path)) { continue; }
            $metaPath = $root . '/' . $id . '.json';
            $meta = [];
            if (is_file($metaPath) && !is_link($metaPath) && filesize($metaPath) <= 65536) {
                $decoded = json_decode((string)file_get_contents($metaPath), true);
                if (is_array($decoded) && !array_is_list($decoded)) { $meta = $decoded; }
            }
            $items[] = [
                'incoming_id'=>$id,
                'filename'=>(string)($meta['filename'] ?? 'staged-upload'),
                'declared_version'=>(string)($meta['declared_version'] ?? ''),
                'source_site'=>(string)($meta['source_site'] ?? ''),
                'size_bytes'=>(int)filesize($path),
                'sha256'=>hash_file('sha256', $path),
                'received_at'=>(int)($meta['received_at'] ?? filemtime($path)),
            ];
        }
        usort($items, static fn(array $left, array $right): int => $right['received_at'] <=> $left['received_at']);
        return $items;
    }
}
