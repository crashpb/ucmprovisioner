<?php
declare(strict_types=1);

namespace {
    interface BMO {}
    class FreePBX_Helpers {}
    final class RemoteOnboardingFreePBX
    {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }
}

namespace FreePBX\modules {
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

    final class RemoteOnboardingFixture extends Ucmprovisioner
    {
        public array $site = [];
        public array $module = [];
        public array $agentProbe = [];
        public array $credential = ['ok'=>true, 'ready'=>true];
        public array $apiProbe = [];
        public bool $installed = false;
        public bool $bootstrapped = false;
        public bool $configured = false;
        public bool $installFailure = false;
        public bool $installedNotReady = false;
        public bool $apiFailure = false;
        public int $signCalls = 0;

        public function signSiteModule(int $siteId, string $adminUser, string $adminPass, bool $useSudo, string $expectedHostKey = ''): array
        {
            $this->signCalls++;
            return ['ok'=>true, 'trusted'=>true, 'state'=>'locally_signed'];
        }

        public function getSite(int $id, bool $withSecrets = false): ?array { return $this->site; }
        public function remoteModuleStatus(int $siteId, string $adminUser = '', string $adminPass = '', string $expectedHostKey = '', bool $useSudo = false): array { return $this->module; }
        public function installRemoteModule(int $siteId, string $adminUser, string $adminPass, bool $useSudo, bool $signAfter = true, string $expectedHostKey = ''): array
        {
            if ($this->installFailure) { return ['ok'=>false, 'status'=>'recovery_required', 'phase'=>'remote-install', 'message'=>'install uncertain']; }
            if ($this->installedNotReady) { return ['ok'=>false, 'status'=>'installed_but_not_ready', 'phase'=>'credential-readiness',
                'version'=>'1.1.0rc4', 'signing'=>['ok'=>true,'trusted'=>true], 'agent'=>['ok'=>true], 'detail'=>'installed fixture', 'message'=>'Credential check failed']; }
            $this->installed = true;
            return ['ok'=>true, 'status'=>'ready', 'version'=>'1.1.0rc4', 'signed'=>$signAfter, 'signing'=>['ok'=>true, 'trusted'=>$signAfter, 'state'=>$signAfter ? 'locally_signed' : 'unsigned'], 'agent'=>['ok'=>true, 'trunks'=>[['endpoint'=>'carrier']]], 'credential_readiness'=>$this->credential, 'detail'=>'verified install'];
        }
        public function bootstrapPbxAgent(int $id, string $host, int $port, string $adminUser, string $adminPass, bool $useSudo, string $expectedHostKey = ''): array
        {
            $this->bootstrapped = true;
            return ['ok'=>true, 'host_key'=>'SHA256:test', 'trunks'=>[['endpoint'=>'carrier']], 'message'=>'agent repaired'];
        }
        public function probeRemoteAgentFresh(int $siteId): array { return $this->agentProbe; }
        public function probeRemoteCredentialReadiness(int $siteId, string $adminUser, string $adminPass, bool $useSudo = false, string $expectedHostKey = ''): array { return $this->credential; }
        public function probeRemoteApi(int $siteId): array { return $this->apiProbe; }
        public function configureSiteApi(int $siteId, bool $enabled, string $scheme, string $host, int $port, bool $verifyTls): array
        {
            $this->configured = true;
            if ($this->apiFailure) { throw new \RuntimeException('API probe failed'); }
            return ['ok'=>true, 'message'=>'api configured'];
        }
        public function moduleStatus(): array { return ['ok'=>true, 'version'=>'1.1.0rc4']; }
    }
}

namespace {
    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    };

    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ucmp_sites (id INTEGER PRIMARY KEY, pbx_api_enabled INTEGER DEFAULT 0, pbx_api_ready INTEGER DEFAULT 0, pbx_api_last_ok INTEGER DEFAULT 0)');
    $db->exec('INSERT INTO ucmp_sites (id,pbx_api_ready) VALUES (2,0)');
    $db->exec('CREATE TABLE ucmp_operation_log (id INTEGER PRIMARY KEY, created INTEGER, site_id INTEGER, level TEXT, operation TEXT, message TEXT, detail TEXT)');
    $fixture = new \FreePBX\modules\RemoteOnboardingFixture(new \RemoteOnboardingFreePBX($db));

    $reflect = new \ReflectionMethod(\FreePBX\modules\Ucmprovisioner::class, 'summarizeRemoteModuleProbe');
    $reflect->setAccessible(true);
    $probe = static function (array $extra = []) use ($reflect, $fixture): array {
        return $reflect->invoke($fixture, array_replace([
            'probe_status'=>'ok', 'php_version'=>'8.2.10', 'framework_version'=>'17.0.28', 'core_version'=>'17.0.28',
            'file_present'=>true, 'file_version'=>'1.1.0rc4', 'registry_present'=>true, 'registry_version'=>'1.1.0rc4',
            'registry_enabled'=>true,
        ], $extra), '1.1.0rc4');
    };
    $expect($probe()['status'] === 'current', 'Current module probe was not classified current');
    $expect($probe(['file_present'=>false, 'registry_present'=>false, 'file_version'=>null, 'registry_version'=>null])['status'] === 'missing', 'Blank module probe was not classified missing');
    $expect($probe(['file_version'=>'0.17.0rc63', 'registry_version'=>'0.17.0rc63'])['status'] === 'older', 'Very old module probe was not classified older');
    $expect($probe(['registry_present'=>false, 'registry_version'=>null])['status'] === 'missingregistry', 'Missing registry probe was not classified missingregistry');
    $expect($probe(['registry_enabled'=>false])['status'] === 'disabled', 'Disabled registry probe was not classified disabled');
    $expect($probe(['registry_version'=>'0.17.0rc63'])['status'] === 'registry_mismatch', 'Mismatched registry probe was not classified registry_mismatch');
    $expect($probe(['file_version'=>'1.1.0rc5'])['comparison'] === 'remote_newer', 'Newer filesystem version was not protected');
    $expect($probe(['registry_version'=>'1.1.0rc5'])['comparison'] === 'remote_newer', 'Newer registry version was not protected');
    $expect($probe(['registry_present'=>false, 'registry_version'=>null, 'file_version'=>'1.1.0rc5'])['comparison'] === 'remote_newer', 'Newer filesystem without registry was not protected');
    $expect($probe(['file_present'=>false, 'file_version'=>null, 'registry_version'=>'1.1.0rc5'])['comparison'] === 'remote_newer', 'Newer registry without filesystem was not protected');
    $expect($probe(['registry_error'=>'query failed'])['comparison'] === 'unknown', 'Registry probe error was treated as an installable missing module');
    $expect($probe(['php_version'=>'8.1.0'])['status'] === 'unsupported', 'Unsupported PHP platform was not classified unsupported');
    $expect($probe(['file_present'=>false, 'registry_present'=>false, 'file_version'=>null, 'registry_version'=>null, 'framework_version'=>''])['comparison'] === 'unknown', 'Empty platform probe was not classified unknown');

    $fixture->site = [
        'id'=>2, 'name'=>'Remote', 'is_local'=>0, 'pbx_host'=>'pbx.example.test', 'pbx_addr'=>'192.0.2.10', 'pbx_ssh_port'=>22,
        'pbx_agent_ready'=>1, 'pbx_api_enabled'=>1, 'pbx_api_ready'=>1, 'pbx_api_scheme'=>'https', 'pbx_api_host'=>'pbx.example.test',
        'pbx_api_port'=>443, 'pbx_api_verify_tls'=>1, 'pbx_api_secret'=>str_repeat('a', 64), 'pbx_host_key'=>'SHA256:test',
    ];
    $fixture->module = ['ok'=>true, 'status'=>'current', 'comparison'=>'same', 'local_version'=>'1.1.0rc4', 'remote_version'=>'1.1.0rc4', 'message'=>'current'];
    $fixture->agentProbe = ['ok'=>true, 'trunks'=>[['endpoint'=>'carrier']], 'host_key'=>'SHA256:test'];
    $fixture->apiProbe = ['ok'=>true, 'ready'=>true, 'message'=>'healthy'];
    $ready = $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only']);
    $expect(!empty($ready['ready']) && $ready['status'] === 'ready', 'Current healthy site did not finish ready');
    $expect(!$fixture->installed && !$fixture->configured, 'Healthy same-version site reinstalled or rotated its API key');
    $expect($fixture->signCalls === 1 && !empty($ready['signing']['trusted']), 'Current-version action did not sign and return structured integrity');
    $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only', 'sign_after'=>false]);
    $expect($fixture->signCalls === 1, 'Unchecked signing option still dispatched signing');
    $expect((int)$db->query('SELECT pbx_api_ready FROM ucmp_sites WHERE id=2')->fetchColumn() === 1, 'Healthy existing API did not persist pbx_api_ready');
    $expect((string)$fixture->site['pbx_api_secret'] === str_repeat('a', 64), 'Healthy existing API secret was not preserved');

    $fixture->module = ['ok'=>true, 'status'=>'missing', 'comparison'=>'missing', 'local_version'=>'1.1.0rc4'];
    $fixture->apiProbe = ['ok'=>false];
    $fixture->apiFailure = false;
    $blank = $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only'], false);
    $expect(!empty($blank['ready']) && $fixture->installed && $fixture->configured, 'Blank site did not install, enroll and configure');
    $expect($blank['comparison'] === 'same' && $blank['remote_version'] === $blank['local_version'] && !empty($blank['signing']['trusted']), 'Installed result omitted updated version comparison or structured signing');

    $fixture->installed = false; $fixture->configured = false; $fixture->module = ['ok'=>true, 'status'=>'current', 'comparison'=>'same', 'local_version'=>'1.1.0rc4', 'remote_version'=>'1.1.0rc4'];
    $fixture->site['pbx_agent_ready'] = 0; $fixture->agentProbe = ['ok'=>false]; $fixture->apiProbe = ['ok'=>true];
    $repair = $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only']);
    $expect(!empty($repair['ready']) && $fixture->bootstrapped && !$fixture->installed, 'Current module with missing agent was reinstalled instead of repaired');

    $fixture->module = ['ok'=>true, 'status'=>'remote_newer', 'comparison'=>'remote_newer', 'remote_version'=>'1.1.0rc5', 'local_version'=>'1.1.0rc4'];
    $fixture->installed = false; $newer = $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only']);
    $expect($newer['status'] === 'remote_newer' && !$fixture->installed, 'Newer remote module was not refused before writes');

    $fixture->module = ['ok'=>true, 'status'=>'missing', 'comparison'=>'missing', 'local_version'=>'1.1.0rc4'];
    $fixture->installFailure = true; $fixture->configured = false; $uncertain = $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only']);
    $expect($uncertain['status'] === 'recovery_required' && !$fixture->configured, 'Uncertain install incorrectly continued into API enrollment');
    $fixture->installFailure = false;

    $fixture->installedNotReady = true;
    $installedPartial = $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only']);
    $expect(empty($installedPartial['ready']) && $installedPartial['status'] === 'installed_but_not_ready'
        && $installedPartial['phase'] === 'credential-readiness' && !$fixture->configured, 'Installed partial state incorrectly continued or claimed complete readiness');
    $expect($installedPartial['remote_version'] === '1.1.0rc4' && !empty($installedPartial['signing']['trusted'])
        && $installedPartial['components']['module']['status'] === 'installed', 'Later credential failure lost installed identity or signing');
    $fixture->installedNotReady = false;

    $fixture->module = ['ok'=>true, 'status'=>'current', 'comparison'=>'same', 'local_version'=>'1.1.0rc4', 'remote_version'=>'1.1.0rc4'];
    $fixture->site['pbx_agent_ready'] = 1; $fixture->agentProbe = ['ok'=>true, 'trunks'=>[]]; $fixture->apiProbe = ['ok'=>false]; $fixture->apiFailure = true; $partial = $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only']);
    $expect($partial['status'] === 'partial' && !empty($partial['components']['agent']['ready']) && empty($partial['ready']), 'API failure did not preserve agent-ready partial state');
    $apiAfterFailure = $db->query('SELECT pbx_api_ready,pbx_api_last_ok FROM ucmp_sites WHERE id=2')->fetch(\PDO::FETCH_ASSOC);
    $expect((int)$apiAfterFailure['pbx_api_ready'] === 0 && (int)$apiAfterFailure['pbx_api_last_ok'] === 0, 'Failed fresh API probe retained stale database readiness');

    $source = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
    $expect(str_contains($source, "'status'=>'broken-current'") && str_contains($source, "'status'=>'unknown'"), 'BMO known-failure and lower-level probe-unknown states are not explicit');

    try { $fixture->prepareRemoteSite(2, ['admin_user'=>'admin', 'admin_pass'=>'request-only', 'api_host'=>'192.0.2.0/24']); throw new \LogicException('CIDR API host was accepted'); } catch (\InvalidArgumentException) {}
    try { $fixture->prepareRemoteSite(2, ['admin_user'=>'admin']); throw new \LogicException('Missing admin password was accepted'); } catch (\InvalidArgumentException) {}

    echo "Remote site onboarding probe and orchestration fixtures passed\n";
}
