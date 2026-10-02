<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\SshEnrollmentTransaction;
$bash=PHP_OS_FAMILY==='Windows'?'C:/Program Files/Git/bin/bash.exe':'/bin/bash';
if (!is_file($bash)) { throw new RuntimeException('Bash is required for executable SSH staging fixtures'); }
$root=str_replace('\\','/',sys_get_temp_dir()) . '/ucmp-ssh-fixture-' . bin2hex(random_bytes(8));
mkdir($root . '/bin',0700,true);
$shellRoot=PHP_OS_FAMILY==='Windows'?preg_replace('#^([A-Za-z]):/#','/$1/',$root):$root;
if (PHP_OS_FAMILY==='Windows') { $shellRoot='/' . strtolower($shellRoot[1]) . substr($shellRoot,2); }
$run=static function(string $script,array $env=[]) use($bash,$root,$shellRoot): array {
    $path=$root . '/run-' . bin2hex(random_bytes(4)) . '.sh';
    file_put_contents($path,"#!/bin/sh\nexport PATH=" . escapeshellarg($shellRoot . '/bin:/usr/bin:/bin') . "\n" . $script);
    $p=proc_open([$bash,$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,
        array_merge(getenv(),['PATH'=>$shellRoot . '/bin:/usr/bin:/bin'],$env));
    if (!is_resource($p)) { throw new RuntimeException('Could not start isolated SSH fixture'); }
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($p);
    return [$code,$out,$err];
};
file_put_contents($root . '/bin/sshd',"#!/bin/sh\ncase \"\$*\" in *candidate*) test \"\${FAIL_VALIDATE:-0}\" != 1;; *) exit 0;; esac\n");
file_put_contents($root . '/bin/systemctl',"#!/bin/sh\nif test \"\${FAIL_RELOAD:-0}\" = 1 && grep -q 'Match User ucmp-tftp' \"\$FIXTURE_CONFIG\"; then exit 1; fi\nexit 0\n");
copy($root . '/bin/systemctl',$root . '/bin/service');
// This fixture tests configuration ordering/compensation, not kernel locking.
file_put_contents($root . '/bin/flock',"#!/bin/sh\nexit 0\n");
file_put_contents($root . '/bin/install', <<<'SH'
#!/bin/sh
if test "${1:-}" = -d; then
  shift; mode=;
  while test $# -gt 0; do case "$1" in -o|-g) shift 2;; -m) mode=$2; shift 2;; --) shift; break;; *) path=$1; shift; break;; esac; done
  mkdir -p "$path"; test -z "$mode" || chmod "$mode" "$path"; exit $?
fi
mode=; src=; dest=;
while test $# -gt 0; do case "$1" in -o|-g) shift 2;; -m) mode=$2; shift 2;; --) shift;; *) if test -z "$src"; then src=$1; else dest=$1; fi; shift;; esac; done
cp "$src" "$dest"; test -z "$mode" || chmod "$mode" "$dest"
SH
);
file_put_contents($root . '/bin/chown',"#!/bin/sh\nexit 0\n");
file_put_contents($root . '/bin/stat', <<<'SH'
#!/bin/sh
if test "${1:-}" = -c; then
  path=$3; mode=$(/usr/bin/stat -c '%a' "$path") || exit $?
  printf '0:0:%s\n' "$mode"
else
  /usr/bin/stat "$@"
fi
SH
);
file_put_contents($root . '/bin/runuser', <<<'SH'
#!/bin/sh
test -z "${RUNUSER_CALLED:-}" || printf '%s\n' called > "$RUNUSER_CALLED"
test "${RUNUSER_FAIL:-0}" = 1 && exit 1
test "$1" = -u && test "$2" = ucmp-tftp && test "$3" = -- || exit 2
shift 3
exec "$@"
SH
);
foreach (glob($root . '/bin/*') as $file) { chmod($file,0755); }
foreach (['validation','reload','success','postcheck','conflict','config-conflict','key-conflict','fresh','read-failure','fresh-read-failure','hash-conflict'] as $scenario) {
    $dir=$root . '/' . $scenario;
    $hasDirectory=!in_array($scenario,['fresh','fresh-read-failure'],true);
    mkdir($dir,0700,true);
    if ($hasDirectory) { mkdir($dir . '/authorized_keys',0700,true); }
    file_put_contents($dir . '/sshd_config',"Port 2222\n");
    if ($hasDirectory) { file_put_contents($dir . '/authorized_keys/ucmp-tftp','old-fixture-key'); }
    $shellDir=$shellRoot . '/' . $scenario;
    $tx=SshEnrollmentTransaction::scripts(bin2hex(random_bytes(12)),"# ucmprovisioner: least-privilege SFTP-only access\nMatch User ucmp-tftp\n    ForceCommand internal-sftp -d /tftpboot\n    PermitTunnel no",$shellDir);
    $existing=$hasDirectory?'old-fixture-key':'';
    $keyScript=SshEnrollmentTransaction::authorizedKeysScript($scenario==='hash-conflict'?'wrong-fixture-key':$existing,'new-fixture-key');
    $script="set -eu\n" . $tx['setup'] . "\n" . $keyScript . "\n" . ($scenario==='config-conflict' ? "printf '%s\\n' 'Port 9922' > \"\$ssh_root/sshd_config\"\n" : '') . $tx['stage'] . $tx['finish'];
    $env=['FAIL_VALIDATE'=>$scenario==='validation'?'1':'0','FAIL_RELOAD'=>$scenario==='reload'?'1':'0','FIXTURE_CONFIG'=>$shellDir . '/sshd_config',
        'RUNUSER_FAIL'=>in_array($scenario,['read-failure','fresh-read-failure'],true)?'1':'0','RUNUSER_CALLED'=>$shellDir . '/runuser.called'];
    [$code,$out,$err]=$run($script,$env);
    $keyPath=$dir . '/authorized_keys/ucmp-tftp';
    $beforePath=$dir . '/ucmp-recovery-' . $tx['recovery_id'] . '/authorized_keys.directory.before';
    $beforeMode=is_file($beforePath) ? trim(file_get_contents($beforePath)) : null;
    if (in_array($scenario,['validation','reload','read-failure','fresh-read-failure','hash-conflict'],true)) {
        if ($code===0 || file_get_contents($dir . '/sshd_config')!=="Port 2222\n" || ($hasDirectory && file_get_contents($keyPath)!=='old-fixture-key') || (!$hasDirectory && is_dir($dir . '/authorized_keys')) || ($hasDirectory && $scenario!=='hash-conflict' && $beforeMode!=='0:0:700')) {
            throw new RuntimeException($scenario . ' failure did not retain/restore original SSH policy: ' . $err);
        }
        if ($scenario==='fresh-read-failure' && (is_dir($dir . '/authorized_keys') || is_file($keyPath))) { throw new RuntimeException('Fresh-host rollback retained a newly created authorized_keys tree: ' . $err); }
        if (in_array($scenario,['read-failure','fresh-read-failure'],true) && !is_file($dir . '/runuser.called')) { throw new RuntimeException('SFTP account readability check was not exercised: ' . $err); }
    } else {
        if ($scenario!=='config-conflict' && ($code!==0 || !str_contains(file_get_contents($dir . '/sshd_config'),'Match User ucmp-tftp') || !is_file($keyPath) || file_get_contents($keyPath)!=='new-fixture-key' || ($hasDirectory && $beforeMode!=='0:0:700'))) { throw new RuntimeException('Staged SSH activation failed: ' . $err); }
        if ($scenario==='conflict') { file_put_contents($dir . '/sshd_config',"Port 9922\n"); }
        if ($scenario==='key-conflict') { file_put_contents($keyPath,'external-fixture-key'); }
        if ($scenario==='config-conflict' && ($code===0 || file_get_contents($dir . '/sshd_config')!=="Port 9922\n" || file_get_contents($keyPath)!=='new-fixture-key')) { throw new RuntimeException('Stage overwrote independently changed SSH policy'); }
        if ($scenario!=='success') {
            [$rollback]=$run($tx['rollback'],$env);
            if ($scenario==='postcheck' && ($rollback!==0 || file_get_contents($dir . '/sshd_config')!=="Port 2222\n")) { throw new RuntimeException('Fresh SFTP failure could not roll back SSH policy'); }
            if ($scenario==='conflict' && ($rollback===0 || file_get_contents($dir . '/sshd_config')!=="Port 9922\n")) { throw new RuntimeException('Rollback overwrote independently changed SSH policy'); }
            if ($scenario==='config-conflict' && ($rollback===0 || file_get_contents($dir . '/sshd_config')!=="Port 9922\n" || file_get_contents($keyPath)!=='new-fixture-key')) { throw new RuntimeException('Rollback overwrote independently changed SSH policy'); }
            if ($scenario==='key-conflict' && ($rollback===0 || file_get_contents($keyPath)!=='external-fixture-key')) { throw new RuntimeException('Rollback overwrote independently changed authorized_keys'); }
        }
    }
    if (!is_file($dir . '/ucmp-recovery-' . $tx['recovery_id'] . '/sshd_config.before')) { throw new RuntimeException('Rollback evidence unavailable in ' . $scenario . ': ' . $err . $out); }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
    if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
}
rmdir($root);
echo "Staged SSH validation, reload failure, post-check rollback and conflict preservation fixtures passed\n";
