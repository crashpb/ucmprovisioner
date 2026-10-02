<?php
declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    interface BMO {}
    class FreePBX_Helpers {}

    final class DockerProbeSftp extends \phpseclib3\Net\SFTP
    {
        public array $files = ['keep.txt'=>'existing content'];
        public array $writes = [];
        public array $deletes = [];
        public function __construct(public string $failure = '') {}
        public function __destruct() {} // No real SSH session was constructed.
        public function chdir($dir) { return $dir === '/tftpboot' && $this->failure !== 'chdir'; }
        public function nlist($dir = '.', $recursive = false) { return $this->failure === 'list' ? false : array_keys($this->files); }
        public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
        {
            $this->writes[] = $remote_file;
            $this->files[$remote_file] = $this->failure === 'write' ? 'partial' : $data;
            return $this->failure !== 'write';
        }
        public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
        {
            return $this->failure === 'read' ? 'corrupted probe' : ($this->files[$remote_file] ?? false);
        }
        public function delete($path, $recursive = true)
        {
            $this->deletes[] = $path;
            if ($this->failure === 'delete') { return false; }
            unset($this->files[$path]);
            return true;
        }
    }
    final class DockerProbeFreePBX
    {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }
}

namespace FreePBX\modules\Ucmprovisioner {
    /** Candidate transport only: no network or local private keys are touched. */
    final class SiteConnection
    {
        public static ?\DockerProbeSftp $sftp = null;
        public static array $candidate = [];
        public static bool $denyLogin = false;
        public static ?string $mode = null;
        public function __construct(array $candidate) { self::$candidate = $candidate; }
        public static function activeTftpMode(array $site): string { return $site['tftp_mode'] ?? 'docker'; }
        public static function resolvedTftpHost(array $site, ?string $mode = null): string
        {
            return $mode === 'pbx' ? ($site['pbx_tftp_service_host'] ?: ($site['pbx_addr'] ?? $site['pbx_host'] ?? ''))
                : ($site['tftp_service_host'] ?: ($site['docker_host'] ?? ''));
        }
        public function tftpSftp(?string $mode = null): \phpseclib3\Net\SFTP
        {
            self::$mode = $mode;
            if (!in_array($mode, ['docker','pbx'], true) || self::$denyLogin) { throw new \RuntimeException('Owner key login fixture failed'); }
            return self::$sftp;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
    use FreePBX\modules\Ucmprovisioner\DockerEnrollment;
    use FreePBX\modules\Ucmprovisioner\SiteConnection;

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    };
    foreach (['', 'chdir', 'list', 'write', 'read', 'delete'] as $failure) {
        $sftp = new DockerProbeSftp($failure);
        $rejected = false;
        try { $result = DockerEnrollment::verifySftp($sftp); }
        catch (\RuntimeException) { $rejected = true; }
        $expect($rejected === ($failure !== ''), 'SFTP probe misclassified ' . ($failure ?: 'success'));
        $expect(($sftp->files['keep.txt'] ?? null) === 'existing content', 'Probe changed existing content');
        if (!in_array($failure, ['delete'], true)) {
            $expect($sftp->files === ['keep.txt'=>'existing content'], 'Probe failed to clean up after ' . $failure);
        }
        if ($failure === '') {
            $expect(($result['entries'] ?? null) === 1 && count($sftp->writes) === 1, 'Successful probe did not verify a write');
        }
    }

    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ucmp_sites (id INTEGER PRIMARY KEY, is_local INTEGER, docker_host TEXT,
        docker_ssh_port INTEGER, docker_ssh_user TEXT, docker_ssh_secret TEXT, docker_host_key TEXT,
        tftp_root_path TEXT, tftp_bind_path TEXT, tftp_container TEXT, docker_bootstrapped_at INTEGER, tftp_mode TEXT)');
    $db->exec("INSERT INTO ucmp_sites VALUES (1,1,'old.example.test',2200,'legacy','fixture-only',
        'SHA256:old','/old','/old','old-container',0,'pbx')");
    foreach (['tftp_service_host TEXT','tftp_pending_mode TEXT','pbx_host TEXT','pbx_addr TEXT','pbx_ssh_port INTEGER',
        'pbx_host_key TEXT','pbx_tftp_service_host TEXT','pbx_tftp_root_path TEXT','pbx_tftp_bind_path TEXT',
        'pbx_tftp_daemon TEXT','pbx_tftp_bootstrapped_at INTEGER'] as $column) {
        $db->exec('ALTER TABLE ucmp_sites ADD COLUMN ' . $column);
    }
    $module = new \FreePBX\modules\Ucmprovisioner(new DockerProbeFreePBX($db));
    $metadata = ['docker_host'=>'tftp.example.test','docker_ssh_port'=>2222,'docker_host_key'=>'SHA256:fixture',
        'tftp_bind_path'=>'/opt/tftp-server/tftpboot','tftp_container'=>'existing-tftp','tftp_service_host'=>'phones.example.test'];
    $before = $db->query('SELECT * FROM ucmp_sites')->fetch(\PDO::FETCH_ASSOC);
    foreach (['login', 'chdir', 'list', 'write', 'read', 'delete'] as $failure) {
        SiteConnection::$denyLogin = $failure === 'login';
        SiteConnection::$sftp = new DockerProbeSftp($failure);
        $rejected = false;
        try { $module->adoptDockerEnrollment($metadata); }
        catch (\RuntimeException) { $rejected = true; }
        $expect($rejected, 'Owning PBX adoption accepted ' . $failure . ' failure');
        $expect($db->query('SELECT * FROM ucmp_sites')->fetch(\PDO::FETCH_ASSOC) === $before, 'Failed owner probe changed site readiness or metadata');
        $expect(SiteConnection::$candidate['docker_ssh_secret'] === null, 'Owner probe allowed a stored-password fallback');
        $expect(SiteConnection::$candidate['docker_ssh_port'] === 2222
            && SiteConnection::$candidate['docker_host_key'] === 'SHA256:fixture', 'Owner probe changed the candidate endpoint or pin');
    }
    SiteConnection::$denyLogin = false;
    SiteConnection::$sftp = new DockerProbeSftp();
    $adopted = $module->adoptDockerEnrollment($metadata);
    $after = $db->query('SELECT * FROM ucmp_sites')->fetch(\PDO::FETCH_ASSOC);
    $expect(!empty($adopted['ok']) && (int)$after['docker_bootstrapped_at'] > 0, 'Verified owner access did not mark enrollment');
    $expect($after['tftp_mode'] === 'pbx', 'Enrollment silently changed the active TFTP architecture');
    $expect($after['docker_ssh_secret'] === null && $after['docker_host'] === 'tftp.example.test', 'Verified enrollment metadata was not adopted');
    $expect($after['tftp_service_host'] === 'phones.example.test' && $after['tftp_pending_mode'] === 'docker', 'Docker owner lost phone address or staged cutover');

    $db->exec("UPDATE ucmp_sites SET tftp_mode='docker'");
    $beforePbx = $db->query('SELECT * FROM ucmp_sites')->fetch(\PDO::FETCH_ASSOC);
    $pbxMetadata = ['pbx_host'=>'pbx.example.test','pbx_ssh_port'=>2291,'pbx_host_key'=>'SHA256:pbxfixture',
        'pbx_tftp_service_host'=>'pbx-phones.example.test'];
    foreach (['login','chdir','list','write','read','delete'] as $failure) {
        SiteConnection::$denyLogin = $failure === 'login';
        SiteConnection::$sftp = new DockerProbeSftp($failure);
        $rejected = false;
        try { $module->adoptPbxTftpEnrollment($pbxMetadata); }
        catch (\RuntimeException) { $rejected = true; }
        $expect($rejected, 'PBX-hosted owner adoption accepted ' . $failure . ' failure');
        $expect($db->query('SELECT * FROM ucmp_sites')->fetch(\PDO::FETCH_ASSOC) === $beforePbx, 'PBX owner failure recorded readiness');
        $expect(SiteConnection::$mode === 'pbx' && SiteConnection::$candidate['pbx_ssh_port'] === 2291
            && SiteConnection::$candidate['pbx_host_key'] === 'SHA256:pbxfixture', 'PBX owner did not probe the pinned candidate');
    }
    SiteConnection::$denyLogin = false;
    SiteConnection::$sftp = new DockerProbeSftp();
    $pbxAdopted = $module->adoptPbxTftpEnrollment($pbxMetadata);
    $afterPbx = $db->query('SELECT * FROM ucmp_sites')->fetch(\PDO::FETCH_ASSOC);
    $expect(!empty($pbxAdopted['ok']) && (int)$afterPbx['pbx_tftp_bootstrapped_at'] > 0, 'PBX owner success did not record readiness');
    $expect($afterPbx['pbx_host'] === 'pbx.example.test' && (int)$afterPbx['pbx_ssh_port'] === 2291
        && $afterPbx['pbx_tftp_service_host'] === 'pbx-phones.example.test', 'PBX owner lost verified endpoint or phone-facing address');
    $expect($afterPbx['tftp_mode'] === 'docker' && $afterPbx['tftp_pending_mode'] === 'pbx', 'PBX owner silently activated the staged architecture');
    echo "Docker and PBX SFTP round-trip, failure cleanup and owner readiness fixtures passed\n";
}
