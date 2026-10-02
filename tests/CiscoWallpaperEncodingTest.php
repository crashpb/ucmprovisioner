<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/TftpStore.php';

use FreePBX\modules\Ucmprovisioner\ModelCatalog;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$chunk = static function (string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
};
$rgbaPng = static function (int $width, int $height) use ($chunk): string {
    $row = "\0" . str_repeat("\xff\x40\x20\x60", $width);
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress(str_repeat($row, $height), 9))
        . $chunk('IEND', '');
};

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/TftpStore.php');
$media = (string)file_get_contents(dirname(__DIR__) . '/views/media.php');
$expect(
    str_contains($source, "case 'wallpaper_set_repair_encoding'")
        && str_contains($media, 'ucm-bg-repair-encoding'),
    'Media Manager must expose the explicit existing-catalog Cisco PNG repair path'
);

if (function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor')) {
    $normalize = new ReflectionMethod(TftpStore::class, 'normalizeWallpaperPayload');
    $normalize->setAccessible(true);
    $encodingIssue = new ReflectionMethod(TftpStore::class, 'wallpaperEncodingIssue');
    $encodingIssue->setAccessible(true);

    $trueColorProfile = ModelCatalog::wallpaperProfileByKey('cisco:800x480x24:800x480x139x109');
    $expect(is_array($trueColorProfile), 'Cisco 800x480 profile fixture is missing');
    $rgba = $rgbaPng(800, 480);
    $expect(
        str_contains((string)$encodingIssue->invoke(null, $rgba, $trueColorProfile, false), 'alpha channel'),
        'Existing RGBA Cisco output must be reported as device-unsafe'
    );
    $opaque = (string)$normalize->invoke(null, $rgba, $trueColorProfile, false);
    $expect(
        ord($opaque[25]) === 2
            && $encodingIssue->invoke(null, $opaque, $trueColorProfile, false) === null,
        'Cisco true-colour publication must strip alpha and prove the opaque result'
    );

    $grayscaleProfile = ModelCatalog::wallpaperProfileByKey('cisco:320x196x4:320x196x80x49');
    $expect(is_array($grayscaleProfile), 'Cisco SIP41 grayscale profile fixture is missing');
    $palette = (string)$normalize->invoke(null, $rgbaPng(320, 196), $grayscaleProfile, false);
    $expect(
        ord($palette[25]) === 3
            && $encodingIssue->invoke(null, $palette, $grayscaleProfile, false) === null,
        'Cisco SIP41 publication must produce an opaque bounded palette'
    );
}

echo "CiscoWallpaperEncodingTest OK\n";
