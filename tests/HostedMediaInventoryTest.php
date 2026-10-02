<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/AssetCatalog.php';
require_once dirname(__DIR__) . '/lib/AssetFileInspector.php';
require_once dirname(__DIR__) . '/lib/MediaUrl.php';
require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/HostedMediaInventory.php';
require_once dirname(__DIR__) . '/lib/TftpStore.php';

use FreePBX\modules\Ucmprovisioner\HostedMediaInventory;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$find = static function (array $items, string $name): array {
    foreach ($items as $item) {
        if (($item['name'] ?? '') === $name) { return $item; }
    }
    throw new RuntimeException("Missing hosted media fixture $name");
};

$root = sys_get_temp_dir() . '/ucmp-hosted-inventory-' . bin2hex(random_bytes(6));
$library = $root . '/cisco_cnf/all';
mkdir($library, 0770, true);
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
file_put_contents($library . '/Example_Logo.bmp', $bmp);
file_put_contents($library . '/Broken.bmp', 'not-a-bmp');

try {
    $inventory = new HostedMediaInventory($root, 'http://pbx.test');
    $observed = $inventory->scan('cisco_cnf', 'all', [], [
        'example_logo.bmp'=>['Template: Base CNF'],
    ]);
    $logo = $find($observed['items'], 'Example_Logo.bmp');
    $expect(
        ($logo['status'] ?? '') === 'discovered',
        'A valid loose HTTP BMP was not classified as observed: ' . json_encode([
            'status'=>$logo['status'] ?? null,
            'validation_message'=>$logo['validation_message'] ?? null,
            'metadata'=>$logo['metadata'] ?? null,
        ], JSON_UNESCAPED_SLASHES)
    );
    $expect(empty($logo['selectable']) && !empty($logo['content_verified']) && $logo['state']==='observed', 'A loose valid image must be available for adoption, never a new background choice');
    $expect(($logo['profile'] ?? '') === 'cisco_cnf:90x56', 'Hosted Cisco CNF media must use the same 90x56 profile key as Settings and Templates');
    $expect(empty($logo['managed']), 'A loose HTTP BMP was incorrectly marked as catalog-owned');
    $expect(($logo['usage_count'] ?? 0) === 1, 'Background assignments were omitted from HTTP media usage');
    $expect(($logo['used_by'] ?? []) === ['Template: Base CNF'], 'HTTP media assignment labels were not projected');
    $expect(!str_contains((string)$logo['url'], '?'), 'Cisco CNF device URL gained an unsafe query string');
    $expect(str_contains((string)$logo['preview_url'], '?v='), 'Browser preview URL was not cache-busted');
    $broken = $find($observed['items'], 'Broken.bmp');
    $expect(
        ($broken['status'] ?? '') === 'invalid' && empty($broken['selectable']),
        'An invalid loose BMP remained selectable'
    );

    $managed = $inventory->scan('cisco_cnf', 'all', [
        'cisco_cnf/all/example_logo.bmp'=>[
            'catalog_id'=>'example_logo', 'catalog_name'=>'Example Logo', 'revision'=>'2',
            'state'=>'current', 'sha256'=>hash('sha256', $bmp),
        ],
    ]);
    $owned = $find($managed['items'], 'Example_Logo.bmp');
    $expect(
        ($owned['status'] ?? '') === 'verified'
        && !empty($owned['managed'])
        && ($owned['catalog_id'] ?? '') === 'example_logo'
        && ($owned['bundle_key'] ?? '') === 'special:example_logo',
        'Catalog ownership was not projected onto the exact served file'
    );

    $owner=['catalog_id'=>'example_logo','catalog_name'=>'Example Logo','state'=>'current','sha256'=>hash('sha256',$bmp)];
    $expect($owned['selectable'], 'The exact verified current catalog output must be selectable');
    foreach (['compatibility','disabled','unavailable'] as $state) {
        $rows=$inventory->scan('cisco_cnf','all',['cisco_cnf/all/example_logo.bmp'=>array_replace($owner,['state'=>$state])]);
        $item=$find($rows['items'],'Example_Logo.bmp');
        $expect(!$item['selectable'] && $item['managed'] && $item['state']===$state && $item['content_verified'], 'Retained or unavailable outputs must remain observable, not selectable');
    }
    $rows=$inventory->scan('cisco_cnf','all',['cisco_cnf/all/example_logo.bmp'=>array_replace($owner,['sha256'=>str_repeat('0',64)])]);
    $item=$find($rows['items'],'Example_Logo.bmp');
    $expect(!$item['selectable'] && $item['status']==='invalid', 'A valid image with different bytes from its catalog must not be selectable');
    foreach (['current','compatibility'] as $state) {
        $rows=$inventory->scan('cisco_cnf','all',['cisco_cnf/all/example_logo.bmp'=>array_replace($owner,['state'=>$state,'sha256'=>''])]);
        $item=$find($rows['items'],'Example_Logo.bmp');
        $expect(!$item['selectable'] && $item['status']==='verified' && $item['managed'] && !$item['catalog_verified'], 'Hashless legacy manifests retain existing reference resolution without authorizing new selections');
    }

    $merge = new ReflectionMethod(TftpStore::class, 'mergeHostedMediaAssets');
    $merge->setAccessible(true);
    $merged = $merge->invoke(null, [], $observed['items'], ['kind'=>'background', 'limit'=>500]);
    $mergedLogo = $find($merged, 'Example_Logo.bmp');
    $expect(
        ($mergedLogo['relative_path'] ?? '') === 'HTTP media/cisco_cnf/all/Example_Logo.bmp',
        'The Asset Index merge discarded a physical HTTP media item'
    );
    if (PHP_OS_FAMILY !== 'Windows') {
        $expect(symlink($library . '/Example_Logo.bmp', $library . '/linked.bmp'), 'Create linked-asset fixture');
        $expect(symlink($library, $root . '/cisco_cnf/linked'), 'Create linked-library fixture');
        $linked = $inventory->scan('cisco_cnf');
        $expect(count($linked['items']) === 2 && count($linked['issues']) === 2,
            'Inventory must exclude assets which the public endpoint refuses to serve');
    }
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path)) { unlink($path); }
        elseif (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') { $remove($path . '/' . $entry); }
            }
            rmdir($path);
        } elseif (is_file($path)) { unlink($path); }
    };
    if (is_dir($root)) { $remove($root); }
}

echo "Hosted media inventory fixtures passed\n";
