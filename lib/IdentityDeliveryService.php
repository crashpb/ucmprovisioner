<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Local projection of an accepted owner relationship, never a person authority. */
final class IdentityDeliveryService
{
    public const CACHE_KEY = 'identity_delivery_cache';
    public function __construct(private object $ucm,private ?ExtensionBinder $binder=null,private string $receiverSiteKey='')
    { $this->binder ??= new ExtensionBinder($ucm); }

    /** Review every destination before writing; omit an individually rejected identity everywhere. */
    public static function planSites(object $ucm,array $packets): array
    {
        $targets=[];
        foreach ($packets as $packet) {
            $key=$packet['identity_site_key'].':'.$packet['extension'];
            if (isset($targets[$key])) { throw new \InvalidArgumentException('Duplicate identity target'); }
            $targets[$key]=$packet;
        }
        $destinations=[];$skipped=[];
        foreach ($ucm->getSites() as $site) {
            if (empty($site['enabled'])) { continue; }
            $id=(int)$site['id'];
            $input=count($packets)>128?$ucm->prepareIdentityDeliveryInput($id,$packets):['identities'=>$packets];
            $plan=$ucm->operatorWorkflowAtSite($id,['op'=>'delivery_plan']+$input);
            if (empty($plan['ok']) || (int)($plan['identity_delivery_schema'] ?? 0)!==1 || empty($plan['plan_hash'])) {
                throw new \RuntimeException('Site #'.$id.': '.($plan['message'] ?? 'Identity planning is unavailable or incomplete'));
            }
            foreach ((array)($plan['skipped'] ?? []) as $skip) {
                $key=(string)($skip['target'] ?? '');
                if (!isset($targets[$key]) || trim((string)($skip['reason'] ?? ''))==='') { throw new \RuntimeException('Invalid identity exclusion receipt'); }
                $skipped[$key][]='Site #'.$id.': '.(string)$skip['reason'];
            }
            $destinations[]=['site_id'=>$id,'site_key'=>(string)$site['site_key'],'input'=>$input,
                'plan_hash'=>$plan['plan_hash'],'phone_targets'=>$plan['phone_targets'] ?? [],'files'=>$plan['files'] ?? [],'excluded'=>$plan['excluded'] ?? []];
        }
        if (!$destinations) { throw new \DomainException('No enabled destinations'); }
        $eligible=array_values(array_diff_key($targets,$skipped));$results=[];
        foreach ($skipped as $key=>$reasons) { $results[$key]=['ok'=>false,'skipped'=>true,'message'=>implode('; ',array_unique($reasons))]; }
        if ($skipped && $eligible) {
            // Filtering changes BLF dependencies and the reviewed file set, so use new exact plans.
            foreach ($destinations as &$destination) {
                $id=$destination['site_id'];$input=count($eligible)>128?$ucm->prepareIdentityDeliveryInput($id,$eligible):['identities'=>$eligible];
                $plan=$ucm->operatorWorkflowAtSite($id,['op'=>'delivery_plan']+$input);
                if (empty($plan['ok']) || (int)($plan['identity_delivery_schema'] ?? 0)!==1 || empty($plan['plan_hash']) || !empty($plan['skipped'])) {
                    throw new \DomainException('Site #'.$id.': identity eligibility changed during planning; retry with a fresh plan');
                }
                $destination=array_replace($destination,['input'=>$input,'plan_hash'=>$plan['plan_hash'],
                    'phone_targets'=>$plan['phone_targets'] ?? [],'files'=>$plan['files'] ?? [],'excluded'=>$plan['excluded'] ?? []]);
            }unset($destination);
        }
        if (!$eligible) {
            foreach ($destinations as &$destination) { $destination=array_replace($destination,['input'=>['identities'=>[]],'plan_hash'=>'','phone_targets'=>[],'files'=>[]]); }unset($destination);
        }
        return ['packets'=>$eligible,'destinations'=>$destinations,'skipped'=>$results];
    }

    public static function cache(object $ucm): array
    {
        $raw = (string)$ucm->getSetting(self::CACHE_KEY,$ucm->localSiteId(),'');
        if ($raw === '') { return ['schema'=>1,'entries'=>[]]; }
        if (strlen($raw)>8388608) { throw new \RuntimeException('Identity projection exceeds its bound'); }
        $cache = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($cache) || ($cache['schema'] ?? 0)!==1 || !is_array($cache['entries'] ?? null) || count($cache['entries'])>10000) { throw new \RuntimeException('Invalid identity projection'); }
        foreach ($cache['entries'] as $key=>$entry) {
            $normalized=self::normalize((array)$entry);
            if ($key !== $normalized['identity_site_key'].':'.$normalized['extension']) { throw new \RuntimeException('Identity projection key mismatch'); }
            $cache['entries'][$key]=$normalized+['received_at'=>(int)($entry['received_at'] ?? 0)];
        }
        $aliases=$cache['receiver_aliases'] ?? [];
        if (!is_array($aliases) || count($aliases)>128) { throw new \RuntimeException('Invalid receiver identity mapping'); }
        foreach ($aliases as $origin=>$alias) {
            if (!is_string($alias) || !preg_match('/^[A-Za-z0-9_*#+.-]{1,128}$/D',(string)$origin) || !preg_match('/^[A-Za-z0-9_*#+.-]{1,128}$/D',$alias) || (string)$origin===$alias) {
                throw new \RuntimeException('Invalid receiver identity mapping');
            }
        }
        return $cache;
    }

    public static function resolveCached(object $ucm,int $siteId,string $extension,?callable $formatter=null,array $preview=[]): ?array
    {
        $site=(array)$ucm->getSite($siteId);
        $local=(array)$ucm->getSite($ucm->localSiteId());
        $cache=$preview ?: self::cache($ucm);
        $key=(string)($site['site_key'] ?? '').':'.$extension;
        $candidates=isset($cache['entries'][$key]) ? [$key=>$cache['entries'][$key]] : [];
        if ($siteId===$ucm->localSiteId()) {
            foreach ($cache['receiver_aliases'] ?? [] as $origin=>$alias) {
                $aliasKey=$alias.':'.$extension;
                $candidate=$cache['entries'][$aliasKey] ?? null;
                if ($candidate && $candidate['origin_site_key']===(string)$origin) { $candidates[$aliasKey]=$candidate; }
            }
        }
        $candidates=array_filter($candidates,static fn(array $item):bool=>empty($item['released']));
        if (count($candidates)>1) { throw new \DomainException('Conflicting received identity authorities'); }
        $entry=$candidates ? reset($candidates) : null;
        if (!$entry) { return null; }
        $logical=(new LogicalExtensionService($ucm->getDb()))->findBySiteExtension($siteId,$extension);
        if ($logical && $entry['origin_site_key'] !== ($local['site_key'] ?? '')) {
            $binding=LogicalExtensionService::identityBinding($logical);
            if ($binding['mode']!=='ad_primary' || $binding['source_id']>0) { return null; }
        }
        if ($logical && $entry['origin_site_key']===($local['site_key'] ?? '')) {
            $binding=LogicalExtensionService::identityBinding($logical);
            if ((int)$logical['revision']>$entry['extension_revision'] || $binding['mode']!==$entry['binding_mode']
                || $binding['source_id']!==$entry['source_id'] || $binding['object_key']!==$entry['object_key']) { return null; }
        }
        $resolved=LogicalExtensionService::resolveIdentity($entry['canonical'],$entry['final_overrides'],$formatter);
        return $resolved+['resolved'=>true,'binding'=>['mode'=>$entry['binding_mode'],'source_id'=>$entry['source_id'],'object_key'=>$entry['object_key'],'adoption'=>'managed'],
            'full_name'=>$resolved['canonical']['display_name'],'short_name'=>$resolved['canonical']['label'],'warnings'=>[],
            'delivery_policy'=>['sync_name'=>$entry['sync_name'],'sync_label'=>$entry['sync_label']],
            'accepted_at'=>$entry['accepted_at'],'delivery_origin'=>$entry['origin_site_key']];
    }

    public function planLocal(array $identities): array
    {
        if (count($identities)>5000) { throw new \InvalidArgumentException('Identity batch exceeds 5000 targets'); }
        $siteId=(int)$this->ucm->localSiteId();$site=(array)$this->ucm->getSite($siteId);$siteKey=(string)($site['site_key'] ?? '');
        if ($siteKey==='') { throw new \RuntimeException('Owner site identity unavailable'); }
        $before=self::cache($this->ucm);$after=$before;$items=[];$core=[];$unresolved=[];$excluded=[];$skipped=[];$seen=[];
        if ($this->receiverSiteKey!=='' && !preg_match('/^[A-Za-z0-9_*#+.-]{1,128}$/D',$this->receiverSiteKey)) { throw new \InvalidArgumentException('Invalid receiver site key'); }
        $localKeys=array_unique(array_filter([$siteKey,$this->receiverSiteKey]));
        foreach ($identities as $raw) {
            $item=self::normalize((array)$raw);$key=$item['identity_site_key'].':'.$item['extension'];
            if ($this->receiverSiteKey!=='' && $this->receiverSiteKey!==$siteKey) {
                $origin=$item['origin_site_key'];
                if ($origin===$siteKey || $origin===$this->receiverSiteKey) { throw new \DomainException('Receiver mapping cannot claim local identity authority'); }
                $priorAlias=$after['receiver_aliases'][$origin] ?? $this->receiverSiteKey;
                if ($priorAlias!==$this->receiverSiteKey) { throw new \DomainException('Receiver identity mapping changed; review the site relationship'); }
                $after['receiver_aliases'][$origin]=$this->receiverSiteKey;
                if (count($after['receiver_aliases'])>128) { throw new \LengthException('Receiver identity mapping exceeds its bound'); }
            }
            if (isset($seen[$key])) { throw new \InvalidArgumentException('Duplicate identity target'); }
            $seen[$key]=true;
            $prior=$before['entries'][$key] ?? null;
            if ($prior && ($prior['origin_site_key']!==$item['origin_site_key'] || $prior['extension_revision']>$item['extension_revision']
                || ($prior['extension_revision']===$item['extension_revision'] && $prior['delivery_hash']!==$item['delivery_hash']))) {
                $skipped[]=['target'=>$key,'reason'=>'Conflicting authority or relationship revision; prior projection retained'];continue;
            }
            if (in_array($item['identity_site_key'],$localKeys,true)) {
                $logical=(new LogicalExtensionService($this->ucm->getDb()))->findBySiteExtension($siteId,$item['extension']);
                $binding=$logical ? LogicalExtensionService::identityBinding($logical) : null;
                if ($binding && $item['origin_site_key']!==$siteKey && ($binding['mode']!=='ad_primary' || $binding['source_id']>0)) {
                    $excluded[]=$skipped[]=['target'=>$key,'reason'=>'Local authoritative identity preserved'];continue;
                }
                if ($binding && $item['origin_site_key']===$siteKey && empty($item['released'])
                    && ((int)$logical['revision']>$item['extension_revision'] || $binding['mode']!==$item['binding_mode']
                        || $binding['source_id']!==$item['source_id'] || $binding['object_key']!==$item['object_key'])) {
                    $skipped[]=['target'=>$key,'reason'=>'Authoritative local relationship changed after source review'];continue;
                }
                if (empty($item['released'])) {
                    if (!$this->binder->getDevice($item['extension'])) { $skipped[]=['target'=>$key,'reason'=>'FreePBX account ' . $item['extension'] . ' does not exist on the receiving site'];continue; }
                    $core[$key]=$item;
                }
            }
            $items[$key]=$item;$after['entries'][$key]=$item;
        }
        ksort($after['entries']);
        if (count($after['entries'])>10000 || strlen(json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>8388608) { throw new \RuntimeException('Desired identity projection exceeds its safe storage bound'); }
        $resolver=new TemplateEngine($this->ucm,[],$after);$phones=[];
        $inventory=$items ? $this->ucm->getPhones(['site_id'=>$siteId]) : [];
        if (count($inventory)>10000) { throw new \RuntimeException('Fleet exceeds dependency inspection bound'); }
        foreach ($inventory as $phone) {
            if (empty($phone['extension']) || empty($phone['mac']) || empty($phone['model_id'])) { continue; }
            try { $resolved=$resolver->resolvePhone($phone,false); }
            catch (\Throwable $error) { $unresolved[]=['phone_id'=>(int)$phone['id'],'reason'=>'Dependency inventory could not be resolved'];continue; }
            $reasons=[];
            foreach ($resolved['lines'] as $line) {
                foreach ($localKeys as $localKey) {
                    if ((int)($line['featureId'] ?? 0)===9 && isset($items[$localKey.':'.(string)($line['name'] ?? '')])) { $reasons[]='account';break; }
                }
                $target=$line['_identity_target'] ?? null;
                if ($target && !empty($target['derived'])) {
                    $owner=$target['site'] ?? $siteId;$ownerSite=null;
                    foreach ($this->ucm->getSites() as $candidate) { if ((string)$candidate['id']===(string)$owner || (string)$candidate['site_key']===(string)$owner) { $ownerSite=$candidate;break; } }
                    if (!$ownerSite) { $unresolved[]=['phone_id'=>(int)$phone['id'],'reason'=>'Derived target owner is unknown'];continue; }
                    if (isset($items[$ownerSite['site_key'].':'.(string)$target['extension']])) { $reasons[]='derived_blf'; }
                }
            }
            if (!$reasons) { continue; }
            if (($phone['config_mode'] ?? 'managed')==='manual' || !empty($phone['exclude_bulk_rebuild'])) { $excluded[]=['phone_id'=>(int)$phone['id'],'reason'=>'Manual configuration or bulk exclusion preserved'];continue; }
            $phones[]=['phone_id'=>(int)$phone['id'],'reasons'=>array_values(array_unique($reasons))];
        }
        usort($phones,static fn($a,$b)=>$a['phone_id']<=>$b['phone_id']);$build=null;
        if ($phones && !$unresolved) {
            $build=(new Provisioner($this->ucm,$this->binder,$resolver))->rebuild(implode(',',array_column($phones,'phone_id')),false,$siteId,false,null,false,true);
            if (empty($build['ok'])) { $unresolved[]=['reason'=>'Identity file generation did not produce a complete plan']; }
        }
        $stable=['site_id'=>$siteId,'receiver_site_key'=>$this->receiverSiteKey,'cache_before_hash'=>self::hash($before),'items'=>array_values($items),'phone_targets'=>$phones,
            'force_reload'=>(bool)array_filter($identities,static fn($item)=>isset($core[$item['identity_site_key'].':'.$item['extension']]) && !empty($item['force']) && !empty($item['sync_name'])),
            'build_plan_hash'=>(string)($build['plan_hash'] ?? ''),'excluded'=>$excluded,'unresolved'=>$unresolved,'skipped'=>$skipped];
        return $stable+['ok'=>!$unresolved,'plan_hash'=>self::hash($stable),'cache_after'=>$after,'core_targets'=>array_values($core),
            'files'=>$build['files'] ?? [],'identity_delivery_schema'=>1,
            'message'=>$unresolved ? 'Identity planning blocked: ' . implode('; ',array_unique(array_column($unresolved,'reason'))) : 'Identity delivery plan ready; '.count($skipped).' identity target(s) skipped'];
    }

    public function applyLocal(array $identities,?array $fixedPlan=null): array
    {
        $site=(int)$this->ucm->localSiteId();
        return (new TftpStore($this->ucm))->withSiteLock($site,function() use ($identities,$fixedPlan,$site): array {
            $plan=$this->planLocal($identities);
            if (!$plan['ok']) { return $plan+['results'=>[]]; }
            if ($fixedPlan!==null && !hash_equals((string)($fixedPlan['plan_hash'] ?? ''),$plan['plan_hash'])) { throw new \DomainException('Identity plan changed; review again'); }
            $cache=$plan['cache_after'];foreach ($plan['items'] as $item) { $cache['entries'][$item['identity_site_key'].':'.$item['extension']]['received_at']=time(); }
            if ($plan['items']) {
                $this->ucm->setSetting(self::CACHE_KEY,json_encode($cache,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$site);
                if (self::hash(self::cache($this->ucm))!==self::hash($cache)) { throw new \RuntimeException('Identity projection readback failed'); }
            }
            $results=[];$changed=false;
            foreach ($plan['core_targets'] as $item) {
                $result=$item['sync_name'] ? $this->binder->updateDisplayName($item['extension'],$item['canonical']['display_name'],'managed') : ['ok'=>true,'changed'=>false,'callerid_checked'=>true];
                $results[$item['extension']]=$result;$changed=$changed||!empty($result['changed']);
            }
            $coreOk=!array_filter($results,static fn($row)=>empty($row['ok']));$reloadOk=true;
            if ($coreOk && ($changed || $plan['force_reload'])) {
                try { $reloadOk=!empty(\FreePBX::Framework()->doReload()['status']); } catch (\Throwable $error) { $reloadOk=false; }
            }
            $build=['ok'=>true,'stages'=>['files_published'=>null]];
            if ($coreOk && $reloadOk && $plan['phone_targets']) {
                $build=(new Provisioner($this->ucm,$this->binder,new TemplateEngine($this->ucm,[],$cache)))->rebuild(implode(',',array_column($plan['phone_targets'],'phone_id')),true,$site,false,$plan['build_plan_hash'],false,true);
            }
            $ok=$coreOk && $reloadOk && !empty($build['ok']);
            foreach ($plan['items'] as $item) {
                if (!isset($results[$item['extension']]) && in_array($item['identity_site_key'],[(string)((array)$this->ucm->getSite($site))['site_key'],$this->receiverSiteKey],true)) {
                    $results[$item['extension']]=['ok'=>$ok,'changed'=>false,'callerid_checked'=>true,'released'=>!empty($item['released'])];
                }
            }
            $failure = !$coreOk ? 'FreePBX identity update failed' : (!$reloadOk ? 'FreePBX configuration activation failed' : (string)($build['message'] ?? 'Phone-file publication failed'));
            foreach ($results as &$row) {
                if (!empty($row['ok']) && !$ok) { $row['message']=$failure; }
                $row['ok']=!empty($row['ok']) && $ok;$row['delivery_recorded_at']=time();
            }unset($row);
            foreach ($plan['skipped'] as $skip) {
                foreach ([(string)((array)$this->ucm->getSite($site))['site_key'],$this->receiverSiteKey] as $localKey) {
                    if ($localKey!=='' && str_starts_with($skip['target'],$localKey.':')) {
                        $results[substr($skip['target'],strlen($localKey)+1)]=['ok'=>false,'skipped'=>true,'message'=>$skip['reason']];break;
                    }
                }
            }
            return ['ok'=>$ok,'identity_delivery_schema'=>1,'results'=>$results,'plan_hash'=>$plan['plan_hash'],'transaction_id'=>$build['transaction_id'] ?? null,
                'phone_targets'=>$plan['phone_targets'],'excluded'=>$plan['excluded'],'unresolved'=>$plan['unresolved'],'skipped'=>$plan['skipped'],
                'stages'=>['desired'=>true,'files_published'=>$build['stages']['files_published'] ?? null,'pbx_activated'=>$coreOk && $reloadOk,'device_observed'=>null],
                'message'=>$ok?'Identity delivery reconciled; physical display remains unverified':'Identity delivery incomplete: ' . $failure];
        });
    }

    public static function hash(array $value,bool $includeOperational=false): string
    {
        $stable=static function($item) use (&$stable,$includeOperational) { if (!is_array($item)) { return $item; }if (!$includeOperational) { unset($item['received_at'],$item['force']); }if (!array_is_list($item)) { ksort($item); }foreach ($item as &$child) { $child=$stable($child); }return $item; };
        return hash('sha256',json_encode($stable($value),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    public static function normalize(array $raw): array
    {
        foreach (['identity_site_key','origin_site_key','extension'] as $key) {
            if (!preg_match('/\A[A-Za-z0-9_*#+.-]{1,128}\z/D',(string)($raw[$key] ?? ''))) { throw new \InvalidArgumentException('Identity delivery requires explicit owner and origin'); }
        }
        if (($raw['adoption'] ?? '')!=='managed' || (int)($raw['extension_revision'] ?? 0)<1
            || !in_array($raw['binding_mode'] ?? '',['ad_primary','linked_additional','manual_shared'],true)) { throw new \InvalidArgumentException('Identity relationship is not adopted or versioned'); }
        $canonicalInput=(array)($raw['canonical'] ?? []);$canonicalInput['extension']=$raw['extension'];
        if (!empty($raw['released'])) { $canonicalInput=['extension'=>$raw['extension'],'display_name'=>$raw['extension']]; }
        $canonical=LogicalExtensionService::normalizeAdIdentity($canonicalInput);
        if (empty($raw['released']) && $canonical['display_name']==='') { throw new \InvalidArgumentException('Full caller name required'); }
        $final=[];
        foreach ((array)($raw['final_overrides'] ?? []) as $override) {
            if (($override['mode'] ?? '')!=='final' || !in_array($override['field_name'] ?? '',LogicalExtensionService::FINAL_FIELDS,true)) { throw new \InvalidArgumentException('Invalid final field'); }
            $value=json_decode((string)($override['value_json'] ?? ''),true,8,JSON_THROW_ON_ERROR);
            if (!is_string($value) || strlen($value)>512 || preg_match('/[\x00-\x1f]/',$value)) { throw new \InvalidArgumentException('Invalid final value'); }
            $final[]=['field_name'=>$override['field_name'],'mode'=>'final','value_json'=>json_encode($value,JSON_THROW_ON_ERROR)];
        }
        $item=['identity_site_key'=>$raw['identity_site_key'],'origin_site_key'=>$raw['origin_site_key'],'extension'=>$raw['extension'],
            'source_id'=>(int)($raw['source_id'] ?? 0),'object_key'=>substr((string)($raw['object_key'] ?? ''),0,128),
            'source_revision'=>substr((string)($raw['source_revision'] ?? ''),0,128),'extension_revision'=>(int)$raw['extension_revision'],
            'binding_mode'=>$raw['binding_mode'],'adoption'=>'managed','canonical'=>$canonical,'final_overrides'=>$final,
            'accepted_at'=>(int)($raw['accepted_at'] ?? 0),'sync_name'=>!empty($raw['sync_name']),'sync_label'=>!empty($raw['sync_label']),
            'released'=>!empty($raw['released'])];
        $presentation=$item;
        unset($presentation['accepted_at'],$presentation['source_revision'],$presentation['sync_name'],$presentation['sync_label']);
        return $item+['delivery_hash'=>self::hash($presentation)];
    }
}
