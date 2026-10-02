<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Keep the old editable identity intact while retiring its public catalog name. */
final class RetainedAssetSource
{
    public static function retirePreviousDirectory(string $oldDirectory, string $newDirectory, array $requiredHashes): array
    {
        $parent=realpath(dirname($oldDirectory));
        if ($parent===false || $parent!==realpath(dirname($newDirectory)) || is_link($oldDirectory) || is_link($newDirectory)
            || !is_dir($oldDirectory) || !is_dir($newDirectory) || realpath($oldDirectory)===realpath($newDirectory) || !$requiredHashes) {
            throw new \InvalidArgumentException('Retained-source rename requires two distinct regular catalog directories in the same store');
        }
        foreach ($requiredHashes as $name=>$hash) {
            if (!is_string($name) || basename($name)!==$name || !preg_match('/^[a-f0-9]{64}$/D',(string)$hash)
                || is_link($newDirectory.'/'.$name) || !is_file($newDirectory.'/'.$name)
                || !hash_equals($hash,(string)hash_file('sha256',$newDirectory.'/'.$name))) {
                throw new \RuntimeException('New retained source failed verification; both catalog identities were preserved');
            }
        }
        $recovery='.renamed-'.basename($oldDirectory).'-'.bin2hex(random_bytes(8));
        if (!@rename($oldDirectory,$parent.'/'.$recovery)) {
            return ['ok'=>true,'cleanup_pending'=>true,'recovery_id'=>basename($oldDirectory),
                'message'=>'New catalog verified; previous complete identity remains because retirement rename failed'];
        }
        return ['ok'=>true,'cleanup_pending'=>false,'recovery_id'=>$recovery,
            'message'=>'New catalog verified; previous complete source retained under its recovery identity'];
    }
}
