<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__.'/MediaChunkTransfer.php';

/** Large editor requests execute once on the PBX that owns both media stores. */
final class WallpaperEditorInput
{
    public const CHUNK = 262144;
    public const LIMIT = 50331648;
    public const OPERATIONS = ['wallpaper_set_source','wallpaper_profile_source_save','wallpaper_profile_source_reset',
        'put_media_pairs','wallpaper_set_rename','wallpaper_set_delete','wallpaper_set_repair_encoding','wallpaper_set_repair_hosted_names'];

    public function __construct(private string $root) {}

    public function handle(array $request, int $siteId, callable $apply): array
    {
        $id=(string)($request['transfer_id']??'');$hash=(string)($request['sha256']??'');$size=(int)($request['size']??0);
        $op=(string)($request['op']??'');
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$hash)
            ||$size<2||$size>self::LIMIT||!in_array($op,['chunk','apply'],true)) {
            throw new \InvalidArgumentException('Wallpaper input identity or bounds are invalid');
        }
        $directory=$this->root.'/'.$id;$path=$directory.'/receipt.json';
        $lock=ServiceFilePermissions::lock($directory.'/input.lock');
        if (!$lock) { throw new \RuntimeException('Wallpaper input is already being processed'); }
        try {
            $identity=['site_id'=>$siteId,'sha256'=>$hash,'size'=>$size];
            $receipt=is_file($path)?json_decode(MediaChunkTransfer::read($path),true,32,JSON_THROW_ON_ERROR):null;
            if ($receipt===null) {
                if ($op!=='chunk'||(int)($request['index']??-1)!==0) { throw new \DomainException('Wallpaper input has no initial receipt'); }
                $receipt=$identity+['state'=>'receiving'];self::save($path,$receipt);
            }
            if (!is_array($receipt)||array_intersect_key($receipt,$identity)!==$identity) { throw new \DomainException('Wallpaper input belongs to another request'); }
            if ($op==='chunk') {
                $index=(int)($request['index']??-1);$count=(int)ceil($size/self::CHUNK);
                $bytes=base64_decode((string)($request['content']??''),true);
                if ($index<0||$index>=$count||$bytes===false||strlen($bytes)!==min(self::CHUNK,$size-$index*self::CHUNK)) {
                    throw new \InvalidArgumentException('Wallpaper input chunk is invalid');
                }
                $part=$directory.'/'.$index.'.part';
                if ($receipt['state']==='complete') {
                    if (!hash_equals((string)($receipt['chunks'][$index]??''),hash('sha256',$bytes))) { throw new \DomainException('Completed wallpaper chunk differs'); }
                    return ['ok'=>true,'transfer_id'=>$id,'index'=>$index];
                }
                if (is_file($part)) {
                    if (MediaChunkTransfer::read($part)!==$bytes) { throw new \DomainException('Replayed wallpaper input differs from retained bytes'); }
                } else {
                    if ($receipt['state']!=='receiving') { throw new \DomainException('Wallpaper input is already sealed'); }
                    MediaChunkTransfer::write($part,$bytes);
                }
                return ['ok'=>true,'transfer_id'=>$id,'index'=>$index];
            }
            if ($receipt['state']==='complete') { return $receipt['result']; }
            if ($receipt['state']!=='receiving') {
                return ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'transfer_id'=>$id,
                    'message'=>'Wallpaper apply completion is uncertain; inspect the operation receipt before retrying'];
            }
            $json='';$receipt['chunks']=[];
            for ($index=0;$index<(int)ceil($size/self::CHUNK);$index++) {
                $chunk=MediaChunkTransfer::read($directory.'/'.$index.'.part',self::CHUNK);
                $receipt['chunks'][$index]=hash('sha256',$chunk);$json.=$chunk;
            }
            if (strlen($json)!==$size||!hash_equals($hash,hash('sha256',$json))) { throw new \DomainException('Complete wallpaper input hash differs'); }
            $payload=json_decode($json,true,32,JSON_THROW_ON_ERROR);unset($json);
            if (!is_array($payload)||!in_array($payload['op']??'',self::OPERATIONS,true)) { throw new \InvalidArgumentException('Staged wallpaper operation is invalid'); }
            $payload['site_id']=$siteId;
            $receipt['state']='applying';self::save($path,$receipt);
            try {
                $result=$apply($payload);
                if (!is_array($result)) { throw new \RuntimeException('Wallpaper apply returned no result'); }
                $receipt['state']='complete';$receipt['result']=$result;self::save($path,$receipt);
                if (!empty($result['ok']) && empty($result['recovery_required'])) {
                    foreach (array_keys($receipt['chunks']) as $index) { @unlink($directory.'/'.$index.'.part'); }
                }
                return $result;
            } catch (\Throwable $error) {
                return ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'transfer_id'=>$id,
                    'message'=>'Wallpaper apply did not confirm completion; retained input must not be replayed: '.$error->getMessage()];
            }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }

    private static function save(string $path,array $receipt): void
    {
        MediaChunkTransfer::write($path,json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
