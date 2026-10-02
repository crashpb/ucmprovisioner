<?php
namespace FreePBX\modules\Ucmprovisioner;

final class RpcResponse
{
    /** Preserve a local recovery result or unwrap an already receipted failure. */
    public static function failureResult(RpcOperationException $error): array
    {
        $receipt = $error->receipt;
        $result = is_array($receipt['result'] ?? null) ? $receipt['result'] : $receipt;
        $result['ok'] = false;
        $result['message'] = (string)($result['message'] ?? $error->getMessage());
        if (!isset($result['status'])) {
            $result['status'] = in_array($receipt['state'] ?? '', ['unknown','unresolved','running','recovery_required'], true)
                ? 'unknown' : 'error';
        }
        if (in_array($result['status'], ['unknown','unresolved','recovery_required'], true)) { $result['recovery_required'] = true; }
        return $result;
    }

    public static function completed($result, string $operationId = ''): array
    {
        return ['rpc_version'=>2, 'state'=>'completed', 'operation_id'=>$operationId, 'result'=>$result];
    }

    /** UI metadata only. Full recovery payloads stay in the encrypted operation journal. */
    public static function publicFailureResult(RpcOperationException $error, callable $redact): array
    {
        $project = static function (array $value, array $keys) use ($redact): array {
            $safe = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $value) && is_scalar($value[$key])) {
                    $safe[$key] = is_string($value[$key]) ? $redact($value[$key]) : $value[$key];
                }
            }
            return $safe;
        };
        $result = self::failureResult($error);
        $safe = $project($result, ['status','recovery_required','failed_stage','recovery_id','transaction_id','publication_completed','message']);
        if (is_array($result['items'] ?? null)) {
            $safe['items'] = array_map(static fn(array $item): array => $project($item,
                ['object_type','object_key','status','destination_id','message','recovery_required']),
                array_values(array_filter($result['items'], 'is_array')));
        }
        $safe['rpc_receipt'] = $project($error->receipt, ['operation_id','state','created','updated']);
        return $safe;
    }

    /** Preserve scalar/null CRUD results and distinguish operation failures from transport failures. */
    public static function unwrap($response)
    {
        $receipt = is_array($response) ? $response : [];
        if (($receipt['rpc_version'] ?? null) === 2) {
            if (($receipt['state'] ?? '') !== 'completed' || !array_key_exists('result', $receipt)) {
                throw new RpcOperationException((string)($receipt['message'] ?? 'Remote operation is unresolved; do not replay it'), $receipt);
            }
            $response = $receipt['result'];
        }
        if (is_array($response) && array_key_exists('ok', $response) && $response['ok'] === false) {
            throw new RpcOperationException(substr((string)($response['message'] ?? 'Remote operation failed'), 0, 1000), $receipt);
        }
        return $response;
    }
}
