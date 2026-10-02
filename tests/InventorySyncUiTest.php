<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$media = (string)file_get_contents($root . '/views/media.php');
$firmware = (string)file_get_contents($root . '/views/firmware.php');
$directory = (string)file_get_contents($root . '/views/directory.php');
$patches = (string)file_get_contents($root . '/views/patches.php');
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($media, 'target_site_ids')
    && str_contains($media, "op:'sync'"),
    'Media inventory must expose explicit cross-site synchronization targets'
);
$expect(
    str_contains($firmware, 'target_site_ids')
    && str_contains($firmware, 'sync_firmware_bundle'),
    'Firmware inventory must expose explicit cross-site bundle synchronization'
);
$expect(
    str_contains($directory, 'publish_sites')
    && str_contains($directory, 'ucm-publish-all'),
    'Phonebooks must publish to selected sites or every enabled site'
);
$expect(
    str_contains($patches, 'ucm-asterisk-sync-target')
    && str_contains($patches, "op:'sync'")
    && str_contains($module, 'syncAsteriskBundle'),
    'Compiled Asterisk bundles must synchronize into target Incoming inventories'
);

echo "Cross-site inventory sync UI fixtures passed\n";
