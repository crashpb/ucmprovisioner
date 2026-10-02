<?php
declare(strict_types=1);

interface BMO {}
class FreePBX_Helpers {}

require_once dirname(__DIR__) . '/lib/MediaUrl.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/TftpStore.php';
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

use FreePBX\modules\Ucmprovisioner;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

final class MediaSyncModuleStub extends Ucmprovisioner
{
    /** @var array<int,array<string,string>> */
    public array $files = [];
    public int $failListSite = 0;
    public int $loseUploadSite = 0;
    public array $chunkCapabilities=[],$uploadSizes=[];
    public function __construct() {}
    public function localSiteId(): int { return 1; }
    public function isSiteLocal(int $siteId): bool { return true; }
    public function mediaAssetOpLocal(array $request): array
    {
        $site = (int)$request['site_id']; $op = (string)$request['op'];
        $name = (string)($request['name'] ?? '');
        if ($op === 'list') {
            if ($site === $this->failListSite) { return ['ok'=>false,'message'=>'fixture library unavailable']; }
            return ['ok'=>true,'transfer_chunk_bytes'=>$this->chunkCapabilities[$site]??98304,'items'=>array_map(
                static fn(string $item): array => ['name'=>$item],
                array_keys($this->files[$site] ?? [])
            )];
        }
        if ($op === 'download_chunk') {
            if (!isset($this->files[$site][$name])) { return ['ok'=>false,'message'=>'missing']; }
            $offset = (int)($request['offset'] ?? 0); $content = $this->files[$site][$name];
            $chunk = substr($content, $offset, (int)($request['chunk_bytes']??98304));
            return ['ok'=>true,'bytes'=>strlen($chunk),'content'=>base64_encode($chunk),'eof'=>$offset+strlen($chunk)>=strlen($content),'size'=>strlen($content),'sha256'=>hash('sha256',$content)];
        }
        if ($op === 'upload_chunk') {
            $data = base64_decode((string)$request['content'], true);
            if ($data === false) { return ['ok'=>false,'message'=>'invalid']; }
            $this->uploadSizes[$site][]=strlen($data);
            if ((int)$request['offset'] === 0) { $this->files[$site][$name] = $data; }
            else { $this->files[$site][$name] .= $data; }
            if ($site === $this->loseUploadSite) { throw new RuntimeException('fixture response lost after upload'); }
            return ['ok'=>true,'bytes'=>strlen($data),'complete'=>!empty($request['final']),'transfer_id'=>$request['transfer_id'],'sha256'=>hash('sha256',$this->files[$site][$name]),'size'=>strlen($this->files[$site][$name])];
        }
        return ['ok'=>false,'message'=>'unsupported'];
    }
}

final class SpecialSyncModuleStub extends Ucmprovisioner
{
    public int $legacySite = 0;
    public int $loseActivationSite = 0;
    /** @param array<int,string> $roots */
    public function __construct(public array $roots) {}
    public function localSiteId(): int { return 1; }
    public function isSiteLocal(int $siteId): bool { return true; }
    private function call(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(Ucmprovisioner::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this, ...$arguments);
    }
    public function mediaAssetOpLocal(array $request): array
    {
        $site = (int)$request['site_id']; $dir = $this->roots[$site];
        $base = 'http://site-' . $site . '.test';
        $result = match ((string)$request['op']) {
            'special_catalog_list'=>$this->call('specialCatalogList', $dir, $base),
            'special_catalog_get'=>$this->call('specialCatalogGet', $dir, $base, (string)$request['catalog_id'], empty($request['include_source']) ? false : true),
            'special_catalog_download_chunk'=>$this->call('specialCatalogDownloadChunk', $dir, $request),
            'special_catalog_upload_chunk'=>$this->call('specialCatalogUploadChunk', $dir, $request),
            'special_catalog_activate'=>$this->call('specialCatalogActivate', $dir, $base, $request),
            default=>['ok'=>false,'message'=>'unsupported'],
        };
        if ($request['op'] === 'special_catalog_list' && $site === $this->legacySite) { unset($result['transfer_protocol']); }
        if ($request['op'] === 'special_catalog_activate' && $site === $this->loseActivationSite) { throw new RuntimeException('fixture activation response lost'); }
        return $result;
    }
}

final class CopyPathSftpStub
{
    public array $directories = [];
    public array $modes = [];
    public ?string $failPutSuffix = null;
    public ?string $failActivateSuffix = null;
    public ?string $corruptReadSuffix = null;
    /** @param array<string,string> $files */
    public function __construct(public array $files) {}
    public function file_exists(string $path): bool { return array_key_exists($path, $this->files) || isset($this->directories[$path]); }
    public function stat(string $path): array|false {
        if (array_key_exists($path, $this->files)) { return ['type'=>1,'size'=>strlen($this->files[$path]),'mode'=>$this->modes[$path] ?? 0664]; }
        return isset($this->directories[$path]) ? ['type'=>2,'mode'=>$this->modes[$path] ?? 0755] : false;
    }
    public function lstat(string $path): array|false { return $this->stat($path); }
    public function is_dir(string $path): bool { return isset($this->directories[$path]); }
    public function mkdir(string $path, int $mode = -1, bool $recursive = false): bool {
        if (isset($this->directories[$path])) { return false; }
        $this->directories[$path] = true; return true;
    }
    public function rmdir(string $path): bool { unset($this->directories[$path]); return true; }
    public function chmod(int $mode, string $path): bool { $this->modes[$path] = $mode; return $this->file_exists($path); }
    public function put(string $path, string $content, int $mode = 0): bool {
        if ($this->failPutSuffix !== null && str_ends_with($path, $this->failPutSuffix)) {
            $this->files[$path] = 'partial upload'; return false;
        }
        $this->files[$path] = $mode === 1 ? (string)file_get_contents($content) : $content;
        return true;
    }
    public function delete(string $path): bool { unset($this->files[$path]); return true; }
    public function rename(string $from, string $to): bool {
        if ($this->failActivateSuffix !== null && str_contains($from, '/stage/') && str_ends_with($to, $this->failActivateSuffix)) {
            $this->failActivateSuffix = null; return false;
        }
        if (!isset($this->files[$from])) { return false; }
        $this->files[$to] = $this->files[$from]; unset($this->files[$from]); return true;
    }
    public function posix_rename(string $from, string $to): bool { return $this->rename($from, $to); }
    public function get(string $remote, mixed $local = false, int $offset = 0, int $length = -1): string|bool
    {
        if (!isset($this->files[$remote])) { return false; }
        if ($this->corruptReadSuffix!==null&&str_ends_with($remote,$this->corruptReadSuffix)) {
            return is_string($local)?file_put_contents($local,'corrupt readback')!==false:'corrupt readback';
        }
        $bytes = $length < 0 ? substr($this->files[$remote], $offset) : substr($this->files[$remote], $offset, $length);
        return is_string($local) ? file_put_contents($local, $bytes) !== false : $bytes;
    }
}

final class CopyPathConnectionStub
{
    public function __construct(private CopyPathSftpStub $sftp, private string $root) {}
    public function dockerSftp(): CopyPathSftpStub { return $this->sftp; }
    public function tftpRoot(): string { return $this->root; }
}

final class CopyPathModuleStub
{
    /** @param array<int,CopyPathConnectionStub> $connections */
    public function __construct(private array $connections) {}
    public function siteConnection(int $siteId): CopyPathConnectionStub { return $this->connections[$siteId]; }
}

$sync = new MediaSyncModuleStub();
$sync->files[1] = ['tone.wav'=>'WAVE', 'wallpaper.png'=>'PNG'];
$sync->failListSite = 2;
$failure = $sync->mediaAssetOp(['op'=>'sync','site_id'=>1,'family'=>'yealink','model'=>'all','names'=>['tone.wav'],'target_site_ids'=>[2,3],'overwrite'=>1]);
$expect(empty($failure['ok']) && empty($sync->files[2]) && ($sync->files[3]['tone.wav'] ?? '') === 'WAVE'
    && $failure['sites'][2]['status'] === 'error', 'A failed destination listing must block that site and preserve later destination success');
$sync->failListSite = 1;
$failure = $sync->mediaAssetOp(['op'=>'sync','site_id'=>1,'family'=>'yealink','model'=>'all','target_site_ids'=>[2,3]]);
$expect(array_keys($failure['sites']) === [2,3] && $failure['sites'][2]['status'] === 'error', 'Source preflight failure must report every unstarted destination');
$sync->failListSite = 0; $sync->loseUploadSite = 2;
$failure = $sync->mediaAssetOp(['op'=>'sync','site_id'=>1,'family'=>'yealink','model'=>'all','names'=>['tone.wav'],'target_site_ids'=>[2],'overwrite'=>1]);
$expect(($sync->files[2]['tone.wav'] ?? '') === 'WAVE' && $failure['sites'][2]['status'] === 'unknown'
    && strlen($failure['sites'][2]['transfer_id']) === 32, 'Lost upload completion must retain transfer identity and uncertainty');
$sync->loseUploadSite = 0; $sync->files = [1=>['tone.wav'=>'WAVE','wallpaper.png'=>'PNG']];
$selected = $sync->mediaAssetOp([
    'op'=>'sync','site_id'=>1,'family'=>'yealink','model'=>'all',
    'names'=>['tone.wav'],'target_site_ids'=>[2],'overwrite'=>1,
]);
$expect(!empty($selected['ok']) && ($sync->files[2] ?? []) === ['tone.wav'=>'WAVE'], 'Named media sync copied anything except the selected file');
$unknown = $sync->mediaAssetOp([
    'op'=>'sync','site_id'=>1,'family'=>'yealink','model'=>'all',
    'names'=>['missing.wav'],'target_site_ids'=>[2],'overwrite'=>1,
]);
$expect(empty($unknown['ok']) && str_contains((string)$unknown['message'], 'Unknown source media'), 'Named media sync must reject unknown source files');
$bulk = $sync->mediaAssetOp([
    'op'=>'sync','site_id'=>1,'family'=>'yealink','model'=>'all',
    'target_site_ids'=>[3],'overwrite'=>1,
]);
$expect(!empty($bulk['ok']) && count($sync->files[3] ?? []) === 2, 'Omitting names must preserve whole-library sync behavior');
$sync->files=[1=>['large.wav'=>str_repeat('x',1048576)]];$sync->chunkCapabilities=[1=>524288,2=>524288];$sync->uploadSizes=[];
$negotiated=$sync->mediaAssetOp(['op'=>'sync','site_id'=>1,'family'=>'yealink','model'=>'all','target_site_ids'=>[2,3],'overwrite'=>1]);
$expect($negotiated['ok'] && $sync->uploadSizes[2]===[524288,524288] && max($sync->uploadSizes[3])===98304,'Hosted-media chunk limits must negotiate per peer and preserve old-peer compatibility');
$expect($sync->files[1]['large.wav']===$sync->files[2]['large.wav'] && $sync->files[1]['large.wav']===$sync->files[3]['large.wav'],'Negotiated media bytes changed');

$sourceSftp = new CopyPathSftpStub(['/source/catalog/output.png'=>'new-output']);
$matchingTarget = new CopyPathSftpStub(['/target/catalog/output.png'=>'new-output']);
$differentTarget = new CopyPathSftpStub(['/target/catalog/output.png'=>'old-or-manual-output']);
$copyPaths = static function (CopyPathSftpStub $target) use ($sourceSftp): array {
    $store = new TftpStore(new CopyPathModuleStub([
        1=>new CopyPathConnectionStub($sourceSftp, '/source'),
        2=>new CopyPathConnectionStub($target, '/target'),
    ]));
    $method = new ReflectionMethod(TftpStore::class, 'copyPaths');
    $method->setAccessible(true);
    return $method->invoke($store, 1, [2], ['catalog/output.png'], false);
};
$preservedMatch = $copyPaths($matchingTarget);
$expect(
    !empty($preservedMatch['ok']) && (int)($preservedMatch['sites']['2']['skipped'] ?? 0) === 1,
    'Preserve-existing sync must accept a byte-identical target: ' . json_encode($preservedMatch)
);
$preservedMismatch = $copyPaths($differentTarget);
$expect(
    empty($preservedMismatch['ok'])
    && str_contains((string)($preservedMismatch['sites']['2']['message'] ?? ''), 'differs from source'),
    'Preserve-existing sync must fail closed when a target filename contains different bytes'
);

foreach (['upload', 'activate','readback'] as $failurePhase) {
    $source = new CopyPathSftpStub(['/source/a.bin'=>'new-a', '/source/b.bin'=>'new-b']);
    $target = new CopyPathSftpStub(['/target/a.bin'=>'old-a', '/target/b.bin'=>'old-b']);
    if ($failurePhase === 'upload') { $target->failPutSuffix = '/stage/b.bin'; }
    elseif ($failurePhase==='activate') { $target->failActivateSuffix = '/b.bin'; }
    else { $target->corruptReadSuffix='/stage/b.bin'; }
    $subject = new TftpStore(new CopyPathModuleStub([
        1=>new CopyPathConnectionStub($source, '/source'), 2=>new CopyPathConnectionStub($target, '/target'),
    ]));
    $copy = new ReflectionMethod(TftpStore::class, 'copyPaths');
    $failedCopy = $copy->invoke($subject, 1, [2], ['a.bin', 'b.bin'], true);
    $expect(empty($failedCopy['ok']), 'A partial asset transfer/activation must report failure');
    $expect($target->files['/target/a.bin'] === 'old-a' && $target->files['/target/b.bin'] === 'old-b',
        'A later copy failure must preserve both previous destination files');
    $expect(($failedCopy['sites']['2']['copied'] ?? -1) === 0, 'Recovered copy must not report reverted publications as copied');
    $recoveryId = (string)($failedCopy['sites']['2']['recovery_id'] ?? '');
    $expect($recoveryId !== '' && !empty($subject->rollbackAssetCopy(2, $recoveryId)['ok']), 'Copy recovery receipt must be safely repeatable');
    $expect($target->files['/target/a.bin'] === 'old-a' && $target->files['/target/b.bin'] === 'old-b', 'Repeat asset recovery preserves restored data');
}

$module = (new ReflectionClass(Ucmprovisioner::class))->newInstanceWithoutConstructor();
$invoke = static function (object $object, string $method, mixed ...$arguments): mixed {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($object, ...$arguments);
};

$tmp = sys_get_temp_dir() . '/ucmp-special-catalog-' . bin2hex(random_bytes(6));
mkdir($tmp, 0770, true);
$pixelOffset = 1078;
$rowStride = 92;
$imageSize = $rowStride * 56;
$fileSize = $pixelOffset + $imageSize;
$bmp = str_repeat("\0", $fileSize);
$bmp = substr_replace($bmp, 'BM', 0, 2);
$bmp = substr_replace($bmp, pack('V', $fileSize), 2, 4);
$bmp = substr_replace($bmp, pack('V', $pixelOffset), 10, 4);
$bmp = substr_replace($bmp, pack('V', 40), 14, 4);
$bmp = substr_replace($bmp, pack('V', 90), 18, 4);
$bmp = substr_replace($bmp, pack('V', 56), 22, 4);
$bmp = substr_replace($bmp, pack('v', 1), 26, 2);
$bmp = substr_replace($bmp, pack('v', 8), 28, 2);
$bmp = substr_replace($bmp, pack('V', 0), 30, 4);
$bmp = substr_replace($bmp, pack('V', $imageSize), 34, 4);
$bmp = substr_replace($bmp, pack('V', 256), 46, 4);
for ($colour = 0; $colour < 256; $colour++) {
    $bmp = substr_replace($bmp, pack('CCCC', $colour, $colour, $colour, 0), 54 + ($colour * 4), 4);
}
$source = "\x89PNG\r\n\x1a\nSOURCE";
try {
    $created = $invoke($module, 'specialCatalogSave', $tmp, 'http://pbx.test', [
        'catalog_id'=>'rg_base','name'=>'RG Base 2026','intent'=>'create',
        'source'=>base64_encode($source),'source_name'=>'original photo.png',
        'published_name'=>'RG_Base_2026.bmp','content'=>base64_encode($bmp),
        'transform'=>['zoom'=>125,'x'=>7,'y'=>-9],'settings'=>['dither'=>'floyd','invert'=>true],
    ]);
    $expect(
        !empty($created['ok'])
        && ($created['catalog']['name'] ?? '') === 'RG Base 2026'
        && ($created['catalog']['published_name'] ?? '') === \FreePBX\modules\Ucmprovisioner\AssetFilename::content('g', 'rg_base', 'bmp')
        && ($created['catalog']['settings']['invert'] ?? false) === true
        && is_file($tmp . '/' . \FreePBX\modules\Ucmprovisioner\AssetFilename::content('g', 'rg_base', 'bmp')),
        'Special catalog create did not preserve its identity and grayscale inversion setting'
    );
    $expect(
        !str_contains((string)$created['catalog']['url'], '?')
        && str_contains((string)$created['catalog']['preview_url'], '?v='),
        'Cisco CNF device URL must remain query-free while the admin preview is cache-busted'
    );
    mkdir($tmp . '/.catalogs/corrupt', 0770, true);
    file_put_contents($tmp . '/.catalogs/corrupt/manifest.json', '{not-json');
    try {
        $invoke($module, 'specialCatalogSave', $tmp, 'http://pbx.test', [
            'catalog_id'=>'corrupt','name'=>'Corrupt retained state','intent'=>'create',
            'source'=>base64_encode($source),'source_name'=>'source.png',
            'published_name'=>'Corrupt.bmp','content'=>base64_encode($bmp),
        ]);
        $expect(false, 'A corrupt retained catalog manifest was treated as a missing catalog');
    } catch (Throwable $expected) {
        $expect(!is_file($tmp . '/Corrupt.bmp'), 'Rejected corrupt catalog state published replacement bytes');
    }
    try {
        $invoke($module, 'specialCatalogSave', $tmp, 'http://pbx.test', [
            'catalog_id'=>'bad_png','name'=>'Bad PNG','intent'=>'create',
            'source'=>base64_encode($source),'source_name'=>'source.png',
            'published_name'=>'Bad.bmp','content'=>base64_encode($source),
        ]);
        $expect(false, 'A PNG renamed to .bmp was accepted as a Cisco CNF output');
    } catch (InvalidArgumentException $expected) {
        $expect(!is_file($tmp . '/Bad.bmp'), 'Rejected mislabeled BMP left published bytes behind');
    }
    $updated = $invoke($module, 'specialCatalogSave', $tmp, 'http://pbx.test', [
        'expected_manifest_sha256'=>$created['catalog']['manifest_sha256'],
        'catalog_id'=>'rg_base','name'=>'RG Base 2026','intent'=>'update',
        'transform'=>['zoom'=>150,'x'=>10,'y'=>-12],
    ]);
    $expect(
        ($updated['catalog']['revision'] ?? 0) === 2
        && ($updated['catalog']['transform']['zoom'] ?? 0) === 150
        && ($updated['catalog']['settings']['invert'] ?? false) === true,
        'Special catalog update did not retain source/output/inversion and advance revision'
    );
    file_put_contents($tmp.'/.catalogs/rg_base/untracked-note.txt','retained extra content');
    $stale=$invoke($module,'specialCatalogSave',$tmp,'http://pbx.test',[
        'catalog_id'=>'rg_base','intent'=>'update','name'=>'Stale edit',
        'expected_manifest_sha256'=>$created['catalog']['manifest_sha256']]);
    $expect(empty($stale['ok'])&&!empty($stale['conflict']), 'A stale special-catalog editor overwrote a newer revision');
    $renamed = $invoke($module, 'specialCatalogRename', $tmp, 'http://pbx.test', [
        'expected_manifest_sha256'=>$updated['catalog']['manifest_sha256'],
        'catalog_id'=>'rg_base','new_catalog_id'=>'rg_2026','name'=>'RG 2026',
    ]);
    $expect(
        !empty($renamed['ok'])
        && ($renamed['catalog']['published_name'] ?? '') === $created['catalog']['published_name']
        && is_dir($tmp . '/.catalogs/rg_2026')
        && !is_dir($tmp . '/.catalogs/rg_base'),
        'Special catalog rename changed its compatibility filename or left its old identity active'
    );
    $recovery=$tmp.'/.catalogs/'.(string)($renamed['recovery_id']??'');
    $expect(is_file($recovery.'/manifest.json')&&is_file($recovery.'/untracked-note.txt')&&is_file($tmp.'/.catalogs/rg_2026/manifest.json'),
        'An unexpected old-directory file must not destroy either complete retained identity');
    $listed = $invoke($module, 'specialCatalogList', $tmp, 'http://pbx.test');
    $expect(count($listed['catalogs'] ?? []) === 1 && !empty($listed['catalogs'][0]['complete']), 'Special catalog list did not report the retained catalog as complete');

    file_put_contents($tmp . '/Loose.bmp', $bmp);
    $looseHash = hash_file('sha256', $tmp . '/Loose.bmp');
    $adopted = $invoke($module, 'specialAssetAdopt', $tmp, 'http://pbx.test', [
        'name'=>'Loose.bmp', 'catalog_id'=>'loose', 'catalog_name'=>'Loose legacy',
    ]);
    $expect(
        !empty($adopted['ok'])
        && ($adopted['catalog']['published_name'] ?? '') === 'Loose.bmp'
        && hash_equals($looseHash, (string)hash_file('sha256', $tmp . '/Loose.bmp'))
        && is_file($tmp . '/.catalogs/loose/manifest.json'),
        'Adopting a loose served BMP changed its phone-facing bytes/URL or failed to retain a catalog'
    );
    $managedDelete = $invoke($module, 'specialAssetDelete', $tmp, 'Loose.bmp');
    $expect(empty($managedDelete['ok']) && is_file($tmp . '/Loose.bmp'), 'Loose-file delete bypassed retained catalog ownership');
    file_put_contents($tmp . '/LooseDelete.bmp', $bmp);
    $looseDelete = $invoke($module, 'specialAssetDelete', $tmp, 'LooseDelete.bmp');
    $expect(empty($looseDelete['ok']) && is_file($tmp . '/LooseDelete.bmp'), 'A loose HTTP deletion without captured site and revision must fail closed');

    $sourceRoot = $tmp . '/sync-source'; $targetRoot = $tmp . '/sync-target';
    mkdir($sourceRoot, 0770, true); mkdir($targetRoot, 0770, true);
    $specialSync = new SpecialSyncModuleStub([1=>$sourceRoot, 2=>$targetRoot]);
    $invoke($specialSync, 'specialCatalogSave', $sourceRoot, 'http://site-1.test', [
        'catalog_id'=>'fleet_logo','name'=>'Fleet Logo','intent'=>'create',
        'source'=>base64_encode($source),'source_name'=>'fleet.png',
        'published_name'=>'Fleet.bmp','content'=>base64_encode($bmp),
        'transform'=>['zoom'=>130,'x'=>4,'y'=>-6],
    ]);
    $replicated = $specialSync->mediaAssetOp([
        'op'=>'special_catalog_sync','site_id'=>1,'set_ids'=>['fleet_logo'],
        'target_site_ids'=>[2],'overwrite'=>1,
    ]);
    $targetCatalogs = $invoke($specialSync, 'specialCatalogList', $targetRoot, 'http://site-2.test');
    $expect(
        !empty($replicated['ok'])
        && count($targetCatalogs['catalogs'] ?? []) === 1
        && !empty($targetCatalogs['catalogs'][0]['complete'])
        && ($targetCatalogs['catalogs'][0]['transform']['zoom'] ?? 0) === 130,
        'Special catalog synchronization did not move source, manifest, output, and retained editor state together'
    );
    $specialSync->legacySite = 2;
    $incompatible = $specialSync->mediaAssetOp(['op'=>'special_catalog_sync','site_id'=>1,'set_ids'=>['fleet_logo'],'target_site_ids'=>[2],'overwrite'=>1]);
    $expect(empty($incompatible['ok']) && str_contains($incompatible['sites'][2]['message'], 'Upgrade'), 'A peer without transfer isolation must be rejected before staging');
    $specialSync->legacySite = 0; $specialSync->loseActivationSite = 2;
    $lost = $specialSync->mediaAssetOp(['op'=>'special_catalog_sync','site_id'=>1,'set_ids'=>['fleet_logo'],'target_site_ids'=>[2],'overwrite'=>1]);
    $expect($lost['sites'][2]['status'] === 'unknown' && strlen($lost['sites'][2]['transfer_id']) === 32
        && hash_file('sha256', $targetRoot . '/' . \FreePBX\modules\Ucmprovisioner\AssetFilename::content('g', 'fleet_logo', 'bmp')) === hash('sha256', $bmp), 'Lost catalog activation response must preserve actual files and report uncertainty with its transfer ID');
    $specialSync->loseActivationSite = 0;

    $transferIds = [str_repeat('a',32),str_repeat('b',32)];
    $payloads = [str_repeat('A',98320),str_repeat('B',98320)];
    foreach ($transferIds as $index=>$transferId) {
        $request = ['catalog_id'=>'fleet_logo','role'=>'source','transfer_id'=>$transferId,'offset'=>0,
            'content'=>base64_encode(substr($payloads[$index],0,98304)), 'expected_sha256'=>hash('sha256',$payloads[$index]),'expected_size'=>strlen($payloads[$index])];
        $invoke($specialSync, 'specialCatalogUploadChunk', $targetRoot, $request);
        $invoke($specialSync, 'specialCatalogUploadChunk', $targetRoot, $request); // exact replay
    }
    foreach ($transferIds as $index=>$transferId) {
        $invoke($specialSync, 'specialCatalogUploadChunk', $targetRoot,
            ['catalog_id'=>'fleet_logo','role'=>'source','transfer_id'=>$transferId,'offset'=>98304,'final'=>true,
             'content'=>base64_encode(substr($payloads[$index],98304)), 'expected_sha256'=>hash('sha256',$payloads[$index]),'expected_size'=>strlen($payloads[$index])]);
        $expect(file_get_contents($targetRoot.'/.catalog-sync/fleet_logo/'.$transferId.'/source.part') === $payloads[$index], 'Overlapping catalog transfers must retain independent bytes after a repeated chunk');
    }
    $sourceManifest = $invoke($specialSync,'specialCatalogGet',$sourceRoot,'http://site-1.test','fleet_logo',false)['manifest'];
    $oldDestination = $invoke($specialSync,'specialCatalogList',$targetRoot,'http://site-2.test')['catalogs'][0]['manifest_sha256'];
    $invoke($specialSync, 'specialCatalogRename', $targetRoot, 'http://site-2.test', ['catalog_id'=>'fleet_logo','name'=>'New operator edit','expected_manifest_sha256'=>$oldDestination]);
    $conflict = $invoke($specialSync, 'specialCatalogActivate', $targetRoot, 'http://site-2.test',
        ['catalog_id'=>'fleet_logo','transfer_id'=>str_repeat('c',32),'manifest'=>$sourceManifest,'overwrite'=>1,'expected_destination'=>$oldDestination]);
    $expect(($conflict['status'] ?? '') === 'conflict'
        && $invoke($specialSync,'specialCatalogList',$targetRoot,'http://site-2.test')['catalogs'][0]['name'] === 'New operator edit',
        'Catalog activation must reject a destination edited after the reviewed inventory');
    try {
        $invoke($specialSync, 'specialCatalogDownloadChunk', $sourceRoot,
            ['catalog_id'=>'fleet_logo','role'=>'source','manifest_sha256'=>str_repeat('0',64)]);
        $expect(false,'Source manifest changes must stop transfer');
    } catch (DomainException $expected) {}
    $deleted = $invoke($module, 'specialCatalogDelete', $tmp, 'rg_2026');
    $expect(empty($deleted['ok']) && is_file($tmp . '/' . \FreePBX\modules\Ucmprovisioner\AssetFilename::content('g', 'rg_base', 'bmp')), 'A catalog deletion without captured site and revision must fail closed');

    $validWav = $tmp . '/valid.wav';
    file_put_contents($validWav, 'RIFF' . pack('V', 4) . 'WAVE');
    $metadata = $invoke($module, 'mediaAudioMetadata', 'yealink', 'all', $validWav, 'wav');
    $expect(($metadata['validation_status'] ?? '') === 'verified' && ($metadata['media_kind'] ?? '') === 'audio', 'Audio list metadata did not verify a WAV container signature');

    $db = new PDO('sqlite::memory:');
    $db->exec('CREATE TABLE ucmp_templates (id INTEGER, site_id INTEGER, name TEXT, setting_overrides TEXT)');
    $db->exec('CREATE TABLE ucmp_settings (skey TEXT, sval TEXT, site_id INTEGER)');
    $db->exec("INSERT INTO ucmp_templates VALUES (1,1,'Lobby','{\"ringtone\":\"valid.wav\"}')");
    $property = new ReflectionProperty(Ucmprovisioner::class, 'db');
    $property->setAccessible(true); $property->setValue($module, $db);
    $usage = $invoke($module, 'mediaAssetUsageIndex', 1);
    $expect(($usage['valid.wav'] ?? []) === ['Template: Lobby'], 'Audio usage metadata did not identify its template reference');
    $brokenModule = (new ReflectionClass(Ucmprovisioner::class))->newInstanceWithoutConstructor();
    $brokenDb = new PDO('sqlite::memory:');
    $brokenDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $brokenDb->exec('CREATE TABLE ucmp_templates (id INTEGER, site_id INTEGER, name TEXT, setting_overrides TEXT)');
    $property->setValue($brokenModule, $brokenDb);
    try {
        $invoke($brokenModule, 'mediaAssetUsageIndex', 1);
        $expect(false, 'A partial media usage query was presented as a valid index');
    } catch (PDOException $expected) {
        // A visible catalog failure is safer than falsely labelling managed assets unused.
    }
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') { $remove($path . '/' . $entry); }
            }
            rmdir($path);
        } elseif (is_file($path)) { unlink($path); }
    };
    if (is_dir($tmp)) { $remove($tmp); }
}

echo "Media backend contract fixtures passed\n";
