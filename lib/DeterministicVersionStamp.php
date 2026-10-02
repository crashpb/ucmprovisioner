<?php
namespace FreePBX\modules\Ucmprovisioner;

/** Produces a stable UUID-shaped stamp from generated configuration content. */
final class DeterministicVersionStamp
{
    public static function fromContent(string $content): string
    {
        $hex = substr(hash('sha256', $content), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
