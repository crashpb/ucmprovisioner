<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Repair a missing public half without ever replacing an enrolled private key. */
final class ProvisionerKeyPair
{
    public static function ensure(string $privatePath): array
    {
        $publicPath=$privatePath . '.pub'; $lockPath=$privatePath . '.lock';
        foreach ([$privatePath,$publicPath,$lockPath] as $path) {
            for ($parent=$path; dirname($parent)!==$parent; $parent=dirname($parent)) {
                if (is_link($parent)) { throw new \RuntimeException('Provisioner key paths cannot be symbolic links'); }
            }
            if (file_exists($path) && !is_file($path)) { throw new \RuntimeException('Provisioner key paths must be regular files'); }
        }
        $lock=fopen($lockPath,'c');
        if (!$lock) { throw new \RuntimeException('Could not lock provisioner key pair'); }
        try {
            ServiceFilePermissions::apply($lockPath,0660);
            if (!flock($lock,LOCK_EX|LOCK_NB)) { throw new \RuntimeException('Provisioner key pair is busy'); }
            if (is_file($privatePath)) {
                $private=\phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey((string)file_get_contents($privatePath));
            } else {
                if (file_exists($publicPath)) { throw new \RuntimeException('Private key is missing while public enrollment remains; restore the enrolled private key'); }
                $private=\phpseclib3\Crypt\RSA::createKey(3072);
                self::writeNew($privatePath,$private->toString('PKCS8'),0640);
            }
            $public=trim($private->getPublicKey()->toString('OpenSSH'));
            if (is_file($publicPath)) {
                $observed=trim(\phpseclib3\Crypt\PublicKeyLoader::loadPublicKey((string)file_get_contents($publicPath))->toString('OpenSSH'));
                if (!hash_equals($public,$observed)) { throw new \RuntimeException('Provisioner public/private key pair disagrees; enrollment keys were not changed'); }
            } else { self::writeNew($publicPath,$public . "\n",0644); }
            return ['private'=>$privatePath,'public'=>$publicPath,'public_key'=>$public];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private static function writeNew(string $path,string $bytes,int $mode): void
    {
        if (file_exists($path)) { throw new \RuntimeException('Refusing to overwrite an existing enrollment key'); }
        $temporary=$path . '.new-' . bin2hex(random_bytes(8)); $file=fopen($temporary,'x');
        if (!$file) { throw new \RuntimeException('Could not stage provisioner key'); }
        try {
            if (!chmod($temporary,$mode) || (PHP_OS_FAMILY!=='Windows' && !chgrp($temporary,'asterisk'))
                || fwrite($file,$bytes)!==strlen($bytes) || !fflush($file)) { throw new \RuntimeException('Could not persist provisioner key'); }
        } finally { fclose($file); }
        if (!rename($temporary,$path)) { throw new \RuntimeException('Could not activate staged provisioner key'); }
    }
}
