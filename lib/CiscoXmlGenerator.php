<?php
/**
 * CiscoXmlGenerator — builds SEP<MAC>.cnf.xml for newer Cisco phones
 * (78xx/88xx/79x1) under the USECALLMANAGER patch.
 *
 * Implements the Cisco XML SIP schema used by USECALLMANAGER-capable firmware.
 * proxy=USECALLMANAGER, registerWithProxy, transportLayerProtocol, line
 * featureID 9, BLF featureID 21 (mask 1), service URIs, etc.
 *
 * Pure/no-side-effect: returns a filename + XML string.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/ModelCatalog.php';
require_once __DIR__ . '/DeterministicVersionStamp.php';
require_once __DIR__ . '/DialTemplateGenerator.php';

class CiscoXmlGenerator
{
    private const TRANSPORT = ['udp' => 2, 'tcp' => 1, 'tls' => 3];

    /**
     * @param array $ctx resolvePhone() output (phone, device, model, settings, lines)
     * @return array{filename:?string,xml:string,warnings:array}
     */
    public function generate(array $ctx): array
    {
        $phone    = $ctx['phone'];
        $settings = $ctx['settings'];
        $lines    = $ctx['lines'];
        $model    = $ctx['model'] ?? null;
        $warnings = [];
        foreach (['_cisco_wallpaper_warning','_cisco_locale_warning','_cisco_tzdata_warning','_identity_warning'] as $warningKey) {
            $warning = trim((string)($settings[$warningKey] ?? ''));
            if ($warning !== '') { $warnings[] = $warning; }
        }
        foreach ($lines as $line) {
            if ((int)($line['featureId'] ?? 9) === 9
                && (int)($line['lineIndex'] ?? 1) === 1
                && strlen((string)($line['secret'] ?? '')) > 30) {
                throw new \InvalidArgumentException('Cisco XML SIP authentication secret exceeds 30 characters; correct the primary extension secret before generating its configuration.');
            }
        }

        $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', (string) ($phone['mac'] ?? '')));
        $filename = ($mac !== '' && strlen($mac) === 12) ? "SEP{$mac}.cnf.xml" : null;
        if ($filename === null) {
            $warnings[] = 'No valid MAC on phone; cannot determine SEP filename.';
        }

        $pbx       = (string) ($settings['pbx_addr'] ?? '');
        $sipPort   = (string) ($settings['sip_port'] ?? '5060');
        $transport = self::TRANSPORT[strtolower((string) ($settings['transport'] ?? 'udp'))] ?? 2;

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $device = $doc->createElement('device');
        $doc->appendChild($device);

        $add = function (\DOMElement $parent, string $name, $value = null) use ($doc) {
            $el = $doc->createElement($name);
            if ($value !== null && $value !== '') {
                $el->appendChild($doc->createTextNode((string) $value));
            }
            $parent->appendChild($el);
            return $el;
        };

        $add($device, 'fullConfig', 'true');
        $add($device, 'deviceProtocol', 'SIP');
        $modelName = strtoupper((string)($model['model'] ?? ''));
        $labelPolicy = ModelCatalog::identityLabelPolicy('Cisco', $modelName) ?? [
            'phone_max_chars'=>12, 'phone_spaces'=>true,
        ];
        $phoneLabel = (string)($phone['label'] ?? '');
        if (empty($labelPolicy['phone_spaces']) && preg_match('/\s/u', $phoneLabel)) {
            $compacted = (string)preg_replace('/\s+/u', '', $phoneLabel);
            $warnings[] = sprintf(
                'Cisco XML %s firmware does not accept spaces in phoneLabel; "%s" was emitted as "%s".',
                $modelName ?: '7900-series',
                $phoneLabel,
                $compacted
            );
            $phoneLabel = $compacted;
        }
        $phoneLabel = $this->boundedLabel(
            $phoneLabel,
            (int)$labelPolicy['phone_max_chars'],
            'phoneLabel',
            $warnings
        );

        // The template engine resolves this pair from the owning site's complete
        // Cisco COP bundle. Manual filenames/versions are deliberately ignored.
        if (!empty($settings['cisco_xml_olson_timezone'])) {
            $tzVersion = trim((string)($settings['_cisco_tzdata_version'] ?? ''));
            $tzFile = trim((string)($settings['_cisco_tzdata_file'] ?? ''));
            if ($tzVersion !== '' && $tzFile !== '') {
                if (!preg_match('/^[A-Za-z0-9_.-]+$/', $tzFile)) {
                    throw new \InvalidArgumentException('Cisco timezone-data filename contains unsafe characters');
                }
                $tzdata = $add($device, 'tzdata');
                $add($tzdata, 'tzolsonversion', $tzVersion);
                $add($tzdata, 'tzupdater', $tzFile);
            } else {
                $warning = trim((string)($settings['_cisco_tzdata_warning']
                    ?? 'Cisco Olson time zone is set, but this site has no compatible detected Cisco timezone payload.'));
                if ($warning !== '' && !in_array($warning, $warnings, true)) { $warnings[] = $warning; }
            }
        }

        // devicePool: date/time + NTP + call manager (the Asterisk/UCM server)
        $pool = $add($device, 'devicePool');
        $dts = $add($pool, 'dateTimeSetting');
        $add($dts, 'dateTemplate', $settings['date_template'] ?? 'D/M/Y');
        $add($dts, 'timeZone', $settings['cisco_xml_timezone'] ?? ($settings['timezone'] ?? 'GMT Standard/Daylight Time'));
        // Olson zones let current enterprise firmware use modern civil-time rules (for
        // example, Iran has remained UTC+03:30 without DST since 2023).  Keep the legacy
        // timeZone as a compatibility fallback for older XML phone generations.
        if (!empty($settings['cisco_xml_olson_timezone'])) {
            $add($dts, 'olsonTimeZone', $settings['cisco_xml_olson_timezone']);
        }
        if (!empty($settings['ntp_server'])) {
            $ntps = $add($dts, 'ntps');
            $ntp = $add($ntps, 'ntp');
            $add($ntp, 'name', $settings['ntp_server']);
            $add($ntp, 'ntpMode', 'Unicast');
        }
        $cmg = $add($pool, 'callManagerGroup');
        $members = $add($cmg, 'members');
        $member = $add($members, 'member');
        $member->setAttribute('priority', '0');
        $cm = $add($member, 'callManager');
        $ports = $add($cm, 'ports');
        $add($ports, 'ethernetPhonePort', '2000');
        $add($ports, 'sipPort', $sipPort);
        $add($ports, 'securedSipPort', '5061');
        $add($cm, 'processNodeName', $pbx);

        // sipProfile
        $sip = $add($device, 'sipProfile');
        $proxies = $add($sip, 'sipProxies');
        foreach (['backupProxy','backupProxyPort','emergencyProxy','emergencyProxyPort','outboundProxy','outboundProxyPort'] as $field) {
            $add($proxies, $field, '');
        }
        $add($proxies, 'registerWithProxy', 'true');
        $feat = $add($sip, 'sipCallFeatures');
        $add($feat, 'callForwardURI', 'x-cisco-serviceuri-cfwdall');
        $add($feat, 'callPickupURI', 'x-cisco-serviceuri-pickup');
        $add($feat, 'callPickupListURI', 'x-cisco-serviceuri-opickup');
        $add($feat, 'callPickupGroupURI', 'x-cisco-serviceuri-gpickup');
        $add($feat, 'meetMeServiceURI', 'x-cisco-serviceuri-meetme');
        $add($feat, 'abbreviatedDialURI', 'x-cisco-serviceuri-abbrdial');
        $add($feat, 'semiAttendedTransfer', 'true');
        $add($feat, 'remoteCcEnable', 'true');
        $add($feat, 'cnfJoinEnabled', 'true');
        foreach ([
            'rfc2543Hold'=>'false', 'callHoldRingback'=>'1', 'localCfwdEnable'=>'true',
            'anonymousCallBlock'=>'0', 'callerIdBlocking'=>'0', 'dndControl'=>'0',
            'retainForwardInformation'=>'false', 'uriDialingDisplayPreference'=>'1',
        ] as $field => $value) {
            $add($feat, $field, $value);
        }

        // Required for Asterisk CONNECTEDLINE() / chan_sip RPID updates. Without this,
        // 78xx placed-call history can record the phone's own CID instead of the party dialed.
        $stack = $add($sip, 'sipStack');
        // Emit a complete base profile: a factory-reset handset has no previously
        // accepted timers or media settings to inherit from a sparse configuration.
        foreach ([
            'sipInviteRetx'=>'6', 'sipRetx'=>'10', 'timerInviteExpires'=>'180',
            'timerRegisterExpires'=>'3600', 'timerRegisterDelta'=>'5',
            'timerKeepAliveExpires'=>'120', 'timerSubscribeExpires'=>'120',
            'timerSubscribeDelta'=>'5', 'timerT1'=>'500', 'timerT2'=>'4000', 'maxRedirects'=>'70',
        ] as $field => $value) {
            $add($stack, $field, $value);
        }
        $add($stack, 'remotePartyID', 'true');
        $add($stack, 'userInfo', 'Phone');
        foreach ([
            'autoAnswerTimer'=>'1', 'autoAnswerAltBehavior'=>'false', 'autoAnswerOverride'=>'true',
            'transferOnhookEnabled'=>'false', 'enableVad'=>'false', 'preferredCodec'=>'g711ulaw',
            'dtmfAvtPayload'=>'101', 'dtmfDbLevel'=>'3', 'dtmfOutofBand'=>'avt',
            'alwaysUsePrimeLine'=>'false', 'alwaysUsePrimeLineVoiceMail'=>'false', 'kpml'=>'3',
        ] as $field => $value) {
            $add($sip, $field, $value);
        }
        // Keep Cisco's documented sipProfile sequence: the device label follows
        // sipStack and precedes the line collection.
        $add($sip, 'phoneLabel', $phoneLabel);

        foreach ([
            'stutterMsgWaiting'=>'0', 'callStats'=>'false', 'offhookToFirstDigitTimer'=>'15000',
            'silentPeriodBetweenCallWaitingBursts'=>'10', 'disableLocalSpeedDialConfig'=>'false',
            'startMediaPort'=>'16384', 'stopMediaPort'=>'32766', 'natEnabled'=>'false',
            'natReceivedProcessing'=>'false', 'natAddress'=>'',
        ] as $field => $value) {
            $add($sip, $field, $value);
        }

        $sipLines = $add($sip, 'sipLines');
        foreach ($lines as $ln) {
            $sipLines->appendChild($this->renderLine($doc, $ln, $settings, $sipPort, $warnings));
        }
        // This is the handset's local port, independently of the PBX listener.
        $add($sip, 'voipControlPort', '5060');
        $add($sip, 'dscpForAudio', '184');
        $add($sip, 'ringSettingBusyStationPolicy', '0');
        $dialFile = trim((string)($settings['dial_template_file'] ?? ''));
        if (!empty($settings['_template_id']) && (isset($settings['digit_timeout']) || isset($settings['dial_pattern']) || !empty($settings['dial_rules']))) {
            $dialFile = DialTemplateGenerator::filenameFor($settings);
        }
        if ($dialFile !== '') {
            $add($sip, 'dialTemplate', basename($dialFile));
        }
        $hasManagedSoftkeys = !empty($settings['_softkey_layout'])
            && !empty($settings['softkey_file']);
        $hasManagedFeaturePolicy = !$hasManagedSoftkeys
            && !empty($settings['_feature_policy_layout'])
            && !empty($settings['featurepolicy_file']);
        $branchPolicy = (array)($settings['_cisco_branch_policy'] ?? []);
        if ($hasManagedSoftkeys && ($branchPolicy['softkey_file_status'] ?? '') === 'experimental') {
            $warnings[] = sprintf(
                'Cisco %s accepts an operator-managed softkey file experimentally; verify the rendered handset states after restart.',
                $modelName ?: 'XML phone'
            );
        }
        if (!empty($model['supports_softkeys']) && $hasManagedSoftkeys) {
            $add($sip, 'softKeyFile', basename((string)$settings['softkey_file']));
        }
        if (!empty($model['supports_featurepolicy']) && $hasManagedFeaturePolicy) {
            $add($device, 'featurePolicyFile', basename((string)$settings['featurepolicy_file']));
        }

        // A character per configured SIP line controls whether that line contributes missed
        // calls to the native history database.  Omitting this has firmware-dependent defaults
        // and can leave an 88xx Recents screen permanently empty.
        $lineCount = max(1, count(array_filter($lines, static fn($line) => (int)($line['featureId'] ?? 0) === 9)));
        $add($device, 'MissedCallLoggingOption', str_repeat('1', $lineCount));

        $common = $add($device, 'commonProfile');
        $add($common, 'phonePassword', $settings['cisco_phone_password'] ?? ($settings['admin_password'] ?? ''));
        // The catalog is user-selectable when true. 8800/8900/9900 can instead lock a configured
        // defaultWallpaperFile below; older XML sets may require personalization/CGI push.
        $add($common, 'backgroundImageAccess', !empty($settings['background_access_disabled']) ? 'false' : 'true');
        $add($common, 'callLogBlfEnabled', '1');

        if (!empty($settings['firmware'])) {
            $add($device, 'loadInformation', $settings['firmware']);
        }
        if (!empty($settings['inactive_firmware'])) {
            $add($device, 'inactiveLoadInformation', $settings['inactive_firmware']);
        }
        $add($device, 'transportLayerProtocol', (string) $transport);

        $vendor = null;
        $vendorKeys = [
            'cisco_line_mode' => ['lineMode', 'bool'],
            'cisco_video_capability' => ['videoCapability', 'bool'],
            'cisco_separate_mute' => ['separateMute', 'bool'],
            'cisco_hide_video' => ['hideVideoByDefault', 'bool'],
            'cisco_camera' => ['ciscoCamera', 'bool'],
            'cisco_web_access' => ['webAccess', 'raw'],
            'cisco_web_protocol' => ['webProtocol', 'raw'],
            'cisco_web_admin' => ['webAdmin', 'bool'],
            'cisco_admin_password' => ['adminPassword', 'raw'],
            'cisco_ssh_access' => ['sshAccess', 'raw'],
            'cisco_peer_firmware_sharing' => ['peerFirmwareSharing', 'bool'],
            'cisco_load_server' => ['loadServer', 'raw'],
            // Display/backlight power-save schedule. Cisco numbers weekdays 1=Sunday
            // through 7=Saturday; blank values inherit firmware/site behavior.
            'cisco_display_refresh_rate' => ['displayRefreshRate', 'raw'],
            'cisco_days_display_not_active' => ['daysDisplayNotActive', 'raw'],
            'cisco_display_on_time' => ['displayOnTime', 'raw'],
            'cisco_display_on_duration' => ['displayOnDuration', 'raw'],
            'cisco_display_idle_timeout' => ['displayIdleTimeout', 'raw'],
            'cisco_display_on_incoming_call' => ['displayOnWhenIncomingCall', 'bool'],
            'cisco_days_backlight_not_active' => ['daysBacklightNotActive', 'raw'],
            'cisco_backlight_on_time' => ['backlightOnTime', 'raw'],
            'cisco_backlight_on_duration' => ['backlightOnDuration', 'raw'],
            'cisco_backlight_idle_timeout' => ['backlightIdleTimeout', 'raw'],
            'cisco_backlight_on_incoming_call' => ['backlightOnWhenIncomingCall', 'bool'],
            // Common 78xx/88xx call presentation controls from vendorConfig.
            'cisco_more_key_reversion_timer' => ['moreKeyReversionTimer', 'raw'],
            'cisco_auto_select_line' => ['autoSelectLineEnable', 'bool'],
            'cisco_auto_call_select' => ['autoCallSelect', 'bool'],
            'cisco_incoming_call_toast_timer' => ['incomingCallToastTimer', 'raw'],
            'cisco_minimum_ring_volume' => ['minimumRingVolume', 'raw'],
            // 0=user choice; 1=Sunrise (88xx); 2=Chirp 1; 3=Chirp 2.
            'cisco_admin_configurable_ringer' => ['adminConfigurableRinger', 'raw'],
            'cisco_simplified_new_call' => ['simplifiedNewCall', 'bool'],
            'cisco_actionable_alert' => ['actionableAlert', 'bool'],
            'cisco_hold_resume_key' => ['holdResumeKey', 'bool'],
            'cisco_call_park_monitor' => ['callParkMonitor', 'bool'],
            'cisco_show_call_history_selected_line' => ['showCallHistoryForSelectedLine', 'bool'],
            'cisco_dial_tone_release_key' => ['dialToneFromReleaseKey', 'bool'],
            'cisco_mark_call_as_spam' => ['markCallAsSpam', 'bool'],
            'cisco_lower_voice_alert' => ['lowerYourVoiceAlert', 'bool'],
            'cisco_pc_port' => ['pcPort', 'raw'],
            'cisco_voice_vlan_access' => ['voiceVlanAccess', 'bool'],
            'cisco_enable_cdp_sw_port' => ['enableCdpSwPort', 'bool'],
            'cisco_enable_cdp_pc_port' => ['enableCdpPcPort', 'bool'],
            'cisco_enable_lldp_sw_port' => ['enableLldpSwPort', 'bool'],
            'cisco_enable_lldp_pc_port' => ['enableLldpPcPort', 'bool'],
            'cisco_cdp_enable' => ['cdpEnable', 'bool'],
        ];
        $usesSoftkeyControl = !empty($model['supports_softkeys'])
            && $hasManagedSoftkeys
            && (bool)preg_match('/^(?:78|88|8961$|9951$|9971$)/', $modelName);
        $hasRecentsControl = (bool)preg_match('/^78/', $modelName)
            && array_key_exists('cisco_recents_softkey', $settings)
            && $settings['cisco_recents_softkey'] !== '';
        $scheduleModes = [
            'cisco_display_schedule_mode' => ['days'=>'cisco_days_display_not_active', 'element'=>'daysDisplayNotActive'],
            'cisco_backlight_schedule_mode' => ['days'=>'cisco_days_backlight_not_active', 'element'=>'daysBacklightNotActive'],
        ];
        $activeScheduleModes = [];
        foreach ($scheduleModes as $modeKey => $schedule) {
            $mode = (string)($settings[$modeKey] ?? '');
            if (in_array($mode, ['daily','custom','off'], true)) {
                $activeScheduleModes[$modeKey] = $schedule + ['mode'=>$mode];
            }
        }
        $needsVendor = !empty($settings['cisco_default_wallpaper']) || $usesSoftkeyControl || $hasRecentsControl || $activeScheduleModes;
        foreach ($vendorKeys as $settingKey => $_vendorSpec) {
            if (array_key_exists($settingKey, $settings) && $settings[$settingKey] !== '') {
                $needsVendor = true;
                break;
            }
        }
        if ($needsVendor) {
            $vendor = $add($device, 'vendorConfig');
            if ($usesSoftkeyControl) {
                $add($vendor, 'softkeyControl', '1');
            }
            if ($hasRecentsControl) {
                $add($vendor, 'recentsSoftKey', !empty($settings['cisco_recents_softkey']) ? '1' : '0');
            }
            foreach ($vendorKeys as $settingKey => [$element, $kind]) {
                if (($settingKey === 'cisco_days_display_not_active' && isset($activeScheduleModes['cisco_display_schedule_mode']))
                    || ($settingKey === 'cisco_days_backlight_not_active' && isset($activeScheduleModes['cisco_backlight_schedule_mode']))) {
                    continue;
                }
                if (!array_key_exists($settingKey, $settings) || $settings[$settingKey] === '') {
                    continue;
                }
                $value = $kind === 'bool' ? (!empty($settings[$settingKey]) ? '1' : '0') : (string)$settings[$settingKey];
                if ($settingKey === 'cisco_display_refresh_rate' && !in_array($value, ['0', '1'], true)) {
                    // Older editors stored an interval in seconds in this enum.
                    $value = '0';
                    $warnings[] = 'Legacy display refresh interval was normalized to Normal; select Normal or Flicker correction in the template.';
                }
                $add($vendor, $element, $value);
            }
            foreach ($activeScheduleModes as $schedule) {
                $value = $schedule['mode'] === 'off'
                    ? '1,2,3,4,5,6,7'
                    : ($schedule['mode'] === 'custom' ? (string)($settings[$schedule['days']] ?? '') : '');
                $add($vendor, $schedule['element'], $value);
            }
            if (!empty($settings['cisco_default_wallpaper'])) {
                $add($vendor, 'defaultWallpaperFile', basename((string)$settings['cisco_default_wallpaper']));
            }
        }

        $requestedKemCount = max(0, (int)($settings['cisco_kem_count'] ?? 0));
        $maxKemCount = max(0, (int)($model['max_expansion_modules'] ?? 0));
        $kemCount = min($requestedKemCount, $maxKemCount);
        if ($requestedKemCount > $maxKemCount) {
            $warnings[] = sprintf('Requested %d expansion modules, but Cisco %s supports %d; the declaration was clamped.', $requestedKemCount, (string)($model['model'] ?? 'phone'), $maxKemCount);
        }
        $supportsExpansion = !empty($model['kem_support'])
            || !empty($model['expansion_types'])
            || (int)($model['max_expansion_modules'] ?? 0) > 0;
        if ($kemCount > 0 && !$supportsExpansion) {
            $warnings[] = 'Expansion modules were requested, but this Cisco model is not marked KEM-capable; addOnModules was omitted.';
        } elseif ($kemCount > 0) {
            $type = (string)($settings['cisco_kem_type'] ?? 'BEKEM');
            $sizes = ['7914' => 14, '7915' => 24, '7916' => 24, 'BEKEM' => 36, 'CP-8800-Audio' => 28, 'CP-8800-Video' => 28, 'CKEM' => 36];
            $allowedTypes = json_decode((string)($model['expansion_types'] ?? '[]'), true);
            if (is_array($allowedTypes) && $allowedTypes && !in_array($type, $allowedTypes, true)) {
                $warnings[] = sprintf('%s is not compatible with Cisco %s; addOnModules was omitted.', $type, (string)($model['model'] ?? 'phone'));
                $kemCount = 0;
            }
            if (!isset($sizes[$type])) {
                $warnings[] = "Unknown Cisco expansion module type '$type'; addOnModules was omitted.";
            } elseif ($kemCount > 0) {
                $vendor = $vendor ?: $add($device, 'vendorConfig');
                $add($vendor, 'kemOneColumn', !empty($settings['cisco_kem_one_column']) ? '1' : '0');
                $modules = $add($device, 'addOnModules');
                for ($idx = 1; $idx <= $kemCount; $idx++) {
                    $module = $add($modules, 'addOnModule');
                    $module->setAttribute('idx', (string)$idx);
                    $add($module, 'deviceType', $type);
                    $add($module, 'deviceLine', (string)$sizes[$type]);
                    if (in_array($type, ['7914', '7915', '7916'], true) && !empty($settings['cisco_kem_firmware'])) {
                        $add($module, 'loadInformation', $settings['cisco_kem_firmware']);
                    }
                }
            }
        }

        // Locales
        $netLocale = $settings['cisco_networkLocale'] ?? ($settings['network_locale'] ?? '');
        $localeUsable = !array_key_exists('_cisco_locale_usable', $settings) || !empty($settings['_cisco_locale_usable']);
        if ($netLocale !== '' && $localeUsable) {
            $add($device, 'networkLocale', $netLocale);
            $networkInfo = $add($device, 'networkLocaleInfo');
            $add($networkInfo, 'name', $netLocale);
        }
        if (!empty($settings['cisco_userLocale']) && $localeUsable) {
            $userLoc = $add($device, 'userLocale');
            $add($userLoc, 'name', $settings['cisco_userLocale']);
            // A hardcoded langCode is usually fine if name is set, or omitted.
        }

        $add($device, 'deviceSecurityMode', (string) ($settings['device_security'] ?? '1'));
        $add($device, 'dndCallAlert', $settings['cisco_dndCallAlert'] ?? ($settings['dnd_call_alert'] ?? '5'));
        $add($device, 'phonePersonalization', !empty($settings['phone_personalization']) ? '1' : '0');
        if (!empty($settings['cisco_ssh_user'])) {
            $add($device, 'sshUserId', $settings['cisco_ssh_user']);
        }
        if (!empty($settings['cisco_ssh_password'])) {
            $add($device, 'sshPassword', $settings['cisco_ssh_password']);
        }

        // URLs
        $urls = [
            'authenticationURL' => '',
            'idleURL'        => '',
            'proxyServerURL' => '',
            'servicesURL'    => $settings['cisco_servicesURL'] ?? ($settings['services_url'] ?? ''),
            'directoryURL'   => $settings['cisco_directoryURL'] ?? ($settings['cisco_directory_url'] ?? ($settings['directory_url'] ?? '')),
            'messagesURL'    => $settings['cisco_messagesURL'] ?? ($settings['messages_url'] ?? ''),
            'informationURL' => $settings['cisco_informationURL'] ?? ($settings['information_url'] ?? ''),
        ];
        foreach ($urls as $uName => $uVal) {
            $uVal = str_replace('{{pbx_ip}}', $pbx, $uVal);
            $add($device, $uName, $uVal);
        }

        // Native Call History entries are not implicit on every enterprise firmware build.
        // provisioning=2 merges these phone-native applications with the direct URL fields
        // above, preserving the configured company directory while restoring Recents.
        $directoryUrl = (string)($urls['directoryURL'] ?? '');
        $phoneServices = $add($device, 'phoneServices');
        $phoneServices->setAttribute('useHTTPS', preg_match('#^https://#i', $directoryUrl) ? 'true' : 'false');
        // "Both" preserves direct directory/messages URLs and the embedded native
        // Missed/Received/Placed applications. Direct-URL-only mode (1) is retained
        // as an explicit operator opt-out, but it removes those embedded applications
        // and can leave 78xx Recents/Navigation-Up without a call-history target.
        $serviceProvisioning = array_key_exists('cisco_service_provisioning', $settings)
            ? (string)$settings['cisco_service_provisioning']
            : '2';
        if (!in_array($serviceProvisioning, ['0','1','2'], true)) { $serviceProvisioning = '2'; }
        $add($phoneServices, 'provisioning', $serviceProvisioning);
        foreach ([
            ['Missed Calls', 'Application:Cisco/MissedCalls', '1'],
            ['Received Calls', 'Application:Cisco/ReceivedCalls', '1'],
            ['Placed Calls', 'Application:Cisco/PlacedCalls', '1'],
        ] as [$name, $url, $type]) {
            $this->addPhoneService($doc, $phoneServices, $name, $url, $type);
        }

        if (!empty($settings['voicemail_number'])) {
            $this->addPhoneService($doc, $phoneServices, 'Voicemail', 'Application:Cisco/Voicemail', '2');
        }

        // SIP TLS and deviceSecurityMode do not encrypt the TFTP XML document.
        // Declare the actual publication format instead of inheriting CUCM state.
        $add($device, 'capfAuthMode', '0');
        $add($device, 'certHash', '');
        $add($device, 'encrConfig', 'false');
        $versionStamp = $add($device, 'versionStamp');
        $versionStamp->appendChild($doc->createTextNode(
            DeterministicVersionStamp::fromContent((string)$doc->saveXML())
        ));

        $xml = $doc->saveXML();
        return ['filename' => $filename, 'xml' => $xml, 'warnings' => $warnings];
    }

    private function addPhoneService(\DOMDocument $doc, \DOMElement $parent, string $name, string $url, string $type): void
    {
        $service = $doc->createElement('phoneService');
        $service->setAttribute('type', $type);
        $service->setAttribute('category', '0');
        foreach (['name' => $name, 'url' => $url, 'vendor' => '', 'version' => ''] as $key => $value) {
            $element = $doc->createElement($key);
            if ($value !== '') { $element->appendChild($doc->createTextNode($value)); }
            $service->appendChild($element);
        }
        $parent->appendChild($service);
    }

    private function renderLine(
        \DOMDocument $doc,
        array $ln,
        array $settings,
        string $sipPort,
        array &$warnings
    ): \DOMElement
    {
        $line = $doc->createElement('line');
        $line->setAttribute('button', (string) $ln['button']);
        if (!empty($ln['lineIndex'])) {
            $line->setAttribute('lineIndex', (string) $ln['lineIndex']);
        }
        $c = function (string $n, $v = null) use ($doc, $line) {
            $el = $doc->createElement($n);
            if ($v !== null && $v !== '') {
                $el->appendChild($doc->createTextNode((string) $v));
            }
            $line->appendChild($el);
        };

        $featureId = (int) ($ln['featureId'] ?? 9);
        $c('featureID', (string) $featureId);
        $featureLabel = $this->boundedLabel(
            (string)($ln['label'] ?? ''),
            30,
            'featureLabel',
            $warnings
        );
        $c('featureLabel', $featureLabel);

        if ($featureId === 9) {
            // Primary/secondary line
            $c('proxy', 'USECALLMANAGER');
            $c('port', $sipPort);
            $c('name', $ln['name'] ?? '');
            $displayName = $this->boundedLabel(
                (string)($ln['displayName'] ?? ($ln['label'] ?? ($ln['name'] ?? ''))),
                30,
                'displayName',
                $warnings
            );
            $c('displayName', $displayName);
            $autoAnswer = $doc->createElement('autoAnswer');
            $autoAnswer->appendChild($doc->createElement('autoAnswerEnabled', '0'));
            $line->appendChild($autoAnswer);
            $c('callWaiting', '3');
            // Only the primary line authenticates. Additional lines are bulk-registered by the
            // primary peer's register= list and must not carry independent credentials.
            if ((int)($ln['lineIndex'] ?? 1) === 1) {
                $c('authName', $ln['authName'] ?? ($ln['name'] ?? ''));
                $c('authPassword', $ln['secret'] ?? '');
            }
            $c('contact', $ln['contact'] ?? ($ln['name'] ?? ''));
            $c('sharedLine', 'false');
            $c('messageWaitingLampPolicy', '1');
            $c('messageWaitingAMWI', '0');
            if (!empty($settings['voicemail_number'])) {
                $c('messagesNumber', $settings['voicemail_number']);
            }
            $c('ringSettingIdle', '4');
            $c('ringSettingActive', '5');
            $forwardInfo = $doc->createElement('forwardCallInfoDisplay');
            foreach (['callerName','callerNumber','redirectedNumber','dialedNumber'] as $field) {
                $forwardInfo->appendChild($doc->createElement($field, 'true'));
            }
            $line->appendChild($forwardInfo);
            $c('maxNumCalls', (string)($settings['max_num_calls'] ?? '4'));
            $c('busyTrigger', (string)($settings['busy_trigger'] ?? '3'));
        } elseif (in_array($featureId, [21, 22, 2], true)) {
            // BLF speed-dial / BLF park / speed-dial
            if ($featureId === 21 && isset($ln['featureOptionMask'])) {
                $c('featureOptionMask', (string) $ln['featureOptionMask']);
            }
            $c('speedDialNumber', $ln['speedDialNumber'] ?? '');
            $c('contact', $ln['contact'] ?? ($ln['speedDialNumber'] ?? ''));
        } elseif ($featureId === 20) {
            $c('serviceURI', $ln['speedDialNumber'] ?? ($settings['_phone_services_url'] ?? ''));
        }
        // Other featureIDs (dnd 130, park 126, pickup 127, etc.) need only id+label.

        return $line;
    }

    /** Clip by Unicode characters, not UTF-8 bytes, and retain an audit warning. */
    private function boundedLabel(string $value, int $maxChars, string $field, array &$warnings): string
    {
        if ($maxChars < 1 || $this->characterLength($value) <= $maxChars) {
            return $value;
        }
        $clipped = function_exists('mb_substr')
            ? mb_substr($value, 0, $maxChars, 'UTF-8')
            : $this->unicodeSlice($value, $maxChars);
        $warnings[] = sprintf(
            'Cisco XML %s exceeds %d characters and was emitted as "%s".',
            $field,
            $maxChars,
            $clipped
        );
        return $clipped;
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? strlen($value) : $count;
    }

    private function unicodeSlice(string $value, int $maxChars): string
    {
        $matched = preg_match_all('/./us', $value, $matches);
        return $matched === false
            ? substr($value, 0, $maxChars)
            : implode('', array_slice($matches[0], 0, $maxChars));
    }
}
