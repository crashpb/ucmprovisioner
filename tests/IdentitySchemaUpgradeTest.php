<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\LogicalExtensionService;
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE ucmp_extensions (id INTEGER PRIMARY KEY,site_id INTEGER,extension TEXT,ad_source_id INTEGER,ad_object_key TEXT,applied_identity_hash TEXT,revision INTEGER)');
$db->exec("INSERT INTO ucmp_extensions VALUES(1,1,'4308',7,'stable-person','prior-hash',9)");
LogicalExtensionService::ensureIdentitySchema($db);
$first = $db->query('SELECT * FROM ucmp_extensions')->fetch(PDO::FETCH_ASSOC);
LogicalExtensionService::ensureIdentitySchema($db);
$second = $db->query('SELECT * FROM ucmp_extensions')->fetch(PDO::FETCH_ASSOC);
if ($first !== $second || $second['identity_adoption'] !== 'legacy_review'
    || $second['binding_mode'] !== 'ad_primary' || $second['ad_object_key'] !== 'stable-person'
    || $second['applied_identity_hash'] !== 'prior-hash' || (int)$second['revision'] !== 9) {
    throw new RuntimeException('Additive repeatable migration must preserve ownership and require legacy adoption review');
}
$source = file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
if (!str_contains($source, 'if ($stored >= 32) {')) {
    throw new RuntimeException('Upgrade from schema 32 must not replay historical transport migration');
}
$xml = simplexml_load_file(dirname(__DIR__) . '/module.xml');
$fields = array_map('strval', $xml->xpath('//table[@name="ucmp_extensions"]/field/@name'));
foreach (['binding_mode','linked_source_id','linked_object_key','identity_adoption','manual_identity_json','canonical_identity_hash'] as $field) {
    if (!in_array($field, $fields, true)) { throw new RuntimeException('Module Admin schema missing ' . $field); }
}
echo "Identity additive schema upgrade fixtures passed\n";
