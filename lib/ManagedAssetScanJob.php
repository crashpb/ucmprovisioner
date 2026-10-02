<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** Durable cursor for bounded, resumable TFTP managed-asset scans. */
final class ManagedAssetScanJob
{
    private const MAX_IDLE_SECONDS = 15 * 60;
    private const KINDS = ['firmware', 'background', 'ringtone', 'locale'];

    private \PDO $db;
    /** @var callable():int */
    private $clock;

    public function __construct(\PDO $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? static fn(): int => time();
        self::ensureSchema($db);
    }

    public static function ensureSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS ucmp_asset_scan_receipts (site_id INTEGER PRIMARY KEY, completed INTEGER NOT NULL, kinds_json TEXT NOT NULL)');
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_asset_scan_jobs (
                    site_id INTEGER PRIMARY KEY,
                    scan_token VARCHAR(32) NOT NULL,
                    state_json TEXT NOT NULL,
                    started INTEGER NOT NULL DEFAULT 0,
                    updated INTEGER NOT NULL DEFAULT 0,
                    last_error TEXT NULL
                )"
            );
            return;
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_asset_scan_jobs (
                site_id INT PRIMARY KEY,
                scan_token CHAR(32) NOT NULL,
                state_json LONGTEXT NOT NULL,
                started INT NOT NULL DEFAULT 0,
                updated INT NOT NULL DEFAULT 0,
                last_error TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** @return array{token:string,state:array<string,mixed>,started:int} */
    public function resumeOrStart(int $siteId, string $token = '', array $kinds = []): array
    {
        if ($siteId < 1) { throw new \InvalidArgumentException('Choose a valid site'); }
        $token = strtolower(trim($token));
        if ($token !== '' && preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Managed asset scan token is invalid');
        }
        $requestedKinds = self::normalizeKinds($kinds);
        $now = ($this->clock)();
        if ($now < 1) { throw new \RuntimeException('Managed asset scan clock returned an invalid timestamp'); }
        $stmt = $this->db->prepare('SELECT * FROM ucmp_asset_scan_jobs WHERE site_id=?');
        $stmt->execute([$siteId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            if ($token !== '' && !hash_equals((string)$row['scan_token'], $token)) {
                throw new \RuntimeException('Another managed asset scan is already active for this site');
            }
            $expired = $now - (int)$row['updated'] > self::MAX_IDLE_SECONDS;
            if ($token === '' && ($expired || trim((string)($row['last_error'] ?? '')) !== '')) {
                $delete = $this->db->prepare(
                    'DELETE FROM ucmp_asset_scan_jobs WHERE site_id=? AND scan_token=?'
                );
                $delete->execute([$siteId, (string)$row['scan_token']]);
                $row = false;
            } elseif ($expired) {
                throw new \RuntimeException('Managed asset scan token is expired; start a new scan');
            }
        }
        if ($row) {
            $state = json_decode((string)$row['state_json'], true);
            if (!is_array($state) || !isset($state['queue']) || !is_array($state['queue'])) {
                throw new \RuntimeException('Managed asset scan state is corrupt; remove the scan job before retrying');
            }
            $stateKinds = self::normalizeKinds((array)($state['kinds'] ?? []));
            if ($token === '' && $requestedKinds && $stateKinds !== $requestedKinds) {
                throw new \RuntimeException(
                    'A managed asset scan with a different scope is already active for this site; restart it first'
                );
            }
            // Continuations created by an older module version did not store a
            // scope and therefore retain the original all-kind behavior.
            $state['kinds'] = $stateKinds ?: self::KINDS;
            return [
                'token'=>(string)$row['scan_token'],
                'state'=>$state,
                'started'=>(int)$row['started'],
            ];
        }
        if ($token !== '') {
            throw new \RuntimeException('Managed asset scan token is expired; start a new scan');
        }
        $token = bin2hex(random_bytes(16));
        $scanKinds = $requestedKinds ?: self::KINDS;
        $state = [
            'queue'=>[['type'=>'directory','path'=>'','depth'=>0]],
            'directories'=>0,
            'entries'=>0,
            'files'=>0,
            'bytes'=>0,
            'kinds'=>$scanKinds,
            'by_kind'=>array_fill_keys($scanKinds, 0),
        ];
        $insert = $this->db->prepare(
            'INSERT INTO ucmp_asset_scan_jobs (site_id,scan_token,state_json,started,updated,last_error)
             VALUES (?,?,?,?,?,NULL)'
        );
        $insert->execute([
            $siteId, $token,
            json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $now, $now,
        ]);
        return ['token'=>$token, 'state'=>$state, 'started'=>$now];
    }

    /** @return array<int,string> */
    private static function normalizeKinds(array $kinds): array
    {
        $normalized = [];
        foreach ($kinds as $kind) {
            $kind = strtolower(trim((string)$kind));
            if ($kind === '') { continue; }
            if (!in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException("Unsupported managed asset scan kind '$kind'");
            }
            $normalized[$kind] = true;
        }
        $ordered = [];
        foreach (self::KINDS as $kind) {
            if (isset($normalized[$kind])) { $ordered[] = $kind; }
        }
        return $ordered;
    }

    /** @param array<string,mixed> $state */
    public function save(int $siteId, string $token, array $state, ?string $error = null): void
    {
        $update = $this->db->prepare(
            'UPDATE ucmp_asset_scan_jobs
                SET state_json=?,updated=?,last_error=?
              WHERE site_id=? AND scan_token=?'
        );
        $update->execute([
            json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ($this->clock)(),
            $error !== null ? substr($error, 0, 4096) : null,
            $siteId,
            $token,
        ]);
        if ($update->rowCount() !== 1) {
            $check = $this->db->prepare('SELECT 1 FROM ucmp_asset_scan_jobs WHERE site_id=? AND scan_token=?');
            $check->execute([$siteId, $token]);
            if (!$check->fetchColumn()) {
                throw new \RuntimeException('Managed asset scan cursor changed while this slice was running');
            }
        }
    }

    public function finish(int $siteId, string $token): void
    {
        $read=$this->db->prepare('SELECT state_json FROM ucmp_asset_scan_jobs WHERE site_id=? AND scan_token=?');
        $read->execute([$siteId,$token]);$state=json_decode((string)$read->fetchColumn(),true);
        if (!is_array($state)||!empty($state['queue'])) { throw new \RuntimeException('Cannot certify an incomplete asset scan'); }
        $this->db->prepare('DELETE FROM ucmp_asset_scan_receipts WHERE site_id=?')->execute([$siteId]);
        $this->db->prepare('INSERT INTO ucmp_asset_scan_receipts (site_id,completed,kinds_json) VALUES (?,?,?)')->execute([$siteId,($this->clock)(),json_encode($state['kinds']??self::KINDS,JSON_THROW_ON_ERROR)]);
        $delete = $this->db->prepare('DELETE FROM ucmp_asset_scan_jobs WHERE site_id=? AND scan_token=?');
        $delete->execute([$siteId, $token]);
    }

    public function verified(int $siteId,string $kind): bool
    {
        $active=$this->db->prepare('SELECT 1 FROM ucmp_asset_scan_jobs WHERE site_id=?');$active->execute([$siteId]);
        if ($active->fetchColumn()) { return false; }
        $read=$this->db->prepare('SELECT completed,kinds_json FROM ucmp_asset_scan_receipts WHERE site_id=?');$read->execute([$siteId]);$row=$read->fetch(\PDO::FETCH_ASSOC);
        return $row && ($this->clock)()-(int)$row['completed']<=3600 && in_array($kind,(array)json_decode($row['kinds_json'],true),true);
    }

    /** Cancel only the cursor; current catalog rows remain untouched. */
    public function reset(int $siteId): bool
    {
        if ($siteId < 1) { throw new \InvalidArgumentException('Choose a valid site'); }
        $delete = $this->db->prepare('DELETE FROM ucmp_asset_scan_jobs WHERE site_id=?');
        $delete->execute([$siteId]);
        return $delete->rowCount() > 0;
    }
}
