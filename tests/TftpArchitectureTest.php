<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use FreePBX\modules\Ucmprovisioner\SiteConnection;
use FreePBX\modules\Ucmprovisioner\TftpArchitectureService;

final class ArchitectureSftpStub
{
    public ?\Closure $beforePut = null;
    /** @param array<string,string> $files @param array<int,string> $directories */
    public function __construct(public array $files, public array $directories = ['/tftpboot']) {}
    public function is_dir(string $path): bool { return in_array(rtrim($path, '/') ?: '/', $this->directories, true); }
    public function is_link(string $path): bool { return false; }
    public function nlist(string $directory): array|false
    {
        $directory = rtrim($directory, '/') ?: '/';
        if (!$this->is_dir($directory)) { return false; }
        $prefix = $directory === '/' ? '/' : $directory . '/';
        $names = ['.', '..'];
        foreach (array_merge(array_keys($this->files), $this->directories) as $path) {
            if (!str_starts_with($path, $prefix) || $path === $directory) { continue; }
            $tail = substr($path, strlen($prefix));
            if ($tail !== '' && !str_contains($tail, '/')) { $names[] = $tail; }
        }
        return array_values(array_unique($names));
    }
    public function stat(string $path): array|false
    {
        if ($this->is_dir($path)) { return ['size'=>0, 'mtime'=>1]; }
        return isset($this->files[$path]) ? ['size'=>strlen($this->files[$path]), 'mtime'=>1] : false;
    }
    public function file_exists(string $path): bool { return isset($this->files[$path]) || $this->is_dir($path); }
    public function get(string $remote, $local = false)
    {
        if (!isset($this->files[$remote])) { return false; }
        if (is_callable($local)) { $local($this->files[$remote]); return true; }
        return $local === false ? $this->files[$remote] : file_put_contents($local, $this->files[$remote]) !== false;
    }
    public function put(string $remote, string $local, int $mode = 2): bool
    {
        if ($this->beforePut) { ($this->beforePut)($remote); }
        $value = $mode === 1 ? file_get_contents($local) : $local;
        if ($value === false) { return false; }
        $this->files[$remote] = $value;
        return true;
    }
    public function mkdir(string $path, int $mode, bool $recursive): bool
    {
        if (!$recursive && $this->is_dir($path)) { return false; }
        $parts = explode('/', trim($path, '/'));
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            if (!in_array($current, $this->directories, true)) { $this->directories[] = $current; }
        }
        return true;
    }
    public function chmod(int $mode, string $path): bool { return $this->is_dir($path) || isset($this->files[$path]); }
    public function delete(string $path): bool { if (!isset($this->files[$path])) { return false; } unset($this->files[$path]); return true; }
    public function rmdir(string $path): bool { if (!$this->is_dir($path)) { return false; } $this->directories = array_values(array_diff($this->directories,[$path])); return true; }
}

final class ArchitectureConnectionStub
{
    public function __construct(public ArchitectureSftpStub $docker, public ArchitectureSftpStub $pbx) {}
    public function tftpSftp(?string $mode = null): ArchitectureSftpStub { return $mode === 'pbx' ? $this->pbx : $this->docker; }
    public function tftpRoot(?string $mode = null): string { return '/tftpboot'; }
    public function dockerSftp(): ArchitectureSftpStub { return $this->docker; }
}

final class ArchitectureModuleStub
{
    public int $resets = 0;
    public bool $throwLog = false;
    public array $logs = [];
    public function __construct(private PDO $db, private ArchitectureConnectionStub $connection) {}
    public function getSite(int $id, bool $secrets = false): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_sites WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function siteConnection(int $id): ArchitectureConnectionStub { return $this->connection; }
    public function isSiteLocal(int $id): bool { return true; }
    public function getDb(): PDO { return $this->db; }
    public function resetSiteConnection(int $id): void { $this->resets++; }
    public function logOperation(?int $siteId, string $operation, string $level, string $message, string $detail = ''): void
    { if ($this->throwLog) { throw new RuntimeException('Fixture audit log unavailable'); } $this->logs[] = compact('siteId','operation','level','message','detail'); }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(SiteConnection::activeTftpMode([]) === 'docker', 'Upgrade fallback must remain Docker');
$expect(SiteConnection::activeTftpMode(['tftp_mode'=>'pbx']) === 'pbx', 'PBX mode did not resolve');
$expect(SiteConnection::resolvedTftpHost(['tftp_mode'=>'pbx','pbx_tftp_service_host'=>'192.0.2.5','pbx_addr'=>'192.0.2.9']) === '192.0.2.5', 'PBX phone-facing host did not resolve');
$expect(SiteConnection::resolvedTftpHost(['tftp_mode'=>'docker','tftp_service_host'=>'192.0.2.6','docker_host'=>'192.0.2.10']) === '192.0.2.6', 'Docker phone-facing host did not resolve');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ucmp_sites (id INTEGER PRIMARY KEY,name TEXT,tftp_mode TEXT,tftp_pending_mode TEXT,docker_bootstrapped_at INTEGER,pbx_tftp_bootstrapped_at INTEGER)');
$db->exec("INSERT INTO ucmp_sites VALUES (1,'SITE-A','docker','pbx',1,1)");
$source = new ArchitectureSftpStub([
    '/tftpboot/SEP001122334455.cnf.xml'=>'phone-config',
    '/tftpboot/Firmware/load.bin'=>'firmware',
    '/tftpboot/.ucmprovisioner/state.json'=>'private-state',
], ['/tftpboot','/tftpboot/Firmware','/tftpboot/.ucmprovisioner']);
$target = new ArchitectureSftpStub(['/tftpboot/target-only.txt'=>'preserved']);
$connection = new ArchitectureConnectionStub($source, $target);
$module = new ArchitectureModuleStub($db, $connection);
$service = new TftpArchitectureService($module);

$preview = $service->preview(1);
$expect($preview['files'] === 3 && $preview['target_only_count'] === 1, 'Cutover preview inventory is incomplete');
$expect(preg_match('/^[a-f0-9]{64}$/D', (string)$preview['plan_hash']) === 1, 'Cutover plan hash is invalid');
$result = $service->apply(1, (string)$preview['plan_hash']);
$expect(!empty($result['ok']) && $result['active_mode'] === 'pbx', 'Verified target was not activated');
$expect($target->files['/tftpboot/Firmware/load.bin'] === 'firmware', 'Firmware was not copied');
$expect($target->files['/tftpboot/.ucmprovisioner/state.json'] === 'private-state', 'Private transaction state was not copied');
$expect($source->files['/tftpboot/SEP001122334455.cnf.xml'] === 'phone-config', 'Source tree was modified');
$expect($target->files['/tftpboot/target-only.txt'] === 'preserved', 'Target-only file was unexpectedly deleted');
$row = $db->query('SELECT tftp_mode,tftp_pending_mode FROM ucmp_sites WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$expect($row['tftp_mode'] === 'pbx' && $row['tftp_pending_mode'] === null, 'Site architecture did not activate atomically');
$expect($module->resets === 1 && count($module->logs) === 1, 'Connection/log finalization did not run');

foreach (['equal_metadata_change','source_lock','target_lock','changed_during_copy','uncertain_activation','post_activation_log'] as $scenario) {
    $db->exec("UPDATE ucmp_sites SET tftp_mode='docker',tftp_pending_mode='pbx' WHERE id=1");
    $source = new ArchitectureSftpStub(['/tftpboot/phone.cfg'=>'old']);
    $target = new ArchitectureSftpStub([]);
    $module = new ArchitectureModuleStub($db,new ArchitectureConnectionStub($source,$target));
    $service = new TftpArchitectureService($module);
    $preview = $service->preview(1);
    $lock = '/tftpboot/.ucmprovisioner/write-lock';
    if ($scenario === 'equal_metadata_change') { $source->files['/tftpboot/phone.cfg'] = 'new'; }
    if (in_array($scenario,['source_lock','target_lock'],true)) {
        $busy = $scenario === 'source_lock' ? $source : $target;
        $busy->directories[]='/tftpboot/.ucmprovisioner';$busy->directories[]=$lock;$busy->files[$lock.'/owner.json']='foreign-owner';
        try { $service->preview(1); throw new LogicException('Preview accepted an unresolved writer'); }
        catch (RuntimeException $expected) {}
    }
    if ($scenario === 'changed_during_copy') {
        $target->beforePut = static function (string $path) use ($source): void {
            if ($path === '/tftpboot/phone.cfg') { $source->files[$path] = 'new'; }
        };
    }
    if ($scenario === 'post_activation_log') {
        $module->throwLog = true;
        $result = $service->apply(1,$preview['plan_hash']);
        $expect(!empty($result['ok']) && !empty($result['warnings']) && $module->getSite(1)['tftp_mode'] === 'pbx', 'A post-activation logging error must preserve confirmed activation');
        $expect(!$source->is_dir($lock) && !$target->is_dir($lock), 'Confirmed activation must release both locks even when logging fails');
    } elseif ($scenario === 'uncertain_activation') {
        $db->exec("CREATE TRIGGER deny_cutover BEFORE UPDATE ON ucmp_sites BEGIN SELECT RAISE(IGNORE); END");
        $result=$service->apply(1,$preview['plan_hash']);
        $expect(!empty($result['recovery_required']), 'Unconfirmed activation must require reconciliation');
        foreach ([$source,$target] as $backend) {
            $owner=json_decode($backend->files[$lock.'/owner.json'] ?? '',true);
            $expect(($owner['context']['plan_hash'] ?? '') === $preview['plan_hash'], 'Both retained locks must identify the exact cutover');
        }
        $db->exec('DROP TRIGGER deny_cutover');
    } else {
        try { $service->apply(1,$preview['plan_hash']); throw new LogicException('Unsafe cutover accepted: '.$scenario); }
        catch (RuntimeException $expected) {}
        $expect($module->getSite(1)['tftp_mode']==='docker', 'Rejected cutover changed the active backend');
        if (isset($busy)) { $expect($busy->files[$lock.'/owner.json']==='foreign-owner', 'Cutover altered foreign lock ownership'); unset($busy); }
    }
}

echo "TFTP architecture resolution and verified cutover passed\n";
