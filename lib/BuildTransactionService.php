<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Small database ledger for build/apply state.
 *
 * Canonical file receipts remain beside each staged TFTP generation.  This
 * table stores only sanitized state and manifests needed by the UI.
 */
final class BuildTransactionService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        self::ensureSchema($db);
    }

    public static function ensureSchema(\PDO $db): void
    {
        $driver = (string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ucmp_apply_transactions (
                    transaction_id TEXT PRIMARY KEY,
                    site_id INTEGER NOT NULL,
                    operation TEXT NOT NULL,
                    state TEXT NOT NULL,
                    plan_hash TEXT NOT NULL,
                    prior_generation TEXT NOT NULL DEFAULT \'\',
                    new_generation TEXT NOT NULL DEFAULT \'\',
                    manifest_json TEXT NOT NULL,
                    details_json TEXT NOT NULL DEFAULT \'{}\',
                    started INTEGER NOT NULL,
                    finished INTEGER NULL,
                    error TEXT NOT NULL DEFAULT \'\',
                    actor TEXT NOT NULL DEFAULT \'\'
                )'
            );
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ucmp_phone_builds (
                    phone_id INTEGER PRIMARY KEY,
                    desired_hash TEXT NOT NULL,
                    applied_hash TEXT NOT NULL DEFAULT \'\',
                    manifest_json TEXT NOT NULL,
                    generation_id TEXT NOT NULL DEFAULT \'\',
                    previous_generation_id TEXT NOT NULL DEFAULT \'\',
                    planned_at INTEGER NOT NULL,
                    applied_at INTEGER NULL
                )'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_ucmp_apply_transactions_site_started
                 ON ucmp_apply_transactions (site_id, started)'
            );
            return;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `ucmp_apply_transactions` (
                `transaction_id` VARCHAR(40) NOT NULL,
                `site_id` INT UNSIGNED NOT NULL,
                `operation` VARCHAR(32) NOT NULL,
                `state` VARCHAR(32) NOT NULL,
                `plan_hash` CHAR(64) NOT NULL,
                `prior_generation` VARCHAR(96) NOT NULL DEFAULT '',
                `new_generation` VARCHAR(96) NOT NULL DEFAULT '',
                `manifest_json` LONGTEXT NOT NULL,
                `details_json` LONGTEXT NOT NULL,
                `started` BIGINT UNSIGNED NOT NULL,
                `finished` BIGINT UNSIGNED NULL,
                `error` TEXT NOT NULL,
                `actor` VARCHAR(128) NOT NULL DEFAULT '',
                PRIMARY KEY (`transaction_id`),
                KEY `idx_ucmp_apply_transactions_site_started` (`site_id`,`started`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `ucmp_phone_builds` (
                `phone_id` INT UNSIGNED NOT NULL,
                `desired_hash` CHAR(64) NOT NULL,
                `applied_hash` CHAR(64) NOT NULL DEFAULT '',
                `manifest_json` LONGTEXT NOT NULL,
                `generation_id` VARCHAR(96) NOT NULL DEFAULT '',
                `previous_generation_id` VARCHAR(96) NOT NULL DEFAULT '',
                `planned_at` BIGINT UNSIGNED NOT NULL,
                `applied_at` BIGINT UNSIGNED NULL,
                PRIMARY KEY (`phone_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** @param array<string,mixed> $manifest */
    public function begin(array $manifest, string $actor = ''): void
    {
        $transactionId = (string)($manifest['transaction_id'] ?? '');
        BuildPlanner::assertTransactionId($transactionId);
        $planHash = strtolower((string)($manifest['plan_hash'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $planHash)) {
            throw new \InvalidArgumentException('Invalid build plan hash');
        }
        $stmt = $this->db->prepare(
            'INSERT INTO ucmp_apply_transactions
             (transaction_id,site_id,operation,state,plan_hash,prior_generation,new_generation,
              manifest_json,details_json,started,finished,error,actor)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $transactionId,
            (int)$manifest['site_id'],
            'phone_build',
            'planned',
            $planHash,
            (string)($manifest['previous_generation_id'] ?? ''),
            $transactionId,
            self::json($manifest),
            '{}',
            time(),
            null,
            '',
            self::cleanActor($actor),
        ]);
        $this->recordPhonePlans($manifest);
    }

    /** @param array<string,mixed> $details */
    public function transition(
        string $transactionId,
        string $state,
        array $details = [],
        string $error = ''
    ): void {
        BuildPlanner::assertTransactionId($transactionId);
        $allowed = [
            'planned', 'staged', 'glue_committed', 'files_published', 'committed',
            'rolling_back', 'rolled_back', 'failed',
        ];
        if (!in_array($state, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid build transaction state');
        }
        $terminal = in_array($state, ['committed', 'rolled_back', 'failed'], true);
        $stmt = $this->db->prepare(
            'UPDATE ucmp_apply_transactions
             SET state=?,details_json=?,finished=?,error=? WHERE transaction_id=?'
        );
        $stmt->execute([
            $state,
            self::json($details),
            $terminal ? time() : null,
            substr(trim($error), 0, 4000),
            $transactionId,
        ]);
        // MySQL may report zero affected rows for an idempotent transition even
        // though the transaction exists.  Test existence instead of confusing
        // "unchanged" with "unknown".
        $exists = $this->db->prepare(
            'SELECT 1 FROM ucmp_apply_transactions WHERE transaction_id=? LIMIT 1'
        );
        $exists->execute([$transactionId]);
        if (!$exists->fetchColumn()) {
            throw new \RuntimeException('Unknown build transaction');
        }
    }

    /** @param array<string,mixed> $manifest */
    public function markPhonesApplied(array $manifest): void
    {
        $generation = (string)$manifest['transaction_id'];
        $appliedAt = time();
        foreach ((array)($manifest['phones'] ?? []) as $phone) {
            $phoneId = (int)($phone['phone_id'] ?? 0);
            if ($phoneId < 1) {
                continue;
            }
            $hash = $this->desiredPhoneHash($phoneId, $manifest);
            $stmt = $this->db->prepare(
                'UPDATE ucmp_phone_builds
                 SET applied_hash=?,generation_id=?,previous_generation_id=?,applied_at=?
                 WHERE phone_id=?'
            );
            $stmt->execute([
                $hash,
                $generation,
                (string)($manifest['previous_generation_id'] ?? ''),
                $appliedAt,
                $phoneId,
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $transactionId): ?array
    {
        BuildPlanner::assertTransactionId($transactionId);
        $stmt = $this->db->prepare(
            'SELECT * FROM ucmp_apply_transactions WHERE transaction_id=? LIMIT 1'
        );
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        foreach (['manifest_json', 'details_json'] as $key) {
            $decoded = json_decode((string)$row[$key], true);
            $row[substr($key, 0, -5)] = is_array($decoded) ? $decoded : [];
            unset($row[$key]);
        }
        return $row;
    }

    /** @param array<string,mixed> $manifest */
    private function recordPhonePlans(array $manifest): void
    {
        $plannedAt = time();
        foreach ((array)($manifest['phones'] ?? []) as $phone) {
            $phoneId = (int)($phone['phone_id'] ?? 0);
            if ($phoneId < 1) {
                continue;
            }
            $desiredHash = $this->desiredPhoneHash($phoneId, $manifest);
            $row = $this->db->prepare('SELECT phone_id FROM ucmp_phone_builds WHERE phone_id=?');
            $row->execute([$phoneId]);
            if ($row->fetchColumn()) {
                $this->db->prepare(
                    'UPDATE ucmp_phone_builds
                     SET desired_hash=?,manifest_json=?,planned_at=? WHERE phone_id=?'
                )->execute([$desiredHash, self::json($manifest), $plannedAt, $phoneId]);
            } else {
                $this->db->prepare(
                    'INSERT INTO ucmp_phone_builds
                     (phone_id,desired_hash,applied_hash,manifest_json,generation_id,
                      previous_generation_id,planned_at,applied_at)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $phoneId,
                    $desiredHash,
                    '',
                    self::json($manifest),
                    '',
                    (string)($manifest['previous_generation_id'] ?? ''),
                    $plannedAt,
                    null,
                ]);
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function desiredPhoneHash(int $phoneId, array $manifest): string
    {
        $files = array_values(array_filter(
            (array)($manifest['files'] ?? []),
            static fn(array $file): bool => (int)($file['phone_id'] ?? 0) === $phoneId
        ));
        return hash('sha256', self::json([
            'phone_id' => $phoneId,
            'plan_hash' => (string)($manifest['plan_hash'] ?? ''),
            'files' => $files,
        ]));
    }

    /** @param mixed $value */
    private static function json($value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function cleanActor(string $actor): string
    {
        $actor = trim((string)preg_replace('/[^A-Za-z0-9@_.:-]+/', '-', $actor));
        return substr($actor, 0, 128);
    }
}
