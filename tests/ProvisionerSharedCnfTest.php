<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FreePBX\modules\Ucmprovisioner\ModelCatalog;
use FreePBX\modules\Ucmprovisioner\Provisioner;
use FreePBX\modules\Ucmprovisioner\TemplateEngine;

$module = new class {
    public array $settings = ['cisco_cnf_sip_port'=>5070];
    public array $templates = [];
    public array $rules = [];
    public function localSiteId(): int { return 1; }
    public function getSiteSettings(?int $siteId = null): array { return $this->settings; }
    public function getSite(int $id): array { return ['id'=>1,'name'=>'Fixture','pbx_addr'=>'192.0.2.10','tftp_service_host'=>'192.0.2.10']; }
    public function getModel(int $id): array { return ['id'=>1,'vendor'=>'Cisco','model'=>'7940','family'=>'cisco_cnf','line_keys'=>2]
        + (ModelCatalog::capabilitiesFor('Cisco', '7940') ?? []); }
    public function getGroup(int $id): ?array { return null; }
    public function getGroups(int $site = 0): array { return []; }
    public function getTemplates(int $site = 0, bool $global = true): array { return $this->templates; }
    public function getTemplate(int $id, ?int $site = null): ?array { return null; }
    public function getDefaultTemplateRules(int $site): array { return $this->rules; }
    public function phoneServicesToken(): string { return 'fixture-only'; }
    public function tftpOp(array $request): array { return ['ok'=>true, 'status'=>'missing', 'items'=>[], 'bundles'=>[]]; }
};
$phone = ['id'=>1,'site_id'=>1,'model_id'=>1,'group_id'=>0,'extension_id'=>0,'extension'=>'700',
    'mac'=>'001122334455','label'=>'Fixture','lines_json'=>'[]','overrides'=>'{}'];
$engine = new TemplateEngine($module);
$provisioner = new Provisioner($module);
$files = new ReflectionMethod(Provisioner::class, 'buildFileSet');
$resolved = $engine->resolvePhone($phone);
$output = $files->invoke($provisioner, [$resolved], []);
$published = array_column($output['files'], 'content', 'filename');
if (!str_contains($published['SIPDefault.cnf'], 'proxy1_port: "5070"')) {
    throw new RuntimeException('Final shared CNF must publish the resolved site-specific port');
}
$phone['id'] = 2; $phone['sip_port'] = 5080; $phone['mac'] = '001122334466';
$different = $engine->resolvePhone($phone);
foreach ([[$different], [$resolved, $different]] as $selection) {
    $rejected = false;
    try { $files->invoke($provisioner, $selection, []); }
    catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'shared policy'); }
    if (!$rejected) { throw new RuntimeException('Targeted and combined incompatible shared CNF policy must fail explicitly'); }
}
$decorated = $resolved;
$decorated['settings']['hosted_image_url'] = 'http://192.0.2.10/logos/selected.bmp';
$decorated['settings']['cisco_legacy_directory_url'] = 'http://192.0.2.10/directory/selected';
foreach ([[$decorated], [$decorated, $resolved]] as $selection) {
    // Each handset can have its own defaults, while SIPDefault stays canonical.
    if (count($selection) > 1) {
        $selection[1]['phone']['id'] = 3;
        $selection[1]['phone']['mac'] = '001122334477';
    }
    $result = $files->invoke($provisioner, $selection, []);
    $contents = array_column($result['files'], 'content', 'filename');
    if (!str_contains($contents['SIP001122334455.cnf'], 'selected.bmp')
        || !str_contains($contents['SIP001122334455.cnf'], '/directory/selected')
        || str_contains($contents['SIPDefault.cnf'], 'selected')) {
        throw new RuntimeException('Per-phone CNF defaults must publish only on their handset and leave shared defaults stable');
    }
}
$conflicting = $decorated;
$conflicting['settings']['ntp_server'] = '192.0.2.99';
try {
    $files->invoke($provisioner, [$conflicting], []);
    throw new LogicException('Per-phone decorations must not bypass a shared NTP conflict');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'shared policy')) { throw $error; }
}
$module->settings['cisco_legacy_directory_url'] = 'http://192.0.2.10/directory/site-default';
$module->templates = [['id'=>1,'name'=>'No phonebook','target_family'=>'cisco_cnf',
    'phonebook_key'=>TemplateEngine::PHONEBOOK_NONE,'buttons'=>'[]','setting_overrides'=>'{}']];
$module->rules = [['id'=>1,'template_id'=>1,'target_family'=>'cisco_cnf','enabled'=>1]];
unset($phone['sip_port']);
$noDirectory = (new TemplateEngine($module))->resolvePhone($phone);
$result = $files->invoke(new Provisioner($module), [$noDirectory], []);
$contents = array_column($result['files'], 'content', 'filename');
if (!str_contains($contents['SIPDefault.cnf'], '/directory/site-default')
    || !str_contains($contents['SIP001122334466.cnf'], 'directory_url: ""')
    || str_contains($contents['SIP001122334466.cnf'], '/directory/site-default')) {
    throw new RuntimeException('Phonebook None must explicitly clear the inherited directory in final handset bytes');
}
echo "Final shared Cisco CNF policy fixtures passed\n";
