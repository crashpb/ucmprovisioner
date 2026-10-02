<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use FreePBX\modules\Ucmprovisioner\TemplateEngine;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ucmp_extensions (id INTEGER PRIMARY KEY,site_id INTEGER,extension TEXT,revision INTEGER,binding_mode TEXT,ad_source_id INTEGER,ad_object_key TEXT,linked_source_id INTEGER,linked_object_key TEXT,identity_adoption TEXT,manual_identity_json TEXT)');
$db->exec('CREATE TABLE ucmp_directory_sources (id INTEGER PRIMARY KEY,last_accepted_revision TEXT)');
$db->exec('CREATE TABLE ucmp_directory_entries (source_id INTEGER,object_key TEXT,source_revision TEXT,display_name TEXT,label TEXT,extension TEXT,attributes TEXT)');
$db->exec('CREATE TABLE ucmp_identity_overrides (id INTEGER,scope_key TEXT,mode TEXT,field_name TEXT)');
$db->exec("INSERT INTO ucmp_directory_sources VALUES (1,'accepted')");
$person = $db->prepare('INSERT INTO ucmp_directory_entries VALUES (1,?,?,?,?,?,?)');
$person->execute(['person-a','accepted','Person Alpha','legacy-a','100',json_encode(['samaccountname'=>['P.Alpha'],'userprincipalname'=>['P.Alpha@example.invalid']])]);
$person->execute(['person-b','accepted','Person Beta','legacy-b','400',json_encode(['samaccountname'=>['P.Beta']])]);
$row = $db->prepare('INSERT INTO ucmp_extensions VALUES (?,1,?,1,?,?,?,?,?,?,?)');
$row->execute([1,'100','ad_primary',1,'person-a',null,null,'managed',null]);
$row->execute([2,'200','linked_additional',null,null,1,'person-a','managed',null]);
$row->execute([3,'300','manual_shared',null,null,null,null,'managed',json_encode(['display_name'=>'Shared Room','label'=>'Room'])]);
$row->execute([4,'400','ad_primary',1,'person-b',null,null,'legacy_review',null]);
$row->execute([5,'500','linked_additional',null,null,1,'absent-person','managed',null]);
$module = new class($db) {
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function getSite(int $id): array { return ['id'=>$id,'name'=>'Fixture']; }
};
$engine = new TemplateEngine($module);
$apply = new ReflectionMethod(TemplateEngine::class, 'applyIdentityLabels');
$phone = ['extension'=>'100','label'=>'Old'];
$lines = [];
foreach (['100','200','300','400','500'] as $index=>$ext) {
    $lines[] = ['featureId'=>9,'accountIndex'=>$index+1,'name'=>$ext,'authName'=>$ext,'secret'=>'fixture-only',
        'label'=>'Existing '.$ext,'displayName'=>'Observed '.$ext];
}
$settings = [];
$apply->invokeArgs($engine,[&$phone,&$lines,&$settings,1,'grandstream']);
if ($phone['label'] !== 'P.Alpha' || $lines[0]['displayName'] !== 'Person Alpha'
    || $lines[1]['label'] !== 'P.Alpha' || $lines[1]['displayName'] !== 'Person Alpha'
    || $lines[2]['label'] !== 'Room' || $lines[2]['displayName'] !== 'Shared Room'
    || $lines[3]['label'] !== 'Existing 400' || $lines[3]['displayName'] !== 'Observed 400'
    || $lines[4]['label'] !== 'Existing 500' || empty($settings['_identity_warning'])) {
    throw new RuntimeException('All-account naming or adoption/unresolved protection failed');
}
foreach ($lines as $line) {
    if ($line['name'] !== $line['authName'] || $line['secret'] !== 'fixture-only') {
        throw new RuntimeException('Identity resolution altered a SIP account or secret');
    }
}
// A numeric top/status label must not become the label of another person's BLF.
$patterns = new ReflectionProperty(TemplateEngine::class, 'labelPatterns');
$patterns->setValue($engine, ['phone_label'=>'{extension}']);
$source = new ReflectionProperty(TemplateEngine::class, 'blfLabelSource');
$button = new ReflectionMethod(TemplateEngine::class, 'materializeButton');
$blf = $button->invoke($engine, ['feature'=>'blf','target'=>'200','target_site'=>1,'label'=>'200'], 2);
if ($blf['label'] !== 'P.Alpha' || $blf['speedDialNumber'] !== '200') {
    throw new RuntimeException('Default BLF labels must use the AD shortname independently of the phone-label pattern');
}
$xml = (new \FreePBX\modules\Ucmprovisioner\CiscoXmlGenerator())->generate([
    'phone'=>['mac'=>'001122AABBCC','label'=>'100'], 'model'=>['model'=>'8845'],
    'settings'=>['pbx_addr'=>'192.0.2.10'], 'lines'=>[$lines[0] + ['button'=>1], $blf],
])['xml'];
$doc = new DOMDocument();
$doc->loadXML($xml);
$xpath = new DOMXPath($doc);
if ($xpath->evaluate('string(/device/sipProfile/phoneLabel)') !== '100'
    || $xpath->evaluate('string(/device/sipProfile/sipLines/line[@button="2"]/featureLabel)') !== 'P.Alpha'
    || $xpath->evaluate('string(/device/sipProfile/sipLines/line[@button="2"]/speedDialNumber)') !== '200') {
    throw new RuntimeException('Cisco XML must retain the numeric top label and BLF target while emitting the AD BLF shortname');
}
foreach (['extension'=>'200', 'extension_name'=>'Person Alpha', 'custom'=>'Saved label'] as $policy=>$expected) {
    $source->setValue($engine, $policy);
    $resolvedButton = $button->invoke($engine, ['feature'=>'blf','target'=>'200','target_site'=>1,'label'=>'Saved label'], 2);
    if ($resolvedButton['label'] !== $expected) { throw new RuntimeException('Explicit BLF label policy changed: '.$policy); }
}
$source->setValue($engine, 'identity_pattern');
$patterns->setValue($engine, ['phone_label'=>'{extension}','blf_label'=>'{label} / {extension}']);
if ($button->invoke($engine, ['feature'=>'blf','target'=>'200','target_site'=>1], 2)['label'] !== 'P.Alpha / 200') {
    throw new RuntimeException('An explicit BLF pattern must still apply');
}
echo "BLF shortname isolation, emitted XML and explicit label policies passed\n";

$db->exec("UPDATE ucmp_extensions SET ad_object_key='person-b' WHERE id=1");
$db->exec("UPDATE ucmp_directory_entries SET extension='' WHERE object_key='person-a'");
$apply->invokeArgs($engine,[&$phone,&$lines,&$settings,1,'grandstream']);
if ($lines[0]['displayName'] !== 'Person Beta' || $lines[1]['displayName'] !== 'Person Alpha') {
    throw new RuntimeException('Additional account followed the reassigned primary slot instead of its person');
}
echo "Template all-account identity, linked retention and adoption protections passed\n";

$db->exec('CREATE TABLE ucmp_phones (id INTEGER PRIMARY KEY,group_id INTEGER,extension TEXT,label TEXT)');
$db->exec("INSERT INTO ucmp_phones VALUES (1,10,'100','Self'),(2,10,'200','Second'),(3,10,'300','Third')");
$expand=new ReflectionMethod(TemplateEngine::class,'expandGroupMembers');
$expanded=$expand->invoke($engine,[['feature'=>'speeddial','slot'=>2,'target'=>'900'],['feature'=>'group_members','slot'=>5]],['id'=>10],1);
if (array_column($expanded,'slot')!==[2,5,6] || array_column($expanded,'target')!==['900','200','300']) {
    throw new RuntimeException('Explicit group-member slots must start at the requested position regardless of preceding buttons');
}
