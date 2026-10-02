<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/BuildPlanner.php';
require_once __DIR__ . '/../lib/CiscoCnfGenerator.php';
require_once __DIR__ . '/../lib/TftpStore.php';
require_once __DIR__ . '/../lib/ModelCatalog.php';
require_once __DIR__ . '/../lib/CiscoTimezoneService.php';
require_once __DIR__ . '/../lib/FirmwareService.php';

use FreePBX\modules\Ucmprovisioner\BuildPlanner;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

final class GenerationSftpStub
{
    /** @var array<string,string> */
    public array $files = [];
    /** @var array<string,bool> */
    public array $directories = ['/' => true, '/tftpboot' => true];
    /** @var array<string,int> */
    public array $modes = ['/' => 0755, '/tftpboot' => 0755];
    public ?string $failActivationTarget = null;
    public ?string $failRestoreTarget = null;
    public ?string $corruptReadTarget = null;
    public ?string $failChmodTarget = null;
    public array $links = [];
    public array $readRequests = [];
    public ?string $growOnDownload = null;
    public string $loseReplacementReply = '';
    public bool $rejectReplacementCleanup = false;
    public ?string $failCompanionTarget = null;
    public bool $loseCommittedReceipt = false;
    private bool $failedOnce = false;

    public function file_exists(string $path): bool
    {
        return isset($this->files[$path]) || isset($this->directories[$path]);
    }

    public function stat(string $path)
    {
        if (isset($this->files[$path])) {
            return ['type' => 1, 'size' => strlen($this->files[$path]), 'mode'=>$this->modes[$path] ?? 0664];
        }
        return isset($this->directories[$path]) ? ['type' => 2, 'mode'=>$this->modes[$path] ?? 0755] : false;
    }

    public function lstat(string $path) { return isset($this->links[$path]) ? ['type'=>3,'size'=>0] : $this->stat($path); }

    public function mkdir(string $path, int $mode = -1, bool $recursive = false): bool
    {
        if (!$recursive && isset($this->directories[$path])) { return false; }
        $cursor = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            $cursor .= '/' . $part;
            $this->directories[$cursor] = true;
            $this->modes[$cursor] = $mode >= 0 ? $mode : 0777;
        }
        return true;
    }

    public function rmdir(string $path): bool
    {
        if (!isset($this->directories[$path])) { return false; }
        unset($this->directories[$path]);
        return true;
    }

    public function put(string $path, string $content): bool
    {
        $this->files[$path] = $content;
        return true;
    }

    public function get(string $path, $local = false, int $offset = 0, int $length = -1)
    {
        $this->readRequests[] = ['path'=>$path,'length'=>$length];
        if (is_string($local) && $path === $this->growOnDownload) { $this->files[$path] .= 'grew'; }
        if ($path === $this->corruptReadTarget) { return 'corrupted isolated readback'; }
        if (!isset($this->files[$path])) { return false; }
        $bytes = $length < 0 ? substr($this->files[$path], $offset) : substr($this->files[$path], $offset, $length);
        if (is_string($local)) { return file_put_contents($local, $bytes) !== false; }
        return $bytes;
    }

    public function chmod(int $mode, string $path): bool
    {
        if ($this->failChmodTarget !== null && ($path === $this->failChmodTarget || str_starts_with($path, $this->failChmodTarget . '.tmp-'))) { return false; }
        if (!$this->file_exists($path)) { return false; }
        $this->modes[$path] = $mode;
        return true;
    }

    public function delete(string $path): bool
    {
        if ($this->rejectReplacementCleanup && str_contains($path, '.bak-')) { return false; }
        if (!isset($this->files[$path])) {
            return false;
        }
        unset($this->files[$path]);
        unset($this->modes[$path]);
        return true;
    }

    public function rename(string $from, string $to): bool
    {
        if ($to===$this->failCompanionTarget) { $this->failCompanionTarget=null;return false; }
        if ($this->failRestoreTarget === $to && str_contains($from, '/backup/')) {
            $this->failRestoreTarget = null;
            return false;
        }
        if (
            !$this->failedOnce
            && $this->failActivationTarget === $to
            && str_contains($from, '/stage/')
        ) {
            $this->failedOnce = true;
            return false;
        }
        if (!isset($this->files[$from])) {
            return false;
        }
        $this->files[$to] = $this->files[$from];
        if (isset($this->modes[$from])) { $this->modes[$to] = $this->modes[$from]; }
        unset($this->files[$from]);
        unset($this->modes[$from]);
        if ($this->loseCommittedReceipt&&str_ends_with($to,'/receipt.json')&&(json_decode($this->files[$to],true)['state']??'')==='committed') {
            $this->loseCommittedReceipt=false;throw new RuntimeException('Injected lost final generation receipt');
        }
        if (($this->loseReplacementReply === 'preserve' && str_contains($to, '.bak-'))
            || ($this->loseReplacementReply === 'activate' && str_contains($from, '.tmp-'))) {
            $this->loseReplacementReply = '';
            throw new RuntimeException('Injected lost replacement rename reply');
        }
        return true;
    }

    public function posix_rename(string $from, string $to): bool { return $this->rename($from, $to); }

    public function is_dir(string $path): bool
    {
        return isset($this->directories[$path]);
    }

    public function rawlist(string $path): array
    {
        $prefix = rtrim($path, '/') . '/';
        $rows = ['.' => ['type' => 2], '..' => ['type' => 2]];
        foreach ($this->directories as $name => $_) {
            if ($name === $path || !str_starts_with($name, $prefix)) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if ($relative !== '' && !str_contains($relative, '/')) {
                $rows[$relative] = ['type' => 2, 'mtime' => 1, 'mode'=>$this->modes[$name] ?? 0755];
            }
        }
        foreach ($this->files as $name => $content) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if ($relative !== '' && !str_contains($relative, '/')) {
                $rows[$relative] = ['type' => 1, 'size' => strlen($content), 'mtime' => 1, 'mode'=>$this->modes[$name] ?? 0664];
            }
        }
        return $rows;
    }
}

final class GenerationConnectionStub
{
    public function __construct(private GenerationSftpStub $sftp) {}
    public function dockerSftp(): GenerationSftpStub { return $this->sftp; }
    public function tftpRoot(): string { return '/tftpboot'; }
}

final class GenerationUcmStub
{
    public bool $failOverrideTracking = false;

    /** @var array<string,array<string,mixed>> */
    public array $phoneFiles = [
        'SEP001122334455.cnf.xml'=>[
            'id'=>1,
            'extension'=>'301',
            'config_mode'=>'managed',
        ],
    ];

    public function __construct(private GenerationSftpStub $sftp) {}
    public function siteConnection(int $siteId): GenerationConnectionStub
    {
        if ($siteId !== 1) {
            throw new RuntimeException('Unexpected site');
        }
        return new GenerationConnectionStub($this->sftp);
    }
    public function phoneConfigFilesLocal(int $siteId): array
    {
        return $this->phoneFiles;
    }
    public function recordPhoneConfigOverrideLocal(
        int $siteId,
        int $phoneId,
        string $filename,
        string $sha256
    ): array {
        if ($siteId !== 1 || $phoneId !== 1 || $filename !== 'SEP001122334455.cnf.xml') {
            throw new RuntimeException('Unexpected override identity');
        }
        if ($this->failOverrideTracking) {
            throw new RuntimeException('Simulated override tracking failure');
        }
        $this->phoneFiles[$filename] = [
            'id'=>$phoneId,
            'extension'=>'301',
            'config_mode'=>'manual',
            'config_override_sha256'=>$sha256,
            'config_overridden_at'=>time(),
        ];
        return $this->phoneFiles[$filename];
    }
}

$makePlan = static function (): array {
    $files = [
        ['filename' => 'SEP001122334455.cnf.xml', 'content' => '<new/>', 'role'=>'phone_config', 'phone_id' => 1],
        ['filename' => 'dial.xml', 'content' => '<dial/>', 'role'=>'dial_plan', 'phone_id' => 1],
    ];
    $manifest = (new BuildPlanner())->plan(
        1,
        [[
            'phone' => ['id' => 1, 'extension' => '301'],
            'model' => ['id' => 1, 'vendor' => 'Cisco', 'model' => 'CP-8845', 'family' => 'cisco_xml'],
            'settings' => [],
        ]],
        $files,
        ['requires_patch' => true]
    );
    return [$files, $manifest];
};

[$files, $manifest] = $makePlan();
$sftp = new GenerationSftpStub();
$sftp->files['/tftpboot/SEP001122334455.cnf.xml'] = '<old/>';
$sftp->files['/tftpboot/dial.xml'] = '<old-dial/>';
$ucm = new GenerationUcmStub($sftp);
$store = new TftpStore($ucm);

$staged = $store->stageGeneration(1, $manifest['transaction_id'], $files, $manifest);
$expect(!empty($staged['ok']) && ($staged['staged'] ?? 0) === 2, 'A complete matching generation must stage');
$expect($sftp->files['/tftpboot/SEP001122334455.cnf.xml'] === '<old/>', 'Staging must not expose a new phone configuration');
$again = $store->stageGeneration(1, $manifest['transaction_id'], $files, $manifest);
$expect(!empty($again['ok']) && !empty($again['idempotent']), 'Staging the exact transaction twice must be idempotent');

$committed = $store->commitGeneration(1, $manifest['transaction_id']);
$expect(!empty($committed['ok']), 'A verified generation must commit');
$expect($sftp->files['/tftpboot/SEP001122334455.cnf.xml'] === '<new/>', 'Commit must activate the new phone configuration');
$expect(($sftp->modes['/tftpboot/SEP001122334455.cnf.xml'] ?? 0) === 0664, 'Generated phone configs must be explicitly phone-readable on the ucmp-tftp volume');
$expect($store->currentGeneration(1) === $manifest['transaction_id'], 'Commit must publish the active generation pointer');
$managedWrite = $store->handle([
    'op' => 'put',
    'site_id' => 1,
    'path' => 'SEP001122334455.cnf.xml',
    'content' => '<browser-overwrite/>',
]);
$expect(empty($managedWrite['ok']), 'The TFTP browser must not overwrite an active generated configuration');
$companionOverride = $store->handle([
    'op'=>'put',
    'site_id'=>1,
    'path'=>'dial.xml',
    'content'=>'<unsafe/>',
    'override_managed'=>1,
]);
$expect(empty($companionOverride['ok']), 'The override flag must never unlock a non-phone generated asset');
$explicitOverride = $store->handle([
    'op'=>'put',
    'site_id'=>1,
    'path'=>'SEP001122334455.cnf.xml',
    'content'=>'<browser-override/>',
    'override_managed'=>1,
]);
$expect(!empty($explicitOverride['ok']), 'An explicit root phone-config override must be accepted');
$expect(
    ($ucm->phoneFiles['SEP001122334455.cnf.xml']['config_mode'] ?? '') === 'manual',
    'A successful phone-config override must be tracked against its phone'
);
$ucm->failOverrideTracking = true;
$beforeFailedOverride = $sftp->files['/tftpboot/SEP001122334455.cnf.xml'];
$failedOverride = $store->handle([
    'op'=>'put',
    'site_id'=>1,
    'path'=>'SEP001122334455.cnf.xml',
    'content'=>'<must-not-survive/>',
    'override_managed'=>1,
]);
$expect(empty($failedOverride['ok']), 'A phone override must fail when its owning-phone state cannot be tracked');
$expect(
    $sftp->files['/tftpboot/SEP001122334455.cnf.xml'] === $beforeFailedOverride,
    'A tracking failure must restore the previous physical phone config'
);
$ucm->failOverrideTracking = false;
$internalDelete = $store->handle([
    'op' => 'delete',
    'site_id' => 1,
    'path' => '.ucmprovisioner',
]);
$expect(empty($internalDelete['ok']), 'The TFTP browser must never expose its transaction ledger');
$listing = $store->handle(['op' => 'list', 'site_id' => 1, 'path' => '']);
$listedByName = [];
foreach ((array)($listing['entries'] ?? []) as $listed) {
    $listedByName[(string)$listed['name']] = $listed;
}
$expect(!isset($listedByName['.ucmprovisioner']), 'Internal transaction storage must stay hidden from browser listings');
$expect(!empty($listedByName['SEP001122334455.cnf.xml']['managed']), 'Active generated files must be visibly marked managed');
$expect(!empty($listedByName['SEP001122334455.cnf.xml']['manual_override']), 'Tracked phone overrides must be visible in browser listings');

$activeDial = 'dialplan-tpl1111111111111111.xml';
$priorSoftkeys = 'tplstack-2222222222222222-softkeys.xml';
$obsoleteDial = 'dialplan-tpl3333333333333333.xml';
$obsoletePolicy = 'tplstack-4444444444444444-featurepolicy.xml';
$humanNamed = 'dialplan-tpl14.xml';
$sftp->files['/tftpboot/' . $activeDial] = '<dialTemplate/>';
$sftp->files['/tftpboot/' . $priorSoftkeys] = '<softKeyCfg/>';
$sftp->files['/tftpboot/' . $obsoleteDial] = '<dialTemplate/>';
$sftp->files['/tftpboot/' . $obsoletePolicy] = '<featurePolicy/>';
$sftp->files['/tftpboot/' . $humanNamed] = '<operator-file/>';
$sftp->files['/tftpboot/SEP001122334455.cnf.xml'] =
    '<device><sipProfile><dialTemplate>' . $activeDial . '</dialTemplate></sipProfile></device>';
$sftp->files[
    '/tftpboot/.ucmprovisioner/transactions/' . $manifest['transaction_id']
    . '/backup/SEP001122334455.cnf.xml'
] = '<device><sipProfile><softKeyFile>' . $priorSoftkeys . '</softKeyFile></sipProfile></device>';
$gcFailure=clone $sftp;
$gcFailure->failCompanionTarget='/tftpboot/.ucmprovisioner/transactions/'.$manifest['transaction_id'].'/retired/'.$obsoletePolicy;
$partialCleanup=(new TftpStore(new GenerationUcmStub($gcFailure)))->retireObsoleteCiscoCompanions(1,$manifest['transaction_id']);
$expect(empty($partialCleanup['ok'])&&!empty($partialCleanup['recovery_required'])&&$partialCleanup['retired']===[$obsoleteDial]
    &&isset($gcFailure->files[$partialCleanup['receipt']])
    &&isset($gcFailure->directories['/tftpboot/.ucmprovisioner/write-lock']),
    'Late companion retirement must retain completed moves, pending receipt and writer exclusion');
$cleanup = $store->retireObsoleteCiscoCompanions(1, $manifest['transaction_id']);
$expect(!empty($cleanup['ok']), 'A committed full-site generation must support bounded Cisco companion cleanup');
$expect(
    isset($sftp->files['/tftpboot/' . $activeDial])
    && isset($sftp->files['/tftpboot/' . $priorSoftkeys]),
    'Cleanup must preserve references from the live and immediately prior SEP configs'
);
$expect(
    !isset($sftp->files['/tftpboot/' . $obsoleteDial])
    && !isset($sftp->files['/tftpboot/' . $obsoletePolicy])
    && isset($sftp->files[
        '/tftpboot/.ucmprovisioner/transactions/' . $manifest['transaction_id']
        . '/retired/' . $obsoleteDial
    ])
    && isset($sftp->files[
        '/tftpboot/.ucmprovisioner/transactions/' . $manifest['transaction_id']
        . '/retired/' . $obsoletePolicy
    ]),
    'Only unreferenced 16-hex generated companions must leave the public TFTP root'
);
$expect(
    isset($sftp->files['/tftpboot/' . $humanNamed]),
    'Cleanup must never infer ownership from a human/template-id XML filename'
);
$provisionerSource = (string)file_get_contents(__DIR__ . '/../lib/Provisioner.php');
$expect(
    str_contains($provisionerSource, 'if ($fullSiteBuild)')
    && str_contains($provisionerSource, 'retireObsoleteCiscoCompanions($siteId, $transactionId)'),
    'Cisco companion cleanup must be invoked only from a successful full-site build path'
);

$sftp->files['/tftpboot/trace-a.txt'] = 'a';
$sftp->files['/tftpboot/trace-b.txt'] = 'b';
$batchDelete = $store->handle([
    'op'=>'delete_batch',
    'site_id'=>1,
    'paths'=>['trace-a.txt','trace-b.txt'],
]);
$expect(!empty($batchDelete['ok']) && count($batchDelete['deleted'] ?? []) === 2, 'A bounded file batch must use the browser delete path');
$expect(
    !isset($sftp->files['/tftpboot/trace-a.txt']) && !isset($sftp->files['/tftpboot/trace-b.txt']),
    'Every selected ordinary file must be deleted'
);
$sftp->files['/tftpboot/keep.txt'] = 'keep';
$unsafeBatch = $store->handle([
    'op'=>'delete_batch',
    'site_id'=>1,
    'paths'=>['keep.txt','.ucmprovisioner/current.json'],
]);
$expect(empty($unsafeBatch['ok']) && isset($sftp->files['/tftpboot/keep.txt']), 'Batch validation must reject internal paths before deleting any file');
$managedBatch = $store->handle([
    'op'=>'delete_batch',
    'site_id'=>1,
    'paths'=>['SEP001122334455.cnf.xml'],
]);
$expect(empty($managedBatch['ok']), 'Batch delete must reject managed and manual phone configs');

$sftp->files['/tftpboot/SIP001122334455.cnf'] = 'legacy phone config';
$sftp->modes['/tftpboot/SIP001122334455.cnf'] = 0664;
$dedicatedDelete = $store->deletePhoneConfig(1, 'SIP001122334455.cnf');
$expect(
    !empty($dedicatedDelete['ok']) && !isset($sftp->files['/tftpboot/SIP001122334455.cnf']),
    'Phone binding cleanup must have a dedicated safe per-MAC delete path independent of browser ownership guards'
);
$expect(
    empty($store->deletePhoneConfig(1, '../SIP001122334455.cnf')['ok']),
    'The dedicated phone-config delete path must reject anything except a supported root per-MAC basename'
);

$sftp->files['/tftpboot/SIPDefault.cnf'] = "sync: \"old\"\n";
$sftp->modes['/tftpboot/SIPDefault.cnf'] = 0600;
$legacySync = $store->bumpLegacySync(1);
$expect(
    $legacySync !== ''
    && str_contains($sftp->files['/tftpboot/SIPDefault.cnf'], 'sync: "' . $legacySync . '"')
    && ($sftp->modes['/tftpboot/SIPDefault.cnf'] ?? 0) === 0664,
    'Every legacy global-file rewrite must restore explicit phone-readable permissions on the ucmp-tftp volume'
);

$permissionSftp = new GenerationSftpStub();
$permissionSftp->modes['/tftpboot'] = 0770;
$permissionSftp->directories['/tftpboot/Firmware'] = true;
$permissionSftp->modes['/tftpboot/Firmware'] = 0770;
$permissionSftp->files['/tftpboot/Firmware/test.loads'] = 'load';
$permissionSftp->modes['/tftpboot/Firmware/test.loads'] = 0600;
$permissionStore = new TftpStore(new GenerationUcmStub($permissionSftp));
$permissionAudit = $permissionStore->auditPublishedPermissions(1, false);
$expect(
    ($permissionAudit['remaining'] ?? 0) === 3,
    'Permission verification must inspect the bind-mounted TFTP root, public directories and public files'
);
$permissionRepair = $permissionStore->auditPublishedPermissions(1, true);
$expect(
    !empty($permissionRepair['ok'])
    && ($permissionSftp->modes['/tftpboot'] ?? 0) === 0775
    && ($permissionSftp->modes['/tftpboot/Firmware'] ?? 0) === 0775
    && ($permissionSftp->modes['/tftpboot/Firmware/test.loads'] ?? 0) === 0604,
    'Permission repair must add only anonymous read/traverse bits and must not make published files writable'
);

$download = $permissionStore->prepareDownload(1, ['Firmware/test.loads']);
try {
    $expect(
        ($download['file_count'] ?? 0) === 1
        && (string)file_get_contents((string)$download['path']) === 'load',
        'A selected TFTP file must be staged for an authenticated download without changing the source'
    );
} finally {
    if (is_file((string)($download['path'] ?? ''))) { unlink((string)$download['path']); }
}
$privateRejected = false;
try {
    $permissionStore->prepareDownload(1, ['.ucmprovisioner/current.json']);
} catch (InvalidArgumentException $error) {
    $privateRejected = str_contains($error->getMessage(), 'published');
}
$expect($privateRejected, 'TFTP downloads must never expose the private transaction ledger');

if (class_exists(ZipArchive::class)) {
    $permissionSftp->files['/tftpboot/Firmware/second.bin'] = 'second';
    $permissionSftp->modes['/tftpboot/Firmware/second.bin'] = 0604;
    $archive = $permissionStore->prepareDownload(1, ['Firmware']);
    try {
        $zip = new ZipArchive();
        $opened = $zip->open((string)$archive['path']) === true;
        $expect(
            $opened
            && $zip->getFromName('Firmware/test.loads') === 'load'
            && $zip->getFromName('Firmware/second.bin') === 'second',
            'A selected TFTP directory must download as one bounded archive with its relative paths intact'
        );
        if ($opened) { $zip->close(); }
    } finally {
        if (is_file((string)($archive['path'] ?? ''))) { unlink((string)$archive['path']); }
    }
}

$sftp->files[
    '/tftpboot/.ucmprovisioner/transactions/' . $manifest['transaction_id']
    . '/backup/SEP001122334455.cnf.xml'
] = '<old/>';
$rolledBack = $store->rollbackGeneration(1, $manifest['transaction_id']);
$expect(empty($rolledBack['ok']), 'Rollback must refuse the manual phone-file edit made above');
$sftp->files['/tftpboot/SEP001122334455.cnf.xml'] = '<new/>';
$rolledBack = $store->rollbackGeneration(1, $manifest['transaction_id']);
$expect(!empty($rolledBack['ok']), 'An activated generation must be reversible after its published bytes are restored');
$expect($sftp->files['/tftpboot/SEP001122334455.cnf.xml'] === '<old/>', 'Rollback must restore the previous phone configuration');
$expect($sftp->files['/tftpboot/dial.xml'] === '<old-dial/>', 'Rollback must restore every previous file');
$expect($store->currentGeneration(1) === '', 'Rollback of the first generation must restore the empty prior pointer');

[$failureFiles, $failureManifest] = $makePlan();
$failingSftp = new GenerationSftpStub();
$failingSftp->files['/tftpboot/SEP001122334455.cnf.xml'] = '<before/>';
$failingSftp->files['/tftpboot/dial.xml'] = '<before-dial/>';
$failingSftp->failActivationTarget = '/tftpboot/dial.xml';
$failingStore = new TftpStore(new GenerationUcmStub($failingSftp));
$expect(!empty($failingStore->stageGeneration(1, $failureManifest['transaction_id'], $failureFiles, $failureManifest)['ok']), 'Failure fixture must stage first');
$failed = $failingStore->commitGeneration(1, $failureManifest['transaction_id']);
$expect(empty($failed['ok']), 'An activation failure must fail the transaction');
$expect(!empty($failed['recovery_required'])&&$failingSftp->files['/tftpboot/SEP001122334455.cnf.xml'] === '<new/>', 'An uncertain activation must retain earlier published files');
$expect(isset($failingSftp->files['/tftpboot/.ucmprovisioner/transactions/'.$failureManifest['transaction_id'].'/backup/dial.xml'])
    &&isset($failingSftp->files[$failed['receipt']]), 'An uncertain activation must retain its pending intent and before-state');
$expect($failingStore->currentGeneration(1) === '', 'A failed generation may not become current');

echo "TFTP generation transaction tests passed\n";

// Independent stale-plan, persisted-intent and recovery fault cases.
$raceSftp = new GenerationSftpStub();
$raceStore = new TftpStore(new GenerationUcmStub($raceSftp));
[$aFiles, $a] = $makePlan(); [$bFiles, $b] = $makePlan();
$expect($raceStore->stageGeneration(1, $a['transaction_id'], $aFiles, $a)['ok'], 'Stage A');
$expect($raceStore->stageGeneration(1, $b['transaction_id'], $bFiles, $b)['ok'], 'Stage B');
$expect($raceStore->commitGeneration(1, $a['transaction_id'])['ok'], 'Commit A');
$expect(!$raceStore->commitGeneration(1, $b['transaction_id'])['ok'], 'Stale B must fail CAS');
$c = (new BuildPlanner())->plan(1, [], $bFiles, [], ['previous_generation_id'=>$a['transaction_id']]);
$expect($raceStore->stageGeneration(1, $c['transaction_id'], $bFiles, $c)['ok'], 'Stage C');
$expect($raceStore->commitGeneration(1, $c['transaction_id'])['ok'], 'Commit C');
$expect(!$raceStore->rollbackGeneration(1, $a['transaction_id'])['ok'], 'Old rollback cannot replace C');
$expect(!$raceStore->commitGeneration(1, $a['transaction_id'])['ok'], 'Old repeat commit cannot claim active');
$expect($raceStore->currentGeneration(1) === $c['transaction_id'], 'New generation survives stale operations');

foreach (['stage', 'manifest'] as $tamper) {
    $fake = new GenerationSftpStub(); $subject = new TftpStore(new GenerationUcmStub($fake));
    [$payload, $plan] = $makePlan(); $tx = '/tftpboot/.ucmprovisioner/transactions/' . $plan['transaction_id'];
    $expect($subject->stageGeneration(1, $plan['transaction_id'], $payload, $plan)['ok'], 'Tamper fixture stages');
    if ($tamper === 'stage') { $fake->files[$tx . '/stage/dial.xml'] = '<evil/>'; }
    else {
        $changed = json_decode($fake->files[$tx . '/manifest.json'], true);
        $changed['files'][0]['sha256'] = str_repeat('0', 64);
        $fake->files[$tx . '/manifest.json'] = json_encode($changed);
    }
    $expect(!$subject->commitGeneration(1, $plan['transaction_id'])['ok'], 'Tampered data must fail before mutation');
    $expect(!isset($fake->files['/tftpboot/dial.xml']), 'Tampered plan must publish nothing');
}

$retrySftp = new GenerationSftpStub(); $retryStore = new TftpStore(new GenerationUcmStub($retrySftp));
[$retryFiles, $retryPlan] = $makePlan();
$retrySftp->files['/tftpboot/SEP001122334455.cnf.xml'] = '<before-phone/>';
$retrySftp->files['/tftpboot/dial.xml'] = '<before-dial/>';
$expect($retryStore->stageGeneration(1, $retryPlan['transaction_id'], $retryFiles, $retryPlan)['ok'], 'Retry fixture stage');
$expect($retryStore->commitGeneration(1, $retryPlan['transaction_id'])['ok'], 'Retry fixture commit');
$retrySftp->failRestoreTarget = '/tftpboot/SEP001122334455.cnf.xml';
$expect(!$retryStore->rollbackGeneration(1, $retryPlan['transaction_id'])['ok'], 'Injected restore failure reported');
$expect($retryStore->currentGeneration(1) === $retryPlan['transaction_id'], 'Partial recovery cannot finalize old pointer');
$expect($retrySftp->files['/tftpboot/dial.xml'] === '<before-dial/>', 'First file restored');
$expect($retryStore->rollbackGeneration(1, $retryPlan['transaction_id'])['ok'], 'Retry completes recovery');
$expect($retrySftp->files['/tftpboot/dial.xml'] === '<before-dial/>', 'Retry preserves consumed-backup restoration');
$expect($retrySftp->files['/tftpboot/SEP001122334455.cnf.xml'] === '<before-phone/>', 'Retry restores failed file');

foreach (['preserve', 'activate'] as $intent) {
    $fake = new GenerationSftpStub(); $subject = new TftpStore(new GenerationUcmStub($fake));
    [$payload, $plan] = $makePlan(); $id = $plan['transaction_id'];
    $tx = '/tftpboot/.ucmprovisioner/transactions/' . $id;
    $subject->stageGeneration(1, $id, $payload, $plan);
    $name = 'dial.xml'; $old = $intent === 'preserve' ? '<old/>' : null;
    // Persisted receipt stops BEFORE the destructive operation's completion flag.
    $fake->files[$tx . '/receipt.json'] = json_encode(['transaction_id'=>$id, 'site_id'=>1, 'state'=>'committing', 'previous_pointer'=>[],
        'files'=>[['filename'=>$name, 'sha256'=>hash('sha256', '<dial/>'), 'previous_sha256'=>$old === null ? null : hash('sha256', $old), 'intent'=>$intent]]]);
    if ($old !== null) { $fake->files[$tx . '/backup/' . $name] = $old; }
    else { $fake->files['/tftpboot/' . $name] = '<dial/>'; unset($fake->files[$tx . '/stage/' . $name]); }
    $expect($subject->rollbackGeneration(1, $id)['ok'], 'Recovery infers actual state from durable intent and hashes');
    $expect(($fake->files['/tftpboot/' . $name] ?? null) === $old, 'Crash-window recovery preserves exact prior existence/content');
}

foreach (['./.ucmprovisioner/current.json', 'x/../.ucmprovisioner/current.json', '.\\.ucmprovisioner\\current.json'] as $alias) {
    try { $answer = $raceStore->handle(['op'=>'get', 'site_id'=>1, 'path'=>$alias]); }
    catch (InvalidArgumentException $error) { $answer = ['ok'=>false]; }
    $expect(empty($answer['ok']), 'Private aliases must fail closed');
}
$blocked = $raceStore->handle(['op'=>'put', 'site_id'=>1, 'path'=>'./SEP001122334455.cnf.xml', 'content'=>'<untracked/>']);
$expect(empty($blocked['ok']), 'Canonicalized managed alias must retain ownership protection');
$raceSftp->directories['/tftpboot/.ucmprovisioner/write-lock'] = true;
$expect(!$raceStore->commitGeneration(1, $c['transaction_id'])['ok'], 'Existing destination lock excludes another writer');
unset($raceSftp->directories['/tftpboot/.ucmprovisioner/write-lock']);
echo "Generation ownership and fault recovery tests passed\n";
