<?php
declare(strict_types=1);

namespace {
    interface BMO {}
    class FreePBX_Helpers {}
    if (!function_exists('_')) { function _($value) { return $value; } }
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
    require_once dirname(__DIR__) . '/lib/SiteConnection.php';
}

namespace FreePBX\modules {
    final class SiteEditorTestModule extends Ucmprovisioner
    {
        public array $fixture = [];

        public function getSite(int $id, bool $withSecrets = false): ?array
        {
            return $this->fixture ?: null;
        }
    }
}

namespace {
    use FreePBX\modules\SiteEditorTestModule;
    use FreePBX\modules\Ucmprovisioner;

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $moduleReflection = new ReflectionClass(SiteEditorTestModule::class);
    $dbProperty = new ReflectionProperty(Ucmprovisioner::class, 'db');
    $dbProperty->setAccessible(true);

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ucmp_sites (
        id INTEGER PRIMARY KEY, site_key TEXT, name TEXT, is_local INTEGER, enabled INTEGER,
        pbx_addr TEXT, pbx_sip_port INTEGER, pbx_host TEXT, pbx_ssh_port INTEGER,
        pbx_ssh_user TEXT, pbx_ssh_secret TEXT, pbx_host_key TEXT, pbx_agent_user TEXT,
        pbx_agent_ready INTEGER, pbx_bootstrapped_at INTEGER, pbx_api_enabled INTEGER,
        pbx_api_ready INTEGER, pbx_api_scheme TEXT, pbx_api_host TEXT, pbx_api_port INTEGER,
        pbx_api_verify_tls INTEGER, pbx_api_last_ok INTEGER, docker_host TEXT,
        docker_ssh_port INTEGER, docker_ssh_user TEXT, docker_ssh_secret TEXT,
        docker_host_key TEXT, docker_sudo INTEGER, tftp_root_path TEXT,
        tftp_service_host TEXT, tftp_container TEXT, tftp_bind_path TEXT,
        docker_bootstrapped_at INTEGER, tftp_mode TEXT, tftp_pending_mode TEXT,
        pbx_tftp_service_host TEXT, pbx_tftp_root_path TEXT, pbx_tftp_bind_path TEXT,
        pbx_tftp_daemon TEXT, pbx_tftp_bootstrapped_at INTEGER, voice_subnets TEXT,
        trunk_endpoint_prefix TEXT, trunk_endpoint TEXT, remote_trunk_endpoint TEXT
    )');
    $db->exec("INSERT INTO ucmp_sites (id) VALUES (1)");

    $site = static function (array $overrides = []): array {
        return array_replace([
            'id'=>1, 'site_key'=>'site-a', 'name'=>'Site A', 'is_local'=>0, 'enabled'=>1,
            'pbx_addr'=>'192.0.2.10', 'pbx_host'=>'192.0.2.10', 'pbx_ssh_port'=>22,
            'pbx_ssh_user'=>'ucmp-pbx', 'pbx_host_key'=>'key', 'pbx_agent_ready'=>1,
            'pbx_bootstrapped_at'=>1, 'pbx_api_enabled'=>0, 'pbx_api_ready'=>0,
            'pbx_api_scheme'=>'http', 'pbx_api_host'=>'192.0.2.10', 'pbx_api_port'=>80,
            'pbx_api_verify_tls'=>1, 'pbx_api_last_ok'=>0, 'docker_host'=>'192.0.2.20',
            'docker_ssh_port'=>22, 'docker_host_key'=>'docker-key', 'docker_bootstrapped_at'=>1,
            'tftp_container'=>'tftp', 'tftp_service_host'=>'192.0.2.20',
            'tftp_mode'=>'docker', 'tftp_pending_mode'=>null,
            'pbx_tftp_service_host'=>'192.0.2.10', 'pbx_tftp_bootstrapped_at'=>1,
        ], $overrides);
    };
    $dockerDefault = $site([
        'tftp_mode'=>'docker', 'docker_host'=>'192.0.2.20', 'tftp_service_host'=>null,
    ]);
    $expect(\FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($dockerDefault, 'docker') === '192.0.2.20', 'Docker TFTP fallback did not use the Docker host');
    $dockerOverride = $site([
        'tftp_mode'=>'docker', 'docker_host'=>'192.0.2.20', 'tftp_service_host'=>'198.51.100.20',
    ]);
    $expect(\FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($dockerOverride, 'docker') === '198.51.100.20', 'Docker TFTP override was not preserved');
    $pbxDefault = $site([
        'tftp_mode'=>'pbx', 'pbx_addr'=>'192.0.2.10', 'pbx_tftp_service_host'=>null,
    ]);
    $expect(\FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($pbxDefault, 'pbx') === '192.0.2.10', 'PBX TFTP fallback did not use the PBX voice address');
    $pbxOverride = $site([
        'tftp_mode'=>'pbx', 'pbx_addr'=>'192.0.2.10', 'pbx_tftp_service_host'=>'198.51.100.21',
    ]);
    $expect(\FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($pbxOverride, 'pbx') === '198.51.100.21', 'PBX TFTP override was not preserved');
    $makeModule = static function (array $fixture) use ($moduleReflection, $dbProperty, $db): SiteEditorTestModule {
        /** @var SiteEditorTestModule $module */
        $module = $moduleReflection->newInstanceWithoutConstructor();
        foreach ($fixture as $key=>$value) { $db->prepare('UPDATE ucmp_sites SET `'.$key.'`=? WHERE id=1')->execute([$value]); }
        $fixture=$db->query('SELECT * FROM ucmp_sites WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $module->fixture = $fixture;
        $dbProperty->setValue($module, $db);
        return $module;
    };
    $save = static function (SiteEditorTestModule $module, array $post) use ($db): array {
        $module->saveSite(['id'=>1, 'name'=>'Site A', 'site_key'=>'site-a','expected_revision'=>Ucmprovisioner::siteEditorRevision($module->fixture)] + $post);
        return $db->query('SELECT tftp_service_host,pbx_tftp_service_host,tftp_mode,tftp_pending_mode,voice_subnets,pbx_sip_port FROM ucmp_sites WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    };
    $connectionFields = new ReflectionMethod(Ucmprovisioner::class, 'siteConnectionFields');
    $connectionFields->setAccessible(true);
    $defaults = $connectionFields->invoke($makeModule($site()), $site(), [
        'docker_host'=>'198.51.100.20', 'tftp_service_host'=>'198.51.100.20',
        'pbx_host'=>'198.51.100.10', 'pbx_addr'=>'198.51.100.11', 'pbx_tftp_service_host'=>'198.51.100.11',
    ]);
    $expect($defaults['tftp_service_host'] === '' && $defaults['pbx_tftp_service_host'] === '', 'Displayed action defaults became permanent overrides');
    $overrides = $connectionFields->invoke($makeModule($site()), $site(), [
        'tftp_service_host'=>'phones.example.test', 'pbx_tftp_service_host'=>'pbx-phones.example.test',
    ]);
    $expect($overrides['tftp_service_host'] === 'phones.example.test' && $overrides['pbx_tftp_service_host'] === 'pbx-phones.example.test', 'Draft normalization discarded deliberate service addresses');
    foreach (['docker','pbx'] as $mode) {
        $field = $mode === 'docker' ? 'tftp_service_host' : 'pbx_tftp_service_host';
        $value = $mode === 'docker' ? '192.0.2.20' : '192.0.2.10';
        $savedDefault = $save($makeModule($site(['tftp_mode'=>$mode])), ['tftp_mode'=>$mode, $field=>$value]);
        $expect($savedDefault[$field] === null || $savedDefault[$field] === '', 'Save persisted a displayed inherited TFTP default');
    }

    $row = $save($makeModule($site([
        'tftp_mode'=>'docker', 'tftp_service_host'=>'192.0.2.20',
        'pbx_tftp_service_host'=>null,
    ])), [
        'tftp_mode'=>'docker', 'tftp_service_host'=>'192.0.2.21',
        'pbx_tftp_service_host'=>'10.40.0.0/16',
        'voice_subnets'=>'10.20.0.0/16', 'pbx_sip_port'=>5170,
    ]);
    $expect($row['tftp_service_host'] === '192.0.2.21' && $row['pbx_tftp_service_host'] === null, 'Docker save rejected or copied the inactive PBX CIDR into the address field');
    $expect($row['voice_subnets'] === '10.20.0.0/16' && (int)$row['pbx_sip_port'] === 5170, 'Docker save did not persist ordinary site fields');

    $row = $save($makeModule($site([
        'tftp_mode'=>'pbx', 'tftp_service_host'=>'192.0.2.20',
        'pbx_tftp_service_host'=>'192.0.2.10',
    ])), [
        'tftp_mode'=>'pbx', 'pbx_tftp_service_host'=>'192.0.2.11',
        'tftp_service_host'=>'https://stale.invalid/path',
    ]);
    $expect($row['pbx_tftp_service_host'] === '192.0.2.11' && $row['tftp_service_host'] === '192.0.2.20', 'PBX save rejected or overwrote the inactive Docker address');

    $row = $save($makeModule($site([
        'tftp_mode'=>'docker', 'tftp_pending_mode'=>'pbx',
        'tftp_service_host'=>'192.0.2.20', 'pbx_tftp_service_host'=>'192.0.2.10',
    ])), [
        'tftp_mode'=>'pbx', 'pbx_tftp_service_host'=>'192.0.2.11',
        'tftp_service_host'=>'https://stale.invalid/path',
    ]);
    $expect($row['tftp_mode'] === 'docker' && $row['tftp_pending_mode'] === 'pbx', 'A PBX request did not retain the staged pending mode while Docker remained active');

    foreach ([
        ['docker','tftp_service_host','Phone-facing TFTP address'],
        ['pbx','pbx_tftp_service_host','PBX-hosted TFTP address'],
    ] as [$mode, $field, $diagnostic]) {
        try {
            $save($makeModule($site(['tftp_mode'=>$mode])), [
                'tftp_mode'=>$mode, $field=>'https://selected.invalid/path',
            ]);
            throw new RuntimeException("An invalid address for selected $mode backend was accepted");
        } catch (InvalidArgumentException $e) {
            $expect(str_contains($e->getMessage(), $diagnostic), "Selected $mode address failure had the wrong diagnostic");
        }
    }

    $actionModule = (new ReflectionClass(Ucmprovisioner::class))->newInstanceWithoutConstructor();
    foreach ([['sites','delsite'], ['phones','delphone']] as [$tab, $action]) {
        $delete = $actionModule->getActionBar([
            'display'=>'ucmprovisioner', 'view'=>'form', 'tab'=>$tab, 'id'=>42, 'site_id'=>7,
        ])['delete'] ?? [];
        $expect(($delete['class'] ?? null) === 'btn btn-danger', "$tab delete class must be one FreePBX-compatible string");
        $attributes = [];
        foreach ($delete as $name=>$value) {
            if (is_scalar($value)) { $attributes[] = $name . '="' . $value . '"'; }
        }
        $dom = new DOMDocument();
        $html = '<!doctype html><input ' . implode(' ', $attributes) . '>';
        $expect(@$dom->loadHTML($html), "$tab action HTML was not parseable");
        $input = $dom->getElementsByTagName('input')->item(0);
        $onclick = $input ? $input->getAttribute('onclick') : '';
        $expect($input !== null && $input->getAttribute('class') === 'btn btn-danger', "$tab delete button lost its class");
        $expect(str_contains($onclick, 'confirm(') && str_contains($onclick, 'window.ucmpPostAction('), "$tab action lost confirmation or POST/CSRF helper flow");
        $expect(str_contains($onclick, "config.php?display=ucmprovisioner&tab=$tab&action=$action&id=42&site_id=7"), "$tab action route lost tab/action/id/site_id");
        $expect(!str_contains($onclick, 'window.location') && !str_contains($onclick, 'location.href'), "$tab action must not mutate through a GET navigation");
    }

    if (!defined('FREEPBX_IS_AUTH')) { define('FREEPBX_IS_AUTH', true); }
    $viewFixture = $site(['trunk_endpoint'=>'saved-local','remote_trunk_endpoint'=>'saved-return']);
    $ucm = new class($viewFixture) {
        public function __construct(private array $site) {}
        public function getSite(int $id): array { return $this->site; }
        public function getOperationLogs(int $id, int $limit): array { return []; }
        public function listPjsipTrunks(int $id): array { throw new RuntimeException('Fixture trunk inventory unavailable'); }
    };
    $_REQUEST = ['view'=>'site','id'=>1];
    $siteHtml = (static function ($ucm): string {
        ob_start();
        include dirname(__DIR__) . '/views/sites.php';
        return (string)ob_get_clean();
    })($ucm);
    $dom = new DOMDocument();
    @$dom->loadHTML($siteHtml);
    $xpath = new DOMXPath($dom);
    foreach (['trunk_endpoint'=>'saved-local','remote_trunk_endpoint'=>'saved-return'] as $field=>$endpoint) {
        $options = $xpath->query('//select[@name="' . $field . '"]/option[@selected]');
        $expect($options->length === 1 && $options->item(0)->getAttribute('value') === $endpoint,
            'Unavailable trunk inventory must retain the saved ' . $field . ' in the actual form');
    }
    $disabledValue = $xpath->query('//input[@type="hidden" and @name="enabled"]')->item(0);
    $expect($disabledValue !== null && $disabledValue->getAttribute('value') === '0', 'An unchecked Manage site checkbox must submit an explicit disabled value');
    $save($makeModule($site()), ['enabled'=>0]);
    $expect((int)$db->query('SELECT enabled FROM ucmp_sites WHERE id=1')->fetchColumn() === 0, 'Explicit disabled selection must persist');
    foreach ([['pbx_host'=>'192.0.2.11'], ['pbx_api_verify_tls'=>0]] as $changedConnection) {
        $save($makeModule($site(['pbx_api_host'=>null,'pbx_api_ready'=>1,'pbx_api_last_ok'=>100])), $changedConnection);
        $apiState = $db->query('SELECT pbx_api_ready,pbx_api_last_ok FROM ucmp_sites WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $expect((int)$apiState['pbx_api_ready'] === 0 && (int)$apiState['pbx_api_last_ok'] === 0,
            'A changed PBX or TLS verification policy must invalidate previous API verification');
    }

    $staleModule=$makeModule($site());
    $oldRevision=Ucmprovisioner::siteEditorRevision($staleModule->fixture);
    $db->exec("UPDATE ucmp_sites SET name='Concurrent edit' WHERE id=1");
    try {
        $staleModule->saveSite(['id'=>1,'name'=>'Old editor','expected_revision'=>$oldRevision]);
        throw new LogicException('Save overwrote a concurrently changed site');
    } catch (DomainException $expected) {}
    $expect($db->query('SELECT name FROM ucmp_sites WHERE id=1')->fetchColumn()==='Concurrent edit','Site compare-and-set must preserve the competing edit');
    $staleModule->fixture['name']='New editor state';
    try {
        $staleModule->saveSite(['id'=>1,'name'=>'Old editor','expected_revision'=>$oldRevision]);
        throw new LogicException('Stale form revision was accepted');
    } catch (DomainException $expected) {}
    $actionRevision=new ReflectionMethod(Ucmprovisioner::class,'siteActionEditorRevision');
    (new ReflectionProperty(Ucmprovisioner::class,'siteActionSavedBase'))->setValue($staleModule,[1=>$staleModule->fixture]);
    $staleModule->fixture['pbx_agent_ready']=1;
    $expect($actionRevision->invoke($staleModule,1)===Ucmprovisioner::siteEditorRevision($staleModule->fixture),'Confirmed enrollment changes must refresh the form revision');
    $staleModule->fixture['name']='Another editor changed the name';
    $expect($actionRevision->invoke($staleModule,1)===null,'Connection completion must not acknowledge an unrelated concurrent edit');
    echo "Site editor save and action-bar regressions passed\n";
}
