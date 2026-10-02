<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$source = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$start = strpos($source, 'public function installRemoteModule(');
$end = strpos($source, 'public function signSiteModule(', $start === false ? 0 : $start);
$expect($start !== false && $end !== false, 'Remote install method boundaries must remain discoverable');
$install = substr($source, $start, $end - $start);

$expect(str_contains($install, '$this->bootstrapPbxAgent('), 'Remote module installation must enroll the PBX agent automatically');
$expect(str_contains($install, "remoteQueryViaSsh(\$siteId, 'moduleStatus')"), 'Remote installation must verify a fresh module identity/version through the key-based RPC');
$expect(str_contains($install, 'credentialStorageReadiness()'), 'Remote installation must verify credential storage as asterisk');
$expect(str_contains($install, 'runuser -u asterisk'), 'Credential readiness must not run through the unprivileged forced-command account');
$expect(str_contains($install, '/usr/sbin/fwconsole chown\\n')
    && !str_contains($install, '/usr/sbin/fwconsole chown --module'), 'Remote install must retain the requested PBX-wide permission reset');
$expect(str_contains($install, "'status'=>'installed_but_not_ready'"), 'Enrollment/readiness failure must expose the installed-but-not-ready state');
$expect(str_contains($install, "'status'=>'recovery_required'"), 'Post-swap installer failure must expose an explicit recovery-required state');
$expect(str_contains($install, 'UPDATE ucmp_sites SET pbx_agent_ready=0'), 'Failed enrollment/readiness must clear stale agent readiness');
$expect(str_contains($install, 'ucmprovisioner-failed-update') && str_contains($install, 'ucmprovisioner-pre-update'), 'Failed installs must retain both new and previous module trees');
$expect(!str_contains($install, 'fwconsole ma install ucmprovisioner || true'), 'Remote install must not blindly reinstall the old module after a failure');
$expect(!str_contains($install, 'Enroll/repair ucmp-pbx next'), 'Successful remote installation must not leave enrollment as a manual next step');

echo "Remote module installation, enrollment, readiness and rollback-safety contracts passed\n";
