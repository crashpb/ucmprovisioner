<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** Owner-routed operator inspections. Never expose generated configuration bytes. */
final class OperatorWorkflowService
{
    private $ucm;
    public function __construct($ucm) { $this->ucm = $ucm; }

    public static function ensureSchema(\PDO $db): void
    {
        $text=(string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql' ? 'LONGTEXT' : 'TEXT';
        $db->exec('CREATE TABLE IF NOT EXISTS ucmp_operator_changes (
            id VARCHAR(32) PRIMARY KEY, site_id INTEGER NOT NULL, kind VARCHAR(24) NOT NULL,
            state VARCHAR(24) NOT NULL, plan_hash VARCHAR(64) NOT NULL, plan_json '.$text.' NOT NULL,
            result_json '.$text.' NULL, actor VARCHAR(128) NOT NULL, created BIGINT NOT NULL, updated BIGINT NOT NULL)');
    }

    public static function isReadOnly(array $request): bool
    {
        return in_array((string)($request['op'] ?? ''), ['explain','impact','evidence','change_status','change_history','delivery_plan','identity_editor','identity_people','identity_sources'], true);
    }

    public function handle(array $request): array
    {
        $op=(string)($request['op'] ?? '');
        try {
            // Relationship IDs belong to the directory authority, not the remote PBX.
            if ($op==='identity_editor') { return $this->identityEditor((int)($request['site_id'] ?? 0),(string)($request['extension'] ?? '')); }
            if ($op==='identity_people') { return $this->identityPeople((string)($request['search'] ?? '')); }
            if ($op==='identity_sources') {
                // Directory authorities are keyed by source_key/domain. There
                // is deliberately no display-only `name` column in this table.
                $sources=$this->ucm->getDb()->query(
                    "SELECT id,source_key,domain,last_success,last_accepted_revision
                      FROM ucmp_directory_sources
                      WHERE enabled=1 AND last_accepted_revision IS NOT NULL AND last_accepted_revision<>''
                      ORDER BY domain,source_key"
                )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                return ['ok'=>true,'sources'=>$sources,'message'=>'Only enabled authorities with an accepted source snapshot are listed.'];
            }
            if (in_array($op,['change_status','change_history'],true) && ($request['authority'] ?? '')==='directory') { return $this->changeHistory((int)($request['site_id'] ?? 0),$request,true); }
            if ($op==='identity_preview') { return $this->previewIdentity($request); }
            if ($op==='identity_save') { return $this->saveIdentity($request); }
            if ($op==='identity_refresh_plan') { return $this->planIdentityRefresh($request); }
            if ($op==='identity_refresh_execute') { return $this->executeIdentityRefresh($request); }
        } catch (\Throwable $error) {
            return $this->safeFailure($request,$error);
        }
        if (!self::isReadOnly($request) && !in_array($op,['delivery_execute','delivery_stage_begin','delivery_stage_chunk','rebuild_plan','rebuild_execute','replacement_preview','replacement_execute'],true)) { return ['ok'=>false,'message'=>'Unknown or unavailable operator operation']; }
        $site = (int)($request['site_id'] ?? 0);
        if ($site < 1) { return ['ok'=>false,'message'=>'Select an explicit owner site']; }
        return $this->ucm->operatorWorkflowAtSite($site,$request);
    }

    /** Keep validation/planning failures distinct from an operation that was claimed. */
    private function safeFailure(array $request, \Throwable $error): array
    {
        $op=(string)($request['op'] ?? '');
        $planId=$this->safePlanId($request);
        if ($planId) {
            $state=$this->retainedPlanState($planId);
            if ($state==='applying') {
                return ['ok'=>false,'plan_id'=>$planId,'state'=>'uncertain',
                    'message'=>'This reviewed operation reached execution but its final response was not recorded. Read retained change status before creating another review.'];
            }
            if ($state==='unknown') {
                return ['ok'=>false,'plan_id'=>$planId,'state'=>'uncertain',
                    'message'=>'The retained review state could not be read. Do not replay this operation; inspect the operator change record first.'];
            }
            if (in_array($state,['completed','failed'],true)) {
                return ['ok'=>false,'plan_id'=>$planId,'state'=>$state,
                    'message'=>'A retained outcome already exists for this review. Read retained change status before taking any further action.'];
            }
            if ($state==='planned') {
                return ['ok'=>false,'plan_id'=>$planId,'state'=>'not_started',
                    'message'=>'This reviewed operation was not started. No changes were made; create a fresh review.'];
            }
        }
        if ($op==='identity_save' || $op==='identity_refresh_execute') {
            return ['ok'=>false,'plan_id'=>$planId,'state'=>'not_started',
                'message'=>'This review could not be started. No changes were made; create a fresh review.'];
        }
        if ($error instanceof \InvalidArgumentException || $error instanceof \DomainException) {
            return ['ok'=>false,'plan_id'=>$planId,'state'=>'not_started',
                'message'=>substr(trim($error->getMessage()),0,240) ?: 'The requested review is not valid. No changes were made.'];
        }
        if ($op==='identity_sources') {
            return ['ok'=>false,'state'=>'not_started',
                'message'=>'Directory authorities could not be read. No changes were made; reload the Directory page and check its schema.'];
        }
        return ['ok'=>false,'plan_id'=>$planId,'state'=>'not_started',
            'message'=>self::isReadOnly($request)
                ? 'Inspection could not complete. No changes were made; check the owner-site diagnostics.'
                : 'The requested review could not be prepared. No changes were made; correct the reported input and try again.'];
    }

    private function retainedPlanState(string $id): ?string
    {
        try {
            $stmt=$this->ucm->getDb()->prepare('SELECT state FROM ucmp_operator_changes WHERE id=?');
            $stmt->execute([$id]);
            $state=$stmt->fetchColumn();
            return $state===false ? null : (string)$state;
        } catch (\Throwable $ignored) {
            // If the receipt store itself cannot be read, never claim that an
            // operation was harmlessly skipped.
            return 'unknown';
        }
    }

    /** @param array<int,array<string,mixed>> $skipped */
    private function skippedSummary(array $skipped): array
    {
        $reasons=[];
        foreach ($skipped as $row) {
            $reason=trim((string)($row['reason'] ?? 'Not eligible for this review'));
            if ($reason==='') { $reason='Not eligible for this review'; }
            $reasons[$reason]=($reasons[$reason] ?? 0)+1;
        }
        arsort($reasons);
        return ['count'=>count($skipped),'reasons'=>$reasons];
    }

    public function handleLocal(array $request): array
    {
        $site = (int)$this->ucm->localSiteId();
        if ((int)($request['site_id'] ?? 0) !== $site) { return ['ok'=>false,'message'=>'Owner site mismatch']; }
        try {
            switch ((string)($request['op'] ?? '')) {
                case 'explain': return $this->explainPhone($site,(int)($request['phone_id'] ?? 0));
                case 'evidence': return $this->evidence($site,(int)($request['phone_id'] ?? 0));
                case 'change_status':
                case 'change_history': return $this->changeHistory($site,$request);
                case 'impact': return $this->previewImpact($site,$request);
                case 'replacement_preview': return $this->previewReplacement($site,$request);
                case 'replacement_execute': return $this->executeReplacement($site,$request);
                case 'rebuild_plan': return $this->planRebuild($site,$request);
                case 'rebuild_execute': return $this->executeRebuild($site,$request);
                case 'delivery_stage_begin': return $this->beginDeliveryInput($site,$request);
                case 'delivery_stage_chunk': return $this->appendDeliveryInput($site,$request);
                case 'delivery_plan':
                    $plan=(new IdentityDeliveryService($this->ucm,null,(string)($request['receiver_site_key'] ?? '')))->planLocal($this->deliveryInput($site,$request));
                    unset($plan['cache_after'],$plan['core_targets'],$plan['items']);
                    return $plan;
                case 'delivery_execute':
                    $fixed = ['plan_hash'=>(string)($request['plan_hash'] ?? '')];
                    if (empty($fixed['plan_hash'])) { throw new \InvalidArgumentException('Reviewed delivery plan required'); }
                    return (new IdentityDeliveryService($this->ucm,null,(string)($request['receiver_site_key'] ?? '')))->applyLocal($this->deliveryInput($site,$request),$fixed);
                default: return ['ok'=>false,'message'=>'Unknown or unavailable operator operation'];
            }
        } catch (\Throwable $error) {
            // Resolver/vendor exceptions can contain raw values; do not reflect them.
            return $this->safeFailure($request,$error);
        }
    }

    private function arrayInput($value): array
    {
        if (is_string($value)) { $value=json_decode($value,true,32,JSON_THROW_ON_ERROR); }
        if (!is_array($value)) { throw new \InvalidArgumentException('Expected object or list'); }
        return $value;
    }

    private function safePlanId(array $request): ?string
    {
        $id=(string)($request['plan_id'] ?? $request['input_id'] ?? '');return preg_match('/\A[a-f0-9]{32}\z/D',$id)?$id:null;
    }

    public function beginDeliveryInput(int $site,array $request): array
    {
        $count=(int)($request['total_count'] ?? 0);$hash=(string)($request['content_hash'] ?? '');$origin=(string)($request['origin_site_key'] ?? '');
        $this->guardRemoteOrigin($site,$request,$origin);
        if ($site!==(int)$this->ucm->localSiteId() || $count<1 || $count>5000 || !preg_match('/\A[a-f0-9]{64}\z/D',$hash) || !preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D',$origin)) { throw new \InvalidArgumentException('Invalid delivery input declaration'); }
        $data=['origin_site_key'=>$origin,'total_count'=>$count,'content_hash'=>$hash,'packets'=>[],'chunk_hashes'=>[]];
        $id=bin2hex(random_bytes(16));$now=time();
        $this->ucm->getDb()->prepare('INSERT INTO ucmp_operator_changes(id,site_id,kind,state,plan_hash,plan_json,result_json,actor,created,updated) VALUES(?,?,?,?,?,?,NULL,?,?,?)')
            ->execute([$id,$site,'delivery_input','staging',IdentityDeliveryService::hash($data,true),json_encode($data,JSON_THROW_ON_ERROR),$this->actor(),$now,$now]);
        return ['ok'=>true,'input_id'=>$id,'received_count'=>0,'ready'=>false];
    }

    private function inputRow(int $site,string $id): array
    {
        if ($site!==(int)$this->ucm->localSiteId() || !preg_match('/\A[a-f0-9]{32}\z/D',$id)) { throw new \InvalidArgumentException('Invalid input owner or ID'); }
        $stmt=$this->ucm->getDb()->prepare("SELECT * FROM ucmp_operator_changes WHERE id=? AND site_id=? AND kind='delivery_input'");$stmt->execute([$id,$site]);$row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !in_array($row['state'],['staging','ready'],true) || (int)$row['created']<time()-3600 || strlen($row['plan_json'])>8388608) { throw new \DomainException('Delivery input unavailable or expired'); }
        $row['data']=json_decode($row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        if (!hash_equals($row['plan_hash'],IdentityDeliveryService::hash($row['data'],true))) { throw new \DomainException('Delivery input integrity failure'); }
        return $row;
    }

    public function appendDeliveryInput(int $site,array $request): array
    {
        $row=$this->inputRow($site,(string)($request['input_id'] ?? ''));$data=$row['data'];$chunk=$this->arrayInput($request['identities'] ?? []);$index=(int)($request['index'] ?? -1);
        $this->guardRemoteOrigin($site,$request,$data['origin_site_key']);
        if (!$chunk || count($chunk)>128 || !array_is_list($chunk) || $index<0) { throw new \InvalidArgumentException('Delivery chunk must contain 1 to 128 ordered packets'); }
        foreach ($chunk as $packet) {
            $validated=IdentityDeliveryService::normalize($packet)+['force'=>!empty($packet['force'])];
            if ($validated['origin_site_key']!==$data['origin_site_key'] || !hash_equals(IdentityDeliveryService::hash($validated,true),IdentityDeliveryService::hash($packet,true))) {
                throw new \DomainException('Staged delivery requires the exact normalized origin projection');
            }
        }
        $digest=IdentityDeliveryService::hash($chunk,true);
        if (isset($data['chunk_hashes'][$index])) {
            if (!hash_equals($data['chunk_hashes'][$index],$digest)) { throw new \DomainException('Different content for an existing delivery chunk'); }
            return ['ok'=>true,'input_id'=>$row['id'],'received_count'=>count($data['packets']),'ready'=>$row['state']==='ready'];
        }
        if ($row['state']!=='staging' || $index!==count($data['chunk_hashes'])) { throw new \DomainException('Delivery chunks must arrive in order'); }
        $data['packets']=array_merge($data['packets'],$chunk);$data['chunk_hashes'][]=$digest;$received=count($data['packets']);
        if ($received>$data['total_count']) { throw new \DomainException('Delivery count exceeds declaration'); }
        $ready=$received===$data['total_count'];
        if ($ready && !hash_equals($data['content_hash'],IdentityDeliveryService::hash($data['packets'],true))) { throw new \DomainException('Complete delivery content does not match declaration'); }
        $encoded=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if (strlen($encoded)>8388608) { throw new \DomainException('Delivery input exceeds 8 MiB'); }
        $stmt=$this->ucm->getDb()->prepare("UPDATE ucmp_operator_changes SET state=?,plan_hash=?,plan_json=?,updated=? WHERE id=? AND state='staging' AND plan_hash=?");
        $stmt->execute([$ready?'ready':'staging',IdentityDeliveryService::hash($data,true),$encoded,time(),$row['id'],$row['plan_hash']]);
        if ($stmt->rowCount()!==1) { throw new \DomainException('Concurrent delivery upload; retry the identical chunk'); }
        return ['ok'=>true,'input_id'=>$row['id'],'received_count'=>$received,'ready'=>$ready];
    }

    private function deliveryInput(int $site,array $request): array
    {
        if (!empty($request['input_id'])) {
            if (isset($request['identities'])) { throw new \InvalidArgumentException('Use either staged or inline input'); }
            $row=$this->inputRow($site,(string)$request['input_id']);$data=$row['data'];
            $this->guardRemoteOrigin($site,$request,$data['origin_site_key']);
            if ($row['state']!=='ready' || count($data['packets'])!==$data['total_count'] || !hash_equals($data['content_hash'],IdentityDeliveryService::hash($data['packets'],true))) { throw new \DomainException('Incomplete delivery input cannot be planned or executed'); }
            return $data['packets'];
        }
        $packets=$this->arrayInput($request['identities'] ?? []);
        if (count($packets)>128) { throw new \InvalidArgumentException('Large delivery requires staged input'); }
        foreach ($packets as $packet) { $this->guardRemoteOrigin($site,$request,(string)($packet['origin_site_key'] ?? '')); }
        return $packets;
    }

    private function guardRemoteOrigin(int $site,array $request,string $origin): void
    {
        // The transport marks remote requests. A shared admin secret is not a
        // cryptographic identity for the claimed foreign origin; never let it
        // impersonate this PBX's stronger local relationship authority.
        if (!empty($request['_remote_request']) && $origin===(string)((array)$this->ucm->getSite($site))['site_key']) {
            throw new \DomainException('Remote delivery cannot claim destination-local identity authority');
        }
    }

    private function actor(): string
    {
        $actor=trim((string)($_SERVER['REMOTE_USER'] ?? ''));
        $user=$_SESSION['AMP_user'] ?? null;
        if ($actor==='' && is_object($user) && method_exists($user,'getUsername')) { $actor=(string)$user->getUsername(); }
        return substr($actor!=='' ? $actor : 'authenticated-operator',0,128);
    }

    private function rememberPlan(int $site,string $kind,array $payload,array $review): array
    {
        $id=bin2hex(random_bytes(16));$hash=IdentityDeliveryService::hash(['site_id'=>$site,'kind'=>$kind,'payload'=>$payload]);$now=time();
        $this->ucm->getDb()->prepare('INSERT INTO ucmp_operator_changes(id,site_id,kind,state,plan_hash,plan_json,result_json,actor,created,updated) VALUES(?,?,?,?,?,?,NULL,?,?,?)')
            ->execute([$id,$site,$kind,'planned',$hash,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$this->actor(),$now,$now]);
        return ['ok'=>true,'plan_id'=>$id,'plan_hash'=>$hash,'site_id'=>$site,'kind'=>$kind,'review'=>$review,'expires_at'=>$now+1800,
            'message'=>'Fixed review recorded. No source save or deployment has occurred.'];
    }

    private function readPlan(array $request,string $kind,?int $site=null): array
    {
        $id=(string)($request['plan_id'] ?? '');$hash=(string)($request['plan_hash'] ?? '');
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$id) || !preg_match('/\A[a-f0-9]{64}\z/D',$hash)) { throw new \InvalidArgumentException('Reviewed plan required'); }
        $stmt=$this->ucm->getDb()->prepare('SELECT * FROM ucmp_operator_changes WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['kind']!==$kind || $row['state']!=='planned' || !hash_equals($row['plan_hash'],$hash) || ($site!==null && (int)$row['site_id']!==$site)
            || (int)$row['created']<time()-1800) { throw new \DomainException('Plan expired, changed or already attempted; review again'); }
        $row['payload']=json_decode($row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        if (!hash_equals($hash,IdentityDeliveryService::hash(['site_id'=>(int)$row['site_id'],'kind'=>$kind,'payload'=>$row['payload']]))) { throw new \DomainException('Stored plan integrity failure'); }
        return $row;
    }

    private function startPlan(array $row): void
    {
        $stmt=$this->ucm->getDb()->prepare("UPDATE ucmp_operator_changes SET state='applying',updated=? WHERE id=? AND state='planned' AND plan_hash=?");
        $stmt->execute([time(),$row['id'],$row['plan_hash']]);if ($stmt->rowCount()!==1) { throw new \DomainException('Plan already claimed'); }
    }

    private function finishPlan(array $row,array $result): array
    {
        try {
            $stmt=$this->ucm->getDb()->prepare("UPDATE ucmp_operator_changes SET state=?,result_json=?,updated=? WHERE id=? AND state='applying' AND plan_hash=?");
            if (!$stmt->execute([!empty($result['ok'])?'completed':'failed',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time(),$row['id'],$row['plan_hash']]) || $stmt->rowCount()!==1) { throw new \RuntimeException('Final operator receipt was not confirmed'); }
        } catch (\Throwable) {
            $result['operation_ok']=!empty($result['ok']);
            $result['ok']=false;
            $result['receipt_saved']=false;
            $result['state']='uncertain';
            $result['reporting_error']='Could not record the final operator outcome. Known stage results are retained below; inspect change status before any new action. This review cannot be replayed.';
            $result['message']=trim((string)($result['message'] ?? '') . ' ' . $result['reporting_error']);
        }
        return $result+['plan_id'=>$row['id'],'plan_hash'=>$row['plan_hash']];
    }

    public function changeHistory(int $site,array $request,bool $directory=false): array
    {
        if ($site<1) { throw new \InvalidArgumentException('Explicit site required'); }
        $id=$this->safePlanId($request);if (($request['op'] ?? '')==='change_status' && !$id) { throw new \InvalidArgumentException('Explicit plan required'); }
        $query='SELECT id,site_id,kind,state,created,updated,result_json FROM ucmp_operator_changes WHERE site_id=?';$args=[$site];
        $query.=$directory?" AND kind IN ('identity','identity_refresh')":" AND kind NOT IN ('identity','identity_refresh','delivery_input')";
        if ($id) { $query.=' AND id=?';$args[]=$id; }
        $stmt=$this->ucm->getDb()->prepare($query.' ORDER BY created DESC LIMIT 50');$stmt->execute($args);$rows=[];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result=json_decode((string)$row['result_json'],true) ?: [];unset($row['result_json']);
            $row['outcome']=array_intersect_key($result,array_flip(['ok','deployed','affected_phone_ids','assignment_before','assignment_after','reverse_review','post_change_checklist',
                'transaction_id','stages','phone_ids','sites','phonebooks','device_observed','message','recovery_required','reporting_errors']));
            $row['uncertain']=$row['state']==='applying';$row['expired']=$row['state']==='planned' && (int)$row['created']<time()-1800;$rows[]=$row;
        }
        return ['ok'=>true,'site_id'=>$site,'changes'=>$rows,'message'=>'Retained receipts only. Applying means incomplete or uncertain; do not replay. No automatic execution or reconciliation was performed.'];
    }

    public function planRebuild(int $site,array $request): array
    {
        $ids=$this->arrayInput($request['phone_ids'] ?? []);$ids=array_values(array_unique(array_map('intval',$ids)));sort($ids);
        if (!$ids || count($ids)>200 || min($ids)<1) { throw new \InvalidArgumentException('Select 1 to 200 explicit phones'); }
        foreach ($ids as $id) { $phone=$this->phone($site,$id);if (($phone['config_mode'] ?? 'managed')==='manual' || !empty($phone['exclude_bulk_rebuild'])) { throw new \DomainException('Manual or excluded phones require their dedicated explicit workflow'); } }
        $preview=(new Provisioner($this->ucm))->rebuild(implode(',',$ids),false,$site);
        if (empty($preview['ok'])) { return ['ok'=>false,'message'=>'Owner build preview did not complete']; }
        return $this->rememberPlan($site,'rebuild',['phone_ids'=>$ids,'build_plan_hash'=>$preview['plan_hash']],['phone_ids'=>$ids,'files'=>$preview['files'],'build_plan_hash'=>$preview['plan_hash'],
            'stages'=>['desired'=>'planned','files'=>'not applied','pbx'=>'not applied','device'=>'unverified']]);
    }

    public function executeRebuild(int $site,array $request): array
    {
        $row=$this->readPlan($request,'rebuild',$site);$this->startPlan($row);
        try {
            $payload=$row['payload'];$result=(new Provisioner($this->ucm))->rebuild(implode(',',$payload['phone_ids']),true,$site,false,$payload['build_plan_hash']);
            $safe=['ok'=>!empty($result['ok']),'transaction_id'=>$result['transaction_id'] ?? null,'stages'=>$result['stages'] ?? [],
                'recovery_required'=>!empty($result['recovery_required']),'reporting_errors'=>$result['reporting_errors'] ?? [],
                'phone_ids'=>$payload['phone_ids'],'message'=>!empty($result['ok'])?'Reviewed build applied; physical display remains unverified':'Build incomplete; inspect its retained transaction before retrying'];
        } catch (\Throwable $error) { $safe=['ok'=>false,'message'=>'Build attempt failed or its outcome is uncertain. Inspect evidence and create a new review; this plan cannot be replayed.']; }
        return $this->finishPlan($row,$safe);
    }

    public function identityEditor(int $site,string $extension): array
    {
        if ($site<1 || !$this->ucm->getSite($site)) { throw new \InvalidArgumentException('Explicit identity site required'); }
        $logical=new LogicalExtensionService($this->ucm->getDb());$row=$logical->findBySiteExtension($site,$extension);
        if (!$row) { return ['ok'=>false,'message'=>'No authoritative logical identity is known here for this extension']; }
        $resolved=(new DirectoryService($this->ucm))->resolveExtensionIdentity($site,$extension);
        return ['ok'=>true,'extension_id'=>(int)$row['id'],'site_id'=>$site,'extension'=>$extension,'revision'=>(int)$row['revision'],
            'binding'=>LogicalExtensionService::identityBinding($row),'resolved'=>$resolved,
            'overrides'=>$logical->overridesForExtension((int)$row['id'],true),
            'message'=>'Full caller name and short phone name are separate. Saving identity does not deploy phones.'];
    }

    public function identityPeople(string $search): array
    {
        $search=trim($search);if (strlen($search)<2) { return ['ok'=>true,'people'=>[],'message'=>'Enter at least two characters to search accepted people']; }
        $stmt=$this->ucm->getDb()->prepare('SELECT d.source_id,d.object_key,d.display_name,d.label,d.extension,d.source_revision,s.last_success FROM ucmp_directory_entries d JOIN ucmp_directory_sources s ON s.id=d.source_id AND s.last_accepted_revision=d.source_revision WHERE d.display_name LIKE ? OR d.label LIKE ? ORDER BY d.display_name LIMIT 101');
        $term='%'.substr($search,0,128).'%';$stmt->execute([$term,$term]);$people=$stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return ['ok'=>true,'people'=>array_slice($people,0,100),'truncated'=>count($people)>100,'message'=>'Last accepted source only; this search does not fetch AD'];
    }

    public function previewIdentity(array $request): array
    {
        $logical=new LogicalExtensionService($this->ucm->getDb());$row=$logical->findById((int)($request['extension_id'] ?? 0));
        if (!$row || (int)($request['revision'] ?? 0)!==(int)$row['revision']) { throw new \DomainException('Identity revision changed'); }
        $site=(int)$row['site_id'];$before=$this->identityEditor($site,(string)$row['extension']);
        $action=(string)($request['action'] ?? 'binding');$payload=['extension_id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'action'=>$action,
            'before_hash'=>IdentityDeliveryService::hash($before)];
        $proposed=$row;$overrides=$logical->overridesForExtension((int)$row['id'],true);$directory=new DirectoryService($this->ucm);
        if ($action==='binding') {
            $mode=(string)($request['binding_mode'] ?? '');if (!in_array($mode,['ad_primary','linked_additional','manual_shared'],true)) { throw new \InvalidArgumentException('Invalid binding mode'); }
            $payload+=['binding_mode'=>$mode,'source_id'=>(int)($request['source_id'] ?? 0),'object_key'=>(string)($request['object_key'] ?? ''),'manual_identity'=>$this->arrayInput($request['manual_identity'] ?? [])];
            $proposed['binding_mode']=$mode;$proposed['ad_source_id']=null;$proposed['ad_object_key']=null;
            $proposed['linked_source_id']=$mode==='linked_additional'?$payload['source_id']:null;$proposed['linked_object_key']=$mode==='linked_additional'?$payload['object_key']:null;
            if ($mode==='manual_shared') { $payload['manual_identity']['extension']=$row['extension'];$payload['manual_identity']=LogicalExtensionService::normalizeAdIdentity($payload['manual_identity']);$proposed['manual_identity_json']=json_encode($payload['manual_identity']); }
            $proposed['identity_adoption']=$mode==='ad_primary'?'legacy_review':'managed';
        } elseif ($action==='adoption') {
            $payload['choices']=$this->arrayInput($request['choices'] ?? []);
            foreach (array_merge(['display_name'],LogicalExtensionService::FINAL_FIELDS) as $field) {
                $choice=$payload['choices'][$field] ?? [];
                if (!in_array($choice['mode'] ?? '',['managed','preserve'],true) || (($choice['mode'] ?? '')==='preserve' && !is_string($choice['value'] ?? null))) { throw new \InvalidArgumentException('Every field requires a managed or preserve decision'); }
                $overrideMode=$field==='display_name'?'canonical':'final';
                $overrides=array_values(array_filter($overrides,static fn($row)=>($row['field_name'] ?? '')!==$field || ($row['mode'] ?? '')!==$overrideMode));
                if ($choice['mode']==='preserve') { $overrides[]=['field_name'=>$field,'mode'=>$overrideMode,'value_json'=>json_encode($choice['value'],JSON_THROW_ON_ERROR)]; }
            }
            $proposed['identity_adoption']='managed';
        } else { throw new \InvalidArgumentException('Unsupported identity review action'); }
        $binding=LogicalExtensionService::identityBinding($proposed);$person=$binding['source_id']>0?$directory->resolvePerson($binding['source_id'],$binding['object_key']):null;
        if ($binding['mode']==='linked_additional' && !$person) { throw new \DomainException('Accepted person is unavailable'); }
        if ($person) { $payload['accepted_person']=['source_id'=>$binding['source_id'],'object_key'=>$binding['object_key'],'hash'=>IdentityDeliveryService::hash($person)]; }
        $after=LogicalExtensionService::resolveBoundIdentity($proposed,$person,$overrides);
        return $this->rememberPlan($site,'identity',$payload,['before'=>$before,'after'=>$after,'action'=>$action,
            'choices'=>$payload['choices'] ?? null,'note'=>'Source-only save. Existing deployments remain unchanged until a separately reviewed refresh.']);
    }

    public function saveIdentity(array $request): array
    {
        $plan=$this->readPlan($request,'identity');$payload=$plan['payload'];$db=$this->ucm->getDb();$logical=new LogicalExtensionService($db);
        $current=$logical->findById($payload['extension_id']);
        if (!$current || !hash_equals($payload['before_hash'],IdentityDeliveryService::hash($this->identityEditor((int)$current['site_id'],(string)$current['extension'])))) { throw new \DomainException('Accepted identity changed after review'); }
        if (isset($payload['accepted_person'])) {
            $accepted=$payload['accepted_person'];$person=(new DirectoryService($this->ucm))->resolvePerson($accepted['source_id'],$accepted['object_key']);
            if (!$person || !hash_equals($accepted['hash'],IdentityDeliveryService::hash($person))) { throw new \DomainException('Selected accepted person changed after review'); }
        }
        $this->startPlan($plan);
        try {
            $db->beginTransaction();
            if ($payload['action']==='binding') {
                $saved=$logical->setIdentityBinding($payload['extension_id'],$payload['binding_mode'],$payload['source_id'],$payload['object_key'],$payload['manual_identity'],$payload['revision']);
            } else { $saved=$logical->adoptIdentity($payload['extension_id'],$payload['revision'],$payload['choices'],$this->actor()); }
            $db->commit();$result=['ok'=>true,'extension_id'=>$payload['extension_id'],'revision'=>(int)$saved['revision'],'deployed'=>false,'message'=>'Reviewed identity saved. No phone rebuild or AD write occurred.'];
        } catch (\Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); }$result=['ok'=>false,'message'=>'Identity save did not complete. Refresh the relationship and create a new review.']; }
        return $this->finishPlan($plan,$result);
    }

    public function planIdentityRefresh(array $request): array
    {
        $scope=$this->arrayInput($request['scope'] ?? []);
        if (isset($scope['targets'])) {
            $targets=$this->arrayInput($scope['targets']);
            if (!$targets) {
                return ['ok'=>false,'state'=>'selection_required','eligible_count'=>0,'skipped_count'=>0,
                    'message'=>'Select at least one relationship. No changes were made.'];
            }
            if (count($targets)>5000) { throw new \InvalidArgumentException('Select no more than 5000 relationships'); }
            $logical=new LogicalExtensionService($this->ucm->getDb());$ids=[];
            foreach ($targets as $target) {
                $row=$logical->findBySiteExtension((int)($target['site_id'] ?? 0),(string)($target['extension'] ?? ''));
                if (!$row) { throw new \DomainException('Selected relationship is not known on this directory authority'); }$ids[]=(int)$row['id'];
            }
            $scope=['extension_ids'=>array_values(array_unique($ids))];
        }
        $all=!empty($scope['all']);$scope=array_intersect_key($scope,array_flip(['site_id','source_id','object_key','extension_ids']));
        if (!$scope && !$all) { throw new \InvalidArgumentException('Choose an explicit identity scope'); }
        foreach (['site_id','source_id'] as $key) { if (array_key_exists($key,$scope) && (int)$scope[$key]<1) { throw new \InvalidArgumentException('Selected scope ID must be positive'); } }
        if (array_key_exists('object_key',$scope) && (trim((string)$scope['object_key'])==='' || empty($scope['source_id']))) { throw new \InvalidArgumentException('A person scope needs an accepted source and object key'); }
        if (array_key_exists('extension_ids',$scope)) {
            $ids=$this->arrayInput($scope['extension_ids']);if (!$ids || count($ids)>5000 || min(array_map('intval',$ids))<1) { throw new \InvalidArgumentException('Selected identities cannot be empty or invalid'); }
            $scope['extension_ids']=array_values(array_unique(array_map('intval',$ids)));
        }
        $directory=new DirectoryService($this->ucm);$accepted=$directory->planIdentityPackets($scope);$packets=(array)($accepted['targets'] ?? []);
        $skippedSummary=$this->skippedSummary((array)($accepted['skipped'] ?? []));
        if (!$packets) {
            $legacy=(int)($skippedSummary['reasons']['Legacy identity fields require adoption review'] ?? 0);
            $message=$legacy>0
                ? sprintf('%d relationship(s) need identity adoption review before they can be refreshed. No changes were made.', $legacy)
                : 'No eligible accepted relationships matched this selection. No changes were made.';
            return ['ok'=>false,'state'=>'no_eligible_relationships','eligible_count'=>0,
                'skipped_count'=>$skippedSummary['count'],'skipped_reasons'=>$skippedSummary['reasons'],
                'message'=>$message];
        }
        if (count($packets)>5000) { throw new \InvalidArgumentException('Select no more than 5000 eligible relationships'); }
        try { $delivery=IdentityDeliveryService::planSites($this->ucm,$packets); }
        catch (\Throwable $error) {
            return ['ok'=>false,'state'=>'destination_unavailable','eligible_count'=>count($packets),
                'skipped_count'=>$skippedSummary['count'],'skipped_reasons'=>$skippedSummary['reasons'],
                'message'=>'Cannot prepare this refresh: '.substr($error->getMessage(),0,240).'. No executable partial plan was saved.'];
        }
        $destinations=$delivery['destinations'];
        foreach ($delivery['skipped'] as $skip) { $skippedSummary['count']++;$skippedSummary['reasons'][$skip['message']]=($skippedSummary['reasons'][$skip['message']] ?? 0)+1; }
        $phonebooks=$directory->planAcceptedPhonebooks();
        $identitySites=array_column($destinations,'site_id');$bookSites=array_column((array)($phonebooks['destinations'] ?? []),'site_id');sort($identitySites);sort($bookSites);
        if ($identitySites!==$bookSites) { throw new \DomainException('Publication destinations changed during review'); }
        return $this->rememberPlan((int)$this->ucm->localSiteId(),'identity_refresh',['packets'=>$packets,'destinations'=>$destinations,'phonebooks'=>$phonebooks,'delivery_skips'=>$delivery['skipped']],
            ['relationships'=>array_map(static fn($packet)=>array_intersect_key($packet,array_flip(['site_id','extension','extension_id','extension_revision','binding_mode','accepted_at','source_revision'])),$packets),
                'destinations'=>array_map(static function($destination) { unset($destination['input']);return $destination; },$destinations),'phonebooks'=>$phonebooks,
                'eligible_count'=>count($delivery['packets']),'skipped_count'=>$skippedSummary['count'],'skipped_reasons'=>$skippedSummary['reasons'],'skipped'=>$delivery['skipped'],
                'note'=>'Force reuses the last accepted snapshot. No AD fetch, secret rotation, driver change or phone notification is planned.']);
    }

    public function executeIdentityRefresh(array $request): array
    {
        $plan=$this->readPlan($request,'identity_refresh',(int)$this->ucm->localSiteId());$payload=$plan['payload'];$directory=new DirectoryService($this->ucm);
        foreach ($payload['destinations'] as $destination) {
            $current=$this->ucm->getSite($destination['site_id']);
            if (!$current || empty($current['enabled']) || (string)$current['site_key']!==$destination['site_key']) { throw new \DomainException('Destination changed after review'); }
        }
        $this->startPlan($plan);$sites=[];$allOk=true;$results=(array)($payload['delivery_skips'] ?? []);
        try {
            $directory->commitIdentityPackets($payload['packets']);
            foreach ($payload['destinations'] as $destination) {
                if ($destination['plan_hash']==='') { $sites[]=['site_id'=>$destination['site_id'],'ok'=>true,'skipped'=>true,'message'=>'No eligible identity targets; no delivery performed'];continue; }
                try {
                    $reply=$this->ucm->operatorWorkflowAtSite($destination['site_id'],['op'=>'delivery_execute','plan_hash'=>$destination['plan_hash']]+$destination['input']);
                    $ok=!empty($reply['ok']) && (int)($reply['identity_delivery_schema'] ?? 0)===1;$allOk=$allOk && $ok;
                    $sites[]=['site_id'=>$destination['site_id'],'ok'=>$ok,'transaction_id'=>$reply['transaction_id'] ?? null,'stages'=>$reply['stages'] ?? []];
                    foreach ($payload['packets'] as $packet) {
                        $key=$packet['identity_site_key'].':'.$packet['extension'];
                        if ((int)$packet['site_id']===$destination['site_id'] && empty($results[$key]['skipped'])) { $results[$key]=$reply['results'][$packet['extension']] ?? ['ok'=>false]; }
                    }
                } catch (\Throwable $error) { $allOk=false;$sites[]=['site_id'=>$destination['site_id'],'ok'=>false,'message'=>'Delivery response unavailable; inspect destination evidence before retrying']; }
            }
            $published=null;
            if ($allOk) {
                try {
                    $publication=$directory->publishAcceptedPhonebooks($payload['phonebooks']);
                    $published=['ok_count'=>(int)($publication['ok_count'] ?? 0),'queued'=>(int)($publication['queued'] ?? 0),'warning_count'=>count((array)($publication['warnings'] ?? []))];
                    $allOk=$published['queued']===0 && $published['warning_count']===0 && $published['ok_count']===count($payload['phonebooks']['destinations']);
                } catch (\Throwable $error) { $allOk=false;$published=['ok'=>false,'message'=>'Reviewed directory publication could not complete']; }
            }
            foreach ($payload['packets'] as $packet) {
                $key=$packet['identity_site_key'].':'.$packet['extension'];$results[$key]=$results[$key] ?? ['ok'=>false];
                if (!empty($results[$key]['skipped'])) { continue; }
                $results[$key]['ok']=!empty($results[$key]['ok']) && $allOk;
                if (!$results[$key]['ok']) { $results[$key]['message']='One or more reviewed destinations did not confirm delivery'; }
            }
            if (method_exists($directory,'recordIdentityPacketResults')) {
                $acknowledged=$directory->recordIdentityPacketResults($payload['packets'],$results);
                $allOk=$allOk && !empty($acknowledged['ok']) && empty($acknowledged['stale_extension_ids']) && empty($acknowledged['queued'])
                    && (int)($acknowledged['applied'] ?? 0)+count($acknowledged['skipped'] ?? [])===count($payload['packets']);
            }
            else { $allOk=false; }
            $skipped=$acknowledged['skipped'] ?? [];
            $result=['ok'=>$allOk,'sites'=>$sites,'phonebooks'=>$published,'acknowledgement'=>$acknowledged ?? null,'skipped'=>$skipped,'device_observed'=>null,
                'message'=>$allOk?'Eligible identities reconciled; '.count($skipped).' extension(s) skipped. Physical display is unverified.':'Reviewed identity delivery is incomplete. Failed or uncertain targets require a fresh review, not replay.'];
        } catch (\Throwable $error) { $result=['ok'=>false,'sites'=>$sites,'message'=>'Accepted state changed or the reviewed transaction did not complete. Refresh evidence before another review.']; }
        return $this->finishPlan($plan,$result);
    }

    private function phone(int $site,int $id): array
    {
        $phone = $id > 0 ? $this->ucm->getPhone($id,$site) : null;
        if (!$phone || (int)($phone['site_id'] ?? $site) !== $site) { throw new \InvalidArgumentException('Unknown owner phone'); }
        return $phone;
    }

    public function explainPhone(int $site,int $id): array
    {
        $phone = $this->phone($site,$id);
        $resolver = new TemplateEngine($this->ucm);
        $resolved = $resolver->resolvePhone($phone);
        $preview = (new Provisioner($this->ucm,null,$resolver))->previewPhone($id);
        if (empty($preview['ok'])) { return ['ok'=>false,'message'=>'Generated preview is unavailable; no evidence was inferred']; }
        $projection = self::projectResolved($resolved);
        $content = (string)($preview['content'] ?? $preview['xml'] ?? '');
        $occurrences = [];
        foreach ($projection['accounts'] as $account) {
            foreach (['label','display_name'] as $field) {
                $value = (string)($account[$field] ?? '');
                if ($value === '') { continue; }
                $lines = [];$tokens=[];
                foreach (explode("\n",$content) as $number=>$line) {
                    if (strpos($line,$value) !== false || strpos($line,htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8')) !== false) {
                        $lines[] = $number+1;
                        if (preg_match_all('/<([A-Za-z_][A-Za-z0-9_.:-]{0,63})(?:\s[^<>]*|)>/',$line,$matches)) { $tokens=array_merge($tokens,$matches[1]); }
                        if (preg_match('/\A\s*([A-Za-z_][A-Za-z0-9_.-]{0,63})\s*[:=]/',$line,$match)) { $tokens[]=$match[1]; }
                    }
                }
                $occurrences[] = ['account'=>$account['account'],'field'=>$field,'line_numbers'=>$lines,'candidate_native_tokens'=>array_values(array_unique($tokens))];
            }
        }
        return ['ok'=>true,'site_id'=>$site,'phone_id'=>$id,'desired'=>$projection,
            'generated'=>['filename'=>(string)$preview['filename'],'sha256'=>hash('sha256',$content),'occurrences'=>$occurrences,
                'note'=>'Literal output occurrences, not proof of an exact causal vendor tag. Configuration bytes are not returned.'],
            'evidence'=>$this->evidence($site,$id),'message'=>'Read-only owner resolution; device display remains unverified'];
    }

    /** Explicit UI projection: never return settings, account secrets or raw config. */
    public static function projectResolved(array $resolved): array
    {
        $settings = (array)($resolved['settings'] ?? []);
        $trace = [];
        foreach ((array)($settings['_setting_trace'] ?? []) as $key=>$history) {
            if (str_starts_with((string)$key,'_')) { continue; }
            foreach ((array)$history as $row) {
                $trace[$key][] = ['source'=>(string)($row['source'] ?? 'unknown'),
                    'id'=>(int)($row['id'] ?? 0),'site_id'=>(int)($row['site_id'] ?? 0),
                    'value'=>TemplateEngine::explainValue((string)$key,$row['value'] ?? null)];
            }
        }
        $accounts = [];
        $buttons = [];
        foreach ((array)($resolved['lines'] ?? []) as $line) {
            $row = ['button'=>(int)($line['button'] ?? 0),'feature'=>(string)($line['feature'] ?? ''),
                'label'=>(string)($line['label'] ?? '')];
            if ((int)($line['featureId'] ?? 0) === 9) {
                $identity=(array)($settings['_identity_accounts'][(string)($line['name'] ?? '')] ?? []);
                $accounts[] = ['account'=>(int)($line['accountIndex'] ?? $line['lineIndex'] ?? 0),
                    'extension'=>(string)($line['name'] ?? ''),'label'=>$row['label'],'display_name'=>(string)($line['displayName'] ?? ''),
                    'identity_state'=>(string)($identity['state'] ?? 'unknown'),
                    'binding_mode'=>(string)($identity['binding']['mode'] ?? 'unbound'),
                    'adoption'=>(string)($identity['binding']['adoption'] ?? 'unknown'),
                    'warnings'=>array_values(array_filter((array)($identity['warnings'] ?? []),'is_string'))];
            }
            $target = (array)($line['_identity_target'] ?? []);
            if ($target) { $row['identity_target'] = ['site_id'=>(int)($target['site_id'] ?? $target['site'] ?? 0),'extension'=>(string)($target['extension'] ?? ''),'derived'=>!empty($target['derived'])]; }
            $buttons[] = $row;
        }
        $model = (array)($resolved['model'] ?? []);
        return ['model'=>array_intersect_key($model,array_flip(['id','vendor','model','family'])),
            'phone_label'=>(string)($resolved['phone']['label'] ?? ''),'accounts'=>$accounts,'buttons'=>$buttons,
            'setting_trace'=>$trace,'template_trace'=>array_map(static fn($row)=>array_intersect_key((array)$row,array_flip(['group_id','group_name','template_id','template_name','position','status','reason'])),(array)($settings['_template_trace'] ?? [])),
            'note'=>'Unknown setting values are masked; only effective presentation is displayed.'];
    }

    public function evidence(int $site,int $id): array
    {
        $phone=$this->phone($site,$id);
        $stmt = $this->ucm->getDb()->prepare('SELECT desired_hash,applied_hash,generation_id,previous_generation_id,planned_at,applied_at FROM ucmp_phone_builds WHERE phone_id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $attempt=null;$stages=[];
        $history=$this->ucm->getDb()->prepare('SELECT transaction_id,state,manifest_json,details_json,started,finished FROM ucmp_apply_transactions WHERE site_id=? ORDER BY started DESC LIMIT 100');
        $history->execute([$site]);
        foreach ($history->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
            $manifest=json_decode((string)$candidate['manifest_json'],true) ?: [];
            if (!in_array($id,array_map('intval',array_column((array)($manifest['phones'] ?? []),'phone_id')),true)) { continue; }
            $details=json_decode((string)$candidate['details_json'],true) ?: [];
            foreach (['files_published','pbx_activated','device_action','device_observed'] as $key) {
                $value=$details['stages'][$key] ?? null;$stages[$key]=is_bool($value)?$value:null;
            }
            $attempt=array_intersect_key($candidate,array_flip(['transaction_id','state','started','finished']));break;
        }
        return ['ok'=>true,'site_id'=>$site,'phone_id'=>$id,'read_at'=>time(),'desired'=>$row ? ['hash'=>$row['desired_hash'],'planned_at'=>$row['planned_at']] : null,
            'files'=>$row && $row['applied_hash'] !== '' ? ['hash'=>$row['applied_hash'],'generation_id'=>$row['generation_id'],'applied_at'=>$row['applied_at']] : null,
            'latest_attempt'=>$attempt,'pbx'=>$stages['pbx_activated'] ?? null,'notification'=>$stages['device_action'] ?? null,'device'=>null,
            'inventory_observation'=>['last_seen_at'=>(int)($phone['last_seen'] ?? 0) ?: null,'status'=>in_array((string)($phone['status'] ?? ''),['online','offline','registered','unregistered','unknown'],true)?$phone['status']:null,'note'=>'Saved inventory observation, not proof of configuration application.'],
            'note'=>'Saved receipts from the latest 100 site transactions only. A published file or accepted notification is not proof of physical display. Unknown stages remain null.'];
    }

    public function previewImpact(int $site,array $request): array
    {
        $kind = (string)($request['kind'] ?? '');
        if (!in_array($kind,['settings','template','group'],true)) { throw new \InvalidArgumentException('Invalid draft kind'); }
        $values = $request['values'] ?? [];
        if (is_string($values)) { $values = json_decode($values,true,32,JSON_THROW_ON_ERROR); }
        if (!is_array($values)) { throw new \InvalidArgumentException('Draft must be an object'); }
        $draft = ['kind'=>$kind,'site_id'=>$site,'id'=>(int)($request['id'] ?? 0),'values'=>$values];
        if ($kind !== 'settings' && $draft['id'] < 1) { throw new \InvalidArgumentException('Save a new row before inspecting its impact'); }
        $phones = $this->ucm->getPhones(['site_id'=>$site]);
        $phones=array_values(array_filter($phones,static fn($phone)=>(int)($phone['site_id'] ?? $site)===$site));
        usort($phones,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        $inventoryHash=IdentityDeliveryService::hash(['phones'=>$phones,'draft'=>$draft],true);
        if (!empty($request['inventory_hash']) && !hash_equals($inventoryHash,(string)$request['inventory_hash'])) { throw new \DomainException('Owner inventory or draft changed between pages; start a fresh comparison'); }
        $total=count($phones);$afterId=max(0,(int)($request['after_phone_id'] ?? 0));$limit=max(1,min(50,(int)($request['page_size'] ?? 25)));
        $remaining=array_values(array_filter($phones,static fn($phone)=>(int)$phone['id']>$afterId));$phones=array_slice($remaining,0,$limit);
        $beforeResolver = new TemplateEngine($this->ucm);
        $afterResolver = new TemplateEngine($this->ucm,$draft);
        $beforeBuild = new Provisioner($this->ucm,null,$beforeResolver);
        $afterBuild = new Provisioner($this->ucm,null,$afterResolver);
        $rows = [];$shared=[];
        foreach ($phones as $phone) {
            $id = (int)$phone['id'];
            if (($phone['config_mode'] ?? 'managed')==='manual' || !empty($phone['exclude_bulk_rebuild'])) { $rows[] = ['phone_id'=>$id,'classification'=>'manual_or_excluded_preserved']; continue; }
            try {
                $beforePolicy = self::projectResolved($beforeResolver->resolvePhone($phone));
                $afterPolicy = self::projectResolved($afterResolver->resolvePhone($phone));
                $before = $beforeBuild->rebuild((string)$id,false,$site);
                $after = $afterBuild->rebuild((string)$id,false,$site);
                if (empty($before['ok']) || empty($after['ok'])) { $rows[] = ['phone_id'=>$id,'classification'=>'unresolved']; continue; }
                $beforeFiles = array_column((array)$before['files'],'sha256','filename');
                $afterFiles = array_column((array)$after['files'],'sha256','filename');
                $changed = [];
                foreach (array_unique(array_merge(array_keys($beforeFiles),array_keys($afterFiles))) as $file) {
                    if (($beforeFiles[$file] ?? null) !== ($afterFiles[$file] ?? null)) { $changed[] = $file; }
                }
                $beforeGlue=$before['manifest']['asterisk_glue_sha256'] ?? null;$afterGlue=$after['manifest']['asterisk_glue_sha256'] ?? null;
                $glueChanged=$beforeGlue!==null && $afterGlue!==null ? $beforeGlue!==$afterGlue : null;
                $endpoint=static fn($preview)=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['id','driver','transport','port','accounts'])),(array)($preview['phones'] ?? []));
                $endpointChanged=isset($before['phones'],$after['phones']) ? $endpoint($before)!==$endpoint($after) : null;
                $incompatible=false;
                if ($kind==='template') { $matching=array_values(array_filter($afterPolicy['template_trace'],static fn($row)=>(int)($row['template_id'] ?? 0)===$draft['id']));$incompatible=(bool)$matching && !array_filter($matching,static fn($row)=>($row['status'] ?? '')==='applied'); }
                $affected=(bool)$changed || $beforePolicy!==$afterPolicy || $glueChanged===true || $endpointChanged===true;
                $classification=$affected?($changed?'generated_bytes_changed':'policy_only'):($incompatible?'incompatible':'unchanged');
                $companions=[];
                foreach (array_merge((array)$before['files'],(array)$after['files']) as $file) {
                    if (($file['role'] ?? '')==='phone_config') { continue; }
                    $name=(string)($file['filename'] ?? '');BuildPlanner::assertFilename($name);$companions[]=$name;
                    $shared[$name]['filename']=$name;$shared[$name]['roles'][(string)($file['role'] ?? 'config')]=true;$shared[$name]['referenced_by_phone_ids'][$id]=true;
                    $shared[$name]['changed']=!empty($shared[$name]['changed']) || in_array($name,$changed,true);
                }
                $rows[] = ['phone_id'=>$id,'classification'=>$classification,'changed_files'=>$changed,'companion_references'=>array_values(array_unique($companions)),
                    'asterisk_glue_changed'=>$glueChanged,'endpoint_policy_changed'=>$endpointChanged,
                    'required_stages'=>['source_save'=>$affected,'file_publication'=>(bool)$changed,'pbx_activation'=>$glueChanged===true||$endpointChanged===true?true:($glueChanged===null||$endpointChanged===null?null:false),
                        'device_config_fetch'=>$changed?true:null,'device_notification'=>null,'device_restart'=>null,'device_observation'=>$affected?true:null],
                    'saved_plan_hash'=>$before['plan_hash'],'draft_plan_hash'=>$after['plan_hash']];
            } catch (\Throwable $error) { $rows[] = ['phone_id'=>$id,'classification'=>'unresolved']; }
        }
        $counts=['affected'=>0,'unchanged'=>0,'manual'=>0,'incompatible'=>0,'unresolved'=>0];
        foreach ($rows as $row) { $category=['generated_bytes_changed'=>'affected','policy_only'=>'affected','manual_or_excluded_preserved'=>'manual'][$row['classification']] ?? $row['classification'];$counts[$category]++; }
        foreach ($shared as &$file) { $file['roles']=array_keys($file['roles']);$file['referenced_by_phone_ids']=array_keys($file['referenced_by_phone_ids']); }unset($file);
        return ['ok'=>true,'site_id'=>$site,'kind'=>$kind,'scope'=>'owner_site_only','rows'=>$rows,'counts'=>$counts,'counts_scope'=>'this_page','shared_files'=>array_values($shared),
            'shared_impact_counts'=>['referenced_filenames'=>count($shared),'changed_filenames'=>count(array_filter($shared,static fn($file)=>$file['changed'])),
                'asterisk_changed_phone_previews'=>count(array_filter($rows,static fn($row)=>($row['asterisk_glue_changed'] ?? null)===true))],
            'pagination'=>['owner_phone_count'=>$total,'page_phone_count'=>count($phones),'next_after_phone_id'=>count($remaining)>count($phones)?(int)end($phones)['id']:null,'inventory_hash'=>$inventoryHash],
            'cross_site_consumers'=>['status'=>'not_evaluated','note'=>'Owner draft is not applied to other sites. Remote synchronized copies and consumers require separate owner reviews.'],
            'saved'=>false,'deployed'=>false,'message'=>'Read-only unsaved owner draft; saving never deploys. Counts and shared-file references cover this page only. Later pages reread policy, not a point-in-time fleet snapshot. Required stages describe draft deltas, not current deployment drift; null means unproven. Device notification/restart requirements need device-specific verification.'];
    }

    public function previewReplacement(int $site,array $request): array
    {
        $target = $this->phone($site,(int)($request['phone_id'] ?? 0));
        $extension = trim((string)($request['extension'] ?? ''));
        if (!array_key_exists('extension',$request) || ($extension!=='' && !preg_match('/\A[0-9*#+]{1,20}\z/D',$extension))) { throw new \InvalidArgumentException('Choose one extension or explicitly leave the phone unassigned'); }
        if ($extension==='' && empty($target['extension'])) { throw new \DomainException('The phone is already unassigned'); }
        if (empty($target['mac']) || empty($target['model_id'])) { throw new \DomainException('Replacement requires a known physical phone and model'); }
        $matches=$extension===''?[]:array_values(array_filter($this->ucm->getPhones(['site_id'=>$site]),static fn($phone)=>(string)($phone['extension'] ?? '')===$extension && (int)$phone['id']!==(int)$target['id']));
        if (count($matches)>1) { throw new \DomainException('Extension has multiple physical owners; resolve that conflict first'); }
        $other=$matches ? $this->phone($site,(int)$matches[0]['id']) : null;
        if (isset($request['other_phone_id']) && (int)$request['other_phone_id']!==(int)($other['id'] ?? 0) && (int)$request['other_phone_id']!==(int)$target['id']) { throw new \DomainException('Selected collision changed'); }
        $action=(string)($request['collision_action'] ?? 'move');$outcomes=PhoneAssignment::plan($target,$other,$extension,$action);
        $resolver=new TemplateEngine($this->ucm);$logical=new LogicalExtensionService($this->ucm->getDb());$revisions=[];
        foreach (array_unique(array_filter([$extension,(string)($target['extension'] ?? '')])) as $number) { $identity=$logical->findBySiteExtension($site,$number);$revisions[$number]=(int)($identity['revision'] ?? 0); }
        $resolved=$extension!==''?$resolver->resolvePhone($this->replacementDraft($site,$target,$extension)):null;$otherPreview=null;
        if ($other && $outcomes['other_extension']!==null) { $otherPreview=self::projectResolved($resolver->resolvePhone($this->replacementDraft($site,$other,$outcomes['other_extension']))); }
        $before=[self::replacementMetadata($target)];$after=[self::replacementMetadata(array_replace($target,['extension'=>$outcomes['target_extension']]))];
        if ($other) {$before[]=self::replacementMetadata($other);$after[]=self::replacementMetadata(array_replace($other,['extension'=>$outcomes['other_extension']]));}
        $recovery=self::replacementRecovery($before);
        $payload=['phone_id'=>(int)$target['id'],'other_phone_id'=>(int)($other['id'] ?? 0),'extension'=>$extension,'collision_action'=>$action,
            'assignment_before'=>$before,'assignment_expected_after'=>$after,
            'expected_target_hash'=>IdentityDeliveryService::hash($target),'expected_other_hash'=>$other?IdentityDeliveryService::hash($other):'','expected_identity_revisions'=>$revisions];
        return $this->rememberPlan($site,'replacement',$payload,['outcomes'=>$outcomes,'target'=>$resolved?self::projectResolved($resolved):['phone_id'=>(int)$target['id'],'unassigned'=>true],'other'=>$otherPreview,'saved'=>false,'deployed'=>false,
            'assignment_before'=>$before,'assignment_expected_after'=>$after]+$recovery+[
            'person_policy'=>'Logical person binding and final identity overrides remain with the extension.',
            'device_policy'=>'MAC, model, firmware and device-local overrides remain on their physical phone rows.',
            'message'=>'Model/account policy resolved. Approval saves the guarded assignment only; generated files and phones require a separate reviewed rebuild.']);
    }

    public function executeReplacement(int $site,array $request): array
    {
        $plan=$this->readPlan($request,'replacement',$site);$payload=$plan['payload'];$this->startPlan($plan);
        $recovery=self::replacementRecovery((array)($payload['assignment_before']??[]));
        try {
            $target=$this->phone($site,$payload['phone_id']);
            $save=array_intersect_key($target,array_flip(['id','site_id','group_id','mac','vendor','model_id','family','ip','driver','transport','sip_port','label','overrides','lines_json','exclude_bulk_rebuild']));
            $save['site_id']=$site;$save['extension']=$payload['extension'];$save['collision_action']=$payload['collision_action'];
            $save['expected_target_hash']=$payload['expected_target_hash'];$save['expected_other_id']=$payload['other_phone_id'];$save['expected_other_hash']=$payload['expected_other_hash'];
            $save['expected_identity_revisions']=$payload['expected_identity_revisions'];
            $saved=$this->ucm->savePhoneAssignmentLocal($save);
            $observed=[];foreach ((array)($payload['assignment_before']??[]) as $before) {$observed[]=self::replacementMetadata($this->phone($site,(int)$before['phone_id']));}
            $verified=$observed===(array)($payload['assignment_expected_after']??[]);
            $result=['ok'=>!empty($saved['ok'])&&$verified,'assignment_before'=>$payload['assignment_before']??[],'assignment_after'=>$observed,
                'affected_phone_ids'=>array_values(array_diff((array)($saved['affected_ids'] ?? []),(array)($saved['unassigned_ids'] ?? []))),
                'deployed'=>false,'message'=>'Reviewed assignment saved. Device-local settings and secrets were not replaced; review a rebuild separately.'];
            if (!$verified) {$result['message']='Assignment save returned, but current mappings differ from the reviewed result. Inspect retained before/current mappings; do not restore automatically.';}
        } catch (\Throwable $error) { $result=['ok'=>false,'assignment_before'=>$payload['assignment_before']??[],'assignment_after'=>null,'deployed'=>false,'message'=>'Replacement did not complete or changed after review. No automatic retry or rebuild was attempted.']; }
        return $this->finishPlan($plan,$result+$recovery);
    }

    private static function replacementMetadata(array $phone): array
    {
        return ['phone_id'=>(int)$phone['id'],'model_id'=>(int)($phone['model_id']??0),'extension'=>(string)($phone['extension']??'')];
    }

    private static function replacementRecovery(array $before): array
    {
        return ['reverse_review'=>['steps'=>array_map(static fn(array $row):array=>['phone_id'=>$row['phone_id'],'extension'=>$row['extension'],'collision_action'=>'swap'],$before),
            'instruction'=>'Inspect current assignments first. For each recorded before mapping that still needs restoration, open that existing phone, select its recorded extension (blank means leave unassigned), and use Review replacement / reassignment. Review any current collision and person revision before approving. Skip mappings already restored by a preceding swap. These are fresh guarded reviews, never automatic rollback or exact configuration restore.'],
            'post_change_checklist'=>['Compare the retained before and observed after phone/extension mappings; inspect any unassigned displaced phone.',
                'Confirm logical person ownership and model/account compatibility. Device-local policy and secrets were not copied.',
                'Review a rebuild of still-assigned affected phones separately; unassignment does not itself remove an old phone configuration.',
                'Verify published files, PBX activation and a representative handset independently; registration alone does not prove the displayed identity.',
                'If the response or final receipt is uncertain, read retained change status before any fresh reversal review. No automatic restore, reset, secret rotation or reboot is requested.']];
    }

    private function replacementDraft(int $site,array $phone,string $extension): array
    {
        $logical=(new LogicalExtensionService($this->ucm->getDb()))->findBySiteExtension($site,$extension);
        return array_replace($phone,['extension'=>$extension,'extension_id'=>$logical['id'] ?? null,'group_id'=>$logical['group_id'] ?? null]);
    }
}
