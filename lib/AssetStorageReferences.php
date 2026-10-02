<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Bounded read-only dependency scan; raw configuration content never escapes. */
final class AssetStorageReferences
{
    public static function scan($sftp, string $root, array $assets): array
    {
        $references=[];$observations=[];$queue=[['',0]];$entries=0;$bytes=0;$checks=0;$deadline=microtime(true)+8;
        try {
            while ($queue) {
                [$relative,$depth]=array_shift($queue);
                if ($depth>12 || $entries>50000 || microtime(true)>$deadline) { throw new \RuntimeException('Reference traversal limit reached'); }
                $listing=$sftp->rawlist(rtrim($root,'/').'/'.$relative);
                if (!is_array($listing)) { throw new \RuntimeException('Reference directory could not be read'); }
                ksort($listing);
                foreach ($listing as $name=>$metadata) {
                    if ($name==='.' || $name==='..') { continue; }
                    if (!is_string($name)||str_contains($name,'/')||str_contains($name,'\\')) { throw new \RuntimeException('Unsafe reference directory entry'); }
                    if (++$entries>50000 || microtime(true)>$deadline) { throw new \RuntimeException('Reference traversal limit reached'); }
                    $path=ltrim($relative.'/'.$name,'/');$type=(int)($metadata['type']??0);
                    if ($type===3) { throw new \RuntimeException('Symbolic link prevents complete reference proof'); }
                    if ($type===2) {
                        // The retained payload's own manifest is not a consumer.
                        if (preg_match('#^\.ucmprovisioner/(?:timezone-bundles|retired-assets|write-lock)(?:/|$)#',$path)) { continue; }
                        $queue[]=[$path,$depth+1];continue;
                    }
                    if (!preg_match('/\.(?:xml|cfg|cnf|json|conf|txt)$/i',$name)) { continue; }
                    $size=(int)($metadata['size']??-1);
                    if ($size<0||$size>2097152||($bytes+=$size)>67108864) { throw new \RuntimeException('Reference content limit reached'); }
                    $content=$sftp->get(rtrim($root,'/').'/'.$path,false,0,2097153);
                    if (!is_string($content)||strlen($content)!==$size) { throw new \RuntimeException('Reference content changed or could not be read'); }
                    $hash=hash('sha256',$content);$observations[$path]=$hash;
                    $type=str_starts_with($path,'.ucmprovisioner/')?'retained_transaction':'live_file';
                    if (str_contains($path,'wallpaper-sets/')) { $type='managed_alias'; }
                    foreach ($assets as $asset) {
                        if (++$checks>1000000||microtime(true)>$deadline) { throw new \RuntimeException('Reference matching limit reached'); }
                        foreach (array_filter([$asset['filename']??'', $asset['asset_key']??'', $asset['metadata']['timezone_asset_key']??'']) as $needle) {
                            if (str_contains($content,(string)$needle)) {
                                $references[(string)$asset['asset_key']][]=['type'=>$type,'label'=>$path,'revision'=>$hash,'mutable'=>false];break;
                            }
                        }
                    }
                }
            }
            ksort($observations);
            return ['complete'=>true,'references'=>$references,'revision'=>hash('sha256',json_encode($observations,JSON_THROW_ON_ERROR)),'issues'=>[]];
        } catch (\Throwable $error) {
            return ['complete'=>false,'references'=>$references,'revision'=>'','issues'=>[$error->getMessage()]];
        }
    }
}
