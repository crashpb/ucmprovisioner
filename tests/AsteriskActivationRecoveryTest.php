<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner {
    function chown(string $path,$owner): bool { return true; }
    function chgrp(string $path,$group): bool { return true; }
    final class RuntimeFixtureFault { public static string $copy=''; public static bool $afterStart=false; }
    function rename(string $from,string $to): bool {
        if (RuntimeFixtureFault::$afterStart
            && ((RuntimeFixtureFault::$copy==='inventory'&&str_ends_with($to,'/inventory.json'))
                || (RuntimeFixtureFault::$copy==='receipt'&&str_ends_with($to,'/recovery.json')))) { return false; }
        return \rename($from,$to);
    }
    function copy(string $from,string $to): bool {
        if (RuntimeFixtureFault::$copy==='binary_backup' && str_contains($to,'asterisk.ucmp-old-')) { return false; }
        if (RuntimeFixtureFault::$copy==='module_backup' && str_contains($to,'.modules.ucmp-old-')) { return false; }
        return \copy($from,$to);
    }
}
namespace {
require_once dirname(__DIR__) . '/lib/AsteriskVersionManager.php';
require_once dirname(__DIR__) . '/lib/PatchManagerHelper.php';
use FreePBX\modules\Ucmprovisioner\AsteriskVersionManager;
use FreePBX\modules\Ucmprovisioner\PatchManagerHelper;
use FreePBX\modules\Ucmprovisioner\RuntimeFixtureFault;
$expect=static function(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$manifestMethod=new ReflectionMethod(AsteriskVersionManager::class,'runtimeManifest');
$hashMethod=new ReflectionMethod(AsteriskVersionManager::class,'manifestHash');
$root=str_replace('\\','/',sys_get_temp_dir()) . '/ucmp-activation-fixture-' . bin2hex(random_bytes(6));
foreach (['binary_backup','module_backup','stop','start','restore_start','inventory','receipt','success'] as $scenario) {
    RuntimeFixtureFault::$afterStart=false;
    $completedScenario=in_array($scenario,['success','inventory','receipt'],true);
    $base=$root . '/' . $scenario; $runtime=$base . '/live'; $state=$base . '/state'; $bundles=$base . '/bundles'; $incoming=$base . '/incoming';
    foreach ([$runtime . '/usr/sbin',$runtime . '/usr/lib/asterisk/modules',$state,$bundles,$incoming] as $directory) { mkdir($directory,0770,true); }
    file_put_contents($runtime . '/usr/sbin/asterisk','20.19.0');
    file_put_contents($runtime . '/usr/lib/asterisk/modules/chan_sip.so','old-module');
    file_put_contents($runtime . '/usr/lib/libasteriskssl.so.1','old-library');
    $commands=[]; $starts=0;
    $runner=static function(array $command) use ($runtime,$scenario,&$commands,&$starts): array {
        $commands[]=$command;
        if (($command[0] ?? '')==='/usr/bin/tar' && in_array('--list',$command,true)) { return ['code'=>0,'stdout'=>"-rw-r--r-- 0/0 100 2026-01-01 00:00 manifest.json\n-rwxr-xr-x 0/0 100 2026-01-01 00:00 runtime/usr/sbin/asterisk\n",'stderr'=>'']; }
        if (($command[0] ?? '')==='/usr/bin/tar' && in_array('--create',$command,true)) { file_put_contents($command[array_search('--file',$command,true)+1],'fixture-archive'); }
        if (($command[0] ?? '')==='/usr/bin/tar' && in_array('--extract',$command,true)) {
            $target=$command[array_search('--directory',$command,true)+1] . '/runtime';
            mkdir($target . '/usr/sbin',0770,true); mkdir($target . '/usr/lib/asterisk/modules',0770,true);
            $old = str_contains($command[array_search('--file',$command,true)+1],'20.19.0');
            file_put_contents($target . '/usr/sbin/asterisk',$old?'20.19.0':'20.20.0');
            file_put_contents($target . '/usr/lib/asterisk/modules/chan_sip.so',$old?'old-module':'new-module');
            file_put_contents($target . '/usr/lib/libasteriskssl.so.1',$old?'old-library':'new-library');
        }
        if (in_array('/usr/sbin/fwconsole',$command,true)) {
            $action=end($command);
            if ($action==='start') { $starts++; RuntimeFixtureFault::$afterStart=true; }
            if (($scenario==='stop' && $action==='stop') || ($scenario==='start' && $action==='start' && $starts===1)
                || ($scenario==='restore_start' && $action==='start')) { return ['code'=>1,'stdout'=>'','stderr'=>'fixture failure']; }
        }
        $last=end($command); $out='';
        if ($last==='core show version' || $last==='-V') { $out='Asterisk ' . file_get_contents($runtime . '/usr/sbin/asterisk'); }
        if ($last==='sip show settings') { $out='Global Settings:'; }
        if ($last==='core show settings') { $out='Module directory: ' . $runtime . '/usr/lib/asterisk/modules'; }
        return ['code'=>0,'stdout'=>$out,'stderr'=>''];
    };
    $manager=new AsteriskVersionManager($runner,$state,$bundles,$incoming,$runtime);
    $oldManifest=$manifestMethod->invoke($manager,$runtime,$runtime . '/usr/lib/asterisk/modules');
    $oldHash=$hashMethod->invoke($manager,$oldManifest);
    $newManifest=$oldManifest;
    foreach (['usr/sbin/asterisk'=>'20.20.0','usr/lib/asterisk/modules/chan_sip.so'=>'new-module','usr/lib/libasteriskssl.so.1'=>'new-library'] as $path=>$bytes) { $newManifest[$path]=['sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes)]; }
    ksort($newManifest,SORT_STRING); $newHash=$hashMethod->invoke($manager,$newManifest);
    foreach ([['old','20.19.0',$oldHash],['new','20.20.0',$newHash]] as [$label,$version,$hash]) {
        $id='asterisk-' . $version . '-' . substr($hash,0,24);
        if ($label==='new') { $newId=$id; } else { $oldId=$id; }
        file_put_contents($bundles . '/' . $id . '.tar.gz','fixture-archive');
        file_put_contents($bundles . '/' . $id . '.json',json_encode(['bundle_id'=>$id,'version'=>$version,'binary_sha256'=>hash('sha256',$version),'runtime_sha256'=>$hash,'archive_sha256'=>hash('sha256','fixture-archive')]));
    }
    file_put_contents($state . '/state.json',json_encode(['active_bundle_id'=>$oldId,'history'=>[],'sentinel'=>'keep']));
    $beforeState=file_get_contents($state . '/state.json');
    $status=$manager->status();
    $expect($status['active_bundle_id']===$oldId && file_get_contents($state . '/state.json')===$beforeState,'Status overwrote durable transaction state or misidentified full runtime');
    $capture = $manager->handle(['op'=>'capture']);
    $expect(!empty($capture['reused']) && $capture['bundle']['bundle_id']===$oldId,'Unchanged capture must explicitly reuse the complete matching bundle');
    if ($scenario==='success') {
        $alias=$runtime . '/usr/lib/libasteriskssl.so';
        if (@symlink('libasteriskssl.so.1',$alias)) {
            $expect($manager->status()['active_bundle_id']===$oldId,'A normal SONAME alias must not invalidate the captured runtime');
            unlink($alias);
            symlink($state . '/state.json',$alias);
            try { $manager->status(); throw new \LogicException('Escaping alias was accepted'); }
            catch (\RuntimeException $expected) { $expect(str_contains($expected->getMessage(),'outside'),'Escaping alias failed for wrong reason'); }
            unlink($alias);
            symlink('libasteriskssl.so.1',$alias);
        } elseif (PHP_OS_FAMILY==='Linux') { throw new \RuntimeException('Linux alias fixture could not be created'); }
        $legacyId='asterisk-20.19.0-legacyduplicate';
        $legacy=json_decode(file_get_contents($bundles . '/' . $oldId . '.json'),true);unset($legacy['runtime_sha256']);$legacy['bundle_id']=$legacyId;
        file_put_contents($bundles . '/' . $legacyId . '.tar.gz','fixture-archive');
        file_put_contents($bundles . '/' . $legacyId . '.json',json_encode($legacy));
        $stateData=json_decode(file_get_contents($state . '/state.json'),true);$stateData['pinned_bundle_id']=$legacyId;file_put_contents($state . '/state.json',json_encode($stateData));
        $manager->handle(['op'=>'delete','bundle_id'=>$legacyId]);
        $stateData=json_decode(file_get_contents($state . '/state.json'),true);
        $expect($stateData['pinned_bundle_id']===$oldId && !file_exists($bundles . '/' . $legacyId . '.json'),'Exact legacy duplicate deletion must retain the pin on the verified active payload');
        $expect(!array_filter($commands,static fn(array $c):bool=>(in_array('/usr/bin/apt-mark',$c,true)&&in_array($c[1]??'', ['hold','unhold'],true))||in_array('/usr/sbin/fwconsole',$c,true)),'Duplicate deletion changed package policy or runtime');
        try { $manager->handle(['op'=>'delete','bundle_id'=>$oldId]); throw new \LogicException('Active bundle deletion accepted'); }
        catch (\RuntimeException $expected) { $expect(str_contains($expected->getMessage(),'active'),'Active deletion failed for wrong reason'); }
        $stateData['pinned_bundle_id']='';file_put_contents($state . '/state.json',json_encode($stateData));
    }
    // A module-only change must break active identity even with the same binary.
    file_put_contents($runtime . '/usr/lib/asterisk/modules/chan_sip.so','changed-module');
    $expect($manager->status()['active_bundle_id']==='','Binary-only equality still marks a modified runtime active');
    file_put_contents($runtime . '/usr/lib/asterisk/modules/chan_sip.so','old-module');
    RuntimeFixtureFault::$copy=$scenario;
    $helper=new PatchManagerHelper($manager,$base . '/manager.lock',false);
    try {
        $result=$helper->handle(['op'=>'activate','bundle_id'=>$newId,'confirmation'=>'ACTIVATE ' . $newId]);
        $expect($completedScenario && $result['ok'],'Injected activation fault unexpectedly succeeded');
        if ($scenario!=='success') { $expect(!empty($result['reporting_error'])&&$starts===1,'Post-verification reporting failure must keep the known activated runtime without a second restart'); }
    } catch (RuntimeException $error) {
        $expect(!$completedScenario,'Successful activation failed: ' . $error->getMessage());
        if ($scenario==='restore_start') { $expect(str_contains($error->getMessage(),'restoration needs review'),'Failed restart did not report recovery required'); }
    }
    if (!$completedScenario) {
        $expect(file_get_contents($runtime . '/usr/sbin/asterisk')==='20.19.0' && file_get_contents($runtime . '/usr/lib/asterisk/modules/chan_sip.so')==='old-module'
            && file_get_contents($runtime . '/usr/lib/libasteriskssl.so.1')==='old-library','Failure lost original runtime bytes');
    }
    if (in_array($scenario,['binary_backup','module_backup'],true)) {
        $expect(!array_filter($commands,static fn(array $command): bool=>in_array('/usr/sbin/fwconsole',$command,true)),'Backup failure stopped the runtime');
    } else {
        $receipts=glob($state . '/.activate-*/recovery.json');
        $expect(count($receipts)===1,'Activation recovery evidence was removed');
        $receipt=json_decode(file_get_contents($receipts[0]),true);
        $expected=$scenario==='receipt'?'replace_intent':($completedScenario ? 'verified' : ($scenario==='restore_start' ? 'recovery_required' : 'rolled_back'));
        $expect($receipt['status']===$expected && is_file($receipt['binary_backup']) && is_dir($receipt['module_backup']),'Activation phase status or retained backups are incorrect');
    }
    RuntimeFixtureFault::$copy='';
    if ($scenario==='success') {
        $count=count($manager->status()['bundles']);
        file_put_contents($runtime . '/usr/lib/asterisk/modules/chan_sip.so','newly changed module');
        $captured=$manager->handle(['op'=>'capture']);
        $expect(empty($captured['reused']) && count($manager->status()['bundles'])===$count+1,'Changed runtime must create a new complete capture');
        $expect($manager->status()['active_bundle_id']===$captured['bundle']['bundle_id'],'New capture must match live runtime including real libraries');
        $incomingId=str_repeat('a',32);
        file_put_contents($incoming . '/' . $incomingId . '.upload',"\x7fELFsynthetic-import");
        $importRunner=static function(array $command) use ($runner): array {
            if (($command[0] ?? '')==='/usr/bin/file') { return ['code'=>0,'stdout'=>'ELF 64-bit x86-64','stderr'=>'']; }
            return $runner($command);
        };
        $importer=new AsteriskVersionManager($importRunner,$state,$bundles,$incoming,$runtime);
        $imported=$importer->handle(['op'=>'import','incoming_id'=>$incomingId,'declared_version'=>'20.20.0']);
        $payload=$imported['bundle']['runtime_manifest'];
        $expect(isset($payload['usr/lib/libasteriskssl.so.1'])
            && $payload['usr/lib/libasteriskssl.so.1']['sha256']===hash_file('sha256',$runtime . '/usr/lib/libasteriskssl.so.1'),
            'Raw executable import must retain the installed shared-library payload used by activation');
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } } rmdir($root);
echo "Asterisk full-runtime identity, observational status and activation/compensation fault assertions passed\n";
}
