<?php
declare(strict_types=1);
namespace {
    interface BMO {}
    class FreePBX_Helpers {}
    final class ReplayTestFreePBX {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }
}
namespace FreePBX\modules\Ucmprovisioner {
    final class SiteConnection {
        public static string $mode = 'scalar';
        public static array $ssh = [];
        public static array $api = [];
        public function __construct(array $site) {}
        public function execApi(array $payload) {
            self::$api[] = $payload;
            if (self::$mode === 'operation-failure') { throw new RpcOperationException('Operation rejected'); }
            if (self::$mode !== 'scalar') { throw new \RuntimeException('Simulated lost API response'); }
            return null;
        }
        public function execAgent(string $payload): string {
            $request = json_decode((string)base64_decode($payload), true, 32, JSON_THROW_ON_ERROR);
            self::$ssh[] = $request;
            if (self::$mode === 'ssh-failure') { throw new \RuntimeException('Simulated SSH timeout'); }
            if ($request['method'] === 'rpcOperationStatusLocal') {
                return json_encode(self::$mode === 'unknown'
                    ? ['rpc_version'=>2,'state'=>'unknown','operation_id'=>$request['args'][0]]
                    : RpcResponse::completed(73, $request['args'][0]), JSON_THROW_ON_ERROR);
            }
            return json_encode(['read'=>'fallback'], JSON_THROW_ON_ERROR);
        }
    }
}
namespace {
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
    use FreePBX\modules\Ucmprovisioner\{SiteConnection, RpcOperationException};
    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    // Deliberately omit pbx_api_last_ok: failed local bookkeeping must not replay a write.
    $db->exec('CREATE TABLE ucmp_sites (id INTEGER PRIMARY KEY,is_local INTEGER,pbx_api_enabled INTEGER,pbx_api_ready INTEGER,pbx_agent_ready INTEGER)');
    $db->exec('INSERT INTO ucmp_sites VALUES (1,1,0,0,0),(2,0,1,1,1)');
    $db->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY,site_id INTEGER,skey TEXT,sval TEXT)');
    $db->exec('CREATE TABLE ucmp_operation_log (id INTEGER PRIMARY KEY,created INTEGER,site_id INTEGER,level TEXT,operation TEXT,message TEXT,detail TEXT)');
    $ucm = new \FreePBX\modules\Ucmprovisioner(new ReplayTestFreePBX($db));
    if ($ucm->remoteQuery(2,'saveTemplateLocal',[['name'=>'fixture']]) !== null || SiteConnection::$ssh) {
        throw new \RuntimeException('Null success/local bookkeeping failure triggered replay');
    }
    SiteConnection::$mode = 'operation-failure';
    try { $ucm->remoteQuery(2,'saveTemplateLocal',[[]]); throw new \LogicException('Operation error swallowed'); }
    catch (RpcOperationException $expected) {}
    if (SiteConnection::$ssh) { throw new \RuntimeException('Authenticated operation error triggered SSH replay'); }

    SiteConnection::$mode = 'lost-response';
    if ($ucm->remoteQuery(2,'saveTemplateLocal',[[]]) !== 73) { throw new \RuntimeException('Lost response was not recovered from destination receipt'); }
    $lastApi = SiteConnection::$api[array_key_last(SiteConnection::$api)];
    $lastSsh = SiteConnection::$ssh[array_key_last(SiteConnection::$ssh)];
    if ($lastSsh['method'] !== 'rpcOperationStatusLocal' || $lastSsh['args'][0] !== $lastApi['operation_id']) {
        throw new \RuntimeException('Uncertain write was replayed or reconciled with the wrong operation ID');
    }
    SiteConnection::$mode = 'unknown';
    try { $ucm->remoteQuery(2,'saveTemplateLocal',[[]]); throw new \LogicException('Unknown operation was reported successful'); }
    catch (RpcOperationException $expected) {}
    if (array_filter(SiteConnection::$ssh, static fn($r)=>$r['method'] !== 'rpcOperationStatusLocal')) {
        throw new \RuntimeException('Unknown destination permitted mutation replay');
    }
    SiteConnection::$mode = 'lost-response';
    if ($ucm->remoteQuery(2,'getBoundDeviceLocal',['123']) !== ['read'=>'fallback']) {
        throw new \RuntimeException('Read-only transport recovery no longer works');
    }
    $db->exec('UPDATE ucmp_sites SET pbx_api_enabled=0 WHERE id=2');
    SiteConnection::$mode = 'ssh-failure';
    try { $ucm->remoteQuery(2,'saveTemplateLocal',[[]]); throw new \LogicException('Uncertain SSH write was reported successful'); }
    catch (RpcOperationException $expected) {
        if (empty($expected->receipt['operation_id'])) { throw new \RuntimeException('Uncertain SSH write lost its operation identity'); }
    }
    echo "Federation operation/transport separation and no uncertain replay fixtures passed\n";
}
