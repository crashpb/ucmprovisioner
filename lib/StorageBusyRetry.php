<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;
require_once __DIR__ . '/RpcOperationException.php';

/** Only a receipted refusal before lock acquisition is safe to submit again. */
final class StorageBusyRetry
{
    public const LEGACY_MESSAGE = 'Destination is locked by another operation; interrupted operations require recovery before unlocking';

    public static function beforeMutation(RpcOperationException $error): bool
    {
        $receipt = $error->receipt;
        $result = (array)($receipt['result'] ?? $receipt);
        if (!empty($result['recovery_required'])) { return false; }
        if ((!isset($receipt['state']) || $receipt['state'] === 'completed')
            && ($result['status'] ?? '') === 'busy' && ($result['mutation_started'] ?? null) === false) { return true; }
        return ($receipt['state'] ?? '') === 'failed' && ($receipt['message'] ?? '') === self::LEGACY_MESSAGE
            && !array_key_exists('result', $receipt);
    }

    public static function run(callable $operation, string $method, array $request, ?callable $waiting = null, int $timeoutMs = 15000): mixed
    {
        $eligible = $method === 'tftpTransferLocal'
            ? in_array($request['op'] ?? '', ['prepare','upload','upload_batch','commit','indexes'], true)
            : ($method === 'mediaAssetOpLocal' && in_array($request['op'] ?? '', ['upload_chunk','special_catalog_upload_chunk','special_catalog_activate'], true));
        $deadline = hrtime(true) + max(0, $timeoutMs) * 1000000;
        do {
            try { return $operation(); }
            catch (RpcOperationException $error) {
                // Older transfer handlers throw this exact error before entering their
                // locked callback. Completed/partial/unknown receipts never qualify.
                if (!$eligible || !self::beforeMutation($error) || hrtime(true) >= $deadline) { throw $error; }
                if ($waiting) { $waiting(); }
                usleep(250000);
            }
        } while (true);
    }
}
