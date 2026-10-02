<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$discovery = (string)file_get_contents($root . '/lib/Discovery.php');
$templates = (string)file_get_contents($root . '/lib/TemplateEngine.php');
$provisioner = (string)file_get_contents($root . '/lib/Provisioner.php');
$manifest = (string)file_get_contents($root . '/module.xml');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(str_contains($manifest, '<table name="ucmp_phone_mac_suppressions">'), 'Manifest must persist deleted physical MACs');
$expect(str_contains($discovery, 'intentionally deleted physical phone(s) left unprovisioned'), 'Discovery must report suppressed physical phones');
$expect(str_contains($discovery, 'isset($suppressedMacs[$observedMac])'), 'Discovery must guard MAC observations before physical upsert');
$expect(str_contains($module, "DELETE FROM ucmp_phone_mac_suppressions WHERE site_id=? AND mac=?"), 'Explicit phone save/recovery must clear the MAC tombstone');
$expect(str_contains($module, 'extension_id remains linked'), 'Deleting hardware must preserve the logical extension link');
$expect(str_contains($module, "mac=NULL,vendor=NULL,model_id=NULL,family=NULL,ip=NULL"), 'Deleting hardware must immediately leave an extension-only inventory row');
$expect(!str_contains($templates, '$lineDevice = $this->getDevice($lineExtension);'), 'Template resolution must not query FreePBX once per line key');
$expect(str_contains($templates, '$this->endpointCatalog($siteId)'), 'Endpoint validation must use one cached site catalog');
$expect(str_contains($provisioner, '$reloadNeeded = $endpointDbChanged;'), 'Unchanged phone builds must not trigger a full FreePBX reload');

echo "PhoneDeletionDiscoveryTest: OK\n";
