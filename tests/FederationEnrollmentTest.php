<?php
declare(strict_types=1);

namespace {
    interface BMO {}
    class FreePBX_Helpers {}

    final class FederationTestFreePBX
    {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }
}

namespace FreePBX\modules\Ucmprovisioner {
    /** In-memory transport that rejects a stale controller key exactly like the HTTP endpoint. */
    final class SiteConnection
    {
        public static string $remoteSecret = '';
        public static int $instances = 0;
        private string $controllerSecret;

        public function __construct(array $site)
        {
            self::$instances++;
            $this->controllerSecret = (string)($site['pbx_api_secret'] ?? '');
        }

        public function execAgent(string $payload): string
        {
            $request = json_decode((string)base64_decode($payload, true), true, 16, JSON_THROW_ON_ERROR);
            if (($request['method'] ?? '') !== 'configureFederationApiLocal') {
                throw new \RuntimeException('Unexpected test RPC');
            }
            self::$remoteSecret = strtolower((string)($request['args'][0] ?? ''));
            return '{"ok":true}';
        }

        public function execApi(array $payload): array
        {
            if (!hash_equals(self::$remoteSecret, $this->controllerSecret)) {
                throw new \RuntimeException('Federation API transport failed (HTTP 403)');
            }
            return ($payload['method'] ?? '') === 'getAllSipExtensionsLocal'
                ? [['id'=>'1001','description'=>'Test extension']]
                : ['ok'=>true, 'version'=>'test'];
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ucmp_sites (
        id INTEGER PRIMARY KEY, name TEXT, is_local INTEGER DEFAULT 0,
        pbx_host TEXT, pbx_addr TEXT, pbx_agent_ready INTEGER DEFAULT 0,
        pbx_ssh_secret TEXT, docker_ssh_secret TEXT,
        pbx_api_enabled INTEGER DEFAULT 0, pbx_api_ready INTEGER DEFAULT 0,
        pbx_api_scheme TEXT, pbx_api_host TEXT, pbx_api_port INTEGER,
        pbx_api_verify_tls INTEGER DEFAULT 0, pbx_api_secret TEXT,
        pbx_api_last_ok INTEGER DEFAULT 0
    )');
    $db->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER, skey TEXT, sval TEXT)');
    $db->exec('CREATE TABLE ucmp_operation_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created INTEGER, site_id INTEGER, level TEXT, operation TEXT, message TEXT, detail TEXT)');

    $oldSecret = str_repeat('a', 64);
    $insert = $db->prepare('INSERT INTO ucmp_sites (id,name,is_local,pbx_host,pbx_addr,pbx_agent_ready,pbx_api_enabled,pbx_api_ready,pbx_api_scheme,pbx_api_host,pbx_api_port,pbx_api_secret) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([1,'Local',1,'127.0.0.1','127.0.0.1',0,0,0,'http','127.0.0.1',80,null]);
    $insert->execute([2,'Remote',0,'192.0.2.2','192.0.2.2',1,1,1,'http','192.0.2.2',80,$oldSecret]);
    \FreePBX\modules\Ucmprovisioner\SiteConnection::$remoteSecret = $oldSecret;

    $ucm = new \FreePBX\modules\Ucmprovisioner(new FederationTestFreePBX($db));
    $result = $ucm->configureSiteApi(2, true, 'http', '192.0.2.2', 80, false);
    if (empty($result['ok'])) {
        throw new \RuntimeException('Federation enrollment did not succeed');
    }
    if (\FreePBX\modules\Ucmprovisioner\SiteConnection::$instances < 2) {
        throw new \RuntimeException('Federation enrollment reused the pre-rotation connection');
    }
    $row = $db->query('SELECT pbx_api_ready,pbx_api_secret FROM ucmp_sites WHERE id=2')->fetch(\PDO::FETCH_ASSOC);
    if ((int)($row['pbx_api_ready'] ?? 0) !== 1 || $ucm->dec((string)($row['pbx_api_secret'] ?? '')) !== \FreePBX\modules\Ucmprovisioner\SiteConnection::$remoteSecret) {
        throw new \RuntimeException('Controller and remote federation state did not converge');
    }

    echo "Federation key-rotation enrollment fixture passed\n";
}
