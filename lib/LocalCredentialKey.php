<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Read-only lookup; installation performs the backup-gated storage migration. */
final class LocalCredentialKey
{
    public function __construct(private \PDO $db, private string $root = '/var/lib/asterisk/ucmprovisioner') {}

    public function read(): string
    {
        $legacy=$this->legacy(); $path=$this->path();
        if ($legacy!=='' && !preg_match('/^[a-f0-9]{64}$/D',$legacy)) { throw new \RuntimeException('Database credential key is invalid; restore the matching recovery backup'); }
        if (is_link($path)) { throw new \RuntimeException('Credential key cannot be a symbolic link'); }
        if (!is_file($path)) {
            if ($legacy==='') {
                if (is_file(dirname($path) . '/credential-migration.json')) { throw new \RuntimeException('Migrated credential key is missing; restore its matching recovery backup'); }
                foreach (['ucmp_sites','ucmp_settings'] as $table) {
                    foreach ($this->db->query('SELECT * FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                        foreach ($row as $value) {
                            if (is_string($value) && preg_match('/^(?:gcm|enc):/',$value)) {
                                throw new \RuntimeException('Encrypted credentials exist without their key; restore the matching recovery backup');
                            }
                        }
                    }
                }
            }
            return $legacy;
        }
        $this->restricted($path,0640);
        $key=file_get_contents($path);
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)
            || ($legacy!=='' && !hash_equals($legacy,$key))) {
            throw new \RuntimeException('Credential keys are invalid or disagree; restore the matching database/key backup');
        }
        return $key;
    }

    public function status(): array
    {
        $key=$this->read(); $legacy=$this->legacy();
        return ['state'=>is_file($this->path()) ? ($legacy!==''?'file_and_legacy':'private_file') : ($legacy!==''?'legacy_database':'uninitialized'),
            'migration_required'=>$legacy!=='','key_sha256'=>$key!==''?hash('sha256',$key):null];
    }

    /** Called during installation, never by credential reads, cron, or federation RPC. */
    public function migrate(string $backupPath,string $siteKey,callable $verifyBackup): array
    {
        $key=$this->read();
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new \RuntimeException('An existing valid key is required; migration never creates or rotates keys'); }
        $backupRoot=realpath(rtrim($this->root,'/\\') . '/configuration-packages'); $real=realpath($backupPath);
        if (!$backupRoot || !$real || is_link($backupPath) || dirname($real)!==$backupRoot || !is_file($real)) {
            throw new \RuntimeException('Recovery backup must be a retained local configuration package');
        }
        $this->restricted($real,0600);
        $verified=$verifyBackup($real,$siteKey,hash('sha256',$key));
        if (empty($verified['ok']) || !hash_equals($siteKey,(string)($verified['site_key'] ?? ''))
            || !in_array(hash('sha256',$key),(array)($verified['key_sha256'] ?? []),true)
            || !isset($verified['database_members']['database/settings.json'],$verified['database_members']['database/sites.json'])
            || !hash_equals((string)($verified['archive_sha256'] ?? ''),(string)hash_file('sha256',$real))) {
            throw new \RuntimeException('A verified matching settings/sites/key recovery backup is required');
        }
        $directory=dirname($this->path());
        if (is_link($directory) || is_link($this->root)) { throw new \RuntimeException('Credential directory cannot be a symbolic link'); }
        if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) { throw new \RuntimeException('Could not create private credential directory'); }
        if (PHP_OS_FAMILY!=='Windows' && (!chgrp($directory,'asterisk') || !chmod($directory,0750))) { throw new \RuntimeException('Could not restrict credential directory'); }
        $lockPath=$directory . '/credential-migration.lock';
        if (is_link($lockPath)) { throw new \RuntimeException('Credential lock cannot be a symbolic link'); }
        $lock=fopen($lockPath,'c');
        if (!$lock) { throw new \RuntimeException('Credential migration lock is unavailable'); }
        try {
            if (!chmod($lockPath,0660) || (PHP_OS_FAMILY!=='Windows' && !chgrp($lockPath,'asterisk')) || !flock($lock,LOCK_EX|LOCK_NB)) {
                throw new \RuntimeException('Credential migration is busy or lock permissions are invalid');
            }
            if (!hash_equals($key,$this->read())) { throw new \RuntimeException('Credential key changed after backup'); }
            $receipt=['state'=>'backup_verified','backup'=>basename($real),'archive_sha256'=>$verified['archive_sha256'],
                'key_sha256'=>hash('sha256',$key),'site_key'=>$siteKey,'created'=>time()];
            $receiptPath=$directory . '/credential-migration.json';
            $this->writePrivate($receiptPath,json_encode($receipt,JSON_THROW_ON_ERROR));
            if (!is_file($this->path())) { $this->writePrivate($this->path(),$key); }
            if (!hash_equals($key,$this->read())) { throw new \RuntimeException('Private credential-key verification failed'); }
            if ($this->db->inTransaction()) { throw new \RuntimeException('Credential migration requires its own transaction'); }
            $this->db->beginTransaction();
            try {
                if ($this->legacy()!=='' && !hash_equals($key,$this->legacy())) { throw new \RuntimeException('Database key changed after backup'); }
                $delete=$this->db->prepare('DELETE FROM ucmp_settings WHERE skey=? AND sval=?');
                $delete->execute(['crypt_key',$key]);
                if (!$this->db->commit()) { throw new \RuntimeException('Credential-key transaction commit was not confirmed'); }
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                throw new \RuntimeException('Key migration needs review; identical private key and verified recovery archive retained',0,$e);
            }
            if ($this->legacy()!=='' || !hash_equals($key,$this->read())) { throw new \RuntimeException('Key migration needs recovery; matching backup retained'); }
            $receipt['state']='complete'; $receipt['finished']=time();
            $this->writePrivate($receiptPath,json_encode($receipt,JSON_THROW_ON_ERROR));
            return ['ok'=>true,'state'=>'private_file','backup'=>basename($real),'archive_sha256'=>$verified['archive_sha256'],
                'message'=>'Identical key moved to restricted host storage; verified matching recovery archive retained.'];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function legacy(): string
    {
        $st=$this->db->prepare('SELECT sval FROM ucmp_settings WHERE skey=?'); $st->execute(['crypt_key']);
        $keys=array_values(array_unique(array_filter(array_map('strval',$st->fetchAll(\PDO::FETCH_COLUMN)),static fn(string $key): bool=>$key!=='')));
        if (count($keys)>1) { throw new \RuntimeException('Database credential keys disagree; manual recovery is required'); }
        return $keys[0] ?? '';
    }
    private function path(): string { return rtrim($this->root,'/\\') . '/secrets/credentials.key'; }
    private function writePrivate(string $path,string $bytes): void
    {
        if (is_link($path)) { throw new \RuntimeException('Private target cannot be a symbolic link'); }
        $temporary=$path . '.new-' . bin2hex(random_bytes(8)); $file=fopen($temporary,'x');
        if (!$file) { throw new \RuntimeException('Could not stage private credential file'); }
        try {
            if (!chmod($temporary,0640) || (PHP_OS_FAMILY!=='Windows' && !chgrp($temporary,'asterisk'))
                || fwrite($file,$bytes)!==strlen($bytes) || !fflush($file)
                || (function_exists('fsync') && !fsync($file))) { throw new \RuntimeException('Could not persist private credential file'); }
        } finally { fclose($file); }
        if (!rename($temporary,$path)) { throw new \RuntimeException('Could not activate private credential file; original retained'); }
        $this->restricted($path,0640);
    }
    private function restricted(string $path,int $maximum): void
    {
        clearstatcache(true,$path);
        if (PHP_OS_FAMILY!=='Windows' && ((fileperms($path)&0777)&(~$maximum))!==0) { throw new \RuntimeException('Credential recovery file permissions are too broad'); }
    }
}
