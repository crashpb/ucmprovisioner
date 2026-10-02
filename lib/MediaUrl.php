<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Query-free phone media routes and legacy Cisco BMP validation.
 *
 * Cisco's older Allegro HTTP client corrupts ampersands in query strings.
 * Keeping the public route in PATH_INFO also makes the generated phone URL
 * independent of PHP's query parser.
 */
final class MediaUrl
{
    private const FAMILIES = ['cisco_cnf', 'cisco_xml', 'yealink', 'grandstream'];

    public static function normalizeBase(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Phone-facing base URL must be a valid HTTP or HTTPS URL');
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('Phone-facing base URL must contain only scheme, host, optional port, and base path');
        }
        $path = rawurldecode((string)($parts['path'] ?? ''));
        if (str_contains($path, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', str_replace('\\', '/', $path))) {
            throw new \InvalidArgumentException('Phone-facing base URL contains an unsafe path');
        }
        return rtrim($baseUrl, '/');
    }

    public static function endpoint(string $baseUrl, string $family, string $model = 'all'): string
    {
        self::assertFamily($family);
        self::assertModel($model);
        return self::normalizeBase($baseUrl)
            . '/ucmp-m.php/' . rawurlencode($family)
            . '/' . rawurlencode($model) . '/';
    }

    /** Phone-facing asset URL. P0S3 receives no query string. */
    public static function asset(
        string $baseUrl,
        string $family,
        string $model,
        string $file,
        string $version = ''
    ): string {
        self::assertFile($file);
        $url = self::endpoint($baseUrl, $family, $model) . rawurlencode($file);
        if ($family !== 'cisco_cnf' && $version !== '') {
            $url .= '?v=' . rawurlencode($version);
        }
        return $url;
    }

    /** Browser-only cache-busted preview; never persist this as a CNF logo_url. */
    public static function preview(
        string $baseUrl,
        string $family,
        string $model,
        string $file,
        string $version
    ): string {
        self::assertFile($file);
        return self::endpoint($baseUrl, $family, $model) . rawurlencode($file)
            . ($version !== '' ? '?v=' . rawurlencode($version) : '');
    }

    /**
     * Repair only URLs emitted by the module's former CNF media API. Arbitrary
     * external query-bearing URLs remain untouched and are rejected by the
     * generator, preserving the direct-P0S3 safety check.
     */
    public static function repairLegacyCiscoAssetUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'http'
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || !isset($parts['query'])
            || !preg_match('#/ucmp-m\.php/cisco_cnf/[A-Za-z0-9_.%~-]+/[A-Za-z0-9_.%~-]+\.bmp$#iD', (string)($parts['path'] ?? ''))
        ) {
            return $url;
        }
        parse_str((string)$parts['query'], $query);
        if (count($query) !== 1 || !isset($query['v']) || !is_scalar($query['v']) || trim((string)$query['v']) === '') {
            return $url;
        }
        $queryAt = strpos($url, '?');
        return $queryAt === false ? $url : substr($url, 0, $queryAt);
    }

    /**
     * @return array{family:string,model:string,file:string}|null
     */
    public static function parsePathInfo(?string $pathInfo): ?array
    {
        if ($pathInfo === null || trim($pathInfo, '/') === '') {
            return null;
        }
        $raw = explode('/', trim($pathInfo, '/'));
        if (count($raw) !== 3 || in_array('', $raw, true)) {
            throw new \InvalidArgumentException('Media path must contain family, model, and filename');
        }
        $parts = array_map('rawurldecode', $raw);
        [$family, $model, $file] = $parts;
        self::assertFamily($family);
        self::assertModel($model);
        self::assertFile($file);
        return ['family'=>$family, 'model'=>$model, 'file'=>$file];
    }

    /**
     * Validate the exact 90x56, 8-bit, uncompressed Windows BMP accepted by
     * Cisco 7940/7960 SIP firmware.
     */
    public static function assertLegacyCiscoBmp(string $data): void
    {
        if (strlen($data) < 54 || substr($data, 0, 2) !== 'BM') {
            throw new \InvalidArgumentException('Cisco legacy logo must be a Windows BMP');
        }
        $width = self::uint32($data, 18);
        $height = self::uint32($data, 22);
        $fileSize = self::uint32($data, 2);
        $dibSize = self::uint32($data, 14);
        $planes = self::uint16($data, 26);
        $bits = self::uint16($data, 28);
        $compression = self::uint32($data, 30);
        $pixelOffset = self::uint32($data, 10);
        if ($width !== 90 || $height !== 56) {
            throw new \InvalidArgumentException('Cisco legacy logo must be exactly 90x56 pixels');
        }
        if ($planes !== 1 || $bits !== 8 || $compression !== 0) {
            throw new \InvalidArgumentException('Cisco legacy logo must be an uncompressed 8-bit Windows BMP');
        }
        $paletteEnd = 14 + max(40, $dibSize) + (256 * 4);
        $rowStride = intdiv(($width * $bits) + 31, 32) * 4;
        $requiredSize = $pixelOffset + ($rowStride * $height);
        if ($dibSize < 40 || $pixelOffset < $paletteEnd || $requiredSize !== strlen($data) || $fileSize !== strlen($data)) {
            throw new \InvalidArgumentException('Cisco legacy logo must contain a complete 256-colour palette and 90x56 pixel payload');
        }
    }

    private static function assertFamily(string $family): void
    {
        if (!in_array($family, self::FAMILIES, true)) {
            throw new \InvalidArgumentException('Choose a supported phone family');
        }
    }

    private static function assertModel(string $model): void
    {
        if ($model === '' || in_array($model, ['.','..'], true) || strlen($model) > 128 || !preg_match('/\A[A-Za-z0-9_.-]+\z/D', $model)) {
            throw new \InvalidArgumentException('Media model contains invalid characters');
        }
    }

    private static function assertFile(string $file): void
    {
        if ($file === '' || in_array($file, ['.','..'], true) || strlen($file) > 255 || basename($file) !== $file || !preg_match('/\A[A-Za-z0-9_.-]+\z/D', $file)) {
            throw new \InvalidArgumentException('Media filename contains invalid characters');
        }
    }

    private static function uint16(string $data, int $offset): int
    {
        return (int)(unpack('vvalue', substr($data, $offset, 2))['value'] ?? -1);
    }

    private static function uint32(string $data, int $offset): int
    {
        return (int)(unpack('Vvalue', substr($data, $offset, 4))['value'] ?? -1);
    }
}
