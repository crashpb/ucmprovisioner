<?php
declare(strict_types=1);

namespace phpseclib3\Net {
    if (!class_exists(SFTP::class, false)) {
        final class SFTP { public const SOURCE_LOCAL_FILE = 1; }
    }
}

namespace {
    require_once __DIR__ . '/../lib/TftpStore.php';

    use FreePBX\modules\Ucmprovisioner\TftpStore;

    final class FirmwareTransactionSftpStub
    {
        /** @var array<string,string> */
        public array $files;
        public array $modes = [];
        public bool $corruptStaging = false;
        public string $failRenameDestination = '';

        /** @param array<string,string> $files */
        public function __construct(array $files) { $this->files = $files; }

        public function put(string $path, string $source, ?int $mode = null): bool
        {
            $content = $mode === \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE
                ? file_get_contents($source)
                : $source;
            if (!is_string($content)) { return false; }
            $this->files[$path] = $this->corruptStaging ? str_repeat('x',strlen($content)) : $content;
            return true;
        }

        public function stat(string $path): array|false { return isset($this->files[$path]) ? ['type'=>1,'size'=>strlen($this->files[$path]),'mode'=>$this->modes[$path] ?? 0664] : false; }
        public function get(string $path,$local=false,int $offset=0,int $length=-1): string|false {
            if (!isset($this->files[$path])) { return false; }
            return $length < 0 ? substr($this->files[$path],$offset) : substr($this->files[$path],$offset,$length);
        }

        public function file_exists(string $path): bool { return array_key_exists($path, $this->files); }
        public function rename(string $from, string $to): bool
        {
            if ($to === $this->failRenameDestination) {
                $this->failRenameDestination = '';
                return false;
            }
            if (!$this->file_exists($from)) { return false; }
            $this->files[$to] = $this->files[$from];
            unset($this->files[$from]);
            return true;
        }
        public function delete(string $path): bool
        {
            unset($this->files[$path]);
            return true;
        }
        public function chmod(int $mode, string $path): bool { $this->modes[$path]=$mode; return true; }
    }

    $store = new TftpStore(new class {});
    $validate = new \ReflectionMethod(TftpStore::class, 'validateFirmwareDeclaration');
    $validate->setAccessible(true);
    $install = new \ReflectionMethod(TftpStore::class, 'installDeclaredFirmware');
    $install->setAccessible(true);
    $relative = 'firmware/yealink/t4u/T46U.rom';
    $target = '/tftpboot/' . $relative;
    $sidecar = dirname($target) . '/' . \FreePBX\modules\Ucmprovisioner\FirmwareService::declarationFilename(basename($target));
    $oldDeclaration = json_encode(['file'=>basename($target),'version'=>1]);
    $declaration = $validate->invoke(
        $store,
        $relative,
        json_encode(['vendor'=>'Yealink', 'file'=>'T46U.rom', 'models'=>['T46U']])
    );
    $upload = tempnam(sys_get_temp_dir(), 'ucmp-fw-test-');
    if ($upload === false || file_put_contents($upload, 'new-firmware') === false) {
        throw new \RuntimeException('Could not prepare firmware transaction fixture');
    }

    try {
        $replace = new \ReflectionMethod(TftpStore::class, 'replaceRemoteFiles');
        $streamed = new FirmwareTransactionSftpStub([$target=>'old-firmware']);
        $replace->invoke($store,$streamed,[$target=>['local_path'=>$upload,'bytes'=>(int)filesize($upload),'sha256'=>hash_file('sha256',$upload),'mode'=>0664]]);
        if ($streamed->files !== [$target=>'new-firmware']) { throw new \RuntimeException('Streamed ordinary upload did not publish exactly its verified bytes'); }
        try {
            $replace->invoke($store,$streamed,[$target=>['local_path'=>$upload,'bytes'=>(int)filesize($upload),'sha256'=>str_repeat('0',64),'mode'=>0664]]);
            throw new \LogicException('A source change after upload inspection was accepted');
        } catch (\RuntimeException $expected) {}
        if ($streamed->files !== [$target=>'new-firmware']) { throw new \RuntimeException('Failed streamed verification changed the active file'); }
        $corrupted = new FirmwareTransactionSftpStub([$target=>'old-firmware',$sidecar=>$oldDeclaration]);
        $corrupted->corruptStaging = true;
        try { $install->invoke($store,$corrupted,$target,$upload,$declaration); throw new \LogicException('Corrupted firmware staging was activated'); }
        catch (\RuntimeException $expected) {}
        if ($corrupted->files !== [$target=>'old-firmware',$sidecar=>$oldDeclaration]) { throw new \RuntimeException('Corrupted staging changed the live firmware pair'); }
        $sftp = new FirmwareTransactionSftpStub([
            $target=>'old-firmware',
            $sidecar=>$oldDeclaration,
        ]);
        $sftp->failRenameDestination = $sidecar;
        $failed = false;
        try {
            $install->invoke($store, $sftp, $target, $upload, $declaration);
        } catch (\Throwable $e) {
            $failed = $e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && !empty($e->receipt['recovery_required']);
            $receipt = $failed ? $e->receipt['file_replacements'][0] : [];
        }
        if (!$failed
            || ($sftp->files[$target] ?? null) !== 'new-firmware'
            || ($sftp->files[$receipt['files'][$target]['backup']] ?? null) !== 'old-firmware'
            || ($sftp->files[$receipt['files'][$sidecar]['backup']] ?? null) !== $oldDeclaration
            || ($sftp->files[$receipt['files'][$sidecar]['staged']] ?? null) !== $declaration) {
            throw new \RuntimeException('An unconfirmed sidecar activation must retain the active binary, prior pair and candidate declaration');
        }

        $success = new FirmwareTransactionSftpStub([
            $target=>'old-firmware',
            $sidecar=>$oldDeclaration,
        ]);
        if (!$install->invoke($store, $success, $target, $upload, $declaration)
            || ($success->files[$target] ?? null) !== 'new-firmware'
            || ($success->files[$sidecar] ?? null) !== $declaration) {
            throw new \RuntimeException('A valid vendor firmware upload must activate its binary and sidecar together');
        }

        $ui = (string)file_get_contents(dirname(__DIR__) . '/views/firmware.php');
        if (!str_contains($ui, "declared?'firmware_upload':'upload'")) {
            throw new \RuntimeException('Vendor firmware UI must use the atomic binary-and-sidecar operation');
        }
    } finally {
        if (is_string($upload) && is_file($upload)) { unlink($upload); }
    }

    echo "Firmware staged pair and uncertain-activation retention tests passed\n";
}
