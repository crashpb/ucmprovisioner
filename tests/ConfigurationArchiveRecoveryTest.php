<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/ConfigurationArchiveService.php';
use FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService;
if (!class_exists(ZipArchive::class) || !in_array('sqlite',PDO::getAvailableDrivers(),true)) { echo "Archive recovery fixtures skipped: ZIP/SQLite unavailable\n"; exit(0); }
final class ArchiveFaultPdo extends PDO {
    public string $fault=''; public string $target='';
    public function commit(): bool {
        if ($this->fault==='conflict') { file_put_contents($this->target,'external-concurrent-content'); return false; }
        if ($this->fault==='uncertain') { parent::commit(); return false; }
        if (str_starts_with($this->fault,'rollback_')) { return false; }
        return parent::commit();
    }
    public function rollBack(): bool {
        if ($this->fault==='rollback_false') { return false; }
        if ($this->fault==='rollback_throw') { throw new RuntimeException('Synthetic lost rollback acknowledgement'); }
        return parent::rollBack();
    }
}
$expect=static function(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$db=new ArchiveFaultPdo('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ucmp_settings (id INTEGER PRIMARY KEY, site_id INTEGER, skey TEXT, sval TEXT)');
$db->exec('CREATE TABLE ucmp_configuration_sync_jobs (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE ucmp_configuration_sync_items (id INTEGER PRIMARY KEY, object_key TEXT)');
$db->exec('CREATE TABLE ucmp_assets (id INTEGER PRIMARY KEY, site_id INTEGER, kind TEXT, filename TEXT, relative_path TEXT)');
$db->exec("INSERT INTO ucmp_settings VALUES (1,1,'crypt_key','fixture-key'),(2,1,'policy','{\"label\":\"keep\",\"bind_password\":\"fixture-bind\"}'),(3,1,'ldap_password','fixture-password')");
$root=str_replace('\\','/',sys_get_temp_dir()) . '/ucmp-archive-recovery-' . bin2hex(random_bytes(6));
$ucm=new class { public function withConfigurationSyncLock(callable $operation): mixed { return $operation(); } public string $site='fixture-site'; public function localSiteId(): int { return 1; } public function getSite(int $id): array { return ['site_key'=>$this->site,'id'=>$id]; } };
$service=new ConfigurationArchiveService($db,$ucm,$root);
$export=$service->export(['settings'],'test');
$zip=new ZipArchive(); $zip->open($export['path']); $bytes=$zip->getFromName('database/settings.json'); $zip->close();
$expect(!str_contains($bytes,'fixture-key') && !str_contains($bytes,'fixture-bind') && !str_contains($bytes,'fixture-password') && str_contains($bytes,'keep'),'Default export leaked a secret or removed nonsecret policy');
$expect($export['manifest']['contains_secrets']===false,'Configuration export mislabeled secrets');
$recovery=$service->export(['settings'],'test','recovery');
$verified=$service->verifyRecoveryBackup($recovery['path'],'fixture-site',hash('sha256','fixture-key'));
$expect($verified['ok'] && !str_contains(json_encode($verified),'fixture-key'),'Recovery verification failed or exposed key material');
$ucm->site='another-site';
try { $service->import($export['path'],['settings']); throw new LogicException('Cross-site import accepted'); } catch (DomainException) {}
$ucm->site='fixture-site';
try { $service->import($export['path'],['mistyped-scope']); throw new LogicException('Unknown selected scope imported every available scope'); } catch (InvalidArgumentException) {}
$tampered=$root . '/tampered.zip'; copy($export['path'],$tampered); $zip->open($tampered); $zip->addFromString('database/settings.json','{"tables":{}}'); $zip->close();
try { $service->import($tampered,['settings']); throw new LogicException('Tampered database accepted'); } catch (InvalidArgumentException) {}

mkdir($root . '/asterisk-bundles'); file_put_contents($root . '/asterisk-bundles/a.bin','archive-a'); file_put_contents($root . '/asterisk-bundles/b.bin','archive-b');
$files=$service->export(['patches'],'test');
file_put_contents($root . '/asterisk-bundles/a.bin','destination-a'); unlink($root . '/asterisk-bundles/b.bin'); mkdir($root . '/asterisk-bundles/b.bin');
try { $service->import($files['path'],['patches']); throw new LogicException('Invalid second target accepted'); } catch (RuntimeException $error) { $expect(!($error instanceof LogicException),'Import should reject directory target'); }
$expect(file_get_contents($root . '/asterisk-bundles/a.bin')==='destination-a','Partial file import did not restore original first file');
$receipts=glob($root . '/configuration-packages/import-*/recovery.json');
$expect(count($receipts)>0,'Import rollback evidence was deleted');
rmdir($root . '/asterisk-bundles/b.bin'); file_put_contents($root . '/asterisk-bundles/b.bin','destination-b');
$db->target=$root . '/asterisk-bundles/a.bin'; $db->fault='conflict';
$result=$service->import($files['path'],['patches']);
$expect(!$result['ok'] && $result['status']==='recovery_required' && file_get_contents($db->target)==='external-concurrent-content','Compensation conflict was erased or not reported');
$expect(is_file($root . '/configuration-packages/' . $result['recovery_id'] . '/database-before.json'),'Database recovery pair was not retained');
$db->fault='uncertain';
$result=$service->import($files['path'],['patches']);
$expect(!$result['ok'] && $result['status']==='recovery_required' && file_get_contents($db->target)==='archive-a','Uncertain committed database caused independent file rollback');
foreach (['rollback_false','rollback_throw'] as $fault) {
    file_put_contents($db->target,'before-'.$fault);$db->fault=$fault;
    $result=$service->import($files['path'],['patches']);
    $expect(empty($result['ok']) && !empty($result['recovery_required']) && $result['status']==='recovery_required'
        && file_get_contents($db->target)==='archive-a' && $db->inTransaction(),
        'Unconfirmed database rollback must retain activated files and an explicit writer-lock recovery result');
    $receipt=json_decode(file_get_contents($root.'/configuration-packages/'.$result['recovery_id'].'/recovery.json'),true);
    $expect($receipt['status']==='database_recovery_required','Database rollback uncertainty lost its durable receipt');
    $db->fault='';$db->rollBack(); // Release this isolated fixture transaction only.
}
// All cleanup is restricted to this fixture's freshly allocated temporary tree.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
rmdir($root);
echo "Archive secret projection, paired recovery, identity/hash gates and compensation/commit fault assertions passed\n";
