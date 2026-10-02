<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/FirmwareService.php';
require_once dirname(__DIR__) . '/lib/AssetCatalog.php';
require_once dirname(__DIR__) . '/lib/AssetFileInspector.php';
require_once dirname(__DIR__) . '/lib/ManagedAssetRegistry.php';
require_once dirname(__DIR__) . '/lib/AssetLifecycleService.php';
require_once dirname(__DIR__) . '/lib/ManagedAssetScanJob.php';
require_once dirname(__DIR__) . '/lib/TftpStore.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry;
use FreePBX\modules\Ucmprovisioner\ModelCatalog;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

final class WallpaperSftpStub
{
    /** @var array<string,string> */
    public array $files = [];
    public array $modes = [];
    /** @var array<string,bool> */
    public array $directories = ['/tftp'=>true];
    /** @var string[] */
    public array $putLog = []; // Publication order: direct puts and staged renames.
    public ?string $failRenameTarget = null;
    public ?string $failRenameSource = null;

    public function is_dir(string $path): bool { return isset($this->directories[rtrim($path, '/')]); }
    public function file_exists(string $path): bool { return isset($this->files[$path]) || $this->is_dir($path); }
    public function stat(string $path): array|false
    {
        if (isset($this->files[$path])) { return ['type'=>1, 'size'=>strlen($this->files[$path]), 'mode'=>$this->modes[$path] ?? 0664]; }
        if ($this->is_dir($path)) { return ['type'=>2, 'size'=>0]; }
        return false;
    }
    public function mkdir(string $path): bool
    {
        $this->directories[rtrim($path, '/')] = true;
        return true;
    }
    public function lstat(string $path): array|false { return $this->stat($path); }
    public function rawlist(string $path): array|false
    {
        $names=$this->nlist($path);if ($names===false) { return false; }$rows=[];
        foreach ($names as $name) { if ($name!=='.'&&$name!=='..') { $rows[$name]=$this->stat(rtrim($path,'/').'/'.$name); } }
        return $rows;
    }
    public function chmod(int $mode, string $path): bool { $this->modes[$path]=$mode; return true; }
    public function put(string $path, string $content, int $mode = 0): bool
    {
        if ($mode === \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE && is_file($content)) {
            $content = (string)file_get_contents($content);
        }
        $this->putLog[] = $path;
        $this->files[$path] = $content;
        return true;
    }
    public function get(string $path, mixed $local = false, int $offset = 0, int $length = -1): string|bool
    {
        if (!isset($this->files[$path])) { return false; }
        $content = $length < 0
            ? substr($this->files[$path], $offset)
            : substr($this->files[$path], $offset, $length);
        if (is_string($local) && $local !== '') { return file_put_contents($local, $content) !== false; }
        return $content;
    }
    public function delete(string $path): bool
    {
        if (!isset($this->files[$path])) { return false; }
        unset($this->files[$path]);
        return true;
    }
    public function rename(string $from, string $to): bool
    {
        if ($from===$this->failRenameSource) { $this->failRenameSource=null;return false; }
        if ($this->failRenameTarget !== null && $to === $this->failRenameTarget) {
            $this->failRenameTarget = null;
            return false;
        }
        $from = rtrim($from, '/');
        $to = rtrim($to, '/');
        if ($this->is_dir($from)) {
            $directories = array_keys($this->directories);
            $files = array_keys($this->files);
            foreach ($directories as $directory) {
                if ($directory === $from || str_starts_with($directory, $from . '/')) {
                    $this->directories[$to . substr($directory, strlen($from))] = true;
                    unset($this->directories[$directory]);
                }
            }
            foreach ($files as $file) {
                if (str_starts_with($file, $from . '/')) {
                    $this->files[$to . substr($file, strlen($from))] = $this->files[$file];
                    unset($this->files[$file]);
                }
            }
            return true;
        }
        if (!isset($this->files[$from])) { return false; }
        $this->putLog[] = $to;
        $this->files[$to] = $this->files[$from];
        $this->modes[$to] = $this->modes[$from] ?? 0664;
        unset($this->files[$from]);
        return true;
    }
    public function posix_rename(string $from, string $to): bool { return $this->rename($from, $to); }
    public function rmdir(string $path): bool
    {
        $path = rtrim($path, '/');
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $path . '/')) { return false; }
        }
        unset($this->directories[$path]);
        return true;
    }
    /** @return string[]|false */
    public function nlist(string $path): array|false
    {
        $path = rtrim($path, '/');
        if (!$this->is_dir($path)) { return false; }
        $items = ['.', '..'];
        foreach (array_keys($this->directories) as $directory) {
            if ($directory !== $path && dirname($directory) === $path) { $items[] = basename($directory); }
        }
        foreach (array_keys($this->files) as $file) {
            if (dirname($file) === $path) { $items[] = basename($file); }
        }
        return array_values(array_unique($items));
    }
}

final class WallpaperConnectionStub
{
    public function __construct(private WallpaperSftpStub $sftp, private string $root) {}
    public function dockerSftp(): WallpaperSftpStub { return $this->sftp; }
    public function tftpRoot(): string { return $this->root; }
}

final class WallpaperUcmStub
{
    /** @var array<string,string> */
    public array $media = [];
    public array $retiredMedia = [];
    public string $loseSyncModel = '';
    public string $failSourceAfterModel = '';
    public bool $sourceUnavailable = false;
    public bool $loseReferenceResponse = false;
    public string $loseUploadModel = '';
    public array $mediaMutations = [];
    public ?Closure $duringSync = null;
    public int $referenceCalls = 0;
    public ?Closure $hostedOperation = null;
    /** @param array<int,array{0:WallpaperSftpStub,1:string}> $sites */
    public function __construct(private PDO $db, public array $sites) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function siteConnection(int $siteId): WallpaperConnectionStub
    {
        if ($siteId===1 && $this->sourceUnavailable) { throw new RuntimeException('fixture source unavailable after hosted copy'); }
        if (!isset($this->sites[$siteId])) { throw new RuntimeException("Unknown test site $siteId"); }
        return new WallpaperConnectionStub($this->sites[$siteId][0], $this->sites[$siteId][1]);
    }
    public function mediaAssetOp(array $request): array
    {
        if ($this->hostedOperation !== null) { return ($this->hostedOperation)($request); }
        $site = (int)($request['site_id'] ?? 1);
        $family = (string)($request['family'] ?? '');
        $model = (string)($request['model'] ?? 'all');
        $prefix = "$site|$family|$model|";
        $op = (string)($request['op'] ?? 'list');
        if ($op === 'list') {
            $items = [];
            foreach ($this->media as $key=>$content) {
                if (!str_starts_with($key, $prefix)) { continue; }
                $name = substr($key, strlen($prefix));
                $items[] = [
                    'name'=>$name, 'size'=>strlen($content), 'mtime'=>1,
                    'url'=>"http://site-$site/ucmp-m.php/$family/$model/$name",
                ];
            }
            return ['ok'=>true, 'items'=>$items];
        }
        if ($op === 'sync') {
            if ($this->duringSync !== null) { ($this->duringSync)(); }
            $selected = array_key_exists('names', $request)
                ? array_fill_keys((array)$request['names'], true)
                : null;
            $sites = [];
            foreach ((array)($request['target_site_ids'] ?? []) as $targetSite) {
                $copied = 0;
                foreach ($this->media as $sourceKey=>$content) {
                    if (!str_starts_with($sourceKey, $prefix)) { continue; }
                    $sourceName = substr($sourceKey, strlen($prefix));
                    if ($selected !== null && !isset($selected[$sourceName])) { continue; }
                    $this->media[(int)$targetSite . "|$family|$model|$sourceName"] = $content;
                    $copied++;
                }
                $sites[(string)$targetSite] = ['ok'=>true,'copied'=>$copied,'skipped'=>0,'message'=>"Copied $copied file(s)"];
            }
            if ($model===$this->loseSyncModel) { throw new RuntimeException('fixture hosted response lost after copying'); }
            if ($model===$this->failSourceAfterModel) { $this->sourceUnavailable=true; }
            return ['ok'=>true,'sites'=>$sites,'message'=>'Test media synchronized'];
        }
        $name = (string)($request['name'] ?? '');
        $key = $prefix . $name;
        if ($op === 'upload') {
            $content = base64_decode((string)($request['content'] ?? ''), true);
            if ($content === false) { return ['ok'=>false, 'message'=>'Invalid test upload']; }
            $this->media[$key] = $content;
            $this->mediaMutations[] = ['op'=>$op,'key'=>$key];
            if ($model === $this->loseUploadModel) { throw new RuntimeException('Injected lost hosted upload reply'); }
            return ['ok'=>true, 'name'=>$name, 'url'=>"http://site-$site/ucmp-m.php/$family/$model/$name"];
        }
        if ($op === 'download') {
            return isset($this->media[$key])
                ? ['ok'=>true, 'name'=>$name, 'content'=>base64_encode($this->media[$key])]
                : ['ok'=>false, 'message'=>'Media file was not found'];
        }
        if ($op === 'delete') {
            $this->mediaMutations[] = ['op'=>$op,'key'=>$key];
            if (!isset($this->media[$key])) { return ['ok'=>false, 'message'=>'Media file was not found']; }
            unset($this->media[$key]);
            return ['ok'=>true, 'message'=>'Media file deleted'];
        }
        return ['ok'=>false, 'message'=>"Unsupported test media op $op"];
    }
    public function wallpaperHostedRetirementLocal(int $siteId,string $family,string $model): object
    {
        return new class($this,$siteId,$family,$model) {
            public function __construct(private WallpaperUcmStub $ucm,private int $site,private string $family,private string $model) {}
            public function asset(string $name): array {
                $bytes=$this->ucm->media[$this->site.'|'.$this->family.'|'.$this->model.'|'.$name];$hash=hash('sha256',$bytes);
                return ['asset_key'=>hash('sha256',"http\0".$this->family.'/'.$this->model.'/'.$name."\0".$hash),'filename'=>$name,'kind'=>'background','content_sha256'=>$hash,'size_bytes'=>strlen($bytes),'metadata'=>[]];
            }
            public function preview(int $site,string $name,array $external): array {
                $asset=$this->asset($name);$refs=$external['references'][$asset['asset_key']]??[];
                return ['can_retire'=>!$refs,'references'=>$refs,'revision'=>$asset['content_sha256'],'blockers'=>[]];
            }
            public function retire(int $site,string $name,array $external,string $revision): array {
                if ($revision!==$this->asset($name)['content_sha256']) { throw new RuntimeException('Stale hosted bytes'); }
                $key=$site.'|'.$this->family.'|'.$this->model.'|'.$name;$this->ucm->retiredMedia[$key]=$this->ucm->media[$key];unset($this->ucm->media[$key]);
                return ['ok'=>true,'recovery_id'=>'fixture'];
            }
        };
    }
    public function replaceWallpaperReferences(int $siteId, array $replacements): array
    {
        $this->referenceCalls++;
        if ($this->loseReferenceResponse) { throw new RuntimeException('Injected lost reference response'); }
        return [
            'ok'=>true,
            'settings_changed'=>0,
            'templates_changed'=>0,
            'replacements'=>$replacements,
        ];
    }
}

$profiles = ModelCatalog::backgroundProfilesForDirectory('800x480x24');
$expect(count($profiles) === 1, 'The 800x480x24 directory must expose only the exact 88xx canvas profile');
$profile800 = ModelCatalog::backgroundProfileForDimensions('800x480x24', [800,480], [139,109]);
$profile640 = ModelCatalog::backgroundProfileForDimensions('640x480x24', [640,480], [123,111]);
$profileGray = ModelCatalog::wallpaperProfileForModel('Cisco', '7941');
$profileGray['dimensions'] = [
    (int)$profileGray['width'], (int)$profileGray['height'],
    (int)$profileGray['thumbnail_width'], (int)$profileGray['thumbnail_height'],
];
$expect($profile800 !== null && $profile640 !== null && $profile800['key'] !== $profile640['key'], 'Official 88xx and 894x directories must resolve to distinct profile keys');
$expect(($profileGray['grayscale_tones'] ?? 0) === 16, 'Cisco 7941 fixture must use the 16-tone grayscale profile');
$expect(ModelCatalog::backgroundDir('Cisco', '8945') === '640x480x24', '8941/8945 must use Cisco official 640x480x24 directory');
$expect(ModelCatalog::backgroundProfileForDimensions('800x480x24', [700,480], [123,111]) === null, 'Undocumented dimensions must fail closed');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ManagedAssetRegistry::ensureSchema($db);
$db->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY,site_id INTEGER,skey TEXT,sval TEXT)');
$db->exec('CREATE TABLE ucmp_templates (id INTEGER PRIMARY KEY,site_id INTEGER,name TEXT,phonebook_filename TEXT,setting_overrides TEXT)');
$db->exec('CREATE TABLE ucmp_phones (id INTEGER PRIMARY KEY,site_id INTEGER,mac TEXT,extension TEXT,overrides TEXT)');
$db->exec('CREATE TABLE ucmp_phonebooks (id INTEGER PRIMARY KEY,name TEXT,filename TEXT,publish_sites TEXT)');
$db->exec('CREATE TABLE ucmp_site_jobs (id INTEGER PRIMARY KEY,site_id INTEGER,job_type TEXT,object_key TEXT,payload_ref TEXT,state TEXT)');
foreach (['media','firmware','configuration'] as $type) {
    $db->exec('CREATE TABLE ucmp_'.$type.'_'.($type==='configuration'?'sync':'replication').'_jobs (id INTEGER PRIMARY KEY,name TEXT,source_site_id INTEGER,enabled INTEGER,scopes_json TEXT,targets_json TEXT,last_status TEXT)');
}
$db->exec('CREATE TABLE ucmp_configuration_sync_items (id INTEGER PRIMARY KEY,job_id INTEGER,target_site_id INTEGER,object_type TEXT,object_key TEXT,status TEXT,intended_json TEXT)');
$db->exec('ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN packages_json TEXT');
$db->exec('ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN timezone_bundle_key TEXT');
foreach (['firmware','timezones','locales'] as $kind) { $db->exec('ALTER TABLE ucmp_firmware_replication_jobs ADD COLUMN include_all_'.$kind.' INTEGER'); }
$db->exec('CREATE TABLE IF NOT EXISTS ucmp_asset_scan_jobs (site_id INTEGER)');
$sftp = new WallpaperSftpStub();
$ucm = new WallpaperUcmStub($db, [1=>[$sftp, '/tftp']]);
$store = new TftpStore($ucm);

$invoke = static function (object $object, string $method, mixed ...$arguments): mixed {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($object, ...$arguments);
};
$png = static fn(int $width, int $height): string =>
    "\x89PNG\r\n\x1a\n"
    . pack('N', 13) . 'IHDR'
    . pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)
    . pack('N', 0)
    . pack('N', 0) . 'IDAT' . pack('N', 0)
    . pack('N', 0) . 'IEND' . pack('N', 0);

$staleSource = "\xff\xd8\xffSTALE";
$recoverySftp = new WallpaperSftpStub();
$staleCreated = $invoke($store, 'wallpaperSetSource', $recoverySftp, '/tftp', 'RG_Base_2026', 'RG Base 2026', base64_encode($staleSource), 'image/jpeg', 'create');
$expect(!empty($staleCreated['ok']), 'Could not create stale-manifest replacement fixture');
$staleManifestPath = '/tftp/.ucmprovisioner/wallpaper-sets/RG_Base_2026/manifest.json';
$staleManifest = json_decode($recoverySftp->files[$staleManifestPath], true, 64, JSON_THROW_ON_ERROR);
$staleManifest['variants'] = ['removed-profile'=>[
    'profile'=>'removed-profile', 'dir'=>'removed-profile', 'models'=>['REMOVED'],
]];
$recoverySftp->files[$staleManifestPath] = json_encode($staleManifest, JSON_THROW_ON_ERROR);
$replacement = $invoke($store, 'wallpaperSetSource', $recoverySftp, '/tftp', 'RG_Base_2026', 'RG Base 2026', base64_encode("\xff\xd8\xffNEW"), 'image/jpeg', 'create');
$retiredDirectories = array_values(array_filter(
    array_keys($recoverySftp->directories),
    static fn(string $path): bool => str_starts_with($path, '/tftp/.ucmprovisioner/wallpaper-sets-retired/RG_Base_2026-')
));
$retiredManifestPath = ($retiredDirectories[0] ?? '') . '/manifest.json';
$retiredManifest = json_decode($recoverySftp->files[$retiredManifestPath] ?? '{}', true);
$expect(
    !empty($replacement['ok'])
    && count($retiredDirectories) === 1
    && str_contains((string)($replacement['retired_path'] ?? ''), 'wallpaper-sets-retired/RG_Base_2026-')
    && isset($retiredManifest['variants']['removed-profile'])
    && is_array(json_decode($recoverySftp->files[$staleManifestPath] ?? '', true)),
    'Explicit create must recoverably retire an incompatible hidden manifest before reusing its catalog ID'
);

foreach (['alpha','beta'] as $id) {
    $source = "\xff\xd8\xff" . strtoupper($id);
    $result = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', $id, ucfirst($id), base64_encode($source), 'image/jpeg', 'create');
    $expect(($result['ok'] ?? false) === true, "Could not retain $id source");
}

$writeManifest = static function (
    WallpaperSftpStub $sftp,
    string $id,
    string $name,
    string $sourceHash,
    array $variants
): void {
    $path = "/tftp/.ucmprovisioner/wallpaper-sets/$id/manifest.json";
    $manifest = json_decode($sftp->files[$path], true, 64, JSON_THROW_ON_ERROR);
    $manifest['version'] = 1;
    $manifest['name'] = $name;
    $manifest['source_sha256'] = $sourceHash;
    $manifest['variants'] = $variants;
    $manifest['updated'] = 100;
    $sftp->files[$path] = json_encode($manifest, JSON_THROW_ON_ERROR);
};
$variant = static function (string $id, array $profile, string $sourceHash): array {
    $base = $id . '-' . $profile['dimensions'][0] . 'x' . $profile['dimensions'][1];
    return [
        'profile'=>$profile['key'], 'dir'=>$profile['dir'],
        'full'=>"$base.png", 'thumbnail'=>"$base-tn.png",
        'full_transform'=>['zoom'=>100,'x'=>0,'y'=>0],
        'thumbnail_transform'=>['zoom'=>120,'x'=>10,'y'=>-10],
        'source_sha256'=>$sourceHash,
    ];
};

$graySource = "\xff\xd8\xffGRAY";
$grayCreated = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', 'gray', 'Gray', base64_encode($graySource), 'image/jpeg', 'create');
$expect(!empty($grayCreated['ok']), 'Could not retain grayscale inversion fixture');
$grayVariant = $variant('gray', $profileGray, hash('sha256', $graySource));
$grayVariant['invert'] = true;
$writeManifest($sftp, 'gray', 'Gray', hash('sha256', $graySource), [$profileGray['key']=>$grayVariant]);
$grayRead = $store->handle(['op'=>'wallpaper_set_get', 'site_id'=>1, 'set_id'=>'gray']);
$expect(
    ($grayRead['manifest']['variants'][$profileGray['key']]['invert'] ?? false) === true,
    'Managed grayscale inversion must survive manifest normalization and editor reload'
);
$graySetPath = '/tftp/.ucmprovisioner/wallpaper-sets/gray';
unset(
    $sftp->files[$graySetPath . '/manifest.json'],
    $sftp->files[$graySetPath . '/' . (string)$grayCreated['manifest']['source']]
);
$expect($sftp->rmdir($graySetPath), 'Could not clean up grayscale inversion fixture');

$alphaHash = hash('sha256', "\xff\xd8\xffALPHA");
$betaHash = hash('sha256', "\xff\xd8\xffBETA");
$alpha800 = $variant('alpha', $profile800, $alphaHash);
$alpha800['full_transform']['zoom'] = 60;
$alpha640 = $variant('alpha', $profile640, $alphaHash);
$beta800 = $variant('beta', $profile800, $betaHash);
$writeManifest($sftp, 'alpha', 'Alpha', $alphaHash, [$profile800['key']=>$alpha800, $profile640['key']=>$alpha640]);
$writeManifest($sftp, 'beta', 'Beta', $betaHash, [$profile800['key']=>$beta800]);
$sftp->mkdir('/tftp/Desktops');
$sftp->mkdir('/tftp/Desktops/800x480x24');
$sftp->mkdir('/tftp/Desktops/640x480x24');
foreach ([[$alpha800,[800,480],[139,109]],[$alpha640,[640,480],[123,111]],[$beta800,[800,480],[139,109]]] as [$record,$full,$thumb]) {
    $sftp->files['/tftp/Desktops/' . $record['dir'] . '/' . $record['full']] = $png($full[0], $full[1]);
    $sftp->files['/tftp/Desktops/' . $record['dir'] . '/' . $record['thumbnail']] = $png($thumb[0], $thumb[1]);
}

$list = $invoke($store, 'wallpaperSetList', 1, $sftp, '/tftp');
$expect(($list['ok'] ?? false) === true && count($list['sets'] ?? []) === 2, 'Linked wallpaper set list must return both sets');
$alphaManifestPath = '/tftp/.ucmprovisioner/wallpaper-sets/alpha/manifest.json';
$originalManifest = $sftp->files[$alphaManifestPath];
$hashedManifest = json_decode($originalManifest,true,512,JSON_THROW_ON_ERROR);
$outputPath = '/tftp/Desktops/' . $alpha800['dir'] . '/' . $alpha800['full'];
$originalOutput = $sftp->files[$outputPath];
$hashedManifest['variants'][$profile800['key']]['full_sha256'] = hash('sha256',$originalOutput);
$sftp->files[$alphaManifestPath] = json_encode($hashedManifest,JSON_THROW_ON_ERROR);
$sftp->files[$outputPath] .= 'changed-after-header';
$tampered = $invoke($store,'wallpaperSetList',1,$sftp,'/tftp');
$tamperedAlpha = array_values(array_filter($tampered['sets'],static fn(array $set): bool=>$set['id']==='alpha'))[0];
$expect(!$tamperedAlpha['complete'] && str_contains(implode('; ',$tamperedAlpha['issues']),'content differs'), 'Correct PNG dimensions must not validate bytes that changed after the header');
$sftp->files[$outputPath] = $originalOutput;$sftp->files[$alphaManifestPath]=$originalManifest;
$masterPath = '/tftp/.ucmprovisioner/wallpaper-sets/alpha/' . $hashedManifest['source'];
$originalMaster = $sftp->files[$masterPath];$sftp->files[$masterPath] .= 'tampered';
$tamperedMaster = $store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'alpha']);
$expect(!$tamperedMaster['ok'] && str_contains($tamperedMaster['message'],'master source'), 'Opening a catalog must reject a changed master source');
$sftp->files[$masterPath] = $originalMaster;
$legacyRead = $store->handle(['op'=>'wallpaper_set_get', 'site_id'=>1, 'set_id'=>'alpha']);
$legacyVariant = $legacyRead['manifest']['variants'][$profile800['key']] ?? [];
$expect(
    ($legacyRead['manifest']['version'] ?? 0) === 2
    && ($legacyVariant['enabled'] ?? null) === true
    && ($legacyVariant['source'] ?? '') === ($legacyRead['manifest']['source'] ?? '')
    && ($legacyVariant['rendered_source_sha256'] ?? '') === $alphaHash
    && ($legacyVariant['full_transform']['zoom'] ?? 0) === 100,
    'Version-1 wallpaper manifests must normalize to v2 defaults without accepting sub-100 legacy zoom'
);
$choices640 = $invoke($store, 'wallpaperChoices', 1, $sftp, '/tftp', $profile640['key']);
$expect(count($choices640['choices'] ?? []) === 1 && ($choices640['choices'][0]['set_id'] ?? '') === 'alpha', 'Exact 8941 profile must use its official directory');
$choices800 = $invoke($store, 'wallpaperChoices', 1, $sftp, '/tftp', $profile800['key']);
$expect(count($choices800['choices'] ?? []) === 2, 'Exact 88xx profile must retain both matching linked sets');
$expect(!array_filter($choices800['choices'], static fn(array $c): bool => empty($c['selectable'])), 'Verified current Cisco catalog outputs must be selectable');
$sftp->files['/tftp/Desktops/800x480x24/loose.png']=$sftp->files['/tftp/Desktops/800x480x24/'.$beta800['full']];
$sftp->files['/tftp/Desktops/800x480x24/loose-tn.png']=$sftp->files['/tftp/Desktops/800x480x24/'.$beta800['thumbnail']];
$looseChoices=$invoke($store,'wallpaperChoices',1,$sftp,'/tftp',$profile800['key']);
$looseChoice=array_values(array_filter($looseChoices['choices'],static fn(array $c): bool => $c['filename']==='loose.png'))[0];
$expect(!$looseChoice['selectable'] && !$looseChoice['current'], 'Valid loose Cisco pairs must be observable for cleanup, not new catalog choices');
unset($sftp->files['/tftp/Desktops/800x480x24/loose.png'],$sftp->files['/tftp/Desktops/800x480x24/loose-tn.png']);

$betaFullPath = '/tftp/Desktops/800x480x24/' . $beta800['full'];
$betaThumbPath = '/tftp/Desktops/800x480x24/' . $beta800['thumbnail'];
$betaFull = $sftp->files[$betaFullPath];
$betaThumb = $sftp->files[$betaThumbPath];
unset($sftp->files[$betaFullPath], $sftp->files[$betaThumbPath]);
$stale = $invoke($store, 'wallpaperSetList', 1, $sftp, '/tftp');
$staleBeta = array_values(array_filter($stale['sets'], static fn(array $set): bool => $set['id'] === 'beta'))[0];
$expect($staleBeta['current_variant_count'] === 0 && !$staleBeta['complete'] && $staleBeta['issues'], 'Deleted Desktops files must make a linked set incomplete with zero current variants and explicit issues');
$sftp->files[$betaFullPath] = $betaFull;
$sftp->files[$betaThumbPath] = $betaThumb;

$duplicate = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', 'alpha', 'Another Alpha', base64_encode("\xff\xd8\xffDUP"), 'image/jpeg', 'create');
$expect(empty($duplicate['ok']), 'Explicit create intent must reject a sanitized set-ID collision');

$oldManifestPath = '/tftp/.ucmprovisioner/wallpaper-sets/beta/manifest.json';
$oldManifest = $sftp->files[$oldManifestPath];
$oldSourceName = json_decode($oldManifest, true, 64, JSON_THROW_ON_ERROR)['source'];
$faultSftp = clone $sftp;
$faultSftp->failRenameTarget = $oldManifestPath;
try {
    $invoke($store, 'wallpaperSetSource', $faultSftp, '/tftp', 'beta', 'Beta', base64_encode("\xff\xd8\xffBETA2"), 'image/jpeg', 'update');
    throw new RuntimeException('Expected manifest activation failure was not raised');
} catch (RuntimeException $error) {
    $expect($error instanceof FreePBX\modules\Ucmprovisioner\RpcOperationException && !empty($error->receipt['recovery_required']), 'An unconfirmed manifest activation must retain a recovery receipt');
    $manifestRecovery = $error->receipt['file_replacements'][0]['files'][$oldManifestPath];
}
$expect(($faultSftp->files[$manifestRecovery['backup']] ?? '') === $oldManifest, 'Unconfirmed replacement must preserve the prior manifest without reverse writes');
$expect(isset($faultSftp->files[$manifestRecovery['staged']]), 'Unconfirmed replacement must retain its candidate manifest');
$expect(isset($faultSftp->files['/tftp/.ucmprovisioner/wallpaper-sets/beta/' . $oldSourceName]), 'Failed source replacement removed the prior retained source');

$sftp->files['/tftp/Desktops/800x480x24/gamma-800x480.png'] = $png(800, 480);
$collision = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', 'gamma', 'Gamma', base64_encode("\xff\xd8\xffGAMMA"), 'image/jpeg', 'create');
$expect(empty($collision['ok']) && !isset($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/gamma/manifest.json']), 'Create preflight must reject a deterministic legacy filename without leaving an empty linked set');

$delta = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', 'delta', 'Delta', base64_encode("\xff\xd8\xffDELTA"), 'image/jpeg', 'create');
$expect(!empty($delta['ok']), 'Could not create batch-validation fixture');
$invalidColorInvert = $store->handle([
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'delta', 'set_name'=>'Delta',
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'delta'])['manifest']['editor_revision'],
    'pairs'=>json_encode([[
        'profile'=>$profile800['key'], 'dir'=>$profile800['dir'], 'models'=>['8841'], 'invert'=>1,
        'full'=>base64_encode($png(800,480)), 'thumb'=>base64_encode($png(139,109)),
    ]], JSON_THROW_ON_ERROR),
]);
$expect(
    empty($invalidColorInvert['ok']) && str_contains((string)($invalidColorInvert['message'] ?? ''), 'Color wallpaper profiles'),
    'Grayscale inversion must fail closed for a color wallpaper profile'
);
$invalidBatch = $store->handle([
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'delta', 'set_name'=>'Delta',
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'delta'])['manifest']['editor_revision'],
    'pairs'=>json_encode([
        ['profile'=>$profile800['key'], 'dir'=>'800x480x24', 'name'=>'ignored', 'full'=>base64_encode($png(800,480)), 'thumb'=>base64_encode($png(139,109))],
        ['profile'=>$profile640['key'], 'dir'=>'640x480x24', 'name'=>'ignored', 'full'=>base64_encode($png(640,480)), 'thumb'=>base64_encode($png(1,1))],
    ], JSON_THROW_ON_ERROR),
]);
$expect(empty($invalidBatch['ok']) && !isset($sftp->files['/tftp/Desktops/800x480x24/delta-800x480.png']), 'An invalid later pair must not allow an earlier pair to be written');

$target = new WallpaperSftpStub();
$target->directories = ['/target'=>true, '/target/Desktops'=>true, '/target/Desktops/800x480x24'=>true];
$target->files['/target/Desktops/800x480x24/Target.png'] = $png(800,480);
$target->files['/target/Desktops/800x480x24/Target-tn.png'] = $png(139,109);
$target->files['/target/Desktops/800x480x24/List.xml'] = 'TARGET OLD LIST';
$sftp->files['/tftp/Desktops/800x480x24/List.xml'] = 'SOURCE LIST MUST NOT BE COPIED';
$ucm->sites[2] = [$target, '/target'];

$selectedTarget = new WallpaperSftpStub();
$selectedTarget->directories = ['/selected'=>true];
$ucm->sites[3] = [$selectedTarget, '/selected'];
$selectedSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'wallpapers',
    'set_ids'=>['alpha'], 'target_site_ids'=>[3], 'overwrite'=>1,
]);
$expect(!empty($selectedSync['ok']) && ($selectedSync['set_ids'] ?? []) === ['alpha'], 'Exact wallpaper catalog synchronization failed');
$expect(
    isset(
        $selectedTarget->files['/selected/.ucmprovisioner/wallpaper-sets/alpha/manifest.json'],
        $selectedTarget->files['/selected/Desktops/800x480x24/' . $alpha800['full']],
        $selectedTarget->files['/selected/Desktops/640x480x24/' . $alpha640['full']]
    )
    && !isset(
        $selectedTarget->files['/selected/.ucmprovisioner/wallpaper-sets/beta/manifest.json'],
        $selectedTarget->files['/selected/Desktops/800x480x24/' . $beta800['full']]
    ),
    'Exact wallpaper synchronization copied an unselected catalog'
);
$manifestPut = array_search('/selected/.ucmprovisioner/wallpaper-sets/alpha/manifest.json', $selectedTarget->putLog, true);
$indexPut = array_search('/selected/Desktops/800x480x24/List.xml', $selectedTarget->putLog, true);
$expect(is_int($manifestPut) && is_int($indexPut) && $manifestPut < $indexPut, 'Selected catalog must activate its manifest before rebuilding the phone-facing vendor index');
$conflictTarget = new WallpaperSftpStub();
$conflictTarget->directories = ['/conflict'=>true, '/conflict/Desktops'=>true, '/conflict/Desktops/800x480x24'=>true];
$conflictPath = '/conflict/Desktops/800x480x24/' . $alpha800['full'];
$conflictTarget->files[$conflictPath] = 'TARGET MANUAL OVERRIDE';
$ucm->sites[7] = [$conflictTarget, '/conflict'];
$conflictSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'wallpapers',
    'set_ids'=>['alpha'], 'target_site_ids'=>[7], 'overwrite'=>0,
]);
$expect(
    empty($conflictSync['ok'])
    && empty($conflictSync['sites']['7']['ok'])
    && str_contains((string)($conflictSync['sites']['7']['message'] ?? ''), 'differs from source')
    && ($conflictTarget->files[$conflictPath] ?? '') === 'TARGET MANUAL OVERRIDE'
    && !isset($conflictTarget->files['/conflict/.ucmprovisioner/wallpaper-sets/alpha/manifest.json']),
    'Preserve-existing selected sync must reject different bytes without overwriting the target or activating its manifest'
);
$unknownSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'wallpapers',
    'set_ids'=>['does-not-exist'], 'target_site_ids'=>[3], 'overwrite'=>1,
]);
$expect(empty($unknownSync['ok']) && str_contains((string)$unknownSync['message'], 'Unknown wallpaper catalog'), 'Unknown selected wallpaper IDs must fail closed');
$sftp->files[$betaFullPath] = $betaFull;
unset($sftp->files[$betaThumbPath]);
$incompleteSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'wallpapers',
    'set_ids'=>['beta'], 'target_site_ids'=>[3], 'overwrite'=>1,
]);
$expect(empty($incompleteSync['ok']) && str_contains((string)$incompleteSync['message'], 'Incomplete wallpaper catalog'), 'Incomplete selected wallpaper catalogs must not partially synchronize');
$sftp->files[$betaThumbPath] = $betaThumb;

$sftp->files['/tftp/Alpha.raw'] = 'RAW ALPHA';
$sftp->files['/tftp/Beta.rwb'] = 'RWB BETA';
$sftp->files['/tftp/Unselected.raw'] = 'RAW UNSELECTED';
$ringTarget = new WallpaperSftpStub();
$ringTarget->directories = ['/ring-target'=>true];
$ucm->sites[5] = [$ringTarget, '/ring-target'];
$ringSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'ringtones',
    'names'=>['Alpha.raw', 'Beta.rwb'], 'target_site_ids'=>[5], 'overwrite'=>1,
]);
$expect(
    !empty($ringSync['ok'])
    && ($ringSync['names'] ?? []) === ['Alpha.raw', 'Beta.rwb']
    && isset(
        $ringTarget->files['/ring-target/Alpha.raw'],
        $ringTarget->files['/ring-target/Beta.rwb'],
        $ringTarget->files['/ring-target/Ringlist.xml'],
        $ringTarget->files['/ring-target/Ringlist-wb.xml']
    )
    && !isset($ringTarget->files['/ring-target/Unselected.raw']),
    'Exact ringtone synchronization copied the wrong source set'
);
$expect(
    str_contains($ringTarget->files['/ring-target/Ringlist.xml'], 'Alpha.raw')
    && !str_contains($ringTarget->files['/ring-target/Ringlist.xml'], 'Unselected.raw')
    && str_contains($ringTarget->files['/ring-target/Ringlist-wb.xml'], 'Beta.rwb'),
    'Selected ringtone indexes must be regenerated from target files, not copied from the broader source library'
);
$alphaPut = array_search('/ring-target/Alpha.raw', $ringTarget->putLog, true);
$betaPut = array_search('/ring-target/Beta.rwb', $ringTarget->putLog, true);
$ringListPut = array_search('/ring-target/Ringlist.xml', $ringTarget->putLog, true);
$wideRingListPut = array_search('/ring-target/Ringlist-wb.xml', $ringTarget->putLog, true);
$expect(
    is_int($alphaPut) && is_int($betaPut) && is_int($ringListPut) && is_int($wideRingListPut)
    && $alphaPut < $ringListPut && $betaPut < $wideRingListPut,
    'Cisco ringtone indexes must be published after their selected payloads'
);
$unknownRingSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'ringtones',
    'names'=>['Missing.raw'], 'target_site_ids'=>[5], 'overwrite'=>1,
]);
$expect(
    empty($unknownRingSync['ok']) && str_contains((string)$unknownRingSync['message'], 'Unknown ringtone filename'),
    'Unknown selected ringtone filenames must fail before synchronization'
);
$sftp->files['/tftp/Ringlist.xml'] = '<CiscoIPPhoneRingList>legacy broad sync</CiscoIPPhoneRingList>';
$sftp->files['/tftp/Ringlist-wb.xml'] = '<CiscoIPPhoneRingList>legacy broad sync</CiscoIPPhoneRingList>';
$broadRingTarget = new WallpaperSftpStub();
$broadRingTarget->directories = ['/broad-ring-target'=>true];
$ucm->sites[6] = [$broadRingTarget, '/broad-ring-target'];
$broadRingSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'ringtones',
    'target_site_ids'=>[6], 'overwrite'=>1,
]);
$expect(
    !empty($broadRingSync['ok']) && isset($broadRingTarget->files['/broad-ring-target/Unselected.raw']),
    'Omitting ringtone names must retain the existing whole-library synchronization behavior'
);

$sync = $store->handle(['op'=>'sync_cisco_media', 'site_id'=>1, 'scope'=>'wallpapers', 'target_site_ids'=>[2], 'overwrite'=>1]);
$expect(($sync['ok'] ?? false) === true, 'Linked wallpaper synchronization failed');
$targetList = $target->files['/target/Desktops/800x480x24/List.xml'] ?? '';
$expect(str_contains($targetList, 'Target.png') && str_contains($targetList, $alpha800['full']), 'Target List.xml rebuild did not preserve target-only entries and add synchronized sets');
$expect(!str_contains($targetList, 'SOURCE LIST MUST NOT BE COPIED'), 'Synchronization copied source List.xml instead of rebuilding the target catalog');
$expect(isset($target->files['/target/.ucmprovisioner/wallpaper-sets/alpha/manifest.json']), 'Synchronization omitted linked-set manifest');
$alphaSourceName = json_decode($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/alpha/manifest.json'], true, 64, JSON_THROW_ON_ERROR)['source'];
$expect(isset($target->files['/target/.ucmprovisioner/wallpaper-sets/alpha/' . $alphaSourceName]), 'Synchronization omitted retained linked-set source');

$omegaSourceBytes = "\xff\xd8\xffOMEGA";
$omegaSource = $invoke(
    $store,
    'wallpaperSetSource',
    $sftp,
    '/tftp',
    'omega',
    'Omega',
    base64_encode($omegaSourceBytes),
    'image/jpeg',
    'create'
);
$expect(!empty($omegaSource['ok']) && ($omegaSource['manifest']['version'] ?? 0) === 3, 'New wallpaper catalogs must emit cache-safe manifest v3');
$omegaRendered = $store->handle([
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'omega'])['manifest']['editor_revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'omega', 'set_name'=>'Omega',
    'pairs'=>json_encode([[
        'profile'=>$profile800['key'], 'dir'=>$profile800['dir'], 'models'=>['8841'],
        'full'=>base64_encode($png(800,480)), 'thumb'=>base64_encode($png(139,109)),
        'transform'=>['full'=>['zoom'=>220,'x'=>15,'y'=>-20], 'thumbnail'=>['zoom'=>100,'x'=>0,'y'=>0]],
    ]], JSON_THROW_ON_ERROR),
]);
$expect(!empty($omegaRendered['ok']), 'Could not publish the v2 wallpaper fixture');

$omegaCustomBytes = "\xff\xd8\xffOMEGA-CUSTOM";
$customSaved = $store->handle([
    'expected_revision'=>$omegaRendered['revision'],
    'op'=>'wallpaper_profile_source_save', 'site_id'=>1, 'set_id'=>'omega',
    'profile'=>$profile800['key'], 'source'=>base64_encode($omegaCustomBytes), 'mime'=>'image/jpeg',
]);
$expect(!empty($customSaved['ok']) && ($customSaved['source_mode'] ?? '') === 'custom', 'Profile source override was not retained');
$beforeStaleEdit=$sftp->files;
$staleEditor=$store->handle(['op'=>'wallpaper_profile_source_reset','site_id'=>1,'set_id'=>'omega',
    'profile'=>$profile800['key'],'expected_revision'=>$omegaRendered['revision']]);
$expect(empty($staleEditor['ok']) && !empty($staleEditor['conflict']) && $beforeStaleEdit===$sftp->files,
    'A stale editor must not overwrite a newer profile source or mutate its files');
$customName = (string)$customSaved['source'];
$customGet = $store->handle([
    'op'=>'wallpaper_profile_source_get', 'site_id'=>1, 'set_id'=>'omega', 'profile'=>$profile800['key'],
]);
$expect(
    !empty($customGet['ok'])
    && base64_decode((string)$customGet['source'], true) === $omegaCustomBytes
    && ($customGet['source_mode'] ?? '') === 'custom',
    'Profile source override could not be fetched independently of the master'
);
$omegaStale = $invoke($store, 'wallpaperSetList', 1, $sftp, '/tftp');
$omegaStaleRow = array_values(array_filter($omegaStale['sets'], static fn(array $set): bool => $set['id'] === 'omega'))[0];
$expect(
    !$omegaStaleRow['complete']
    && ($omegaStaleRow['variants'][0]['source_mode'] ?? '') === 'custom'
    && !($omegaStaleRow['variants'][0]['current'] ?? true),
    'Changing a profile source must make its old enabled render explicitly stale'
);

$omegaCustomRender = $store->handle([
    'expected_revision'=>$customSaved['revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'omega', 'set_name'=>'Omega',
    'pairs'=>json_encode([[
        'profile'=>$profile800['key'], 'dir'=>$profile800['dir'], 'models'=>['8841'],
        'full'=>base64_encode($png(800,480)), 'thumb'=>base64_encode($png(139,109)),
        'transform'=>['full'=>['zoom'=>220,'x'=>15,'y'=>-20], 'thumbnail'=>['zoom'=>100,'x'=>0,'y'=>0]],
    ]], JSON_THROW_ON_ERROR),
]);
$expect(!empty($omegaCustomRender['ok']), 'Could not publish the retained custom profile source');
$omegaPublishedManifest = json_decode(
    $sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/omega/manifest.json'],
    true,
    64,
    JSON_THROW_ON_ERROR
);
$omegaPublishedVariant = $omegaPublishedManifest['variants'][$profile800['key']];
$omegaPublishedFullName = (string)$omegaPublishedVariant['full'];
$omegaPublishedThumbName = (string)$omegaPublishedVariant['thumbnail'];
$expect(
    preg_match('/^w[a-f0-9]{12}\.png$/D', $omegaPublishedFullName) === 1
    && preg_match('/^w[a-f0-9]{12}-tn\.png$/D', $omegaPublishedThumbName) === 1,
    'Rendered wallpaper names must carry a deterministic content token'
);

$omegaDisabled = $store->handle([
    'expected_revision'=>$omegaCustomRender['revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'omega', 'set_name'=>'Omega',
    'pairs'=>json_encode([[
        'profile'=>$profile800['key'], 'dir'=>$profile800['dir'], 'models'=>['8841'], 'enabled'=>0,
        'transform'=>['full'=>['zoom'=>60,'x'=>100,'y'=>-100], 'thumbnail'=>['zoom'=>60,'x'=>0,'y'=>0]],
    ]], JSON_THROW_ON_ERROR),
]);
$expect(!empty($omegaDisabled['ok']) && ($omegaDisabled['disabled'] ?? 0) === 1, 'Cisco wallpaper profile could not be disabled');
$omegaFull = '/tftp/Desktops/800x480x24/' . $omegaPublishedFullName;
$omegaThumb = '/tftp/Desktops/800x480x24/' . $omegaPublishedThumbName;
$expect(
    isset($sftp->files[$omegaFull], $sftp->files[$omegaThumb])
    && !str_contains($sftp->files['/tftp/Desktops/800x480x24/List.xml'] ?? '', $omegaPublishedFullName),
    'Disabled Cisco profile must retain existing URLs while leaving selection lists'
);
$omegaDisabledList = $invoke($store, 'wallpaperSetList', 1, $sftp, '/tftp');
$omegaDisabledRow = array_values(array_filter($omegaDisabledList['sets'], static fn(array $set): bool => $set['id'] === 'omega'))[0];
$expect(
    $omegaDisabledRow['complete']
    && ($omegaDisabledRow['disabled_variant_count'] ?? 0) === 1
    && ($omegaDisabledRow['variants'][0]['current'] ?? false)
    && ($omegaDisabledRow['variants'][0]['source_mode'] ?? '') === 'custom'
    && ($omegaDisabledRow['variants'][0]['full_transform']['zoom'] ?? 0) === 60,
    'Disabled profile must remain a complete retained custom-source draft with v2 framing'
);
$omegaChoices = $invoke($store, 'wallpaperChoices', 1, $sftp, '/tftp', $profile800['key']);
$expect(
    !array_filter($omegaChoices['choices'] ?? [], static fn(array $choice): bool => ($choice['set_id'] ?? '') === 'omega'),
    'Disabled Cisco profile remained in template wallpaper choices'
);
$staleAssets = (new ManagedAssetRegistry($db))->list(1, ['kind'=>'background', 'state'=>'stale']);
$expect(
    !(bool)array_filter($staleAssets, static fn(array $asset): bool => ($asset['filename'] ?? '') === $omegaPublishedFullName),
    'Disabling a Cisco profile must not mark its retained bytes as missing'
);

$omegaRenamed = $invoke($store, 'wallpaperSetRename', 1, $sftp, '/tftp', 'omega', 'omega2', 'Omega 2');
$expect(!empty($omegaRenamed['ok']), 'Disabled custom-source catalog could not be renamed');
$omega2ManifestPath = '/tftp/.ucmprovisioner/wallpaper-sets/omega2/manifest.json';
$omega2Manifest = json_decode($sftp->files[$omega2ManifestPath], true, 64, JSON_THROW_ON_ERROR);
$expect(
    !isset($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/omega/manifest.json'])
    && isset($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/omega2/' . $customName])
    && empty($omega2Manifest['variants'][$profile800['key']]['enabled']),
    'Rename did not move the retained custom source and disabled state atomically'
);

$omegaSync = $store->handle([
    'op'=>'sync_wallpaper_media', 'site_id'=>1, 'scope'=>'wallpapers',
    'target_site_ids'=>[2], 'overwrite'=>1,
]);
$expect(
    !empty($omegaSync['ok'])
    && isset($target->files['/target/.ucmprovisioner/wallpaper-sets/omega2/' . $customName]),
    'Wallpaper synchronization omitted a retained profile-specific source'
);

$omegaReenabled = $store->handle([
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'omega2'])['manifest']['editor_revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'omega2', 'set_name'=>'Omega 2',
    'pairs'=>json_encode([[
        'profile'=>$profile800['key'], 'dir'=>$profile800['dir'], 'models'=>['8841'], 'enabled'=>1,
        'full'=>base64_encode($png(800,480)), 'thumb'=>base64_encode($png(139,109)),
        'transform'=>['full'=>['zoom'=>220,'x'=>15,'y'=>-20], 'thumbnail'=>['zoom'=>100,'x'=>0,'y'=>0]],
    ]], JSON_THROW_ON_ERROR),
]);
$omega2ReenabledManifest = json_decode($sftp->files[$omega2ManifestPath], true, 64, JSON_THROW_ON_ERROR);
$omega2ReenabledVariant = $omega2ReenabledManifest['variants'][$profile800['key']];
$expect(
    !empty($omegaReenabled['ok'])
    && isset(
        $sftp->files['/tftp/Desktops/800x480x24/' . $omega2ReenabledVariant['full']],
        $sftp->files['/tftp/Desktops/800x480x24/' . $omega2ReenabledVariant['thumbnail']]
    ),
    'Re-enabling a retained profile did not republish its rendered output'
);
$customReset = $store->handle([
    'expected_revision'=>$omegaReenabled['revision'],
    'op'=>'wallpaper_profile_source_reset', 'site_id'=>1, 'set_id'=>'omega2', 'profile'=>$profile800['key'],
]);
$expect(
    !empty($customReset['ok'])
    && !isset($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/omega2/' . $customName]),
    'Resetting a profile source did not restore master inheritance and retire the orphan source'
);
$masterGet = $store->handle([
    'op'=>'wallpaper_profile_source_get', 'site_id'=>1, 'set_id'=>'omega2', 'profile'=>$profile800['key'],
]);
$expect(
    !empty($masterGet['ok'])
    && ($masterGet['source_mode'] ?? '') === 'master'
    && base64_decode((string)$masterGet['source'], true) === $omegaSourceBytes,
    'Profile source reset did not expose the retained master source'
);

$yealinkProfile = ModelCatalog::wallpaperProfileForModel('Yealink', 'T46U');
$expect(($yealinkProfile['key'] ?? '') === 'yealink:480x272', 'Yealink linked-catalog fixture requires the standard 480x272 profile');
$epsilonSource = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', 'epsilon', 'Epsilon', base64_encode("\xff\xd8\xffEPSILON"), 'image/jpeg', 'create');
$expect(!empty($epsilonSource['ok']), 'Could not retain the cross-vendor wallpaper source');
$epsilon = $store->handle([
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'epsilon'])['manifest']['editor_revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'epsilon', 'set_name'=>'Epsilon',
    'pairs'=>json_encode([[
        'profile'=>$yealinkProfile['key'], 'dir'=>$yealinkProfile['dir'],
        'models'=>['T46S','T46U'], 'full'=>base64_encode($png(480,272)), 'thumb'=>'',
    ]], JSON_THROW_ON_ERROR),
]);
$expect(!empty($epsilon['ok']) && ($epsilon['written'] ?? 0) === 2, 'One Yealink render must publish to both explicitly scoped model libraries');
$epsilonManifest = json_decode($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/epsilon/manifest.json'], true, 64, JSON_THROW_ON_ERROR);
$epsilonName = (string)$epsilonManifest['variants'][$yealinkProfile['key']]['full'];
$uploadFaultSftp = clone $sftp;
$uploadFaultUcm = clone $ucm;
$uploadFaultUcm->sites[1] = [$uploadFaultSftp, '/tftp'];
$uploadFaultUcm->loseUploadModel = 't46u';
$uploadFaultUcm->mediaMutations = [];
$uploadFaultStore = new TftpStore($uploadFaultUcm);
$lostUpload = $uploadFaultStore->handle([
    'expected_revision'=>$epsilon['revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'epsilon', 'set_name'=>'Epsilon',
    'pairs'=>json_encode([['profile'=>$yealinkProfile['key'], 'dir'=>$yealinkProfile['dir'],
        'models'=>['T46S','T46U'], 'full'=>base64_encode($png(480,272) . 'new-render')]], JSON_THROW_ON_ERROR),
]);
$expect(empty($lostUpload['ok']) && !empty($lostUpload['recovery_required']) && $lostUpload['failed_stage']==='hosted_files', 'Lost hosted upload must preserve its failed stage');
$expect(array_column($lostUpload['hosted_results'],'status')===['published','unknown'], 'Earlier hosted success must survive a later lost reply');
$expect(array_column($uploadFaultUcm->mediaMutations,'op')===['upload','upload'], 'A lost upload reply must not trigger reverse uploads or deletes');
foreach ($uploadFaultUcm->mediaMutations as $mutation) { $expect(isset($uploadFaultUcm->media[$mutation['key']]), 'Completed or uncertain hosted uploads must be retained'); }
$expect($uploadFaultStore->siteLockStatus(1)['state']==='locked', 'Unknown cross-storage publication must block another writer');
$renameUploadSftp = clone $sftp;
$renameUploadUcm = clone $ucm;
$renameUploadUcm->sites[1] = [$renameUploadSftp,'/tftp'];
$renameUploadUcm->loseUploadModel = 't46u';
$renameUploadUcm->mediaMutations = [];
$renameUploadStore = new TftpStore($renameUploadUcm);
$lostRenameUpload = $renameUploadStore->handle(['op'=>'wallpaper_set_rename','site_id'=>1,'set_id'=>'epsilon','new_set_id'=>'epsilon_new','set_name'=>'Epsilon new',
    'expected_revision'=>$renameUploadStore->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'epsilon'])['manifest']['editor_revision']]);
$expect(empty($lostRenameUpload['ok']) && !empty($lostRenameUpload['recovery_required']) && $lostRenameUpload['failed_stage']==='hosted_files', 'Rename must retain hosted failure details before TFTP publication');
$expect(array_column($lostRenameUpload['hosted_results'],'status')===['published','unknown'] && $lostRenameUpload['references_state']==='not_attempted', 'Rename receipts must distinguish hosted success, unknown upload and unattempted reference writes');
$expect(array_column($renameUploadUcm->mediaMutations,'op')===['upload','upload'], 'Partial rename must not delete its hosted files');
$expect(
    isset($ucm->media["1|yealink|t46s|$epsilonName"], $ucm->media["1|yealink|t46u|$epsilonName"]),
    'Linked Yealink wallpaper outputs were not published through the existing phone-facing media store'
);
$hostedList = $invoke($store, 'wallpaperSetList', 1, $sftp, '/tftp');
$hostedEpsilon = array_values(array_filter($hostedList['sets'], static fn(array $set): bool => $set['id'] === 'epsilon'))[0];
$expect(
    $hostedEpsilon['complete'] && str_starts_with((string)($hostedEpsilon['variants'][0]['url'] ?? ''), 'http://site-1/'),
    'Hosted wallpaper catalog state must verify every scoped model and expose a phone-facing preview URL'
);
$hostedTarget = new WallpaperSftpStub();
$hostedTarget->directories = ['/hosted-target'=>true];
$ucm->sites[4] = [$hostedTarget, '/hosted-target'];
$ucm->media['1|yealink|t46s|unrelated.png'] = $png(480,272);
$foreignSftp = clone $sftp;
$foreignUcm = clone $ucm;
$foreignUcm->sites[1] = [$foreignSftp,'/tftp'];
$foreignStore = new TftpStore($foreignUcm);
$competingEdit = null;
$ucm->duringSync = static function () use ($foreignStore,$foreignSftp,$sftp,&$competingEdit): void {
    $foreignSftp->files = $sftp->files;
    $foreignSftp->directories = $sftp->directories;
    $foreignSftp->modes = $sftp->modes;
    $competingEdit = $foreignStore->handle(['op'=>'wallpaper_set_source','site_id'=>1,'set_id'=>'epsilon',
        'set_name'=>'Concurrent edit','intent'=>'update','source'=>base64_encode("\xff\xd8\xffCONCURRENT"),'mime'=>'image/jpeg']);
};
$sourceManifestBeforeSync = $sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/epsilon/manifest.json'];
$hostedSync = $store->handle([
    'op'=>'sync_wallpaper_media','site_id'=>1,'scope'=>'wallpapers',
    'set_ids'=>['epsilon'],'target_site_ids'=>[4],'overwrite'=>1,
]);
$ucm->duringSync = null;
$expect(is_array($competingEdit) && empty($competingEdit['ok']) && ($competingEdit['status']??'')==='busy' && ($competingEdit['mutation_started']??null)===false, 'An independent source writer must be excluded before mutation between payload and manifest transfer');
$expect($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/epsilon/manifest.json']===$sourceManifestBeforeSync
    && ($hostedTarget->files['/hosted-target/.ucmprovisioner/wallpaper-sets/epsilon/manifest.json'] ?? '')===$sourceManifestBeforeSync, 'Payloads and destination manifest must describe the same source catalog');
$expect($store->siteLockStatus(1)['state']==='unlocked', 'Completed synchronization must release its source lock');
$expect(
    !empty($hostedSync['ok'])
    && isset(
        $ucm->media["4|yealink|t46s|$epsilonName"],
        $ucm->media["4|yealink|t46u|$epsilonName"],
        $hostedTarget->files['/hosted-target/.ucmprovisioner/wallpaper-sets/epsilon/manifest.json']
    )
    && !isset($ucm->media['4|yealink|t46s|unrelated.png']),
    'Selected hosted wallpaper sync did not copy the exact catalog libraries and retained manifest'
);

$ucm->sites[5] = [new WallpaperSftpStub(),'/tftp'];
$ucm->sites[6] = [new WallpaperSftpStub(),'/tftp'];
$ucm->loseSyncModel='t46u';
$lostHosted=$store->handle(['op'=>'sync_wallpaper_media','site_id'=>1,'scope'=>'wallpapers','set_ids'=>['epsilon'],'target_site_ids'=>[5,6],'overwrite'=>1]);
foreach ([5,6] as $targetId) {
    $outcome=$lostHosted['sites'][$targetId] ?? [];
    $expect(empty($lostHosted['ok']) && ($outcome['copied'] ?? 0)>0 && !empty($outcome['hosted_media'][0]['ok'])
        && ($outcome['hosted_media'][1]['status'] ?? '')==='unknown'
        && !isset($ucm->sites[$targetId][0]->files['/tftp/.ucmprovisioner/wallpaper-sets/epsilon/manifest.json']),
        'Lost hosted copy response must retain preceding payload/library work and leave manifests unactivated');
}
$ucm->loseSyncModel='';$ucm->failSourceAfterModel='t46u';
$lostManifest=$store->handle(['op'=>'sync_wallpaper_media','site_id'=>1,'scope'=>'wallpapers','set_ids'=>['epsilon'],'target_site_ids'=>[5,6],'overwrite'=>1]);
$ucm->failSourceAfterModel='';$ucm->sourceUnavailable=false;
foreach ([5,6] as $targetId) {
    $outcome=$lostManifest['sites'][$targetId] ?? [];
    $expect(empty($lostManifest['ok']) && !empty($outcome['hosted_media'][0]['ok']) && !empty($outcome['hosted_media'][1]['ok'])
        && isset($outcome['activation']) && empty($outcome['activation']['ok'])
        && str_contains((string)($outcome['activation']['message'] ?? ''),'source unavailable'),
        'A manifest-phase failure must retain each completed hosted library and its specific activation outcome');
}

$jpeg = static function (int $width, int $height): string {
    return "\xff\xd8\xff\xc0" . pack('n', 17) . "\x08" . pack('nn', $height, $width)
        . "\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00\xff\xd9";
};
$grandstreamProfile = ModelCatalog::wallpaperProfileForModel('Grandstream', 'GHP630');
$zetaSource = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', 'zeta', 'Zeta', base64_encode("\xff\xd8\xffZETA"), 'image/jpeg', 'create');
$expect(!empty($zetaSource['ok']), 'Could not retain the Grandstream wallpaper source');
$zeta = $store->handle([
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'zeta'])['manifest']['editor_revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'zeta', 'set_name'=>'Zeta',
    'pairs'=>json_encode([[
        'profile'=>$grandstreamProfile['key'], 'dir'=>$grandstreamProfile['dir'],
        'models'=>['GHP630'], 'full'=>base64_encode($jpeg(480,320)), 'thumb'=>'',
    ]], JSON_THROW_ON_ERROR),
]);
$zetaManifest = json_decode($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/zeta/manifest.json'], true, 64, JSON_THROW_ON_ERROR);
$zetaName = (string)$zetaManifest['variants'][$grandstreamProfile['key']]['full'];
$expect(!empty($zeta['ok']) && isset($ucm->media['1|grandstream|ghp630|' . $zetaName]), 'Grandstream standard profile must publish a dimension-checked JPEG');

$epsilonDisabled = $store->handle([
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'epsilon'])['manifest']['editor_revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'epsilon', 'set_name'=>'Epsilon',
    'pairs'=>json_encode([[
        'profile'=>$yealinkProfile['key'], 'dir'=>$yealinkProfile['dir'],
        'models'=>['T46S','T46U'], 'enabled'=>0,
        'transform'=>['full'=>['zoom'=>60,'x'=>0,'y'=>0]],
    ]], JSON_THROW_ON_ERROR),
]);
$hostedDisabledList = $invoke($store, 'wallpaperSetList', 1, $sftp, '/tftp');
$hostedDisabled = array_values(array_filter($hostedDisabledList['sets'], static fn(array $set): bool => $set['id'] === 'epsilon'))[0];
$expect(
    !empty($epsilonDisabled['ok'])
    && isset($ucm->media["1|yealink|t46s|$epsilonName"], $ucm->media["1|yealink|t46u|$epsilonName"])
    && $hostedDisabled['complete']
    && ($hostedDisabled['disabled_variant_count'] ?? 0) === 1,
    'Disabling a hosted wallpaper profile must preserve existing phone URLs and complete catalog state'
);

$epsilonDeleted = $invoke($store, 'wallpaperSetDelete', 1, $sftp, '/tftp', 'epsilon');
$zetaDeleted = $invoke($store, 'wallpaperSetDelete', 1, $sftp, '/tftp', 'zeta');
$expect(!empty($epsilonDeleted['ok']) && !empty($zetaDeleted['ok']), 'Cross-vendor whole-catalog deletion failed');
$expect(
    !isset($ucm->media["1|yealink|t46s|$epsilonName"], $ucm->media['1|grandstream|ghp630|' . $zetaName]),
    'Whole-catalog deletion left a linked vendor output behind'
);

$thetaSource = $invoke($store, 'wallpaperSetSource', $sftp, '/tftp', 'theta', 'Theta', base64_encode("\xff\xd8\xffTHETA"), 'image/jpeg', 'create');
$expect(!empty($thetaSource['ok']), 'Could not retain catalog-rename fixture source');
$theta = $store->handle([
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'theta'])['manifest']['editor_revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'theta', 'set_name'=>'Theta',
    'pairs'=>json_encode([[
        'profile'=>$profile800['key'], 'dir'=>$profile800['dir'],
        'models'=>['8841'], 'full'=>base64_encode($png(800,480)),
        'thumb'=>base64_encode($png(139,109)),
    ]], JSON_THROW_ON_ERROR),
]);
$expect(!empty($theta['ok']), 'Could not publish catalog-rename fixture');
$thetaManifest = json_decode($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/theta/manifest.json'], true, 64, JSON_THROW_ON_ERROR);
$thetaInitialFull = (string)$thetaManifest['variants'][$profile800['key']]['full'];
$thetaChanged = $store->handle([
    'expected_revision'=>$theta['revision'],
    'op'=>'put_media_pairs', 'site_id'=>1, 'intent'=>'update', 'set_id'=>'theta', 'set_name'=>'Theta',
    'pairs'=>json_encode([[
        'profile'=>$profile800['key'], 'dir'=>$profile800['dir'],
        'models'=>['8841'], 'full'=>base64_encode($png(800,480) . 'changed-pixels'),
        'thumb'=>base64_encode($png(139,109) . 'changed-thumbnail'),
    ]], JSON_THROW_ON_ERROR),
]);
$thetaChangedManifest = json_decode($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/theta/manifest.json'], true, 64, JSON_THROW_ON_ERROR);
$thetaCurrentFull = (string)$thetaChangedManifest['variants'][$profile800['key']]['full'];
$expect(
    !empty($thetaChanged['ok'])
    && !hash_equals($thetaInitialFull, $thetaCurrentFull)
    && isset($sftp->files['/tftp/Desktops/800x480x24/' . $thetaInitialFull])
    && (bool)array_filter(
        (array)($thetaChangedManifest['aliases'] ?? []),
        static fn(array $alias): bool => ($alias['full'] ?? '') === $thetaInitialFull
    )
    && (bool)array_filter(
        (array)($thetaChanged['references']['replacements'] ?? []),
        static fn(array $row): bool => ($row['old'] ?? '') === $thetaInitialFull && ($row['new'] ?? '') === $thetaCurrentFull
    ),
    'Changing rendered bytes must publish a new filename, retain the old compatibility file and propagate exact references'
);
$renameFaultSftp = clone $sftp;
$renameFaultUcm = clone $ucm;
$renameFaultUcm->sites[1] = [$renameFaultSftp, '/tftp'];
$renameFaultUcm->loseReferenceResponse = true;
$renameFaultStore = new TftpStore($renameFaultUcm);
$referenceCallsBefore = $renameFaultUcm->referenceCalls;
$uncertainRename = $renameFaultStore->handle(['op'=>'wallpaper_set_rename','site_id'=>1,'set_id'=>'theta','new_set_id'=>'theta_uncertain','set_name'=>'Theta uncertain',
    'expected_revision'=>$renameFaultStore->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'theta'])['manifest']['editor_revision']]);
$expect(empty($uncertainRename['ok']) && !empty($uncertainRename['recovery_required']) && ($uncertainRename['failed_stage'] ?? '')==='references', 'A lost reference update must return the known published files and uncertain stage');
$expect($renameFaultUcm->referenceCalls === $referenceCallsBefore + 1, 'A lost reference response cannot trigger a reverse update');
$expect(isset($renameFaultSftp->files['/tftp/.ucmprovisioner/wallpaper-sets/theta/manifest.json'], $renameFaultSftp->files['/tftp/.ucmprovisioner/wallpaper-sets/theta_uncertain/manifest.json']), 'Both catalogs must remain available after uncertain reference changes');
foreach ($uncertainRename['written'] as $writtenPath) { $expect(isset($renameFaultSftp->files[$writtenPath]), 'Post-publication errors must not delete newly referenced files'); }
$expect($renameFaultStore->siteLockStatus(1)['state']==='locked', 'An uncertain wallpaper rename must block another writer until reconciled');
$renamed = $invoke($store, 'wallpaperSetRename', 1, $sftp, '/tftp', 'theta', 'theta_2026', 'Theta 2026');
$expect(!empty($renamed['ok']) && ($renamed['set_id'] ?? '') === 'theta_2026', 'Catalog-scope rename failed');
$thetaRenamedManifest = json_decode($sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/theta_2026/manifest.json'], true, 64, JSON_THROW_ON_ERROR);
$thetaNewFull = (string)$thetaRenamedManifest['variants'][$profile800['key']]['full'];
$expect(
    isset(
        $sftp->files['/tftp/Desktops/800x480x24/' . $thetaInitialFull],
        $sftp->files['/tftp/Desktops/800x480x24/' . $thetaCurrentFull],
        $sftp->files['/tftp/Desktops/800x480x24/' . $thetaNewFull]
    ),
    'Catalog rename did not retain the old compatibility file and publish the new filename'
);
$expect(
    !str_contains($sftp->files['/tftp/Desktops/800x480x24/List.xml'] ?? '', $thetaInitialFull)
    && !str_contains($sftp->files['/tftp/Desktops/800x480x24/List.xml'] ?? '', $thetaCurrentFull)
    && str_contains($sftp->files['/tftp/Desktops/800x480x24/List.xml'] ?? '', $thetaNewFull),
    'Compatibility aliases must stay out of the selectable Cisco List.xml'
);
$thetaDeleted = $invoke($store, 'wallpaperSetDelete', 1, $sftp, '/tftp', 'theta_2026');
$expect(
    !empty($thetaDeleted['ok'])
    && !isset(
        $sftp->files['/tftp/Desktops/800x480x24/' . $thetaInitialFull],
        $sftp->files['/tftp/Desktops/800x480x24/' . $thetaCurrentFull],
        $sftp->files['/tftp/Desktops/800x480x24/' . $thetaNewFull]
    ),
    'Whole-catalog delete must remove current outputs and managed rename aliases'
);

$reference=$db->prepare('INSERT INTO ucmp_settings (id,site_id,skey,sval) VALUES (1,1,?,?)');
$reference->execute(['wallpaper',$alpha800['full']]);
$protected=$invoke($store,'wallpaperSetDelete',1,$sftp,'/tftp','alpha');
$expect(empty($protected['ok'])&&!empty($protected['protected'])&&isset($sftp->files['/tftp/Desktops/800x480x24/'.$alpha800['full']]),'Assigned catalog retirement must stop before moving files');
$db->exec('DELETE FROM ucmp_settings WHERE id=1');
$sftp->files['/tftp/SEP000000000001.cnf.xml']=$alpha800['full'];
$protected=$invoke($store,'wallpaperSetDelete',1,$sftp,'/tftp','alpha');
$expect(empty($protected['ok'])&&!empty($protected['protected']),'Generated phone files must protect catalog outputs');
unset($sftp->files['/tftp/SEP000000000001.cnf.xml']);
$partialSftp=clone $sftp;$partialSftp->failRenameSource='/tftp/Desktops/800x480x24/'.$alpha800['thumbnail'];
$partialStore=new TftpStore(new WallpaperUcmStub($db,[1=>[$partialSftp,'/tftp']]));
$partial=$invoke($partialStore,'wallpaperSetDelete',1,$partialSftp,'/tftp','alpha');
$expect(empty($partial['ok'])&&!empty($partial['recovery_required'])&&count($partial['completed'])>=1
    &&isset($partialSftp->files[$partial['receipt']])
    &&isset($partialSftp->files[$partial['completed'][0]['destination']])
    &&isset($partialSftp->files['/tftp/.ucmprovisioner/wallpaper-sets/alpha/manifest.json']),
    'Late catalog retirement failure must retain completed moves, source and pending receipt');
$deleted = $invoke($store, 'wallpaperSetDelete', 1, $sftp, '/tftp', 'alpha');
$expect(($deleted['ok'] ?? false) === true, 'Whole-set delete failed');
$expect(!isset($sftp->files['/tftp/Desktops/800x480x24/' . $alpha800['full']]), 'Whole-set delete left an Alpha full image');
$expect(isset($sftp->files['/tftp/Desktops/800x480x24/' . $beta800['full']]), 'Whole-set delete removed Beta');
$expect(str_contains($sftp->files['/tftp/Desktops/800x480x24/List.xml'] ?? '', $beta800['full']), 'Rebuilt List.xml lost the remaining set');
$expect(!str_contains($sftp->files['/tftp/Desktops/800x480x24/List.xml'] ?? '', $alpha800['full']), 'Rebuilt List.xml retained the deleted set');
$omegaCustomAgain = $store->handle([
    'expected_revision'=>$store->handle(['op'=>'wallpaper_set_get','site_id'=>1,'set_id'=>'omega2'])['manifest']['editor_revision'],
    'op'=>'wallpaper_profile_source_save', 'site_id'=>1, 'set_id'=>'omega2',
    'profile'=>$profile800['key'], 'source'=>base64_encode($omegaCustomBytes), 'mime'=>'image/jpeg',
]);
$omega2Deleted = $invoke($store, 'wallpaperSetDelete', 1, $sftp, '/tftp', 'omega2');
$expect(
    !empty($omegaCustomAgain['ok'])
    && !empty($omega2Deleted['ok'])
    && !isset(
        $sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/omega2/manifest.json'],
        $sftp->files['/tftp/.ucmprovisioner/wallpaper-sets/omega2/' . $customName],
        $sftp->files['/tftp/Desktops/800x480x24/omega2-800x480.png']
    ),
    'Whole-catalog delete must remove custom sources and republished outputs'
);
$remaining = $invoke($store, 'wallpaperSetList', 1, $sftp, '/tftp');
$expect(!array_filter($remaining['sets'] ?? [], static fn(array $set): bool => $set['id'] === 'alpha'), 'Deleted manifest remained in the linked-set list');

echo "Linked wallpaper set fixtures passed\n";
