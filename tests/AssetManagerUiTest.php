<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$firmware = (string)file_get_contents($root . '/views/firmware.php');
$media = (string)file_get_contents($root . '/views/media.php');
$tftp = (string)file_get_contents($root . '/lib/TftpStore.php');
$tftpView = (string)file_get_contents($root . '/views/tftp.php');
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$mediaEndpoint = (string)file_get_contents($root . '/media_asset.php');
$hostedInventory = (string)file_get_contents($root . '/lib/HostedMediaInventory.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($firmware, 'Asset index (advanced)')
    && str_contains($firmware, 'Asset ID / status')
    && str_contains($firmware, 'signature_status')
    && str_contains($firmware, 'catalog_assets')
    && str_contains($firmware, 'usage'),
    'Firmware Manager must expose stable IDs, lifecycle state, authenticity, manifest and usage'
);
$expect(
    str_contains($media, 'Asset index (advanced)')
    && str_contains($media, 'Reload all media')
    && str_contains($media, 'Verify / refresh all media')
    && str_contains($media, 'Restart TFTP scan')
    && str_contains($media, 'ucm-media-assets')
    && str_contains($media, 'catalog_assets')
    && str_contains($media, "op:'managed_asset_inventory'")
    && str_contains($media, "state:'current'")
    && str_contains($media, 'next_offset')
    && str_contains($media, 'content_sha256')
    && str_contains($media, 'Observed only')
    && str_contains($media, 'Unified physical inventory')
    && str_contains($media, 'renderLooseHostedItems')
    && str_contains($media, "op:'special_asset_adopt'")
    && str_contains($media, "op:'special_asset_delete'")
    && str_contains($media, 'Devices excluded from generation')
    && str_contains($media, 'id="ucm-hosted-publish"')
    && !str_contains($media, 'ucm-hosted-render-upload')
    && str_contains($media, 'id="ucm-bg-rename"')
    && str_contains($media, 'syncAwareRequest')
    && str_contains($media, "op:'states',object_type:'wallpaper_catalog'")
    && str_contains($media, "catalogSyncBadge('special_catalog'")
    && str_contains($tftp, 'synchronizedCatalogMutation')
    && str_contains($module, 'configurationSyncCatalogEditGuard')
    && str_contains($media, "if(!activeSetId&&!String(\$('#ucm-bg-name').val()||'').trim())")
    && !str_contains($media, 'id="ucm-hosted-source"'),
    'Media Manager must own managed and loose HTTP media while preserving the bounded TFTP scan'
);
$expect(
    str_contains($tftp, 'mergeHostedMediaAssets')
    && str_contains($tftp, "'op'=>'inventory', 'site_id'=>\$siteId")
    && str_contains($hostedInventory, 'catalog manifests alone authorize new')
    && str_contains($hostedInventory, "'provenance'=>\$managed ? 'generated' : 'http_observation'")
    && str_contains($hostedInventory, "'selectable'=>!\$errors")
    && str_contains($module, "['png','jpg','jpeg','gif','bmp','wav','mp3','ogg']"),
    'Physical inventory must retain observations while catalog manifests authorize new backgrounds'
);
$expect(
    str_contains($tftp, "'asset_catalog'")
    && str_contains($tftp, "'asset_selections'")
    && str_contains($tftp, "'asset_validate_selection'")
    && str_contains($tftp, "'asset_validate_timezone'")
    && str_contains($tftp, 'AssetFileInspector::inspect')
    && str_contains($tftp, 'ManagedAssetScanJob')
    && str_contains($tftp, "'complete'=>false")
    && str_contains($tftp, 'reconcileFirmware')
    && str_contains($tftp, 'reconcileTimezone')
    && str_contains($tftp, '$generatedCatalog'),
    'TFTP manager must expose bounded validation, reconcile bundle hashes, and retain generated-catalog receipts'
);
$expect(
    str_contains($module, "'preview_url'=>\\FreePBX\\modules\\Ucmprovisioner\\MediaUrl::preview")
    && str_contains($module, "'url'=>\\FreePBX\\modules\\Ucmprovisioner\\MediaUrl::asset")
    && str_contains($media, "item.preview_url||item.url")
    && str_contains($mediaEndpoint, 'must-revalidate')
    && str_contains($mediaEndpoint, 'HTTP_IF_NONE_MATCH')
    && str_contains($mediaEndpoint, "hash_file('sha256'"),
    'Phone media must separate a safe persisted URL from browser cache-busted preview and revalidate exact content'
);
$expect(
    str_contains($tftpView, 'id="ucm-tftp-upload-input" style="display:none;" multiple')
    && str_contains($tftpView, "files.length>50")
    && str_contains($tftpView, "always(next)")
    && str_contains($tftpView, "Failed: '+failed.join"),
    'TFTP Browser must upload a bounded multi-file batch and report partial failures'
);

echo "Asset manager UI fixtures passed\n";
