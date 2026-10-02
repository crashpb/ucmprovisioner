<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/MediaChunkTransfer.php';
use FreePBX\modules\Ucmprovisioner\MediaChunkTransfer;
$expect=static function(bool $ok,string $message):void { if (!$ok) { throw new RuntimeException($message); } };
$reject=static function(callable $call,string $message) use($expect):void {
    $failed=false;try {$call();}catch (Throwable $e) {$failed=true;}$expect($failed,$message);
};
$root=sys_get_temp_dir().'/ucmp-media-chunks-'.bin2hex(random_bytes(8));mkdir($root,0700);
$stage=$root.'/transfers';$file=$root.'/asset.wav';$source=$root.'/source.wav';
$request=static function(string $id,string $bytes,int $offset,int $length,bool $final=false):array {
    return ['transfer_id'=>$id,'expected_sha256'=>hash('sha256',$bytes),'expected_size'=>strlen($bytes),
        'offset'=>$offset,'content'=>base64_encode(substr($bytes,$offset,$length)),'final'=>$final,'overwrite'=>true];
};
$upload=static fn(array $r)=>MediaChunkTransfer::upload($file,$stage,'site:family:model:asset.wav',$r);
try {
    $compact=$root.'/'.\FreePBX\modules\Ucmprovisioner\AssetFilename::content('r','first','wav');
    file_put_contents($compact,'first');
    $reject(fn()=>MediaChunkTransfer::write($compact,'other'),'Direct compact-name collision was overwritten');
    $compactRequest=$request(str_repeat('e',32),'other',0,5,true);
    $reject(fn()=>MediaChunkTransfer::upload($compact,$stage,'compact-collision',$compactRequest),'Chunked compact-name collision was overwritten');
    $expect(file_get_contents($compact)==='first','Compact-name collision changed existing hosted bytes');
    $atomic=$root.'/atomic.wav';file_put_contents($atomic,'before');
    MediaChunkTransfer::write($atomic,'after');
    $expect(file_get_contents($atomic)==='after' && !glob($atomic.'.*.tmp'),'Direct media publication must atomically replace bytes without temporary-file leftovers');
    $reject(fn()=>MediaChunkTransfer::write($atomic,str_repeat('x',8388609)),'Oversized direct media writes must fail before replacement');
    $expect(file_get_contents($atomic)==='after','Rejected writes must preserve the original live file');
    $reject(fn()=>MediaChunkTransfer::write($root,'x'),'A directory cannot be replaced by media content');
    if (PHP_OS_FAMILY !== 'Windows') {
        $linked=$root.'/linked';symlink($root,$linked);
        $reject(fn()=>MediaChunkTransfer::read($linked.'/atomic.wav'),'Media reads must reject linked ancestor directories');
        $reject(fn()=>MediaChunkTransfer::write($linked.'/atomic.wav','linked change'),'Media writes must reject linked ancestor directories');
        unlink($linked);
        $expect(file_get_contents($atomic)==='after','Rejected linked write must preserve its referent');
    }
    $a=str_repeat('A',98304).str_repeat('a',128);$b=str_repeat('B',98304).str_repeat('b',128);
    $aid=str_repeat('a',32);$bid=str_repeat('b',32);file_put_contents($file,'prior');
    $first=$request($aid,$a,0,98304);$second=$request($aid,$a,98304,128,true);
    $expect(!$upload($first)['complete'],'First chunk does not publish');
    $expect(!$upload($first)['complete'],'Identical initial chunk retry is idempotent');
    $upload($request($bid,$b,0,98304));
    $expect(file_get_contents($file)==='prior','Interleaved transfer prefixes do not alter destination');
    $bad=$first;$bad['content']=base64_encode(str_repeat('X',98304));
    $reject(fn()=>$upload($bad),'Conflicting chunk retry must fail');
    $wrongOwner=$first;$wrongOwner['expected_sha256']=hash('sha256',$b);
    $reject(fn()=>$upload($wrongOwner),'Transfer ID cannot change expected content');
    $reject(fn()=>MediaChunkTransfer::upload($file,$stage,'another-owner',$first),'Transfer ID cannot change target owner');
    $done=$upload($second);
    $expect($done['complete'] && $done['sha256']===hash('sha256',$a) && file_get_contents($file)===$a,'Only the complete verified A bytes publish');
    $expect(file_get_contents($stage.'/'.$aid.'/before')==='prior','Prior destination bytes retained');
    $expect($upload($second)['complete'],'Completed final response retry is idempotent');
    $reject(fn()=>$upload($request($bid,$b,98304,128,true)),'Second transfer cannot overwrite changed destination');
    $expect(file_get_contents($file)===$a,'Destination CAS preserves first completed transfer');
    $expect(file_get_contents($stage.'/'.$bid.'/payload')===$b,'Conflicting complete transfer retained for inspection');

    // A crash after activation but before the final receipt is recoverable without republishing.
    $receiptPath=$stage.'/'.$aid.'/receipt.json';$receipt=json_decode(file_get_contents($receiptPath),true);
    $receipt['state']='committing';file_put_contents($receiptPath,json_encode($receipt));
    $expect($upload($second)['complete'],'Commit-intent plus matching destination resumes after lost final receipt');
    $expect(json_decode(file_get_contents($receiptPath),true)['state']==='complete','Recovered completion receipt persisted');
    file_put_contents($file,'later');
    $reject(fn()=>$upload($second),'Completed transfer cannot replay over later bytes');
    $expect(file_get_contents($file)==='later','Later destination preserved');

    $cid=str_repeat('c',32);$c='unchanged';$corrupt=$request($cid,$c,0,strlen($c),true);$corrupt['content']=base64_encode('corrupted');
    $reject(fn()=>$upload($corrupt),'Full hash must reject corrupt content');
    $expect(file_get_contents($file)==='later','Hash failure does not publish');
    $missing=$request(str_repeat('d',32),$a,98304,128,true);
    $reject(fn()=>$upload($missing),'Noninitial chunk requires receipt');
    $legacy=['offset'=>0,'content'=>base64_encode('x'),'final'=>true];
    $reject(fn()=>$upload($legacy),'Legacy unowned chunk requests fail closed');
    $noOverwrite=$request(str_repeat('e',32),'new',0,3,true);$noOverwrite['overwrite']=false;
    $reject(fn()=>$upload($noOverwrite),'No-overwrite is checked at receiver');
    $invalidFormat=$request(str_repeat('f',32),'bad-format',0,10,true);
    $reject(fn()=>MediaChunkTransfer::upload($file,$stage,'site:family:model:asset.wav',$invalidFormat,
        static function(string $bytes):void {throw new DomainException('Rejected media format');}),'Format validator runs before publication');
    $expect(file_get_contents($file)==='later','Format failure does not replace destination');

    file_put_contents($source,$a);$download=MediaChunkTransfer::download($source,['offset'=>0]);
    $expect($download['sha256']===hash('sha256',$a) && $download['size']===strlen($a) && !$download['eof'],'Source first chunk reports immutable content pin');
    $pin=['offset'=>98304,'expected_sha256'=>$download['sha256'],'expected_size'=>$download['size']];
    $expect(base64_decode(MediaChunkTransfer::download($source,$pin)['content'])===str_repeat('a',128),'Pinned next chunk reads same source');
    $reject(fn()=>MediaChunkTransfer::download($source,['offset'=>98304]),'Later download cannot omit content pin');
    file_put_contents($source,$b);
    $reject(fn()=>MediaChunkTransfer::download($source,$pin),'Same-size source replacement is detected between chunks');
    $reject(fn()=>MediaChunkTransfer::download($source,['offset'=>0,'expected_sha256'=>$download['sha256'],'expected_size'=>$download['size']]),'A restarted target cannot silently change a previously pinned source');
    $largeId=str_repeat('2',32);$largeBytes=str_repeat('media!',100000);
    file_put_contents($source,$largeBytes);
    $largeChunk=MediaChunkTransfer::download($source,['offset'=>0,'chunk_bytes'=>524288]);
    $expect($largeChunk['bytes']===524288 && !$largeChunk['eof'],'Negotiated hosted-media chunk is bounded to 512 KiB');
    $upload($request($largeId,$largeBytes,0,524288));
    $largeDone=$upload($request($largeId,$largeBytes,524288,strlen($largeBytes)-524288,true));
    $expect($largeDone['complete'] && file_get_contents($file)===$largeBytes,'Appended large chunks must publish the exact verified file');
    $reject(fn()=>MediaChunkTransfer::download($source,['chunk_bytes'=>524289]),'Oversized media download chunk accepted');
    if (PHP_OS_FAMILY !== 'Windows') {
        $group=posix_getgrnam('asterisk');
        foreach ([$stage,$stage.'/'.$aid] as $directory) {
            $expect((fileperms($directory)&0777)===0770 && filegroup($directory)===$group['gid'],'Media transfer directories must be shared by the existing service group');
        }
        $expect((fileperms($stage.'/'.$bid.'/payload')&0777)===0660,'Retained transfer payload must allow service-group recovery');
    }
    if (function_exists('pcntl_fork') && function_exists('posix_geteuid') && posix_geteuid()===0
        && posix_getpwnam('asterisk') && posix_getpwnam('ucmp-pbx')) {
        // Exercise real distinct process identities without changing either account.
        \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::directory($root);
        $asUser=static function(string $name,callable $operation) use($expect):void {
            $pid=pcntl_fork();
            if ($pid===-1) { throw new RuntimeException('Cannot fork service-account fixture'); }
            if ($pid===0) {
                try {
                    $account=posix_getpwnam($name);
                    if (!posix_initgroups($name,$account['gid']) || !posix_setgid($account['gid']) || !posix_setuid($account['uid'])) {
                        throw new RuntimeException('Cannot select fixture service identity');
                    }
                    $operation();exit(0);
                } catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
            }
            pcntl_waitpid($pid,$status);
            $expect(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0,'Media transaction failed as '.$name);
        };
        $crossId=str_repeat('1',32);$crossFile=$root.'/cross-service.wav';$crossRoot=$root.'/cross-service-transfers';
        $crossUpload=static fn(array $r)=>MediaChunkTransfer::upload($crossFile,$crossRoot,'cross-service-fixture',$r);
        $asUser('asterisk',static fn()=>$crossUpload($request($crossId,$a,0,98304)));
        $asUser('ucmp-pbx',static fn()=>$crossUpload($request($crossId,$a,98304,128,true)));
        $asUser('asterisk',static fn()=>$crossUpload($request($crossId,$a,98304,128,true)));
        $expect(file_get_contents($crossFile)===$a,'Cross-service reconciliation changed completed media bytes');
        echo "Distinct API/SSH service-account receipt continuation passed\n";
    } else { echo "Distinct service-account fixture requires Linux root, pcntl and the enrolled accounts\n"; }
    echo "HTTP media transfer ownership, interleaving, content pins, retry, recovery and destination CAS fixtures passed\n";
} finally {
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname()); }
    rmdir($root);
}
