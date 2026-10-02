<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__.'/AssetReferenceIndex.php';

/** Revision-bound plan; content disposal stays at the locked storage boundary. */
final class AssetRetirementService
{
    public function __construct(private \PDO $db) {}

    public function preview(int $siteId,int $assetId,array $storage,?int $replacementId=null): array
    {
        $registry=new ManagedAssetRegistry($this->db);
        $asset=$registry->getById($siteId,$assetId);
        if (!$asset) { throw new \InvalidArgumentException('Asset was not found on the captured site'); }
        $snapshot=(new AssetReferenceIndex($this->db))->snapshot($siteId);
        $references=[];$mutable=[];
        foreach ($snapshot['sources'] as $source) {
            if (!AssetReferenceIndex::matches($source,$asset)) { continue; }
            $references[]=AssetReferenceIndex::summary($source);
            if ($source['mutable']) { $mutable[]=$source; }
        }
        foreach ($registry->all($siteId,['state'=>'current']) as $peer) {
            if ((int)$peer['asset_id']===$assetId) { continue; }
            foreach (array_merge((array)($peer['companions']??[]),(array)($peer['manifest']??[])) as $dependency) {
                if (($dependency['asset_key']??'')===$asset['asset_key'] ||
                    (($dependency['filename']??'')===$asset['filename']&&($dependency['content_sha256']??$dependency['sha256']??'')===$asset['content_sha256'])) {
                    $references[]=['type'=>'asset_dependency','id'=>$peer['asset_id'],'label'=>$peer['filename'],'mutable'=>false];break;
                }
            }
        }
        array_push($references,...(array)($storage['references'][$asset['asset_key']]??[]));
        $blockers=[];
        if (!$snapshot['complete']) { $blockers[]='Database reference graph is incomplete'; }
        if (empty($storage['complete'])) { $blockers[]='Live and rollback reference graph is incomplete'; }
        if (empty($storage['scan_complete'])) { $blockers[]='Complete asset verification is required with no active scan'; }
        if (($asset['state']??'')!=='current') { $blockers[]='Only a current exact file can be retired'; }
        if (empty($asset['relative_path'])) { $blockers[]='Asset has no verified TFTP path'; }
        $replacement=null;
        if ($replacementId!==null) {
            $replacement=(new AssetLifecycleService($this->db))->get($siteId,$replacementId);
            if ($replacementId===$assetId || empty($replacement['selectable']) || $replacement['kind']!==$asset['kind']
                || ($replacement['profile']??'')!==($asset['profile']??'')) { throw new \InvalidArgumentException('Replacement must be another verified, selectable asset of the same kind and profile'); }
        }
        $plan=['site_id'=>$siteId,'asset_id'=>$assetId,'asset_key'=>$asset['asset_key'],'filename'=>$asset['filename'],
            'kind'=>$asset['kind'],'profile'=>$asset['profile'],'content_sha256'=>$asset['content_sha256'],
            'references'=>$references,'reference_count'=>count($references),'mutable_count'=>count($mutable),
            'dependencies'=>(array)($asset['companions']??[]),'blockers'=>$blockers,
            'protected_bytes'=>($references||$blockers)?(int)$asset['size_bytes']:0,
            'reclaimable_bytes'=>($references||$blockers)?0:(int)$asset['size_bytes'],
            'can_retire'=>!$references&&!$blockers,'can_reassign'=>!$blockers&&$replacement!==null&&count($mutable)>0,
            'replacement_asset_id'=>$replacementId,'retention'=>'Quarantine only; retained recovery bytes are not permanently reclaimed'];
        // Include private exact field revisions without exposing their values.
        $plan['revision']=hash('sha256',json_encode([$plan,$snapshot,$storage],JSON_THROW_ON_ERROR));
        return $plan;
    }

    public function reassign(int $siteId,int $assetId,int $replacementId,string $revision,array $storage): array
    {
        $plan=$this->preview($siteId,$assetId,$storage,$replacementId);
        if ($revision===''||!hash_equals($plan['revision'],$revision)||!$plan['can_reassign']) { throw new \RuntimeException('Reassignment preview is stale or blocked; preview again'); }
        $registry=new ManagedAssetRegistry($this->db);$old=$registry->getById($siteId,$assetId);$new=$registry->getById($siteId,$replacementId);
        $sources=(new AssetReferenceIndex($this->db))->snapshot($siteId)['sources'];$changed=0;
        $this->db->beginTransaction();
        try {
            foreach ($sources as $source) {
                if (!$source['mutable']||!AssetReferenceIndex::matches($source,$old)) { continue; }
                foreach ($source['fields'] as $field=>$before) {
                    $after=self::replace($before,$old,$new,$source['key']?:$field);
                    if ($after===$before) { continue; }
                    $stmt=$this->db->prepare('UPDATE `'.$source['table'].'` SET `'.$field.'`=? WHERE id=? AND site_id=? AND `'.$field.'`=?');
                    $stmt->execute([$after,$source['id'],$siteId,$before]);
                    if ($stmt->rowCount()!==1) { throw new \RuntimeException('A reference changed concurrently; no reassignment was committed'); }
                    $changed++;
                }
            }
            if (!$changed) { throw new \RuntimeException('No exact replaceable references were found'); }
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $error; }
        return ['ok'=>true,'changed_fields'=>$changed,'message'=>'Configuration references reassigned. Rebuild affected phones and re-preview; live and rollback dependencies remain protected.'];
    }

    private static function replace(mixed $value,array $old,array $new,string $key): mixed
    {
        if (is_string($value)) {
            $decoded=json_decode($value,true);
            if (json_last_error()===JSON_ERROR_NONE&&is_array($decoded)) { $updated=self::replace($decoded,$old,$new,$key);return $updated===$decoded?$value:json_encode($updated,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
            if ($value===$old['asset_key']) { return $new['asset_key']; }
            if ($value===($old['metadata']['timezone_asset_key']??null)) { return $new['metadata']['timezone_asset_key']??$value; }
            if (preg_match('/(?:asset|payload)_id$/i',$key)&&ctype_digit($value)&&(int)$value===(int)$old['asset_id']) { return (string)$new['asset_id']; }
            if (AssetReferenceIndex::references($value,$old,$key)&&$value===$old['filename']) { return $new['filename']; }
            // URLs and composite bindings require their owning manager, not text substitution.
            return $value;
        }
        if (is_int($value)&&preg_match('/(?:asset|payload)_id$/i',$key)&&$value===(int)$old['asset_id']) { return (int)$new['asset_id']; }
        if (is_array($value)) { foreach ($value as $child=>$item) { $value[$child]=self::replace($item,$old,$new,$key.'.'.$child); } }
        return $value;
    }
}
