<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Match the existing generators' credential limits before files and Core diverge. */
final class SipSecretPolicy
{
    public static function needsAlignment(string $secret, string $family): bool
    {
        $limit = match ($family) { 'cisco_cnf'=>16, 'cisco_xml'=>30, default=>256 };
        return $secret === '' || strlen($secret) > $limit || preg_match('/[\x00-\x1F\x7F]/', $secret)
            || ($family === 'cisco_cnf' && preg_match('/[^\x20-\x7E]|"/', $secret));
    }

    public static function aligned(string $extension, string $secret, string $privateKey): string
    {
        if (strlen($privateKey) < 32) { throw new \RuntimeException('A valid local credential key is required for SIP password alignment'); }
        // Stable between preview and apply, without saving a password during a
        // dry run. The per-install secret key prevents guessing the replacement.
        return substr(strtr(base64_encode(hash_hmac('sha256',"ucmp-sip-alignment-v1\0".$extension."\0".$secret,$privateKey,true)),'+/','AZ'),0,16);
    }
}
