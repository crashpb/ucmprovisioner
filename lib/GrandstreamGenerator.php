<?php
/**
 * GrandstreamGenerator — builds cfg<MAC>.xml provisioning files.
 *
 * Supports GXP/GRP/GHP desk phones and DP75x DECT base stations. DECT handsets
 * provision through their base; this generator never writes a handset MAC cfg.
 *
 * Grandstream phones SUBSCRIBE for BLF natively; no UCM glue required.
 * Remote reboot is not via SIP NOTIFY — use HTTP/admin or config-triggered reboot.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class GrandstreamGenerator
{
    /** GHP63x firmware 1.0.1.50 built-in VMPK icons; arbitrary uploads are unsupported. */
    private const GHP63X_ICON_CODES = [
        'WU-1', 'VA-2', 'VA-1', 'TT-3', 'TT-2', 'TT-1', 'TR-3', 'TR-2', 'TR-1',
        'RS-5', 'RS-2', 'RS-1', 'RS-4', 'RS-3', 'OP-3', 'OP-2', 'OP-1', 'OD-1',
        'MT-1', 'MS-1', 'IN-3', 'IN-2', 'IN-1', 'HK-1', 'HK-2', 'HB-3', 'HB-2',
        'HB-1', 'GP-7', 'GP-6', 'GP-5', 'GP-4', 'GP-3', 'GP-2', 'GP-1', 'FD-3',
        'FD-2', 'FD-1', 'EM-1', 'EM-2', 'BI-1', 'AC-7', 'AC-6', 'AC-5', 'AC-4',
        'AC-3', 'AC-2', 'AC-1', 'C-1', 'C-2', 'C-3', 'C-4',
    ];

    /**
     * @param array $ctx resolvePhone() output
     * @return array{filename:?string,content:string,warnings:array}
     */
    public function generate(array $ctx): array
    {
        $phone    = $ctx['phone'] ?? [];
        $settings = $ctx['settings'] ?? [];
        $lines    = $ctx['lines'] ?? [];
        $model    = $ctx['model'] ?? [];
        $warnings = [];

        $modelName = strtoupper((string)($model['model'] ?? ''));
        $role = strtolower((string)($model['role'] ?? ''));
        if ($this->unsupportedRole($role, $modelName)) {
            $message = sprintf(
                'Grandstream %s is a %s and is provisioned through its base/system, not with a desk-phone MAC cfg.',
                $modelName !== '' ? $modelName : 'endpoint',
                $role !== '' ? $role : 'handset/system component'
            );
            return ['filename'=>null, 'content'=>'', 'warnings'=>[$message], 'error'=>$message];
        }

        $mac = strtolower(preg_replace('/[^0-9a-f]/i', '', (string) ($phone['mac'] ?? '')));
        $filename = ($mac !== '' && strlen($mac) === 12) ? "cfg{$mac}.xml" : null;
        if ($filename === null) {
            $warnings[] = 'No valid MAC; cannot determine Grandstream cfg filename.';
        }

        $pbx    = (string) ($settings['pbx_addr'] ?? '');
        $port   = (int) ($settings['sip_port'] ?? 5060);
        $configProfile = strtolower((string)($model['config_profile'] ?? ''));
        $isAliasV2 = $modelName === 'DP755' || $configProfile === 'grandstream_dp755_alias_v2';

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('gs_provision');
        $root->setAttribute('version', '1');
        $doc->appendChild($root);

        $macEl = $doc->createElement('mac', $mac);
        $root->appendChild($macEl);

        $config = $doc->createElement('config');
        $config->setAttribute('version', $isAliasV2 ? '2' : '1');
        $root->appendChild($config);

        $isDectBase = str_contains($role, 'base') || (bool)preg_match('/^DP(?:750|752|755)$/', $modelName);
        $isGhp63x = (bool)preg_match('/^GHP63[01]W?$/', $modelName);
        $defaultAccounts = $modelName === 'DP755' ? 20 : ($isDectBase ? 10 : 16);
        $accountLimit = (int)($model['sip_accounts'] ?? 0);
        $accountLimit = $accountLimit > 0 ? $accountLimit : $defaultAccounts;
        $accounts = $this->accountLines($lines, $accountLimit, $warnings);
        if ($isAliasV2) {
            $handsetsMax = (int)($model['handsets_max'] ?? 0);
            $this->renderDp755Alias($doc, $config, $accounts, $settings, $pbx, $port, $handsetsMax > 0 ? $handsetsMax : 10, $warnings);
        } elseif ($isDectBase) {
            $handsetsMax = (int)($model['handsets_max'] ?? 0);
            $this->renderDp75x($doc, $config, $accounts, $settings, $pbx, $port, $handsetsMax > 0 ? $handsetsMax : 5, $warnings);
        } else {
            $this->renderDeskAccounts($doc, $config, $accounts, $settings, $pbx, $port);
        }

        // Capture the actual model's managed account/profile fields before
        // globals and native settings are added. They must agree with preflight.
        $managedAccountFields = [];
        foreach ($config->childNodes as $node) {
            if ($node instanceof \DOMElement) { $managedAccountFields[$node->tagName] = true; }
        }
        $phonebookUrl = (string)($settings['grandstream_phonebook_url'] ?? ($settings['phonebook_url'] ?? ''));
        if ($isAliasV2) {
            $this->renderDp755Globals($doc, $config, $settings, $phonebookUrl, $warnings);
        } else {
            if (!empty($settings['ntp_server'])) {
                $this->pval($doc, $config, 'P30', (string)$settings['ntp_server']);
            }
            if (isset($settings['digit_timeout'])) {
                $this->pval($doc, $config, 'P282', (string)(int)$settings['digit_timeout']);
            }
            if (!empty($settings['admin_password'])) {
                $this->pval($doc, $config, 'P2', (string)$settings['admin_password']);
            }
            if (!empty($settings['user_password'])) {
                $this->pval($doc, $config, 'P196', (string)$settings['user_password']);
            }
            if ($phonebookUrl !== '') {
                // P330 is the download mode. P331 is the host/path without the scheme.
                $mode = isset($settings['gs_phonebook_mode']) ? (int)$settings['gs_phonebook_mode'] : (stripos($phonebookUrl, 'https://') === 0 ? 3 : 1);
                $this->pval($doc, $config, 'P330', (string)$mode);
                $this->pval($doc, $config, 'P331', preg_replace('#^https?://#i', '', $phonebookUrl));
                $this->pval($doc, $config, 'P332', (string)max(5, (int)($settings['gs_phonebook_interval'] ?? 60)));
            }
            // GXP/GRP XML Application service. DECT bases and GHP hotel profiles do not
            // share this application surface and are deliberately left untouched.
            if (!$isDectBase && !$isGhp63x && preg_match('/^(?:GXP|GRP)/', $modelName) && !empty($settings['_phone_services_url'])) {
                $this->pval($doc, $config, 'P337', (string)$settings['_phone_services_url']);
                $this->pval($doc, $config, 'P352', 'Services');
            }
            if (isset($settings['auto_answer']) && $settings['auto_answer'] !== '') {
                $this->pval($doc, $config, 'P90', (string)$this->boolValue($settings['auto_answer']));
            }

            // P1362 is Downloaded Language. P2916/P2917 are the documented wallpaper pair.
            if (!$isDectBase && !empty($settings['hosted_image_url'])) {
                $logoUrl = str_replace('{{pbx_ip}}', $pbx, (string)$settings['hosted_image_url']);
                $this->pval($doc, $config, 'P2916', (string)($settings['ghp_wallpaper_source'] ?? '1'));
                $this->pval($doc, $config, 'P2917', $logoUrl);
            }
            if ($isGhp63x && isset($settings['ghp_wallpaper_color']) && $settings['ghp_wallpaper_color'] !== '') {
                $this->pval($doc, $config, 'P2921', (string)$settings['ghp_wallpaper_color']);
            }

            $firmware = $this->firmwareEndpoint($settings, $warnings);
            $hasDirectFirmware = (array_key_exists('gs_P6767', $settings) && $settings['gs_P6767'] !== '')
                || (array_key_exists('gs_P192', $settings) && $settings['gs_P192'] !== '');
            if ($firmware !== null && !$hasDirectFirmware) {
                $this->pval($doc, $config, 'P6767', (string)$firmware['mode']);
                $this->pval($doc, $config, 'P192', $firmware['server']);
            }
            $ringtone = $settings['grandstream_ringtone'] ?? ($settings['ringtone'] ?? null);
            if (!$isDectBase && !$isGhp63x && is_numeric($ringtone) && (int)$ringtone >= 0 && (int)$ringtone <= 12) {
                $this->pval($doc, $config, 'P8398', (string)(int)$ringtone);
            }

            // Direct P-values remain the backwards-compatible escape hatch for version-1 files.
            foreach ($settings as $k => $v) {
                if (strpos((string)$k, 'gs_P') !== 0 || $v === '') {
                    continue;
                }
                $pVal = substr((string)$k, 3);
                if (preg_match('/^P\d+$/', $pVal) !== 1) {
                    continue;
                }
                if ($this->isManagedIdentityPValue($pVal, $modelName)) {
                    $warnings[] = "Advanced Grandstream setting '$k' was ignored because AD/template identity owns that field.";
                    continue;
                }
                if (isset($managedAccountFields[$pVal])) {
                    $warnings[] = "Advanced Grandstream setting '$k' was ignored because the managed account owns that field.";
                    continue;
                }
                if ($v === 'custom' && !empty($settings['hosted_ringtone_url'])) {
                    $v = str_replace('{{pbx_ip}}', $pbx, (string)$settings['hosted_ringtone_url']);
                }
                $this->pval($doc, $config, $pVal, (string)$v);
            }
        }

        $writtenKeys = 0;
        if ($isGhp63x && !$isAliasV2) {
            $writtenKeys = $this->renderGhp63xKeys($doc, $config, $lines, $warnings);
            $writtenKeys += $this->renderGhp63xSpeedDials($doc, $config, $settings, $warnings);
        } elseif (!$isDectBase && !$isAliasV2) {
            $writtenKeys = $this->renderDeskKeys($doc, $config, $lines, $settings, $model, $modelName, $configProfile, $warnings);
        }

        if ($writtenKeys === 0 && !$isDectBase) {
            $warnings[] = 'No programmable keys assigned; only account registration generated.';
        }

        return [
            'filename' => $filename,
            'content'  => $doc->saveXML(),
            'warnings' => $warnings,
        ];
    }

    private function pval(\DOMDocument $doc, \DOMElement $parent, string $name, string $value): void
    {
        $el = $doc->createElement($name);
        $el->appendChild($doc->createTextNode($value));
        $existing = $parent->getElementsByTagName($name)->item(0);
        if ($existing !== null) { $parent->replaceChild($el, $existing); }
        else { $parent->appendChild($el); }
    }

    private function item(\DOMDocument $doc, \DOMElement $parent, string $name, string $value): void
    {
        $el = $doc->createElement('item');
        $el->setAttribute('name', $name);
        $el->appendChild($doc->createTextNode($value));
        $parent->appendChild($el);
    }

    /** Keep the account namespace separate from the requested physical-key position. */
    private function accountLines(array $lines, int $limit, array &$warnings): array
    {
        $accounts = [];
        $used = [];
        $offset = 0;
        foreach ($lines as $line) {
            if ((int)($line['featureId'] ?? 0) !== 9) {
                continue;
            }
            $offset++;
            $index = (int)($line['accountIndex'] ?? ($line['lineIndex'] ?? $offset));
            if ($index < 1 || $index > $limit || isset($used[$index])) {
                $warnings[] = sprintf('Grandstream account index %d is outside this model\'s 1-%d capacity or is duplicated; skipped.', $index, $limit);
                continue;
            }
            $used[$index] = true;
            $line['_account_index'] = $index;
            $accounts[] = $line;
        }
        return $accounts;
    }

    private function boolValue($value): int
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'true', 'on'], true) ? 1 : 0;
    }

    private function transportCode(string $transport): int
    {
        return match (strtolower(trim($transport))) {
            'tcp' => 1,
            'tls', 'tls/tcp', 'tcp/tls' => 2,
            default => 0,
        };
    }

    private function transportAlias(string $transport): string
    {
        return match ($this->transportCode($transport)) {
            1 => 'TCP',
            2 => 'Tls Or Tcp',
            default => 'UDP',
        };
    }

    /** @return array{mode:int,protocol:string,server:string}|null */
    private function firmwareEndpoint(array $settings, array &$warnings): ?array
    {
        $endpoint = '';
        foreach (['firmware_bundle_url', 'grandstream_firmware_url', 'firmware_server_url', '_managed_firmware_url'] as $key) {
            if (trim((string)($settings[$key] ?? '')) !== '') {
                $endpoint = trim((string)$settings[$key]);
                break;
            }
        }
        if ($endpoint === '') {
            return null;
        }
        $scheme = strtolower((string)(parse_url($endpoint, PHP_URL_SCHEME) ?: ''));
        if ($scheme === '') {
            $scheme = strtolower((string)($settings['grandstream_firmware_protocol'] ?? 'tftp'));
        }
        $modes = ['tftp'=>0, 'http'=>1, 'https'=>2, 'ftp'=>3, 'ftps'=>4];
        if (!isset($modes[$scheme])) {
            $warnings[] = "Unsupported Grandstream firmware URL scheme '$scheme'; firmware server settings were not emitted.";
            return null;
        }
        return [
            'mode' => $modes[$scheme],
            'protocol' => strtoupper($scheme),
            'server' => preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $endpoint),
        ];
    }

    private function handsetSelection($raw, int $maximum, array &$warnings, int $account): array
    {
        $values = array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)$raw))));
        $valid = [];
        foreach ($values as $handset) {
            if ($handset >= 1 && $handset <= $maximum) {
                $valid[] = $handset;
            } else {
                $warnings[] = sprintf('Grandstream account %d references handset %d outside this base\'s 1-%d capacity; skipped.', $account, $handset, $maximum);
            }
        }
        return $valid;
    }

    private function limited(string $value, int $maximum, string $field, array &$warnings): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false || count($characters) <= $maximum) {
            return $value;
        }
        $warnings[] = sprintf('%s exceeded the documented %d-character limit and was truncated.', $field, $maximum);
        return rtrim(implode('', array_slice($characters, 0, $maximum)));
    }

    /** @return array<int,string> */
    private function dectHandsetNames(
        array $settings,
        array $lineLabels,
        array $handsetLines,
        int $maximum,
        int $labelLimit,
        array &$warnings,
        string $profile
    ): array {
        $inventory = $settings['dect_handsets'] ?? [];
        if (is_string($inventory)) {
            $inventory = json_decode($inventory, true) ?: [];
        }
        if (!is_array($inventory)) {
            return [];
        }
        $overrides = [];
        foreach ($inventory as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slot = (int)($row['slot'] ?? 0);
            if ($slot < 1 || $slot > $maximum) {
                $warnings[] = sprintf('%s handset slot %d is outside this base\'s 1-%d capacity; its name was skipped.', $profile, $slot, $maximum);
                continue;
            }
            $name = trim((string)($row['label'] ?? ''));
            if ($name !== '') {
                $overrides[$slot] = $name;
            }
        }
        $names = [];
        for ($slot = 1; $slot <= $maximum; $slot++) {
            if (isset($overrides[$slot])) {
                $names[$slot] = $this->limited($overrides[$slot], $labelLimit, "{$profile} handset {$slot} name", $warnings);
                continue;
            }
            $accounts = array_values(array_unique(array_map('intval', $handsetLines[$slot] ?? [])));
            sort($accounts, SORT_NUMERIC);
            if (!$accounts || !isset($lineLabels[$accounts[0]])) {
                continue;
            }
            $names[$slot] = $this->limited((string)$lineLabels[$accounts[0]], $labelLimit, "{$profile} handset {$slot} name", $warnings);
            if (count($accounts) > 1) {
                $warnings[] = sprintf(
                    '%s handset %d has multiple assigned accounts; account %d supplied its derived name.',
                    $profile,
                    $slot,
                    $accounts[0]
                );
            }
        }
        return $names;
    }

    private function unsupportedRole(string $role, string $model): bool
    {
        return (bool)preg_match('/handset|radio|manager/', $role)
            || (bool)preg_match('/^DP7(?:20|22|25|30|35)$/', $model);
    }

    /** DP755 firmware 1.0.3.x uses the documented version-2 alias namespace. */
    private function renderDp755Alias(
        \DOMDocument $doc,
        \DOMElement $config,
        array $accounts,
        array $settings,
        string $pbx,
        int $port,
        int $maxHandsets,
        array &$warnings
    ): void {
        $server = $port === 5060 ? $pbx : "$pbx:$port";
        $transport = $this->transportAlias((string)($settings['transport'] ?? 'udp'));
        $handsetLines = [];
        $lineLabels = [];
        foreach ($accounts as $line) {
            $index = (int)$line['_account_index'];
            $ext = (string)($line['name'] ?? '');
            $label = $this->limited((string)($line['label'] ?? $ext), 512, "Grandstream DP755 account {$index} name", $warnings);
            $displayName = $this->limited((string)($line['displayName'] ?? $ext), 512, "Grandstream DP755 account {$index} SIP name", $warnings);
            $lineLabels[$index] = $label;
            foreach ([
                "account.$index.enable" => 'Yes',
                "account.$index.name" => $label,
                "account.$index.sip.server.1.address" => $server,
                "account.$index.sip.userid" => $ext,
                "account.$index.sip.subscriber.userid" => (string)($line['authName'] ?? $ext),
                "account.$index.sip.subscriber.password" => (string)($line['secret'] ?? ''),
                "account.$index.sip.subscriber.name" => $displayName,
                "account.$index.sip.voicemail.number" => (string)($settings['voicemail_number'] ?? '*97'),
                "account.$index.sip.transport" => $transport,
            ] as $name => $value) {
                $this->item($doc, $config, $name, $value);
            }
            if (isset($settings['auto_answer']) && $settings['auto_answer'] !== '') {
                $this->item($doc, $config, "account.$index.intercom.allowAutoAnswer", $this->boolValue($settings['auto_answer']) ? 'Yes' : 'No');
            }
            if (isset($settings['digit_timeout'])) {
                $this->item($doc, $config, "account.$index.call.noKeyEntryTimeout", (string)max(1, min(15, (int)$settings['digit_timeout'])));
            }

            $rawHandsets = $settings["gs_dect_account_{$index}_handsets"]
                ?? ($settings["gs_dect_account_{$index}_handset"] ?? min($index, $maxHandsets));
            $handsets = $this->handsetSelection($rawHandsets, $maxHandsets, $warnings, $index);
            $this->item($doc, $config, "account.$index.ring.mode", '103');
            for ($handset = 1; $handset <= $maxHandsets; $handset++) {
                $this->item($doc, $config, "account.$index.hs$handset", in_array($handset, $handsets, true) ? 'Yes' : 'No');
                if (in_array($handset, $handsets, true)) {
                    $handsetLines[$handset][] = $index;
                }
            }
        }
        foreach ($this->dectHandsetNames($settings, $lineLabels, $handsetLines, $maxHandsets, 10, $warnings, 'Grandstream DP755') as $handset => $name) {
            $this->item($doc, $config, "hs.$handset.user.hsName", $name);
        }
    }

    private function renderDp755Globals(\DOMDocument $doc, \DOMElement $config, array $settings, string $phonebookUrl, array &$warnings): void
    {
        if (!empty($settings['ntp_server'])) {
            $this->item($doc, $config, 'dateTime.ntp.server.1', (string)$settings['ntp_server']);
        }
        if (!empty($settings['admin_password'])) {
            $this->item($doc, $config, 'users.admin.password', (string)$settings['admin_password']);
        }
        if (!empty($settings['user_password'])) {
            $this->item($doc, $config, 'users.user.password', (string)$settings['user_password']);
        }
        if ($phonebookUrl !== '') {
            $scheme = strtolower((string)(parse_url($phonebookUrl, PHP_URL_SCHEME) ?: 'http'));
            $modes = ['http'=>'EnabledUseHTTP', 'https'=>'EnabledUseHTTPS', 'tftp'=>'EnabledUseTFTP', 'ftp'=>'EnabledUseFTP'];
            if (isset($modes[$scheme])) {
                $this->item($doc, $config, 'phonebook.download.mode', $modes[$scheme]);
                $this->item($doc, $config, 'phonebook.download.server', preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $phonebookUrl));
                $this->item($doc, $config, 'phonebook.download.interval', (string)max(5, min(720, (int)($settings['gs_phonebook_interval'] ?? 60))));
            } else {
                $warnings[] = "Unsupported DP755 phonebook URL scheme '$scheme'; automatic phonebook download was not emitted.";
            }
        }
        $firmware = $this->firmwareEndpoint($settings, $warnings);
        if ($firmware !== null) {
            $this->item($doc, $config, 'provisioning.firmware.protocol', $firmware['protocol']);
            $this->item($doc, $config, 'provisioning.firmware.serverPath', $firmware['server']);
        }
        $ringtone = $settings['grandstream_ringtone'] ?? ($settings['ringtone'] ?? null);
        if (is_numeric($ringtone) && (int)$ringtone >= 0 && (int)$ringtone <= 10) {
            for ($handset = 1; $handset <= 10; $handset++) {
                $this->item($doc, $config, "hs.$handset.user.customRingtone", (string)(int)$ringtone);
            }
        }
        $networkAliases = [
            'gs_alias_network_lldp_enable'=>'network.lldp.enable',
            'gs_alias_network_cdp'=>'network.cdp',
            'gs_alias_network_dhcp_enable_vlan'=>'network.dhcp.enableVlan',
            'gs_alias_network_eth1_vlan_enable'=>'network.port.eth.1.vlan.enable',
            'gs_alias_network_eth1_vlan_tag'=>'network.port.eth.1.vlan.tag',
            'gs_alias_network_eth1_vlan_priority'=>'network.port.eth.1.vlan.priority',
        ];
        foreach ($networkAliases as $key=>$name) {
            if (array_key_exists($key, $settings) && $settings[$key] !== '') {
                $this->item($doc, $config, $name, (string)$settings[$key]);
            }
        }
        foreach ($settings as $key => $value) {
            if ($value !== '' && strpos((string)$key, 'gs_P') === 0) {
                $warnings[] = 'Direct gs_P settings were ignored for DP755 because version-2 alias XML cannot safely mix P-value elements.';
                break;
            }
        }
    }

    /**
     * Render the documented GXP/GRP VPK namespace and GXP2200EXT namespace.
     * Button 1 is the primary account appearance managed by firmware; button 2
     * therefore addresses VPK 1. Account registration remains independent.
     */
    private function renderDeskKeys(
        \DOMDocument $doc,
        \DOMElement $config,
        array $lines,
        array $settings,
        array $model,
        string $modelName,
        string $configProfile,
        array &$warnings
    ): int {
        $catalogKeys = (int)($model['programmable_keys'] ?? 0);
        $baseButtons = max(1, $catalogKeys > 0 ? $catalogKeys : (int)($model['line_keys'] ?? 1));
        $fixedCounts = [
            'GXP2130'=>3, 'GXP2140'=>4, 'GXP2170'=>12,
            'GRP2604'=>6, 'GRP2614'=>4, 'GRP2634'=>4,
        ];
        $fixedCount = min(max(0, $baseButtons - 1), (int)($fixedCounts[$modelName] ?? 0));
        $isGxp = str_starts_with($modelName, 'GXP') || $configProfile === 'grandstream_gxp';

        $hasExpansion = !empty($model['kem_support'])
            || !empty($model['expansion_types'])
            || in_array($modelName, ['GXP2140', 'GXP2170'], true);
        $maxModules = max(0, (int)($model['max_expansion_modules'] ?? 0));
        $keysPerModule = max(0, (int)($model['keys_per_expansion'] ?? 0));
        if ($hasExpansion && $maxModules === 0) {
            $maxModules = 4;
        }
        if ($hasExpansion && $keysPerModule === 0) {
            $keysPerModule = 40;
        }
        $requestedModules = max(0, (int)($settings['grandstream_expansion_count'] ?? 0));
        $configuredModules = min($requestedModules, $maxModules);
        $expansionCapacity = $configuredModules * $keysPerModule;
        if ($requestedModules > $maxModules) {
            $warnings[] = sprintf('Grandstream expansion count %d exceeds this model\'s %d-module limit; only %d module(s) were used.', $requestedModules, $maxModules, $configuredModules);
        }

        $vpk = [];
        $extensionKeys = [];
        foreach ($lines as $line) {
            $button = (int)($line['button'] ?? 0);
            if ($button < 2) {
                continue;
            }
            $featureId = (int)($line['featureId'] ?? 0);
            $isLine = $featureId === 9;
            if (!$isLine && !in_array($featureId, [2, 18, 21, 22], true)) {
                continue;
            }
            $target = $isLine
                ? (string)($line['name'] ?? ($line['contact'] ?? ''))
                : (string)($line['speedDialNumber'] ?? '');
            if (!$isLine && $target === '' && $featureId !== 18) {
                continue;
            }
            $account = $isLine
                ? max(0, (int)($line['accountIndex'] ?? ($line['lineIndex'] ?? 1)) - 1)
                : max(0, (int)($line['account'] ?? 0));
            $account = min(max(0, (int)($model['sip_accounts'] ?? 1) - 1), $account);
            $entry = [
                'button'=>$button,
                'featureId'=>$featureId,
                'line'=>$isLine,
                'account'=>$account,
                'label'=>(string)($line['label'] ?? $target),
                'target'=>$target,
            ];

            if ($button <= $baseButtons) {
                $position = $button - 1;
                if ($isLine && ($fixedCount === 0 || $position > $fixedCount)) {
                    $warnings[] = sprintf('Grandstream account on button %d remains registered, but this model cannot place a Line function on dynamic VPK %d.', $button, $position);
                    continue;
                }
                $vpk[$position] = $entry;
                continue;
            }

            $extensionPosition = $button - $baseButtons;
            if ($extensionPosition > $expansionCapacity) {
                $warnings[] = sprintf(
                    'Grandstream key position %d exceeds the configured base/expansion capacity (%d); skipped.',
                    $button,
                    $baseButtons + $expansionCapacity
                );
                continue;
            }
            if ($isLine) {
                $warnings[] = sprintf('Grandstream account on button %d remains registered, but GXP2200EXT keys do not support the Line mode.', $button);
                continue;
            }
            $extensionKeys[$extensionPosition] = $entry;
        }

        if ($vpk && $fixedCount === 0) {
            $warnings[] = sprintf('No verified fixed-VPK map is available for Grandstream model %s; BLF/speed-dial keys use the documented dynamic VPK encoding.', $modelName ?: 'unknown');
        }

        ksort($vpk);
        if ($vpk) {
            $lastPosition = max(array_keys($vpk));
            // Clear every deliberate gap. This also satisfies Grandstream's rule
            // that omitting an earlier dynamic VPK removes every later one.
            for ($position = 1; $position <= $lastPosition; $position++) {
                if (!isset($vpk[$position])) {
                    $this->renderVpk($doc, $config, $position, '-1', 0, '', '');
                    continue;
                }
                $entry = $vpk[$position];
                if ($entry['line']) {
                    $mode = $isGxp ? '0' : '31';
                    $target = '';
                } else {
                    $isBlf = in_array($entry['featureId'], [21, 22], true);
                    $mode = $entry['featureId'] === 18 ? '18' : (($isGxp && $position <= $fixedCount)
                        ? ($isBlf ? '11' : '10')
                        : ($isBlf ? '1' : '0'));
                    $target = $entry['target'];
                }
                $this->renderVpk($doc, $config, $position, $mode, $entry['account'], $entry['label'], $target);
            }
        }

        ksort($extensionKeys);
        $lastExtensionPosition = $extensionKeys ? max(array_keys($extensionKeys)) : 0;
        for ($position = 1; $position <= $lastExtensionPosition; $position++) {
            $base = 23000 + (($position - 1) * 5);
            if (!isset($extensionKeys[$position])) {
                $this->pval($doc, $config, 'P' . $base, '-1');
                $this->pval($doc, $config, 'P' . ($base + 1), '0');
                $this->pval($doc, $config, 'P' . ($base + 2), '');
                $this->pval($doc, $config, 'P' . ($base + 3), '');
                continue;
            }
            $entry = $extensionKeys[$position];
            $this->pval($doc, $config, 'P' . $base, $entry['featureId'] === 18 ? '18' : (in_array($entry['featureId'], [21, 22], true) ? '1' : '0'));
            $this->pval($doc, $config, 'P' . ($base + 1), (string)$entry['account']);
            $this->pval($doc, $config, 'P' . ($base + 2), $entry['label']);
            $this->pval($doc, $config, 'P' . ($base + 3), $entry['target']);
        }

        return count($vpk) + count($extensionKeys);
    }

    private function renderVpk(
        \DOMDocument $doc,
        \DOMElement $config,
        int $position,
        string $mode,
        int $account,
        string $label,
        string $target
    ): void {
        if ($position <= 6) {
            $modeParam = 1363 + (($position - 1) * 2);
            $labelParam = 1465 + (($position - 1) * 2);
            $params = [$modeParam, $modeParam + 1, $labelParam, $labelParam + 1];
        } else {
            $modeParam = 23800 + (($position - 7) * 4);
            $params = [$modeParam, $modeParam + 1, $modeParam + 2, $modeParam + 3];
        }
        $this->pval($doc, $config, 'P' . $params[0], $mode);
        $this->pval($doc, $config, 'P' . $params[1], (string)$account);
        $this->pval($doc, $config, 'P' . $params[2], $label);
        $this->pval($doc, $config, 'P' . $params[3], $target);
    }

    /** GHP630/631 firmware 1.0.1.50: six programmable/context keys. */
    private function renderGhp63xKeys(\DOMDocument $doc, \DOMElement $config, array $lines, array &$warnings): int
    {
        $modeParams = [1363, 1365, 1367, 1369, 1371, 1373];
        $accountParams = [1364, 1366, 1368, 1370, 1372, 1374];
        $descriptionParams = [1465, 1467, 1469, 1471, 1473, 1475];
        $valueParams = [1466, 1468, 1470, 1472, 1474, 1476];
        $iconParams = [23990, 23991, 23992, 23993, 23994, 23995];
        $written = 0;
        foreach ($lines as $line) {
            $button = (int)($line['button'] ?? 0);
            if ($button > 7 && (int)($line['featureId'] ?? 0) !== 9) {
                $warnings[] = sprintf('GHP63x button %d is outside the six context keys; digit-pad assignments must use ghp_speed_dials.', $button);
                continue;
            }
            if ($button < 2 || $button > 7 || (int)($line['featureId'] ?? 0) === 9) {
                continue;
            }
            $index = $button - 2;
            $featureId = (int)($line['featureId'] ?? 0);
            if (!in_array($featureId, [-1, 2, 5, 6, 7, 27], true)) {
                $warnings[] = sprintf('GHP63x context key %d uses an unsupported mode; only None, Speed Dial, Dial DTMF, Voice Mail, Call Return and GDS Open Door are emitted.', $index + 1);
                continue;
            }
            $target = (string)($line['speedDialNumber'] ?? '');
            $description = (string)($line['label'] ?? $target);
            if ($this->characterLength($description) > 32 || $this->characterLength($target) > 64) {
                $warnings[] = sprintf('GHP63x context key %d exceeds the documented 32-character description or 64-character value limit; skipped.', $index + 1);
                continue;
            }
            $icon = strtoupper(trim((string)($line['icon'] ?? '')));
            if ($icon !== '' && !in_array($icon, self::GHP63X_ICON_CODES, true)) {
                $warnings[] = sprintf('GHP63x context key %d has unsupported icon code %s; the key was kept without an icon override.', $index + 1, $icon);
                $icon = '';
            }
            if ($icon !== '') {
                $this->pval($doc, $config, 'P' . $iconParams[$index], $icon);
            }
            $this->pval($doc, $config, 'P' . $modeParams[$index], $featureId === 2 ? '0' : (string)$featureId);
            $this->pval($doc, $config, 'P' . $accountParams[$index], (string)max(0, min(1, (int)($line['account'] ?? 0))));
            $this->pval($doc, $config, 'P' . $descriptionParams[$index], $description);
            $this->pval($doc, $config, 'P' . $valueParams[$index], $target);
            $written++;
        }
        return $written;
    }

    /** GHP630/631 digit keys 1..9 and 0 have their own ten speed-dial parameter sets. */
    private function renderGhp63xSpeedDials(\DOMDocument $doc, \DOMElement $config, array $settings, array &$warnings): int
    {
        $rows = $settings['ghp_speed_dials'] ?? [];
        if (is_string($rows)) {
            $rows = json_decode($rows, true) ?: [];
        }
        if (!is_array($rows)) {
            return 0;
        }
        $params = [
            '1'=>[324,304,305,306], '2'=>[325,307,308,309], '3'=>[326,310,311,312],
            '4'=>[327,313,314,315], '5'=>[328,316,317,318], '6'=>[329,319,320,321],
            '7'=>[353,354,355,356], '8'=>[357,358,359,360], '9'=>[361,362,363,364],
            '0'=>[323,301,302,303],
        ];
        $written = 0;
        foreach ($rows as $row) {
            $digit = (string)($row['digit'] ?? '');
            if (!isset($params[$digit]) || ($row['mode'] ?? '') === '') {
                continue;
            }
            $modeValue = (int)$row['mode'];
            if (!in_array($modeValue, [-1, 0, 5, 27], true)) {
                $warnings[] = sprintf('GHP63x digit %s uses unsupported mode %s; skipped.', $digit, (string)$row['mode']);
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $target = trim((string)($row['target'] ?? ''));
            if ($this->characterLength($label) > 256 || $this->characterLength($target) > 256) {
                $warnings[] = sprintf('GHP63x digit %s description or value exceeds the documented 256-character limit; skipped.', $digit);
                continue;
            }
            [$mode, $account, $description, $value] = $params[$digit];
            $this->pval($doc, $config, 'P' . $mode, (string)$modeValue);
            $this->pval($doc, $config, 'P' . $account, (string)max(0, min(1, (int)($row['account'] ?? 0))));
            $this->pval($doc, $config, 'P' . $description, $label);
            $this->pval($doc, $config, 'P' . $value, $target);
            $written++;
        }
        return $written;
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $matched = preg_match_all('/./us', $value, $characters);
        return $matched === false ? strlen($value) : $matched;
    }

    private function renderDeskAccounts(\DOMDocument $doc, \DOMElement $config, array $accounts, array $settings, string $pbx, int $port): void
    {
        $server = $port === 5060 ? $pbx : "$pbx:$port";
        $bases = [2=>400, 3=>500, 4=>600, 5=>1700, 6=>1800, 7=>50600, 8=>50700, 9=>50800, 10=>50900, 11=>51000, 12=>51100, 13=>51200, 14=>51300, 15=>51400, 16=>51500];
        $transport = (string)$this->transportCode((string)($settings['transport'] ?? 'udp'));
        foreach ($accounts as $line) {
            $index = (int)$line['_account_index'];
            $ext = (string)($line['name'] ?? '');
            $label = (string)($line['label'] ?? $ext);
            $displayName = (string)($line['displayName'] ?? $ext);
            $secret = (string)($line['secret'] ?? '');
            if ($index === 1) {
                $map = ['active'=>'P271', 'account'=>'P270', 'server'=>'P47', 'user'=>'P35', 'auth'=>'P36', 'password'=>'P34', 'name'=>'P3', 'vm'=>'P33', 'transport'=>'P130'];
            } else {
                if (!isset($bases[$index])) {
                    continue;
                }
                $base = $bases[$index];
                $map = ['active'=>'P'.($base+1), 'account'=>'P'.($base+17), 'server'=>'P'.($base+2), 'user'=>'P'.($base+4), 'auth'=>'P'.($base+5), 'password'=>'P'.($base+6), 'name'=>'P'.($base+7), 'vm'=>'P'.($base+26), 'transport'=>'P'.($base+48)];
            }
            foreach (['active'=>'1', 'account'=>$label, 'server'=>$server, 'user'=>$ext, 'auth'=>(string)($line['authName'] ?? $ext), 'password'=>$secret, 'name'=>$displayName, 'vm'=>(string)($settings['voicemail_number'] ?? '*97'), 'transport'=>$transport] as $field => $value) {
                $this->pval($doc, $config, $map[$field], $value);
            }
        }
    }

    private function renderDp75x(
        \DOMDocument $doc,
        \DOMElement $config,
        array $accounts,
        array $settings,
        string $pbx,
        int $port,
        int $maxHandsets,
        array &$warnings
    ): void {
        $server = $port === 5060 ? $pbx : "$pbx:$port";
        $profileMaps = [
            1=>['active'=>'P271', 'name'=>'P27762', 'server'=>'P47',  'vm'=>'P27680', 'transport'=>'P130'],
            2=>['active'=>'P401', 'name'=>'P27763', 'server'=>'P747', 'vm'=>'P27681', 'transport'=>'P830'],
            3=>['active'=>'P501', 'name'=>'P27764', 'server'=>'P547', 'vm'=>'P27682', 'transport'=>'P530'],
            4=>['active'=>'P601', 'name'=>'P27765', 'server'=>'P602', 'vm'=>'P27683', 'transport'=>'P4648'],
        ];
        $profiles = [];
        foreach ($accounts as $line) {
            $index = (int)$line['_account_index'];
            $profiles[$index] = max(1, min(4, (int)($settings["gs_dect_account_{$index}_profile"] ?? 1)));
        }
        foreach (array_unique(array_values($profiles)) as $profile) {
            $map = $profileMaps[$profile];
            foreach (['active'=>'1', 'name'=>'Asterisk', 'server'=>$server, 'vm'=>(string)($settings['voicemail_number'] ?? '*97'), 'transport'=>(string)$this->transportCode((string)($settings['transport'] ?? 'udp'))] as $field => $value) {
                $this->pval($doc, $config, $map[$field], $value);
            }
        }
        $handsetLines = [];
        $lineLabels = [];
        foreach ($accounts as $line) {
            $index = (int)$line['_account_index'];
            $offset = $index - 1;
            $suffix = (string)$offset;
            $ext = (string)($line['name'] ?? '');
            $lineLabels[$index] = (string)($line['label'] ?? $ext);
            $displayName = $this->limited(
                (string)($line['displayName'] ?? $ext),
                256,
                "Grandstream DP75x account {$index} SIP name",
                $warnings
            );
            $profile = $profiles[$index];
            $rawHandsets = $settings["gs_dect_account_{$index}_handsets"]
                ?? ($settings["gs_dect_account_{$index}_handset"] ?? min($index, $maxHandsets));
            $handsets = $this->handsetSelection($rawHandsets, $maxHandsets, $warnings, $index);
            $hsMode = count($handsets) === 1 ? $handsets[0] : 103;
            foreach ([
                'P406' . $suffix => $ext,
                'P409' . $suffix => (string)($line['authName'] ?? $ext),
                'P412' . $suffix => (string)($line['secret'] ?? ''),
                'P418' . $suffix => $displayName,
                'P415' . $suffix => (string)($profile - 1),
                'P2708' . $suffix => (string)$hsMode,
                'P' . (4595 + $offset) => '1',
            ] as $p => $value) {
                $this->pval($doc, $config, $p, $value);
            }
            foreach ($handsets as $handset) {
                $handsetLines[$handset][] = $index;
            }
        }
        foreach ($this->dectHandsetNames($settings, $lineLabels, $handsetLines, $maxHandsets, 10, $warnings, 'Grandstream DP75x') as $handset => $name) {
            $this->pval($doc, $config, 'P' . (27020 + $handset - 1), $name);
        }
        for ($handset = 1; $handset <= $maxHandsets; $handset++) {
            $accountIndexes = array_slice($handsetLines[$handset] ?? [], 0, 10);
            for ($slot = 0; $slot < 10; $slot++) {
                $this->pval($doc, $config, 'P' . (4300 + (($handset - 1) * 10) + $slot), (string)($accountIndexes[$slot] ?? 0));
            }
        }
    }

    private function isManagedIdentityPValue(string $pValue, string $model): bool
    {
        if (in_array($model, ['DP750', 'DP752'], true)) {
            $number = (int)substr($pValue, 1);
            return ($number >= 4180 && $number <= 4189)
                || ($number >= 27020 && $number <= 27024);
        }
        $managed = ['P270', 'P3'];
        foreach ([400, 500, 600, 1700, 1800, 50600, 50700, 50800, 50900, 51000, 51100, 51200, 51300, 51400, 51500] as $base) {
            $managed[] = 'P' . ($base + 17);
            $managed[] = 'P' . ($base + 7);
        }
        return in_array($pValue, $managed, true);
    }
}
