<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/AssetFilename.php';

/**
 * Dependency-free inspection for bytes already staged by a manager.
 *
 * It validates magic before trusting a filename and extracts only metadata
 * that PHP's standard library can prove. Firmware authenticity deliberately
 * remains unverified unless a separate vendor-signature verifier says so.
 */
final class AssetFileInspector
{
    private const PREFIX_BYTES = 1048576;
    private const MAX_BYTES = 536870912;

    /** @return array<string,mixed> */
    public static function inspect(string $path, string $filename, ?string $kind = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Asset inspection requires a readable regular file');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 0 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Asset inspection size is outside the supported bound');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \RuntimeException('Could not hash staged asset');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open staged asset');
        }
        try {
            $prefix = (string)fread($handle, min(self::PREFIX_BYTES, max(1, $size)));
        } finally {
            fclose($handle);
        }

        $filename = basename(str_replace('\\', '/', trim($filename)));
        $contentType = self::contentType($prefix);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'jar' && $contentType === 'application/zip') {
            $contentType = 'application/java-archive';
        }
        $metadata = ['magic'=>self::magicLabel($contentType)];
        $errors = self::extensionErrors($filename, $contentType);
        $version = self::parsedVersion($filename, $prefix);
        $normalizedKind = AssetCatalog::normalizeKind((string)$kind);
        $timezonePayload = AssetFilename::timezonePayload($filename) ?? $filename;
        if ($normalizedKind === 'locale' && in_array(strtolower($timezonePayload), ['tzupdater.jar','j9-tzdata.jar','tzdatacsv.csv'], true)) {
            require_once __DIR__ . '/CiscoTimezoneService.php';
            $runtime = CiscoTimezoneService::runtimeValidation($timezonePayload, (string)file_get_contents($path));
            $metadata['container_valid'] = $runtime['valid'];
            if (!$runtime['valid']) { $errors[] = $runtime['message']; }
        }
        if ($normalizedKind === 'locale' && $version === null && $extension === 'jar') {
            $version = self::archiveTzdataVersion($path);
        }
        $rawCiscoAudio = false;
        if ($normalizedKind === 'ringtone' && in_array($extension, ['raw', 'rwb'], true)) {
            $rawCiscoAudio = true;
            if ($extension === 'rwb' && $size % 2 !== 0) {
                $errors[] = 'Wideband signed-16-bit ringtone has an odd byte count';
            }
            $sampleRate = $extension === 'rwb' ? 16000 : 8000;
            $bytesPerSample = $extension === 'rwb' ? 2 : 1;
            $contentType = $extension === 'rwb' ? 'audio/x-cisco-rwb' : 'audio/basic';
            $metadata = array_merge($metadata, [
                'magic'=>$extension === 'rwb' ? 'headerless signed 16-bit PCM' : 'headerless G.711 mu-law',
                'container'=>'raw',
                'audio_codec'=>$extension === 'rwb' ? 'pcm-s16' : 'mu-law',
                'sample_rate'=>$sampleRate,
                'channel_count'=>1,
                'bits'=>$bytesPerSample * 8,
                'duration_ms'=>(int)round(($size / ($sampleRate * $bytesPerSample)) * 1000),
            ]);
        }

        if (str_starts_with($contentType, 'image/')) {
            $image = @getimagesize($path);
            if (!is_array($image) || empty($image[0]) || empty($image[1])) {
                $errors[] = 'Image bytes could not be decoded';
            } else {
                $metadata += [
                    'width'=>(int)$image[0],
                    'height'=>(int)$image[1],
                    'bits'=>(int)($image['bits'] ?? 0),
                    'channels'=>(int)($image['channels'] ?? 0),
                    'format'=>strtolower((string)image_type_to_extension((int)$image[2], false)),
                ];
                $metadata += self::imageDetails($prefix, $contentType, $path, $size);
            }
        } elseif ($contentType === 'audio/wav') {
            $wav = self::wav($prefix, $size);
            if ($wav === null) {
                $errors[] = 'WAV header is incomplete or unsupported';
            } else {
                $metadata += $wav;
            }
        } elseif ($contentType === 'audio/mpeg') {
            $audio = self::mp3($prefix, $size);
            if ($audio === null) { $errors[] = 'MP3 frame header is incomplete or unsupported'; }
            else { $metadata += $audio; }
        } elseif ($contentType === 'audio/ogg') {
            $audio = self::ogg($path, $prefix, $size);
            if ($audio === null) { $errors[] = 'Ogg codec/rate metadata is incomplete or unsupported'; }
            else { $metadata += $audio; }
        }

        if ($normalizedKind === 'locale' && $version !== null) {
            $metadata['declared_tzdata_version'] = $version;
        }
        $verifiedByContent = !$errors && (
            str_starts_with($contentType, 'image/')
            || $contentType === 'audio/wav'
            || ($contentType === 'audio/mpeg' && isset($metadata['sample_rate'], $metadata['channel_count']))
            || ($contentType === 'audio/ogg' && isset($metadata['sample_rate'], $metadata['channel_count']))
            || $rawCiscoAudio
            || ($normalizedKind === 'locale' && $version !== null)
            || $normalizedKind === 'phonebook'
        );

        return [
            'content_sha256'=>$hash,
            'size_bytes'=>$size,
            'content_type'=>$contentType,
            'parsed_version'=>$version,
            'metadata'=>array_filter($metadata, static fn($value): bool => $value !== '' && $value !== 0),
            'validation_status'=>$errors ? 'invalid' : ($verifiedByContent ? 'verified' : 'discovered'),
            'signature_status'=>$normalizedKind === 'firmware' ? 'unverified' : 'not_applicable',
            'errors'=>$errors,
        ];
    }

    private static function contentType(string $prefix): string
    {
        if (str_starts_with($prefix, "\x89PNG\r\n\x1a\n")) { return 'image/png'; }
        if (str_starts_with($prefix, "\xff\xd8\xff")) { return 'image/jpeg'; }
        if (str_starts_with($prefix, 'GIF87a') || str_starts_with($prefix, 'GIF89a')) { return 'image/gif'; }
        if (str_starts_with($prefix, 'BM')) { return 'image/bmp'; }
        if (substr($prefix, 0, 4) === 'RIFF' && substr($prefix, 8, 4) === 'WAVE') { return 'audio/wav'; }
        if (str_starts_with($prefix, 'OggS')) { return 'audio/ogg'; }
        if (str_starts_with($prefix, 'ID3') || self::looksLikeMp3Frame($prefix)) { return 'audio/mpeg'; }
        if (str_starts_with($prefix, "PK\x03\x04")) { return 'application/zip'; }
        $text = ltrim(substr($prefix, 0, 512));
        if ($text !== '' && str_starts_with($text, '<')) { return 'application/xml'; }
        if ($text !== '' && preg_match('//u', $text) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) !== 1) {
            return 'text/plain';
        }
        return 'application/octet-stream';
    }

    /** @return string[] */
    private static function extensionErrors(string $filename, string $contentType): array
    {
        $expected = [
            'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg',
            'gif'=>'image/gif', 'bmp'=>'image/bmp', 'wav'=>'audio/wav',
            'mp3'=>'audio/mpeg', 'ogg'=>'audio/ogg',
        ];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (isset($expected[$extension]) && $expected[$extension] !== $contentType) {
            return [sprintf(
                '%s extension does not match decoded %s content',
                strtoupper($extension),
                $contentType
            )];
        }
        return [];
    }

    /** @return array<string,mixed> */
    private static function imageDetails(string $prefix, string $contentType, string $path, int $size): array
    {
        if ($contentType === 'image/png' && strlen($prefix) >= 26) {
            $colourType = ord($prefix[25]);
            return ['alpha'=>in_array($colourType, [4, 6], true), 'animated'=>str_contains($prefix, 'acTL')];
        }
        if ($contentType === 'image/gif') {
            $data = $size <= 16777216 ? (string)file_get_contents($path) : $prefix;
            return ['alpha'=>str_contains($data, "\x21\xF9\x04"), 'animated'=>substr_count($data, "\x2C") > 1];
        }
        if ($contentType === 'image/bmp' && strlen($prefix) >= 34) {
            $bits = unpack('v', substr($prefix, 28, 2))[1] ?? 0;
            $compression = unpack('V', substr($prefix, 30, 4))[1] ?? 0;
            return [
                'bits'=>(int)$bits,
                'alpha'=>(int)$bits === 32,
                'animated'=>false,
                'compression'=>$compression === 0 ? 'BI_RGB' : 'compressed',
            ];
        }
        return ['alpha'=>false, 'animated'=>false];
    }

    /** @return array<string,mixed>|null */
    private static function wav(string $prefix, int $fileSize): ?array
    {
        $end = (int)(unpack('V',substr($prefix,4,4))[1] ?? 0) + 8;
        if ($end < 44 || $end > $fileSize) { return null; }
        $fmt = null; $declaredData = null;
        for ($offset=12;$offset+8 <= min($end,strlen($prefix));) {
            $type=substr($prefix,$offset,4);
            $length=(int)(unpack('V',substr($prefix,$offset+4,4))[1] ?? 0);
            $next=$offset+8+$length+($length%2);
            if ($next > $end) { return null; }
            if ($type==='fmt ') {
                if ($fmt!==null || $length<16 || $offset+24>strlen($prefix)) { return null; }
                $fmt=$offset;
            } elseif ($type==='data') {
                if ($fmt===null || $length<1) { return null; }
                $declaredData=$length;break;
            }
            $offset=$next;
        }
        if ($fmt===null || $declaredData===null) { return null; }
        $audioFormat = unpack('v', substr($prefix, $fmt + 8, 2))[1] ?? 0;
        $channels = unpack('v', substr($prefix, $fmt + 10, 2))[1] ?? 0;
        $sampleRate = unpack('V', substr($prefix, $fmt + 12, 4))[1] ?? 0;
        $byteRate = unpack('V', substr($prefix, $fmt + 16, 4))[1] ?? 0;
        $alignment = unpack('v', substr($prefix, $fmt + 20, 2))[1] ?? 0;
        $bits = unpack('v', substr($prefix, $fmt + 22, 2))[1] ?? 0;
        if ($channels < 1 || $sampleRate < 1 || $byteRate < 1 || $bits < 1 || $alignment < 1) { return null; }
        if (in_array($audioFormat,[1,6,7],true) && ($bits%8!==0 || $alignment!==$channels*intdiv($bits,8)
            || $byteRate!==$sampleRate*$alignment || $declaredData%$alignment!==0)) { return null; }
        $codec = [1=>'pcm', 6=>'a-law', 7=>'mu-law'][$audioFormat] ?? 'format-' . $audioFormat;
        return [
            'container'=>'wav',
            'audio_codec'=>$codec,
            'sample_rate'=>(int)$sampleRate,
            'channel_count'=>(int)$channels,
            'bits'=>(int)$bits,
            'duration_ms'=>(int)round(($declaredData / (int)$byteRate) * 1000),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function mp3(string $prefix, int $fileSize): ?array
    {
        $offset = 0;
        if (str_starts_with($prefix, 'ID3') && strlen($prefix) >= 10) {
            $offset = 10
                + ((ord($prefix[6]) & 0x7f) << 21)
                + ((ord($prefix[7]) & 0x7f) << 14)
                + ((ord($prefix[8]) & 0x7f) << 7)
                + (ord($prefix[9]) & 0x7f);
        }
        $limit = min(strlen($prefix) - 4, $offset + 65536);
        for ($position = $offset; $position <= $limit; $position++) {
            $header = unpack('N', substr($prefix, $position, 4))[1] ?? 0;
            if (($header & 0xffe00000) !== 0xffe00000) { continue; }
            $versionBits = ($header >> 19) & 0x3;
            $layerBits = ($header >> 17) & 0x3;
            $bitrateIndex = ($header >> 12) & 0xf;
            $sampleIndex = ($header >> 10) & 0x3;
            if ($versionBits === 1 || $layerBits !== 1 || $bitrateIndex < 1
                || $bitrateIndex > 14 || $sampleIndex > 2) {
                continue;
            }
            $mpeg1 = $versionBits === 3;
            $bitrates = $mpeg1
                ? [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320]
                : [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160];
            $sampleRates = [44100, 48000, 32000];
            $divisor = $mpeg1 ? 1 : ($versionBits === 2 ? 2 : 4);
            $bitrate = $bitrates[$bitrateIndex] * 1000;
            $sampleRate = intdiv($sampleRates[$sampleIndex], $divisor);
            $channels = (($header >> 6) & 0x3) === 3 ? 1 : 2;
            return [
                'container'=>'mp3',
                'audio_codec'=>'mp3',
                'sample_rate'=>$sampleRate,
                'channel_count'=>$channels,
                'duration_ms'=>(int)round((max(0, $fileSize - $offset) * 8 / $bitrate) * 1000),
            ];
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private static function ogg(string $path, string $prefix, int $fileSize): ?array
    {
        $codec = null;
        $channels = 0;
        $sampleRate = 0;
        $vorbis = strpos($prefix, "\x01vorbis");
        if ($vorbis !== false && strlen($prefix) >= $vorbis + 30) {
            // Xiph Vorbis I 4.2.2: version precedes channels/rate; both block
            // sizes and the final framing bit are mandatory identification data.
            $version = (int)(unpack('V', substr($prefix, $vorbis + 7, 4))[1] ?? -1);
            $blocks = ord($prefix[$vorbis + 28]);
            $small = $blocks & 15; $large = $blocks >> 4;
            if ($version !== 0 || $small < 6 || $large > 13 || $small > $large || !(ord($prefix[$vorbis + 29]) & 1)) { return null; }
            $codec = 'vorbis';
            $channels = ord($prefix[$vorbis + 11]);
            $sampleRate = (int)(unpack('V', substr($prefix, $vorbis + 12, 4))[1] ?? 0);
        } else {
            $opus = strpos($prefix, 'OpusHead');
            if ($opus !== false && strlen($prefix) >= $opus + 19 && ord($prefix[$opus + 8]) <= 15) {
                $codec = 'opus';
                $channels = ord($prefix[$opus + 9]);
                $sampleRate = 48000;
            }
        }
        if ($codec === null || $channels < 1 || $sampleRate < 1) { return null; }
        $duration = 0;
        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            try {
                $tailBytes = min(131072, $fileSize);
                if (fseek($handle, -$tailBytes, SEEK_END) === 0) {
                    $tail = (string)fread($handle, $tailBytes);
                    $offset = 0;
                    $maximumGranule = 0.0;
                    while (($page = strpos($tail, 'OggS', $offset)) !== false) {
                        if (strlen($tail) >= $page + 14) {
                            $granule = unpack('Vlow/Vhigh', substr($tail, $page + 6, 8));
                            if (is_array($granule)) {
                                $value = (float)$granule['high'] * 4294967296.0 + (float)$granule['low'];
                                $maximumGranule = max($maximumGranule, $value);
                            }
                        }
                        $offset = $page + 4;
                    }
                    if ($maximumGranule > 0) {
                        $duration = (int)round(($maximumGranule / $sampleRate) * 1000);
                    }
                }
            } finally {
                fclose($handle);
            }
        }
        return array_filter([
            'container'=>'ogg',
            'audio_codec'=>$codec,
            'sample_rate'=>$sampleRate,
            'channel_count'=>$channels,
            'duration_ms'=>$duration,
        ], static fn($value): bool => $value !== 0);
    }

    private static function parsedVersion(string $filename, string $prefix): ?string
    {
        if (strcasecmp($filename, 'tzupdater.ver') === 0) {
            $value = trim((string)strtok($prefix, "\r\n"));
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $value) === 1 ? $value : null;
        }
        if (strcasecmp($filename, 'tzdb.dat') === 0
            && preg_match('/(?<![A-Za-z0-9])((?:20)\d{2}[a-z])(?![A-Za-z0-9])/i', $prefix, $match)) {
            return strtolower((string)$match[1]);
        }
        if (preg_match('/\btzdata[-_. ]?((?:20)?\d{2}[a-z])\b/i', $prefix, $match)
            || preg_match('/\b((?:20)\d{2}[a-z])\b/i', $filename, $match)) {
            return strtolower((string)$match[1]);
        }
        $stem = (string)pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/(?:^|[-_.])(\d+(?:[-_.]\d+){1,6}(?:[-_.][A-Za-z0-9]+)*)/D', $stem, $match)) {
            return trim(str_replace('_', '.', (string)$match[1]), '.-');
        }
        return null;
    }

    private static function archiveTzdataVersion(string $path): ?string
    {
        try {
            $archive = new \PharData($path);
            $seen = 0;
            foreach (new \RecursiveIteratorIterator($archive) as $name => $entry) {
                if (++$seen > 256) { break; }
                $name = (string)$name;
                if (preg_match('/\b((?:20)\d{2}[a-z])\b/i', $name, $match)) {
                    return strtolower((string)$match[1]);
                }
                if (!preg_match('/(?:manifest|version|tzdata|zoneinfo)/i', $name)
                    || !method_exists($entry, 'getContent')
                    || (int)$entry->getSize() > self::PREFIX_BYTES) {
                    continue;
                }
                $content = (string)$entry->getContent();
                if (preg_match('/\btzdata[-_. ]?((?:20)?\d{2}[a-z])\b/i', $content, $match)
                    || preg_match('/\b((?:20)\d{2}[a-z])\b/i', $content, $match)) {
                    return strtolower((string)$match[1]);
                }
            }
        } catch (\Throwable $error) {
            return null;
        }
        return null;
    }

    private static function looksLikeMp3Frame(string $prefix): bool
    {
        return strlen($prefix) >= 2
            && ord($prefix[0]) === 0xff
            && (ord($prefix[1]) & 0xe0) === 0xe0;
    }

    private static function magicLabel(string $contentType): string
    {
        return [
            'image/png'=>'PNG', 'image/jpeg'=>'JPEG', 'image/gif'=>'GIF',
            'image/bmp'=>'BMP', 'audio/wav'=>'RIFF/WAVE',
            'audio/ogg'=>'Ogg', 'audio/mpeg'=>'MPEG audio',
            'application/zip'=>'ZIP',
            'application/java-archive'=>'ZIP/JAR',
        ][$contentType] ?? $contentType;
    }
}
