<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/PatchCatalog.php';
require_once $root . '/lib/AsteriskVersionManager.php';
require_once $root . '/lib/PatchManagerHelper.php';

use FreePBX\modules\Ucmprovisioner\AsteriskVersionManager;
use FreePBX\modules\Ucmprovisioner\PatchCatalog;
use FreePBX\modules\Ucmprovisioner\PatchManagerHelper;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$entries = PatchCatalog::entries();
$expect(count($entries) === 4, 'Catalog must expose the last four compatible Asterisk 20 patches');
$expect(count(array_unique(array_column($entries, 'catalog_id'))) === 4, 'Catalog IDs must be unique');
$expect(array_column($entries, 'asterisk_version') === ['20.20.0','20.19.0','20.18.0','20.16.0'], 'Catalog order must be newest compatible first');
foreach ($entries as $entry) {
    $expect(!empty($entry['operator_selectable']) && !empty($entry['production_installable']), 'Compatible older releases must remain selectable');
    $expect((bool)preg_match('/^[a-f0-9]{64}$/D', $entry['source_sha256']), 'Every source hash must be exact');
    $expect((bool)preg_match('/^[a-f0-9]{64}$/D', $entry['patch_sha256']), 'Every patch hash must be exact');
}
$expect(PatchCatalog::forVersion('20.19.0')['catalog_id'] === 'asterisk-20.19.0-ucm-e59678c3', 'Exact version lookup failed');
$expect(PatchCatalog::forVersion('20.19.1') === null, 'Nearby versions must never inherit a patch');

$commands = [];
$runner = static function (array $command) use (&$commands): array {
    $commands[] = $command;
    if ($command === ['/usr/bin/nproc']) { return ['code'=>0,'stdout'=>"8\n",'stderr'=>'']; }
    return ['code'=>0,'stdout'=>'','stderr'=>''];
};
$stateRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ucmp-manager-test-' . bin2hex(random_bytes(6));
$bundleRoot = $stateRoot . DIRECTORY_SEPARATOR . 'bundles';
$incomingRoot = $stateRoot . DIRECTORY_SEPARATOR . 'incoming';
$manager = new AsteriskVersionManager($runner, $stateRoot, $bundleRoot, $incomingRoot);
$helper = new PatchManagerHelper($manager, $stateRoot . DIRECTORY_SEPARATOR . 'manager.lock', false);
$archiveCheck=new ReflectionMethod($manager,'assertSafeArchive');
$regular='-rw-r--r-- 0/0 100 2026-01-01 00:00 ';
$archiveCheck->invoke($manager,$regular."manifest.json\ndrwxr-xr-x 0/0 0 2026-01-01 00:00 runtime/\n".$regular."runtime/usr/sbin/asterisk\n");
foreach ([
    'lrwxrwxrwx 0/0 0 2026-01-01 00:00 runtime/link -> /outside',
    'hrw-r--r-- 0/0 0 2026-01-01 00:00 runtime/hardlink link to /outside',
    'prw-r--r-- 0/0 0 2026-01-01 00:00 runtime/pipe',
    $regular.'runtime/../outside',$regular.'manifest.json.extra',$regular.'runtime/a\\nname',
    $regular."runtime/duplicate\n".$regular.'runtime/duplicate',
    '-rw-r--r-- 0/0 536870913 2026-01-01 00:00 runtime/oversized',
] as $unsafe) {
    $rejected=false;try{$archiveCheck->invoke($manager,$unsafe);}catch(RuntimeException){$rejected=true;}
    $expect($rejected,'Unsafe archive must fail before extraction: '.$unsafe);
}

$preview = $helper->handle([
    'op'=>'preview',
    'catalog_id'=>'asterisk-20.19.0-ucm-e59678c3',
    'high_strength_srtp'=>'1',
    'jobs'=>'4',
]);
$previewText = implode("\n", $preview['commands'] ?? []);
$expect(str_contains($previewText, 'patch --strip=1'), 'Preview must show the documented patch command');
$expect(str_contains($previewText, './configure --with-jansson-bundled'), 'Preview must show the FreePBX 17 configure preset');
$expect(str_contains($previewText, 'ENABLE_SRTP_AES_256') && str_contains($previewText, 'ENABLE_SRTP_AES_GCM'), 'Optional SRTP commands are missing');
$expect(str_contains($previewText, 'make -j4 all'), 'Preview must show the selected parallel build');
$expect(!str_contains($previewText, 'make samples'), 'Manager must not overwrite FreePBX with sample configuration');

if (!is_dir($stateRoot)) { mkdir($stateRoot,0700,true); }
file_put_contents($stateRoot.'/state.json','{"pinned_bundle_id":');
$stateRead=new ReflectionMethod($manager,'readState');
$rejected=false;try{$stateRead->invoke($manager);}catch(RuntimeException){$rejected=true;}
$expect($rejected,'Corrupt durable state must not silently remove the pin or history');
unlink($stateRoot.'/state.json');

if (PHP_OS_FAMILY==='Linux') {
    $native=new AsteriskVersionManager();$nativeRun=new ReflectionMethod($native,'run');
    $both=$nativeRun->invoke($native,[PHP_BINARY,'-r','fwrite(STDERR,str_repeat("e",60000));fwrite(STDOUT,"completed");']);
    $expect($both['code']===0&&$both['stdout']==='completed'&&strlen($both['stderr'])===60000,
        'Native commands must drain a full stderr stream while stdout is still open');
    file_put_contents($stateRoot.'/manifest.json','{}');mkdir($stateRoot.'/runtime');
    $archive=$stateRoot.'/fixture.tar.gz';
    $created=$nativeRun->invoke($native,['/usr/bin/tar','--create','--gzip','--file',$archive,'--directory',$stateRoot,'manifest.json','runtime']);
    $expect($created['code']===0,'Real GNU tar fixture creation failed');
    $listed=$nativeRun->invoke($native,['/usr/bin/tar','--list','--verbose','--numeric-owner','--quoting-style=escape','--gzip','--file',$archive]);
    $expect($listed['code']===0,'Real GNU tar verbose fixture failed');
    $archiveCheck->invoke($manager,$listed['stdout']);
    unlink($archive);unlink($stateRoot.'/manifest.json');rmdir($stateRoot.'/runtime');
}

try {
    $helper->handle([
        'op'=>'preview',
        'catalog_id'=>'asterisk-20.19.0-ucm-e59678c3',
        'url'=>'https://attacker.invalid/file',
    ]);
    throw new RuntimeException('Operator URL was accepted');
} catch (InvalidArgumentException $e) {
    $expect(str_contains($e->getMessage(), 'Unknown fields'), 'Unknown field failed for the wrong reason');
}
try {
    $helper->handle(['op'=>'activate','bundle_id'=>'asterisk-20.19.0-deadbeef0000','confirmation'=>'yes']);
    throw new RuntimeException('Activation without exact confirmation was accepted');
} catch (RuntimeException $e) {
    $expect(str_contains($e->getMessage(), 'exact transaction confirmation'), 'Activation confirmation failed for the wrong reason');
}

$helperSource = (string)file_get_contents($root . '/lib/PatchManagerHelper.php');
$managerSource = (string)file_get_contents($root . '/lib/AsteriskVersionManager.php');
$clientSource = (string)file_get_contents($root . '/lib/PatchWorkflowService.php');
$entrySource = (string)file_get_contents($root . '/resources/ucmp-patch-manager');
$moduleSource = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$installSource = (string)file_get_contents($root . '/install.php');
$sudoersSource = trim((string)file_get_contents($root . '/resources/ucmp-patch-manager.sudoers'));
$expect(
    str_contains($helperSource, 'Unknown fields are not accepted by the Patch Manager helper')
    && str_contains($helperSource, 'LOCK_EX | LOCK_NB')
    && str_contains($helperSource, "'ACTIVATE '")
    && str_contains($helperSource, "'PIN '"),
    'Root helper schema, lock or typed confirmations are incomplete'
);
$expect(
    str_contains($managerSource, "'/usr/bin/apt-mark','hold','asterisk-version-switch'")
    && str_contains($managerSource, 'HISTORY_LIMIT = 3')
    && str_contains($managerSource, 'make install DESTDIR=<version-bundle-staging>')
    && str_contains($managerSource, 'Capture current installation') === false
    && !str_contains($managerSource, 'module show like chan_sip.so')
    && str_contains($managerSource, "'sip show settings'"),
    'Simple source-build, updater hold or three-version history implementation is missing'
);
$expect(
    str_contains($clientSource, "['/usr/bin/sudo','-n',self::HELPER]")
    && !str_contains($clientSource, 'shell_exec'),
    'Unprivileged client must use only the fixed no-argument helper'
);
$expect(str_contains($entrySource, '$argc !== 1'), 'Root entry point must reject command-line arguments');
$expect(
    str_contains($installSource, "'/usr/local/libexec/AsteriskVersionManager.php'")
    && str_contains($installSource, "'/usr/local/libexec/PatchManagerHelper.php'")
    && $sudoersSource === "# UCM Provisioner Patch Manager; bounded JSON on stdin and no command-line arguments.\nasterisk ALL=(root) NOPASSWD: /usr/local/sbin/ucmp-patch-manager \"\"",
    'Install hook must enroll the exact helper and sudoers rule'
);
$expect(
    preg_match('/public function remoteDispatch.*?\$allowed\s*=\s*\[(.*?)\];/s', $moduleSource, $allowlist) === 1
    && !str_contains($allowlist[1], 'patchWorkflowOp')
    && str_contains($allowlist[1], 'stageAsteriskBundleChunkLocal'),
    'Remote dispatch must allow bounded bundle staging but never privileged activation'
);

echo "Patch workflow selection, preview and security fixtures passed\n";
