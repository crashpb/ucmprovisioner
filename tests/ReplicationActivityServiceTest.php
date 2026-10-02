<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ReplicationActivityService.php';

use FreePBX\modules\Ucmprovisioner\ReplicationActivityService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new ReplicationActivityService($db);
$configuration = $service->describeResult(['ok'=>false,'targets'=>[2=>['ok'=>true],3=>['ok'=>false,'message'=>'fixture failure']]],[2,3]);
$expect(array_column($configuration['destination_results'],'status') === ['success','error'], 'Configuration Sync targets must retain known success and failure');
$uncertain = $service->describeResult(['ok'=>false,'sites'=>[2=>['ok'=>false,'status'=>'recovery_required']]],[2]);
$expect($uncertain['destination_results'][0]['status'] === 'unknown', 'Status-only recovery must not become an ordinary failed destination');
$first = $service->begin('firmware','Firmware inventory',1,[4,4,2],['operation'=>'copy']);
$targeted = $service->describeResult(['ok'=>false,'operations'=>[
    ['sites'=>[2=>['ok'=>true],3=>['ok'=>true]]],
    ['target_site_id'=>2,'sites'=>[2=>['ok'=>false,'status'=>'unknown']]],
]],[2,3]);
$expect(array_column($targeted['destination_results'],'status') === ['unknown','success'], 'One-site activation failure must not invent a missing result on an unrelated site');
$paused = $service->describeResult(['ok'=>true,'targets'=>[2=>['ok'=>true,'needs_confirmation'=>true,'status'=>'conflict']]],[2]);
$expect($paused['destination_results'][0]['status'] !== 'success', 'A paused conflict is not a completed destination');
$second = $service->begin('media','Wallpaper catalog',1,[4],['operation'=>'sync']);
$expect((int)$first['id'] > 0 && (int)$second['id'] > (int)$first['id'], 'Concurrent replications must receive independent durable run IDs');
$runs = $service->recent();
$expect(count(array_filter($runs, static fn(array $row): bool => $row['status'] === 'running')) === 2, 'Both concurrent runs must remain visible');
$service->update((int)$first['id'],1,2,'Site 2','One destination verified');
$service->finish((int)$first['id'],['ok'=>true,'status'=>'success','message'=>'Firmware copied']);
$service->fail((int)$second['id'],new RuntimeException('Destination unavailable'));
$runs = $service->recent();
$byId = [];
foreach ($runs as $row) { $byId[(int)$row['id']] = $row; }
$expect(($byId[(int)$first['id']]['status'] ?? '') === 'success', 'Successful run state must survive a later list request');
$expect(($byId[(int)$second['id']]['status'] ?? '') === 'error', 'Failed run state must survive a later list request');
$expect(($byId[(int)$first['id']]['target_site_ids'] ?? []) === [4,2] || ($byId[(int)$first['id']]['target_site_ids'] ?? []) === [2,4], 'Run history must retain destination context');

$db->exec('CREATE TABLE ucmp_sites (id INTEGER PRIMARY KEY,name TEXT)');
$db->exec("INSERT INTO ucmp_sites VALUES (2,'Two'),(3,'Three'),(4,'Four')");
$live=$service->begin('firmware','Live transfer',1,[2,3]);
$service->plan($live['id'],['firmware_packages'=>2,'selected_bytes_all_sites'=>10000]);
$service->progress($live['id'],1,'Reading source','Source hashing');
$service->progress($live['id'],2,'Sending','First package',null,['id'=>'first','sent'=>1500,'total'=>2000,'unchanged'=>100]);
$service->progress($live['id'],2,'Sending','First package',null,['id'=>'first','sent'=>2000,'total'=>2000,'unchanged'=>100]);
$service->progress($live['id'],2,'Sending','Second package',null,['id'=>'second','sent'=>500,'total'=>5000,'unchanged'=>300]);
$service->progress($live['id'],2,'Sending','Repeated poll',null,['id'=>'second','sent'=>500,'total'=>5000,'unchanged'=>300]);
$scopeRows=array_column($service->recent(),null,'id');
$expect($scopeRows[$live['id']]['bytes_sent']===2500 && $scopeRows[$live['id']]['bytes_unchanged']===400,'Whole-job byte counts must accumulate across packages without counting repeated progress twice');
$expect(count($scopeRows[$live['id']]['destination_results'])===1 && $scopeRows[$live['id']]['plan']['selected_bytes_all_sites']===10000,'Source work must not create a destination row or reset the selection total');
$service->progress($live['id'],2,'Transferring files','1048576 / 2097152 bytes staged');
$liveRows=array_column($service->recent(),null,'id');
$expect($liveRows[$live['id']]['current_item']==='Two: Transferring files' && $liveRows[$live['id']]['destination_results'][0]['status']==='running','Byte progress must survive polling without falsely completing the destination');
$service->progress($live['id'],2,'Files committed','One package committed',['ok'=>true]);
$liveRows=array_column($service->recent(),null,'id');
$expect($liveRows[$live['id']]['status']==='running' && $liveRows[$live['id']]['destination_results'][0]['succeeded']===1,'A committed sub-operation must remain visible while later scopes run');
$stageFailure=$service->describeResult(['ok'=>false,'sites'=>[2=>['ok'=>false,'copied'=>65,'skipped'=>0,'hosted_media'=>[['ok'=>true,'copied'=>2],['ok'=>false,'message'=>'busy']]]]], [2]);
$expect($stageFailure['destination_results'][0]['status']==='partial' && $stageFailure['destination_results'][0]['succeeded']===2 && $stageFailure['destination_results'][0]['failed']===1,'Hosted-media failure must preserve successful TFTP and earlier media stages');
$failedMutation=\FreePBX\modules\Ucmprovisioner\ReplicationActivityService::failedOperation(new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Handler failed after writing',['state'=>'failed','message'=>'Handler failed after writing']),[2],'Media');
$expect($failedMutation['sites'][2]['status']==='unknown','A generic failed RPC can have written data and must not be reclassified as a pre-write rejection');
$mixed = ['ok'=>false,'sites'=>[2=>['ok'=>true,'copied'=>3],3=>['ok'=>false,'message'=>'Storage locked']]];
$described = $service->describeResult($mixed,[2,3,4]);
$expect(array_column($described['destination_results'],'status') === ['success','error','unknown'], 'Missing destination results must not be called successful');
$expect($described['status'] === 'partial' && str_contains($described['destination_summary'],'1 succeeded, 1 failed'), 'Partial success must be explicit');
$expect($described['destination_results'][0]['name'] === 'Two', 'Destination names must be included');
$nested = $service->describeResult(['ok'=>false,'operations'=>[$mixed,['libraries'=>[['sites'=>[2=>['ok'=>false,'message'=>'Later scope failed'],3=>['ok'=>true]]]]]]],[2,3]);
$expect(array_column($nested['destination_results'],'status') === ['partial','partial'], 'A later scope failure must not erase an earlier success');
$missingLater = $service->describeResult(['ok'=>false,'operations'=>[$mixed,['sites'=>[3=>['ok'=>true]]]]],[2,3]);
$expect($missingLater['destination_results'][0]['status'] === 'unknown', 'An earlier success cannot hide a missing later scope result');
$expect(empty($missingLater['destination_results'][0]['details'][1]['recovery_required']), 'Missing reports do not prove that a storage recovery is needed');
$uncertain = $service->describeResult(['ok'=>false,'sites'=>[2=>['ok'=>false,'recovery_required'=>true,'operation_id'=>'retained']]], [2]);
$expect($uncertain['destination_results'][0]['status'] === 'unknown', 'Unresolved transfer must not be presented as an ordinary retryable failure');
$large = $service->begin('media','Large inventory',1,[2,3]);
$service->finish($large['id'],$mixed+['assets'=>str_repeat('x',300000)]);
$saved = json_decode((string)$db->query('SELECT result_json FROM ucmp_replication_runs WHERE id='.(int)$large['id'])->fetchColumn(),true);
$expect(!empty($saved['truncated']) && count($saved['destination_results'])===2, 'Large inventories must retain destination outcomes when file details are truncated');
$legacy = $service->begin('media','Legacy result',1,[2,3]);
$db->prepare("UPDATE ucmp_replication_runs SET status='error',result_json=? WHERE id=?")->execute([json_encode($mixed),$legacy['id']]);
$legacyRows = array_column($service->recent(),'destination_results','id');
$expect(count($legacyRows[$legacy['id']])===2,'Previously stored run results must gain destination details without database migration');

$stale = $service->begin('media','Retained transfer',1,[2]);
$db->prepare('UPDATE ucmp_replication_runs SET updated=? WHERE id=?')->execute([time()-22000,$stale['id']]);
$before = $db->query('SELECT * FROM ucmp_replication_runs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$db->exec('PRAGMA query_only = ON');
$readOnlyService = new ReplicationActivityService($db);
$rows = $readOnlyService->recent();
$after = $db->query('SELECT * FROM ucmp_replication_runs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$expect($before === $after, 'Opening activity status must not update retained operation state');
$staleRows = array_values(array_filter($rows, static fn(array $row): bool => $row['id'] === (int)$stale['id']));
$expect(($staleRows[0]['status'] ?? '') === 'unknown', 'Old activity must be displayed as uncertain without claiming worker termination');

echo "Replication activity service assertions passed\n";

interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
$db->exec('PRAGMA query_only = OFF');
$module = (new ReflectionClass(FreePBX\modules\Ucmprovisioner::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($module, 'db'))->setValue($module, $db);
$tracked = new ReflectionMethod($module, 'trackedReplication');
$db->exec("CREATE TRIGGER fail_final_receipt BEFORE UPDATE ON ucmp_replication_runs WHEN NEW.finished > 0 BEGIN SELECT RAISE(ABORT, 'fixture history write failed'); END");
$calls = 0;
$result = $tracked->invoke($module, 'media', 'Fixture', ['site_id'=>1,'target_site_ids'=>[2,3]], static function () use (&$calls, $mixed): array { $calls++; return $mixed; });
$expect($calls === 1 && $result['sites'] === $mixed['sites'] && !empty($result['reporting_error'])
    && array_column($result['destination_results'], 'status') === ['success','error'],
    'A final history write failure must retain exact destination results without replaying the operation');
$primary = new RuntimeException('fixture operation failure');
try {
    $tracked->invoke($module, 'media', 'Fixture', ['site_id'=>1,'target_site_ids'=>[2]], static function () use ($primary): array { throw $primary; });
    $expect(false, 'Operation failure must remain visible');
} catch (RuntimeException $error) { $expect($error === $primary, 'A failed history write must not replace the primary exception'); }
echo "Tracked replication receipt failure assertions passed\n";
