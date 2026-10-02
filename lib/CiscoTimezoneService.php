<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Inspect individual Cisco timezone runtime files placed on a site's TFTP volume.
 *
 * Operators may upload extracted files through Firmware or TFTP Browser. This
 * service identifies their architecture role and validates usable payloads; it
 * does not require or parse a vendor installer archive.
 */
final class CiscoTimezoneService
{
    public const FILES = [
        'tzupdater.ver' => 'version',
        'j9-tzdata.jar' => 'j9-payload',
        'tzupdater.jar' => 'java-payload',
        'TzDataCSV.csv' => 'csv-payload',
        'tzdb.dat' => 'database',
    ];

    public const PROFILES = ModelCatalog::CISCO_TIMEZONE_PROFILES;

    /**
     * @param string[] $names Exact basenames present at TFTP root.
     * @param callable(string):string|false $read Bounded file reader.
     * @return array<string,mixed>
     */
    public static function inspect(array $names, callable $read): array
    {
        $present = [];
        foreach ($names as $name) {
            $name = basename(str_replace('\\', '/', trim((string)$name)));
            if ($name !== '' && $name !== '.' && $name !== '..') {
                $present[$name] = true;
            }
        }
        $folded = [];
        foreach (array_keys($present) as $name) {
            $folded[strtolower($name)][] = $name;
        }

        $files = [];
        $issues = [];
        $contents = [];
        foreach (self::FILES as $expected => $role) {
            $actual = isset($present[$expected]) ? $expected : null;
            if ($actual === null && !empty($folded[strtolower($expected)])) {
                $actual = (string)$folded[strtolower($expected)][0];
                $issues[] = [
                    'code'=>'case_mismatch',
                    'message'=>"$expected has incorrect case on the TFTP server: $actual",
                ];
            }
            if ($actual === null) {
                $issues[] = ['code'=>'missing_file', 'message'=>"Missing $expected"];
                $files[] = ['name'=>$expected, 'role'=>$role, 'present'=>false];
                continue;
            }
            $bytes = $read($actual);
            if (!is_string($bytes)) {
                $issues[] = ['code'=>'unreadable_file', 'message'=>"Cannot read $actual"];
                $files[] = ['name'=>$expected, 'actual_name'=>$actual, 'role'=>$role, 'present'=>true, 'readable'=>false];
                continue;
            }
            $contents[$expected] = $bytes;
            $runtime = in_array($role,['j9-payload','java-payload','csv-payload'],true) ? self::runtimeValidation($expected,$bytes) : ['valid'=>true,'message'=>'Metadata file'];
            if (!$runtime['valid']) { $issues[]=['code'=>'invalid_container','message'=>$expected . ': ' . $runtime['message']]; }
            $files[] = [
                'name'=>$expected,
                'actual_name'=>$actual,
                'role'=>$role,
                'present'=>true,
                'readable'=>true,
                'container_valid'=>$runtime['valid'],
                'container_message'=>$runtime['message'],
                'exact_case'=>$actual === $expected,
                'size'=>strlen($bytes),
                'sha256'=>hash('sha256', $bytes),
            ];
        }

        $servedVersion = trim((string)($contents['tzupdater.ver'] ?? ''));
        if ($servedVersion !== '' && str_contains($servedVersion, "\n")) {
            $servedVersion = trim((string)strtok($servedVersion, "\r\n"));
        }
        $declaredVersion = $servedVersion;
        if ($servedVersion === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $servedVersion) !== 1) {
            if ($servedVersion !== '') {
                $issues[] = ['code'=>'invalid_version', 'message'=>'tzupdater.ver does not contain one safe version value'];
            }
            $servedVersion = '';
        }

        $versionEvidence = [];
        foreach ($contents as $name => $bytes) {
            foreach (self::versionEvidence((string)$name, (string)$bytes) as $evidence) {
                $versionEvidence[] = $evidence;
            }
        }
        $reliableVersions = [];
        foreach ($versionEvidence as $evidence) {
            if (in_array((string)($evidence['confidence'] ?? ''), ['authoritative','declared'], true)) {
                $reliableVersions[] = strtolower((string)$evidence['version']);
            }
        }
        $reliableVersions = array_values(array_unique(array_filter($reliableVersions)));
        sort($reliableVersions, SORT_STRING);
        $servedIana = '';
        if (preg_match('/((?:20)\d{2}[a-z])/i', $servedVersion, $match)) {
            $servedIana = strtolower((string)$match[1]);
        }
        // Cisco's post-2021a COPs can intentionally contain different releases
        // for different phone payloads.  Cross-profile variance is therefore
        // evidence to report, not proof that the set is corrupt.  The served
        // release is independently corroborated only when one payload or tzdb
        // exposes the same release.
        $releaseVariance = count($reliableVersions) > 1;
        $ianaVersion = $servedIana !== '' && in_array($servedIana, $reliableVersions, true)
            ? $servedIana
            : (count($reliableVersions) === 1 ? $reliableVersions[0] : '');
        if ($releaseVariance) {
            $issues[] = [
                'code'=>'payload_release_variance',
                'message'=>'Cisco family payloads expose different IANA releases: ' . implode(', ', $reliableVersions),
            ];
        }

        // The XML version stamp only has to change when the selected bytes do.
        // Prefer real payload evidence, otherwise use a deterministic hash
        // stamp. This keeps opaque EOL payloads usable without asking an
        // operator to invent tzupdater.ver by hand.
        $generatedVersion = false;
        if ($servedVersion === '') {
            if ($ianaVersion !== '') {
                $servedVersion = $ianaVersion;
            } else {
                $payloadHashes = [];
                foreach ($files as $file) {
                    if (in_array((string)($file['role'] ?? ''), ['j9-payload','java-payload','csv-payload'], true)
                        && preg_match('/^[a-f0-9]{64}$/D', (string)($file['sha256'] ?? '')) === 1) {
                        $payloadHashes[] = (string)$file['sha256'];
                    }
                }
                if ($payloadHashes) {
                    sort($payloadHashes, SORT_STRING);
                    $servedVersion = 'ucmp-' . substr(hash('sha256', implode("\0", $payloadHashes)), 0, 12);
                }
            }
            $generatedVersion = $servedVersion !== '';
            if ($generatedVersion) {
                $issues[] = [
                    'code'=>'generated_version',
                    'message'=>'No usable tzupdater.ver was supplied; the module derived a stable phone-facing version from the payload bytes.',
                ];
            }
        }
        if ($servedIana === '' && preg_match('/((?:20)\d{2}[a-z])/i', $servedVersion, $match)) {
            $servedIana = strtolower((string)$match[1]);
        }
        $canGenerateVersion = $generatedVersion;
        $compatibility = [];
        foreach (self::PROFILES as $profile => $definition) {
            $payload = (string)$definition['payload'];
            $payloadFile = null;
            foreach ($files as $file) {
                if (($file['name'] ?? '') === $payload) {
                    $payloadFile = $file;
                    break;
                }
            }
            $payloadReady = !empty($payloadFile['present'])
                && !empty($payloadFile['readable']) && !empty($payloadFile['container_valid']);
            $payloadVersions = [];
            foreach ($versionEvidence as $evidence) {
                if (strcasecmp((string)($evidence['file'] ?? ''), $payload) !== 0
                    || !in_array((string)($evidence['confidence'] ?? ''), ['authoritative','declared'], true)) {
                    continue;
                }
                $payloadVersions[] = strtolower((string)$evidence['version']);
            }
            $payloadVersions = array_values(array_unique(array_filter($payloadVersions)));
            sort($payloadVersions, SORT_STRING);
            $actualVersion = count($payloadVersions) === 1 ? $payloadVersions[0] : '';
            $versionConflict = count($payloadVersions) > 1;
            $versionMismatch = $servedIana !== '' && $actualVersion !== '' && $servedIana !== $actualVersion;
            $legacyFrozen = $profile === 'cisco-79xx-8961-99x1'
                && $versionMismatch
                && strcmp($servedIana, '2021a') >= 0
                && strcmp($actualVersion, $servedIana) < 0;
            if ($versionConflict) {
                $issues[] = [
                    'code'=>'payload_version_conflict',
                    'profile'=>$profile,
                    'payload'=>$payload,
                    'message'=>"$payload contains conflicting IANA releases: " . implode(', ', $payloadVersions),
                ];
            } elseif ($legacyFrozen) {
                $issues[] = [
                    'code'=>'legacy_payload_frozen',
                    'profile'=>$profile,
                    'payload'=>$payload,
                    'message'=>"$payload contains $actualVersion while tzupdater.ver advertises $servedIana; Cisco stopped updating this legacy Java-phone payload from the 2021a COP onward",
                ];
            } elseif ($versionMismatch) {
                $issues[] = [
                    'code'=>'version_mismatch',
                    'profile'=>$profile,
                    'payload'=>$payload,
                    'message'=>"tzupdater.ver advertises $servedIana but $payload contains $actualVersion",
                ];
            }
            if (!$payloadReady) {
                $status = 'incomplete';
            } elseif ($versionConflict) {
                $status = 'mismatch';
            } elseif ($legacyFrozen) {
                $status = 'legacy_frozen';
            } elseif ($versionMismatch) {
                $status = 'mismatch';
            } elseif ($actualVersion === '') {
                $status = 'unverified';
            } elseif ($servedVersion !== '') {
                $status = 'complete';
            } else {
                $status = 'derivable';
            }
            // Configuration usability is deliberately profile-scoped.  Cisco
            // payloads do not all expose a parseable IANA marker; presence of
            // the exact requested payload plus a safe phone-facing version is
            // enough to keep that family configured, but it is still reported
            // as unverified rather than promoted to complete.
            $configurationUsable = $payloadReady
                && $servedVersion !== ''
                && !$versionConflict
                && (!$versionMismatch || $legacyFrozen);
            $missing = [];
            if (!$payloadReady) { $missing[] = $payload; }
            $compatibility[$profile] = [
                'profile'=>$profile,
                'label'=>(string)$definition['label'],
                'architecture'=>(string)($definition['architecture'] ?? ''),
                'payload'=>$payload,
                'models'=>array_values((array)($definition['models'] ?? [])),
                'firmware_profiles'=>array_values((array)($definition['firmware_profiles'] ?? [])),
                'advertised_version'=>$servedVersion,
                'advertised_iana_version'=>$servedIana,
                'actual_version'=>$actualVersion,
                'version_evidence'=>$payloadVersions,
                'required_files'=>[$payload],
                'version_companion'=>'tzupdater.ver',
                'version_generated'=>$generatedVersion,
                'status'=>$status,
                'selectable'=>$configurationUsable,
                'importable'=>$configurationUsable || $status === 'derivable',
                'configuration_usable'=>$configurationUsable,
                'legacy_frozen'=>$legacyFrozen,
                'support_note'=>$legacyFrozen
                    ? 'Cisco stopped refreshing tzupdater.jar for these unsupported legacy phones from the 2021a COP onward.'
                    : '',
                'version_inspection'=>$actualVersion !== '' ? 'verified' : 'unavailable',
                'container_valid'=>!empty($payloadFile['container_valid']),
                'signature_status'=>'unverified',
                'missing'=>$missing,
            ];
        }

        $codes = array_column($issues, 'code');
        // Only contradictory versions inside one payload make the bytes
        // internally unsafe.  An older profile beside usable newer profiles is
        // retained as a partial exact set so rollback and site sync remain
        // available without advertising that older payload to its phones.
        $mixed = in_array('payload_version_conflict', $codes, true);
        $completeProfiles = array_keys(array_filter(
            $compatibility,
            static fn(array $profile): bool => $profile['status'] === 'complete'
        ));
        $derivableProfiles = array_keys(array_filter(
            $compatibility,
            static fn(array $profile): bool => $profile['status'] === 'derivable'
        ));
        $unverifiedProfiles = array_keys(array_filter(
            $compatibility,
            static fn(array $profile): bool => $profile['status'] === 'unverified'
        ));
        $usableProfiles = array_keys(array_filter(
            $compatibility,
            static fn(array $profile): bool => !empty($profile['configuration_usable'])
        ));
        $status = $mixed
            ? 'mixed'
            : (count($usableProfiles) === count(self::PROFILES)
                ? 'complete'
                : ($usableProfiles
                    ? 'partial'
                    : ($derivableProfiles ? 'derivable' : ($unverifiedProfiles ? 'unverified' : 'incomplete'))));
        $fingerprint = $servedVersion !== '' ? self::fingerprint($files) : '';
        $retainable = in_array($status, ['complete','partial'], true) && $usableProfiles !== [];
        $captureAllowed = $retainable || ($canGenerateVersion && $usableProfiles !== []);
        $captureable = $servedVersion !== ''
            && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
            && $usableProfiles !== [];

        return [
            'ok'=>true,
            'status'=>$status,
            // These aggregate flags summarize independent profile results; a
            // caller still has to select the row for its exact model payload.
            'selectable'=>$usableProfiles !== [],
            'importable'=>$usableProfiles !== [] || $derivableProfiles !== [],
            'retainable'=>$retainable,
            'capture_allowed'=>$captureAllowed,
            'configuration_usable'=>$usableProfiles !== [],
            'captureable'=>$captureable,
            'quarantine_required'=>$captureable && !$retainable,
            'served_version'=>$servedVersion,
            'iana_version'=>$ianaVersion,
            'derived_version'=>$servedVersion !== '' ? $servedVersion : $ianaVersion,
            'version_source'=>$generatedVersion
                ? ($ianaVersion !== '' ? 'payload metadata' : 'content fingerprint')
                : ($declaredVersion !== '' ? 'tzupdater.ver' : ''),
            'can_generate_version'=>$canGenerateVersion,
            'needs_version_file'=>$generatedVersion && empty($contents['tzupdater.ver']),
            'fingerprint'=>$fingerprint,
            'files'=>$files,
            'issues'=>$issues,
            'required_files'=>array_keys(self::FILES),
            'payloads'=>array_map(static fn(array $profile): string => (string)$profile['payload'], self::PROFILES),
            'compatibility'=>$compatibility,
            'complete_profiles'=>$completeProfiles,
            'derivable_profiles'=>$derivableProfiles,
            'unverified_profiles'=>$unverifiedProfiles,
            'usable_profiles'=>$usableProfiles,
            'legacy_frozen_profiles'=>array_keys(array_filter(
                $compatibility,
                static fn(array $profile): bool => !empty($profile['legacy_frozen'])
            )),
            'release_variance'=>$releaseVariance,
            'version_evidence'=>$versionEvidence,
        ];
    }

    /** Structural plausibility is separate from version evidence and vendor authenticity. */
    public static function runtimeValidation(string $filename, string $bytes): array
    {
        $invalid=static fn(string $message): array => ['valid'=>false,'message'=>$message];
        if ($bytes==='' || strlen($bytes)>16777216) { return $invalid('Runtime payload is empty or exceeds 16 MiB'); }
        if (strtolower($filename)==='tzdatacsv.csv') {
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$bytes) || preg_match('//u',$bytes)!==1) { return $invalid('Timezone CSV is not valid text'); }
            $lines=preg_split('/\r\n|\n|\r/',trim($bytes));
            $lines=array_values(array_filter($lines,static fn(string $line): bool => trim($line)!=='' && !str_starts_with(ltrim($line),'#')));
            if (count($lines)<3 || count($lines)>20000 || trim($lines[0])!=='Table:TypeTimeZone') { return $invalid('Timezone CSV table marker or data rows are missing'); }
            $header=str_getcsv($lines[1],',','"','');
            if ($header!==['Moniker','TZname','Offset','DSTStart','DSTend','DSTBias','PreTZname']) { return $invalid('Timezone CSV column header is invalid'); }
            foreach (array_slice($lines,2) as $line) {
                $row=str_getcsv($line,',','"','');
                if (count($row)!==7 || trim($row[0])==='' || trim($row[1])==='' || !preg_match('/^-?\d+$/D',$row[2])) { return $invalid('Timezone CSV contains a malformed timezone row'); }
            }
            return ['valid'=>true,'message'=>'Cisco timezone CSV table structure verified; vendor authenticity unverified'];
        }
        if (!in_array(strtolower($filename),['tzupdater.jar','j9-tzdata.jar'],true) || !class_exists(\ZipArchive::class)) { return $invalid('Runtime JAR inspection is unavailable'); }
        $valid=self::runtimeZip($bytes,0);
        return $valid ? ['valid'=>true,'message'=>'Timezone JAR and zone-data container verified; vendor authenticity unverified'] : $invalid('JAR is not a complete timezone runtime container');
    }

    private static function runtimeZip(string $bytes, int $depth): bool
    {
        if (!str_starts_with($bytes,"PK\x03\x04")) { return false; }
        $path=tempnam(sys_get_temp_dir(),'ucmp-tzcheck-');
        if ($path===false) { return false; }
        $zip=new \ZipArchive(); $opened=false;
        try {
            if (!@chmod($path,0600) || file_put_contents($path,$bytes)!==strlen($bytes) || $zip->open($path,\ZipArchive::CHECKCONS)!==true) { return false; }
            $opened=true;
            if ($zip->numFiles<1 || $zip->numFiles>2048) { return false; }
            $runtime=false; $total=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i); if (!is_array($stat)) { return false; }
                $name=(string)$stat['name']; $size=(int)$stat['size']; $total+=$size;
                if ($size>16777216 || $total>33554432 || str_contains($name,'..') || str_starts_with($name,'/') || str_contains($name,'\\') || !empty($stat['encryption_method'])) { return false; }
                if (str_ends_with($name,'/')) { continue; }
                $data=$zip->getFromIndex($i); if (!is_string($data) || strlen($data)!==$size || sprintf('%u',crc32($data))!==sprintf('%u',$stat['crc'])) { return false; }
                if ($depth===0 && preg_match('#^(?:data/tzdata[^/]*\.zip|zi\.zip)$#iD',$name)) { $runtime=self::runtimeZip($data,1)||$runtime; }
                if ($depth===1 && $size>=8 && (str_starts_with($data,'javazi') || str_starts_with($data,'TZif'))) { $runtime=true; }
            }
            return $runtime;
        } finally { if ($opened) { $zip->close(); } @unlink($path); }
    }

    /** @param array<int,array<string,mixed>> $files */
    public static function fingerprint(array $files): string
    {
        $parts = [];
        foreach ($files as $file) {
            $name = (string)($file['name'] ?? '');
            $sha256 = strtolower((string)($file['sha256'] ?? ''));
            if (array_key_exists($name, self::FILES) && preg_match('/^[a-f0-9]{64}$/D', $sha256) === 1) {
                $parts[$name] = $sha256;
            }
        }
        if (!$parts) { return ''; }
        ksort($parts, SORT_STRING);
        $canonical = '';
        foreach ($parts as $name => $sha256) {
            $canonical .= $name . "\0" . $sha256 . "\n";
        }
        return hash('sha256', $canonical);
    }

    /** @return array<int,array{version:string,file:string,source:string,confidence:string}> */
    private static function versionEvidence(string $filename, string $bytes): array
    {
        $evidence = [];
        $add = static function (string $version, string $source, string $confidence) use (&$evidence, $filename): void {
            $version = strtolower($version);
            if (preg_match('/^20\d{2}[a-z]$/D', $version) !== 1) { return; }
            $key = $version . "\0" . $source . "\0" . $confidence;
            $evidence[$key] = [
                'version'=>$version,
                'file'=>$filename,
                'source'=>$source,
                'confidence'=>$confidence,
            ];
        };

        if (strcasecmp($filename, 'tzdb.dat') === 0) {
            if (preg_match('/(?<![A-Za-z0-9])(20\d{2}[a-z])(?![A-Za-z0-9])/i', $bytes, $match)) {
                $add((string)$match[1], 'tzdb header', 'authoritative');
            }
        }

        if (in_array(strtolower($filename), ['tzupdater.jar','j9-tzdata.jar','tzdatacsv.csv'], true)
            && preg_match_all('/(?:^|[^A-Za-z0-9])tzdata[-_.]?(20\d{2}[a-z])(?:[^A-Za-z0-9]|$)/i', $bytes, $matches)) {
            foreach ($matches[1] as $version) {
                $add((string)$version, 'embedded payload release', 'authoritative');
            }
        }

        $declaredPattern = '/(?:tzdata|olson|tzdb)[ _.-]*(?:release|version)\s*[:=]\s*(20\d{2}[a-z])/i';
        if (preg_match_all($declaredPattern, $bytes, $matches)) {
            foreach ($matches[1] as $version) { $add((string)$version, 'declared metadata', 'declared'); }
        }
        if (preg_match_all('/ciscocm\.dst-(?:updater|tzdata)[._-](20\d{2}[a-z])/i', $bytes, $matches)) {
            foreach ($matches[1] as $version) { $add((string)$version, 'Cisco package metadata', 'declared'); }
        }
        if (preg_match_all('/(?<![A-Za-z0-9])(20\d{2}[a-z])(?![A-Za-z0-9])/i', $bytes, $matches)) {
            foreach ($matches[1] as $version) { $add((string)$version, 'embedded string', 'hint'); }
        }

        if (str_starts_with($bytes, "PK") && class_exists(\ZipArchive::class)) {
            $temporary = tempnam(sys_get_temp_dir(), 'ucmp-tz-');
            if ($temporary !== false) {
                try {
                    if (file_put_contents($temporary, $bytes) === strlen($bytes)) {
                        $zip = new \ZipArchive();
                        if ($zip->open($temporary) === true) {
                            $limit = min(512, $zip->numFiles);
                            for ($index = 0; $index < $limit; $index++) {
                                $stat = $zip->statIndex($index);
                                if (!is_array($stat) || (int)($stat['size'] ?? 0) > 1024 * 1024) { continue; }
                                $name = (string)($stat['name'] ?? '');
                                $addFrom = $zip->getFromIndex($index, 0, \ZipArchive::FL_UNCHANGED);
                                if (is_string($addFrom) && preg_match_all($declaredPattern, $addFrom, $matches)) {
                                    foreach ($matches[1] as $version) {
                                        $add((string)$version, 'JAR entry ' . substr($name, 0, 96), 'declared');
                                    }
                                }
                                if (preg_match_all('/(?:^|[^A-Za-z0-9])tzdata(20\d{2}[a-z])(?:[^A-Za-z0-9]|$)/i', $name, $matches)) {
                                    foreach ($matches[1] as $version) {
                                        $add((string)$version, 'JAR embedded release ' . substr($name, 0, 96), 'authoritative');
                                    }
                                }
                            }
                            $zip->close();
                        }
                    }
                } finally {
                    @unlink($temporary);
                }
            }
        }

        return array_values($evidence);
    }
}
