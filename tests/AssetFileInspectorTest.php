<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/AssetCatalog.php';
require_once dirname(__DIR__) . '/lib/AssetFileInspector.php';
require_once __DIR__ . '/TimezoneRuntimeFixture.php';

use FreePBX\modules\Ucmprovisioner\AssetFileInspector;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$write = static function (string $bytes): string {
    $path = tempnam(sys_get_temp_dir(), 'ucmp-inspector-');
    if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Could not create inspector fixture');
    }
    return $path;
};

$paths = [];
try {
    $paths[] = $pngPath = $write((string)base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    ));
    $png = AssetFileInspector::inspect($pngPath, 'pixel.png', 'wallpaper');
    $expect(
        $png['content_type'] === 'image/png'
        && $png['validation_status'] === 'verified'
        && ($png['metadata']['width'] ?? 0) === 1
        && ($png['metadata']['height'] ?? 0) === 1
        && strlen((string)$png['content_sha256']) === 64,
        'PNG inspection must trust decoded magic/dimensions and hash exact bytes'
    );

    $paths[] = $fakePngPath = $write('not a png');
    $fakePng = AssetFileInspector::inspect($fakePngPath, 'fake.png', 'wallpaper');
    $expect(
        $fakePng['validation_status'] === 'invalid'
        && str_contains(implode(';', $fakePng['errors']), 'extension does not match'),
        'A media extension that disagrees with magic must be invalid'
    );

    $paths[] = $rawPath = $write(str_repeat("\x7f", 8000));
    $chunk=static fn(string $name,string $bytes):string=>$name.pack('V',strlen($bytes)).$bytes.(strlen($bytes)%2?"\0":'');
    $fmt=$chunk('fmt ',pack('vvVVvv',1,1,8000,16000,2,16));
    $data=$chunk('data',str_repeat("\0",16000));
    $riff=static fn(string $chunks):string=>'RIFF'.pack('V',strlen($chunks)+4).'WAVE'.$chunks;
    // Misleading chunk names inside metadata must not become the audio header.
    $wave=$riff($chunk('JUNK','data fmt misleading metadata').$fmt.$data);
    $paths[]=$wavePath=$write($wave);
    $waveInfo=AssetFileInspector::inspect($wavePath,'Valid.wav','ringtone');
    $expect($waveInfo['validation_status']==='verified'&&$waveInfo['metadata']['duration_ms']===1000,'WAV chunks and odd-byte padding must be parsed structurally');
    foreach ([substr($wave,0,-8),$riff($fmt.'data'.pack('V',20000).str_repeat("\0",20)),
        $riff($chunk('JUNK',$fmt.$data)),$riff($data.$fmt),
        $riff($chunk('fmt ',pack('vvVVvv',1,1,8000,1,2,16)).$data)] as $badWave) {
        $paths[]=$badPath=$write($badWave);
        $expect(AssetFileInspector::inspect($badPath,'Broken.wav','ringtone')['validation_status']==='invalid',
            'Truncation, misleading embedded headers and inconsistent WAV rate must fail inspection');
    }
    $raw = AssetFileInspector::inspect($rawPath, 'Classic.raw', 'ringtone');
    $expect(
        $raw['validation_status'] === 'verified'
        && $raw['content_type'] === 'audio/basic'
        && ($raw['metadata']['audio_codec'] ?? '') === 'mu-law'
        && ($raw['metadata']['sample_rate'] ?? 0) === 8000
        && ($raw['metadata']['duration_ms'] ?? 0) === 1000,
        'Headerless Cisco raw ringtone metadata must be interpreted by its verified profile'
    );

    $paths[] = $widebandPath = $write('odd');
    $wideband = AssetFileInspector::inspect($widebandPath, 'Wide.rwb', 'ringtone');
    $expect($wideband['validation_status'] === 'invalid', 'Odd-width signed-16-bit ringtone must be invalid');

    $paths[] = $mp3Path = $write("\xff\xfb\x90\x00" . str_repeat("\0", 15996));
    $mp3 = AssetFileInspector::inspect($mp3Path, 'Tone.mp3', 'ringtone');
    $expect(
        $mp3['validation_status'] === 'verified'
        && ($mp3['metadata']['audio_codec'] ?? '') === 'mp3'
        && ($mp3['metadata']['sample_rate'] ?? 0) === 44100
        && ($mp3['metadata']['channel_count'] ?? 0) === 2,
        'MP3 inspection must parse codec, rate, channels and duration from the frame header'
    );

    $vorbisHeader = "\x01vorbis" . pack('V',0) . chr(2) . pack('V',48000) . pack('V3',0,0,0) . "\xB8\x01";
    $oggPage=static fn(string $packet): string=>'OggS'."\0\x02".str_repeat("\0",8).pack('V3',1,0,0)."\x01".chr(strlen($packet)).$packet;
    $paths[] = $oggPath = $write($oggPage($vorbisHeader));
    $ogg = AssetFileInspector::inspect($oggPath, 'Tone.ogg', 'ringtone');
    $expect(
        $ogg['validation_status'] === 'verified'
        && ($ogg['metadata']['audio_codec'] ?? '') === 'vorbis'
        && ($ogg['metadata']['sample_rate'] ?? 0) === 48000
        && ($ogg['metadata']['channel_count'] ?? 0) === 2,
        'Ogg inspection must parse the codec identification header'
    );
    foreach ([substr($vorbisHeader,0,16),substr_replace($vorbisHeader,pack('V',1),7,4),substr_replace($vorbisHeader,"\0",11,1),substr_replace($vorbisHeader,"\0",29,1),substr_replace($vorbisHeader,"\xF1",28,1)] as $invalidHeader) {
        $paths[]=$invalidPath=$write($oggPage($invalidHeader));
        $expect(AssetFileInspector::inspect($invalidPath,'Broken.ogg','ringtone')['validation_status']==='invalid','Malformed or truncated Vorbis identification must be rejected');
    }
    foreach (['tzupdater.jar','j9-tzdata.jar','TzDataCSV.csv'] as $runtimeName) {
        $paths[]=$invalidPath=$write('plausible tzdata2025b text but no runtime container');
        $expect(AssetFileInspector::inspect($invalidPath,$runtimeName,'locale')['validation_status']==='invalid','Version-like text alone must not verify a runtime payload');
    }

    $paths[] = $timezonePath = $write(timezoneRuntimeFixture('j9-tzdata.jar','tzdata2025b'));
    $timezone = AssetFileInspector::inspect($timezonePath, 'j9-tzdata.jar', 'timezone');
    $expect(
        $timezone['parsed_version'] === '2025b'
        && $timezone['validation_status'] === 'verified'
        && ($timezone['metadata']['declared_tzdata_version'] ?? '') === '2025b',
        'Cisco timezone payload inspection must bind the parsed tzdata version to the hash'
    );
    $paths[] = $timezoneVersionPath = $write("2022c-2026\n");
    $timezoneVersion = AssetFileInspector::inspect($timezoneVersionPath, 'tzupdater.ver', 'timezone');
    $expect(
        $timezoneVersion['parsed_version'] === '2022c-2026'
        && $timezoneVersion['validation_status'] === 'verified',
        'tzupdater.ver must be treated as the authoritative served version'
    );

    $paths[] = $firmwarePath = $write("firmware bytes\n");
    $firmware = AssetFileInspector::inspect($firmwarePath, 'sip88xx.14-2-1.loads', 'firmware');
    $expect(
        $firmware['parsed_version'] === '14-2-1'
        && $firmware['validation_status'] === 'discovered'
        && $firmware['signature_status'] === 'unverified',
        'A standalone firmware file may be version/hash observed but not verified before bundle reconciliation'
    );
} finally {
    foreach ($paths as $path) {
        if (is_file($path)) { unlink($path); }
    }
}

echo "Asset file inspector fixtures passed\n";
