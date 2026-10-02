<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\{AssetFilename,AssetCatalog,CiscoTimezoneBundleManager,DialTemplateGenerator,FirmwareService,ManagedAssetRegistry,ModelCatalog,TftpStore};

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $call, string $message): void {
    try { $call(); } catch (RuntimeException|InvalidArgumentException $expected) { return; }
    throw new LogicException($message);
};
$hash = hash('sha256', 'synthetic payload');
foreach (['d'=>'xml','s'=>'xml','f'=>'xml','a'=>'jar','j'=>'jar','c'=>'csv','w'=>'png','o'=>'jpg','r'=>'raw','h'=>'png','g'=>'bmp','p'=>'xml','v'=>'json'] as $type=>$extension) {
    $name = AssetFilename::fromHash($type, $hash, $extension);
    $expect(strlen($name) <= 20 && AssetFilename::isCompact($name), 'A generated filename exceeds the compact contract');
    $expect($name !== AssetFilename::fromHash($type, hash('sha256', 'changed payload'), $extension), 'Changed identity reused a cached name');
}
$reject(fn()=>AssetFilename::fromHash('w', $hash, 'json', '-tn'), 'Oversized suffix accepted');
$collision = substr($hash, 0, 12) . str_repeat('0', 52);
$name = AssetFilename::fromHash('d', $hash, 'xml');
$expect($name === AssetFilename::fromHash('d', $collision, 'xml'), 'Collision fixture does not share a prefix');
$reject(fn()=>AssetFilename::assertSameContent($name, $hash, $collision), 'A truncated-hash collision overwrote another full identity');
AssetFilename::assertSameContent($name, $hash, $hash);
foreach ([$name, 's'.substr($hash,0,12).'.xml', 'f'.substr($hash,0,12).'.xml', 'dialplan-tpl'.$hash.'.xml', 'tplstack-'.substr($hash,0,16).'-softkeys.xml'] as $companion) {
    $expect(AssetFilename::isCompanion($companion), 'Companion lifecycle lost a new or retained filename');
}
$expect(!AssetFilename::isCompanion('SEP001122334455.cnf.xml'), 'Phone configuration mistaken for disposable companion');
$settings = ['dial_template_rules'=>[['match'=>'*','timeout'=>5]]];
$dial = DialTemplateGenerator::filenameFor($settings);
$expect($dial === AssetFilename::content('d', DialTemplateGenerator::fromSettings($settings), 'xml'), 'Dial reference does not identify actual rendered bytes');

$metadata = new ReflectionMethod(TftpStore::class, 'managedAssetMetadata');
foreach (ModelCatalog::CISCO_TIMEZONE_PROFILES as $profile=>$definition) {
    $key = hash('sha256', $profile . "\0" . $hash);
    $alias = CiscoTimezoneBundleManager::publishedFilename($profile, $key);
    $expect(strlen($alias) === 17 && AssetFilename::timezonePayload($alias) === $definition['payload'], 'Timezone alias lost its runtime type');
    $row = $metadata->invoke(null, $alias, $hash);
    $expect($row['kind'] === 'locale' && $row['profile'] === $profile && $row['metadata']['timezone_asset_key'] === $key, 'Scan/replication lost the complete timezone identity');
    $reject(fn()=>$metadata->invoke(null, $alias, hash('sha256', 'other bytes')), 'Timezone alias accepted content with a different full identity');
    $legacy = $definition['published_prefix'] . '-' . $key . '.' . pathinfo($definition['payload'], PATHINFO_EXTENSION);
    $expect(AssetFilename::timezonePayload($legacy) === $definition['payload'], 'Retained timezone aliases cannot be read');
}

$outputNames = new ReflectionMethod(TftpStore::class, 'wallpaperOutputNames');
$safeName = new ReflectionMethod(TftpStore::class, 'wallpaperOutputNameIsSafe');
$profile = ModelCatalog::wallpaperProfileByKey('cisco-800x480');
if ($profile === null) {
    foreach (ModelCatalog::backgroundProfilesForDirectory('800x480x24') as $candidate) { $profile = ModelCatalog::wallpaperProfileByKey($candidate['key']); if ($profile) { break; } }
}
$expect(is_array($profile), 'Wallpaper fixture profile missing');
$pair = $outputNames->invoke(null, 'catalog-with-a-long-human-name', $profile, $hash, $hash);
$expect(strlen($pair['full']) === 17 && strlen($pair['thumbnail']) === 20, 'Wallpaper pair remains long');
$expect(str_replace('-tn.png', '.png', $pair['thumbnail']) === $pair['full'], 'Wallpaper index cannot pair compact names');
$expect($safeName->invoke(null, $pair['full'], 'catalog-with-a-long-human-name', $profile, 'full', $hash, $hash), 'Catalog reader rejects its own compact filename');
$expect(!$safeName->invoke(null, $pair['full'], 'different-owner', $profile, 'full', $hash, $hash), 'Compact naming lost catalog ownership');

$vendor = 'vendor-release-with-a-long-original-filename.rom';
$sidecar = FirmwareService::declarationFilename($vendor);
$expect(strlen($sidecar) <= 20, 'Firmware declaration still appends a long suffix');
$base = 'firmware/yealink/t4u/';
$manifest = FirmwareService::compatibilityManifest('Yealink', $vendor, ['T46U']);
$entries = [['path'=>$base.$vendor,'size'=>100], ['path'=>$base.$sidecar,'size'=>100]];
$bundles = FirmwareService::analyzeFiles($entries, static fn(string $path)=>$path === $base.$sidecar ? json_encode($manifest) : false);
$expect(count($bundles) === 1 && $bundles[0]['status'] === 'complete', 'Compact firmware declaration is not selectable');
$expect($bundles[0]['files'][0]['name'] === $vendor && $bundles[0]['files'][1]['name'] === $sidecar, 'Firmware binary was renamed or its declaration detached');
$expect($metadata->invoke(null, $base.$sidecar)['kind'] === 'firmware', 'Replication rejects compact firmware metadata');

$db = new PDO('sqlite::memory:'); ManagedAssetRegistry::ensureSchema($db);
$module = new class($db) { public function __construct(private PDO $db) {} public function getDb(): PDO { return $this->db; } };
$store = new TftpStore($module);
$ring = AssetFilename::content('r', 'synthetic audio', 'raw');
$storage = new class($ring) {
    public array $files;
    public function __construct(string $ring) { $this->files = ['/tftp/'.$ring=>'synthetic audio']; }
    public function file_exists(string $path): bool { return isset($this->files[$path]); }
    public function nlist(string $path): array { return array_map('basename', array_keys($this->files)); }
    public function stat(string $path): array|false { return isset($this->files[$path]) ? ['type'=>1,'size'=>strlen($this->files[$path])] : false; }
    public function get(string $path, $local=false, int $offset=0, int $length=-1): string|false { return isset($this->files[$path]) ? substr($this->files[$path], $offset, $length < 0 ? null : $length) : false; }
    public function put(string $path, string $bytes): bool { $this->files[$path]=$bytes; return true; }
    public function chmod(int $mode, string $path): bool { return true; }
};
$regenerate = new ReflectionMethod(TftpStore::class, 'regenerateRingList');
$result = $regenerate->invoke($store, 1, $storage, '/tftp', false, [$ring=>'Sales & Support']);
$expect(!empty($result['ok']) && str_contains($storage->files['/tftp/Ringlist.xml'], 'Sales &amp; Support'), 'Copied ringtone lost its readable escaped label');
$regenerate->invoke($store, 1, $storage, '/tftp', false);
$expect(str_contains($storage->files['/tftp/Ringlist.xml'], 'Sales &amp; Support'), 'A later index rebuild lost the copied label');
$guard = new ReflectionMethod(TftpStore::class, 'assertCompactTarget');
$reject(fn()=>$guard->invoke($store, $storage, '/tftp/'.$ring, hash('sha256', 'different audio')), 'Direct TFTP publication did not reject a compact-name conflict');
$expect($storage->files['/tftp/'.$ring] === 'synthetic audio', 'Collision damaged the existing ringtone');
echo "Compact filenames, full identities, ownership, firmware dependencies and ringtone labels passed\n";
