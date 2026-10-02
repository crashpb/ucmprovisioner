<?php
declare(strict_types=1);

// Reuse the catalog/SFTP fixtures, then replace only their hosted transport with
// the real endpoint body. Its filesystem root is isolated; naming is not mocked.
require __DIR__ . '/WallpaperSetTest.php';
$source = file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$start = strpos($source, '    private function mediaAssetOpLocalUnlocked(');
$end = strpos($source, '    private function specialCatalogLibrary(', $start);
$method = substr($source, $start, $end - $start);
$method = str_replace("\$root = '/var/lib/asterisk/ucmprovisioner/media';", '$root = $this->root;', $method, $replacements);
$expect($replacements === 1, 'The real uploader must use only the isolated media root');
$root = sys_get_temp_dir() . '/ucmp-wallpaper-upload-' . bin2hex(random_bytes(6));
$backend = eval('return new class($root) {
    public function __construct(public string $root) {}
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ["pbx_addr"=>"192.0.2.10"]; }
    public function getSetting(string $key, ?int $site=null, $default=null) { return $default; }
    public $catalogRead;
    public function tftpOp(array $request): array { return ($this->catalogRead)($request); }
    private function mediaAssetUsageIndex(int $site): array { return []; }
    public function op(array $request): array {
        return $this->mediaAssetOpLocalUnlocked($request, in_array($request["op"], ["inventory","list","download","download_chunk"], true));
    }
' . $method . '};');
$httpSftp = new WallpaperSftpStub();
$httpUcm = new WallpaperUcmStub($db, [1=>[$httpSftp, '/tftp']]);
$httpUcm->hostedOperation = fn(array $r): array => $backend->op($r);
$httpStore = new \FreePBX\modules\Ucmprovisioner\TftpStore($httpUcm);
$backend->catalogRead=fn(array $request): array => $httpStore->handle($request+['site_id'=>1]);
$choices=fn(): array => $backend->op(['op'=>'inventory'])['background_choices'];
$manifestPath = '/tftp/.ucmprovisioner/wallpaper-sets/hosted/manifest.json';
$profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::wallpaperProfileByKey('grandstream:480x320');
$image = imagecreatetruecolor(480, 320);
ob_start(); imagejpeg($image); $jpeg = ob_get_clean(); imagedestroy($image);
$pair = ['profile'=>$profile['key'],'dir'=>$profile['dir'],'models'=>['GHP630','GHP630W'],'enabled'=>1,'full'=>base64_encode($jpeg)];
$readCatalog = fn(): array => $invoke($httpStore, 'wallpaperSetList', 1, $httpSftp, '/tftp')['sets'][0];
$pathFor = fn(string $model, string $name): string => $root . '/grandstream/' . strtolower($model) . '/' . $name;
$repair = fn(): array => $httpStore->handle(['op'=>'wallpaper_set_repair_hosted_names','site_id'=>1,'set_id'=>'hosted','expected_revision'=>$readCatalog()['revision']]);
try {
    $created = $invoke($httpStore, 'wallpaperSetSource', $httpSftp, '/tftp', 'hosted', 'Hosted', base64_encode($jpeg), 'image/jpeg', 'create');
    $expect(!empty($created['ok']), 'Hosted catalog source creation failed');
    $saved = $invoke($httpStore, 'saveWallpaperVariants', 1, $httpSftp, '/tftp', ['set_id'=>'hosted','set_name'=>'Hosted','intent'=>'update','pairs'=>[$pair]]);
    $expect(!empty($saved['ok']), 'Hosted save failed: ' . json_encode($saved));
    $catalog = $readCatalog(); $variant = $catalog['variants'][0]; $name = $variant['full'];
    $expect(!empty($saved['ok']) && $catalog['complete'], 'Real uploader and catalog must agree on published filenames');
    foreach ($pair['models'] as $model) { $expect(file_get_contents($pathFor($model,$name)) === $jpeg, 'Manifest reference has no matching published bytes'); }
    $ordinary = $backend->op(['op'=>'upload','family'=>'grandstream','model'=>'ghp630','name'=>'long-human-upload-name.jpg','content'=>base64_encode($jpeg)]);
    $fallback = $ordinary['name'];
    $expect($fallback === \FreePBX\modules\Ucmprovisioner\AssetFilename::content('h',$jpeg,'jpg'), 'Ordinary image uploads lost compact content names');
    $expect(count($choices())===2 && array_unique(array_column($choices(),'name'))===[$name], 'Current catalog outputs alone must authorize choices, not an identical loose upload');
    try { $backend->op(['op'=>'upload','family'=>'grandstream','model'=>'ghp630','name'=>$name,'content'=>base64_encode('different')]); throw new LogicException('Immutable wallpaper overwrite accepted'); }
    catch (RuntimeException) {}

    // Exact former regression: the uploader wrote h*, while the catalog owns w*.
    $originalManifest = $httpSftp->files[$manifestPath];
    foreach ($pair['models'] as $model) {
        $backend->op(['op'=>'upload','family'=>'grandstream','model'=>strtolower($model),'name'=>'legacy.jpg','content'=>base64_encode($jpeg)]);
        unlink($pathFor($model,$name));
    }
    $expect(!$readCatalog()['complete'] && !is_file($pathFor('GHP630',$name)), 'Read-only validation must detect, not silently repair, the mismatch');
    $expect($choices()===[], 'A missing managed publication must not fall back to a loose identical image');
    file_put_contents($pathFor('GHP630W',$fallback),'corrupt');
    $expect(empty($repair()['ok']) && !is_file($pathFor('GHP630',$name)), 'Repair must validate every source before the first write');
    file_put_contents($pathFor('GHP630W',$fallback),$jpeg);
    $stale = $httpStore->handle(['op'=>'wallpaper_set_repair_hosted_names','site_id'=>1,'set_id'=>'hosted','expected_revision'=>'stale']);
    $expect(empty($stale['ok']) && !is_file($pathFor('GHP630',$name)), 'A stale editor must not repair a different catalog revision');
    $lost = true;
    $httpUcm->hostedOperation = function(array $r) use($backend,&$lost): array {
        $result = $backend->op($r);
        if ($r['op']==='upload' && $r['model']==='ghp630' && $lost) { $lost=false; throw new RuntimeException('Lost reply after publication'); }
        return $result;
    };
    $interrupted = $repair();
    $expect(!empty($interrupted['ok']) && $interrupted['repaired']===2 && !$lost, 'A lost reply must be reconciled from the actual immutable destination before continuing');
    $httpUcm->hostedOperation = fn(array $r): array => $backend->op($r);
    $repaired = $repair();
    $expect(!empty($repaired['ok']) && $repaired['repaired']===0 && $readCatalog()['complete'], 'Retry must recognize the already verified files: ' . json_encode($repaired));
    $expect($repair()['repaired']===0 && $httpSftp->files[$manifestPath]===$originalManifest, 'Repair must be idempotent and preserve catalog/crop/source metadata');
    foreach ($pair['models'] as $model) { $expect(file_get_contents($pathFor($model,$fallback))===$jpeg, 'Repair removed an existing hosted URL'); }
    $expect(count($choices())===2 && array_unique(array_column($choices(),'name'))===[$name], 'Repair must restore only current choices without offering its retained compatibility copies');

    // The real chunk receiver must retain the source name, too.
    $chunk = $backend->op(['op'=>'download_chunk','family'=>'grandstream','model'=>'ghp630','name'=>$name]);
    $copied = $backend->op(['op'=>'upload_chunk','family'=>'grandstream','model'=>'ghp631','name'=>$name,
        'transfer_id'=>bin2hex(random_bytes(16)),'expected_sha256'=>$chunk['sha256'],'expected_size'=>$chunk['size'],
        'offset'=>0,'final'=>1,'content'=>$chunk['content']]);
    $expect(!empty($copied['complete']) && file_get_contents($pathFor('GHP631',$name))===$jpeg, 'Hosted sync receiver changed the published filename or bytes');
    $expect(count($choices())===2, 'Copying generated-looking bytes without the owning manifest must not create a background choice');

    // An old receiver must stop before a new manifest or rename is committed.
    $httpUcm->hostedOperation = function(array $r) use($backend): array { if($r['op']==='upload'){$r['name']='old-receiver.jpg';} return $backend->op($r); };
    $renamed = $invoke($httpStore,'wallpaperSetRename',1,$httpSftp,'/tftp','hosted','renamed','Renamed');
    $expect(empty($renamed['ok']) && isset($httpSftp->files[$manifestPath]) && !isset($httpSftp->files['/tftp/.ucmprovisioner/wallpaper-sets/renamed/manifest.json']), 'Renaming through an old receiver committed an invalid catalog');
    $changed = $jpeg . "\n"; $pair['full']=base64_encode($changed);
    $failedSave = $invoke($httpStore,'saveWallpaperVariants',1,$httpSftp,'/tftp',['set_id'=>'hosted','intent'=>'update','pairs'=>[$pair]]);
    $expect(empty($failedSave['ok']) && $httpSftp->files[$manifestPath]===$originalManifest, 'Updating through an old receiver committed an invalid manifest');
    $httpUcm->hostedOperation = fn(array $r): array => $backend->op($r);
    $updated = $invoke($httpStore,'saveWallpaperVariants',1,$httpSftp,'/tftp',['set_id'=>'hosted','intent'=>'update','pairs'=>[$pair]]);
    $expect(!empty($updated['ok']) && $readCatalog()['complete'] && $readCatalog()['variants'][0]['full']!==$name, 'Changed content must publish a new valid catalog filename');
    $renamed = $invoke($httpStore,'wallpaperSetRename',1,$httpSftp,'/tftp','hosted','renamed','Renamed');
    $expect(!empty($renamed['ok']) && $readCatalog()['complete'], 'Real uploader broke catalog rename');
    $renamedChoices=$choices();
    $expect(count($renamedChoices)===2 && array_unique(array_column($renamedChoices,'catalog_name'))===['Renamed'] && !in_array($name,array_column($renamedChoices,'name'),true), 'Replacement and rename must leave exactly the current catalog outputs selectable');
    $normalCatalogRead=$backend->catalogRead;
    $backend->catalogRead=static fn(array $r): array => throw new RuntimeException('Catalog unavailable');
    $expect($choices()===[], 'Unavailable catalog metadata must never fall back to arbitrary served images');
    $backend->catalogRead=$normalCatalogRead;

    $pngProfile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::wallpaperProfileByKey('yealink:320x240');
    $image = imagecreatetruecolor(320,240); ob_start(); imagepng($image); $pngBytes=ob_get_clean(); imagedestroy($image);
    $invoke($httpStore,'wallpaperSetSource',$httpSftp,'/tftp','png','PNG',base64_encode($pngBytes),'image/png','create');
    $pngSaved = $invoke($httpStore,'saveWallpaperVariants',1,$httpSftp,'/tftp',['set_id'=>'png','intent'=>'update','pairs'=>[
        ['profile'=>$pngProfile['key'],'dir'=>$pngProfile['dir'],'models'=>['T33G'],'full'=>base64_encode($pngBytes)],
    ]]);
    $pngCatalog = array_values(array_filter($invoke($httpStore,'wallpaperSetList',1,$httpSftp,'/tftp')['sets'],fn($s)=>$s['id']==='png'))[0];
    $expect(!empty($pngSaved['ok']) && $pngCatalog['complete'], 'Yealink PNG publication changed the managed name');
    $pngName=$pngCatalog['variants'][0]['full']; $pngPath=$root.'/yealink/t33g/'.$pngName;
    $backend->op(['op'=>'upload','family'=>'yealink','model'=>'t33g','name'=>'legacy.png','content'=>base64_encode($pngBytes)]);
    file_put_contents($pngPath,'conflicting bytes');
    $pngRequest=['op'=>'wallpaper_set_repair_hosted_names','site_id'=>1,'set_id'=>'png','expected_revision'=>$pngCatalog['revision']];
    $expect(empty($httpStore->handle($pngRequest)['ok']) && file_get_contents($pngPath)==='conflicting bytes', 'Repair overwrote a conflicting managed destination');
    unlink($pngPath);
    $pngRepair=$httpStore->handle($pngRequest);
    $expect(!empty($pngRepair['ok']) && $pngRepair['repaired']===1 && file_get_contents($pngPath)===$pngBytes, 'Yealink PNG repair failed to restore the exact referenced bytes');
    $expect(count($choices())===3, 'Yealink repair must expose one current output, without a duplicate content-named image');

    // If readback also fails, retain uncertainty and never advance to another model.
    unlink($pngPath); $hidden=false;
    $httpUcm->hostedOperation=function(array $r) use($backend,&$hidden): array {
        if($r['op']==='list' && $hidden){throw new RuntimeException('Readback unavailable');}
        $result=$backend->op($r);
        if($r['op']==='upload'){$hidden=true;throw new RuntimeException('Lost reply');}
        return $result;
    };
    $unknown=$httpStore->handle($pngRequest);
    $expect(empty($unknown['ok']) && !empty($unknown['recovery_required']) && $unknown['results'][0]['status']==='unknown', 'Unreadable completion must remain unknown');
    $expect(isset($httpSftp->files['/tftp/.ucmprovisioner/write-lock/owner.json']), 'Unreconciled publication lost its owner lock');
} finally {
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
echo "Real hosted uploader, catalog naming, sync, immutable collisions and interrupted repair passed\n";
