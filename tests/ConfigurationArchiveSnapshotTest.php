<?php
declare(strict_types=1);
require_once __DIR__ . '/ConfigurationWriteLockTest.php';
require_once __DIR__ . '/TftpGenerationTransactionTest.php';
use FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService;

final class ArchiveSnapshotPdo extends PDO {
    public $beforeTemplates = null;
    public bool $failCommit = false;
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if ($this->beforeTemplates && $query === 'SELECT * FROM `ucmp_templates`') {
            $callback = $this->beforeTemplates; $this->beforeTemplates = null; $callback();
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query,$fetchMode,...$args);
    }
    public function commit(): bool { $ok = parent::commit(); return $this->failCommit ? false : $ok; }
}
$expect = static function (bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$root = sys_get_temp_dir() . '/ucmp-snapshot-' . bin2hex(random_bytes(6)); mkdir($root);
$db = new ArchiveSnapshotPdo('sqlite:' . $root . '/test.db'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=WAL');
foreach ([
    'ucmp_phones (id INTEGER,group_id INTEGER,extension_id INTEGER)',
    'ucmp_phone_mac_suppressions (id INTEGER)', 'ucmp_phone_builds (id INTEGER)',
    'ucmp_templates (id INTEGER)', 'ucmp_default_template_rules (id INTEGER,template_id INTEGER)',
    'ucmp_groups (id INTEGER,template_id INTEGER,parent_group_id INTEGER)',
    'ucmp_extensions (id INTEGER,group_id INTEGER)', 'ucmp_identity_overrides (id INTEGER)',
    'ucmp_assets (id INTEGER)',
    'ucmp_directory_sources (id INTEGER)', 'ucmp_directory_entries (id INTEGER)',
    'ucmp_directory_conflicts (id INTEGER)', 'ucmp_phonebooks (id INTEGER)',
] as $table) { $db->exec('CREATE TABLE ' . $table); }
$db->exec('INSERT INTO ucmp_phones VALUES(1,1,1)');
$db->exec('INSERT INTO ucmp_groups VALUES(1,1,NULL)');
$db->exec('INSERT INTO ucmp_extensions VALUES(1,1)');
$db->exec('INSERT INTO ucmp_templates VALUES(1),(2)');
$writer = new PDO('sqlite:' . $root . '/test.db'); $writer->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$interleaved = false;
$db->beforeTemplates = static function () use ($writer,&$interleaved): void {
    $writer->beginTransaction(); $writer->exec('UPDATE ucmp_groups SET template_id=2 WHERE id=1');
    $writer->exec('DELETE FROM ucmp_templates WHERE id=1'); $writer->commit(); $interleaved = true;
};
$ucm = new class {
    private ConfigurationLockModule $lock;
    public GenerationSftpStub $sftp;
    public function __construct() { $this->lock = new ConfigurationLockModule(); $this->sftp = new GenerationSftpStub(); }
    public function siteConnection(int $id): GenerationConnectionStub { return new GenerationConnectionStub($this->sftp); }
    public function withConfigurationSyncLock(callable $operation): mixed { return $this->lock->withConfigurationSyncLock($operation); }
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['id'=>1,'site_key'=>'fixture-site']; }
};
$archive = new ConfigurationArchiveService($db,$ucm,$root);
try {
    $result = $archive->export(['phones','groups','templates'],'fixture','configuration',false);
    $zip = new ZipArchive(); $zip->open($result['path']);
    $groups = json_decode($zip->getFromName('database/groups.json'),true)['tables'];
    $templates = json_decode($zip->getFromName('database/templates.json'),true)['tables']; $zip->close();
    $expect($interleaved && (int)$groups['ucmp_groups'][0]['template_id'] === 1 && array_column($templates['ucmp_templates'],'id') === [1,2], 'Archive mixed database revisions');
    $expect((int)$writer->query('SELECT template_id FROM ucmp_groups')->fetchColumn() === 2, 'Concurrent writer did not actually commit');
    $db->beginTransaction();
    try { $archive->export(['phones'],'fixture','configuration',false); throw new LogicException('Export accepted caller transaction'); }
    catch (RuntimeException) { $expect($db->inTransaction(),'Export committed the caller transaction'); }
    $db->rollBack();
    $writer->exec('UPDATE ucmp_groups SET template_id=99 WHERE id=1');
    try { $archive->export(['groups','templates'],'fixture','configuration',false); throw new LogicException('Broken selected reference was exported'); }
    catch (RuntimeException) {}
    $partial = $archive->export(['phones'],'fixture','configuration',false);
    $expect(is_file($partial['path']),'Valid partial scope export was rejected');
    $db->failCommit = true; $before = glob($root . '/configuration-packages/export-*');
    try { $archive->export(['phones'],'fixture','configuration',false); throw new LogicException('Unconfirmed snapshot completed'); }
    catch (RuntimeException) {}
    $expect(glob($root . '/configuration-packages/export-*') === $before,'Failed snapshot left a deliverable archive');
    $db->failCommit = false;
    $ucm->sftp->files['/tftpboot/SEP001122334455.cnf.xml'] = 'fixture-phone';
    $managed = $archive->export(['phones'],'fixture','recovery',true);
    $expect(count($managed['manifest']['files']) === 1 && !isset($ucm->sftp->directories['/tftpboot/.ucmprovisioner/write-lock']), 'Managed file export did not capture the generation or release its lock');
    $ucm->sftp->mkdir('/tftpboot/.ucmprovisioner/write-lock',0770);
    try { $archive->export(['phones'],'fixture','recovery',true); throw new LogicException('Export entered a locked TFTP generation'); }
    catch (RuntimeException) {}
    $ucm->sftp->rmdir('/tftpboot/.ucmprovisioner/write-lock');
    $publication = \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock($root . '/directory/.publication.lock');
    try { $archive->export(['directory'],'fixture','recovery',false); throw new LogicException('Export entered a publishing directory'); }
    catch (RuntimeException) {}
    flock($publication,LOCK_UN); fclose($publication);
    file_put_contents($root . '/directory/directory.json','{"fixture":true}');
    $directory = $archive->export(['directory'],'fixture','recovery',false);
    $expect(array_column($directory['manifest']['files'],'relative_path') === ['directory/directory.json'],'Runtime directory lock leaked into the archive');
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    $db = null; $writer = null; unset($archive);
    gc_collect_cycles();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
echo "Archive interleaved writer snapshot, reference closure, partial scope and transaction ownership passed\n";
