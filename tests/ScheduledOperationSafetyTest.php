<?php
declare(strict_types=1);

interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/RpcOperationException.php';

use FreePBX\modules\Ucmprovisioner;
use FreePBX\modules\Ucmprovisioner\ServiceFilePermissions;

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$root = sys_get_temp_dir() . '/ucmp-schedule-' . bin2hex(random_bytes(6));
// Run the actual methods, changing only their fixed storage root. All external
// PBX and phone actions below are isolated fixture methods.
$lines = file(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$methods = '';
foreach (['runScheduledPhoneRebuild','runScheduledPublishMesh','saveSettingsLocal','saveSettingsWithScheduleLock'] as $name) {
    $method = new ReflectionMethod(Ucmprovisioner::class, $name);
    $methods .= implode('', array_slice($lines, $method->getStartLine()-1, $method->getEndLine()-$method->getStartLine()+1));
}
$methods = str_replace('/var/lib/asterisk/ucmprovisioner', str_replace('\\','/',$root), $methods);
$fixture = eval('return new class {' . $methods . <<<'PHP'
    public array $settings = [];
    public string $root = '';
    public string $prefix = '';
    public string $lockName = '';
    public string $failure = '';
    public int $applies = 0;
    public int $commands = 0;
    public int $saves = 0;
    public $duringApply = null;
    private function withConfigurationSyncLock(callable $operation):mixed{return $operation();}
    public function localSiteId(): int { return 1; }
    public function getSetting(string $key, int $siteId, mixed $default = ''): mixed {
        $probe = \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock($this->root . '/' . $this->lockName . '.lock');
        if (is_resource($probe)) { flock($probe,LOCK_UN);fclose($probe);throw new LogicException('Schedule state read before acquiring its lock'); }
        return $this->settings[$key] ?? $default;
    }
    public function setSetting(string $key, mixed $value, int $siteId): void {
        if ($this->failure === 'claim' && str_ends_with($key,'_next_run')) { throw new RuntimeException('Fixture claim write failed'); }
        $this->settings[$key] = $value;
    }
    private function saveSettingsLocalLocked(array $data): void { $this->saves++; }
    private function asteriskConfigurationWriteInProgress(): bool { return false; }
    public function logOperation(mixed ...$args): void {
        if ($this->failure === 'history') { throw new RuntimeException('Fixture history failed'); }
    }
    private function apply(): void {
        if ((int)($this->settings[$this->prefix . '_next_run'] ?? 0) <= time()) { throw new LogicException('External action started before consuming its due slot'); }
        $this->applies++;
        if ($this->duringApply) { ($this->duringApply)($this); }
        if ($this->failure === 'apply') { throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Fixture apply reply lost',['transaction_id'=>'fixture-accepted']); }
    }
    public function rebuild(mixed $selection, bool $apply = false, mixed ...$args): array {
        if (!$apply) { return ['ok'=>true,'plan_hash'=>'fixture-plan']; }
        $this->apply();
        return ['ok'=>true,'message'=>'Fixture build completed','phones'=>array_map(static fn(int $id):array=>['id'=>$id,'family'=>'cisco_xml'],range(1,201))];
    }
    public function syncPublishMesh(bool $apply = false, mixed ...$args): array {
        if (!$apply) { return ['ok'=>true,'plan_hash'=>'fixture-plan','participants'=>[['changed'=>true]]]; }
        $this->apply();
        return ['ok'=>true,'message'=>'Fixture mesh completed','participants'=>[['status'=>'committed']]];
    }
    public function sendPhoneCommands(array $ids, string $command, int $site): array {
        $this->commands++;
        if ($this->failure === 'commands' && $this->commands === 2) { throw new RuntimeException('Fixture second batch reply lost'); }
        return ['ok'=>$this->failure !== 'command_failure','failed'=>$this->failure === 'command_failure' ? 1 : 0,'count'=>count($ids)];
    }
};
PHP);
$fixture->root = $root;
try {
    foreach (['phone_rebuild'=>['runScheduledPhoneRebuild','phone-rebuild-scheduler'],'blf_mesh'=>['runScheduledPublishMesh','mesh-scheduler']] as $prefix=>[$runner,$lockName]) {
        $fixture->prefix=$prefix; $fixture->lockName=$lockName;
        foreach (['history','apply','claim'] as $failure) {
            $fixture->settings=[$prefix . '_schedule_enabled'=>'1',$prefix . '_next_run'=>time()-1,
                $prefix . '_schedule_json'=>'{"mode":"interval","interval_minutes":60}'];
            $fixture->failure=$failure; $fixture->applies=0;
            $fixture->duringApply=static function ($subject) use ($expect,$prefix): void {
                try { $subject->saveSettingsLocal(['settings'=>[$prefix . '_schedule_enabled'=>'0']]); throw new LogicException('Concurrent schedule edit accepted'); }
                catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'schedule is running'),'Wrong schedule edit failure'); }
            };
            try {
                $result=$fixture->$runner();
                $expect($failure !== 'claim','Unconfirmed claim allowed an operation');
            } catch (RuntimeException $error) {
                $expect($failure === 'claim' && str_contains($error->getMessage(),'claim write failed') && $fixture->applies===0,'Claim failure must stop before external actions');
                continue;
            }
            $expect($fixture->applies===1 && $fixture->saves===0,'Scheduled operation or rejected edit was repeated');
            if ($failure === 'history') { $expect(!empty($result['ok']) && !empty($result['reporting_error']),'Late history error must retain the completed operation'); }
            else { $expect($result['status']==='unknown' && ($result['operation_receipt']['transaction_id'] ?? '')==='fixture-accepted','Lost reply must retain its destination receipt'); }
            $fixture->failure=''; $next=$fixture->$runner();
            $expect(!empty($next['skipped']) && $fixture->applies===1,'The next tick must not replay a consumed slot');
        }
        $fixture->failure='';
        $fixture->settings=[$prefix . '_schedule_enabled'=>'0'];
        $expect(!empty($fixture->$runner()['skipped']),'Disabled schedule must remain inactive');
    }
    $fixture->prefix='phone_rebuild';$fixture->lockName='phone-rebuild-scheduler';$fixture->duringApply=null;
    foreach (['commands','command_failure'] as $failure) {
        $fixture->settings=['phone_rebuild_schedule_enabled'=>'1','phone_rebuild_next_run'=>time()-1,'phone_rebuild_reboot'=>'1'];
        $fixture->failure=$failure;$fixture->commands=0;
        $result=$fixture->runScheduledPhoneRebuild();
        $expect(empty($result['ok']) && count($result['phones'])===201,'Failed phone commands must retain build success details and report partial failure');
        if ($failure==='commands') { $expect(count($result['scheduled_reboots'])===1 && $result['failed_stage']==='phone_commands','A later lost command batch must retain earlier batch results'); }
    }
    $fixture->failure='';$fixture->saveSettingsLocal(['settings'=>['voicemail_number'=>'100']]);
    $expect($fixture->saves===1,'Unrelated settings must use the normal save path');
    foreach (['cli_phone_rebuild.php','cli_mesh_sync.php'] as $entrypoint) {
        $source=(string)file_get_contents(dirname(__DIR__) . '/' . $entrypoint);
        $bootstrap=<<<'PHP'
class FreePBX {
    public static function Ucmprovisioner(): object {
        return new class {
            public function __call(string $name, array $args): array {
                return ['ok'=>true,'message'=>'Fixture operation completed','reporting_error'=>'Fixture history unavailable'];
            }
        };
    }
}
PHP;
        $source=str_replace("require_once '/etc/freepbx.conf';",$bootstrap,$source,$replaced);
        $expect($replaced===1,'CLI fixture must replace exactly the production bootstrap');
        $process=proc_open([PHP_BINARY,'-r',substr($source,5)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $expect(is_resource($process),'Could not launch isolated CLI fixture');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $expect(proc_close($process)===1 && $out==='' && str_contains($err,'Fixture operation completed') && str_contains($err,'Fixture history unavailable'),
            'CLI must expose the completed outcome and separate history failure');
    }
    $atomicMethod = new ReflectionMethod(Ucmprovisioner::class, 'configurationSyncTransaction');
    $atomicSource = implode('', array_slice($lines,$atomicMethod->getStartLine()-1,$atomicMethod->getEndLine()-$atomicMethod->getStartLine()+1));
    $atomicSource = str_replace(['/var/lib/asterisk/ucmprovisioner','private function configurationSyncTransaction'], [str_replace('\\','/',$root),'public function configurationSyncTransaction'], $atomicSource);
    $atomic = eval('return new class { public $db; private array $configurationSyncAfterCommit=[]; private array $configurationSyncScheduleLocks=[];' . $atomicSource . '};');
    $atomic->db = new class extends PDO {
        public $duringCommit;
        public function __construct() { parent::__construct('sqlite::memory:'); }
        public function commit(): bool { ($this->duringCommit)(); return parent::commit(); }
    };
    foreach (['settings_blf_federation'=>'mesh-scheduler','settings_phone_automation'=>'phone-rebuild-scheduler'] as $scope=>$file) {
        $assertHeld = static function () use ($root,$file,$expect): void {
            $other = ServiceFilePermissions::lock($root . '/' . $file . '.lock');
            if (is_resource($other)) { flock($other,LOCK_UN);fclose($other); }
            $expect($other===false,'The category must retain scheduler exclusion through commit');
        };
        $atomic->db->duringCommit = $assertHeld;
        $atomic->configurationSyncTransaction(static function () use ($assertHeld): array { $assertHeld(); return ['changed'=>false]; },$scope);
        $released=ServiceFilePermissions::lock($root . '/' . $file . '.lock');
        $expect(is_resource($released),'The schedule lock must be released after a confirmed commit');flock($released,LOCK_UN);fclose($released);
    }
    echo "Scheduled state exclusion, atomic category commit, consumed slots, lost results and retained history fixtures passed\n";
} finally {
    foreach (['phone-rebuild-scheduler','mesh-scheduler'] as $name) { @unlink($root . '/' . $name . '.lock'); }
    @rmdir($root);
}
