<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner {
    final class DirectoryService {
        public static bool $stale=false;
        public function __construct($ucm) {}
        public function planIdentityPackets(array $scope): array { return ['targets'=>[['extension_id'=>1,'extension'=>'100','site_id'=>1,'identity_site_key'=>'owner','origin_site_key'=>'owner','extension_revision'=>2]],'skipped'=>[]]; }
        public function commitIdentityPackets(array $packets): void {}
        public function planAcceptedPhonebooks(): array { return ['plan_hash'=>str_repeat('b',64),'destinations'=>[['site_id'=>1,'site_key'=>'owner']]]; }
        public function publishAcceptedPhonebooks(?array $plan=null): array { return ['ok_count'=>1,'queued'=>0,'warnings'=>[]]; }
        public function recordIdentityPacketResults(array $packets,array $results): array {
            if (!isset($results['owner:100'])) { throw new \RuntimeException('Composite owner result was lost'); }
            if (!empty($results['owner:100']['skipped'])) { return ['ok'=>true,'applied'=>0,'queued'=>0,'skipped'=>[['extension'=>'100','reason'=>$results['owner:100']['message']]],'stale_extension_ids'=>[]]; }
            return ['ok'=>!self::$stale,'applied'=>self::$stale?0:1,'queued'=>0,'stale_extension_ids'=>self::$stale?[1]:[]];
        }
    }
}
namespace {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    use FreePBX\modules\Ucmprovisioner\{OperatorWorkflowService,DirectoryService};
    $module=new class {
        private PDO $db;
        public bool $skip=false;
        public int $executions=0;
        public function __construct() { $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);OperatorWorkflowService::ensureSchema($this->db); }
        public function getDb(): PDO { return $this->db; }
        public function localSiteId(): int { return 1; }
        public function getSites(): array { return [['id'=>1,'site_key'=>'owner','enabled'=>1]]; }
        public function getSite(int $id): array { return $this->getSites()[0]; }
        public function operatorWorkflowAtSite(int $site,array $request): array {
            if ($request['op']==='delivery_execute') { $this->executions++; }
            return ['ok'=>true,'identity_delivery_schema'=>1,'plan_hash'=>str_repeat('a',64),'phone_targets'=>[],'files'=>[],'excluded'=>[],
                'skipped'=>$this->skip?[['target'=>'owner:100','reason'=>'Account does not exist']]:[],
                'results'=>['100'=>['ok'=>true,'callerid_checked'=>true]],'stages'=>['files_published'=>true,'pbx_activated'=>true,'device_observed'=>null]];
        }
    };
    $service=new OperatorWorkflowService($module);
    foreach ([false,true] as $stale) {
        DirectoryService::$stale=$stale;
        $plan=$service->planIdentityRefresh(['scope'=>['extension_ids'=>[1]]]);
        $result=$service->executeIdentityRefresh(['plan_id'=>$plan['plan_id'],'plan_hash'=>$plan['plan_hash']]);
        if ($result['ok']!==!$stale) { throw new RuntimeException('A stale controller acknowledgement must invalidate otherwise successful destination receipts'); }
        $query=$module->getDb()->prepare('SELECT state FROM ucmp_operator_changes WHERE id=?');$query->execute([$plan['plan_id']]);
        if ($query->fetchColumn()!==($stale?'failed':'completed')) { throw new RuntimeException('Durable result state must reflect controller acknowledgement'); }
    }
    $module->skip=true;$before=$module->executions;DirectoryService::$stale=false;
    $plan=$service->planIdentityRefresh(['scope'=>['extension_ids'=>[1]]]);
    if (!$plan['ok'] || $plan['review']['eligible_count']!==0 || $plan['review']['skipped_count']!==1) { throw new RuntimeException('Reviewed exclusions must show their count before execution'); }
    $result=$service->executeIdentityRefresh(['plan_id'=>$plan['plan_id'],'plan_hash'=>$plan['plan_hash']]);
    if (!$result['ok'] || count($result['skipped'])!==1 || $module->executions!==$before || $result['acknowledgement']['applied']!==0) { throw new RuntimeException('All-skipped operator review must record warnings without falsely applying identities or calling delivery'); }
    echo "Operator cross-site result and interleaved stale acknowledgement fixtures passed\n";
}
