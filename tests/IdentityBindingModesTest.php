<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/LogicalExtensionService.php';
require_once __DIR__ . '/../lib/DirectoryService.php';
require_once __DIR__ . '/../lib/IdentityLabelFormatter.php';

use FreePBX\modules\Ucmprovisioner\LogicalExtensionService;
use FreePBX\modules\Ucmprovisioner\DirectoryService;
use FreePBX\modules\Ucmprovisioner\IdentityLabelFormatter;

$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $action): void {
    try { $action(); } catch (DomainException|InvalidArgumentException $e) { return; }
    throw new RuntimeException('Unsafe identity operation was accepted');
};
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE ucmp_extensions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,extension TEXT NOT NULL,
    ad_source_id INTEGER,ad_object_key TEXT,ad_source_revision TEXT,
    binding_mode TEXT NOT NULL DEFAULT 'ad_primary',linked_source_id INTEGER,linked_object_key TEXT,
    identity_adoption TEXT NOT NULL DEFAULT 'legacy_review',manual_identity_json TEXT,canonical_identity_hash TEXT,
    desired_identity_hash TEXT,applied_identity_hash TEXT,apply_status TEXT DEFAULT 'unbound',apply_error TEXT,
    revision INTEGER DEFAULT 1,updated INTEGER DEFAULT 0,pbx_presence INTEGER DEFAULT 0,pbx_last_confirmed INTEGER DEFAULT 0,
    UNIQUE(site_id,extension),UNIQUE(ad_source_id,ad_object_key))");
$db->exec('CREATE TABLE ucmp_directory_sources (id INTEGER PRIMARY KEY,last_accepted_revision TEXT,last_success INTEGER DEFAULT 1)');
$db->exec('CREATE TABLE ucmp_directory_entries (id INTEGER PRIMARY KEY,source_id INTEGER,object_key TEXT,source_revision TEXT,display_name TEXT,label TEXT,extension TEXT,attributes TEXT,site_id INTEGER,applied_hash TEXT,department TEXT,company TEXT,org_unit TEXT,mail TEXT,mobile TEXT,conflicted INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE ucmp_identity_overrides (id INTEGER PRIMARY KEY AUTOINCREMENT,scope_key TEXT,scope_type TEXT,source_id INTEGER,object_key TEXT,extension_id INTEGER,site_id INTEGER,field_name TEXT,mode TEXT,value_json TEXT,reason TEXT,source_revision TEXT,created_by TEXT,updated_by TEXT,created INTEGER,updated INTEGER,UNIQUE(scope_key,field_name,mode))');
$db->exec('CREATE TABLE ucmp_site_jobs (site_id INTEGER,job_type TEXT,object_key TEXT,desired_hash TEXT,state TEXT,updated INTEGER,last_error TEXT)');
$db->exec("INSERT INTO ucmp_directory_sources (id,last_accepted_revision) VALUES (1,'r1')");
$personA = ['source_id'=>1,'object_key'=>'person-a','source_revision'=>'r1','display_name'=>'Alex Example','label'=>'a.example','extension'=>'100','site_id'=>1,'attributes'=>['samaccountname'=>['a.example'],'userprincipalname'=>['different@example.test'],'givenname'=>['Alex'],'sn'=>['Example']]];
$insertPerson = $db->prepare('INSERT INTO ucmp_directory_entries (id,source_id,object_key,source_revision,display_name,label,extension,attributes,site_id) VALUES (?,?,?,?,?,?,?,?,?)');
$insertPerson->execute([1,1,'person-a','r1','Alex Example','a.example','100',json_encode($personA['attributes']),1]);
$logical = new LogicalExtensionService($db);
$primary = $logical->bindAdIdentity(1,1,'person-a','r1',$personA);
$secondary = $logical->ensurePbxExtension(1,'200');
$secondary = $logical->setIdentityBinding((int)$secondary['id'],'linked_additional',1,'person-a',[],(int)$secondary['revision']);
$manual = $logical->ensurePbxExtension(1,'300');
$manual = $logical->setIdentityBinding((int)$manual['id'],'manual_shared',null,null,['display_name'=>'Shared Service Desk','label'=>'Helpdesk']);
$reject(fn()=>$logical->bindAdIdentity(1,1,'person-c','r1',['display_name'=>'C Example','extension'=>'300']));
$reject(fn()=>$logical->bindAdIdentity(1,1,'person-a','r1',['display_name'=>'Alex Example','extension'=>'400']));
$expect(count($logical->enumerateBindings(1,'person-a')) === 2, 'Primary and additional bindings must enumerate by stable person');
$resolved = LogicalExtensionService::resolveBoundIdentity($secondary,$personA,[],fn($identity)=>IdentityLabelFormatter::outputs($identity));
$expect($resolved['full_name'] === 'Alex Example' && $resolved['short_name'] === 'a.example' && $resolved['canonical']['extension'] === '200', 'Additional extension must retain its own number and resolve full/short person names');
$expect(count($resolved['warnings']) === 1, 'Account disagreement must be visible');
$noTelephone = array_replace($personA,['extension'=>'']);
$expect(LogicalExtensionService::resolveBoundIdentity($secondary,$noTelephone)['resolved'], 'Additional binding does not require primary telephoneNumber');
$upnOnly = array_replace($noTelephone,['attributes'=>['userprincipalname'=>['a.example@example.test']]]);
$expect(LogicalExtensionService::resolveBoundIdentity($secondary,$upnOnly)['short_name'] === 'a.example', 'UPN prefix must be an explicit fallback');
$expect(IdentityLabelFormatter::outputs(['display_name'=>'Do Not Split Me'],['phone_label'=>'{given}/{surname}'])['phone_label'] === '/', 'Formatter must not guess structured names');
$expect(LogicalExtensionService::resolveBoundIdentity($manual,null)['full_name'] === 'Shared Service Desk', 'Manual full name must remain independent of AD');

// Unknown old overrides acquire the departing owner and never follow the slot's new person.
$db->prepare("INSERT INTO ucmp_identity_overrides (scope_key,scope_type,extension_id,field_name,mode,value_json) VALUES (?,'extension',?,'phone_label','final','\"Old Personal Label\"')")->execute(['extension:' . $primary['id'],$primary['id']]);
$reject(fn()=>$logical->reconcileAcceptedSourceBindings(1,'r1',[],false));
$expect($logical->findByAdObject(1,'person-a') !== null, 'Incomplete source must retain primary');
$db->exec("UPDATE ucmp_directory_sources SET last_accepted_revision='r2'; UPDATE ucmp_directory_entries SET source_revision='r2',extension='' WHERE id=1");
$released = $logical->reconcileAcceptedSourceBindings(1,'r2',[array_replace($personA,['extension'=>'','source_revision'=>'r2'])],true);
$expect(count($released['released']) === 1, 'Complete source can release a now numberless primary owner');
$personB = ['source_id'=>1,'object_key'=>'person-b','source_revision'=>'r2','display_name'=>'Blair Example','label'=>'b.example','extension'=>'100','site_id'=>1];
$primaryB = $logical->bindAdIdentity(1,1,'person-b','r2',$personB);
$expect($logical->overridesForExtension((int)$primaryB['id']) === [], 'Old person override must not transfer to replacement');
$expect(count($logical->overridesForExtension((int)$primaryB['id'],true)) === 1, 'Old override must remain reviewable');
$expect(LogicalExtensionService::identityBinding($logical->findById((int)$secondary['id']))['object_key'] === 'person-a', 'Additional binding must not follow replacement at old primary number');

// Real source/delivery hash cycle and destination drift check, using an isolated destination.
$ucm = new class($db) {
    public array $destination = [];
    public int $calls = 0;
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int { return 1; }
    public function getSites(): array { return [['id'=>1,'site_key'=>'owner-site','enabled'=>1]]; }
    public function getSite(int $id): array { return $this->getSites()[0]; }
    public function getSetting(string $key,?int $site=null,$default=null) { return $default; }
    public function getSiteSettingsLocal(int $site): array { return ['ad_sync_names'=>'1','ad_sync_labels'=>'1']; }
    public function applyDirectoryIdentities(int $siteId,array $batch): array {
        $this->calls++;
        $results = [];
        foreach ($batch as $item) {
            $changed = ($this->destination[$item['extension']] ?? null) !== $item['display_name'];
            $this->destination[$item['extension']] = $item['display_name'];
            $results[$item['extension']] = ['ok'=>true,'changed'=>$changed,'callerid_checked'=>true];
        }
        return ['results'=>$results];
    }
};
$directory = new DirectoryService($ucm);
$expect($directory->resolvePerson(1,'person-a')['extension'] === '', 'Accepted person lookup must survive number removal');
$apply = new ReflectionMethod(DirectoryService::class,'applyIdentities');
$entry = static fn(array $row): array => $personB + ['extension_id'=>$row['id'],'extension_revision'=>$row['revision'],'desired_identity_hash'=>$row['desired_identity_hash'],'applied_identity_hash'=>$row['applied_identity_hash'],'source_key'=>'public-b'];
$settings = ['ad_sync_names'=>'1','ad_sync_labels'=>'1'];
$manualCollision = $personB;
$manualCollision['extension'] = '300';
$manualCollision['source_key'] = 'synthetic-collision';
$manualOutcome = $apply->invoke($directory,[$manualCollision],$settings,false,['site_id'=>1]);
$expect(($ucm->destination['300'] ?? '') === 'Shared Service Desk', 'An AD number collision must not replace an explicitly manual full name');
$manualTargets = array_values(array_filter($manualOutcome['targets'],static fn(array $target): bool=>$target['extension'] === '300'));
$expect(count($manualTargets) === 1 && $manualTargets[0]['binding_mode'] === 'manual_shared', 'A manual slot must never receive an AD-primary delivery target');
$ucm->calls = 0;
$scope = ['extension_ids'=>[(int)$primaryB['id']]];
$apply->invoke($directory,[$entry($primaryB)],$settings,false,$scope);
$applied = $logical->findById((int)$primaryB['id']);
$expect($applied['apply_status'] === 'applied', 'First effective delivery must be acknowledged');
$same = $logical->bindAdIdentity(1,1,'person-b','r3',$personB);
$apply->invoke($directory,[$entry($same)],$settings,false,$scope);
$unchanged = $logical->findById((int)$same['id']);
$expect($ucm->calls === 1 && $unchanged['revision'] === $applied['revision'] && $unchanged['apply_status'] === 'applied', 'Identical bind/apply cycle must not churn revision or lose acknowledgement');
$ucm->destination['100'] = 'Destination Drift';
$apply->invoke($directory,[$entry($unchanged)],$settings,true,$scope);
$expect($ucm->calls === 2 && $ucm->destination['100'] === 'Blair Example', 'Force must verify/correct actual destination despite unchanged source hash');
$insertPerson->execute([2,1,'person-b','r3','Blair Example','b.example','100','{}',1]);
$db->exec("CREATE TRIGGER fail_directory_ack BEFORE UPDATE OF applied_hash ON ucmp_directory_entries BEGIN SELECT RAISE(ABORT,'fixture accepted-catalog receipt failure'); END");
$ucm->destination['100'] = 'Fixture drift';
$receiptFailure = $apply->invoke($directory,[$entry($logical->findById((int)$primaryB['id']))],$settings,true,$scope);
$expect($receiptFailure['changed'] === 1 && $receiptFailure['queued'] === 0 && !empty($receiptFailure['warnings']), 'Confirmed identity delivery must survive a later catalog acknowledgement failure');
$expect($logical->findById((int)$primaryB['id'])['apply_status'] === 'applied', 'Reporting failure must not change a confirmed identity back to failed');
$db->exec('DROP TRIGGER fail_directory_ack');
$db->exec('DELETE FROM ucmp_directory_entries WHERE id=2');
$callsBeforeUpgrade = $ucm->calls;
$db->prepare('UPDATE ucmp_extensions SET canonical_identity_hash=NULL WHERE id=?')->execute([$primaryB['id']]);
$upgrade = $logical->bindAdIdentity(1,1,'person-b','r4',$personB);
$apply->invoke($directory,[$entry($upgrade)],$settings,false,$scope);
$expect($logical->findById((int)$primaryB['id'])['apply_status'] === 'applied' && $ucm->calls === $callsBeforeUpgrade, 'Upgrade hash initialization must retain an already-current destination acknowledgement');

$legacy = $logical->ensurePbxExtension(1,'500');
$legacy = $logical->bindAdIdentity(1,1,'person-legacy','r2',['display_name'=>'Legacy Example','extension'=>'500']);
$expect($legacy['identity_adoption'] === 'legacy_review', 'Existing unknown slot cannot silently adopt AD presentation');
$choices = array_fill_keys(array_merge(['display_name'],LogicalExtensionService::FINAL_FIELDS),['mode'=>'managed']);
$choices['phone_label'] = ['mode'=>'preserve','value'=>'Reviewed Legacy Label'];
$adopted = $logical->adoptIdentity((int)$legacy['id'],(int)$legacy['revision'],$choices,'fixture-reviewer');
$expect($adopted['identity_adoption'] === 'managed' && count($logical->overridesForExtension((int)$legacy['id'])) === 1, 'Adoption must store explicit reviewed ownership');
$reject(fn()=>$logical->adoptIdentity((int)$legacy['id'],(int)$legacy['revision'],$choices,'fixture-reviewer'));
$moves = [];
for ($i=0;$i<501;$i++) { $moves[] = ['source_id'=>1,'object_key'=>'unbound-' . $i,'source_revision'=>'r2','site_id'=>1,'identity'=>['extension'=>(string)(1000+$i),'display_name'=>'Synthetic Person']]; }
$expect($logical->reconcileAuthoritativeAdIdentityMoves($moves)['applied'] === [], 'Move budget must count actual moves, not complete source rows');
echo "Identity binding, reassignment, adoption and force-drift fixtures passed\n";

// Upgrade compatibility uses accepted ownership, never a live PBX label guess.
$db->exec("INSERT INTO ucmp_directory_sources (id,last_accepted_revision) VALUES (8,'accepted-8')");
$seed = $db->prepare("INSERT INTO ucmp_extensions (site_id,extension,ad_source_id,ad_object_key,ad_source_revision) VALUES (8,?,8,?,'accepted-8')");
$ids = [];
foreach (['700','701','702','703','704'] as $number) {
    $seed->execute([$number,'stable-'.$number]);
    $ids[$number] = (int)$db->lastInsertId();
    $insertPerson->execute([(int)$number,8,'stable-'.$number,'accepted-8','Accepted Person','accepted',$number,'{}',8]);
}
$db->prepare('UPDATE ucmp_extensions SET manual_identity_json=? WHERE id=?')->execute(['{"display_name":"Manual Name"}',$ids['701']]);
$db->exec('UPDATE ucmp_directory_entries SET conflicted=1 WHERE id=704');
$putOverride=$db->prepare("INSERT INTO ucmp_identity_overrides (scope_key,scope_type,extension_id,source_id,object_key,source_revision,field_name,mode,value_json) VALUES (?,'extension',?,?,?,?, 'phone_label','final',?)");
$putOverride->execute(['extension:'.$ids['700'],$ids['700'],8,'stable-700','accepted-8','"Keep My Label"']);
$putOverride->execute(['extension:'.$ids['702'],$ids['702'],null,null,null,'"Unknown Owner Label"']);
$putOverride->execute(['extension:'.$ids['703'],$ids['703'],8,'another-person','accepted-8','"Previous Person Label"']);
$overridesBefore=$db->query('SELECT * FROM ucmp_identity_overrides ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$plan=$logical->planLegacyIdentityAdoption(5000,8);
$expect($plan['eligible_count']===1 && $plan['eligible'][0]['extension_id']===$ids['700'] && $plan['uncertain_count']===4,'Only proven current relationships are upgrade-compatible');
$db->beginTransaction();
$logical->adoptLegacyIdentityRelationships($plan['eligible']);
$db->rollBack();
$expect($logical->findById($ids['700'])['identity_adoption']==='legacy_review','Failed snapshot acceptance rolls back compatibility classification');
$db->exec("UPDATE ucmp_directory_sources SET last_accepted_revision='changed' WHERE id=8");
$reject(fn()=>$logical->adoptLegacyIdentityRelationships($plan['eligible']));
$db->exec("UPDATE ucmp_directory_sources SET last_accepted_revision='accepted-8' WHERE id=8");

// Exercise the actual installer caller with isolated schema/catalog no-ops.
interface BMO {}
class FreePBX_Helpers {}
require_once __DIR__.'/../Ucmprovisioner.class.php';
$installer=new class(new class($db) {
    public function __construct(private PDO $db) {}
    public function Database(): PDO { return $this->db; }
}) extends \FreePBX\modules\Ucmprovisioner {
    public function ensureSchema(bool $force=false): void { if(!$force)throw new RuntimeException('Install must run schema repair'); }
    public function ensureModelCatalog(): void {}
};
$installer->install();
$expect($logical->findById($ids['700'])['identity_adoption']==='managed','Install must activate established accepted ownership');
foreach(['701','702','703','704'] as $number)$expect($logical->findById($ids[$number])['identity_adoption']==='legacy_review','Manual/uncertain relationships must remain unchanged');
$expect($overridesBefore===$db->query('SELECT * FROM ucmp_identity_overrides ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Install must preserve every override byte and its ownership');
$expect($logical->findById($ids['701'])['manual_identity_json']==='{"display_name":"Manual Name"}','Manual identity must remain intact');
$expect($logical->planLegacyIdentityAdoption(5000,8)['eligible_count']===0,'Compatibility classification must be idempotent');
echo "Actual install compatibility preserves manual identities, overrides and revision guards\n";
