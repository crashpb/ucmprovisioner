<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner {
    final class MeshReceiptFault { public static bool $fail = false; }
    function rename(string $from, string $to): bool {
        if (MeshReceiptFault::$fail && str_ends_with($to,'.json')) { return false; }
        return \rename($from,$to);
    }
}
namespace {

require_once dirname(__DIR__) . '/lib/PublishMesh.php';

use FreePBX\modules\Ucmprovisioner\PublishMesh;

function meshExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function meshRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/ucmp-mesh-' . bin2hex(random_bytes(8));
$astDir = $root . '/asterisk';
$stateDir = $root . '/state';
mkdir($astDir, 0700, true);
$target = $astDir . '/pjsip_custom.conf';
$before = "; exact pre-transaction snapshot\n[transport-udp]\ntype=transport\n";
file_put_contents($target, $before);
$peer = [
    'key'=>'site-a', 'name'=>'SITE-A', 'endpoint'=>'SITEB-SITEA_PJSIP',
    'peer_resource'=>'SITEA-SITEB_PJSIP', 'local_addr'=>'198.51.100.10',
    'local_port'=>5160, 'peer_addr'=>'192.0.2.10', 'peer_port'=>5160,
];

try {
    $reloads = [];
    $runner = static function (string $command) use (&$reloads): array {
        $reloads[] = $command;
        return ['status'=>0, 'output'=>'Module reloaded'];
    };
    $mesh = new PublishMesh(new stdClass(), $astDir, $stateDir, $runner);
    $preview = $mesh->previewExplicitPeer($peer);
    meshExpect(!empty($preview['ok']) && !empty($preview['changed']), 'Explicit peer preview must produce a changed stable plan');
    meshExpect(strlen((string)$preview['plan_hash']) === 64, 'Mesh preview must expose a SHA-256 plan hash');

    $wrong = $mesh->prepareExplicitPeer($peer, str_repeat('0', 64), 'mesh-wrong-0001');
    meshExpect(empty($wrong['ok']) && !empty($wrong['conflict']), 'Prepare must reject a stale or invented plan hash');
    meshExpect(file_get_contents($target) === $before, 'Rejected prepare must not change live Asterisk state');

    $prepared = $mesh->prepareExplicitPeer($peer, $preview['plan_hash'], 'mesh-success-0001');
    meshExpect(!empty($prepared['ok']) && $prepared['phase'] === 'prepared', 'Approved plan must enter prepared state');
    meshExpect(file_get_contents($target) === $before && !$reloads, 'Prepare must leave the live file and Asterisk untouched');
    $committed = $mesh->commit('mesh-success-0001');
    meshExpect(!empty($committed['ok']) && $committed['phase'] === 'committed', 'Prepared mesh must commit');
    meshExpect(str_contains((string)file_get_contents($target), 'type=outbound-publish'), 'Commit must publish the planned PJSIP objects');
    meshExpect(count($reloads) === 1, 'Commit must run one checked PJSIP reload');
    meshExpect(
        str_contains($reloads[0], '/usr/bin/timeout --signal=KILL 15 /usr/sbin/asterisk -rx'),
        'PJSIP reload must have a hard execution bound'
    );
    $rolledBack = $mesh->rollback('mesh-success-0001');
    meshExpect(!empty($rolledBack['ok']) && !empty($rolledBack['content_restored']), 'Committed mesh must be compensatable');
    meshExpect(file_get_contents($target) === $before, 'Rollback must restore the exact byte-for-byte snapshot');

    $preview = $mesh->previewExplicitPeer($peer);
    $mesh->prepareExplicitPeer($peer, $preview['plan_hash'], 'mesh-conflict-0001');
    $newer = "; administrator changed this after prepare\n";
    file_put_contents($target, $newer);
    $conflict = $mesh->commit('mesh-conflict-0001');
    meshExpect(empty($conflict['ok']), 'Concurrent target mutation must block commit');
    meshExpect(file_get_contents($target) === $newer, 'Conflict handling must never overwrite newer administrator content');

    file_put_contents($target, $before);
    $reloadNumber = 0;
    $failFirstReload = static function () use (&$reloadNumber): array {
        $reloadNumber++;
        return $reloadNumber === 1
            ? ['status'=>1, 'output'=>'PJSIP reload failed']
            : ['status'=>0, 'output'=>'PJSIP reload complete'];
    };
    $meshWithFailure = new PublishMesh(new stdClass(), $astDir, $stateDir, $failFirstReload);
    $preview = $meshWithFailure->previewExplicitPeer($peer);
    $meshWithFailure->prepareExplicitPeer($peer, $preview['plan_hash'], 'mesh-reload-0001');
    $failed = $meshWithFailure->commit('mesh-reload-0001');
    meshExpect(empty($failed['ok']) && !empty($failed['rollback']['ok']), 'Reload failure must fail commit and trigger automatic restore');
    meshExpect(file_get_contents($target) === $before, 'Reload failure compensation must restore the exact original content');
    meshExpect($reloadNumber === 2, 'Reload failure must reload once after restoring the snapshot');

    $events = [];
    $participant = static function (string $id, bool $commitOk = true, bool $rollbackOk = true) use (&$events): array {
        return [
            'id'=>$id,
            'preview'=>static fn(): array => ['ok'=>true, 'plan_hash'=>hash('sha256', $id), 'changed'=>true],
            'prepare'=>static function (string $planHash, string $transactionId) use (&$events, $id): array {
                $events[] = "prepare:$id";
                return ['ok'=>true, 'transaction_id'=>$transactionId, 'plan_hash'=>$planHash];
            },
            'commit'=>static function (string $transactionId) use (&$events, $id, $commitOk): array {
                $events[] = "commit:$id";
                return ['ok'=>$commitOk, 'transaction_id'=>$transactionId, 'message'=>$commitOk ? 'ok' : 'injected commit failure'];
            },
            'rollback'=>static function (string $transactionId) use (&$events, $id, $rollbackOk): array {
                $events[] = "rollback:$id";
                return ['ok'=>$rollbackOk, 'transaction_id'=>$transactionId];
            },
        ];
    };
    $participants = [$participant('remote-a'), $participant('remote-b', false), $participant('local-controller')];
    $multiPreview = PublishMesh::coordinate($participants);
    $multi = PublishMesh::coordinate($participants, true, $multiPreview['plan_hash']);
    meshExpect(empty($multi['ok']) && $multi['failed_participant'] === 'remote-b', 'Coordinator must report the exact failing commit participant');
    meshExpect(empty($multi['partial']), 'Successful compensation must not be reported as partial state');
    meshExpect(
        array_slice($events, -3) === ['rollback:local-controller', 'rollback:remote-b', 'rollback:remote-a'],
        'Commit failure must abort uncommitted participants and compensate commits in reverse order'
    );

    $events = [];
    $partialParticipants = [$participant('remote-a', true, false), $participant('remote-b', false), $participant('local-controller')];
    $partialPreview = PublishMesh::coordinate($partialParticipants);
    $partial = PublishMesh::coordinate($partialParticipants, true, $partialPreview['plan_hash']);
    meshExpect(empty($partial['ok']) && !empty($partial['partial']), 'A failed compensating rollback must be reported as exact partial state');

    $moduleSource = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
    meshExpect(
        str_contains($moduleSource, "\$result['mesh_deferred'] = true;")
            && str_contains($moduleSource, 'Cross-site BLF mesh is independent; use Sync BLF Mesh or its schedule')
            && !str_contains($moduleSource, '$crossSite && $allowMesh && !empty($result[\'ok\'])'),
        'Phone/TFTP rebuilds must report cross-site work without mutating the independently scheduled publication mesh'
    );

    echo "Publish mesh two-phase transaction fixtures passed\n";

    file_put_contents($target,$before);
    $preview=$mesh->previewExplicitPeer($peer);
    $mesh->prepareExplicitPeer($peer,$preview['plan_hash'],'mesh-busy-0001');
    $held=\FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock($stateDir.'/writer.lock');
    $busy=$mesh->commit('mesh-busy-0001');
    meshExpect(!empty($busy['busy']) && file_get_contents($target)===$before,'A concurrent mesh writer must stop before replacing the target');
    flock($held,LOCK_UN);fclose($held);

    $lateReloads=0;
    $lateMesh=new PublishMesh(new stdClass(),$astDir,$stateDir,static function() use (&$lateReloads):array {
        $lateReloads++;\FreePBX\modules\Ucmprovisioner\MeshReceiptFault::$fail=true;
        return ['status'=>0,'output'=>'Reloaded'];
    });
    $late=$lateMesh->commit('mesh-busy-0001');
    \FreePBX\modules\Ucmprovisioner\MeshReceiptFault::$fail=false;
    meshExpect(!empty($late['ok']) && !empty($late['recovery_required']) && isset($late['reporting_error'])
        && $lateReloads===1 && file_get_contents($target)!==$before,'Final receipt failure must retain a verified commit without another reload');
    meshExpect(is_file($stateDir.'/.writer-owner'),'Unresolved receipt must retain its writer reservation');
    $again=$lateMesh->commit('mesh-busy-0001');
    meshExpect(empty($again['ok']) && !empty($again['recovery_required']) && $lateReloads===1,'A committing receipt cannot replay an uncertain operation');
    $next=$lateMesh->previewExplicitPeer($peer);
    $blocked=$lateMesh->prepareExplicitPeer($peer,$next['plan_hash'],'mesh-blocked-0002');
    meshExpect(!empty($blocked['recovery_required']) && $blocked['pending_transaction_id']==='mesh-busy-0001','An unresolved earlier writer must block a new transaction');

    foreach (['throw','invalid','recovery'] as $fault) {
        $events=[];
        $lost=$participant('remote-b');
        $lost['commit']=static function() use ($fault) {
            if ($fault==='throw') { throw new RuntimeException('Lost commit response'); }
            return $fault==='invalid' ? null : ['ok'=>true,'recovery_required'=>true];
        };
        $peers=[$participant('remote-a'),$lost,$participant('local-controller')];
        $plan=PublishMesh::coordinate($peers);
        $outcome=PublishMesh::coordinate($peers,true,$plan['plan_hash']);
        meshExpect(!empty($outcome['recovery_required']) && !empty($outcome['participants']['remote-a']['commit']['ok'])
            && !array_filter($events,static fn(string $event):bool=>str_starts_with($event,'rollback:'))
            && !in_array('commit:local-controller',$events,true),'Uncertain commit must retain known sites, stop later commits and avoid compensation');
    }
    foreach (['timeout','lost'] as $fault) {
        $isolated=$root.'/'.$fault;mkdir($isolated,0700,true);file_put_contents($isolated.'/pjsip_custom.conf',$before);
        $reloadAttempts=0;
        $uncertainMesh=new PublishMesh(new stdClass(),$isolated,$root.'/'.$fault.'-state',static function() use ($fault,&$reloadAttempts):array {
            $reloadAttempts++;
            if ($fault==='lost') { throw new RuntimeException('Reload response lost'); }
            return ['status'=>124,'output'=>''];
        });
        $plan=$uncertainMesh->previewExplicitPeer($peer);
        $uncertainMesh->prepareExplicitPeer($peer,$plan['plan_hash'],'mesh-unknown-'.$fault);
        $outcome=$uncertainMesh->commit('mesh-unknown-'.$fault);
        meshExpect(empty($outcome['ok']) && !empty($outcome['recovery_required']) && !empty($outcome['files_published'])
            && $reloadAttempts===1 && file_get_contents($isolated.'/pjsip_custom.conf')!==$before
            && is_file($root.'/'.$fault.'-state/.writer-owner'),'Uncertain reload must retain published content and reservation without a compensating reload');
    }
} finally {
    \FreePBX\modules\Ucmprovisioner\MeshReceiptFault::$fail=false;
    meshRemoveTree($root);
}
}
