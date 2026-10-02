<?php

namespace FreePBX\modules\Ucmprovisioner;

/** Resolve the one endpoint tuple shared by FreePBX, generated configs and UI. */
final class EndpointProfile
{
    public const DRIVERS = ['sip', 'pjsip'];
    public const TRANSPORTS = ['udp', 'tcp', 'tls'];

    /** @return array{driver:string,transport:string,port:int,driver_mode:string,transport_mode:string,port_mode:int,driver_source:string,transport_source:string,port_source:string,is_cisco:bool,is_cisco_cnf:bool,transport_locked:bool} */
    public static function resolve(array $phone, array $model, array $settings): array
    {
        $vendor = strtolower(trim((string)($model['vendor'] ?? $phone['m_vendor'] ?? $phone['vendor'] ?? '')));
        $family = strtolower(trim((string)($model['family'] ?? $phone['m_family'] ?? $phone['family'] ?? '')));
        $isCisco = $vendor === 'cisco' || str_starts_with($family, 'cisco_');
        $isCiscoCnf = $family === 'cisco_cnf';

        if ($isCiscoCnf) {
            // Cisco's 7940/7960 SIP firmware supports SIP signaling over UDP
            // only. It has no TCP/TLS configuration directive, so allowing the
            // wider Cisco default to reach chan_sip creates an impossible
            // TCP-only peer while the handset continues to REGISTER via UDP.
            $defaultDriver = 'sip';
            $defaultTransport = 'udp';
            $defaultPort = self::port(
                $settings['cisco_cnf_sip_port'] ?? $settings['cisco_sip_port'] ?? $settings['sip_port'] ?? 5060,
                5060
            );
        } elseif ($isCisco) {
            $defaultDriver = 'sip';
            $defaultTransport = self::transport($settings['cisco_transport'] ?? $settings['transport'] ?? 'udp');
            $defaultPort = self::port($settings['cisco_sip_port'] ?? $settings['sip_port'] ?? 5060, 5060);
        } else {
            $defaultDriver = self::driver($settings['non_cisco_driver'] ?? 'pjsip');
            $defaultTransport = self::transport($settings['non_cisco_transport'] ?? 'udp');
            $defaultPort = self::port(
                $settings['non_cisco_sip_port'] ?? ($defaultDriver === 'pjsip' ? 5160 : 5060),
                $defaultDriver === 'pjsip' ? 5160 : 5060
            );
        }

        $driverMode = self::mode((string)($phone['driver'] ?? 'inherit'), self::DRIVERS);
        $transportMode = self::mode((string)($phone['transport'] ?? 'inherit'), self::TRANSPORTS);
        $portMode = (int)($phone['sip_port'] ?? 0);
        if ($portMode < 1 || $portMode > 65535) { $portMode = 0; }

        $driver = $isCisco || $driverMode === 'inherit' ? $defaultDriver : $driverMode;
        $transport = $isCiscoCnf
            ? 'udp'
            : ($transportMode === 'inherit' ? $defaultTransport : $transportMode);
        $port = $portMode > 0 ? $portMode : $defaultPort;

        return [
            'driver'=>$driver,
            'transport'=>$transport,
            'port'=>$port,
            'driver_mode'=>$isCisco ? 'inherit' : $driverMode,
            'transport_mode'=>$isCiscoCnf ? 'inherit' : $transportMode,
            'port_mode'=>$portMode,
            'driver_source'=>$isCisco ? 'Cisco UCM requirement' : ($driverMode === 'inherit' ? 'Non-Cisco site default' : 'Phone override'),
            'transport_source'=>$isCiscoCnf
                ? 'Cisco 7940/7960 SIP firmware (UDP only)'
                : ($transportMode === 'inherit' ? ($isCisco ? 'Cisco XML site default' : 'Non-Cisco site default') : 'Phone override'),
            'port_source'=>$portMode > 0 ? 'Phone override' : ($isCiscoCnf ? 'Cisco CNF site default' : ($isCisco ? 'Cisco XML site default' : 'Non-Cisco site default')),
            'is_cisco'=>$isCisco,
            'is_cisco_cnf'=>$isCiscoCnf,
            'transport_locked'=>$isCiscoCnf,
        ];
    }

    public static function driverLabel(string $driver): string
    {
        return strtolower($driver) === 'pjsip' ? 'PJSIP' : 'CHAN_SIP';
    }

    private static function driver($value): string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, self::DRIVERS, true) ? $value : 'pjsip';
    }

    private static function transport($value): string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, self::TRANSPORTS, true) ? $value : 'udp';
    }

    private static function port($value, int $fallback): int
    {
        $port = (int)$value;
        return $port >= 1 && $port <= 65535 ? $port : $fallback;
    }

    private static function mode(string $value, array $allowed): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : 'inherit';
    }
}
