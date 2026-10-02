<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/VlanPolicy.php';

use FreePBX\modules\Ucmprovisioner\VlanPolicy;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
};

$settings = ['vlan_mode'=>'manual','vlan_voice_vid'=>'1','vlan_voice_pcp'=>'7','vlan_pc_port'=>'tagged','vlan_pc_vid'=>'4094','vlan_pc_pcp'=>'0'];
$trace = VlanPolicy::apply('yealink', ['model'=>'T48U','role'=>'desk','config_profile'=>'yealink_desk'], $settings);
$expect(($settings['static.network.vlan.internet_port_vid'] ?? '') === '1', 'Yealink voice VID lower bound not rendered');
$expect(($settings['static.network.vlan.pc_port_vid'] ?? '') === '4094', 'Yealink PC VID upper bound not rendered');
$expect($trace['status'] === 'rendered', 'Yealink VLAN trace missing');

$legacy = ['vlan_mode'=>'discovery','vlan_discovery'=>'lldp,dhcp'];
VlanPolicy::apply('yealink', ['model'=>'T28P','role'=>'desk','config_profile'=>'yealink_legacy_t2'], $legacy);
$expect(($legacy['network.lldp.enable'] ?? '') === '1' && ($legacy['network.cdp.enable'] ?? '') === '0', 'Yealink legacy discovery namespace mismatch');

$gxp = ['vlan_mode'=>'discovery','vlan_discovery'=>'lldp,cdp,dhcp','vlan_pc_port'=>'inherit'];
VlanPolicy::apply('grandstream', ['model'=>'GXP2170','role'=>'desk','config_profile'=>'grandstream_gxp'], $gxp);
$expect(
    ($gxp['gs_P8300'] ?? '') === '1'
    && ($gxp['gs_P1684'] ?? '') === '1'
    && ($gxp['gs_P22119'] ?? '') === '0'
    && ($gxp['gs_P22174'] ?? '') === '1',
    'Grandstream discovery booleans must respect their documented mixed polarity'
);

$dp750 = ['vlan_mode'=>'manual','vlan_voice_vid'=>'4094','vlan_voice_pcp'=>'0','vlan_pc_port'=>'inherit'];
VlanPolicy::apply('grandstream', ['model'=>'DP750','role'=>'dect_base','config_profile'=>'grandstream_dp75x_pvalue'], $dp750);
$expect(
    ($dp750['gs_P27004'] ?? '') === '1'
    && ($dp750['gs_P51'] ?? '') === '4094'
    && ($dp750['gs_P5038'] ?? '') === '0'
    && ($dp750['gs_P5042'] ?? '') === '0',
    'DP750/752 must use DECT-base VLAN and separate SIP/RTP PCP fields'
);

$dp755 = ['vlan_mode'=>'manual','vlan_voice_vid'=>'120','vlan_voice_pcp'=>'7','vlan_pc_port'=>'inherit'];
VlanPolicy::apply('grandstream', ['model'=>'DP755','role'=>'dect_base','config_profile'=>'grandstream_dp755_alias_v2'], $dp755);
$expect(
    ($dp755['gs_alias_network_eth1_vlan_enable'] ?? '') === 'Yes'
    && ($dp755['gs_alias_network_eth1_vlan_tag'] ?? '') === '120'
    && ($dp755['gs_alias_network_eth1_vlan_priority'] ?? '') === '7',
    'DP755 must use version-2 network aliases rather than P-values'
);

$ghp = ['vlan_mode'=>'manual','vlan_voice_vid'=>'1','vlan_voice_pcp'=>'5','vlan_pc_port'=>'inherit'];
$ghpTrace = VlanPolicy::apply('grandstream', ['model'=>'GHP630','role'=>'desk','config_profile'=>'grandstream_ghp'], $ghp);
$expect(
    ($ghp['gs_P51'] ?? '') === '1'
    && ($ghp['gs_P87'] ?? '') === '5'
    && ($ghp['gs_P22174'] ?? '') === '1'
    && ($ghpTrace['status'] ?? '') === 'rendered',
    'GHP63x manual VLAN must use P51/P87 and the documented enabled polarity'
);

$ghpDiscovery = ['vlan_mode'=>'discovery','vlan_discovery'=>'lldp,cdp,dhcp','vlan_pc_port'=>'inherit'];
VlanPolicy::apply('grandstream', ['model'=>'GHP631W','role'=>'desk','config_profile'=>'grandstream_ghp'], $ghpDiscovery);
$expect(
    ($ghpDiscovery['gs_P8300'] ?? '') === '1'
    && ($ghpDiscovery['gs_P1684'] ?? '') === '1'
    && ($ghpDiscovery['gs_P22119'] ?? '') === '1'
    && ($ghpDiscovery['gs_P22174'] ?? '') === '0',
    'GHP63x discovery controls must use their documented 1=enabled polarity'
);

$cisco = ['vlan_mode'=>'discovery','vlan_discovery'=>'cdp,lldp','vlan_pc_port'=>'disabled'];
VlanPolicy::apply('cisco_xml', ['model'=>'8845','role'=>'desk'], $cisco);
$expect(($cisco['cisco_enable_cdp_sw_port'] ?? '') === '1' && ($cisco['cisco_pc_port'] ?? '') === '1', 'Cisco discovery/PC-port policy mismatch');

$grandstream = ['vlan_mode'=>'manual','vlan_voice_vid'=>'120','vlan_voice_pcp'=>'5','vlan_pc_port'=>'untagged'];
VlanPolicy::apply('grandstream', ['model'=>'GRP2614','role'=>'desk','config_profile'=>'grandstream_grp'], $grandstream);
$expect(($grandstream['gs_P51'] ?? '') === '120' && ($grandstream['gs_P229'] ?? '') === '0', 'Grandstream VLAN P-values mismatch');

foreach ([
    ['cisco_xml',['model'=>'8845','role'=>'desk'],['vlan_mode'=>'manual','vlan_voice_vid'=>'100']],
    ['cisco_cnf',['model'=>'7960','role'=>'desk'],['vlan_mode'=>'manual','vlan_voice_vid'=>'100']],
    ['yealink',['model'=>'T48U','role'=>'desk'],['vlan_mode'=>'manual','vlan_voice_vid'=>'4095']],
] as [$family,$model,$invalid]) {
    try {
        VlanPolicy::apply($family, $model, $invalid);
        $expect(false, "Unsupported VLAN policy was accepted for $family");
    } catch (\InvalidArgumentException|\RuntimeException $e) {
    }
}

$inherit = ['vlan_mode'=>'inherit'];
$before = $inherit;
VlanPolicy::apply('cisco_cnf', ['model'=>'7960','role'=>'desk'], $inherit);
$expect($inherit === $before, 'Inherited VLAN policy must not emit vendor settings');

echo "VLAN policy assertions passed\n";
