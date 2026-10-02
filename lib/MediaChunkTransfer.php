<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/AssetFilename.php';

require_once __DIR__ . '/ServiceFilePermissions.php';

/** Bounded receipt-owned HTTP transfers; caller holds the destination site lock. */
final class MediaChunkTransfer
{
    private const LIMIT = 8388608;
    public static function download(string $file, array $request): array
    {
        $bytes=self::read($file);$hash=hash('sha256',$bytes);$size=strlen($bytes);$offset=(int)($request['offset']??0);
        if ($offset<0 || $offset>$size) { throw new \InvalidArgumentException('Media transfer offset is invalid'); }
        if ($offset>0 || isset($request['expected_sha256'])) {
            if (!hash_equals($hash,(string)($request['expected_sha256']??'')) || $size!==(int)($request['expected_size']??-1)) {
                throw new \DomainException('Media source changed during transfer; restart with a new transfer');
            }
        }
        $chunkBytes=filter_var($request['chunk_bytes']??98304,FILTER_VALIDATE_INT);
        if ($chunkBytes===false || $chunkBytes<1 || $chunkBytes>524288) { throw new \InvalidArgumentException('Media chunk size is invalid'); }
        $chunk=substr($bytes,$offset,$chunkBytes);
        return ['ok'=>true,'offset'=>$offset,'bytes'=>strlen($chunk),'content'=>base64_encode($chunk),'eof'=>$offset+strlen($chunk)===$size,'size'=>$size,'sha256'=>$hash];
    }

    public static function upload(string $file,string $root,string $owner,array $request,?callable $validate=null): array
    {
        $id=(string)($request['transfer_id']??'');$hash=(string)($request['expected_sha256']??'');
        $size=(int)($request['expected_size']??-1);$offset=(int)($request['offset']??-1);
        $data=base64_decode((string)($request['content']??''),true);$final=!empty($request['final']);
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$id) || !preg_match('/\A[a-f0-9]{64}\z/D',$hash)
            || $size<0 || $size>self::LIMIT || $offset<0 || $data===false || strlen($data)>524288
            || $offset+strlen($data)>$size || ($final && $offset+strlen($data)!==$size)) {
            throw new \InvalidArgumentException('Media transfer ownership, content or bounds are invalid');
        }
        self::directory($root);$directory=$root.'/'.$id;$receiptPath=$directory.'/receipt.json';$part=$directory.'/payload';
        $identity=['transfer_id'=>$id,'owner'=>$owner,'filename'=>basename($file),'sha256'=>$hash,'size'=>$size,'overwrite'=>!empty($request['overwrite'])];
        if (is_link($directory)) { throw new \RuntimeException('Media transfer directory is a symbolic link'); }
        if (!is_dir($directory)) {
            if ($offset!==0) { throw new \DomainException('Media transfer has no initial owner receipt'); }
            $before=self::identity($file);
            if ($before !== null) { AssetFilename::assertSameContent(basename($file), $hash, $before['sha256']); }
            if (!$identity['overwrite'] && $before!==null) { throw new \DomainException('Destination already exists; no overwrite was approved'); }
            self::directory($directory);self::save($receiptPath,$identity+['before'=>$before,'state'=>'receiving']);
        }
        $receipt=json_decode(self::read($receiptPath,8192),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($receipt) || array_intersect_key($receipt,$identity)!==$identity) {
            throw new \DomainException('Media transfer receipt belongs to different content or target');
        }
        $destination=self::identity($file);$expected=['sha256'=>$hash,'size'=>$size];
        if ($destination !== null) { AssetFilename::assertSameContent(basename($file), $hash, $destination['sha256']); }
        if (in_array($receipt['state']??'',['committing','complete'],true) && $destination===$expected) {
            if (substr(self::read($file),$offset,strlen($data))!==$data) { throw new \DomainException('Replayed media chunk differs from completed transfer'); }
            if ($receipt['state']!=='complete') { $receipt['state']='complete';self::save($receiptPath,$receipt); }
            return ['ok'=>true,'bytes'=>strlen($data),'complete'=>true,'transfer_id'=>$id,'sha256'=>$hash,'size'=>$size];
        }
        if (($receipt['state']??'')==='complete') { throw new \DomainException('Completed transfer destination changed; refusing replay'); }
        ServiceFilePermissions::assertNoLinks($part);
        clearstatcache(true,$part);
        if (file_exists($part) && !is_file($part)) { throw new \RuntimeException('Media staging target is not a regular file'); }
        $storedSize=is_file($part)?filesize($part):0;
        if ($storedSize>self::LIMIT) { throw new \RuntimeException('Media staging exceeds its bound'); }
        if ($offset<$storedSize) {
            if ($offset+strlen($data)>$storedSize || file_get_contents($part,false,null,$offset,strlen($data))!==$data) { throw new \DomainException('Replayed media chunk differs from retained bytes'); }
        } elseif ($offset===$storedSize) {
            $handle=fopen($part,'c+b');
            if (!$handle) { throw new \RuntimeException('Cannot open private media staging'); }
            try {
                ServiceFilePermissions::apply($part,0660);
                if (fseek($handle,$offset)!==0 || fwrite($handle,$data)!==strlen($data) || !fflush($handle) || !fsync($handle)) {
                    throw new \RuntimeException('Private media chunk persistence is unverified');
                }
            } finally { fclose($handle); }
            if (file_get_contents($part,false,null,$offset,strlen($data))!==$data) { throw new \RuntimeException('Private media chunk verification failed'); }
        } else { throw new \DomainException('Media transfer chunks arrived out of order'); }
        if (!$final) { return ['ok'=>true,'bytes'=>strlen($data),'complete'=>false,'transfer_id'=>$id]; }
        $stored=self::read($part);
        if (strlen($stored)!==$size || !hash_equals($hash,hash('sha256',$stored))) { throw new \DomainException('Complete media transfer hash differs; retained bytes were not published'); }
        if ($validate) { $validate($stored); }
        if ($destination!==($receipt['before']??null)) { throw new \DomainException('Destination changed after transfer began; retained bytes were not published'); }
        if ($destination!==null) {
            $backup=$directory.'/before';
            if (!is_file($backup)) { self::write($backup,self::read($file)); }
            if (self::identity($backup)!==$destination) { throw new \RuntimeException('Retained prior media failed verification'); }
        }
        $receipt['state']='committing';self::save($receiptPath,$receipt);
        ServiceFilePermissions::apply($part,0660);
        if (!rename($part,$file)) { throw new \RuntimeException('Media activation failed; receipt and prior bytes retained'); }
        if (self::identity($file)!==$expected) { throw new \RuntimeException('Media activation verification failed; inspect retained transfer and prior bytes'); }
        $receipt['state']='complete';self::save($receiptPath,$receipt);
        return ['ok'=>true,'bytes'=>strlen($data),'complete'=>true,'transfer_id'=>$id,'sha256'=>$hash,'size'=>$size];
    }

    private static function identity(string $path): ?array
    {
        ServiceFilePermissions::assertNoLinks($path);
        clearstatcache(true,$path);
        if (is_link($path)) { throw new \RuntimeException('Media transfer path is a symbolic link'); }
        if (!file_exists($path)) { return null; }
        $bytes=self::read($path);return ['sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes)];
    }
    /** Bounded regular-file read, also used by the non-chunk media endpoint. */
    public static function read(string $path,int $limit=self::LIMIT): string
    {
        ServiceFilePermissions::assertNoLinks($path);
        clearstatcache(true,$path);
        if (is_link($path) || !is_file($path) || filesize($path)>$limit) { throw new \RuntimeException('Media transfer file is missing, unsafe or exceeds its bound'); }
        $bytes=file_get_contents($path,false,null,0,$limit+1);
        if (!is_string($bytes) || strlen($bytes)>$limit) { throw new \RuntimeException('Media transfer read failed or exceeds its bound'); }
        return $bytes;
    }
    private static function directory(string $path): void
    {
        // API and forced-command SSH execute as different accounts in the
        // existing asterisk group. Both must be able to reconcile the receipt.
        ServiceFilePermissions::directory($path);
    }
    private static function save(string $path,array $receipt): void { self::write($path,json_encode($receipt,JSON_THROW_ON_ERROR)); }
    /** Replace one media/state file without exposing a truncated live file. */
    public static function write(string $path,string $bytes): void
    {
        ServiceFilePermissions::assertNoLinks($path);
        if (strlen($bytes)>self::LIMIT || (file_exists($path) && !is_file($path))) { throw new \RuntimeException('Media write target or size is invalid'); }
        if (AssetFilename::isImmutable(basename($path)) && is_file($path)) {
            AssetFilename::assertSameContent(basename($path), hash('sha256', $bytes), (string)hash_file('sha256', $path));
        }
        $temporary=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$handle=fopen($temporary,'xb');
        if (!$handle) { throw new \RuntimeException('Cannot stage transfer state'); }
        try {
            ServiceFilePermissions::apply($temporary,0660);
            if (fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Cannot persist transfer state'); }
            fclose($handle); $handle=null;
            if (!rename($temporary,$path) || self::read($path)!==$bytes) { throw new \RuntimeException('Transfer state persistence is unverified'); }
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }
}
