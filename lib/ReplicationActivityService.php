<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

use PDO;
require_once __DIR__ . '/StorageBusyRetry.php';

/** Durable, shared status for replication requests across every module tab. */
final class ReplicationActivityService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function ensureSchema(PDO $db): void
    {
        if ((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_replication_runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    run_key TEXT NOT NULL UNIQUE,
                    kind TEXT NOT NULL,
                    label TEXT NOT NULL,
                    source_site_id INTEGER NOT NULL DEFAULT 0,
                    targets_json TEXT,
                    status TEXT NOT NULL DEFAULT 'running',
                    total_units INTEGER NOT NULL DEFAULT 0,
                    completed_units INTEGER NOT NULL DEFAULT 0,
                    current_item TEXT,
                    message TEXT,
                    context_json TEXT,
                    result_json TEXT,
                    created INTEGER NOT NULL DEFAULT 0,
                    started INTEGER NOT NULL DEFAULT 0,
                    updated INTEGER NOT NULL DEFAULT 0,
                    finished INTEGER NOT NULL DEFAULT 0
                )"
            );
            $db->exec('CREATE INDEX IF NOT EXISTS idx_ucmp_replication_runs_status_updated ON ucmp_replication_runs (status,updated)');
            return;
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_replication_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_key CHAR(32) NOT NULL,
                kind VARCHAR(32) NOT NULL,
                label VARCHAR(160) NOT NULL,
                source_site_id INT NOT NULL DEFAULT 0,
                targets_json LONGTEXT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'running',
                total_units INT NOT NULL DEFAULT 0,
                completed_units INT NOT NULL DEFAULT 0,
                current_item VARCHAR(255) NULL,
                message TEXT NULL,
                context_json LONGTEXT NULL,
                result_json LONGTEXT NULL,
                created INT NOT NULL DEFAULT 0,
                started INT NOT NULL DEFAULT 0,
                updated INT NOT NULL DEFAULT 0,
                finished INT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_ucmp_replication_run_key (run_key),
                KEY idx_ucmp_replication_runs_status_updated (status,updated)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** @return array{id:int,run_key:string} */
    public function begin(string $kind, string $label, int $sourceSiteId, array $targets = [], array $context = []): array
    {
        self::ensureSchema($this->db);
        $now = time();
        $kind = preg_replace('/[^a-z0-9_-]+/i', '-', trim($kind)) ?: 'replication';
        $kind = substr($kind, 0, 32);
        $label = trim($label) ?: 'Replication';
        $label = substr($label, 0, 160);
        $targets = array_values(array_unique(array_filter(array_map('intval', $targets), static fn(int $id): bool => $id > 0)));
        $runKey = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            "INSERT INTO ucmp_replication_runs
                (run_key,kind,label,source_site_id,targets_json,status,total_units,completed_units,current_item,message,context_json,created,started,updated,finished)
             VALUES (?,?,?,?,?,'running',?,0,?,?,?, ?,?,?,0)"
        );
        $stmt->execute([
            $runKey,
            $kind,
            $label,
            max(0, $sourceSiteId),
            json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            count($targets),
            'Preparing transfer',
            'Replication request accepted by the controller',
            json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $now,
            $now,
            $now,
        ]);
        return ['id'=>(int)$this->db->lastInsertId(), 'run_key'=>$runKey];
    }

    public function update(int $id, int $completed, int $total, string $currentItem = '', string $message = ''): void
    {
        if ($id < 1) { return; }
        $this->db->prepare(
            "UPDATE ucmp_replication_runs SET completed_units=?,total_units=?,current_item=?,message=?,updated=?
              WHERE id=? AND status='running'"
        )->execute([
            max(0, $completed),
            max(0, $total),
            substr($currentItem, 0, 255),
            substr($message, 0, 4000),
            time(),
            $id,
        ]);
    }


    /** Sub-operation progress never declares the whole destination complete. */
    public function progress(int $id, int $siteId, string $stage, string $message, ?array $result = null, ?array $bytes = null): void
    {
        $stmt = $this->db->prepare("SELECT result_json,targets_json FROM ucmp_replication_runs WHERE id=? AND status='running'");
        $stmt->execute([$id]); $stored = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($stored === false) { return; }
        $state = json_decode((string)$stored['result_json'], true) ?: [];
        $targets = json_decode((string)$stored['targets_json'], true) ?: [];
        if (!in_array($siteId, $targets, true)) { $this->db->prepare("UPDATE ucmp_replication_runs SET current_item=?,message=?,updated=? WHERE id=? AND status='running'")->execute([substr('Source: ' . $stage,0,255),substr($message,0,4000),time(),$id]); return; }
        $rows = array_column((array)($state['destination_results'] ?? []), null, 'site_id');
        $row = $rows[$siteId] ?? ['site_id'=>$siteId,'name'=>'Site #' . $siteId,'status'=>'running','succeeded'=>0,'failed'=>0,'unknown'=>0,'details'=>[]];
        try { $name = $this->db->prepare('SELECT name FROM ucmp_sites WHERE id=?'); $name->execute([$siteId]); $row['name'] = (string)($name->fetchColumn() ?: $row['name']); }
        catch (\Throwable $ignored) {}
        if ($result !== null) { $row[!empty($result['ok']) ? 'succeeded' : 'failed']++; }
        if ($bytes !== null) {
            $current = $row['transfer'] ?? [];
            if (($current['id'] ?? '') !== $bytes['id']) {
                $current = ['id'=>$bytes['id'],'sent'=>0];
                $row['bytes_unchanged'] = (int)($row['bytes_unchanged'] ?? 0) + max(0,(int)($bytes['unchanged'] ?? 0));
            }
            $sent = max((int)$current['sent'], (int)$bytes['sent']);
            $row['bytes_sent'] = (int)($row['bytes_sent'] ?? 0) + $sent - (int)$current['sent'];
            $row['transfer'] = ['id'=>$bytes['id'],'sent'=>$sent,'total'=>max(0,(int)$bytes['total'])];
        }
        $row['details'] = [['scope'=>substr($stage,0,160),'status'=>'running','message'=>substr($message,0,2000)]];
        $rows[$siteId] = $row;
        $state['destination_results'] = array_values($rows);
        $this->db->prepare("UPDATE ucmp_replication_runs SET current_item=?,message=?,result_json=?,updated=? WHERE id=? AND status='running'")
            ->execute([substr($row['name'] . ': ' . $stage,0,255),substr($message,0,4000),json_encode($state,JSON_INVALID_UTF8_SUBSTITUTE),time(),$id]);
    }

    public function plan(int $id, array $plan): void
    {
        $stmt=$this->db->prepare("SELECT result_json FROM ucmp_replication_runs WHERE id=? AND status='running'");$stmt->execute([$id]);$stored=$stmt->fetchColumn();
        if ($stored===false) { return; }
        $state=json_decode((string)$stored,true)?:[];$state['plan']=$plan;
        $this->db->prepare("UPDATE ucmp_replication_runs SET result_json=?,updated=? WHERE id=? AND status='running'")->execute([json_encode($state,JSON_INVALID_UTF8_SUBSTITUTE),time(),$id]);
    }

    /** A missing mutation receipt is unknown; a source-read failure made no destination changes. */
    public static function failedOperation(\Throwable $error, array $targets, string $scope, bool $readOnly = false): array
    {
        $receipt = $error instanceof RpcOperationException ? $error->receipt : [];
        if (($receipt['state'] ?? '') === 'completed' && is_array($receipt['result'] ?? null)) {
            return ['scope'=>$scope] + $receipt['result'];
        }
        $knownFailure = $error instanceof RpcOperationException && StorageBusyRetry::beforeMutation($error);
        $outcome = ['ok'=>false,'status'=>($readOnly || $knownFailure) ? 'error' : 'unknown','message'=>$error->getMessage()];
        if (!empty($receipt['operation_id'])) { $outcome['operation_id'] = $receipt['operation_id']; }
        return ['scope'=>$scope,'sites'=>array_fill_keys($targets,$outcome)] + $outcome;
    }

    /** Preserve destination outcomes independently of large per-file payloads. */
    public function describeResult(array $result, array $targets = []): array
    {
        $rows = [];
        foreach ($targets as $id) {
            if ((int)$id > 0) { $rows[(string)(int)$id] = ['site_id'=>(int)$id,'succeeded'=>0,'failed'=>0,'unknown'=>0,'details'=>[]]; }
        }
        $record = static function (int $id, array $value, string $scope) use (&$rows): void {
            if ($id < 1) { return; }
            $key = (string)$id;
            $rows[$key] ??= ['site_id'=>$id,'succeeded'=>0,'failed'=>0,'unknown'=>0,'details'=>[]];
            $uncertain = !empty($value['recovery_required']) || !empty($value['needs_confirmation'])
                || in_array(($value['status'] ?? ''), ['unknown','recovery_required','unresolved','conflict','paused'], true);
            $state = $uncertain ? 'unknown' : (!empty($value['ok']) ? 'success' : 'error');
            $rows[$key][$state === 'success' ? 'succeeded' : ($state === 'unknown' ? 'unknown' : 'failed')]++;
            if (count($rows[$key]['details']) < 100) {
                $rows[$key]['details'][] = [
                    'scope'=>substr($scope,0,160),'status'=>$state,
                    'message'=>substr((string)($value['message'] ?? ($state === 'success' ? 'Completed' : 'Destination failed')),0,2000),
                    'transport'=>$value['transport'] ?? null,
                    'copied'=>(int)($value['copied'] ?? 0),'skipped'=>(int)($value['skipped'] ?? 0),
                    'recovery_required'=>!empty($value['recovery_required']),
                    'recovery_id'=>$value['recovery_id'] ?? null,'operation_id'=>$value['operation_id'] ?? null,
                    'phase'=>$value['phase'] ?? null,'staging_incomplete'=>!empty($value['staging_incomplete']) || ($value['status'] ?? '') === 'staging_incomplete',
                    'staged_bytes'=>(int)($value['staged_bytes'] ?? 0),'publication_completed'=>!empty($value['publication_completed']),
                ];
            }
        };
        $walk = function (array $node, string $scope = '', int $depth = 0) use (&$walk, $record, $targets): void {
            if ($depth > 8) { return; }
            $scope = (string)($node['library'] ?? $node['scope'] ?? $scope);
            $expected = !empty($node['target_site_id']) ? [(int)$node['target_site_id']]
                : (is_array($node['target_site_ids'] ?? null) ? $node['target_site_ids'] : $targets);
            $destinations = $node['sites'] ?? $node['targets'] ?? null;
            if (is_array($destinations)) {
                foreach ($destinations as $id=>$value) {
                    if (!is_array($value)) { continue; }
                    // Keep committed TFTP payloads distinct from subsequent media,
                    // manifest and index failures. Old retained rows have these same children.
                    $children = (array)($value['hosted_media'] ?? []);
                    if (isset($value['activation'])) { $children[] = (array)$value['activation'] + ['scope'=>'Manifest activation']; }
                    $children = array_values(array_filter($children, 'is_array'));
                    if ($children && ((int)($value['copied'] ?? 0) + (int)($value['skipped'] ?? 0) > 0)) {
                        $record((int)$id, ['ok'=>true,'message'=>'TFTP payloads committed or already identical'] + array_intersect_key($value,array_flip(['copied','skipped','transport','recovery_id'])), 'TFTP files');
                        foreach ($children as $child) { if (is_array($child)) { $record((int)$id,$child,(string)($child['scope'] ?? 'Hosted media')); } }
                        if (empty($value['ok']) && !array_filter($children, static fn(array $child): bool => empty($child['ok']))) { $record((int)$id,$value,'Final catalog verification'); }
                    } else { $record((int)$id,$value,$scope); }
                }
                foreach ($expected as $id) {
                    if (!isset($destinations[(string)$id])) { $record((int)$id,['status'=>'unknown','message'=>'No destination completion was recorded for this scope'],$scope); }
                }
                return;
            }
            if (!empty($node['target_site_id'])) { $record((int)$node['target_site_id'],$node,$scope); return; }
            $children = false;
            foreach (['operations','libraries'] as $key) {
                foreach ((array)($node[$key] ?? []) as $child) {
                    if (is_array($child)) { $children = true; $walk($child,$scope,$depth+1); }
                }
            }
            if (!$children && empty($node['ok']) && empty($node['skipped'])) {
                foreach ($expected as $id) { $record((int)$id,['ok'=>false,'status'=>'unknown','message'=>$node['message'] ?? 'No destination result was returned'],$scope); }
            }
        };
        $walk($result);
        $names = [];
        try { $names = $this->db->query('SELECT id,name FROM ucmp_sites')->fetchAll(PDO::FETCH_KEY_PAIR) ?: []; }
        catch (\Throwable $ignored) { /* Retained results remain readable if site inventory is unavailable. */ }
        $counts = ['success'=>0,'partial'=>0,'error'=>0,'unknown'=>0];
        foreach ($rows as &$row) {
            $row['name'] = (string)($names[$row['site_id']] ?? ('Site #' . $row['site_id']));
            $row['status'] = $row['unknown'] ? 'unknown' : ($row['failed'] ? ($row['succeeded'] ? 'partial' : 'error') : ($row['succeeded'] ? 'success' : 'unknown'));
            $counts[$row['status']]++;
            if (!$row['details']) { $row['details'][] = ['scope'=>'','status'=>'unknown','message'=>'No destination completion was recorded']; }
        }
        unset($row);
        $result['destination_results'] = array_values($rows);
        if ($rows) {
            $summary = $counts['success'] . ' succeeded, ' . $counts['error'] . ' failed';
            if ($counts['partial']) { $summary .= ', ' . $counts['partial'] . ' partially completed'; }
            if ($counts['unknown']) { $summary .= ', ' . $counts['unknown'] . ' need review'; }
            $result['destination_summary'] = $summary;
            if (empty($result['ok']) && ($counts['success'] || $counts['partial'])) { $result['status'] = 'partial'; }
        }
        return $result;
    }

    public function finish(int $id, array $result): void
    {
        if ($id < 1) { return; }
        $saved=$this->db->prepare('SELECT result_json FROM ucmp_replication_runs WHERE id=?');$saved->execute([$id]);
        $state=json_decode((string)$saved->fetchColumn(),true)?:[];
        if (isset($state['plan'])) { $result['plan']=$state['plan']; }
        $result['bytes_sent']=array_sum(array_column($state['destination_results']??[],'bytes_sent'));
        $result['bytes_unchanged']=array_sum(array_column($state['destination_results']??[],'bytes_unchanged'));
        if (!isset($result['destination_results'])) {
            $stmt = $this->db->prepare('SELECT targets_json FROM ucmp_replication_runs WHERE id=?');
            $stmt->execute([$id]);
            $targets = json_decode((string)$stmt->fetchColumn(),true);
            $result = $this->describeResult($result,is_array($targets) ? $targets : []);
        }
        $ok = !empty($result['ok']);
        $status = strtolower(trim((string)($result['status'] ?? ($ok ? 'success' : 'error'))));
        if ($ok && !in_array($status, ['success','warning','partial','skipped'], true)) { $status = 'success'; }
        if (!$ok && !in_array($status, ['error','partial','warning'], true)) { $status = 'error'; }
        $message = substr(trim((string)($result['message'] ?? 'Replication finished')), 0, 4000);
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) { $encoded = '{}'; }
        if (strlen($encoded) > 262144) {
            $compact = $result['destination_results'] ?? [];
            foreach ($compact as &$destination) {
                $destination['details_omitted'] = max(0,count($destination['details'] ?? [])-3);
                $destination['details'] = array_slice($destination['details'] ?? [],0,3);
            }
            unset($destination);
            $encoded = json_encode(['truncated'=>true,'ok'=>$ok,'status'=>$status,'message'=>$message,'destination_results'=>$compact,'destination_summary'=>$result['destination_summary'] ?? '',
                'plan'=>$result['plan']??null,'bytes_sent'=>$result['bytes_sent'],'bytes_unchanged'=>$result['bytes_unchanged']],JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $now = time();
        $this->db->prepare(
            'UPDATE ucmp_replication_runs
                SET status=?,completed_units=CASE WHEN total_units>0 THEN total_units ELSE completed_units END,
                    current_item=?,message=?,result_json=?,updated=?,finished=? WHERE id=?'
        )->execute([$status,'Finished',$message,$encoded,$now,$now,$id]);
    }

    public function fail(int $id, \Throwable $error): void
    {
        $this->finish($id, ['ok'=>false,'status'=>'error','message'=>$error->getMessage()]);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        $now = time();
        $limit = max(1, min(50, $limit));
        $rows = $this->db->query(
            "SELECT * FROM ucmp_replication_runs
              WHERE status='running' OR updated>=" . (int)($now - 86400) . "
              ORDER BY (status='running') DESC,updated DESC,id DESC LIMIT $limit"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            if ($row['status'] === 'running' && (int)$row['updated'] < $now - 21600) {
                $row['status'] = 'unknown';
                $row['message'] = 'No recent worker update; completion is not confirmed.';
            }
            foreach (['id','source_site_id','total_units','completed_units','created','started','updated','finished'] as $field) {
                $row[$field] = (int)($row[$field] ?? 0);
            }
            $targets = json_decode((string)($row['targets_json'] ?? ''), true);
            $row['target_site_ids'] = is_array($targets) ? array_values(array_map('intval', $targets)) : [];
            $result = json_decode((string)($row['result_json'] ?? ''), true);
            if (is_array($result)) {
                $row['plan']=$result['plan']??null;
                $row['bytes_sent']=$result['bytes_sent']??array_sum(array_column($result['destination_results']??[],'bytes_sent'));
                $row['bytes_unchanged']=$result['bytes_unchanged']??array_sum(array_column($result['destination_results']??[],'bytes_unchanged'));
                $hasStages = isset($result['sites']) || isset($result['targets']) || isset($result['operations']) || isset($result['libraries']);
                $described = !$hasStages && isset($result['destination_results']) ? $result : $this->describeResult($result,$row['target_site_ids']);
                $row['destination_results'] = $described['destination_results'] ?? [];
                $row['destination_summary'] = $described['destination_summary'] ?? '';
                if ($row['status'] !== 'running' && ($described['status'] ?? '') === 'partial') { $row['status'] = 'partial'; }
            }
            unset($row['targets_json'], $row['context_json'], $row['result_json']);
        }
        unset($row);
        return $rows;
    }
}
