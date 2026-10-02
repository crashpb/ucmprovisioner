<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/LogicalExtensionService.php';
require_once __DIR__ . '/../lib/DirectoryService.php';
use FreePBX\modules\Ucmprovisioner\DirectoryService;

$expect = static function (bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE ucmp_phonebooks (id INTEGER PRIMARY KEY AUTOINCREMENT,book_key TEXT UNIQUE,name TEXT,filename TEXT UNIQUE,title TEXT,include_mode TEXT,rules TEXT,exclusions TEXT,manual_entries TEXT,group_by TEXT,hierarchy TEXT,publish_sites TEXT,name_format TEXT,sort_by TEXT,include_mobile INTEGER,cisco_page_size INTEGER,is_default INTEGER,updated INTEGER)');
$db->exec('CREATE TABLE ucmp_site_jobs (site_id INTEGER,job_type TEXT,object_key TEXT,desired_hash TEXT,state TEXT,updated INTEGER,last_error TEXT)');
$db->exec('CREATE TABLE ucmp_directory_sources (id INTEGER,enabled INTEGER,last_success INTEGER,last_accepted_revision TEXT)');
$db->exec("INSERT INTO ucmp_directory_sources VALUES (1,1,1234,'accepted-fixture')");
$ucm = new class($db) {
    public array $wire = [];
    public array $sent = [];
    public array $failSites = [];
    public bool $failReferences = false;
    public function getTemplates(int $siteId): array { if ($this->failReferences) { throw new RuntimeException('Fixture reference read failed'); } return []; }
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function getSites(): array { return [['id'=>1,'site_key'=>'site-a','name'=>'Site A','enabled'=>1], ['id'=>2,'site_key'=>'site-b','name'=>'Site B','enabled'=>1]]; }
    public function getSite(int $id): array { return $this->getSites()[0]; }
    public function getSiteSettingsLocal(int $id): array { return []; }
    public function directoryTokenForSite(array $site): string { return 'synthetic-token'; }
    public function importDirectorySnapshot(int $site,array $wire,string $token): array { $this->sent[] = $site; $this->wire = $wire; if (in_array($site,$this->failSites,true)) { throw new RuntimeException('Fixture destination unavailable'); } return ['ok'=>true]; }
    public function directoryBaseUrl(int $site): string { return 'https://pbx.example.test/directory.php'; }
    public function refreshDirectorySourceTokens(int $site,array $catalog): array { return ['ok'=>true]; }
};
$directory = new class($ucm) extends DirectoryService {
    public array $catalog = [];
    public array $limits = [];
    public bool $failRead = false;
    public function entries(bool $includeConflicted = true,int $limit = 5000): array {
        if ($this->failRead) { throw new RuntimeException('Fixture accepted catalog unavailable'); }
        $this->limits[] = $limit;
        return $limit > 0 ? array_slice($this->catalog,0,$limit) : $this->catalog;
    }
};
for ($i=0;$i<5001;$i++) {
    $directory->catalog[] = ['source_key'=>hash('sha256','public-' . $i),'object_key'=>md5('object-' . $i),'display_name'=>'Person ' . $i,'extension'=>(string)(10000+$i),'label'=>'person.' . $i];
}
$excluded = $directory->catalog[5000]['object_key'];
$book = ['book_key'=>'stable-original-key','name'=>'Renamed Staff','title'=>'Staff','filename'=>'staff.xml','include_mode'=>'all','exclusions'=>[$excluded],'group_by'=>'none','publish_sites'=>['*']];
$id = $directory->importPhonebook($book);
$expect($directory->phonebook($id)['book_key'] === 'stable-original-key', 'Import must preserve the stable source key after rename');
$expect(count($ucm->wire) === 5002 && end($directory->limits) === 0, 'Ordinary save publication must enumerate every accepted contact');
$compiled = $ucm->wire[0]['__ucmp_compiled_phonebooks'][0];
$expect($ucm->wire[0]['__ucmp_source_freshness'][0]===['last_success'=>1234,'last_accepted_revision'=>hash('sha256','accepted-fixture')], 'Accepted source freshness lost or raw identity leaked');
$expect(count($compiled['nodes']['root']['contacts']) === 5000, 'Legacy exclusion must survive actual wire projection and publication');
$expect(!in_array($directory->catalog[5000]['source_key'],$compiled['nodes']['root']['contacts'],true), 'Excluded late-page contact must remain absent');
$expect(!isset($ucm->wire[1]['object_key']), 'Raw object key must not be added to wire contacts to fix exclusions');
$idAgain = $directory->importPhonebook($book);
$expect($idAgain === $id && count($directory->phonebooks()) === 1, 'Second import must update the same identity');
$edit = $book;
unset($edit['book_key'],$edit['exclusions']);
$edit['id'] = $id;
$edit['name'] = 'Another Display Name';
$directory->savePhonebook($edit);
$expect($directory->phonebook($id)['exclusions'] === [$excluded], 'Omitted editor exclusion state must remain intact');
$expect($directory->phonebook($id)['book_key'] === 'stable-original-key', 'Ordinary display rename must retain stable key');
$normalizeBook = new ReflectionMethod(DirectoryService::class,'normalizeCompiledPhonebook');
try {
    $directory->importPhonebook(array_replace($book, ['book_key'=>str_repeat('x', 65), 'filename'=>'overlong.xml']));
    throw new RuntimeException('A phonebook key larger than the database and handset contract was accepted');
} catch (InvalidArgumentException $expected) {}
unset($edit['include_mode']);
$directory->savePhonebook($edit);
$expect($directory->phonebook($id)['include_mode'] === 'all', 'Omitted include mode must use the documented default');
try {
    $normalizeBook->invoke($directory,['schema_version'=>2,'nodes'=>[],'contacts'=>array_fill(0,10001,[])]);
    throw new RuntimeException('Over-capacity book was silently truncated');
} catch (LengthException $expected) {}

$normalize = new ReflectionMethod(DirectoryService::class,'normalizeCiscoSoftkeys');

$manualFixture = ['display_name'=>'Manual operator','extension'=>'9001','attributes'=>['l'=>['Test city']],'groups'=>['Test team']];
$directory->savePhonebook(array_replace($edit, ['manual_entries'=>[$manualFixture]]));
$savedManual = $directory->phonebook($id)['manual_entries'][0];
$manualDraft = ['source_key'=>$savedManual['source_key'],'display_name'=>'Renamed operator','extension'=>'9001'];
$directory->savePhonebook(array_replace($edit, ['manual_entries'=>[$manualDraft]]));
$retainedManual = $directory->phonebook($id)['manual_entries'][0];
$expect($retainedManual['attributes'] === $savedManual['attributes'] && $retainedManual['groups'] === $savedManual['groups'], 'Editing visible contact fields must retain saved attributes and group membership');
$directory->savePhonebook($edit);
$expect(count($directory->phonebook($id)['manual_entries']) === 1, 'An omitted manual-contact field must preserve existing contacts');
$beforeInvalidManual = $db->query('SELECT * FROM ucmp_phonebooks')->fetchAll(PDO::FETCH_ASSOC);
foreach ([
    [['display_name'=>'Incomplete','extension'=>'']],
    [['display_name'=>['invalid'],'extension'=>'9001']],
    [['display_name'=>'Overlong','extension'=>str_repeat('9',65)]],
    array_fill(0,501,$manualFixture),
    '{"invalid":true}',
    '[invalid',
] as $invalidManual) {
    try {
        $directory->savePhonebook(array_replace($edit, ['manual_entries'=>$invalidManual]));
        throw new RuntimeException('Invalid manual contacts were silently removed or truncated');
    } catch (InvalidArgumentException|JsonException $expected) {}
    $expect($db->query('SELECT * FROM ucmp_phonebooks')->fetchAll(PDO::FETCH_ASSOC) === $beforeInvalidManual, 'Invalid contact drafts must leave the saved phonebook unchanged');
}
// Confirmed delivery must survive local receipt failures; failed retry persistence
// at one destination must also leave the later destination runnable.
$ucm->sent = [];
$db->exec('DROP TABLE ucmp_site_jobs');
$publication = $directory->publishAcceptedPhonebooks();
$expect($ucm->sent === [1,2] && $publication['ok_count'] === 2 && $publication['queued'] === 0, 'A lost acknowledgement record must not queue a completed publication');
$expect(!empty($publication['sites'][1]['published']) && !empty($publication['sites'][1]['reporting_error']), 'Known site publication and its reporting error must remain distinct');
$ucm->sent = [];
$ucm->failSites = [1];
$publication = $directory->publishAcceptedPhonebooks();
$expect($ucm->sent === [1,2] && $publication['ok_count'] === 1 && $publication['queued'] === 0, 'Retry persistence failure must not abort other sites or invent a queued job');
$expect(empty($publication['sites'][1]['published']) && !empty($publication['sites'][2]['published']), 'Mixed destination outcomes must survive');
$directory->failRead = true;
$savedAfterReadFailure = $directory->savePhonebook($edit);
$expect($savedAfterReadFailure === $id && count($directory->phonebooks()) === 1, 'Late publication failure must return the saved identity without creating duplicates');
$expect(str_contains(implode(' ', $directory->lastPublicationResult()['warnings']), 'publication could not start'), 'A completed save must retain the failed publication warning even when logging also fails');
foreach (['menu'=>['Select','Search','Exit','Previous','Next','None'],'list'=>['Dial','EditDial','Search','Exit','Previous','Next','None']] as $view=>$allowed) {
    foreach ($allowed as $a) { foreach ($allowed as $b) { foreach ($allowed as $c) { foreach ($allowed as $d) {
        $keys = $normalize->invoke($directory,[$view=>[$a,$b,$c,$d]])[$view];
        $expect(in_array($view === 'menu' ? 'Select' : 'Dial',$keys,true) && in_array('Exit',$keys,true), 'Every allowed four-slot combination must retain both required actions');
    } } } }
}
$current = $directory->phonebook($id);
$directory->savePhonebook(array_replace($edit,['expected_updated'=>$current['updated']]));
try { $directory->savePhonebook(array_replace($edit,['expected_updated'=>$current['updated']])); throw new LogicException('Stale phonebook editor replaced newer data'); }
catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'changed after'),'Two same-second saves must have distinct revisions'); }
$ucm->failReferences = true;
try { $directory->savePhonebook(array_replace($edit,['publish_sites'=>['site-a']])); throw new LogicException('Narrowed publication without verifying consumers'); }
catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'Cannot verify'),'An unavailable consumer must block publication narrowing'); }
$ucm->failReferences = false;
$second = $directory->savePhonebook(['name'=>'Second book','title'=>'Second book','filename'=>'second.xml']);
$beforeDelete = $db->query('SELECT * FROM ucmp_phonebooks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$db->exec("CREATE TRIGGER fail_default_reassignment BEFORE UPDATE ON ucmp_phonebooks WHEN NEW.id=$second AND NEW.is_default=1 BEGIN SELECT RAISE(ABORT,'Fixture default reassignment failed'); END");
try { $directory->deletePhonebook($id); throw new LogicException('Partial phonebook deletion accepted'); }
catch (PDOException $error) { $expect(str_contains($error->getMessage(),'Fixture default'),'Deletion fixture must reach the later default update'); }
$expect($db->query('SELECT * FROM ucmp_phonebooks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$beforeDelete,'Late default reassignment failure must preserve both books');
$db->exec('DROP TRIGGER fail_default_reassignment');

$shortId=$directory->savePhonebook(['name'=>str_repeat('Long readable name ',3),'title'=>'Readable title','filename'=>''],null,false);
$short=$directory->phonebook($shortId);
$expect(strlen($short['filename'])<=20 && str_starts_with($short['name'],'Long readable name'),'New phonebooks need short files without truncating readable labels');
$longName='retained-legacy-phonebook-reference.xml';
$legacyId=$directory->importPhonebook(['book_key'=>'legacy-reference','name'=>'Legacy','title'=>'Legacy','filename'=>$longName],false);
$expect($directory->phonebook($legacyId)['filename']===$longName,'Import must preserve a source filename still referenced by templates');
echo "Directory complete publication, exclusion, stable import, revision and deletion fixtures passed\n";
