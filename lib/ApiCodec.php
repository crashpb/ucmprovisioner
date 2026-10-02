<?php
/** Encrypted request/response envelopes for the site-to-site HTTP API. */
namespace FreePBX\modules\Ucmprovisioner;

final class ApiCodec
{
    private const VERSION = 1;
    private const AAD = 'ucmprovisioner-api-v1';
    public const MAX_PLAINTEXT_BYTES = 2097152;
    // Base64 expands the encrypted body; the wire envelope has its own bound.
    public const MAX_ENVELOPE_BYTES = 3145728;

    public static function seal(array $payload, string $secret): string
    {
        self::validateSecret($secret);
        $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($plain) > self::MAX_PLAINTEXT_BYTES) { throw new \InvalidArgumentException('API payload is too large'); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if ($cipher === false || strlen($tag) !== 16) { throw new \RuntimeException('API envelope encryption failed'); }
        return (string)json_encode([
            'v'=>self::VERSION,
            'iv'=>base64_encode($iv),
            'tag'=>base64_encode($tag),
            'data'=>base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function open(string $envelope, string $secret): array
    {
        self::validateSecret($secret);
        if ($envelope === '' || strlen($envelope) > self::MAX_ENVELOPE_BYTES) { throw new \InvalidArgumentException('Invalid API envelope size'); }
        $outer = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($outer) || (int)($outer['v'] ?? 0) !== self::VERSION) { throw new \InvalidArgumentException('Unsupported API envelope version'); }
        $iv = base64_decode((string)($outer['iv'] ?? ''), true);
        $tag = base64_decode((string)($outer['tag'] ?? ''), true);
        $cipher = base64_decode((string)($outer['data'] ?? ''), true);
        if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $cipher === false) {
            throw new \InvalidArgumentException('Malformed API envelope');
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if ($plain === false) { throw new \RuntimeException('API envelope authentication failed'); }
        if (strlen($plain) > self::MAX_PLAINTEXT_BYTES) { throw new \InvalidArgumentException('API payload is too large'); }
        $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new \InvalidArgumentException('Invalid API payload'); }
        return $payload;
    }

    private static function validateSecret(string $secret): void
    {
        if (!preg_match('/^[A-Fa-f0-9]{64,128}$/', $secret)) { throw new \InvalidArgumentException('Invalid API shared secret'); }
    }
}
