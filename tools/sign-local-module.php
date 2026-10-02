<?php

declare(strict_types=1);

namespace UcmProvisioner\Signing;

use RuntimeException;
use Throwable;

const MODULE_NAME = 'ucmprovisioner';
const SIGNER_HOME = '/var/lib/ucmprovisioner-signer';
const SECURE_DIRECTORY = '/etc/freepbx.secure';
const EXPECTED_SIGNATURE_STATUS = 129; // GPG::STATE_GOOD | GPG::STATE_TRUSTED

final class SignaturePublicationException extends RuntimeException
{
    public function __construct(string $message,public readonly array $receipt){parent::__construct($message);}
}

/**
 * This utility intentionally accepts no arguments. It is invoked through a
 * temporary root/sudo SSH session and can only sign this module on this PBX.
 */
function main(): array
{
    requireCliRoot();
    umask(0077);

    $gpgBinary = findExecutable(['/usr/bin/gpg', '/bin/gpg', '/usr/local/bin/gpg']);
    $runuserBinary = findExecutable(['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser']);

    ensureRootDirectory(SIGNER_HOME, 0700);
    $lock = acquireSignerLock();

    try {
        $pending=SIGNER_HOME.'/pending-signature.json';
        if (is_link($pending)) { throw new RuntimeException('Pending signature receipt cannot be a symbolic link.'); }
        if (is_file($pending)) {
            $receipt=json_decode((string)file_get_contents($pending,false,null,0,65537),true,32,JSON_THROW_ON_ERROR);
            if (!is_array($receipt)||($receipt['state']??'')!=='complete') {
                throw new SignaturePublicationException('An earlier signature publication needs reconciliation; its prepared pair and signing identity were retained.',
                    ['state'=>'recovery_required','recovery_required'=>true,'receipt'=>$pending]);
            }
        }
        bootstrapFreePBX();

        $gpg = \FreePBX::GPG();
        $webRoot = rtrim((string) \FreePBX::Config()->get('AMPWEBROOT'), '/');
        if ($webRoot === '' || $webRoot[0] !== '/') {
            throw new RuntimeException('FreePBX returned an invalid AMPWEBROOT.');
        }

        $moduleDirectory = $webRoot . '/admin/modules/' . MODULE_NAME;
        validateModuleDirectory($moduleDirectory);
        validateModuleIdentity($moduleDirectory . '/module.xml');

        $webUser = (string) \FreePBX::Config()->get('AMPASTERISKWEBUSER');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $webUser)) {
            throw new RuntimeException('FreePBX returned an invalid web-service account name.');
        }

        $webAccount = posix_getpwnam($webUser);
        if (!is_array($webAccount) || !isset($webAccount['uid'], $webAccount['gid'])) {
            throw new RuntimeException('The FreePBX web-service account does not exist.');
        }

        $serviceHome = is_dir((string) ($webAccount['dir'] ?? ''))
            ? (string) $webAccount['dir']
            : (string) \FreePBX::Config()->get('ASTSPOOLDIR');
        if ($serviceHome === '' || $serviceHome[0] !== '/' || !is_dir($serviceHome) || is_link($serviceHome)) {
            throw new RuntimeException('FreePBX returned an unsafe web-service home directory.');
        }
        $expectedVerifierHome = rtrim($serviceHome, '/') . '/.gnupg';
        if (!file_exists($expectedVerifierHome)) {
            // Let the unprivileged service account create its own GPG directory;
            // root never changes metadata through a service-owned pathname.
            runCommand([
                $runuserBinary, '-u', $webUser, '--', $gpgBinary,
                '--homedir', $expectedVerifierHome, '--batch', '--list-keys',
            ], null, verifierEnvironment($webUser, $expectedVerifierHome));
        }
        // fwconsole chown may relax this directory after module installation.
        // Normalize it as the owning service account, never through a
        // privileged path operation.
        $chmodBinary = findExecutable(['/bin/chmod', '/usr/bin/chmod']);
        runCommand([
            $runuserBinary, '-u', $webUser, '--', $chmodBinary, '0700', $expectedVerifierHome,
        ], null, verifierEnvironment($webUser, $expectedVerifierHome));
        $verifierHome = (string) $gpg->getGpgLocation();
        if (rtrim($verifierHome, '/') !== $expectedVerifierHome) {
            throw new RuntimeException('FreePBX GPG verifier location does not match its service account home.');
        }
        prepareVerifierHome($verifierHome, (int) $webAccount['uid'], (int) $webAccount['gid']);

        [$fingerprint, $keyCreated] = ensureSigningKey($gpgBinary);
        importVerifierPublicKey($gpgBinary, $runuserBinary, $webUser, $verifierHome, $fingerprint);

        $hashes = $gpg->getHashes($moduleDirectory);
        if (!is_array($hashes)) {
            throw new RuntimeException('FreePBX did not return a module hash list.');
        }
        unset($hashes['module.sig']);
        ksort($hashes, SORT_STRING);

        $secureManifest = buildManifest($hashes, 'manual', $fingerprint);
        $secureSignature = clearSign($gpgBinary, $fingerprint, $secureManifest);
        // Finish both signatures before replacing either installed file.
        $outerManifest = buildManifest([MODULE_NAME . '.sig' => hash('sha256', $secureSignature)], 'local', $fingerprint);
        $outerSignature = clearSign($gpgBinary, $fingerprint, $outerManifest);
        $moduleSignaturePath = $moduleDirectory . '/module.sig';
        validateReplaceableFile($moduleSignaturePath, false);

        ensureSecureDirectory();
        $secureSignaturePath = SECURE_DIRECTORY . '/' . MODULE_NAME . '.sig';
        validateReplaceableFile($secureSignaturePath, true);
        $receipt=publishSignaturePair($secureSignaturePath,$secureSignature,$moduleSignaturePath,$outerSignature,
            (int)$webAccount['uid'],(int)$webAccount['gid'],$fingerprint);

        clearstatcache(true);
        try { $verification = $gpg->verifyModule(MODULE_NAME); }
        catch (Throwable $error) {
            throw new SignaturePublicationException('Signature pair installed; trust verification failed: '.$error->getMessage(),
                ['state'=>'recovery_required','recovery_required'=>true,'receipt'=>$pending,'transaction'=>$receipt['transaction']]);
        }
        $status = isset($verification['status']) ? (int) $verification['status'] : 0;
        $details = normalizeDetails($verification['details'] ?? ($verification['trustdetails'] ?? []));
        if ($status !== EXPECTED_SIGNATURE_STATUS) {
            throw new SignaturePublicationException(
                'FreePBX rejected the local signature (status ' . $status .
                ($details !== [] ? ': ' . implode('; ', $details) : '') . '); prepared pair and before-state retained',
                ['state'=>'recovery_required','recovery_required'=>true,'receipt'=>$pending,'transaction'=>$receipt['transaction']]
            );
        }
        $receipt['state']='complete';$receipt['verified_status']=$status;
        try { atomicWrite($pending,json_encode($receipt,JSON_THROW_ON_ERROR),0,0,0600); }
        catch (Throwable $error) {
            throw new SignaturePublicationException('Signature pair verified; completion receipt could not be saved: '.$error->getMessage(),
                ['state'=>'recovery_required','recovery_required'=>true,'trusted'=>true,'status'=>$status,'receipt'=>$pending,'transaction'=>$receipt['transaction']]);
        }

        // verifyModule() above is the authoritative local result. Do not launch
        // FreePBX's fleet-wide key refresh here: unreachable public keyservers
        // can leave detached GPG workers even though this local signature is
        // already valid. FreePBX refreshes its notification cache separately.
        $notificationRefreshed = false;
        $details[] = 'Signature notification refresh deferred to FreePBX';

        return [
            'ok' => true,
            'trusted' => true,
            'module' => MODULE_NAME,
            'state' => 'locally_signed',
            'status' => $status,
            'fingerprint' => $fingerprint,
            'key_created' => $keyCreated,
            'notification_refreshed' => $notificationRefreshed,
            'details' => $details,
        ];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function requireCliRoot(): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('This signing utility is CLI-only.');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Local module signing must run as root.');
    }
}

function bootstrapFreePBX(): void
{
    $bootstrap = '/etc/freepbx.conf';
    if (!is_file($bootstrap)) {
        throw new RuntimeException('FreePBX bootstrap file was not found.');
    }

    // freepbx.conf populates $amp_conf and framework code subsequently reads it
    // from the global scope. An include inside this namespaced function must
    // therefore bind both bootstrap variables explicitly to $GLOBALS.
    global $amp_conf, $bootstrap_settings;
    $bootstrap_settings = ['freepbx_auth' => false];
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        require_once $bootstrap;
    } finally {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
    }

    if (!class_exists('FreePBX')) {
        throw new RuntimeException('FreePBX bootstrap did not initialize the application.');
    }
}

function findExecutable(array $candidates): string
{
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('A required system executable is unavailable.');
}

function ensureRootDirectory(string $path, int $mode): void
{
    if (is_link($path)) {
        throw new RuntimeException('Refusing to use a symbolic-link signer directory.');
    }
    if (!file_exists($path) && !mkdir($path, $mode, false)) {
        throw new RuntimeException('Unable to create the local signer directory.');
    }
    if (!is_dir($path)) {
        throw new RuntimeException('The local signer path is not a directory.');
    }

    $stat = stat($path);
    if (!is_array($stat) || (int) $stat['uid'] !== 0) {
        throw new RuntimeException('The local signer directory must be owned by root.');
    }
    if (!chmod($path, $mode)) {
        throw new RuntimeException('Unable to secure the local signer directory.');
    }
}

function acquireSignerLock()
{
    $lockPath = SIGNER_HOME . '/sign.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Refusing to follow a symbolic-link signer lock.');
    }
    $lock = fopen($lockPath, 'c');
    if (!is_resource($lock)) {
        throw new RuntimeException('Unable to open the local signer lock.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('Another local module signing operation is already running.');
    }
    $openStat = fstat($lock);
    $pathStat = lstat($lockPath);
    if (!is_array($openStat) || !is_array($pathStat)
        || $openStat['dev'] !== $pathStat['dev']
        || $openStat['ino'] !== $pathStat['ino']
    ) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('The local signer lock path changed unexpectedly.');
    }
    // Some FreePBX PHP builds disable fchmod/fchown/fchgrp. The inode check
    // above and the root-only parent directory make the path operation safe.
    if (!chmod($lockPath, 0600)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('Unable to secure the local signer lock.');
    }
    return $lock;
}

function validateModuleDirectory(string $moduleDirectory): void
{
    if (is_link($moduleDirectory)) {
        throw new RuntimeException('Refusing to sign a module through a symbolic link.');
    }
    if (!is_dir($moduleDirectory)) {
        throw new RuntimeException('The installed ucmprovisioner module directory was not found.');
    }
    $real = realpath($moduleDirectory);
    if ($real === false || $real !== $moduleDirectory) {
        throw new RuntimeException('The installed module directory did not resolve to the expected path.');
    }
}

function validateModuleIdentity(string $moduleXml): void
{
    if (is_link($moduleXml) || !is_file($moduleXml)) {
        throw new RuntimeException('The installed module.xml is missing or unsafe.');
    }

    $previous = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_file($moduleXml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$xml instanceof \SimpleXMLElement || trim((string) $xml->rawname) !== MODULE_NAME) {
        throw new RuntimeException('The installed module identity is not ucmprovisioner.');
    }
}

function prepareVerifierHome(string $path, int $uid, int $gid): void
{
    if ($path === '' || $path[0] !== '/' || is_link($path) || !is_dir($path)) {
        throw new RuntimeException('FreePBX returned an unsafe GPG verifier directory.');
    }

    $stat = stat($path);
    if (!is_array($stat)) {
        throw new RuntimeException('Unable to inspect the FreePBX GPG verifier directory.');
    }

    if ((int) $stat['uid'] !== $uid || (int) $stat['gid'] !== $gid) {
        throw new RuntimeException('The FreePBX GPG directory is not owned by its service account.');
    }
    if (((int) $stat['mode'] & 0077) !== 0) {
        throw new RuntimeException('The FreePBX GPG directory grants group or other access; expected mode 0700.');
    }
}

function ensureSigningKey(string $gpgBinary): array
{
    $fingerprintFile = SIGNER_HOME . '/key.fingerprint';
    if (is_link($fingerprintFile)) {
        throw new RuntimeException('Refusing to follow a symbolic-link signer fingerprint.');
    }

    $fingerprints = listSecretKeyFingerprints($gpgBinary);
    $keyCreated = false;
    if ($fingerprints === []) {
        if (file_exists($fingerprintFile)) {
            throw new RuntimeException('The recorded signing key is missing; preserve the fingerprint and recover the existing key before signing.');
        }
        $hostname = preg_replace('/[^A-Za-z0-9._-]/', '-', gethostname() ?: 'freepbx');
        $hostname = substr((string) $hostname, 0, 63);
        $identity = 'UCM Provisioner Local Signer (' . $hostname . ') <ucmprovisioner@localhost>';
        runCommand([
            $gpgBinary,
            '--homedir', SIGNER_HOME,
            '--batch',
            '--yes',
            '--pinentry-mode', 'loopback',
            '--passphrase', '',
            '--quick-generate-key', $identity,
            'rsa3072',
            'sign',
            '0',
        ], null, signerEnvironment());
        $fingerprints = listSecretKeyFingerprints($gpgBinary);
        $keyCreated = true;
    }

    if (count($fingerprints) !== 1) {
        throw new RuntimeException('The dedicated signer keyring must contain exactly one primary secret key.');
    }
    $fingerprint = $fingerprints[0];

    if (is_file($fingerprintFile)) {
        $recorded = strtoupper(trim((string) file_get_contents($fingerprintFile)));
        if (!hash_equals($recorded, $fingerprint)) {
            throw new RuntimeException('The signer fingerprint record does not match its private key.');
        }
    } else {
        atomicWrite($fingerprintFile, $fingerprint . "\n", 0, 0, 0600);
    }

    return [$fingerprint, $keyCreated];
}

function listSecretKeyFingerprints(string $gpgBinary): array
{
    $result = runCommand([
        $gpgBinary,
        '--homedir', SIGNER_HOME,
        '--batch',
        '--with-colons',
        '--fingerprint',
        '--list-secret-keys',
    ], null, signerEnvironment());

    $fingerprints = [];
    $expectFingerprint = false;
    foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $line) {
        $fields = explode(':', $line);
        $recordType = $fields[0] ?? '';
        if ($recordType === 'sec') {
            $expectFingerprint = true;
            continue;
        }
        if ($recordType === 'ssb') {
            $expectFingerprint = false;
            continue;
        }
        if ($expectFingerprint && $recordType === 'fpr') {
            $fingerprint = strtoupper($fields[9] ?? '');
            if (!preg_match('/^[A-F0-9]{40,64}$/', $fingerprint)) {
                throw new RuntimeException('GnuPG returned an invalid signer fingerprint.');
            }
            $fingerprints[] = $fingerprint;
            $expectFingerprint = false;
        }
    }
    return array_values(array_unique($fingerprints));
}

function importVerifierPublicKey(
    string $gpgBinary,
    string $runuserBinary,
    string $webUser,
    string $verifierHome,
    string $fingerprint
): void {
    $export = runCommand([
        $gpgBinary,
        '--homedir', SIGNER_HOME,
        '--batch',
        '--armor',
        '--export', $fingerprint,
    ], null, signerEnvironment());
    if (strpos($export['stdout'], '-----BEGIN PGP PUBLIC KEY BLOCK-----') === false) {
        throw new RuntimeException('GnuPG did not export the local signer public key.');
    }

    $base = [
        $runuserBinary,
        '-u', $webUser,
        '--',
        $gpgBinary,
        '--homedir', $verifierHome,
        '--batch',
    ];
    runCommand(array_merge($base, ['--import']), $export['stdout'], verifierEnvironment($webUser, $verifierHome));

    $listed = runCommand(
        array_merge($base, ['--with-colons', '--fingerprint', '--list-keys', $fingerprint]),
        null,
        verifierEnvironment($webUser, $verifierHome)
    );
    if (stripos($listed['stdout'], $fingerprint) === false) {
        throw new RuntimeException('The signer public key was not installed in the FreePBX verifier keyring.');
    }

    // Local-signature trust comes from /etc/freepbx.secure being root-owned.
    // Owner-trusting this key would incorrectly make it a fleet-wide trust
    // authority for ordinary (non-local) module signatures.
    $ownerTrust = runCommand(
        array_merge($base, ['--export-ownertrust']),
        null,
        verifierEnvironment($webUser, $verifierHome)
    );
    foreach (preg_split('/\r?\n/', $ownerTrust['stdout']) ?: [] as $line) {
        if (preg_match('/^([A-F0-9]{40,64}):([0-9]):/i', trim($line), $matches)
            && strtoupper($matches[1]) === $fingerprint
            && (int) $matches[2] >= 4
        ) {
            throw new RuntimeException('The local signer key is owner-trusted; remove that trust before local signing.');
        }
    }
}

function ensureSecureDirectory(): void
{
    if (is_link(SECURE_DIRECTORY)) {
        throw new RuntimeException('Refusing to use a symbolic-link FreePBX secure directory.');
    }
    if (!file_exists(SECURE_DIRECTORY) && !mkdir(SECURE_DIRECTORY, 0755, false)) {
        throw new RuntimeException('Unable to create the FreePBX secure signing directory.');
    }
    if (!is_dir(SECURE_DIRECTORY)) {
        throw new RuntimeException('The FreePBX secure signing path is not a directory.');
    }

    $stat = stat(SECURE_DIRECTORY);
    if (!is_array($stat) || (int) $stat['uid'] !== 0) {
        throw new RuntimeException('The FreePBX secure signing directory must be owned by root.');
    }
    if (!chmod(SECURE_DIRECTORY, 0755)) {
        throw new RuntimeException('Unable to secure the FreePBX secure signing directory.');
    }
}

function buildManifest(array $hashes, string $repo, string $fingerprint): string
{
    $header = <<<'HEADER'
;################################################
;# FreePBX Module Signature File                #
;################################################
;# Do not alter the contents of this file! If   #
;# this file is tampered with, the module will  #
;# fail validation and be marked as invalid!    #
;################################################
HEADER;

    $manifest = $header . "\n[config]\n";
    $manifest .= "version=2\n";
    $manifest .= "hash=sha256\n";
    $manifest .= 'signedwith=' . $fingerprint . "\n";
    $manifest .= "signedby=ucmprovisioner-sign-local\n";
    $manifest .= 'repo=' . $repo . "\n";
    $manifest .= "type=local\n";
    $manifest .= "[hashes]\n";

    foreach ($hashes as $file => $hash) {
        $file = (string) $file;
        $hash = strtolower((string) $hash);
        if ($file === '' || preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D', $file) !== 1
            || array_intersect(explode('/', $file), ['.','..'])) {
            throw new RuntimeException('The module contains a filename that cannot be signed safely.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('FreePBX returned an invalid module file hash.');
        }
        $manifest .= $file . ' = ' . $hash . "\n";
    }
    return $manifest . ";# End\n";
}

function clearSign(string $gpgBinary, string $fingerprint, string $payload): string
{
    $result = runCommand([
        $gpgBinary,
        '--homedir', SIGNER_HOME,
        '--batch',
        '--yes',
        '--pinentry-mode', 'loopback',
        '--passphrase', '',
        '--local-user', $fingerprint,
        '--digest-algo', 'SHA256',
        '--armor',
        '--clearsign',
    ], $payload, signerEnvironment());
    if (strpos($result['stdout'], '-----BEGIN PGP SIGNED MESSAGE-----') !== 0) {
        throw new RuntimeException('GnuPG did not create a clear-signed module manifest.');
    }
    return $result['stdout'];
}

function validateReplaceableFile(string $path, bool $mustBeRootOwned): void
{
    if (is_link($path)) {
        throw new RuntimeException('Refusing to replace a symbolic-link signature file.');
    }
    if (!file_exists($path)) {
        return;
    }
    if (!is_file($path)) {
        throw new RuntimeException('The signature destination is not a regular file.');
    }
    if ($mustBeRootOwned) {
        $stat = stat($path);
        if (!is_array($stat) || (int) $stat['uid'] !== 0) {
            throw new RuntimeException('The existing local signature must be owned by root.');
        }
    }
}

function publishSignaturePair(string $securePath,string $secureBytes,string $modulePath,string $moduleBytes,int $uid,int $gid,string $fingerprint):array
{
    $directory=SIGNER_HOME.'/signature-'.bin2hex(random_bytes(12));
    ensureRootDirectory($directory,0700);
    $receipt=['schema'=>1,'state'=>'prepared','fingerprint'=>$fingerprint,'transaction'=>$directory,'files'=>[]];
    foreach ([[$securePath,$secureBytes],[$modulePath,$moduleBytes]] as $index=>[$path,$bytes]) {
        validateReplaceableFile($path,$index===0);
        $before=null;
        if(is_file($path)){
            $before=file_get_contents($path,false,null,0,16777217);
            if(!is_string($before)||strlen($before)>16777216){throw new RuntimeException('Existing signature cannot be safely retained.');}
            atomicWrite($directory.'/before-'.$index.'.sig',$before,0,0,0600);
        }
        atomicWrite($directory.'/prepared-'.$index.'.sig',$bytes,0,0,0600);
        $receipt['files'][]=['path'=>$path,'sha256'=>hash('sha256',$bytes),'before_sha256'=>$before===null?null:hash('sha256',$before)];
    }
    $pending=SIGNER_HOME.'/pending-signature.json';
    atomicWrite($pending,json_encode($receipt,JSON_THROW_ON_ERROR),0,0,0600);
    try {
        foreach ([[$securePath,$secureBytes,0,0],[$modulePath,$moduleBytes,$uid,$gid]] as $index=>[$path,$bytes,$owner,$group]) {
            atomicWrite($path,$bytes,$owner,$group,0644);
            if(!hash_equals(hash('sha256',$bytes),(string)hash_file('sha256',$path))){throw new RuntimeException('Installed signature readback differs from the prepared bytes.');}
            $receipt['state']=$index===0?'secure_installed':'pair_installed';
            atomicWrite($pending,json_encode($receipt,JSON_THROW_ON_ERROR),0,0,0600);
        }
    }catch(Throwable $failure){
        throw new SignaturePublicationException('Signature publication is incomplete; reconcile its retained prepared pair before retrying: '.$failure->getMessage(),
            ['state'=>'recovery_required','recovery_required'=>true,'receipt'=>$pending,'transaction'=>$directory]);
    }
    return $receipt;
}

function atomicWrite(string $destination, string $contents, int $uid, int $gid, int $mode): void
{
    // A service-owned directory can be renamed by that service. Never perform
    // root chown/chmod through its temporary path; publish with the target UID.
    if (posix_geteuid()===0 && $uid!==0) {
        $account=posix_getpwuid($uid);
        if(!is_array($account)||!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/',(string)$account['name'])){throw new RuntimeException('Signature service account is invalid.');}
        $runuser=findExecutable(['/usr/sbin/runuser','/sbin/runuser','/usr/bin/runuser']);
        $code='require $argv[1]; \\UcmProvisioner\\Signing\\atomicWrite($argv[2],stream_get_contents(STDIN),(int)$argv[3],(int)$argv[4],(int)$argv[5]);';
        runCommand([$runuser,'-u',$account['name'],'--',PHP_BINARY,'-r',$code,__FILE__,$destination,(string)$uid,(string)$gid,(string)$mode],$contents,null,30);
        return;
    }
    $directory = dirname($destination);
    if (!is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('The signature destination directory is unsafe.');
    }

    $temporary = '';
    $handle = false;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = $directory . '/.ucmp-sign-' . bin2hex(random_bytes(16));
        $candidateHandle = @fopen($candidate, 'x+b');
        if (is_resource($candidateHandle)) {
            $temporary = $candidate;
            $handle = $candidateHandle;
            break;
        }
    }
    if (!is_resource($handle)) {
        throw new RuntimeException('Unable to create an atomic signature output file.');
    }

    try {
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($handle, substr($contents, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Unable to write the signature output file.');
            }
            $written += $count;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Unable to flush the signature output file.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Unable to synchronize the signature output file.');
        }
        $openStat = fstat($handle);
        $pathStat = lstat($temporary);
        if (!is_array($openStat) || !is_array($pathStat)
            || $openStat['dev'] !== $pathStat['dev']
            || $openStat['ino'] !== $pathStat['ino']
        ) {
            throw new RuntimeException('The atomic signature output path changed unexpectedly.');
        }

        if (!chown($temporary, $uid) || !chgrp($temporary, $gid) || !chmod($temporary, $mode)) {
            throw new RuntimeException('Unable to set signature file ownership or permissions.');
        }
        clearstatcache(true, $temporary);
        $pathStat = lstat($temporary);
        if (!is_array($pathStat)
            || $openStat['dev'] !== $pathStat['dev']
            || $openStat['ino'] !== $pathStat['ino']
        ) {
            throw new RuntimeException('The signature output path changed while setting permissions.');
        }

        if (!rename($temporary, $destination)) {
            throw new RuntimeException('Unable to install the signature output file atomically.');
        }
        $temporary = '';
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($temporary !== '' && file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

function runCommand(array $command, ?string $stdin = null, ?array $environment = null, float $timeoutSeconds = 120): array
{
    $limit = 32 * 1024 * 1024;
    if ($timeoutSeconds <= 0 || $timeoutSeconds > 300 || strlen($stdin ?? '') > 16 * 1024 * 1024) {
        throw new RuntimeException('Signing process input or timeout exceeds its bound.');
    }
    $streams = []; $process = null; $pipes = [];
    try {
        // Private files avoid blocking while a child writes stderr before reading stdin.
        for ($index = 0; $index < 3; $index++) {
            $stream = tmpfile();
            if (!is_resource($stream)) { throw new RuntimeException('Unable to allocate private signing process streams.'); }
            $streams[] = $stream;
        }
        $input = $stdin ?? '';
        for ($written = 0; $written < strlen($input); $written += $count) {
            $count = fwrite($streams[0], substr($input, $written, 65536));
            if ($count === false || $count === 0) { throw new RuntimeException('Unable to stage signing process input.'); }
        }
        rewind($streams[0]);
        $executable = $command;
        if (PHP_OS_FAMILY !== 'Windows') {
            $timeout = findExecutable(['/usr/bin/timeout', '/bin/timeout']);
            $executable = [$timeout, '--signal=TERM', '--kill-after=5', (string)$timeoutSeconds, ...$command];
        }
        $process = proc_open($executable, $streams, $pipes, sys_get_temp_dir(), $environment, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start a required signing process.'); }
        $deadline = microtime(true) + $timeoutSeconds + (PHP_OS_FAMILY === 'Windows' ? 0 : 6);
        do {
            foreach ([1,2] as $index) {
                $size = fstat($streams[$index]);
                if (!is_array($size) || $size['size'] > $limit) { throw new RuntimeException('Signing process output exceeded its safe bound.'); }
            }
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (microtime(true) >= $deadline) { throw new RuntimeException('Signing process exceeded its time limit.'); }
            usleep(10000);
        } while (true);
        $closed = proc_close($process); $process = null;
        $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        rewind($streams[1]); rewind($streams[2]);
        $stdout = stream_get_contents($streams[1], $limit + 1);
        $stderr = stream_get_contents($streams[2], $limit + 1);
        if (!is_string($stdout) || !is_string($stderr) || strlen($stdout) > $limit || strlen($stderr) > $limit) {
            throw new RuntimeException('Signing process output exceeded its safe bound.');
        }
        if ($exitCode !== 0) {
            $program = basename((string)($command[0] ?? 'process'));
            $message = sanitizeMessage($stderr);
            throw new RuntimeException($program . ' failed' . ($message !== '' ? ': ' . $message : ' with exit code ' . $exitCode . '.'));
        }
        return ['stdout'=>$stdout, 'stderr'=>$stderr, 'exit_code'=>$exitCode];
    } finally {
        if (is_resource($process)) {
            // GNU timeout owns its child group on Linux; terminate the group as well.
            $status = proc_get_status($process);
            if ($status['running'] && function_exists('posix_getpgid') && function_exists('posix_kill')
                && posix_getpgid($status['pid']) === $status['pid']) {
                posix_kill(-$status['pid'], 9);
            }
            proc_terminate($process, 9);
            proc_close($process);
        }
        foreach ($streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
    }
}

function signerEnvironment(): array
{
    return [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => SIGNER_HOME,
        'GNUPGHOME' => SIGNER_HOME,
        'USER' => 'root',
        'LOGNAME' => 'root',
        'LANG' => 'C',
        'LC_ALL' => 'C',
    ];
}

function verifierEnvironment(string $webUser, string $gpgHome): array
{
    $home = preg_replace('#/\.gnupg/?$#', '', $gpgHome);
    return [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => (string) $home,
        'USER' => $webUser,
        'LOGNAME' => $webUser,
        'LANG' => 'C',
        'LC_ALL' => 'C',
    ];
}

function normalizeDetails($details): array
{
    if (!is_array($details)) {
        $details = [$details];
    }
    $normalized = [];
    foreach ($details as $detail) {
        if (is_scalar($detail)) {
            $message = sanitizeMessage((string) $detail);
            if ($message !== '') {
                $normalized[] = $message;
            }
        }
    }
    return array_values(array_unique($normalized));
}

function sanitizeMessage(string $message): string
{
    $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($message));
    return substr((string) $message, 0, 800);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__FILE__)) { return; }

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    $result = main();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $error = [
        'ok' => false,
        'module' => MODULE_NAME,
        'state' => 'error',
        'error' => sanitizeMessage($exception->getMessage()),
    ];
    if ($exception instanceof SignaturePublicationException) { $error=array_replace($error,$exception->receipt); }
    echo json_encode($error, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
