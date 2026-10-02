<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Normalized VLAN intent rendered into verified vendor settings.
 *
 * Missing/inherit policy emits nothing. This is important during upgrades:
 * installing the module must never move a phone to a new VLAN.
 */
final class VlanPolicy
{
    public const MODES = ['inherit', 'discovery', 'manual', 'off'];
    public const PC_MODES = ['inherit', 'untagged', 'tagged', 'disabled'];
    public const DISCOVERY = ['lldp', 'cdp', 'dhcp'];

    /**
     * @return array<string,mixed>
     */
    public static function normalize(array $settings): array
    {
        $mode = strtolower(trim((string)($settings['vlan_mode'] ?? 'inherit'))) ?: 'inherit';
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('VLAN mode must be inherit, discovery, manual, or off');
        }
        $pcMode = strtolower(trim((string)($settings['vlan_pc_port'] ?? 'inherit'))) ?: 'inherit';
        if (!in_array($pcMode, self::PC_MODES, true)) {
            throw new \InvalidArgumentException('PC-port VLAN mode is invalid');
        }
        $discoveryInput = $settings['vlan_discovery'] ?? ['lldp', 'cdp', 'dhcp'];
        if (is_string($discoveryInput)) {
            $discoveryInput = preg_split('/[\s,]+/', strtolower($discoveryInput), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($discoveryInput)) {
            throw new \InvalidArgumentException('VLAN discovery must be a list');
        }
        $discovery = [];
        foreach ($discoveryInput as $protocol) {
            $protocol = strtolower(trim((string)$protocol));
            if (!in_array($protocol, self::DISCOVERY, true)) {
                throw new \InvalidArgumentException("Unsupported VLAN discovery protocol $protocol");
            }
            if (!in_array($protocol, $discovery, true)) { $discovery[] = $protocol; }
        }
        if ($mode === 'discovery' && !$discovery) {
            throw new \InvalidArgumentException('Discovery mode needs at least one supported discovery protocol');
        }

        $voiceVid = self::integer($settings, 'vlan_voice_vid', 1, 4094);
        $voicePcp = self::integer($settings, 'vlan_voice_pcp', 0, 7, 0);
        $pcVid = self::integer($settings, 'vlan_pc_vid', 1, 4094);
        $pcPcp = self::integer($settings, 'vlan_pc_pcp', 0, 7, 0);
        if ($mode === 'manual' && $voiceVid === null) {
            throw new \InvalidArgumentException('Manual VLAN mode requires a voice VLAN ID from 1 to 4094');
        }
        if ($pcMode === 'tagged' && $pcVid === null) {
            throw new \InvalidArgumentException('Tagged PC-port mode requires a PC VLAN ID from 1 to 4094');
        }

        return [
            'mode'=>$mode,
            'voice_vid'=>$voiceVid,
            'voice_pcp'=>$voicePcp ?? 0,
            'pc_port'=>$pcMode,
            'pc_vid'=>$pcVid,
            'pc_pcp'=>$pcPcp ?? 0,
            'discovery'=>$discovery,
        ];
    }

    /**
     * Add only verified vendor settings and return an operator-facing trace.
     *
     * @return array{status:string,profile:string,message:string,emitted:array<int,string>}
     */
    public static function apply(string $family, array $model, array &$settings): array
    {
        $policy = self::normalize($settings);
        $mode = $policy['mode'];
        $modelName = strtoupper(trim((string)($model['model'] ?? '')));
        $role = strtolower(trim((string)($model['role'] ?? 'desk')));
        if ($mode === 'inherit') {
            return ['status'=>'inherited','profile'=>$family,'message'=>'No VLAN settings emitted; switch, DHCP, firmware, or an earlier layer remains authoritative.','emitted'=>[]];
        }

        return match ($family) {
            'cisco_xml' => self::ciscoXml($modelName, $policy, $settings),
            'cisco_cnf' => self::ciscoCnf($policy),
            'yealink' => self::yealink($modelName, $role, (string)($model['config_profile'] ?? ''), $policy, $settings),
            'grandstream' => self::grandstream(
                $modelName,
                $role,
                strtolower((string)($model['config_profile'] ?? '')),
                $policy,
                $settings
            ),
            default => throw new \RuntimeException("VLAN policy is not implemented for family $family"),
        };
    }

    private static function ciscoXml(string $model, array $policy, array &$settings): array
    {
        $emitted = [];
        $wireless = $model === '8821';
        if ($policy['mode'] === 'manual') {
            throw new \RuntimeException('Cisco enterprise XML provisioning has no verified static voice-VID field; use CDP/LLDP/DHCP discovery or switch policy');
        }
        if ($wireless && $policy['pc_port'] !== 'inherit') {
            throw new \RuntimeException('Cisco 8821 has no wired PC port');
        }
        $discovery = $policy['mode'] === 'discovery' ? $policy['discovery'] : [];
        if ($wireless) {
            $settings['cisco_cdp_enable'] = in_array('cdp', $discovery, true) ? '1' : '0';
            $emitted[] = 'cdpEnable';
        } else {
            $settings['cisco_enable_cdp_sw_port'] = in_array('cdp', $discovery, true) ? '1' : '0';
            $settings['cisco_enable_lldp_sw_port'] = in_array('lldp', $discovery, true) ? '1' : '0';
            $emitted = ['enableCdpSwPort', 'enableLldpSwPort'];
            if ($policy['pc_port'] === 'disabled') {
                $settings['cisco_pc_port'] = '1';
                $emitted[] = 'pcPort';
            } elseif ($policy['pc_port'] === 'untagged') {
                $settings['cisco_pc_port'] = '0';
                $settings['cisco_voice_vlan_access'] = '0';
                array_push($emitted, 'pcPort', 'voiceVlanAccess');
            } elseif ($policy['pc_port'] === 'tagged') {
                throw new \RuntimeException('Cisco XML has no verified static data-VLAN tag field for the downstream PC port');
            }
        }
        $message = $policy['mode'] === 'off'
            ? 'CDP/LLDP VLAN discovery disabled by policy.'
            : 'Cisco voice VLAN remains network-discovered; no static VID was invented.';
        if (in_array('dhcp', $discovery, true)) {
            $message .= ' DHCP VLAN remains firmware/network-owned because SEP XML has no verified option selector.';
        }
        return ['status'=>'rendered','profile'=>$wireless ? 'cisco_8821' : 'cisco_enterprise_xml','message'=>$message,'emitted'=>$emitted];
    }

    private static function ciscoCnf(array $policy): array
    {
        if ($policy['mode'] === 'discovery' && $policy['pc_port'] === 'inherit') {
            return [
                'status'=>'firmware_owned',
                'profile'=>'cisco_cnf',
                'message'=>'Legacy Cisco CNF VLAN discovery stays switch/CDP/DHCP-owned; no unverified SIPDefault.cnf key was emitted.',
                'emitted'=>[],
            ];
        }
        throw new \RuntimeException('Legacy Cisco CNF has no verified static/off/PC-port VLAN provisioning profile; leave it on discovery/inherit and configure the switch');
    }

    private static function yealink(
        string $model,
        string $role,
        string $configProfile,
        array $policy,
        array &$settings
    ): array {
        if (str_contains($role, 'handset') || str_contains($role, 'radio') || str_contains($role, 'manager')) {
            throw new \RuntimeException("Yealink $model receives network policy through its DECT system, not a standalone handset/radio config");
        }
        if ($policy['pc_port'] === 'disabled') {
            throw new \RuntimeException('This release has no verified Yealink physical PC-port disable key; use inherit, untagged, or tagged');
        }
        $prefix = strtolower($configProfile) === 'yealink_legacy_t2' ? 'network' : 'static.network';
        $set = static function (string $key, $value) use (&$settings, &$prefix): void {
            $settings[$prefix . '.' . $key] = (string)$value;
        };
        $emitted = [];
        if ($policy['mode'] === 'discovery') {
            $set('lldp.enable', in_array('lldp', $policy['discovery'], true) ? 1 : 0);
            $set('cdp.enable', in_array('cdp', $policy['discovery'], true) ? 1 : 0);
            $set('vlan.dhcp_enable', in_array('dhcp', $policy['discovery'], true) ? 1 : 0);
            $emitted = [$prefix.'.lldp.enable', $prefix.'.cdp.enable', $prefix.'.vlan.dhcp_enable'];
        } else {
            foreach (['lldp.enable','cdp.enable','vlan.dhcp_enable'] as $key) { $set($key, 0); }
            $set('vlan.internet_port_enable', $policy['mode'] === 'manual' ? 1 : 0);
            $emitted = [
                $prefix.'.lldp.enable', $prefix.'.cdp.enable', $prefix.'.vlan.dhcp_enable',
                $prefix.'.vlan.internet_port_enable',
            ];
            if ($policy['mode'] === 'manual') {
                $set('vlan.internet_port_vid', $policy['voice_vid']);
                $set('vlan.internet_port_priority', $policy['voice_pcp']);
                array_push($emitted, $prefix.'.vlan.internet_port_vid', $prefix.'.vlan.internet_port_priority');
            }
        }
        if ($policy['pc_port'] === 'tagged') {
            $set('vlan.pc_port_enable', 1);
            $set('vlan.pc_port_vid', $policy['pc_vid']);
            $set('vlan.pc_port_priority', $policy['pc_pcp']);
            array_push($emitted, $prefix.'.vlan.pc_port_enable', $prefix.'.vlan.pc_port_vid', $prefix.'.vlan.pc_port_priority');
        } elseif ($policy['pc_port'] === 'untagged') {
            $set('vlan.pc_port_enable', 0);
            $emitted[] = $prefix.'.vlan.pc_port_enable';
        }
        return [
            'status'=>'rendered',
            'profile'=>$prefix === 'network' ? 'yealink_legacy' : 'yealink_static',
            'message'=>'Yealink VLAN policy rendered with the model configuration namespace.',
            'emitted'=>$emitted,
        ];
    }

    private static function grandstream(
        string $model,
        string $role,
        string $configProfile,
        array $policy,
        array &$settings
    ): array {
        if (str_contains($role, 'handset')) {
            throw new \RuntimeException("Grandstream $model has no verified wired VLAN P-value profile in this release");
        }
        if ($configProfile === 'grandstream_dp755_alias_v2' || $model === 'DP755') {
            return self::grandstreamDp755($policy, $settings);
        }
        if ($configProfile === 'grandstream_dp75x_pvalue' || preg_match('/^DP(?:750|752)$/', $model)) {
            return self::grandstreamDp75x($model, $policy, $settings);
        }
        if (preg_match('/^GHP63[01]W?$/', $model) || $configProfile === 'grandstream_ghp') {
            return self::grandstreamGhp($model, $policy, $settings);
        }
        if (!preg_match('/^(?:GXP|GRP)/', $model)) {
            throw new \RuntimeException("Grandstream $model has no verified wired VLAN P-value profile in this release");
        }
        return self::grandstreamDesk($model, $policy, $settings);
    }

    private static function grandstreamDesk(string $model, array $policy, array &$settings): array
    {
        if ($policy['pc_port'] === 'disabled') {
            throw new \RuntimeException('This Grandstream profile has no verified PC-port disable value; use inherit, untagged, or tagged');
        }
        self::setGrandstreamDiscovery($settings, $policy);
        $emitted = ['P8300', 'P22174', 'P22119', 'P1684'];
        if ($policy['mode'] === 'manual') {
            $settings['gs_P51'] = (string)$policy['voice_vid'];
            $settings['gs_P87'] = (string)$policy['voice_pcp'];
            array_push($emitted, 'P51', 'P87');
        } elseif ($policy['mode'] === 'off') {
            $settings['gs_P51'] = '0';
            $settings['gs_P87'] = '0';
            array_push($emitted, 'P51', 'P87');
        }
        if ($policy['pc_port'] === 'tagged') {
            $settings['gs_P229'] = (string)$policy['pc_vid'];
            $settings['gs_P230'] = (string)$policy['pc_pcp'];
            array_push($emitted, 'P229', 'P230');
        } elseif ($policy['pc_port'] === 'untagged') {
            $settings['gs_P229'] = '0';
            $settings['gs_P230'] = '0';
            array_push($emitted, 'P229', 'P230');
        }
        return [
            'status'=>'rendered',
            'profile'=>'grandstream_gxp_grp',
            'message'=>"Grandstream $model VLAN policy rendered with the documented discovery/manual and PC-tag P-values.",
            'emitted'=>array_values(array_unique($emitted)),
        ];
    }

    private static function grandstreamGhp(string $model, array $policy, array &$settings): array
    {
        if ($policy['pc_port'] !== 'inherit') {
            throw new \RuntimeException("Grandstream $model has no verified downstream PC-port VLAN profile");
        }
        $discovery = $policy['mode'] === 'discovery' ? $policy['discovery'] : [];
        // GHP63x firmware 1.0.1.50 uses normal 1=enabled polarity for all
        // four discovery/manual controls. Older GXP/GRP profiles are handled
        // separately by setGrandstreamDiscovery().
        $settings['gs_P8300'] = in_array('dhcp', $discovery, true) ? '1' : '0';
        $settings['gs_P1684'] = in_array('lldp', $discovery, true) ? '1' : '0';
        $settings['gs_P22119'] = in_array('cdp', $discovery, true) ? '1' : '0';
        $settings['gs_P22174'] = $policy['mode'] === 'manual' ? '1' : '0';
        $emitted = ['P8300', 'P22174', 'P22119', 'P1684'];
        if ($policy['mode'] === 'manual') {
            $settings['gs_P51'] = (string)$policy['voice_vid'];
            $settings['gs_P87'] = (string)$policy['voice_pcp'];
            array_push($emitted, 'P51', 'P87');
        } elseif ($policy['mode'] === 'off') {
            $settings['gs_P51'] = '0';
            $settings['gs_P87'] = '0';
            array_push($emitted, 'P51', 'P87');
        }
        return [
            'status'=>'rendered',
            'profile'=>'grandstream_ghp',
            'message'=>"Grandstream $model VLAN policy rendered with its documented discovery, manual VID and 802.1p priority fields.",
            'emitted'=>$emitted,
        ];
    }

    private static function grandstreamDp75x(string $model, array $policy, array &$settings): array
    {
        if ($policy['pc_port'] !== 'inherit') {
            throw new \RuntimeException("Grandstream $model DECT base has no downstream PC port");
        }
        if ($policy['mode'] === 'discovery') {
            if (!in_array('lldp', $policy['discovery'], true)) {
                throw new \RuntimeException("Grandstream $model DECT discovery supports LLDP; select LLDP or use manual/off");
            }
            $settings['gs_P1684'] = '1';
            return [
                'status'=>'rendered',
                'profile'=>'grandstream_dp75x_pvalue',
                'message'=>"Grandstream $model uses LLDP discovery; unsupported CDP/DHCP selections are not emitted.",
                'emitted'=>['P1684'],
            ];
        }
        $manual = $policy['mode'] === 'manual';
        $settings['gs_P1684'] = '0';
        $settings['gs_P27004'] = $manual ? '1' : '0';
        $settings['gs_P51'] = $manual ? (string)$policy['voice_vid'] : '0';
        $settings['gs_P5038'] = $manual ? (string)$policy['voice_pcp'] : '0';
        $settings['gs_P5042'] = $manual ? (string)$policy['voice_pcp'] : '0';
        return [
            'status'=>'rendered',
            'profile'=>'grandstream_dp75x_pvalue',
            'message'=>"Grandstream $model DECT VLAN policy rendered with its base-specific VID, SIP/RTP PCP and LLDP fields.",
            'emitted'=>['P1684','P27004','P51','P5038','P5042'],
        ];
    }

    private static function grandstreamDp755(array $policy, array &$settings): array
    {
        if ($policy['pc_port'] !== 'inherit') {
            throw new \RuntimeException('Grandstream DP755 has no downstream PC port');
        }
        $enabled = static fn(bool $value): string => $value ? 'Yes' : 'No';
        $discovery = $policy['mode'] === 'discovery' ? $policy['discovery'] : [];
        $settings['gs_alias_network_lldp_enable'] = $enabled(in_array('lldp', $discovery, true));
        $settings['gs_alias_network_cdp'] = $enabled(in_array('cdp', $discovery, true));
        $settings['gs_alias_network_dhcp_enable_vlan'] = $enabled(in_array('dhcp', $discovery, true));
        $manual = $policy['mode'] === 'manual';
        $settings['gs_alias_network_eth1_vlan_enable'] = $enabled($manual);
        if ($manual) {
            $settings['gs_alias_network_eth1_vlan_tag'] = (string)$policy['voice_vid'];
            $settings['gs_alias_network_eth1_vlan_priority'] = (string)$policy['voice_pcp'];
        }
        return [
            'status'=>'rendered',
            'profile'=>'grandstream_dp755_alias_v2',
            'message'=>'Grandstream DP755 VLAN policy rendered through its version-2 network alias namespace.',
            'emitted'=>array_values(array_filter([
                'network.lldp.enable','network.cdp','network.dhcp.enableVlan',
                'network.port.eth.1.vlan.enable',
                $manual ? 'network.port.eth.1.vlan.tag' : null,
                $manual ? 'network.port.eth.1.vlan.priority' : null,
            ])),
        ];
    }

    /**
     * Grandstream GXP/GRP/GHP boolean values are not uniform:
     * P8300/P1684 use 1=enabled while P22174/P22119 use 0=enabled.
     */
    private static function setGrandstreamDiscovery(array &$settings, array $policy): void
    {
        $discovery = $policy['mode'] === 'discovery' ? $policy['discovery'] : [];
        $settings['gs_P8300'] = in_array('dhcp', $discovery, true) ? '1' : '0';
        $settings['gs_P1684'] = in_array('lldp', $discovery, true) ? '1' : '0';
        $settings['gs_P22119'] = in_array('cdp', $discovery, true) ? '0' : '1';
        $settings['gs_P22174'] = $policy['mode'] === 'manual' ? '0' : '1';
    }

    private static function integer(
        array $settings,
        string $key,
        int $min,
        int $max,
        ?int $default = null
    ): ?int {
        if (!array_key_exists($key, $settings) || trim((string)$settings[$key]) === '') {
            return $default;
        }
        $value = filter_var($settings[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new \InvalidArgumentException("$key must be between $min and $max");
        }
        return (int)$value;
    }
}
