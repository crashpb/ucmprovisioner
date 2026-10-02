<?php
/**
 * Resolve the complete, read-only policy for one physical phone.
 *
 * The resolver combines device capabilities, applicable parent/child group
 * layers, final member exceptions, a site default when no compatible group
 * layer exists, AD-derived identity, phone-level overrides and group BLF
 * expansion. Vendor generators consume the result; this class does not write
 * Asterisk or TFTP files.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/AssetFilename.php';
require_once __DIR__ . '/SoftkeyPolicyGenerator.php';

class TemplateEngine
{
    public const PHONEBOOK_SITE_DEFAULT = '__default__';
    public const PHONEBOOK_NONE = '__none__';

    private $ucm;
    private $currentSiteId = 0;
    private $extensionCatalog = [];
    private $phoneCatalog = [];
    private $blfLabelSource = 'phone_label';
    private array $labelPatterns = [];
    private string $phoneServicesUrl = '';
    private array $timezoneInventory = [];
    private array $localeInventory = [];
    private array $templateGroupsBySite = [];
    private array $templatesBySite = [];
    private array $defaultTemplateRulesBySite = [];
    private array $draft = [];
    private array $identityPreview = [];
    private array $settingTrace = [];

    /** Symbolic feature -> Cisco line-key featureID. */
    public const FEATURE_IDS = [
        'line'         => 9,
        'speeddial'    => 2,
        'blf'          => 21,
        'blfpark'      => 22,
        'intercom'     => 23,
        'serviceurl'   => 20,
        'dnd'          => 130,
        'park'         => 126,
        'pickup'       => 127,
        'gpickup'      => 128,
        'opickup'      => 135,
        'huntgroup'    => 139,
        'record'       => 159,
        'allcalls'     => 140,
        'alerting'     => 142,
        'mobility'     => 0, // feature-policy only on supported models
    ];

    /**
     * Compatibility allowlist for schema-less softkey policies saved by older
     * versions. Current policies bypass this list and preserve the operator's
     * exact states, order and repeated slots through SoftkeyPolicyGenerator.
     */
    public const SOFTKEY_STATES = [
        'On Hook'              => ['Redial', 'NewCall', 'DND', 'CFwdALL', 'HLog', 'PickUp', 'GPickUp', 'OPickUp', 'CallBack', 'MeetMe', 'QRT', 'Undefined'],
        'Off Hook'             => ['Redial', 'EndCall', 'DND', 'CFwdALL', 'HLog', 'PickUp', 'GPickUp', 'OPickUp', 'AbbrDial', 'Undefined'],
        'Off Hook With Feature'=> ['Redial', 'EndCall', 'DND', 'AbbrDial', 'Undefined'],
        'Digits After First'   => ['EndCall', '<<', 'DND', 'AbbrDial', 'Undefined'],
        'Ring Out'             => ['EndCall', 'DND', 'CallBack', 'Undefined'],
        'Connected'            => ['Hold', 'EndCall', 'Transfer', 'Park', 'Confrn', 'ConfList', 'RmLstC', 'Record', 'iDivert', 'DND', 'HLog', 'QRT', 'MCID', 'Select', 'Join', 'VidMode', 'Undefined'],
        'Connected No Feature' => ['EndCall', 'Undefined'],
        'Connected Transfer'   => ['EndCall', 'Transfer', 'CallBack', 'QRT', 'Undefined'],
        'Connected Conference' => ['EndCall', 'Confrn', 'DND', 'QRT', 'Undefined'],
        'On Hold'              => ['Resume', 'NewCall', 'iDivert', 'DND', 'Select', 'Join', 'Undefined'],
        'Ring In'              => ['Answer', 'iDivert', 'DND', 'Undefined'],
    ];

    /** Mandatory operational keys used only while reading schema-less policies. */
    public const SOFTKEY_MANDATORY = [
        'On Hook'              => ['NewCall'],
        'Digits After First'   => ['<<'],
        'Ring Out'             => ['EndCall'],
        'Ring In'              => ['Answer'],
        'Connected'            => ['EndCall'],
        'Connected Transfer'   => ['Transfer'],
        'Connected Conference' => ['Confrn'],
        'On Hold'              => ['Resume'],
    ];
    /** Wire definitions used only by the schema-less compatibility renderer. */
    public const SOFTKEY_CODES = [
        'Undefined' => [0, 0, 0],
        'Redial'    => [1, 1, 301],
        'NewCall'   => [2, 2, 302],
        'Hold'      => [3, 3, 303],
        'Transfer'  => [4, 4, 304],
        'CFwdALL'   => [5, 5, 305],
        '<<'        => [8, 8, 308],
        'EndCall'   => [9, 9, 309],
        'Resume'    => [10, 10, 310],
        'Answer'    => [11, 11, 311],
        'Confrn'    => [13, 13, 313],
        'Park'      => [14, 14, 314],
        'Join'      => [15, 15, 315],
        'MeetMe'    => [16, 16, 316],
        'PickUp'    => [17, 17, 317],
        'GPickUp'   => [18, 18, 318],
        'RmLstC'    => [57, 19, 319],
        'CallBack'  => [65, 20, 320],
        'DND'       => [63, 69, 369],
        'QRT'       => [75, 22, 322],
        'VidMode'   => [82, 33, 333],
        'MCID'      => [76, 27, 327],
        'Select'    => [78, 29, 329],
        'ConfList'  => [79, 30, 330],
        'iDivert'   => [80, 31, 331],
        'OPickUp'   => [91, 34, 334],
        'HLog'      => [92, 35, 335],
        'AbbrDial'  => [7740, 71, 371],
        'Record'    => [7747, 74, 374],
    ];

    /**
     * Feature-policy names (featurepolicy.xml — 8961/9951/9971, and 78xx/88xx if
     * <softKeyControl> disabled). Source: https://usecallmanager.nz/feature-policy.html
     * (id => name, in the exact order/IDs Cisco expects).
     */
    public const FEATURE_POLICY_KEYS = [
        1  => 'Forward All',
        2  => 'Park',
        3  => 'Divert (Alerting)',
        4  => 'Conference Details',
        5  => 'Speed Dial',
        6  => 'Call Back',
        7  => 'Redial',
        8  => 'Barge',
        9  => 'Divert (Connected)',
        10 => 'Call PickUp',
        11 => 'Other Call PickUp',
        12 => 'Group Call PickUp',
        13 => 'Mobility',
        14 => 'Meet Me',
        15 => 'Quality Reporting Tool',
        16 => 'Malicious Caller ID',
    ];

    public function __construct($ucm, array $draft = [], array $identityPreview = [])
    {
        $this->ucm = $ucm;
        $this->draft = $draft;
        $this->identityPreview = $identityPreview;
    }

    /** Load one complete site-owned settings set over documented safe defaults. */
    public function effectiveSettings(?int $siteId): array
    {
        $s = [
            // Legacy keys remain readable for upgrades, but endpoint routing is now
            // resolved from the vendor-class defaults below.
            'transport' => 'udp', 'sip_port' => '5060',
            'cisco_transport' => 'udp', 'cisco_sip_port' => '5060',
            'cisco_cnf_transport' => 'udp', 'cisco_cnf_sip_port' => '5060',
            'non_cisco_driver' => 'pjsip', 'non_cisco_transport' => 'udp',
            'non_cisco_sip_port' => '5160', 'proxy' => 'USECALLMANAGER',
            'date_template' => 'D/M/Y', 'cisco_xml_timezone' => 'GMT Standard/Daylight Time',
            'cisco_xml_olson_timezone' => '', 'cisco_legacy_timezone' => 'GMT',
            'cisco_legacy_dst_auto_adjust' => '1', 'yealink_timezone' => '+0',
            'dial_template_file' => '', 'voicemail_number' => '*97',
            // Merge direct service URLs with the embedded Missed/Received/Placed
            // applications. Direct-URL-only mode can remove the native Recents target.
            'cisco_service_provisioning' => '2',
            'device_security' => '1',
        ];
        $this->settingTrace = [];
        foreach ($s as $key=>$value) { $this->settingTrace[$key][] = ['source'=>'default','value'=>self::explainValue($key,$value)]; }
        $ownerSite = $siteId ?: $this->ucm->localSiteId();
        $siteSettings = $this->ucm->getSiteSettings($ownerSite);
        if (($this->draft['kind'] ?? '') === 'settings' && (int)($this->draft['site_id'] ?? 0) === $ownerSite) { $siteSettings = array_replace($siteSettings,(array)($this->draft['values'] ?? [])); }
        foreach ($siteSettings as $k => $v) {
            $s[$k] = $v;
            $this->settingTrace[$k][] = ['source'=>'site','site_id'=>$ownerSite,'value'=>self::explainValue($k,$v)];
        }
        return $s;
    }

    /**
     * Return the same default/group applicability trace used by config generation,
     * without resolving accounts, secrets, media, or the rendered phone file.
     */
    public function templateAssignmentTrace(array $phone): array
    {
        $siteId = (int)($phone['site_id'] ?? 0) ?: $this->ucm->localSiteId();
        $this->currentSiteId = $siteId;
        $modelId = (int)($phone['model_id'] ?? 0);
        $vendor = trim((string)($phone['m_vendor'] ?? ''));
        $modelName = trim((string)($phone['m_model'] ?? ''));
        $family = trim((string)($phone['m_family'] ?? ''));
        $capabilities = $vendor !== '' && $modelName !== ''
            ? ModelCatalog::capabilitiesFor($vendor, $modelName)
            : null;
        $model = $vendor !== '' && $modelName !== '' && $family !== ''
            ? [
                'id'=>$modelId,
                'vendor'=>$vendor,
                'model'=>$modelName,
                'family'=>$family,
                'series_key'=>trim((string)($phone['m_series_key'] ?? ''))
                    ?: ModelCatalog::seriesKey($vendor, $modelName, $family),
                'role'=>trim((string)($phone['m_role'] ?? ''))
                    ?: (string)($capabilities['role'] ?? 'desk'),
            ]
            : ($modelId > 0 ? $this->ucm->getModel($modelId) : null);
        if (!$model) {
            throw new \RuntimeException('Choose a supported model before resolving its template assignment.');
        }

        $group = null;
        $groupId = (int)($phone['group_id'] ?? 0);
        if ($groupId > 0) {
            foreach ($this->templateGroupsForSite($siteId) as $candidate) {
                if ((int)($candidate['id'] ?? 0) === $groupId) {
                    $group = $candidate;
                    break;
                }
            }
            if (!$group) {
                throw new \RuntimeException("Provisioning group $groupId was not found.");
            }
        }

        return $this->templateStackForGroup(
            $group,
            $model,
            (string)($model['family'] ?? ''),
            true,
            (string)($phone['extension'] ?? '')
        )['trace'];
    }

    /**
     * Resolve everything needed to render a phone's config.
     *
     * @return array{phone:array,device:array,model:?array,settings:array,lines:array}
     */
    public function resolvePhone(array $phone, bool $fullDeviceResolution = true): array
    {
        $siteId   = (int) ($phone['site_id'] ?: $this->ucm->localSiteId());
        $this->currentSiteId = $siteId;
        $settings = $this->effectiveSettings($siteId);
        $this->blfLabelSource = 'phone_label';
        $this->labelPatterns = [];
        $site     = $this->ucm->getSite($siteId) ?: [];
        if (!empty($site['pbx_addr'])) {
            $settings['pbx_addr'] = $site['pbx_addr'];
        }
        // Normal module loading resolves this through SiteConnection. The small
        // fallback keeps TemplateEngine independently testable without changing
        // the production architecture decision.
        $phoneFacingTftpHost = class_exists(SiteConnection::class)
            ? SiteConnection::resolvedTftpHost($site)
            : ((string)($site['tftp_mode'] ?? 'docker') === 'pbx'
                ? (trim((string)($site['pbx_tftp_service_host'] ?? '')) ?: trim((string)($site['pbx_addr'] ?? '')))
                : (trim((string)($site['tftp_service_host'] ?? '')) ?: trim((string)($site['docker_host'] ?? ''))));
        if ($phoneFacingTftpHost !== '') {
            $settings['_tftp_host'] = $phoneFacingTftpHost;
        }

        $model = !empty($phone['model_id']) ? $this->ucm->getModel((int) $phone['model_id']) : null;
        if (!$model) {
            throw new \RuntimeException('Choose a supported model or DECT base before building this phone; family-wide defaults are never applied to an unclassified endpoint.');
        }
        if (ModelCatalog::capabilitiesFor((string)($model['vendor'] ?? ''), (string)($model['model'] ?? '')) === null) {
            throw new \RuntimeException(sprintf(
                '%s %s has no verified provisioning profile and cannot be built safely.',
                (string)($model['vendor'] ?? 'Unknown vendor'),
                (string)($model['model'] ?? 'model')
            ));
        }
        $modelRole = (string)($model['role'] ?? 'desk');
        if (in_array($modelRole, ['dect_handset', 'dect_radio', 'dect_manager'], true)) {
            throw new \RuntimeException(sprintf(
                '%s %s is a %s component and cannot receive a standalone desk-phone config; assign it through its DECT system/base.',
                (string)($model['vendor'] ?? ''),
                (string)($model['model'] ?? ''),
                str_replace('_', ' ', $modelRole)
            ));
        }
        // Firmware is never guessed from a catalog default. A model-compatible, complete bundle
        // selected by the template is resolved later; no selection means the generated config
        // leaves the phone's current load alone.
        $modelName = strtoupper((string)($model['model'] ?? ''));
        $accountLimit = max(1, (int)($model['sip_accounts'] ?? ($model['line_keys'] ?? 1)));
        $catalogLineKeys = max(0, (int)($model['programmable_keys'] ?? ($model['line_keys'] ?? 0)));
        $isGhp63x = strtolower((string)($model['family'] ?? '')) === 'grandstream'
            && (bool)preg_match('/^GHP63[01]W?$/', $modelName);
        // GHP63x has six context keys plus ten separately provisioned digit keys.
        // The digit keys must never inflate the generic line/context allocator.
        if ($isGhp63x) { $catalogLineKeys = 7; }
        $isDectBase = $modelRole === 'dect_base' || (bool)preg_match('/^(?:DP750|DP752|DP755|W60B|W70B)$/', $modelName);
        $device = $this->getDevice((string) $phone['extension']);

        // Clear unused/cached line keys logic support
        $occupied = $isDectBase ? [] : [1 => true];
        $lines = [[
            // A DECT base owns SIP registrations, not handset line keys. Keeping its
            // appearance at zero prevents a fake DSS/line key from leaking into the
            // physical-key allocator while still registering account 1.
            'button'    => $isDectBase ? 0 : 1,
            'feature'   => 'line',
            'featureId' => 9,
            'lineIndex' => 1,
            'accountIndex' => 1,
            'label'     => $phone['label'] ?: ($device['description'] ?? $phone['extension']),
            // Caller identity is deliberately separate from the text beside
            // the physical line key.
            'displayName' => $device['description'] ?? ($phone['label'] ?: $phone['extension']),
            'name'      => $phone['extension'],
            'authName'  => $phone['extension'],
            'secret'    => $device['secret'] ?? '',
            'contact'   => $phone['extension'],
        ]];

        // Multi-line assignments via lines_json
        $customLines = $this->decode($phone['lines_json'] ?? null);
        $lineIdx = 2;
        $usedAccounts = [1 => true];
        if (is_array($customLines)) {
            foreach ($customLines as $cl) {
                $accountIdx = (int)($cl['account'] ?? ($cl['account_index'] ?? ($cl['lineIndex'] ?? ($cl['button'] ?? $lineIdx))));
                $hasAppearance = array_key_exists('button', $cl);
                $btnNum = (int) ($cl['button'] ?? $accountIdx);
                $clExt = trim((string) ($cl['extension'] ?? ''));
                $validSlot = $accountIdx >= 2 && $accountIdx <= $accountLimit && !isset($usedAccounts[$accountIdx]);
                if ($validSlot && $clExt !== '') {
                    if (!$hasAppearance) {
                        $btnNum = $accountIdx;
                    }
                    $clDevice = $this->getDevice($clExt);
                    $lines[] = [
                        'button'    => $btnNum,
                        'feature'   => 'line',
                        'featureId' => 9,
                        'lineIndex' => $accountIdx,
                        'accountIndex' => $accountIdx,
                        'label'     => ($cl['label'] ?? '') ?: ($clDevice['description'] ?? $clExt),
                        'displayName' => $clDevice['description'] ?? (($cl['label'] ?? '') ?: $clExt),
                        'name'      => $clExt,
                        'authName'  => $clExt,
                        'secret'    => $clDevice['secret'] ?? '',
                        'contact'   => $clExt,
                    ];
                    $usedAccounts[$accountIdx] = true;
                    $lineIdx = max($lineIdx, $accountIdx + 1);
                    // Account slots may exceed visible key capacity (for example a 16-account
                    // Yealink with ten base DSS positions). Only actual visible appearances
                    // consume positions in the template key allocator.
                    if (!$isGhp63x && $btnNum >= 2 && $btnNum <= $catalogLineKeys) {
                        $occupied[$btnNum] = true;
                    }
                }
            }
        }

        $family = (string)($model['family'] ?? '');
        $this->phoneServicesUrl = $this->phoneServicesUrl($family, $phone, $settings);
        $group = null;
        if ((int)($phone['group_id'] ?? 0)>0) {
            foreach ($this->templateGroupsForSite($siteId) as $candidate) { if ((int)$candidate['id'] === (int)$phone['group_id']) { $group=$candidate; break; } }
            if (!$group) { throw new \RuntimeException('Assigned provisioning group is unavailable on the owner site'); }
        }
        $stack = $this->templateStackForGroup(
            $group,
            $model ?: [],
            $family,
            $fullDeviceResolution,
            (string)($phone['extension'] ?? '')
        );
        $template = $stack['template'];
        if (!empty($stack['trace'])) {
            $settings['_template_trace'] = $stack['trace'];
            $settings['_template_group_path'] = $stack['group_path'];
        }

        // Per-template setting overrides (ringtone, background, display, etc.) apply regardless of
        // whether the model has free line keys for the button layout below.
        if ($template) {
            foreach ((array)($template['_setting_layers'] ?? []) as $layer) {
                foreach ((array)$layer['values'] as $key=>$value) { $this->settingTrace[$key][] = ['source'=>'template','site_id'=>$siteId,'id'=>$layer['id'],'name'=>$layer['name'],'value'=>self::explainValue($key,$value)]; }
            }
            $tplSettings = $this->decode($template['setting_overrides'] ?? null);
            if (is_array($tplSettings)) {
                foreach ($tplSettings as $k => $v) {
                    $settings[$k] = $v;
                }
            }
            if ($family === 'cisco_cnf' && !empty($settings['cisco_legacy_directory_url'])) {
                $settings['cisco_directory_url'] = $settings['cisco_legacy_directory_url'];
                $settings['cisco_directoryURL'] = $settings['cisco_legacy_directory_url'];
            }
            $settings['_template_id'] = (int) $template['id'];
            $settings['_template_stack'] = array_map('intval', (array)($template['_stack_ids'] ?? [$template['id']]));
            $settings['_template_key'] = (string)($template['_stack_key'] ?? $template['id']);

            // Custom softkey/feature-policy layout -> a template-specific file, so unrelated
            // templates keep using the owning site's default; another site is never consulted.
            $skLayout = $this->decode($template['softkeys'] ?? null);
            $hasSoftkeySets = false;
            if (is_array($skLayout) && $skLayout) {
                if (SoftkeyPolicy::isV2($skLayout)) {
                    foreach ((array)($skLayout['states'] ?? []) as $keys) {
                        if (is_array($keys)) {
                            $hasSoftkeySets = true;
                            break;
                        }
                    }
                } else {
                    $hasSoftkeySets = true;
                    // Older schema-less templates implicitly enabled the separate
                    // 7800 Recents product setting. Once an operator saves the
                    // current schema, its explicit Inherit/Enabled/Disabled choice
                    // is authoritative and this compatibility branch is bypassed.
                    if (preg_match('/^78/', $modelName)
                        && !array_key_exists('cisco_recents_softkey', $settings)) {
                        $settings['cisco_recents_softkey'] = '1';
                    }
                }
            }
            if ($hasSoftkeySets) {
                $settings['softkey_file'] = AssetFilename::content('s', (new SoftkeyPolicyGenerator())->softkeysXml($skLayout), 'xml');
                $settings['_softkey_layout'] = $skLayout;
            }
            $fpLayout = $this->decode($template['feature_policy'] ?? null);
            if (is_array($fpLayout) && $fpLayout) {
                $settings['featurepolicy_file'] = AssetFilename::content('f', (new SoftkeyPolicyGenerator())->featurePolicyXml($fpLayout), 'xml');
                $settings['_feature_policy_layout'] = $fpLayout;
            }
        }

        // Resolve label policy before any BLF key is materialized. Other phone
        // settings retain their existing later merge and validation order.
        $ov = $this->decode($phone['overrides'] ?? null);
        foreach (['blf_label_source','phone_label_pattern','line_label_pattern','blf_label_pattern','directory_label_pattern'] as $key) {
            $value = $ov['settings'][$key] ?? null;
            if ($value !== null && $value !== '') { $settings[$key] = $value; }
        }
        $candidateLabelSource = (string)($settings['blf_label_source'] ?? 'phone_label');
        $this->blfLabelSource = in_array($candidateLabelSource, ['phone_label', 'extension_name', 'extension', 'custom'], true)
            ? $candidateLabelSource
            : 'phone_label';
        foreach (['phone_label','line_label','blf_label','directory_label'] as $labelField) {
            $patternKey = $labelField . '_pattern';
            if (array_key_exists($patternKey, $settings)) {
                $this->labelPatterns[$labelField] = (string)$settings[$patternKey];
            }
        }
        if ($this->blfLabelSource !== 'custom' && isset($this->labelPatterns['blf_label'])) {
            $this->blfLabelSource = 'identity_pattern';
        }

        // AD remains the preferred identity authority. Unlinked extensions use
        // FreePBX identity and finally the legacy phone label, while the same
        // template-pattern and audited-final-override precedence still applies.

        // Expansion modules extend the physical key range used by the same ordered template.
        // Their vendor-specific rendering happens in the generator; unsupported models never
        // gain synthetic capacity merely because a template contains expansion settings.
        $lineKeys = $catalogLineKeys + $this->expansionCapacity($family, $settings, $model ?: []);
        $lineKeys = $isDectBase ? 0 : max(1, $lineKeys);
        $settings['_base_line_keys'] = $isDectBase ? 0 : max(1, $catalogLineKeys);
        $settings['_total_line_keys'] = $lineKeys;
        $freeKeysCount = max(0, $lineKeys - count($occupied));
        if ($freeKeysCount > 0 && $template) {
            $ordered = $this->orderedButtons($template, $freeKeysCount);
            if ($group && !empty($template['member_blf']) && !empty($group['member_blf'])) {
                $ordered = $this->expandGroupMembers($ordered, $group, (int) $phone['id']);
            } else {
                $ordered = array_values(array_filter($ordered, fn($b) => ($b['feature'] ?? '') !== 'group_members'));
            }
            $extra = $group ? $this->decode($group['extra_targets'] ?? null) : null;
            if (is_array($extra) && $extra) {
                $ordered = array_merge($ordered, array_values($extra));
            }
            $btn = 2;
            foreach ($ordered as $b) {
                $requested = isset($b['slot']) ? (int)$b['slot'] : 0;
                if ($requested > 1) {
                    // Explicit placement is exact. An occupied/invalid slot is not silently moved
                    // because that would destroy intentional blank spaces on a KEM.
                    if ($requested > $lineKeys || isset($occupied[$requested])) {
                        continue;
                    }
                    $lines[] = $this->materializeButton($b, $requested);
                    $occupied[$requested] = true;
                    continue;
                }
                while (isset($occupied[$btn]) && $btn <= $lineKeys) {
                    $btn++;
                }
                if ($btn > $lineKeys) {
                    break;
                }
                $lines[] = $this->materializeButton($b, $btn);
                $occupied[$btn] = true;
                $btn++;
            }
        }

        // Sort lines by button number for consistency
        usort($lines, fn($a, $b) => $a['button'] <=> $b['button']);

        // Per-phone overrides: overrides.buttons = full replace of buttons 2..N
        if (is_array($ov)) {
            foreach ((array)($ov['settings'] ?? []) as $key => $value) {
                if ($value !== null && $value !== '') {
                    $settings[$key] = $value;
                }
            }
            // Backward-compatible flat per-phone settings used by the old DECT editor.
            foreach ($ov as $key => $value) {
                if ($key !== 'buttons' && $key !== 'settings' && (strpos((string)$key, 'gs_') === 0 || strpos((string)$key, 'yl_') === 0 || strpos((string)$key, 'account.') === 0 || $key === 'is_dect' || $key === 'dect_handsets')) {
                    $settings[$key] = $value;
                }
            }
        }
        if (array_key_exists('buttons', $ov) && is_array($ov['buttons'])) {
            // buttons is a full replacement of programmable appearances only. SIP
            // registrations (including additional desk accounts and every DECT
            // account) must survive the override.
            $lines = array_values(array_filter($lines, static fn(array $line): bool => (int)($line['featureId'] ?? 0) === 9));
            $occupied = $isGhp63x ? [1=>true] : [];
            if (!$isGhp63x) {
                foreach ($lines as $line) {
                    $appearance = (int)($line['button'] ?? 0);
                    if ($appearance >= 1 && $appearance <= $lineKeys) { $occupied[$appearance] = true; }
                }
            }
            $btn = 2;
            foreach ($ov['buttons'] as $b) {
                $requested = isset($b['slot']) ? (int)$b['slot'] : 0;
                if ($requested > 1) {
                    if ($requested > $lineKeys || isset($occupied[$requested])) { continue; }
                    $lines[] = $this->materializeButton($b, $requested);
                    $occupied[$requested] = true;
                    continue;
                }
                while (isset($occupied[$btn]) && $btn <= $lineKeys) { $btn++; }
                if ($btn > $lineKeys) {
                    break;
                }
                $lines[] = $this->materializeButton($b, $btn);
                $occupied[$btn] = true;
                $btn++;
            }
        }

        // Compose directory labels from the final phone/template policy.
        $phonebookKey = trim((string)($template['phonebook_key'] ?? ''));
        $phonebookFilename = trim((string)($template['phonebook_filename'] ?? ''));
        $phonebookSourceUrl = trim((string)($template['phonebook_source_url'] ?? ''));
        $directoryLabelPattern = array_key_exists('directory_label_pattern', $settings)
            ? (string)$settings['directory_label_pattern']
            : null;
        $directoryUrlKeys = ['cisco_directory_url','cisco_directoryURL','cisco_legacy_directory_url','directory_url','yealink_phonebook_url','grandstream_phonebook_url','phonebook_url'];
        if ($phonebookKey === self::PHONEBOOK_NONE) {
            foreach ($directoryUrlKeys as $urlKey) { unset($settings[$urlKey]); }
        } else {
            if ($phonebookKey === self::PHONEBOOK_SITE_DEFAULT) {
                // This is an explicit stack reset: use the owning PBX's normal
                // published default and ignore a lower tier's alias/source.
                $phonebookKey = '';
                $phonebookFilename = '';
                $phonebookSourceUrl = '';
            }
            if ($phonebookKey !== '' || $phonebookFilename !== '' || $phonebookSourceUrl !== '' || $directoryLabelPattern !== null) {
                foreach ($directoryUrlKeys as $urlKey) {
                    if (!empty($settings[$urlKey])) {
                        if ($phonebookSourceUrl !== '') { $settings[$urlKey] = $this->phonebookSourceUrl((string)$settings[$urlKey], $phonebookSourceUrl); }
                        $settings[$urlKey] = $this->phonebookUrl(
                            (string)$settings[$urlKey],
                            $phonebookKey,
                            $phonebookFilename,
                            $directoryLabelPattern
                        );
                    }
                }
            }
        }

        // Apply presentation after all account/phone overrides, without changing
        // account names, authentication identities, credentials or routing.
        $this->applyIdentityLabels($phone, $lines, $settings, $siteId, $family);
        foreach ((array)($ov['settings'] ?? []) as $key=>$value) { $this->settingTrace[$key][] = ['source'=>'phone','site_id'=>$siteId,'id'=>(int)($phone['id'] ?? 0),'value'=>self::explainValue($key,$value)]; }
        foreach ((array)$ov as $key=>$value) { if ($key !== 'buttons' && $key !== 'settings' && (str_starts_with((string)$key,'gs_') || str_starts_with((string)$key,'yl_') || str_starts_with((string)$key,'account.') || in_array($key,['is_dect','dect_handsets'],true))) { $this->settingTrace[$key][] = ['source'=>'phone','site_id'=>$siteId,'id'=>(int)($phone['id'] ?? 0),'value'=>self::explainValue($key,$value)]; } }

        // Driver/transport/port are one endpoint tuple. Templates cannot silently
        // split the generated phone from the FreePBX device; only the appropriate
        // site default and an explicit Phones-tab override participate here.
        $endpoint = EndpointProfile::resolve($phone, $model ?: [], $settings);
        $settings['driver'] = $endpoint['driver'];
        $settings['transport'] = $endpoint['transport'];
        $settings['sip_port'] = (string)$endpoint['port'];
        $settings['_endpoint_profile'] = $endpoint;

        foreach ($lines as $line) {
            if ((int)($line['featureId'] ?? 0) !== 9) { continue; }
            $lineExtension = trim((string)($line['name'] ?? ''));
            if ($lineExtension === '') { continue; }
            $actualDriver = $this->driverForExtension($lineExtension, $siteId);
            if ($actualDriver !== '' && $actualDriver !== $endpoint['driver']) {
                throw new \RuntimeException(sprintf(
                    'Extension %s is a %s device in FreePBX, but this phone resolves to %s. Change the extension driver in FreePBX or select a matching phone override.',
                    $lineExtension,
                    EndpointProfile::driverLabel($actualDriver),
                    EndpointProfile::driverLabel($endpoint['driver'])
                ));
            }
        }

        $settings['_vlan_policy'] = VlanPolicy::apply($family, $model ?: [], $settings);

        // Resolve site defaults and broad-template selections against this phone's
        // actual compatibility profiles. The generated config still receives one
        // concrete load/wallpaper; maps never leak into vendor output.
        if ($fullDeviceResolution) {
            $this->resolveProfileAssets($family, $model ?: [], $settings);
            if ($family === 'cisco_xml') {
                $this->resolveCiscoTimezone($siteId, $model ?: [], $settings);
                $this->resolveCiscoLocale($siteId, $model ?: [], $settings);
            }
        }

        // Managed service routes are always per-phone. Re-assert them after every
        // template/phone override so a stale site-wide URL cannot carry another
        // family's format or another handset's MAC into the generated config.
        if ($this->phoneServicesUrl !== '') {
            $settings['_phone_services_url'] = $this->phoneServicesUrl;
            if ($family === 'cisco_xml') {
                $settings['cisco_servicesURL'] = $this->phoneServicesUrl;
            } elseif ($family === 'cisco_cnf') {
                $settings['cisco_services_url'] = $this->phoneServicesUrl;
            }
        }

        // Validate only the final settings, after per-phone overrides. Otherwise a
        // phone override could replace a previously validated load with arbitrary text.
        if ($fullDeviceResolution) {
            $this->assertAllFirmwareSelections($siteId, $family, $model ?: [], $settings);
            $this->resolveManagedFirmwareEndpoint($family, $settings);
        }

        foreach ($settings as $key=>$value) {
            if (str_starts_with((string)$key,'_')) { continue; }
            $value = self::explainValue($key,$value);
            $history = $this->settingTrace[$key] ?? [];
            if (!$history || end($history)['value'] !== $value) { $this->settingTrace[$key][] = ['source'=>'derived','value'=>$value]; }
        }
        $settings['_setting_trace'] = $this->settingTrace;
        return [
            'phone'    => $phone,
            'device'   => $device,
            'model'    => $model,
            'settings' => $settings,
            'lines'    => $lines,
        ];
    }

    private function applyIdentityLabels(
        array &$phone,
        array &$lines,
        array &$settings,
        int $siteId,
        string $family
    ): void {
        $patterns = [];
        foreach (LogicalExtensionService::FINAL_FIELDS as $field) {
            if (array_key_exists($field . '_pattern', $settings)) { $patterns[$field] = (string)$settings[$field . '_pattern']; }
        }
        $site = $this->ucm->getSite($siteId) ?: [];
        $formatter = static fn(array $canonical): array=>IdentityLabelFormatter::outputs($canonical, $patterns, ['site'=>(string)($site['name'] ?? '')]);
        $directory = method_exists($this->ucm, 'getDb') ? new DirectoryService($this->ucm) : null;
        $warnings = [];
        $settings['_identity_accounts'] = [];
        foreach ($lines as &$line) {
            if ((int)($line['featureId'] ?? 0) !== 9) { continue; }
            $extension = trim((string)($line['name'] ?? ''));
            if ($extension === '') { continue; }
            $primary = (int)($line['accountIndex'] ?? $line['lineIndex'] ?? 0) === 1;
            try {
                $resolved = $this->identityPreview ? IdentityDeliveryService::resolveCached($this->ucm,$siteId,$extension,$formatter,$this->identityPreview) : null;
                $resolved ??= $directory ? $directory->resolveExtensionIdentity($siteId, $extension, $formatter) : null;
                if ($resolved !== null && (empty($resolved['resolved']) || ($resolved['binding']['adoption'] ?? '') !== 'managed')) {
                    $reason = empty($resolved['resolved']) ? 'Person identity is unresolved; existing presentation retained' : 'Legacy identity adoption review is required';
                    $settings['_identity_accounts'][$extension] = ['resolved'=>(bool)($resolved['resolved'] ?? false),
                        'binding'=>$resolved['binding'] ?? [], 'full_name'=>$resolved['full_name'] ?? null,
                        'short_name'=>$resolved['short_name'] ?? null, 'effective_label'=>$line['label'] ?? '',
                        'effective_caller'=>$line['displayName'] ?? '', 'state'=>empty($resolved['resolved']) ? 'unresolved' : 'review_required', 'warnings'=>[$reason]];
                    $warnings[] = $extension . ': ' . $reason;
                    continue;
                }
                if ($resolved === null) {
                    $display = trim((string)($line['displayName'] ?? '')) ?: $extension;
                    $label = trim((string)($line['label'] ?? '')) ?: $display;
                    $resolved = LogicalExtensionService::resolveProvisioningIdentity(
                        ['display_name'=>$display, 'label'=>$label, 'extension'=>$extension], 'freepbx', [], $formatter
                    );
                }
                $syncLabel = $resolved['delivery_policy']['sync_label'] ?? true;
                $syncName = $resolved['delivery_policy']['sync_name'] ?? true;
                if ($syncLabel) { $line['label'] = $resolved['outputs']['line_label']; }
                if ($syncName) { $line['displayName'] = $resolved['canonical']['display_name']; }
                $settings['_identity_accounts'][$extension] = ['resolved'=>true, 'binding'=>$resolved['binding'] ?? [],
                    'full_name'=>$line['displayName'], 'short_name'=>$resolved['canonical']['label'],
                    'effective_label'=>$line['label'], 'effective_caller'=>$line['displayName'],
                    'state'=>'desired', 'trace'=>$resolved['trace'], 'warnings'=>$resolved['warnings'] ?? []];
                foreach (($resolved['warnings'] ?? []) as $warning) { $warnings[] = $extension . ': ' . $warning; }
                if ($primary) {
                    if ($syncLabel) { $phone['label'] = $resolved['outputs']['phone_label']; }
                    $settings['_identity_trace'] = $resolved['trace'];
                    $settings['_identity_base_source'] = (string)($resolved['base_source'] ?? 'ad');
                    $settings['_label_preview'] = [
                        'managed'=>$phone['label'], 'firmware_suffix'=>$family === 'cisco_cnf' ? 'SIP' : '',
                        'expected'=>$phone['label'], 'line'=>$line['label'], 'caller'=>$line['displayName'],
                        'blf'=>$resolved['outputs']['blf_label'], 'directory'=>$resolved['outputs']['directory_label'],
                    ];
                }
            } catch (\Throwable $e) {
                $warnings[] = $extension . ': Identity resolution unavailable; existing presentation retained: ' . $e->getMessage();
            }
        }
        unset($line);
        if ($warnings) { $settings['_identity_warning'] = implode('; ', array_unique($warnings)); }
    }

    private function resolveProfileAssets(string $family, array $model, array &$settings): void
    {
        $firmwareProfile = ModelCatalog::firmwareProfile((string)($model['vendor'] ?? ''), (string)($model['model'] ?? ''));
        if ($firmwareProfile !== null) {
            $siteMap = $this->decode($settings['firmware_defaults'] ?? null);
            $templateMap = $this->decode($settings['firmware_by_profile'] ?? null);
            $firmware = trim((string)($settings['firmware'] ?? ''));
            if ($firmware === '') {
                $firmware = trim((string)($templateMap[$firmwareProfile] ?? $siteMap[$firmwareProfile] ?? ''));
                if ($firmware !== '') {
                    $settings['firmware'] = $firmware;
                    $settings['firmware_profile'] = $firmwareProfile;
                }
            }

            $inactiveMap = $this->decode($settings['inactive_firmware_by_profile'] ?? null);
            if (trim((string)($settings['inactive_firmware'] ?? '')) === '' && !empty($inactiveMap[$firmwareProfile])) {
                $settings['inactive_firmware'] = (string)$inactiveMap[$firmwareProfile];
            }
        }

        if ($family === 'cisco_xml' && trim((string)($settings['cisco_default_wallpaper'] ?? '')) === '') {
            $backgroundProfile = ModelCatalog::backgroundProfileKey((string)($model['vendor'] ?? ''), (string)($model['model'] ?? ''));
            if ($backgroundProfile !== null) {
                $siteMap = $this->decode($settings['cisco_wallpaper_defaults'] ?? null);
                $templateMap = $this->decode($settings['cisco_wallpaper_by_profile'] ?? null);
                $wallpaper = trim((string)($templateMap[$backgroundProfile] ?? $siteMap[$backgroundProfile] ?? ''));
                if ($wallpaper !== '') { $settings['cisco_default_wallpaper'] = $wallpaper; }
            }
        }
        if ($family === 'cisco_xml') {
            $branch = ModelCatalog::ciscoXmlBranchPolicy(
                (string)($model['vendor'] ?? ''),
                (string)($model['model'] ?? ''),
                (string)($model['family'] ?? '')
            );
            if ($branch !== null) { $settings['_cisco_branch_policy'] = $branch; }
            if (trim((string)($settings['cisco_default_wallpaper'] ?? '')) !== '') {
                $support = ModelCatalog::ciscoDefaultWallpaperSupport(
                    (string)($model['vendor'] ?? ''),
                    (string)($model['model'] ?? ''),
                    (string)($settings['firmware'] ?? '')
                );
                $settings['_cisco_wallpaper_support'] = $support;
                if ($support['supported'] === false) {
                    unset($settings['cisco_default_wallpaper']);
                    $settings['_cisco_wallpaper_warning'] = (string)$support['message'] . ' The managed default was omitted.';
                } else {
                    // Cisco applies defaultWallpaperFile only while user background
                    // selection is disabled. Unknown running loads are preserved with
                    // an explicit warning instead of silently removing an existing setup.
                    $settings['background_access_disabled'] = '1';
                    if ($support['supported'] === null) {
                        $settings['_cisco_wallpaper_warning'] = (string)$support['message'];
                    }
                }
            }
        }

        if (trim((string)($settings['hosted_image_url'] ?? '')) === '') {
            $hosted = $this->decode($settings['hosted_image_defaults'] ?? null);
            $templateHosted = $this->decode($settings['hosted_image_by_profile'] ?? null);
            $mediaProfile = ModelCatalog::hostedMediaProfileKey((string)($model['vendor'] ?? ''), (string)($model['model'] ?? ''));
            $url = $mediaProfile !== null ? (string)($templateHosted[$mediaProfile] ?? $hosted[$mediaProfile] ?? '') : '';
            if ($url === '') { $url = (string)($hosted[$family] ?? ''); } // migrate pre-profile site defaults
            if ($url !== '') { $settings['hosted_image_url'] = $url; }
        }
    }

    /** Resolve Cisco XML timezone data from the owning site's real TFTP inventory. */
    private function resolveCiscoTimezone(int $siteId, array $model, array &$settings): void
    {
        unset(
            $settings['cisco_xml_tzdata_version'],
            $settings['cisco_xml_tzdata_file'],
            $settings['_cisco_tzdata_version'],
            $settings['_cisco_tzdata_file'],
            $settings['_cisco_tzdata_asset_key'],
            $settings['_cisco_tzdata_bundle_key'],
            $settings['_cisco_tzdata_iana_version'],
            $settings['_cisco_tzdata_status'],
            $settings['_cisco_tzdata_set_status'],
            $settings['_cisco_tzdata_warning']
        );
        if (trim((string)($settings['cisco_xml_olson_timezone'] ?? '')) === '') {
            return;
        }
        $profile = ModelCatalog::ciscoTimezoneProfile(
            (string)($model['vendor'] ?? ''),
            (string)($model['model'] ?? ''),
            (string)($model['family'] ?? '')
        );
        if ($profile === null) {
            $settings['_cisco_tzdata_warning'] = 'This Cisco XML model has no documented timezone runtime mapping.';
            return;
        }
        $payload = (string)ModelCatalog::CISCO_TIMEZONE_PROFILES[$profile]['payload'];
        if (!array_key_exists($siteId, $this->timezoneInventory)) {
            try {
                $this->timezoneInventory[$siteId] = $this->ucm->tftpOp([
                    'op'=>'timezone_versions',
                    'site_id'=>$siteId,
                ]);
            } catch (\Throwable $e) {
                $this->timezoneInventory[$siteId] = [
                    'ok'=>false,
                    'status'=>'unreachable',
                    'message'=>$e->getMessage(),
                ];
            }
        }
        $versionInventory = (array)$this->timezoneInventory[$siteId];
        if (empty($versionInventory['ok'])) {
            $settings['_cisco_tzdata_status'] = 'unreachable';
            $settings['_cisco_tzdata_warning'] = 'Cisco timezone assets are unavailable on this site: '
                . (string)($versionInventory['message'] ?? 'inventory request failed');
            return;
        }
        $inventory = is_array($versionInventory['current'] ?? null)
            ? (array)$versionInventory['current']
            : $versionInventory;
        $settings['_cisco_tzdata_set_status'] = (string)($inventory['status'] ?? 'unknown');
        $templateMap = $this->decode($settings['cisco_timezone_by_profile'] ?? null);
        $defaultMap = $this->decode($settings['cisco_timezone_defaults'] ?? null);
        $templateKey = strtolower(trim((string)($templateMap[$profile] ?? '')));
        $defaultKey = strtolower(trim((string)($defaultMap[$profile] ?? '')));
        $selectedKey = $templateKey !== '' ? $templateKey : $defaultKey;
        $selectionSource = $templateKey !== '' ? 'template' : ($defaultKey !== '' ? 'site default' : '');
        $assets = (array)($versionInventory['assets'] ?? []);
        $findAsset = static function (string $key) use ($assets, $profile): ?array {
            if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) { return null; }
            foreach ($assets as $candidate) {
                if (is_array($candidate)
                    && (string)($candidate['profile'] ?? '') === $profile
                    && !empty($candidate['selectable'])
                    && hash_equals($key, (string)($candidate['asset_key'] ?? ''))) {
                    return $candidate;
                }
            }
            return null;
        };
        $asset = $selectedKey !== '' ? $findAsset($selectedKey) : null;
        $fallbackWarning = '';
        if ($asset === null && $templateKey !== '') {
            $fallbackWarning = 'The template-selected Cisco timezone asset is unavailable; using this destination site’s runtime default.';
            $selectedKey = $defaultKey;
            $selectionSource = $defaultKey !== '' ? 'site default fallback' : '';
            $asset = $selectedKey !== '' ? $findAsset($selectedKey) : null;
        }
        if ($asset === null && $defaultKey !== '') {
            $fallbackWarning = trim($fallbackWarning . ' The configured site-default timezone asset is unavailable; using the recognized canonical payload.');
        }
        // Migrate a pre-profile bundle pin without requiring the bundle to own
        // every other architecture in the same historical capture.
        if ($asset === null && $templateKey === '' && $defaultKey === '') {
            $legacyBundle = strtolower(trim((string)($settings['cisco_timezone_bundle_key'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $legacyBundle) === 1) {
                foreach ($assets as $candidate) {
                    if (is_array($candidate)
                        && (string)($candidate['profile'] ?? '') === $profile
                        && !empty($candidate['selectable'])
                        && hash_equals($legacyBundle, (string)($candidate['source_bundle_key'] ?? ''))) {
                        $asset = $candidate;
                        $selectionSource = 'legacy template migration';
                        break;
                    }
                }
            }
        }
        if ($asset !== null) {
            $filename = (string)($asset['published_filename'] ?? '');
            $version = trim((string)($asset['version'] ?? ''));
            if (!AssetFilename::isTimezone($filename)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $version) !== 1) {
                $settings['_cisco_tzdata_status'] = 'invalid_asset';
                $settings['_cisco_tzdata_warning'] = 'The selected Cisco timezone asset has unsafe publication metadata.';
                return;
            }
            $settings['_cisco_tzdata_version'] = $version;
            $settings['_cisco_tzdata_file'] = $filename;
            $settings['_cisco_tzdata_asset_key'] = (string)$asset['asset_key'];
            $settings['_cisco_tzdata_bundle_key'] = (string)($asset['source_bundle_key'] ?? '');
            $settings['_cisco_tzdata_iana_version'] = (string)($asset['actual_version'] ?? '');
            $settings['_cisco_tzdata_status'] = (string)($asset['status'] ?? 'usable');
            if ($fallbackWarning !== '') {
                $settings['_cisco_tzdata_warning'] = $fallbackWarning;
            } elseif ((string)($asset['actual_version'] ?? '') === '') {
                $settings['_cisco_tzdata_warning'] = $payload . ' was selected from the ' . $selectionSource
                    . ' by verified SHA-256, but its embedded IANA release is opaque; the module uses a stable content version stamp.';
            }
            return;
        }

        // Backward-compatible fallback for a canonical root file that has not
        // yet been retained by the Firmware page's explicit scan action.
        $profiles = (array)($inventory['compatibility'] ?? []);
        $profileReady = !$profiles && ($inventory['status'] ?? '') === 'complete';
        $profileState = null;
        foreach ($profiles as $profile) {
            if (!is_array($profile) || ($profile['payload'] ?? '') !== $payload) { continue; }
            $profileState = $profile;
            $profileReady = ($profile['status'] ?? '') === 'complete'
                || !empty($profile['configuration_usable']);
            break;
        }
        $settings['_cisco_tzdata_status'] = (string)($profileState['status'] ?? ($profileReady ? 'complete' : 'unknown'));
        if (!$profileReady) {
            $detail = trim((string)($inventory['message'] ?? ''));
            if ($detail === '' && is_array($profileState)) {
                $advertised = trim((string)($profileState['advertised_iana_version'] ?? ''));
                $actual = trim((string)($profileState['actual_version'] ?? ''));
                if (($profileState['status'] ?? '') === 'legacy_frozen' && $advertised !== '' && $actual !== '') {
                    $detail = $payload . ' contains legacy IANA ' . $actual
                        . ' while tzupdater.ver advertises ' . $advertised
                        . '. Cisco stopped refreshing this Java-phone payload from the 2021a COP onward; upload and capture a newer tzupdater.jar before advertising it to this model';
                } elseif (($profileState['status'] ?? '') === 'mismatch' && $advertised !== '' && $actual !== '') {
                    $detail = $payload . ' contains IANA ' . $actual
                        . ' while tzupdater.ver advertises ' . $advertised;
                } elseif (!empty($profileState['missing'])) {
                    $detail = 'missing or unreadable: ' . implode(', ', (array)$profileState['missing']);
                }
            }
            if ($detail === '') {
                $detail = implode('; ', array_filter(array_map(
                    static fn(array $issue): string => (string)($issue['message'] ?? ''),
                    (array)($inventory['issues'] ?? [])
                )));
            }
            $settings['_cisco_tzdata_warning'] = 'This site has no usable timezone payload for the selected Cisco model'
                . ($detail !== '' ? ': ' . $detail : '.');
            return;
        }
        $settings['_cisco_tzdata_version'] = (string)($inventory['served_version'] ?? '');
        $settings['_cisco_tzdata_file'] = $payload;
        $settings['_cisco_tzdata_iana_version'] = (string)($profileState['actual_version'] ?? $inventory['iana_version'] ?? '');
        if (($profileState['status'] ?? '') === 'unverified') {
            $settings['_cisco_tzdata_warning'] = $payload
                . ' is present and will be advertised to this phone family, but its embedded IANA release could not be independently read.';
        } elseif ($fallbackWarning !== '') {
            $settings['_cisco_tzdata_warning'] = $fallbackWarning;
        }
    }

    /**
     * A custom Cisco locale is safe only when the exact model-profile bundle is
     * present at the paths the handset requests. Inventory is cached once per
     * site and user-locale directory for a complete multi-phone build.
     */
    private function resolveCiscoLocale(int $siteId, array $model, array &$settings): void
    {
        unset($settings['_cisco_locale_usable'], $settings['_cisco_locale_warning']);
        $locale = trim((string)($settings['cisco_networkLocale'] ?? ($settings['network_locale'] ?? '')));
        if ($locale === '') { return; }
        $policy = ModelCatalog::ciscoXmlBranchPolicy(
            (string)($model['vendor'] ?? ''),
            (string)($model['model'] ?? ''),
            (string)($model['family'] ?? '')
        );
        $profile = trim((string)($policy['locale_profile'] ?? ''));
        if ($profile === '') {
            $settings['_cisco_locale_usable'] = false;
            $settings['_cisco_locale_warning'] = "Cisco network locale '$locale' was omitted because this firmware branch has no verified locale-companion profile.";
            return;
        }
        $userLocale = trim((string)($settings['cisco_userLocale'] ?? 'English_United_States')) ?: 'English_United_States';
        $networkLocale = trim((string)($settings['cisco_networkLocale'] ?? ''));
        $cacheKey = $siteId . ':' . strtolower($userLocale) . ':' . strtolower($networkLocale);
        if (!array_key_exists($cacheKey, $this->localeInventory)) {
            try {
                $this->localeInventory[$cacheKey] = $this->ucm->tftpOp([
                    'op'=>'cisco_locale_inventory', 'site_id'=>$siteId,
                    'user_locale'=>$userLocale, 'network_locale'=>$networkLocale,
                ]);
            } catch (\Throwable $e) {
                $this->localeInventory[$cacheKey] = ['ok'=>false, 'profiles'=>[], 'message'=>$e->getMessage()];
            }
        }
        $inventory = $this->localeInventory[$cacheKey];
        $profileState = (array)($inventory['profiles'][$profile] ?? []);
        $usable = !empty($inventory['ok']) && ($profileState['status'] ?? '') === 'complete';
        $settings['_cisco_locale_usable'] = $usable;
        if (!$usable) {
            $detail = trim((string)($inventory['message'] ?? ''));
            $missing = array_values(array_filter(array_map(
                static fn(array $file): string => empty($file['usable']) ? (string)($file['path'] ?? '') : '',
                (array)($profileState['files'] ?? [])
            )));
            $reason = $missing ? 'missing or invalid: ' . implode(', ', $missing) : 'the bundle could not be verified';
            $settings['_cisco_locale_warning'] = "Cisco network locale '$locale' was omitted because its $profile bundle is incomplete ($reason)"
                . ($detail !== '' ? ': ' . $detail : '.');
        }
    }

    /** Choose the ordered button list for the given free-key count (hybrid tiers). */
    private function orderedButtons(array $template, int $freeKeys): array
    {
        $overrides = $this->decode($template['capacity_overrides'] ?? null);
        if (is_array($overrides) && isset($overrides[(string) $freeKeys])) {
            return (array) $overrides[(string) $freeKeys];
        }
        return (array) $this->decode($template['buttons'] ?? null);
    }

    private function expansionCapacity(string $family, array $settings, array $model): int
    {
        $expansionTypes = $this->decode($model['expansion_types'] ?? null);
        $supportsExpansion = !empty($model['kem_support'])
            || !empty($expansionTypes)
            || (int)($model['max_expansion_modules'] ?? 0) > 0;
        if (!$supportsExpansion) {
            return 0;
        }
        $settingKey = $family === 'yealink' ? 'yealink_expansion_count'
            : ($family === 'grandstream' ? 'grandstream_expansion_count' : 'cisco_kem_count');
        $maxModules = max(0, (int)($model['max_expansion_modules'] ?? 0));
        if ($maxModules === 0) { $maxModules = $family === 'grandstream' ? 4 : 3; }
        $count = max(0, min($maxModules, (int)($settings[$settingKey] ?? 0)));
        if ($count === 0) {
            return 0;
        }
        if ($family === 'cisco_xml') {
            $type = (string)($settings['cisco_kem_type'] ?? 'BEKEM');
            $sizes = ['7914' => 14, '7915' => 24, '7916' => 24, 'BEKEM' => 36, 'CP-8800-Audio' => 28, 'CP-8800-Video' => 28, 'CKEM' => 36];
            $allowed = $expansionTypes;
            if ($allowed && !in_array($type, $allowed, true)) {
                throw new \RuntimeException(sprintf('%s is not a supported expansion module for Cisco %s', $type, (string)($model['model'] ?? 'phone')));
            }
            return $count * ($sizes[$type] ?? 0);
        }
        $catalogKeys = max(0, (int)($model['keys_per_expansion'] ?? 0));
        if ($catalogKeys > 0) { return $count * $catalogKeys; }
        if ($family === 'yealink') {
            return $count * 60;
        }
        if ($family === 'grandstream') {
            return $count * 40;
        }
        return 0;
    }

    /** Expand any {feature:"group_members"} entry into a BLF per other group member. */
    private function expandGroupMembers(array $buttons, array $group, int $selfPhoneId): array
    {
        $out = [];
        foreach ($buttons as $b) {
            if (($b['feature'] ?? '') !== 'group_members') {
                $out[] = $b;
                continue;
            }
            $db = $this->ucm->getDb();
            $st = $db->prepare("SELECT extension, label FROM ucmp_phones WHERE group_id = ? AND id <> ? AND extension IS NOT NULL ORDER BY extension");
            $st->execute([(int) $group['id'], $selfPhoneId]);
            $memberOffset = 0;
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $m) {
                $item = [
                    'feature' => 'blf',
                    // Apply the template's label policy to generated member BLFs too.
                    'label'   => '',
                    'target'  => $m['extension'],
                ];
                if (isset($b['slot'])) {
                    $item['slot'] = (int)$b['slot'] + $memberOffset;
                }
                $memberOffset++;
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Turn a template button def into a concrete line-key structure. */
    private function materializeButton(array $b, int $button): array
    {
        $feature = $b['feature'] ?? 'speeddial';
        // If the feature is numeric (Yealink/Grandstream specific), featureId doesn't matter for Cisco.
        $featureId = self::FEATURE_IDS[$feature] ?? (is_numeric($feature) ? (int)$feature : 2);
        
        $label = trim((string)($b['label'] ?? ''));
        $resolvedLabel = null;
        if (in_array($feature, ['blf', 'blfpark'], true) && !empty($b['target']) && ($this->blfLabelSource !== 'custom' || $label === '')) {
            $resolvedLabel = $this->labelForTarget((string)$b['target'], $b['target_site'] ?? null);
        }
        
        $target = (string)($b['target'] ?? '');
        if ($target === '{services}') { $target = $this->phoneServicesUrl; }
        $line = [
            'button'    => $button,
            'feature'   => $feature,
            'featureId' => $featureId,
            'label'     => $resolvedLabel ?? ($label !== '' ? $label : $target),
        ];
        if (in_array($feature,['blf','blfpark'],true) && ($this->blfLabelSource !== 'custom' || trim((string)($b['label'] ?? '')) === '')) {
            $line['_identity_target'] = ['site'=>$b['target_site'] ?? $this->currentSiteId,'extension'=>(string)($b['target'] ?? ''),'derived'=>true];
        }
        foreach (['account', 'icon'] as $optional) {
            if (isset($b[$optional]) && $b[$optional] !== '') {
                $line[$optional] = $b[$optional];
            }
        }
        // For standard generic string features OR manufacturer-specific numeric features
        if (in_array($feature, ['blf', 'blfpark', 'speeddial', 'serviceurl'], true) || is_numeric($feature)) {
            $line['speedDialNumber'] = $target;
            $line['contact'] = $target;
            // Mask is only relevant for Cisco XML BLFs, but we preserve it if present
            if ($feature === 'blf') {
                $line['featureOptionMask'] = isset($b['mask']) ? (int) $b['mask'] : 1;
            }
            // Cross-site BLF marker (drives PublishMesh + hint/subscribe targeting).
            if (!empty($b['target_site']) && (int)$b['target_site'] !== $this->currentSiteId) {
                $line['target_site'] = $b['target_site'];
            }
        }
        return $line;
    }

    private function phoneServicesUrl(string $family, array $phone, array $settings): string
    {
        $mac = strtolower(preg_replace('/[^0-9a-f]/i','',(string)($phone['mac'] ?? '')));
        $formats = ['cisco_xml'=>'cisco','cisco_cnf'=>'cisco_legacy','yealink'=>'yealink','grandstream'=>'grandstream'];
        if (strlen($mac) !== 12 || !isset($formats[$family])) { return ''; }
        $base = trim((string)($settings['phone_services_base_url'] ?? ''));
        if ($base === '') {
            $host = trim((string)($settings['pbx_addr'] ?? ''));
            if ($host === '') { return ''; }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { $host='['.$host.']'; }
            $base = 'http://'.$host;
        }
        $base = MediaUrl::normalizeBase($base);
        return $base.'/ucmp-s.php/'.rawurlencode($this->ucm->phoneServicesToken()).'/'.$formats[$family].'/'.$mac;
    }

    /**
     * Resolve one leaf group's parent path and ordered template layers.
     *
     * Existing template_map JSON remains the persistence format for this
     * revision; its array order is authoritative and legacy assignment-level
     * family/model constraints are still honored.
     *
     * @return array{template:?array,trace:array,group_path:array}
     */
    private function templateStackForGroup(
        ?array $group,
        array $model,
        string $family,
        bool $fullDeviceResolution = true,
        string $extension = ''
    ): array
    {
        $siteId = (int)($group['site_id'] ?? 0) ?: $this->currentSiteId;
        $groups = $group && method_exists($this->ucm, 'getGroups')
            ? $this->templateGroupsForSite($siteId)
            : ($group ? [$group] : []);
        $byGroup = [];
        foreach ($groups as $candidate) {
            if (is_array($candidate) && (int)($candidate['id'] ?? 0) > 0) {
                $byGroup[(int)$candidate['id']] = $candidate;
            }
        }
        if ($group) {
            $byGroup[(int)$group['id']] = array_replace(
                $byGroup[(int)$group['id']] ?? [],
                $group
            );
        }
        $groups = array_values($byGroup);

        $assignments = [];
        $templateIds = [];
        $assignmentId = 0;
        foreach ($groups as $groupRow) {
            $map = $this->decode($groupRow['template_map'] ?? null);
            if (!$map && !empty($groupRow['template_id'])) {
                $map = [['template_id'=>(int)$groupRow['template_id'], 'position'=>10]];
            }
            foreach (array_values(is_array($map) ? $map : []) as $position => $entry) {
                if (!is_array($entry) || (int)($entry['template_id'] ?? 0) < 1) {
                    continue;
                }
                $assignment = [
                    'id'=>++$assignmentId,
                    'group_id'=>(int)$groupRow['id'],
                    'template_id'=>(int)$entry['template_id'],
                    'position'=>(int)($entry['position'] ?? (($position + 1) * 10)),
                    'enabled'=>array_key_exists('enabled', $entry) ? (int)!empty($entry['enabled']) : 1,
                ];
                foreach (['vendor','family','series','model','model_id','role'] as $scope) {
                    if (array_key_exists($scope, $entry)) {
                        $assignment[$scope] = $entry[$scope];
                    }
                }
                $assignments[] = $assignment;
                $templateIds[(int)$entry['template_id']] = true;
            }
        }

        $templates = method_exists($this->ucm, 'getTemplates')
            ? $this->templatesForSite($siteId)
            : [];
        $templatesById = [];
        foreach ($templates as $template) {
            if (is_array($template) && (int)($template['id'] ?? 0) > 0) {
                $templatesById[(int)$template['id']] = $template;
            }
        }
        foreach (array_keys($templateIds) as $templateId) {
            if (!isset($templatesById[$templateId])) {
                $template = $this->ucm->getTemplate((int)$templateId, $siteId);
                if (is_array($template)) {
                    $templatesById[$templateId] = $template;
                }
            }
        }
        $templates = array_values($templatesById);
        $device = [
            'vendor'=>(string)($model['vendor'] ?? ''),
            'family'=>$family,
            'series_key'=>(string)($model['series_key'] ?? '') ?: ModelCatalog::seriesKey(
                (string)($model['vendor'] ?? ''),
                (string)($model['model'] ?? ''),
                $family
            ),
            'model'=>(string)($model['model'] ?? ''),
            'model_id'=>(int)($model['id'] ?? 0),
            'role'=>(string)($model['role'] ?? 'desk'),
        ];
        $layers = [];
        $trace = [];
        $groupPath = [];

        $resolvedGroup = null;
        if ($group) {
            $resolvedGroup = (new TemplateStackResolver())->resolve(
                (int)$group['id'],
                $groups,
                $assignments,
                $templates,
                $device
            );
            foreach ((array)$resolvedGroup['applied_template_ids'] as $templateId) {
                if (isset($templatesById[(int)$templateId])) {
                    $layers[] = $templatesById[(int)$templateId];
                }
            }
            $trace = array_merge($trace, array_map(
                static fn(array $entry): array => ['source'=>'group'] + $entry,
                (array)$resolvedGroup['trace']
            ));
            $groupPath = (array)$resolvedGroup['group_path'];
        }

        // A real matching group stack is authoritative. Empty groups and groups
        // whose layers do not match this phone fall back to the site's most
        // specific default instead of producing an unusable empty build.
        if (!$layers && method_exists($this->ucm, 'getDefaultTemplateRules')) {
            $default = (new DefaultTemplateResolver())->resolve(
                $this->defaultTemplateRulesForSite($siteId),
                $templates,
                $device
            );
            $trace = array_merge($trace, (array)$default['trace']);
            $defaultTemplateId = (int)($default['template_id'] ?? 0);
            if ($defaultTemplateId > 0 && isset($templatesById[$defaultTemplateId])) {
                $layers[] = $templatesById[$defaultTemplateId];
            }
        }

        // A member exception is one final, applicability-checked layer. Explicit
        // map/scalar values win normally; a non-empty key list replaces the
        // inherited template key list rather than appending to it.
        $extension = trim($extension);
        if ($group && $extension !== '') {
            $exceptions = $this->decode($group['member_exceptions'] ?? null);
            foreach (is_array($exceptions) ? $exceptions : [] as $exception) {
                if (!is_array($exception)) { continue; }
                $members = array_map('strval', is_array($exception['extensions'] ?? null) ? $exception['extensions'] : []);
                if (!in_array($extension, $members, true)) { continue; }
                $exceptionTemplateId = (int)($exception['template_id'] ?? 0);
                $exceptionResolved = (new TemplateStackResolver())->resolve(
                    (int)$group['id'],
                    [[
                        'id'=>(int)$group['id'],
                        'site_id'=>$siteId,
                        'name'=>(string)($group['name'] ?? ('Group ' . (int)$group['id'])),
                        'parent_group_id'=>null,
                    ]],
                    [[
                        'group_id'=>(int)$group['id'],
                        'template_id'=>$exceptionTemplateId,
                        'position'=>10,
                        'enabled'=>1,
                    ]],
                    $templates,
                    $device
                );
                $trace = array_merge($trace, array_map(
                    static fn(array $entry): array => ['source'=>'exception'] + $entry,
                    (array)$exceptionResolved['trace']
                ));
                if (!empty($exceptionResolved['applied_template_ids'][0])
                    && isset($templatesById[$exceptionTemplateId])) {
                    $exceptionTemplate = $templatesById[$exceptionTemplateId];
                    $exceptionTemplate['_replace_buttons'] = true;
                    $layers[] = $exceptionTemplate;
                }
                break;
            }
        }
        return [
            'template'=>$layers ? $this->mergeTemplateLayers($layers, $fullDeviceResolution) : null,
            'trace'=>$trace,
            'group_path'=>$groupPath,
        ];
    }

    private function templateGroupsForSite(int $siteId): array
    {
        if (!array_key_exists($siteId, $this->templateGroupsBySite)) {
            $groups = (array)$this->ucm->getGroups($siteId);
            $groups = $this->draftRows('group',$siteId,$groups);
            foreach ($groups as &$group) {
                if (is_array($group)) { $group['site_id'] = $siteId; }
            }
            unset($group);
            $this->templateGroupsBySite[$siteId] = $groups;
        }
        return $this->templateGroupsBySite[$siteId];
    }

    private function templatesForSite(int $siteId): array
    {
        if (!array_key_exists($siteId, $this->templatesBySite)) {
            $this->templatesBySite[$siteId] = $this->draftRows('template',$siteId,(array)$this->ucm->getTemplates($siteId, false));
        }
        return $this->templatesBySite[$siteId];
    }

    private function defaultTemplateRulesForSite(int $siteId): array
    {
        if (!array_key_exists($siteId, $this->defaultTemplateRulesBySite)) {
            $this->defaultTemplateRulesBySite[$siteId] = (array)$this->ucm->getDefaultTemplateRules($siteId);
            if (($this->draft['kind'] ?? '')==='settings' && (int)($this->draft['site_id'] ?? 0)===$siteId && array_key_exists('default_template_rules',(array)($this->draft['values'] ?? []))) {
                $this->defaultTemplateRulesBySite[$siteId]=$this->decode($this->draft['values']['default_template_rules']);
            }
        }
        return $this->defaultTemplateRulesBySite[$siteId];
    }

    /** Deliberately allowlisted: unknown settings may contain credentials. */
    public static function explainValue(string $key,$value)
    {
        if (!preg_match('/\A(?:driver|transport|sip_port|cisco_transport|cisco_sip_port|cisco_cnf_transport|cisco_cnf_sip_port|non_cisco_driver|non_cisco_transport|non_cisco_sip_port|date_template|cisco_xml_timezone|cisco_xml_olson_timezone|cisco_legacy_timezone|cisco_legacy_dst_auto_adjust|yealink_timezone|device_security|network_locale|firmware|firmware_profile|inactive_firmware|vlan_mode|vlan_voice_vid|vlan_voice_pcp|vlan_pc_port|vlan_pc_vid|vlan_pc_pcp|vlan_discovery|cisco_kem_type|cisco_kem_count|yealink_expansion_count|grandstream_expansion_count|background_access_disabled|phone_personalization|blf_label_source|phone_label_pattern|line_label_pattern|blf_label_pattern|directory_label_pattern)\z/D',$key)
            || !is_scalar($value) || strlen((string)$value) > 512 || preg_match('/[\x00-\x1f]|enc:|encg:|https?:\/\/|PRIVATE KEY/i',(string)$value)) { return '[not shown]'; }
        return $value;
    }

    private function draftRows(string $kind,int $siteId,array $rows): array
    {
        if (($this->draft['kind'] ?? '') !== $kind || (int)($this->draft['site_id'] ?? 0) !== $siteId) { return $rows; }
        $id = (int)($this->draft['id'] ?? 0);
        foreach ($rows as &$row) { if ((int)$row['id'] === $id) { $row = array_replace($row,(array)($this->draft['values'] ?? [])); } }
        unset($row);
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $layers */
    private function mergeTemplateLayers(array $layers, bool $includeTemplateCompanions = true): array
    {
        $last = end($layers);
        $merged = [
            'id'=>(int)$last['id'],
            'name'=>implode(' + ', array_map(static fn(array $layer): string => (string)$layer['name'], $layers)),
            'setting_overrides'=>[],
            'capacity_overrides'=>[],
            'buttons'=>[],
            'softkeys'=>[],
            'feature_policy'=>[],
            'member_blf'=>0,
        ];
        $softkeyLayers = [];
        foreach ($layers as $layer) {
            $safeValues = [];
            foreach ((array)$this->decode($layer['setting_overrides'] ?? null) as $key=>$value) { $safeValues[$key] = self::explainValue($key,$value); }
            $merged['_setting_layers'][] = ['id'=>(int)$layer['id'],'name'=>(string)$layer['name'],'values'=>$safeValues];
            foreach (['setting_overrides','capacity_overrides'] as $field) {
                $patch = $this->decode($layer[$field] ?? null);
                if (is_array($patch)) {
                    $merged[$field] = $this->mergeMapPatch((array)$merged[$field], $patch, true);
                }
            }
            if ($includeTemplateCompanions) {
                $softkeyPatch = $this->decode($layer['softkeys'] ?? null);
                if (is_array($softkeyPatch) && $softkeyPatch) {
                    if (SoftkeyPolicy::isV2($softkeyPatch)) {
                        try {
                            SoftkeyPolicy::normalizeV2($softkeyPatch);
                        } catch (\InvalidArgumentException $error) {
                            throw new \InvalidArgumentException(sprintf(
                                'Template "%s" has an invalid softkey policy: %s',
                                (string)($layer['name'] ?? ('#' . (int)($layer['id'] ?? 0))),
                                $error->getMessage()
                            ), 0, $error);
                        }
                    }
                    $softkeyLayers[] = $softkeyPatch;
                }
                $featurePatch = $this->decode($layer['feature_policy'] ?? null);
                if (is_array($featurePatch)) {
                    $merged['feature_policy'] = $this->mergeMapPatch(
                        (array)$merged['feature_policy'],
                        $featurePatch,
                        false
                    );
                }
            }
            $buttons = $this->decode($layer['buttons'] ?? null);
            if (is_array($buttons)) {
                $merged['buttons'] = !empty($layer['_replace_buttons']) && $buttons
                    ? $this->mergeButtons([], $buttons)
                    : $this->mergeButtons((array)$merged['buttons'], $buttons);
            }
            foreach (['phonebook_key','phonebook_filename','phonebook_source_site','phonebook_source_url'] as $field) {
                if (trim((string)($layer[$field] ?? '')) !== '') {
                    $merged[$field] = $layer[$field];
                }
            }
            if (array_key_exists('member_blf', $layer)) {
                $merged['member_blf'] = !empty($layer['member_blf']) ? 1 : 0;
            }
        }
        if ($softkeyLayers) {
            $usesV2 = false;
            foreach ($softkeyLayers as $policy) {
                if (SoftkeyPolicy::isV2($policy)) {
                    $usesV2 = true;
                    break;
                }
            }
            if ($usesV2) {
                $effective = [];
                foreach ($softkeyLayers as $policy) {
                    // Each reusable tier validates independently; the merge
                    // then applies exact state replacement and definition
                    // overrides in root-to-leaf order.
                    $effective = SoftkeyPolicy::merge($effective, $policy);
                }
                $merged['softkeys'] = $effective;
            } else {
                foreach ($softkeyLayers as $policy) {
                    $merged['softkeys'] = $this->mergeMapPatch(
                        (array)$merged['softkeys'],
                        $policy,
                        false
                    );
                }
            }
        }
        foreach (['setting_overrides','capacity_overrides','buttons','softkeys','feature_policy'] as $field) {
            $merged[$field] = json_encode(
                $merged[$field],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $merged['_stack_ids'] = array_map(static fn(array $layer): int => (int)$layer['id'], $layers);
        $merged['_stack_key'] = substr(hash('sha256', json_encode([
            $merged['_stack_ids'],
            $merged['setting_overrides'],
            $merged['buttons'],
            $merged['softkeys'],
            $merged['feature_policy'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 16);
        return $merged;
    }

    private function mergeMapPatch(array $base, array $patch, bool $retainNull): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null && !$retainNull) {
                unset($base[$key]);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function mergeButtons(array $base, array $patch): array
    {
        $slots = [];
        foreach ($base as $position => $button) {
            if (!is_array($button)) { continue; }
            $slot = max(2, (int)($button['slot'] ?? ($position + 2)));
            $button['slot'] = $slot;
            $slots[$slot] = $button;
        }
        foreach (array_values($patch) as $position => $button) {
            if (!is_array($button)) { continue; }
            $slot = max(2, (int)($button['slot'] ?? ($position + 2)));
            if (!empty($button['_clear']) || (string)($button['feature'] ?? '') === 'clear') {
                unset($slots[$slot]);
                continue;
            }
            $button['slot'] = $slot;
            $slots[$slot] = $button;
        }
        ksort($slots, SORT_NUMERIC);
        return array_values($slots);
    }

    private function templateMatchesModel(array $template, array $model): bool
    {
        $target = trim((string)($template['target_model'] ?? ''));
        if ($target === '') { return true; }
        return strcasecmp($target, trim((string)($model['vendor'] ?? '')) . '|' . trim((string)($model['model'] ?? ''))) === 0;
    }

    /** Reject deleted, incomplete or cross-model firmware before a config reaches TFTP. */
    private function assertFirmwareSelection(int $siteId, array $model, array $settings): void
    {
        if (!$model) {
            throw new \RuntimeException('A firmware override requires a known exact phone model');
        }
        $required = ModelCatalog::firmwareProfile((string)($model['vendor'] ?? ''), (string)($model['model'] ?? ''));
        $stored = trim((string)($settings['firmware_profile'] ?? ''));
        if ($required === null || ($stored !== '' && !hash_equals($required, $stored))) {
            throw new \RuntimeException(sprintf(
                'Firmware selection does not match %s %s',
                (string)($model['vendor'] ?? ''),
                (string)($model['model'] ?? '')
            ));
        }
        $validation = (new FirmwareService($this->ucm))->validateLoadSelection(
            $siteId,
            $model,
            (string)$settings['firmware'],
            false
        );
        if (empty($validation['ok'])) {
            throw new \RuntimeException((string)($validation['message'] ?? 'Firmware selection is no longer valid'));
        }
    }

    /** Validate every load-bearing Cisco field, including post-template phone overrides. */
    private function assertAllFirmwareSelections(int $siteId, string $family, array $model, array $settings): void
    {
        if (trim((string)($settings['firmware'] ?? '')) !== '') {
            $this->assertFirmwareSelection($siteId, $model, $settings);
        }
        if (trim((string)($settings['inactive_firmware'] ?? '')) !== '') {
            $inactive = $settings;
            $inactive['firmware'] = (string)$settings['inactive_firmware'];
            $this->assertFirmwareSelection($siteId, $model, $inactive);
        }
        $kemLoad = trim((string)($settings['cisco_kem_firmware'] ?? ''));
        if ($kemLoad !== '') {
            if ($family !== 'cisco_xml' || (int)($settings['cisco_kem_count'] ?? 0) < 1) {
                throw new \RuntimeException('An expansion-module firmware load requires an enabled Cisco expansion module');
            }
            $type = (string)($settings['cisco_kem_type'] ?? '');
            $validation = (new FirmwareService($this->ucm))->validateAccessoryLoad($siteId, $type, $kemLoad, false);
            if (empty($validation['ok'])) {
                throw new \RuntimeException((string)($validation['message'] ?? 'Expansion-module firmware selection is no longer valid'));
            }
        }
    }

    /** Turn a validated site-relative package into the endpoint syntax each vendor expects. */
    private function resolveManagedFirmwareEndpoint(string $family, array &$settings): void
    {
        $load = trim(str_replace('\\', '/', (string)($settings['firmware'] ?? '')), '/');
        $host = trim((string)($settings['_tftp_host'] ?? ''));
        if ($load === '' || $host === '' || !in_array($family, ['yealink', 'grandstream'], true)) { return; }
        if (str_contains($host, ':') && $host[0] !== '[') { $host = '[' . $host . ']'; }
        if ($family === 'yealink') {
            $settings['_managed_firmware_url'] = 'tftp://' . $host . '/' . $load;
            return;
        }
        $directory = dirname($load);
        $settings['_managed_firmware_url'] = 'tftp://' . $host . ($directory === '.' || $directory === '' ? '' : '/' . trim($directory, '/'));
    }

    private function phonebookUrl(string $url, string $bookKey, string $filename, ?string $labelPattern = null): string
    {
        if ($labelPattern !== null) { IdentityLabelFormatter::validatePattern($labelPattern); }
        if (preg_match('~^(https?://[^/]+/ucmp-d\.php/[A-Fa-f0-9]{8,128})(?:/[^/?#]+)?(?:\?[^#]*)?$~D', $url, $match)) {
            // Legacy 7940/7960 firmware needs the deliberately short,
            // query-free PATH_INFO URL; keep its shared published label.
            return $match[1] . ($bookKey !== '' ? '/' . rawurlencode($bookKey) : '');
        }
        $url = preg_replace('/([?&])(book|file|label_pattern)=[^&#]*/i', '$1', $url) ?? $url;
        $url = rtrim(str_replace(['?&','&&'], ['?','&'], $url), '?&');
        $params = [];
        if ($bookKey !== '') { $params['book'] = $bookKey; }
        if ($filename !== '') { $params['file'] = basename($filename); }
        if ($labelPattern !== null) { $params['label_pattern'] = $labelPattern; }
        return $params ? $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : $url;
    }

    private function phonebookSourceUrl(string $current, string $source): string
    {
        $currentParts = parse_url($current); $sourceParts = parse_url($source);
        if (!is_array($currentParts) || !is_array($sourceParts) || empty($sourceParts['scheme']) || empty($sourceParts['host'])) { return $current; }
        $host = (string)$sourceParts['host'];
        if (str_contains($host, ':') && $host[0] !== '[') { $host = '[' . $host . ']'; }
        $authority = $sourceParts['scheme'] . '://' . $host . (!empty($sourceParts['port']) ? ':' . (int)$sourceParts['port'] : '');
        if (preg_match('#/ucmp-d\.php/([A-Fa-f0-9]{8,128})#', (string)($currentParts['path'] ?? ''))) {
            $sourceQuery = [];
            parse_str((string)($sourceParts['query'] ?? ''), $sourceQuery);
            $sourceToken = (string)($sourceQuery['token'] ?? '');
            return preg_match('/^[A-Fa-f0-9]{8,128}$/D', $sourceToken)
                ? $authority . '/ucmp-d.php/' . rawurlencode($sourceToken)
                : $current;
        }
        $path = (string)($sourceParts['path'] ?? '/ucmprovisioner-directory.php');
        $currentQuery = []; $sourceQuery = [];
        parse_str((string)($currentParts['query'] ?? ''), $currentQuery);
        parse_str((string)($sourceParts['query'] ?? ''), $sourceQuery);
        // The selected source owns its bearer token; the current local URL owns
        // vendor format and any other renderer parameters.
        $query = array_replace($currentQuery, $sourceQuery);
        return $authority . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /** Resolve an omitted BLF label using the template policy and target site's own data. */
    private function labelForTarget(string $extension, $targetSite): ?string
    {
        $siteId = is_numeric($targetSite) ? (int)$targetSite : 0;
        if ($siteId <= 0 && is_string($targetSite) && $targetSite !== '') {
            foreach ($this->ucm->getSites() as $site) {
                if ((string)($site['site_key'] ?? '') === $targetSite) {
                    $siteId = (int)$site['id'];
                    break;
                }
            }
        }
        $siteId = $siteId ?: $this->currentSiteId ?: $this->ucm->localSiteId();
        if ($this->blfLabelSource === 'extension') {
            return $extension;
        }
        $resolved = null;
        $identityAvailable = true;
        $formatter = fn(array $canonical): array=>IdentityLabelFormatter::outputs($canonical,$this->labelPatterns,['site'=>(string)(($this->ucm->getSite($siteId) ?: [])['name'] ?? '')]);
        try {
            $resolved = $this->identityPreview ? IdentityDeliveryService::resolveCached($this->ucm,$siteId,$extension,$formatter,$this->identityPreview) : null;
            $resolved ??= method_exists($this->ucm,'getDb') ? (new DirectoryService($this->ucm))->resolveExtensionIdentity($siteId,$extension,$formatter) : null;
            if ($resolved && !empty($resolved['resolved']) && ($resolved['binding']['adoption'] ?? '') === 'managed'
                && ($resolved['delivery_policy'][$this->blfLabelSource === 'extension_name' ? 'sync_name' : 'sync_label'] ?? true)) {
                // The watching phone's top-label pattern must not format its BLF targets.
                return $this->blfLabelSource === 'extension_name' ? (string)$resolved['canonical']['display_name'] : (string)$resolved['outputs']['blf_label'];
            }
        } catch (\Throwable) {
            $identityAvailable = false;
        }
        // Only unbound targets may synthesize a FreePBX identity. An unavailable,
        // unadopted or unresolved owned identity must retain the existing fallback.
        $usePattern = $identityAvailable && $this->blfLabelSource === 'identity_pattern'
            && ($resolved === null || (($resolved['binding']['mode'] ?? '') === 'ad_primary'
                && (int)($resolved['binding']['source_id'] ?? 0) === 0));
        $fallback = static function (string $label) use ($usePattern, $extension, $formatter): ?string {
            if ($label === '') { return null; }
            return $usePattern
                ? (string)LogicalExtensionService::resolveProvisioningIdentity(
                    ['display_name'=>$label,'label'=>$label,'extension'=>$extension], 'freepbx', [], $formatter
                )['outputs']['blf_label']
                : $label;
        };
        if ($this->blfLabelSource === 'phone_label') {
            if (!array_key_exists($siteId, $this->phoneCatalog)) {
                try {
                    $this->phoneCatalog[$siteId] = $this->ucm->getPhones(['site_id' => $siteId]);
                } catch (\Throwable $e) {
                    $this->phoneCatalog[$siteId] = [];
                }
            }
            foreach ($this->phoneCatalog[$siteId] as $phone) {
                if ((string)($phone['extension'] ?? '') === $extension && trim((string)($phone['label'] ?? '')) !== '') {
                    return trim((string)$phone['label']);
                }
            }
        }
        foreach ($this->endpointCatalog($siteId) as $row) {
            $number = (string)($row['extension'] ?? $row['ext'] ?? $row['id'] ?? '');
            if ($number !== $extension) {
                continue;
            }
            foreach (['description', 'name', 'display_name', 'label'] as $key) {
                if (trim((string)($row[$key] ?? '')) !== '') {
                    return $fallback(trim((string)$row[$key]));
                }
            }
        }
        if ($siteId === $this->currentSiteId) {
            $device = $this->getDevice($extension);
            return $fallback(trim((string)($device['description'] ?? '')));
        }
        return null;
    }

    /** Load the FreePBX endpoint catalog once per site instead of once per line key. */
    private function endpointCatalog(int $siteId): array
    {
        if (!array_key_exists($siteId, $this->extensionCatalog)) {
            try {
                $this->extensionCatalog[$siteId] = $this->ucm->getAllSipExtensions($siteId);
            } catch (\Throwable $e) {
                $this->extensionCatalog[$siteId] = [];
            }
        }
        return $this->extensionCatalog[$siteId];
    }

    private function driverForExtension(string $extension, int $siteId): string
    {
        foreach ($this->endpointCatalog($siteId) as $row) {
            $number = (string)($row['extension'] ?? $row['ext'] ?? $row['id'] ?? '');
            if ($number === $extension) {
                return strtolower((string)($row['driver'] ?? $row['tech'] ?? ''));
            }
        }
        return '';
    }

    /** Read a chan_sip device via the official Core API (secret, description, etc.). */
    private function getDevice(string $ext): array
    {
        try {
            $core = \FreePBX::Core();
            if (is_object($core) && method_exists($core, 'getDevice')) {
                $d = $core->getDevice($ext);
                if (is_array($d)) {
                    return $d;
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return [];
    }

    private function decode($json)
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $v = json_decode($json, true);
        return is_array($v) ? $v : [];
    }
}
