<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/DockerEnrollment.php';

use FreePBX\modules\Ucmprovisioner\DockerEnrollment;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$row = static function (string $name, string $id = 'abc123', string $running = 'true', string $tftp = '1', string $rw = 'false'): string {
    return 'ID=' . $id . "\tNAME=/" . $name . "\tRUNNING=" . $running . "\tNETWORK=bridge\tBIND=/opt/tftp-server/tftpboot|/tftpboot|" . $rw . "\tTFTP=" . $tftp;
};

$parsed = DockerEnrollment::parseInspection("UCM_DOCKER_META=1\n" . $row('tftp') . "\n");
$selected = DockerEnrollment::selectContainer($parsed, '');
$expect($selected['NAME'] === 'tftp', 'Unique compatible existing container was not selected');
$expect(DockerEnrollment::selectContainer($parsed, 'abc123')['NAME'] === 'tftp', 'Saved container ID was not accepted');

$failed = false;
try {
    DockerEnrollment::selectContainer([], '');
} catch (RuntimeException $e) {
    $failed = str_contains($e->getMessage(), 'No running compatible TFTP container');
}
$expect($failed, 'Missing container did not reject before any enrollment mutation');

$failed = false;
try {
    DockerEnrollment::selectContainer(DockerEnrollment::parseInspection($row('one') . "\n" . $row('two', 'def456')), '');
} catch (RuntimeException $e) {
    $failed = str_contains($e->getMessage(), 'multiple compatible');
}
$expect($failed, 'Ambiguous auto-discovery did not reject');

$sameKey = 'ssh-ed25519 AAAAexisting';
$exactKeys = "# retained comment\r\nssh-ed25519 AAAAexisting owner\n\n";
$expect(DockerEnrollment::decodeAuthorizedKeys("sudo diagnostic\nUCM_AUTHORIZED_KEYS_B64=" . base64_encode($exactKeys) . "\n\n") === $exactKeys, 'SSH diagnostics or newline normalization changed authorized_keys bytes');
$expect(DockerEnrollment::decodeAuthorizedKeys("UCM_AUTHORIZED_KEYS_B64=\n") === '', 'Absent key file was not decoded as empty');
foreach (["no record\n", "UCM_AUTHORIZED_KEYS_B64=YQ\n", "UCM_AUTHORIZED_KEYS_B64=\nUCM_AUTHORIZED_KEYS_B64=\n"] as $invalid) {
    try { DockerEnrollment::decodeAuthorizedKeys($invalid); throw new LogicException('Invalid key record was accepted'); }
    catch (RuntimeException) {}
}
$existing = "from=10.0.0.0/8 " . $sameKey . " owner\n" .
    "command=internal-sftp " . $sameKey . " alternate\n" .
    "ssh-rsa AAAAother old\n";
$merged = DockerEnrollment::mergeAuthorizedKeys($existing, [$sameKey, 'ssh-ed25519 AAAAnew']);
$expect(str_contains($merged, 'from=10.0.0.0/8 ' . $sameKey . ' owner'), 'Existing restricted key was not preserved');
$expect(str_contains($merged, 'command=internal-sftp ' . $sameKey . ' alternate'), 'Existing same-key entry with different options was not preserved');
$expect(substr_count($merged, $sameKey) === 2, 'A bare duplicate key widened existing authorized_keys');
$expect(str_contains($merged, 'ssh-ed25519 AAAAnew'), 'New controller/owner key was not merged');

$source = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$expect(str_contains($source, 'docker inspect --format='), 'Docker metadata preflight is missing');
$expect(!preg_match('/docker\s+(?:run|create|pull|build)\b/i', $source), 'Docker enrollment must not create or install containers');
$expect(!str_contains($source, 'mkdir -p " . escapeshellarg($hostDir)'), 'Docker enrollment must not create an empty bind root');
$dockerStart = strpos($source, 'public function bootstrapDockerHost(');
$expect($dockerStart !== false, 'Docker bootstrap method is missing');
$dockerBootstrap = substr($source, $dockerStart);
foreach (['command -v tftp', 'timeout 8 tftp', 'TFTP_PROBE=', 'ucmp-tftp-probe-', 'TFTP packet probe', 'tftp-hpa'] as $forbidden) {
    $expect(!str_contains($dockerBootstrap, $forbidden), 'Docker enrollment must not require, install or probe a TFTP client: ' . $forbidden);
}
$pbxStart = strpos($source, 'public function bootstrapPbxTftp(');
$expect($pbxStart !== false, 'PBX-local TFTP bootstrap method is missing');
$pbxBootstrap = substr($source, $pbxStart, $dockerStart - $pbxStart);
$expect(str_contains($pbxBootstrap, 'apt-get install -y tftpd-hpa tftp-hpa'), 'PBX-local bootstrap must install both server and client when authorized');
$expect(str_contains($pbxBootstrap, 'timeout 8 tftp 127.0.0.1'), 'PBX-local bootstrap must retain its local retrieval verification');
$expect(str_contains($source, "new \\FreePBX\\modules\\Ucmprovisioner\\SiteConnection(\$candidate)"), 'Owner adoption does not probe a candidate SiteConnection');
$preflightPosition = strpos($source, '$container = $this->dockerEnrollmentPreflight');
$keyPosition = strpos($source, '$key = $this->ensureProvisionerKey()', $preflightPosition ?: 0);
$ownerKeyPosition = strpos($source, "remoteLiveQuery((int)\$targetSite['id'], 'getProvisionerPublicKey')", $preflightPosition ?: 0);
$expect($preflightPosition !== false && $keyPosition !== false && $preflightPosition < $keyPosition, 'Docker preflight must precede local enrollment-key creation');
$expect($preflightPosition !== false && $ownerKeyPosition !== false && $preflightPosition < $ownerKeyPosition, 'Docker preflight must precede owner-key RPC');
$probePosition = strpos($source, '$candidateSftp = $candidateConnection->tftpSftp');
$adoptUpdatePosition = strpos($source, 'docker_bootstrapped_at=? WHERE id=?', $probePosition ?: 0);
$expect($probePosition !== false && $adoptUpdatePosition !== false && $probePosition < $adoptUpdatePosition, 'Owner adoption must probe SFTP before recording readiness');

echo "Docker preflight, key merge, and adoption guard fixtures passed\n";
