<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/VlanPolicy.php';
require_once dirname(__DIR__) . '/lib/CiscoXmlGenerator.php';
require_once dirname(__DIR__) . '/lib/YealinkGenerator.php';
require_once dirname(__DIR__) . '/lib/GrandstreamGenerator.php';

use FreePBX\modules\Ucmprovisioner\CiscoXmlGenerator;
use FreePBX\modules\Ucmprovisioner\GrandstreamGenerator;
use FreePBX\modules\Ucmprovisioner\VlanPolicy;
use FreePBX\modules\Ucmprovisioner\YealinkGenerator;

$expect = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, $message . "\nMissing: $needle\n");
        exit(1);
    }
};
$base = [
    'phone'=>['mac'=>'001122334455','extension'=>'4019','label'=>'H.Haghani'],
    'settings'=>['pbx_addr'=>'10.0.0.10','sip_port'=>'5060','transport'=>'udp'],
    'lines'=>[['button'=>1,'featureId'=>9,'lineIndex'=>1,'accountIndex'=>1,'label'=>'H.Haghani','name'=>'4019','authName'=>'4019','secret'=>'secret']],
];

$cisco = $base;
$cisco['model'] = ['model'=>'8845','role'=>'desk','supports_softkeys'=>1];
$cisco['settings'] += ['vlan_mode'=>'discovery','vlan_discovery'=>'cdp,lldp','vlan_pc_port'=>'disabled'];
VlanPolicy::apply('cisco_xml', $cisco['model'], $cisco['settings']);
$ciscoXml = (new CiscoXmlGenerator())->generate($cisco)['xml'];
$expect('<enableCdpSwPort>1</enableCdpSwPort>', $ciscoXml, 'Cisco CDP control was not emitted');
$expect('<enableLldpSwPort>1</enableLldpSwPort>', $ciscoXml, 'Cisco LLDP control was not emitted');
$expect('<pcPort>1</pcPort>', $ciscoXml, 'Cisco PC-port disable control was not emitted');

$yealink = $base;
$yealink['model'] = ['model'=>'T48U','role'=>'desk','config_profile'=>'yealink_desk','sip_accounts'=>16,'programmable_keys'=>29];
$yealink['settings'] += ['vlan_mode'=>'manual','vlan_voice_vid'=>'120','vlan_voice_pcp'=>'5'];
VlanPolicy::apply('yealink', $yealink['model'], $yealink['settings']);
$yealinkCfg = (new YealinkGenerator())->generate($yealink)['content'];
$expect('static.network.vlan.internet_port_vid = 120', $yealinkCfg, 'Yealink manual VID was not emitted');
$expect('static.network.vlan.internet_port_priority = 5', $yealinkCfg, 'Yealink manual PCP was not emitted');

$grandstream = $base;
$grandstream['model'] = ['model'=>'GRP2614','role'=>'desk','config_profile'=>'grandstream_grp','sip_accounts'=>4,'programmable_keys'=>4];
$grandstream['settings'] += ['vlan_mode'=>'manual','vlan_voice_vid'=>'120','vlan_voice_pcp'=>'5'];
VlanPolicy::apply('grandstream', $grandstream['model'], $grandstream['settings']);
$grandstreamXml = (new GrandstreamGenerator())->generate($grandstream)['content'];
$expect('<P51>120</P51>', $grandstreamXml, 'Grandstream manual VID was not emitted');
$expect('<P87>5</P87>', $grandstreamXml, 'Grandstream manual PCP was not emitted');

$dp755 = $base;
$dp755['model'] = ['model'=>'DP755','role'=>'dect_base','config_profile'=>'grandstream_dp755_alias_v2','sip_accounts'=>20,'handsets_max'=>10];
$dp755['settings'] += ['vlan_mode'=>'manual','vlan_voice_vid'=>'220','vlan_voice_pcp'=>'6','vlan_pc_port'=>'inherit'];
VlanPolicy::apply('grandstream', $dp755['model'], $dp755['settings']);
$dp755Xml = (new GrandstreamGenerator())->generate($dp755)['content'];
$expect('<item name="network.port.eth.1.vlan.enable">Yes</item>', $dp755Xml, 'DP755 alias VLAN enable was not emitted');
$expect('<item name="network.port.eth.1.vlan.tag">220</item>', $dp755Xml, 'DP755 alias VLAN tag was not emitted');
if (str_contains($dp755Xml, '<P51>')) {
    fwrite(STDERR, "DP755 alias-v2 config must never mix P-value VLAN elements\n");
    exit(1);
}

$dp750 = $base;
$dp750['model'] = ['model'=>'DP750','role'=>'dect_base','config_profile'=>'grandstream_dp75x_pvalue','sip_accounts'=>10,'handsets_max'=>5];
$dp750['settings'] += ['vlan_mode'=>'manual','vlan_voice_vid'=>'221','vlan_voice_pcp'=>'4','vlan_pc_port'=>'inherit'];
VlanPolicy::apply('grandstream', $dp750['model'], $dp750['settings']);
$dp750Xml = (new GrandstreamGenerator())->generate($dp750)['content'];
$expect('<P27004>1</P27004>', $dp750Xml, 'DP750 VLAN enable was not emitted');
$expect('<P5038>4</P5038>', $dp750Xml, 'DP750 SIP priority was not emitted');
$expect('<P5042>4</P5042>', $dp750Xml, 'DP750 RTP priority was not emitted');

$ghp = $base;
$ghp['model'] = ['model'=>'GHP630','role'=>'desk','config_profile'=>'grandstream_ghp','sip_accounts'=>2,'programmable_keys'=>16];
$ghp['settings'] += ['vlan_mode'=>'manual','vlan_voice_vid'=>'222','vlan_voice_pcp'=>'5','vlan_pc_port'=>'inherit'];
VlanPolicy::apply('grandstream', $ghp['model'], $ghp['settings']);
$ghpXml = (new GrandstreamGenerator())->generate($ghp)['content'];
$expect('<P51>222</P51>', $ghpXml, 'GHP63x manual VID was not emitted');
$expect('<P87>5</P87>', $ghpXml, 'GHP63x manual 802.1p priority was not emitted');
$expect('<P22174>1</P22174>', $ghpXml, 'GHP63x manual VLAN mode was not enabled');

echo "Cross-vendor VLAN generator assertions passed\n";
