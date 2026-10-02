<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/ApiCodec.php';
require_once dirname(__DIR__) . '/lib/RpcOperationException.php';
require_once dirname(__DIR__) . '/lib/RpcResponse.php';
require_once dirname(__DIR__) . '/lib/RpcOperationJournal.php';

use FreePBX\modules\Ucmprovisioner\{ApiCodec, RpcOperationException, RpcOperationJournal, RpcResponse};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$key = bin2hex(random_bytes(32));
$seal = static fn(string $s): string=>ApiCodec::seal(['data'=>$s], $key);
$open = static fn(string $s): string=>ApiCodec::open($s, $key)['data'];
$journal = new RpcOperationJournal($db, $seal, $open);
$calls = 0;
foreach ([0, 42, '', 'typed-value', false, null, ['nested'=>[1,2]]] as $value) {
    $id = bin2hex(random_bytes(16));
    $request = ['method'=>'test', 'args'=>[$value]];
    $response = $journal->execute($id, $request, static function () use (&$calls, $value) { $calls++; return $value; });
    $before = $calls;
    $again = $journal->execute($id, $request, static function () { throw new RuntimeException('Must never replay'); });
    if (RpcResponse::unwrap($response) !== $value || RpcResponse::unwrap($again) !== $value || $calls !== $before) {
        throw new RuntimeException('Typed operation results or repeat suppression failed');
    }
    try {
        $journal->execute($id, ['method'=>'different'], static fn()=>null);
        throw new LogicException('Operation ID accepted a different request');
    } catch (RuntimeException $expected) {}
}
$id = bin2hex(random_bytes(16));
$nested = null;
$journal->execute($id, ['method'=>'running'], function () use ($journal,$id,&$nested) {
    $nested = $journal->execute($id, ['method'=>'running'], static function () { throw new LogicException('Concurrent operation replayed'); });
    return true;
});
if (($nested['state'] ?? '') !== 'running') { throw new RuntimeException('Concurrent duplicate did not preserve pending state'); }

$id = bin2hex(random_bytes(16));
$failure = $journal->execute($id, ['method'=>'fails'], static function () { throw new RuntimeException('fixture failure'); });
try { RpcResponse::unwrap($failure); throw new LogicException('Operation failure was not distinguished'); }
catch (RpcOperationException $expected) {}
$again = $journal->execute($id, ['method'=>'fails'], static function () { throw new LogicException('Failed operation replayed'); });
if (($again['state'] ?? '') !== 'failed') { throw new RuntimeException('Failed receipt was not retained'); }

$id = bin2hex(random_bytes(16));
$journal->execute($id, ['method'=>'secret'], static fn()=>['secret'=>'receipt-private-marker']);
$stored = (string)$db->query("SELECT response_enc FROM ucmp_rpc_operations WHERE operation_id='$id'")->fetchColumn();
if (str_contains($stored,'receipt-private-marker')) { throw new RuntimeException('Sensitive response persisted unencrypted'); }
if (($journal->status(bin2hex(random_bytes(16)))['state'] ?? '') !== 'unknown') { throw new RuntimeException('Missing receipt was not unknown'); }

foreach ([
    ['status'=>'unknown','recovery_required'=>true,'failed_stage'=>'database_commit','recovery_id'=>'fixture-recovery'],
    ['status'=>'partial','items'=>[['object_key'=>'group-one','status'=>'success'],['object_key'=>'group-two','status'=>'failed','message'=>'fixture-private-marker','intended_json'=>'private-payload']]],
    RpcResponse::completed(['ok'=>false,'status'=>'unknown','recovery_required'=>true,'transaction_id'=>'fixture-transaction'], str_repeat('b',32)),
] as $receipt) {
    $id = bin2hex(random_bytes(16));
    $error = new RpcOperationException('fixture-private-marker', $receipt);
    $expectedResult = RpcResponse::failureResult($error);
    $response = $journal->execute($id, ['method'=>'recovery'], static function () use ($error) { throw $error; });
    $again = $journal->execute($id, ['method'=>'recovery'], static function () { throw new LogicException('Recovery operation replayed'); });
    $status = $journal->status($id);
    unset($again['created'],$again['updated'],$status['created'],$status['updated']);
    if ($again !== $response || $status !== $response || $response['result'] !== $expectedResult) {
        throw new RuntimeException('Structured failure changed across execution, retry or status reconciliation');
    }
    try { RpcResponse::unwrap($response); throw new LogicException('Recovery result accepted as success'); }
    catch (RpcOperationException $caught) {
        if ($caught->receipt !== $response) { throw new RuntimeException('Unwrap discarded recovery metadata'); }
        $public = RpcResponse::publicFailureResult($caught, static fn(string $text): string => str_replace('fixture-private-marker','[redacted]',$text));
        if ($public['status'] !== $expectedResult['status'] || $public['rpc_receipt']['operation_id'] !== $id
            || str_contains(json_encode($public),'fixture-private-marker') || str_contains(json_encode($public),'private-payload')) {
            throw new RuntimeException('UI projection lost recovery state or disclosed private payload');
        }
    }
    $stored = (string)$db->query("SELECT response_enc FROM ucmp_rpc_operations WHERE operation_id='$id'")->fetchColumn();
    if (str_contains($stored, 'fixture-private-marker') || str_contains($stored, 'private-payload')) { throw new RuntimeException('Failure payload persisted in plaintext'); }
}

$db->exec("CREATE TRIGGER refuse_receipt BEFORE UPDATE ON ucmp_rpc_operations BEGIN SELECT RAISE(IGNORE); END");
$id = bin2hex(random_bytes(16));
$executions = 0;
try {
    $journal->execute($id, ['method'=>'lost-receipt'], static function () use (&$executions) { $executions++; return true; });
    throw new LogicException('Lost receipt finalization was reported successful');
} catch (RuntimeException $expected) {}
$retry = $journal->execute($id, ['method'=>'lost-receipt'], static function () use (&$executions) { $executions++; return true; });
if ($executions !== 1 || ($retry['state'] ?? '') !== 'running') { throw new RuntimeException('Lost receipt permitted replay'); }
echo "Typed RPC, encrypted destination receipt and uncertain replay fixtures passed\n";
