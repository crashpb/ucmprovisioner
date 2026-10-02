<?php
namespace FreePBX\modules\Ucmprovisioner;

/** Destination receipts serialize an operation ID across HTTP and SSH without replaying uncertain writes. */
final class RpcOperationJournal
{
    public function __construct(private \PDO $db, private $encrypt, private $decrypt) {}

    public function ensureSchema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS ucmp_rpc_operations (
            operation_id VARCHAR(32) PRIMARY KEY, request_hash CHAR(64) NOT NULL,
            state VARCHAR(16) NOT NULL, response_enc LONGTEXT NULL,
            created INTEGER NOT NULL, updated INTEGER NOT NULL
        )');
    }

    public function status(string $id): array
    {
        $this->validateId($id);
        try {
            $st = $this->db->prepare('SELECT state,response_enc,created,updated FROM ucmp_rpc_operations WHERE operation_id=?');
            $st->execute([$id]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return ['rpc_version'=>2, 'state'=>'unknown', 'operation_id'=>$id,
                'message'=>'Destination operation journal is unavailable; do not replay the operation'];
        }
        if (!$row) {
            return ['rpc_version'=>2, 'state'=>'unknown', 'operation_id'=>$id,
                'message'=>'No destination receipt exists; execution remains unconfirmed'];
        }
        if (!empty($row['response_enc'])) {
            $response = json_decode(($this->decrypt)((string)$row['response_enc']), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($response)) { throw new \RuntimeException('Destination receipt cannot be decoded'); }
            return $response + ['created'=>(int)$row['created'], 'updated'=>(int)$row['updated']];
        }
        return ['rpc_version'=>2, 'state'=>(string)$row['state'], 'operation_id'=>$id,
            'created'=>(int)$row['created'], 'updated'=>(int)$row['updated'],
            'message'=>'Destination execution is pending or interrupted; reconcile before any new operation'];
    }

    public function execute(string $id, array $request, callable $operation): array
    {
        $this->validateId($id);
        $hash = hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->ensureSchema();
        $probe = 'rpc-receipt-preflight';
        if (($this->decrypt)(($this->encrypt)($probe)) !== $probe) {
            throw new \RuntimeException('Destination receipt encryption is unavailable');
        }
        try {
            $this->db->prepare('INSERT INTO ucmp_rpc_operations (operation_id,request_hash,state,created,updated) VALUES (?,?,?,?,?)')
                ->execute([$id,$hash,'running',time(),time()]);
        } catch (\PDOException $e) {
            $st = $this->db->prepare('SELECT request_hash FROM ucmp_rpc_operations WHERE operation_id=?');
            $st->execute([$id]);
            $existing = $st->fetchColumn();
            if ($existing === false) { throw $e; }
            if (!hash_equals((string)$existing, $hash)) {
                throw new \RuntimeException('Operation ID was already used for a different request');
            }
            return $this->status($id);
        }
        try {
            $response = RpcResponse::completed($operation(), $id);
        } catch (RpcOperationException $e) {
            // The invocation ended, but its writes may be partial or unknown.
            // Retain that distinction in the same encrypted, non-replayable result.
            $response = RpcResponse::completed(RpcResponse::failureResult($e), $id);
        } catch (\Throwable $e) {
            $response = ['rpc_version'=>2, 'state'=>'failed', 'operation_id'=>$id,
                'message'=>substr($e->getMessage(), 0, 1000)];
        }
        // Persist the exact typed response encrypted: RPC results can contain credentials.
        // A failed receipt write intentionally leaves 'running'; no timeout steals/replays it.
        $sealed = ($this->encrypt)(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!is_string($sealed) || $sealed === '') { throw new \RuntimeException('Could not encrypt the destination receipt'); }
        $save = $this->db->prepare('UPDATE ucmp_rpc_operations SET state=?,response_enc=?,updated=? WHERE operation_id=? AND state=?');
        $save->execute([$response['state'],$sealed,time(),$id,'running']);
        if ($save->rowCount() !== 1) { throw new \RuntimeException('Destination execution finished but its receipt could not be finalized'); }
        return $response;
    }

    private function validateId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) { throw new \InvalidArgumentException('Invalid operation ID'); }
    }
}
