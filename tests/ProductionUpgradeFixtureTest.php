<?php
declare(strict_types=1);

/*
 * Schema-only fixture derived from a pre-0.17 production installation on
 * 2026-07-26.  No database rows, credentials, addresses or other production
 * data are included.  Original module.xml SHA-256:
 * 782d9b633d4954b301b8d3cfb8aea4866963df10c84214652fd8a3bbfa4de8f8
 */
$production016 = [
    'ucmp_sites' => ['id','site_key','name','is_local','enabled','pbx_addr','pbx_sip_port','pbx_host','pbx_ssh_port','pbx_ssh_user','pbx_ssh_secret','pbx_host_key','pbx_agent_user','pbx_agent_ready','pbx_bootstrapped_at','pbx_api_enabled','pbx_api_ready','pbx_api_scheme','pbx_api_host','pbx_api_port','pbx_api_verify_tls','pbx_api_secret','pbx_api_last_ok','docker_host','docker_ssh_port','docker_ssh_user','docker_ssh_secret','docker_host_key','docker_sudo','tftp_root_path','tftp_service_host','tftp_container','tftp_bind_path','docker_bootstrapped_at','voice_subnets','trunk_endpoint_prefix','trunk_endpoint','remote_trunk_endpoint'],
    'ucmp_models' => ['id','vendor','model','family','role','sip_accounts','line_appearances','programmable_keys','programmable_key_namespace','programmable_key_pages','handsets_max','simultaneous_calls','expansion_types','max_expansion_modules','keys_per_expansion','config_profile','firmware_profile','directory_profile','media_profile','auth_profile','line_keys','supports_softkeys','supports_featurepolicy','kem_support','default_firmware','notes'],
    'ucmp_templates' => ['id','name','site_id','target_family','target_model','phonebook_key','phonebook_filename','phonebook_source_site','phonebook_source_url','buttons','capacity_overrides','softkeys','feature_policy','setting_overrides','member_blf','updated'],
    'ucmp_groups' => ['id','name','site_id','template_id','template_map','member_blf','extra_targets','updated'],
    'ucmp_phones' => ['id','site_id','group_id','mac','vendor','model_id','family','ip','extension','prev_extension','transport','label','overrides','lines_json','status','last_seen','last_command','last_command_at','last_applied','config_filename','updated'],
    'ucmp_settings' => ['id','site_id','skey','sval'],
    'ucmp_phone_services' => ['id','site_id','service_key','name','service_type','enabled','locked','menu_order','settings_json','updated'],
    'ucmp_operation_log' => ['id','created','site_id','level','operation','message','detail'],
    'ucmp_directory_entries' => ['id','source_key','display_name','label','extension','department','company','org_unit','mail','mobile','attributes','source_dn','site_id','conflicted','applied_hash','updated'],
    'ucmp_directory_conflicts' => ['id','conflict_key','conflict_type','conflict_value','candidates','resolution_key','status','notified','updated'],
    'ucmp_phonebooks' => ['id','book_key','name','filename','title','include_mode','rules','exclusions','manual_entries','group_by','hierarchy','publish_sites','name_format','sort_by','include_mobile','cisco_page_size','is_default','updated'],
    'ucmp_firmware_bundles' => ['id','site_id','vendor','profile','load_name','files_json','missing_json','status','scanned_at'],
];

$root = dirname(__DIR__);
$xml = simplexml_load_file($root . '/module.xml');
if (!$xml || !isset($xml->database)) {
    throw new RuntimeException('Current module.xml is not readable');
}

$current = [];
foreach ($xml->database->table as $table) {
    $name = (string)$table['name'];
    $current[$name] = [];
    foreach ($table->field as $field) {
        $current[$name][] = (string)$field['name'];
    }
}

foreach ($production016 as $table => $fields) {
    if (!array_key_exists($table, $current)) {
        throw new RuntimeException("0.16.0 table was removed: $table");
    }
    $missing = array_values(array_diff($fields, $current[$table]));
    if ($missing !== []) {
        throw new RuntimeException("$table removed 0.16.0 fields: " . implode(', ', $missing));
    }
}

$requiredNewTables = [
    'ucmp_apply_transactions',
    'ucmp_assets',
    'ucmp_default_template_rules',
    'ucmp_directory_sources',
    'ucmp_directory_sync_runs',
    'ucmp_extensions',
    'ucmp_identity_overrides',
    'ucmp_phone_builds',
    'ucmp_site_jobs',
    'ucmp_site_snapshots',
];
foreach ($requiredNewTables as $table) {
    if (!array_key_exists($table, $current)) {
        throw new RuntimeException("Upgrade table is absent from module.xml: $table");
    }
}

$runtimeSchema = (string)file_get_contents($root . '/Ucmprovisioner.class.php')
    . (string)file_get_contents($root . '/lib/BuildTransactionService.php')
    . (string)file_get_contents($root . '/lib/LogicalExtensionService.php')
    . (string)file_get_contents($root . '/lib/ManagedAssetRegistry.php');
foreach ($requiredNewTables as $table) {
    if (!str_contains($runtimeSchema, $table)) {
        throw new RuntimeException("Runtime schema migration does not mention $table");
    }
}

foreach ([
    'ucmp_directory_conflicts' => ['source_id'],
    'ucmp_directory_entries' => ['source_id','object_key','source_revision','last_seen_at'],
    'ucmp_groups' => ['parent_group_id','member_exceptions','revision'],
    'ucmp_models' => ['series_key'],
    'ucmp_phones' => ['extension_id'],
    'ucmp_templates' => ['target_vendor','target_series','target_role','revision'],
] as $table => $fields) {
    foreach ($fields as $field) {
        if (!str_contains($runtimeSchema, $field)) {
            throw new RuntimeException("Runtime upgrade path does not mention $table.$field");
        }
    }
}

foreach (['ensureTemplateNameIntegrity', 'uniq_ucmp_templates_site_name', 'duplicate #', 'already exists on this site'] as $contract) {
    if (!str_contains($runtimeSchema, $contract)) {
        throw new RuntimeException("Template-name upgrade contract is missing: $contract");
    }
}

echo "Production 0.16.0 schema remains a strict subset with runtime upgrade hooks\n";

// An installed module must not need a writable/schema-capable connection to open a page.
interface BMO {}
class FreePBX_Helpers {}
require_once $root . '/Ucmprovisioner.class.php';
$installed = new class extends \FreePBX\modules\Ucmprovisioner {
    public int $versionReads = 0;
    public function __construct() {}
    public function getSetting(string $key, ?int $siteId = null, $default = null) {
        if ($key !== 'schema_version') { throw new RuntimeException('Unexpected request-time repair'); }
        $this->versionReads++;
        return (new ReflectionClass(\FreePBX\modules\Ucmprovisioner::class))->getConstant('SCHEMA_VERSION');
    }
};
$installed->ensureSchema();
if ($installed->versionReads !== 1) { throw new RuntimeException('Installed schema should need only one version read'); }
echo "Installed module skips request-time schema repair\n";

// Exercise the actual migration failure boundary with an unavailable identity table.
$migrationDb = new class('sqlite::memory:') extends PDO {
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if ($query === 'SHOW COLUMNS FROM ucmp_phones') {
            return parent::query("SELECT 'driver' UNION ALL SELECT 'sip_port' UNION ALL SELECT 'applied_driver' UNION ALL SELECT 'applied_transport' UNION ALL SELECT 'applied_sip_port'");
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function exec(string $statement): int|false {
        if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS ucmp_phone_mac_suppressions')) { return 0; }
        return parent::exec($statement);
    }
};
$migrationModule = new class($migrationDb) extends \FreePBX\modules\Ucmprovisioner {
    public array $errors = [];
    public function __construct(PDO $db) { parent::__construct(new class($db) { public function __construct(private PDO $db) {} public function Database(): PDO { return $this->db; } }); }
    public function getSetting(string $key, ?int $siteId = null, $default = null) { return $key === 'schema_version' ? 32 : $default; }
    public function addError($message) { $this->errors[] = $message; }
};
$migrationRejected = false;
try { $migrationModule->ensureSchema(true); }
catch (RuntimeException $error) { $migrationRejected = str_contains($error->getMessage(), 'Logical extension schema is unavailable'); }
if (!$migrationRejected) { throw new RuntimeException('A failed migration must stop installation before catalog/version advancement'); }
echo "Failed schema repair propagates to its caller\n";

$phoneRepairDb = new class('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]) extends PDO {
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_starts_with($query, 'SHOW INDEX FROM ucmp_phones')) {
            return parent::query("SELECT 'site_id' Column_name, 1 Seq_in_index, 0 Non_unique UNION ALL SELECT 'mac', 2, 0");
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
};
$phoneColumns = array_map(static fn(string $field): string => $field === 'id' ? 'id INTEGER PRIMARY KEY' : '"' . $field . '" TEXT', $current['ucmp_phones']);
$phoneRepairDb->exec('CREATE TABLE ucmp_phones (' . implode(',', $phoneColumns) . ')');
$phoneRepairDb->exec("INSERT INTO ucmp_phones (id,site_id,mac,label,updated,config_mode,config_filename) VALUES (1,1,'001122334455','Retained label',1,'manual','retained.cnf'),(2,1,'001122334455','',2,'managed',NULL)");
$phoneRepairDb->exec("CREATE TRIGGER fail_phone_repair BEFORE UPDATE ON ucmp_phones WHEN NEW.id=2 BEGIN SELECT RAISE(ABORT,'Fixture merged-row failure'); END");
$phoneRepairDb->exec('CREATE TABLE outer_fixture (value TEXT)');
$phoneRepairModule = new class($phoneRepairDb) extends \FreePBX\modules\Ucmprovisioner {
    public function __construct(PDO $db) { parent::__construct(new class($db) { public function __construct(private PDO $db) {} public function Database(): PDO { return $this->db; } }); }
    public function localSiteId(): int { return 1; }
};
$repair = new ReflectionMethod(\FreePBX\modules\Ucmprovisioner::class, 'ensurePhoneIntegrity');
$beforePhones = $phoneRepairDb->query('SELECT * FROM ucmp_phones ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ([false,true] as $outerTransaction) {
    if ($outerTransaction) { $phoneRepairDb->beginTransaction(); $phoneRepairDb->exec("INSERT INTO outer_fixture VALUES ('preserve')"); }
    $repairFailed = false;
    try { $repair->invoke($phoneRepairModule); } catch (RuntimeException|PDOException $error) { $repairFailed = str_contains($error->getMessage(), 'Fixture merged-row failure'); }
    if (!$repairFailed || $phoneRepairDb->query('SELECT * FROM ucmp_phones ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) !== $beforePhones) {
        throw new RuntimeException('Failed duplicate repair deleted original phone data');
    }
    if ($outerTransaction) {
        if (!$phoneRepairDb->inTransaction() || (int)$phoneRepairDb->query('SELECT COUNT(*) FROM outer_fixture')->fetchColumn() !== 1) {
            throw new RuntimeException('Duplicate repair failure discarded unrelated outer work');
        }
        $phoneRepairDb->commit();
    }
}
$phoneRepairDb->exec('DROP TRIGGER fail_phone_repair');
$repair->invoke($phoneRepairModule);
$mergedPhones = $phoneRepairDb->query('SELECT id,label,config_mode,config_filename FROM ucmp_phones')->fetchAll(PDO::FETCH_ASSOC);
if (count($mergedPhones) !== 1 || (int)$mergedPhones[0]['id'] !== 2 || $mergedPhones[0]['label'] !== 'Retained label' || $mergedPhones[0]['config_filename'] !== 'retained.cnf') {
    throw new RuntimeException('Successful duplicate repair must retain older useful/manual configuration fields');
}
echo "Duplicate-phone repair is atomic inside and outside an existing transaction\n";
