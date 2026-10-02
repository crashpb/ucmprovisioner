<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner {
    // No real TFTP boundary is allowed in this source/receipt integration fixture.
    class TftpStore {
        public function __construct($ucm) {}
        public function withSiteLock(int $site,callable $operation) { return $operation(); }
    }
}
namespace {
final class FreePBX {
    public static int $reloads=0;
    public static function Framework(): object { return new class { public function doReload(): array { FreePBX::$reloads++;return ['status'=>true]; } }; }
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/IdentityBindingModesTest.php';

use FreePBX\modules\Ucmprovisioner\{DirectoryService,LogicalExtensionService};

// Reuse the preceding isolated relationship fixture, never a PBX database.
$db->exec('CREATE TABLE ucmp_settings (site_id INTEGER,skey TEXT,sval TEXT,UNIQUE(site_id,skey))');
foreach (['payload_ref TEXT','attempts INTEGER DEFAULT 0','next_attempt INTEGER DEFAULT 0','created INTEGER DEFAULT 0'] as $column) { $db->exec('ALTER TABLE ucmp_site_jobs ADD COLUMN '.$column); }
$db->exec('CREATE UNIQUE INDEX uniq_packet_jobs ON ucmp_site_jobs(site_id,job_type,object_key,desired_hash)');
$db->exec("UPDATE ucmp_directory_sources SET last_accepted_revision='r4',last_success=100");
$insertPerson->execute([2,1,'person-b','r4','Blair Example','b.example','100','{}',1]);
$packetUcm = new class($db) {
    public int $deliveries=0;
    public ?array $reply=null;
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function getSites(): array { return [['id'=>1,'site_key'=>'owner-site','enabled'=>1]]; }
    public function getSite(int $id): array { return $this->getSites()[0]; }
    public function getSiteSettingsLocal(int $site): array { return ['ad_sync_names'=>'1','ad_sync_labels'=>'1']; }
    public function getSetting(string $key,?int $site=null,$default=null) {
        $st=$this->db->prepare('SELECT sval FROM ucmp_settings WHERE site_id=? AND skey=?');$st->execute([$site,$key]);$value=$st->fetchColumn();return $value===false?$default:$value;
    }
    public function applyDirectoryIdentities(int $site,array $packets): array { $this->deliveries++;if ($this->reply!==null) { return $this->reply; }throw new RuntimeException('Read-only plan unexpectedly attempted delivery'); }
};
$packetsService = new class($packetUcm) extends DirectoryService {
    public function entries(bool $includeConflicted=true,int $limit=5000): array {
        // The production entries() supplies exactly these accepted relationship columns.
        $db=(new ReflectionProperty(DirectoryService::class,'ucm'))->getValue($this)->getDb();
        $rows=$db->query('SELECT d.*,x.id extension_id,x.revision extension_revision,x.desired_identity_hash,x.applied_identity_hash FROM ucmp_directory_entries d JOIN ucmp_directory_sources s ON s.id=d.source_id AND s.last_accepted_revision=d.source_revision LEFT JOIN ucmp_extensions x ON x.ad_source_id=d.source_id AND x.ad_object_key=d.object_key')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['source_key']=$row['source_id'].':'.$row['object_key'];$row['attributes']=json_decode((string)$row['attributes'],true); }
        return $rows;
    }
};
$targetId=(int)$primaryB['id'];
$db->prepare('UPDATE ucmp_extensions SET desired_identity_hash=NULL WHERE id=?')->execute([$targetId]);
$before=$db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$plan=$packetsService->planIdentityPackets(['extension_ids'=>[$targetId]]);
$expect(count($plan['targets'])===1 && $packetUcm->deliveries===0,'Review must capture exactly one target without delivery');
$expect($before===$db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Review must not mutate desired hashes, revisions or status');
$packet=$plan['targets'][0];
$expect($packet['origin_site_key']==='owner-site' && $packet['identity_site_key']==='owner-site' && $packet['accepted_at']===100,'Packet carries explicit origin, owner and accepted-source freshness');
$expect($packet['canonical']['display_name']==='Blair Example' && $packet['extension_revision']===$packet['base_revision']+1,'Packet predicts the managed desired revision');
$deliveryModule=new class($packetUcm) {
    public array $settings=[];
    public function __construct(private object $module) {}
    public function __call(string $method,array $args) { return $this->module->$method(...$args); }
    public function getDb(): PDO { return $this->module->getDb(); }
    public function getSetting(string $key,?int $site=null,$default=null) { return $this->settings[$key] ?? $this->module->getSetting($key,$site,$default); }
    public function setSetting(string $key,string $value,int $site): void { $this->settings[$key]=$value; }
    public function getPhones(array $filters=[]): array { return []; }
};
$deliveryBinder=new class extends \FreePBX\modules\Ucmprovisioner\ExtensionBinder {
    public array $names=[];
    public bool $missing=false;
    public function getDevice(string $extension): ?array { return $this->missing?null:['id'=>$extension,'tech'=>'pjsip','secret'=>'fixture']; }
    public function updateDisplayName(string $extension,string $name,string $mode='preserve_custom'): array { $this->names[$extension]=$name;return ['ok'=>true,'changed'=>false,'callerid_checked'=>true]; }
};
$delivery=new \FreePBX\modules\Ucmprovisioner\IdentityDeliveryService($deliveryModule,$deliveryBinder);
$localPreview=$delivery->planLocal([$packet]);
$expect($localPreview['ok'],'Accepted predicted-revision packet can be planned locally before desired-state commit');
$packetsService->commitIdentityPackets([$packet]);
$row=$logical->findById($targetId);
$expect((int)$row['revision']===$packet['extension_revision'] && $row['desired_identity_hash']===$packet['hash'],'Approved exact desired state commits with CAS');
$localAfterCommit=$delivery->planLocal([$packet]);
$expect($localAfterCommit['plan_hash']===$localPreview['plan_hash'],'Desired revision commit must not invalidate identical local delivery inputs');
$localApplied=$delivery->applyLocal([$packet],$localPreview);
$expect($localApplied['ok'] && $deliveryBinder->names['100']==='Blair Example','Source preview, approved CAS and exact local Core delivery complete without retargeting');
$expect($localApplied['stages']['device_observed']===null && $localApplied['stages']['files_published']===null,'No-phone fixture must not claim generated files or device acceptance');
$againRejected=false;
try { $packetsService->commitIdentityPackets([$packet]); } catch (DomainException $expected) { $againRejected=true; }
$expect($againRejected,'A stale review cannot commit against a new revision');
$failed=$packetsService->recordIdentityPacketResults([$packet],['owner-site:100'=>['ok'=>false,'message'=>'Synthetic peer unavailable']]);
$expect($failed['queued']===1 && $logical->findById($targetId)['apply_status']==='failed','Unverified consumer delivery is queued and not acknowledged');
$accepted=$packetsService->recordIdentityPacketResults([$packet],['owner-site:100'=>['ok'=>true,'callerid_checked'=>true]]);
$expect($accepted['applied']===1 && $db->query('SELECT state FROM ucmp_site_jobs LIMIT 1')->fetchColumn()==='completed','Exact all-target acknowledgement completes the pending desired receipt');

// An absent destination is a durable warning, never a success or an endlessly failed job.
$deliveryBinder->missing=true;$deliveryBinder->names=[];$beforeSettings=$deliveryModule->settings;$beforeReloads=FreePBX::$reloads;
$forcedPacket=array_replace($packet,['force'=>true]);$skipPlan=$delivery->planLocal([$forcedPacket]);
$skipReply=$delivery->applyLocal([$forcedPacket],$skipPlan);
$expect($skipReply['ok'] && !empty($skipReply['results']['100']['skipped']) && empty($skipReply['results']['100']['ok']),'Missing owner has an explicit exclusion receipt');
$expect(!$deliveryBinder->names && $beforeSettings===$deliveryModule->settings && FreePBX::$reloads===$beforeReloads,'All-skipped forced delivery must not write cache, touch Core or reload');
$packetsService->recordIdentityPacketResults([$packet],['owner-site:100'=>['ok'=>false,'message'=>'Earlier failed delivery']]);
$excluded=$packetsService->recordIdentityPacketResults([$packet],$skipReply['results']);
$expect($excluded['ok'] && $excluded['applied']===0 && $excluded['queued']===0 && count($excluded['skipped'])===1,'Skipped result must not count as applied or queue a failed retry');
$expect($logical->findById($targetId)['apply_status']==='skipped' && $db->query('SELECT state FROM ucmp_site_jobs LIMIT 1')->fetchColumn()==='skipped','Persist exclusion and retire the obsolete retry');
$db->exec("CREATE TABLE ucmp_sites (id INTEGER PRIMARY KEY,name TEXT); INSERT INTO ucmp_sites VALUES (1,'Example branch'); CREATE TABLE ucmp_directory_conflicts (status TEXT)");
$warning=$packetsService->stats()['identity_skips'][0];
$expect($warning['extension']==='100' && $warning['site_name']==='Example branch' && str_contains($warning['reason'],'does not exist'),'Directory warning must identify site, extension and reason');
$staleSkip=$packetsService->recordIdentityPacketResults([array_replace($packet,['extension_revision'=>$packet['extension_revision']+1])],$skipReply['results']);
$expect(!$staleSkip['ok'] && $staleSkip['stale_extension_ids']===[$targetId],'A skip receipt must obey the same revision guard as success');
$deliveryBinder->missing=false;
$reject(fn()=>$delivery->applyLocal([$forcedPacket],$skipPlan));
$expect(!$deliveryBinder->names && $beforeSettings===$deliveryModule->settings,'Account appearing after review cannot change the fixed plan silently');
$retryPlan=$delivery->planLocal([$packet]);$retryReply=$delivery->applyLocal([$packet],$retryPlan);
$expect($retryReply['ok'] && $deliveryBinder->names['100']==='Blair Example','A fresh plan rejoins the restored account');
$packetUcm->reply=['results'=>$retryReply['results']];
$apply=new ReflectionMethod(DirectoryService::class,'applyIdentities');
$retried=$apply->invoke($packetsService,$packetsService->entries(false,0),['ad_sync_names'=>'1','ad_sync_labels'=>'1'],false,['extension_ids'=>[$targetId]],false);
$expect($packetUcm->deliveries===1 && !$retried['queued'] && $logical->findById($targetId)['apply_status']==='applied','Next ordinary sync must reconsider an exclusion even when its last applied hash matches');
$expect(!$packetsService->stats()['identity_skips'],'Successful recovery automatically clears the editor warning');

// A complete accepted departure records a durable tombstone, preserving linked devices.
$beforeRelease=$logical->enumerateBindings(1);
$db->exec("UPDATE ucmp_directory_sources SET last_accepted_revision='r5',last_success=200");
$db->exec("UPDATE ucmp_directory_entries SET source_revision='r5',extension='' WHERE object_key='person-a'");
$db->exec("DELETE FROM ucmp_directory_entries WHERE object_key='person-b'");
$remember=new ReflectionMethod(DirectoryService::class,'rememberIdentityReleases');
$beforeFailure=$db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$db->exec("CREATE TRIGGER reject_release_storage BEFORE INSERT ON ucmp_settings WHEN NEW.skey='identity_delivery_releases' BEGIN SELECT RAISE(ABORT,'fixture release storage failure'); END");
$storageFailed=false;$db->beginTransaction();
try {
    $logical->reconcileAcceptedSourceBindings(1,'r5',[['object_key'=>'person-a','extension'=>'']],true,200);
    $remember->invoke($packetsService,$beforeRelease,1,'r5',200);
    $db->commit();
} catch (PDOException $expected) { $storageFailed=true;$db->rollBack(); }
$expect($storageFailed && $beforeFailure===$db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Release storage failure rolls back the primary unbinding and all relationship revisions');
$db->exec('DROP TRIGGER reject_release_storage');
$db->beginTransaction();
$logical->reconcileAcceptedSourceBindings(1,'r5',[['object_key'=>'person-a','extension'=>'']],true,200);
$remember->invoke($packetsService,$beforeRelease,1,'r5',200);
$db->commit();
$releasedPlan=$packetsService->planIdentityPackets(['extension_ids'=>[$targetId]]);
$expect(count($releasedPlan['targets'])===1 && !empty($releasedPlan['targets'][0]['released']),'A later force refresh retains a complete-source release, even after interruption');
$serviceClass=get_class($packetsService);$restartedService=new $serviceClass($packetUcm);
$expect($restartedService->planIdentityPackets(['extension_ids'=>[$targetId]])['targets']===$releasedPlan['targets'],'A fresh service instance recovers the exact committed release after interruption');
$expect($logical->findById((int)$secondary['id'])['linked_object_key']==='person-a','Departure does not retarget linked additional identity');
$saved=$packetUcm->getSetting('identity_delivery_releases',1,'');
try { $remember->invoke($packetsService,$beforeRelease,1,'unaccepted',201);throw new LogicException('Unaccepted release admitted'); }
catch (DomainException $expected) {}
$expect($saved===$packetUcm->getSetting('identity_delivery_releases',1,''),'Unaccepted/incomplete source cannot alter retained release evidence');
echo "Identity packet read-only planning, CAS, failure acknowledgement and durable release assertions passed\n";
}
