<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/ConfigurationSyncService.php';

use FreePBX\modules\Ucmprovisioner\ConfigurationSyncService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

foreach (ConfigurationSyncService::OBJECT_TYPES as $type) {
    $expect(strlen($type) <= ConfigurationSyncService::OBJECT_TYPE_MAX_LENGTH, "Object type exceeds the shared schema limit: $type");
    $expect(ConfigurationSyncService::normalizeObjectType($type) === $type, "Supported object type did not round-trip: $type");
}
$longestType = max(array_map('strlen', ConfigurationSyncService::OBJECT_TYPES));
$expect($longestType === strlen('settings_default_template_selectors'), 'The test must exercise the actual longest object type');
$expect(
    in_array('settings_default_template_selectors', ConfigurationSyncService::OBJECT_TYPES, true),
    'The longest Settings synchronization identifier must be part of the shared object-type contract'
);

try {
    ConfigurationSyncService::normalizeObjectType(str_repeat('x', ConfigurationSyncService::OBJECT_TYPE_MAX_LENGTH + 1));
    $expect(false, 'Oversized object types must fail before reaching SQL');
} catch (InvalidArgumentException) {}

$baseItem = [
    'job_key'=>'cfgc:fixture-1:1', 'source_site_key'=>'site-a-pbx', 'source_site_name'=>'SITE-A-PBX',
    'object_key'=>'selector:family:cisco_xml', 'status'=>'active',
];
foreach (ConfigurationSyncService::OBJECT_TYPES as $type) {
    $normalized = ConfigurationSyncService::normalizeTrackingItem($baseItem + ['object_type'=>$type]);
    $expect($normalized['object_type'] === $type, "Tracking-row normalization changed object type: $type");
}
try {
    ConfigurationSyncService::normalizeTrackingItem($baseItem + ['object_type'=>'unsupported_contract_value']);
    $expect(false, 'Unsupported tracking object types must fail before reaching SQL');
} catch (InvalidArgumentException) {}

$expect(
    strlen(ConfigurationSyncService::normalizeObjectKey(str_repeat('k', ConfigurationSyncService::OBJECT_KEY_MAX_LENGTH))) === ConfigurationSyncService::OBJECT_KEY_MAX_LENGTH,
    'An object key at the declared boundary must remain valid'
);
try {
    ConfigurationSyncService::normalizeObjectKey(str_repeat('k', ConfigurationSyncService::OBJECT_KEY_MAX_LENGTH + 1));
    $expect(false, 'Oversized object keys must fail before reaching SQL');
} catch (InvalidArgumentException) {}

$service = (string)file_get_contents(dirname(__DIR__) . '/lib/ConfigurationSyncService.php');
$module = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$manifest = (string)file_get_contents(dirname(__DIR__) . '/module.xml');
$expect(str_contains($service, 'object_type VARCHAR(64) NOT NULL'), 'New MySQL schemas must allocate the shared 64-character object-type width');
$expect(str_contains($service, 'ALTER TABLE ucmp_configuration_sync_items MODIFY object_type VARCHAR('), 'Existing MySQL schemas must widen object_type in place');
$expect(str_contains($manifest, '<field name="object_type" type="string" length="64"/>'), 'FreePBX manifest schema must match the runtime object-type width');
$expect(substr_count($module, 'ConfigurationSyncService::normalizeObjectType') >= 4, 'Federation entry points must validate the shared object-type contract');
$expect(!str_contains($service, "substr((string)(\$item['object_type'] ?? ''), 0, 32)"), 'Controller ownership rows must never truncate object types to the obsolete width');
$expect(!str_contains($module, "ConfigurationSyncService::OBJECT_TYPE_MAX_LENGTH)"), 'Federation dispatch must reject invalid values instead of silently truncating them');

echo "Configuration sync schema contract assertions passed\n";
