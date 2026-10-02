<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__.'/AssetReferenceIndex.php';
require_once __DIR__.'/AssetStorageReferences.php';

/** Exact HTTP-library retirement. Call writes under the destination site lock. */
final class HostedAssetRetirement
{
    public function __construct(private \PDO $db,private string $directory,private string $family,private string $model,private ?\Closure $move=null)
    {
        $resolved=realpath($directory);
        if ($resolved===false||is_link($directory)||!is_dir($resolved)) { throw new \RuntimeException('HTTP media library is unavailable or linked'); }
        $this->directory=str_replace('\\','/',$resolved);
        $this->move ??= static fn(string $from,string $to):bool=>@rename($from,$to);
        if (!in_array($family,['cisco_cnf','cisco_xml','yealink','grandstream'],true)||!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,63}$/D',$model)) { throw new \InvalidArgumentException('HTTP media scope is invalid'); }
    }

    public function asset(string $name): array
    {
        self::name($name);$path=$this->directory.'/'.$name;
        if (is_link($path)||!is_file($path)||!is_readable($path)) { throw new \RuntimeException('Exact HTTP asset is missing, unreadable or linked'); }
        $size=filesize($path);
        if ($size===false||$size>8388608) { throw new \RuntimeException('HTTP asset exceeds the verification bound'); }
        $hash=hash_file('sha256',$path);
        if (!is_string($hash)) { throw new \RuntimeException('HTTP asset hash could not be read'); }
        $relative=$this->family.'/'.$this->model.'/'.$name;
        return ['asset_id'=>0,'asset_key'=>hash('sha256',"http\0".$relative."\0".$hash),'filename'=>$name,
            'kind'=>in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['wav','mp3','ogg'],true)?'ringtone':'background',
            'profile'=>$this->family.':'.$this->model,'content_sha256'=>$hash,'size_bytes'=>$size,'metadata'=>[]];
    }

    public function preview(int $siteId,string $name,array $external,?string $catalogId=null): array
    {
        $asset=$this->asset($name);$graph=(new AssetReferenceIndex($this->db))->snapshot($siteId);$references=[];$blockers=[];
        foreach ($graph['sources'] as $source) { if (AssetReferenceIndex::matches($source,$asset)) { $references[]=AssetReferenceIndex::summary($source); } }
        array_push($references,...(array)($external['references'][$asset['asset_key']]??[]));
        $catalog=$catalogId===null?null:$this->catalogPath($catalogId);
        $tree=$catalog===null?[]:self::tree($catalog);
        $local=$this->localReferences($asset,$catalog);
        array_push($references,...(array)($local['references'][$asset['asset_key']]??[]));
        if (!$graph['complete']) { $blockers[]='Database reference inventory is incomplete'; }
        if (($external['subject_asset_key']??'')!==$asset['asset_key']) { $blockers[]='HTTP asset changed since its generated/rollback references were collected'; }
        if (empty($external['complete'])||!empty($external['active_scan'])) { $blockers[]='TFTP generated/rollback inventory is incomplete or a scan is active'; }
        if (!$local['complete']) { $blockers[]='HTTP source/alias inventory is incomplete'; }
        $bytes=(int)$asset['size_bytes']+array_sum(array_column($tree,'size'));
        $plan=['site_id'=>$siteId,'family'=>$this->family,'model'=>$this->model,'filename'=>$name,'catalog_id'=>$catalogId,
            'asset_key'=>$asset['asset_key'],'content_sha256'=>$asset['content_sha256'],'references'=>$references,
            'dependencies'=>array_map(static fn(string $path,array $file):array=>['filename'=>$path,'size_bytes'=>$file['size'],'content_sha256'=>$file['sha256']],array_keys($tree),array_values($tree)),
            'blockers'=>$blockers,'can_retire'=>!$references&&!$blockers,
            'protected_bytes'=>($references||$blockers)?$bytes:0,'reclaimable_bytes'=>($references||$blockers)?0:$bytes,
            'replacement_plan'=>array_values(array_filter($references,static fn(array $reference):bool=>!empty($reference['mutable']))),
            'replacement_instruction'=>'Reassign the listed site/template/phone fields to a compatible replacement, rebuild affected phones, then refresh this exact preview. Retained aliases and rollback generations stay protected.',
            'retention'=>'Exact public file and complete editable catalog are quarantined; no retained bytes are permanently deleted'];
        $plan['revision']=hash('sha256',json_encode([$plan,$graph,$external,$local,$tree],JSON_THROW_ON_ERROR));
        return $plan;
    }

    public function retire(int $siteId,string $name,array $external,string $revision,?string $catalogId=null): array
    {
        $plan=$this->preview($siteId,$name,$external,$catalogId);
        if ($revision===''||!hash_equals($plan['revision'],$revision)||!$plan['can_retire']) { throw new \RuntimeException('HTTP retirement preview is stale or the asset is protected'); }
        $asset=$this->asset($name);$catalog=$catalogId===null?null:$this->catalogPath($catalogId);$tree=$catalog===null?[]:self::tree($catalog);
        $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(8));$quarantine=$this->recoveryRoot(true).'/'.$id;
        if (!mkdir($quarantine,0700)||!chmod($quarantine,0700)) { throw new \RuntimeException('Private HTTP quarantine could not be created'); }
        $receipt=['schema'=>1,'site_id'=>$siteId,'family'=>$this->family,'model'=>$this->model,'filename'=>$name,'sha256'=>$asset['content_sha256'],
            'size'=>$asset['size_bytes'],'catalog_id'=>$catalogId,'tree'=>$tree,'created'=>time(),'revision'=>$revision];
        $json=json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (file_put_contents($quarantine.'/receipt.json',$json,LOCK_EX)!==strlen($json)||!chmod($quarantine.'/receipt.json',0600)||file_get_contents($quarantine.'/receipt.json')!==$json) { throw new \RuntimeException('HTTP recovery receipt could not be verified; no asset was moved'); }
        $catalogMoved=false;$payloadMoved=false;
        try {
            if ($catalog!==null) {
                if (!(($this->move)($catalog,$quarantine.'/catalog'))) { throw new \RuntimeException('Editable catalog could not be quarantined'); }
                $catalogMoved=true;
            }
            if (!(($this->move)($this->directory.'/'.$name,$quarantine.'/payload'))) { throw new \RuntimeException('Published HTTP file could not be quarantined'); }
            $payloadMoved=true;
            if (!hash_equals($asset['content_sha256'],(string)hash_file('sha256',$quarantine.'/payload'))||($catalogMoved&&self::tree($quarantine.'/catalog')!==$tree)) { throw new \RuntimeException('HTTP quarantine readback differs from its receipt'); }
        } catch (\Throwable $error) {
            return ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'recovery_id'=>$id,
                'catalog_move_confirmed'=>$catalogMoved,'payload_move_confirmed'=>$payloadMoved,
                'message'=>$error->getMessage().'; retained receipt and completed moves require reconciliation'];
        }
        return ['ok'=>true,'changed'=>true,'recovery_id'=>$id,'quarantined_bytes'=>$plan['reclaimable_bytes'],'reclaimed_bytes'=>0,
            'message'=>'HTTP asset and editable source moved to private recovery storage; no retained bytes were deleted'];
    }

    public function restore(int $siteId,string $id): array
    {
        if (!preg_match('/^[0-9]{14}-[a-f0-9]{16}$/D',$id)) { throw new \InvalidArgumentException('HTTP recovery ID is invalid'); }
        $quarantine=$this->recoveryRoot(false).'/'.$id;$receiptPath=$quarantine.'/receipt.json';
        if (is_link($quarantine)||is_link($receiptPath)||!is_file($receiptPath)||filesize($receiptPath)>131072) { throw new \RuntimeException('HTTP recovery receipt is missing or unsafe'); }
        $receipt=json_decode((string)file_get_contents($receiptPath),true);
        if (!is_array($receipt)||(int)($receipt['site_id']??0)!==$siteId||($receipt['family']??'')!==$this->family||($receipt['model']??'')!==$this->model) { throw new \RuntimeException('HTTP recovery belongs to another site or library'); }
        $name=(string)($receipt['filename']??'');self::name($name);$destination=$this->directory.'/'.$name;$payload=$quarantine.'/payload';
        $hash=(string)($receipt['sha256']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$hash)) {throw new \RuntimeException('HTTP recovery hash is invalid');}
        foreach ([$destination,$payload] as $path) {
            if (is_link($path)||(file_exists($path)&&(!is_file($path)||!hash_equals($hash,(string)hash_file('sha256',$path))))) { throw new \RuntimeException('HTTP recovery cannot overwrite changed or linked bytes'); }
        }
        if (!is_file($destination)&&!is_file($payload)) { throw new \RuntimeException('Retained HTTP payload is missing'); }
        $catalogId=$receipt['catalog_id']??null;$catalog=$catalogId===null?null:$this->catalogPath((string)$catalogId,false);$tree=(array)($receipt['tree']??[]);
        if ($catalog!==null) {
            foreach ([$catalog,$quarantine.'/catalog'] as $path) { if (file_exists($path)&&(is_link($path)||self::tree($path)!==$tree)) { throw new \RuntimeException('HTTP editable-source recovery conflicts with changed content'); } }
            if (!is_dir($catalog)&&!is_dir($quarantine.'/catalog')) { throw new \RuntimeException('Retained editable source is missing'); }
        }
        try {
            if (!is_file($destination)&&!(($this->move)($payload,$destination))) { throw new \RuntimeException('HTTP payload restore failed; retained bytes preserved'); }
            if ($catalog!==null&&!is_dir($catalog)&&!(($this->move)($quarantine.'/catalog',$catalog))) { throw new \RuntimeException('HTTP file restored; retained editable source still requires recovery'); }
            if (!hash_equals($hash,(string)hash_file('sha256',$destination))||($catalog!==null&&self::tree($catalog)!==$tree)) { throw new \RuntimeException('HTTP recovery readback failed; receipt retained for inspection'); }
        } catch (\Throwable $error) { return ['ok'=>false,'recovery_required'=>true,'recovery_id'=>$id,'message'=>$error->getMessage()]; }
        return ['ok'=>true,'changed'=>true,'recovery_id'=>$id,'message'=>'Exact HTTP file and editable catalog restored without overwriting another revision'];
    }

    private function localReferences(array $asset,?string $ownCatalog): array
    {
        $directory=$this->directory;
        $reader=new class($directory,$ownCatalog) {
            public function __construct(private string $directory,private ?string $ownCatalog){}
            public function rawlist(string $path): array|false {
                $names=@scandir($path);if($names===false)return false;$rows=[];
                foreach($names as $name){if($name==='.'||$name==='..')continue;$full=rtrim($path,'/').'/'.$name;
                    if($full===$this->directory.'/.retired-assets'||$full===$this->ownCatalog)continue;
                    if(str_ends_with($name,'.ucmp-part'))throw new \RuntimeException('Pending HTTP upload prevents complete retirement proof');
                    $rows[$name]=['type'=>is_link($full)?3:(is_dir($full)?2:1),'size'=>is_file($full)?filesize($full):0];
                }return $rows;
            }
            public function get(string $path,mixed $unused=false,int $offset=0,int $length=2097153): string|false {return @file_get_contents($path,false,null,$offset,$length);}
        };
        return AssetStorageReferences::scan($reader,$directory,[$asset]);
    }

    private function catalogPath(string $id,bool $require=true): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$id)) { throw new \InvalidArgumentException('HTTP catalog identity is invalid'); }
        $parent=$this->directory.'/.catalogs';$path=$parent.'/'.$id;
        if (is_link($parent)||is_link($path)||!is_dir($parent)||($require&&!is_dir($path))) { throw new \RuntimeException('HTTP editable catalog is missing or linked'); }
        return $path;
    }
    private function recoveryRoot(bool $create): string
    {
        $root=$this->directory.'/.retired-assets';
        if (is_link($root)) { throw new \RuntimeException('HTTP recovery root cannot be a symbolic link'); }
        if (!is_dir($root)&&(!$create||!mkdir($root,0700))) { throw new \RuntimeException('HTTP recovery root is unavailable'); }
        if ($create&&!chmod($root,0700)) { throw new \RuntimeException('HTTP recovery root permissions failed'); }
        return $root;
    }
    private static function name(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.(?:png|jpg|jpeg|bmp|gif|wav|mp3|ogg)$/iD',$name)) { throw new \InvalidArgumentException('HTTP asset filename is invalid'); }
    }
    private static function tree(string $root): array
    {
        if (is_link($root)||!is_dir($root)) { throw new \RuntimeException('Retained source tree is unsafe'); }
        $files=[];$bytes=0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()||!$file->isFile()||count($files)>=500||$file->getSize()>8388608||($bytes+=$file->getSize())>33554432) { throw new \RuntimeException('Retained source tree exceeds safe verification bounds'); }
            $path=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            $hash=hash_file('sha256',$file->getPathname());
            if (!is_string($hash)) { throw new \RuntimeException('Retained source file could not be verified'); }
            $files[$path]=['sha256'=>$hash,'size'=>$file->getSize()];
        }
        ksort($files);return $files;
    }
}
