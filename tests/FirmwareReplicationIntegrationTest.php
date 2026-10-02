<?php
declare(strict_types=1);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$xml = (string)file_get_contents($root . '/module.xml');
$cli = (string)file_get_contents($root . '/cli_media_sync.php');

$expect(str_contains($module, "'firmwarereplication'"), 'Firmware replication must be an authenticated AJAX command');
$expect(str_contains($module, '$this->firmwareReplicationOp($_REQUEST)'), 'The firmware replication AJAX command must reach the central dispatcher');
$expect(substr_count($module, 'FirmwareReplicationService::ensureSchema($this->db)') >= 3, 'Firmware replication schema must be guarded on startup, upgrade, and service use');
$expect(str_contains($module, 'public function firmwareReplicationOp(array $req)'), 'The central module must expose firmware replication CRUD/copy operations');
$expect(str_contains($module, 'public function runScheduledFirmwareReplication()'), 'The central module must expose the scheduled firmware runner');
require_once $root . '/lib/ConfigurationSyncService.php';
$expect(in_array('ntp_server', \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_FIELDS['settings_clock_date'], true), 'Clock/date replication must include the shared NTP setting');
$expect(str_contains($module, '$settingScopes[$key] = $scope') && str_contains($module, "configurationSyncEditGuard(\$scope,0,'setting:' . \$key,\$d)"), 'Manual Settings edits must use the shared field-to-scope ownership guard');
$expect(str_contains($xml, '<field name="object_filter_json"'), 'Configuration sync jobs must persist granular object filters in module metadata');
$expect(str_contains($cli, '$module->runScheduledFirmwareReplication()'), 'The minute scheduler CLI must invoke firmware replication');

$entry = str_replace("require_once '/etc/freepbx.conf';", '', substr($cli, 5));
$fixture = <<<'PHP'
class FreePBX {
    public static function Ucmprovisioner(): object { return new class {
        public function runScheduledMediaReplication(): array { throw new RuntimeException('fixture media unavailable'); }
        public function runScheduledConfigurationSync(): array { return ['ok'=>true,'message'=>'configuration completed']; }
        public function runScheduledFirmwareReplication(): array { return ['ok'=>true,'message'=>'firmware completed']; }
    }; }
}
PHP;
$process = proc_open([PHP_BINARY,'-r',$fixture . $entry], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot run CLI scheduler fixture'); }
$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
$expect($exit === 1 && str_contains($stderr,'fixture media unavailable') && str_contains($stderr,'configuration completed') && str_contains($stderr,'firmware completed'), 'A failed scheduler must not suppress the remaining schedulers or their results');
echo "Firmware replication central integration assertions passed\n";
