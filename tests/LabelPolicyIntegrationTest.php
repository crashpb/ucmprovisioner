<?php
declare(strict_types=1);

require __DIR__ . '/TemplateIdentityAccountsTest.php';

use FreePBX\modules\Ucmprovisioner\CiscoXmlGenerator;
use FreePBX\modules\Ucmprovisioner\TemplateEngine;

$expect = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$db->exec('ALTER TABLE ucmp_identity_overrides ADD COLUMN value_json TEXT');
$db->exec("INSERT INTO ucmp_extensions VALUES (9,2,'227',1,'ad_primary',1,'person-a',NULL,NULL,'managed',NULL)");
$ucm = new class($db) {
    public array $siteSettings = [];
    public array $templateSettings = ['phone_label_pattern'=>'{extension}'];
    public array $buttons = [['feature'=>'blf','target'=>'227','target_site'=>2,'label'=>'227']];
    public bool $hasTemplate = true;
    public bool $memberBlf = false;
    public string $book = 'staff';
    public function __construct(private PDO $db) {}
    public function getDb(): PDO { return $this->db; }
    public function getSetting(string $key, ?int $site=null, $default=null) { return $default; }
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['id'=>$id,'site_key'=>'site-'.$id,'name'=>'Site '.$id,'pbx_addr'=>'192.0.2.10']; }
    public function getSites(): array { return [$this->getSite(1),$this->getSite(2)]; }
    public function getSiteSettings(int $id): array { return $this->siteSettings + ['cisco_directory_url'=>'https://pbx.example.test/directory?format=cisco']; }
    public function phoneServicesToken(): string { return '0123456789abcdef0123456789abcdef'; }
    public function getModel(int $id): array { return ['id'=>1,'vendor'=>'Cisco','model'=>'8845','family'=>'cisco_xml','line_keys'=>5]; }
    public function getGroup(int $id): array { return ['id'=>10,'site_id'=>1,'template_id'=>1,'member_blf'=>$this->memberBlf ? 1 : 0,'extra_targets'=>'[]']; }
    public function getGroups(int $site): array { return [$this->getGroup(10)]; }
    public function getTemplate(int $id): array {
        return ['id'=>1,'site_id'=>1,'name'=>'Fixture','target_family'=>'cisco_xml','setting_overrides'=>json_encode($this->templateSettings),
            'buttons'=>json_encode($this->buttons),'member_blf'=>$this->memberBlf ? 1 : 0,'phonebook_key'=>$this->book];
    }
    public function getTemplates(int $site, bool $global=false): array { return $this->hasTemplate ? [$this->getTemplate(1)] : []; }
    public function getDefaultTemplateRules(int $site): array { return []; }
    public function getPhones(array $filters=[]): array { return [['extension'=>'227','label'=>'Stored label']]; }
    public function getAllSipExtensions(?int $site=null): array { return [['extension'=>'227','description'=>'Branch Reception']]; }
};
$phone = ['id'=>1,'site_id'=>1,'group_id'=>10,'model_id'=>1,'extension'=>'100','mac'=>'001122AABBCC','label'=>'Desk','lines_json'=>'[]','overrides'=>''];
$resolve = static function (array $overrides=[]) use ($ucm, $phone): array {
    $p = $phone;
    if (!$ucm->hasTemplate) { $p['group_id'] = null; }
    $p['overrides'] = json_encode($overrides);
    return (new TemplateEngine($ucm))->resolvePhone($p, false);
};
$xmlFields = static function (array $context): array {
    $xml = (new CiscoXmlGenerator())->generate($context)['xml'];
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) { throw new RuntimeException('Invalid generated XML'); }
    $xpath = new DOMXPath($doc);
    return array_map(static fn(string $path): string => $xpath->evaluate('string('.$path.')'), [
        '/device/sipProfile/phoneLabel', '/device/sipProfile/sipLines/line[@button="2"]/featureLabel',
        '/device/sipProfile/sipLines/line[@button="2"]/speedDialNumber',
    ]);
};
$expect($xmlFields($resolve()) === ['100','P.Alpha','227'], 'AD BLF names and numeric top labels must remain independent through XML');
foreach (['extension'=>'227','extension_name'=>'Person Alpha','custom'=>'227'] as $policy=>$expected) {
    $c = $resolve(['settings'=>['blf_label_source'=>$policy]]);
    $expect($xmlFields($c)[1] === $expected, 'Per-phone BLF source must apply: '.$policy);
}
$c = $resolve(['settings'=>['blf_label_pattern'=>'Desk {label}','directory_label_pattern'=>'{extension}',
    'cisco_directory_url'=>'https://pbx.example.test/other?format=cisco&label_pattern=old']]);
$expect($xmlFields($c)[1] === 'Desk P.Alpha', 'Per-phone BLF pattern must apply before XML rendering');
parse_str((string)parse_url($c['settings']['cisco_directory_url'], PHP_URL_QUERY), $query);
$expect(($query['label_pattern'] ?? null) === '{extension}' && ($query['book'] ?? '') === 'staff', 'Final directory URL must include phone label policy and template book');
$ucm->book = TemplateEngine::PHONEBOOK_NONE;
$expect(empty($resolve()['settings']['cisco_directory_url']), 'Explicit no-directory selection must remain disabled');
$ucm->book = 'staff';
$override = $db->prepare("INSERT INTO ucmp_identity_overrides (id,scope_key,mode,field_name,value_json) VALUES (1,'extension:9','final','blf_label',?)");
foreach (['','0','Final'] as $value) {
    $db->exec('DELETE FROM ucmp_identity_overrides');
    $override->execute([json_encode($value)]);
    $expect($xmlFields($resolve(['settings'=>['blf_label_pattern'=>'Ignored {label}']]))[1] === $value, 'Final BLF overrides, including blank/zero, must survive XML');
}
$db->exec('DELETE FROM ucmp_identity_overrides');
$ucm->hasTemplate = false;
$ucm->siteSettings = ['blf_label_pattern'=>'Site {label}'];
$c = $resolve(['buttons'=>$ucm->buttons]);
$expect($xmlFields($c)[1] === 'Site P.Alpha', 'Site label policy must apply to phone buttons without a template');
$ucm->hasTemplate = true;
$ucm->siteSettings = [];
$ucm->templateSettings['blf_label_pattern'] = 'EXT {extension}';
$db->exec("UPDATE ucmp_extensions SET identity_adoption='legacy_review' WHERE id=9");
$expect($xmlFields($resolve())[1] === 'Branch Reception', 'Unadopted AD identity must not be relabeled');
$db->exec("UPDATE ucmp_extensions SET identity_adoption='managed',ad_object_key='missing-person' WHERE id=9");
$expect($xmlFields($resolve())[1] === 'Branch Reception', 'Unresolved owned identity must not synthesize a replacement');
$db->exec('DELETE FROM ucmp_extensions WHERE id=9');
$expect($xmlFields($resolve())[1] === 'EXT 227', 'Unbound FreePBX BLF targets must use the pattern');
$db->exec("INSERT INTO ucmp_extensions VALUES (9,2,'227',1,'ad_primary',NULL,NULL,NULL,NULL,'legacy_review',NULL)");
$expect($xmlFields($resolve())[1] === 'EXT 227', 'Unbound inventory rows must not suppress FreePBX BLF patterns');
$ucm->templateSettings = ['phone_label_pattern'=>'{extension}'];
$ucm->memberBlf = true;
$ucm->buttons = [['feature'=>'group_members']];
$c = $resolve();
$expect(array_column(array_slice($c['lines'],1),'label') === ['P.Alpha','Room'], 'Generated group-member BLFs must use their own identities');
echo "Integrated label policy, directory URLs, final blank/zero overrides and identity guards passed\n";
