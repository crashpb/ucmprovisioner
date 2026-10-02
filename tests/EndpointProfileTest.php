<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/EndpointProfile.php';

use FreePBX\modules\Ucmprovisioner\EndpointProfile;

$settings = [
    'cisco_transport'=>'tcp', 'cisco_sip_port'=>'5062',
    'cisco_cnf_transport'=>'udp', 'cisco_cnf_sip_port'=>'5070',
    'non_cisco_driver'=>'pjsip', 'non_cisco_transport'=>'udp', 'non_cisco_sip_port'=>'5160',
];

$cisco = EndpointProfile::resolve(
    ['driver'=>'pjsip','transport'=>'inherit','sip_port'=>0],
    ['vendor'=>'Cisco','family'=>'cisco_xml'],
    $settings
);
if ($cisco['driver'] !== 'sip' || $cisco['transport'] !== 'tcp' || $cisco['port'] !== 5062
    || $cisco['driver_mode'] !== 'inherit' || !$cisco['is_cisco']) {
    throw new RuntimeException('Cisco must stay on CHAN_SIP while inheriting only Cisco transport/port defaults');
}

$ciscoCnf = EndpointProfile::resolve(
    ['driver'=>'sip','transport'=>'tcp','sip_port'=>0],
    ['vendor'=>'Cisco','family'=>'cisco_cnf'],
    $settings
);
if ($ciscoCnf['driver'] !== 'sip' || $ciscoCnf['transport'] !== 'udp' || $ciscoCnf['port'] !== 5070
    || $ciscoCnf['transport_mode'] !== 'inherit' || !$ciscoCnf['is_cisco_cnf'] || !$ciscoCnf['transport_locked']
    || $ciscoCnf['port_source'] !== 'Cisco CNF site default') {
    throw new RuntimeException('Cisco 7940/7960 CNF must use its separate UDP-only site default even if a stale phone override says TCP');
}

$yealink = EndpointProfile::resolve(
    ['driver'=>'inherit','transport'=>'inherit','sip_port'=>0],
    ['vendor'=>'Yealink','family'=>'yealink'],
    $settings
);
if ($yealink['driver'] !== 'pjsip' || $yealink['transport'] !== 'udp' || $yealink['port'] !== 5160
    || $yealink['driver_source'] !== 'Non-Cisco site default') {
    throw new RuntimeException('A non-Cisco phone must inherit its independent PJSIP endpoint tuple');
}

$override = EndpointProfile::resolve(
    ['driver'=>'sip','transport'=>'tls','sip_port'=>5061],
    ['vendor'=>'Grandstream','family'=>'grandstream'],
    $settings
);
if ($override['driver'] !== 'sip' || $override['transport'] !== 'tls' || $override['port'] !== 5061
    || $override['driver_source'] !== 'Phone override' || $override['port_source'] !== 'Phone override') {
    throw new RuntimeException('A non-Cisco per-phone endpoint override must win as one visible tuple');
}

echo "Endpoint vendor defaults and per-phone precedence assertions passed\n";
