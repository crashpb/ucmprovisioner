<?php
declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/lib/ManagedAssetRegistry.php';
    require_once dirname(__DIR__) . '/lib/DirectoryService.php';

    use FreePBX\modules\Ucmprovisioner\DirectoryService;

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    };

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ucmp_phonebooks (id INTEGER PRIMARY KEY, is_default INTEGER, name TEXT)');
    // Deliberately omit ucmp_assets to make advisory inventory recording fail.
    $ucm = new class($db) {
        public array $saved = [];
        public string $failSetting = '';
        public function __construct(private PDO $db) {}
        public function getDb(): PDO { return $this->db; }
        public function localSiteId(): int { return 7; }
        public function getSetting(string $key, int $siteId, string $default = ''): string { return $key === 'default_phonebook_key' ? 'company' : $default; }
        public function getSiteSettingsLocal(int $siteId): array { return []; }
        public function directoryBaseUrl(int $siteId): string
        {
            return 'https://pbx.example/ucmprovisioner-directory.php';
        }
        public function setSetting(string $key, string $value, int $siteId): void
        {
            if ($key === $this->failSetting) { throw new RuntimeException('Fixture URL setting write failed'); }
            $this->saved[$key] = $value;
        }
    };

    $cacheRoot = sys_get_temp_dir() . '/ucmp-directory-' . bin2hex(random_bytes(6));
    $directory = new DirectoryService($ucm,$cacheRoot);
    try {
    $result = $directory->publishSnapshotLocal([[
        '__ucmp_source'=>['site_key'=>'site-a','site_name'=>'SITE-A-PBX'],
    ],[
        'source_key'=>str_repeat('a', 64),
        'display_name'=>'Alice Example',
        'label'=>'a.example',
        'extension'=>'301',
    ]], 'A1B2C3D4');

    $files = [];
    foreach (['directory.json','token'] as $file) { $files['/var/lib/asterisk/ucmprovisioner/directory/' . $file] = file_get_contents($cacheRoot . '/' . $file); }
    $expect(!empty($result['ok']), 'Managed inventory failure must not report directory delivery failure');
    $expect(
        !empty($result['warning'])
        && str_contains((string)$result['warning'], 'managed asset inventory was not updated'),
        'Managed inventory failure must return a visible warning'
    );
    $expect(!isset($result['asset']), 'A failed inventory record must not return a fabricated asset');
    $expect(
        isset($files['/var/lib/asterisk/ucmprovisioner/directory/directory.json'])
        && ($files['/var/lib/asterisk/ucmprovisioner/directory/token'] ?? '') === "A1B2C3D4\n",
        'The directory cache and token must remain published'
    );
    $cache = json_decode((string)$files['/var/lib/asterisk/ucmprovisioner/directory/directory.json'], true);
    $expect(
        ($cache['default_book_key'] ?? '') === 'company'
        && ($cache['source']['site_key'] ?? '') === 'site-a',
        'Published cache must persist the effective per-site default and secret-free publisher identity'
    );
    $expect(
        isset($ucm->saved['directory_token'])
        && isset($ucm->saved['cisco_directory_url'])
        && isset($ucm->saved['cisco_legacy_directory_url'])
        && isset($ucm->saved['yealink_phonebook_url'])
        && isset($ucm->saved['grandstream_phonebook_url']),
        'All phone-facing URL settings must still update after advisory inventory failure'
    );

    $defaultResult = $directory->setPublishedDefaultPhonebook('company');
    $expect(!empty($defaultResult['ok']), 'Changing the published default must keep the already-written cache active');
    $expect(
        !empty($defaultResult['warning'])
        && str_contains((string)$defaultResult['warning'], 'managed asset inventory was not updated'),
        'Changing the published default must expose an advisory inventory failure instead of hiding it'
    );

    $ucm->saved = [];
    $ucm->failSetting = 'cisco_directory_url';
    $lateSettings = $directory->publishSnapshotLocal([], 'A1B2C3D4');
    $expect(!empty($lateSettings['ok']) && str_contains(implode(' ', $lateSettings['warnings']), 'URL setting'), 'Completed cache publication must retain a later settings failure as a warning');
    $expect(isset($ucm->saved['grandstream_phonebook_url']), 'One failed URL setting must not stop independent URL updates');
    $lock = \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock($cacheRoot . '/.publication.lock');
    foreach ([fn()=>$directory->publishSnapshotLocal([], 'A1B2C3D4'),fn()=>$directory->setPublishedDefaultPhonebook('company')] as $publish) {
        try { $publish(); throw new LogicException('Concurrent cache writer accepted'); }
        catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'in progress'),'Both cache writers must share one publication lock'); }
    }
    flock($lock,LOCK_UN);fclose($lock);
    unlink($cacheRoot . '/token');mkdir($cacheRoot . '/token');
    $lateToken = $directory->publishSnapshotLocal([], 'FFEEDDCC');
    $payload = json_decode(file_get_contents($cacheRoot . '/directory.json'),true,512,JSON_THROW_ON_ERROR);
    $expect($lateToken['ok'] && str_contains(implode('; ',$lateToken['warnings']),'token companion') && $payload['token_sha256']===hash('sha256','FFEEDDCC'),
        'The atomic snapshot must retain its own authentication after a late token-file failure');
    rmdir($cacheRoot . '/token');file_put_contents($cacheRoot . '/token',"A1B2C3D4\n");
    $endpoint = file_get_contents(dirname(__DIR__) . '/directory.php');
    $endpoint = str_replace("'/var/lib/asterisk/ucmprovisioner/directory'",var_export($cacheRoot,true),substr($endpoint,5));
    foreach (['FFEEDDCC'=>200,'A1B2C3D4'=>403] as $token=>$expected) {
        $script = 'http_response_code(200); $_GET=' . var_export(['token'=>$token,'format'=>'cisco'],true) . '; register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());}); ' . $endpoint;
        $process=proc_open([PHP_BINARY,'-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $expect($exit===0 && $status===(string)$expected,'The actual phone endpoint must authenticate against the selected snapshot despite a mismatched companion token: ' . $status);
    }
    echo "Directory atomic publication, reader authentication, writer exclusion and registry-warning fixtures passed\n";
    } finally {
        foreach (glob($cacheRoot . '/*') ?: [] as $path) { is_dir($path) ? rmdir($path) : unlink($path); }
        @unlink($cacheRoot . '/.publication.lock'); @rmdir($cacheRoot);
    }
}
