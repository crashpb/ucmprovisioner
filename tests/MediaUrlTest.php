<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/MediaUrl.php';

use FreePBX\modules\Ucmprovisioner\MediaUrl;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$endpoint = MediaUrl::endpoint('http://192.0.2.10/site/', 'cisco_cnf', 'all');
if ($endpoint !== 'http://192.0.2.10/site/ucmp-m.php/cisco_cnf/all/' || strpbrk($endpoint, '?&') !== false) {
    $fail('Media endpoint was not rendered as a query-free path');
}
$cnfAsset = MediaUrl::asset('http://192.0.2.10', 'cisco_cnf', 'all', 'Corporate_Default.bmp', '243a68b4');
if ($cnfAsset !== 'http://192.0.2.10/ucmp-m.php/cisco_cnf/all/Corporate_Default.bmp') {
    $fail('Cisco CNF phone URL retained a browser cache token');
}
$cnfPreview = MediaUrl::preview('http://192.0.2.10', 'cisco_cnf', 'all', 'Corporate_Default.bmp', '243a68b4');
if ($cnfPreview !== 'http://192.0.2.10/ucmp-m.php/cisco_cnf/all/Corporate_Default.bmp?v=243a68b4') {
    $fail('Cisco CNF browser preview did not retain its separate cache token');
}
$formerCnfAsset = $cnfPreview;
if (MediaUrl::repairLegacyCiscoAssetUrl($formerCnfAsset) !== $cnfAsset) {
    $fail('A former module-owned CNF URL was not repaired to the direct phone URL');
}
if (MediaUrl::repairLegacyCiscoAssetUrl('http://example.test/company.bmp?v=243a68b4') !== 'http://example.test/company.bmp?v=243a68b4') {
    $fail('An arbitrary external logo URL was incorrectly rewritten');
}
$xmlAsset = MediaUrl::asset('http://192.0.2.10', 'yealink', 'all', 'wallpaper.png', '243a68b4');
if ($xmlAsset !== 'http://192.0.2.10/ucmp-m.php/yealink/all/wallpaper.png?v=243a68b4') {
    $fail('Modern phone media unexpectedly lost same-name replacement cache busting');
}
if (MediaUrl::normalizeBase('https://phones.example.test:8443/pbx/') !== 'https://phones.example.test:8443/pbx') {
    $fail('Phone-facing base normalization did not retain scheme, authority, port, and base path');
}

$route = MediaUrl::parsePathInfo('/cisco_cnf/all/Company_Logo.bmp');
if (($route['file'] ?? '') !== 'Company_Logo.bmp') {
    $fail('PATH_INFO filename was not decoded');
}

foreach ([
    '/cisco_cnf/all/../secret.bmp',
    '/cisco_cnf/%2e%2e/secret.bmp',
    '/cisco_cnf/./secret.bmp',
    '/cisco_cnf/all/..',
    '/cisco_cnf/all/%2e%2e%2fsecret.bmp',
    '/unknown/all/logo.bmp',
    '/cisco_cnf/all',
] as $badPath) {
    try {
        MediaUrl::parsePathInfo($badPath);
        $fail("Unsafe media path was accepted: $badPath");
    } catch (\InvalidArgumentException $e) {
    }
}

foreach ([
    'ftp://pbx.example',
    'http://user:pass@pbx.example',
    'http://pbx.example/?token=secret',
    'http://pbx.example/#fragment',
] as $badBase) {
    try {
        MediaUrl::normalizeBase($badBase);
        $fail("Unsafe media base URL was accepted: $badBase");
    } catch (\InvalidArgumentException $e) {
    }
}

$pixelOffset = 1078;
$rowStride = 92;
$imageSize = $rowStride * 56;
$fileSize = $pixelOffset + $imageSize;
$bmp = str_repeat("\0", $fileSize);
$put16 = static function (string &$buffer, int $offset, int $value): void {
    $buffer = substr_replace($buffer, pack('v', $value), $offset, 2);
};
$put32 = static function (string &$buffer, int $offset, int $value): void {
    $buffer = substr_replace($buffer, pack('V', $value), $offset, 4);
};
$bmp = substr_replace($bmp, 'BM', 0, 2);
$put32($bmp, 2, $fileSize);
$put32($bmp, 10, 1078);
$put32($bmp, 14, 40);
$put32($bmp, 18, 90);
$put32($bmp, 22, 56);
$put16($bmp, 26, 1);
$put16($bmp, 28, 8);
$put32($bmp, 30, 0);
$put32($bmp, 34, $imageSize);
$put32($bmp, 46, 256);
for ($colour = 0; $colour < 256; $colour++) {
    $bmp = substr_replace($bmp, pack('CCCC', $colour, $colour, $colour, 0), 54 + ($colour * 4), 4);
}
MediaUrl::assertLegacyCiscoBmp($bmp);

$rle = $bmp;
$put32($rle, 30, 1);
try {
    MediaUrl::assertLegacyCiscoBmp($rle);
    $fail('RLE8 Cisco BMP was accepted');
} catch (\InvalidArgumentException $e) {
}

try {
    MediaUrl::assertLegacyCiscoBmp(substr($bmp, 0, -1));
    $fail('Truncated Cisco BMP pixel payload was accepted');
} catch (\InvalidArgumentException $e) {
}

$fixture = sys_get_temp_dir() . '/ucmp-media-route-' . bin2hex(random_bytes(6));
mkdir($fixture . '/cisco_cnf/all', 0700, true);
file_put_contents($fixture . '/cisco_cnf/all/fixture.bmp', 'fixture-media');
file_put_contents($fixture . '/outside.bmp', 'outside-media');
$source = substr((string)file_get_contents(dirname(__DIR__) . '/media_asset.php'), 5);
$source = str_replace("__DIR__ . '/lib/MediaUrl.php'", var_export(dirname(__DIR__) . '/lib/MediaUrl.php', true), $source);
$source = str_replace("'/var/lib/asterisk/ucmprovisioner/media/'", var_export($fixture . '/', true), $source);
$run = static function (array $server) use ($source): array {
    $code = 'ob_start();register_shutdown_function(static function(): void {$body=ob_get_clean();echo json_encode([http_response_code() ?: 200,$body]);});$_SERVER='
        . var_export($server,true) . ';' . $source;
    $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start media endpoint fixture'); }
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if ($exit !== 0) { throw new RuntimeException('Media endpoint fixture failed: ' . $err); }
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
try {
    foreach ([['GET',200,'fixture-media'],['HEAD',200,''],['POST',405,'']] as [$method,$status,$body]) {
        if ($run(['REQUEST_METHOD'=>$method,'PATH_INFO'=>'/cisco_cnf/all/fixture.bmp']) !== [$status,$body]) {
            throw new RuntimeException('Media endpoint method/body handling is incorrect');
        }
    }
    if ($run(['PATH_INFO'=>'/cisco_cnf/%2e%2e/outside.bmp'])[0] !== 400) {
        throw new RuntimeException('Media endpoint accepted a parent-directory route');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        if (!symlink($fixture . '/outside.bmp', $fixture . '/cisco_cnf/all/linked.bmp')
            || !symlink($fixture . '/cisco_cnf/all', $fixture . '/cisco_cnf/linked')) {
            throw new RuntimeException('Cannot create media link fixture');
        }
        foreach (['/cisco_cnf/all/linked.bmp','/cisco_cnf/linked/fixture.bmp'] as $path) {
            if ($run(['PATH_INFO'=>$path]) !== [404,'']) { throw new RuntimeException('Public media followed a symbolic link'); }
        }
    } else { echo "Media symlink cases require Linux; method/path cases executed\n"; }
} finally {
    foreach (['cisco_cnf/all/linked.bmp','cisco_cnf/linked','cisco_cnf/all/fixture.bmp','outside.bmp'] as $path) {
        if (is_link($fixture.'/'.$path) || is_file($fixture.'/'.$path)) { unlink($fixture.'/'.$path); }
    }
    rmdir($fixture.'/cisco_cnf/all');rmdir($fixture.'/cisco_cnf');rmdir($fixture);
}
echo "Media URL and Cisco BMP assertions passed\n";
