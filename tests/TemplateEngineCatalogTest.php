<?php
declare(strict_types=1);

$lib = dirname(__DIR__) . '/lib/';
foreach ([
    'ModelCatalog.php','MediaUrl.php','VlanPolicy.php','IdentityLabelFormatter.php','EndpointProfile.php','LogicalExtensionService.php',
    'TemplateStackResolver.php','DefaultTemplateResolver.php','SoftkeyPolicy.php','FirmwareService.php','TemplateEngine.php',
    'CiscoXmlGenerator.php','CiscoCnfGenerator.php','YealinkGenerator.php','GrandstreamGenerator.php',
] as $file) { require_once $lib . $file; }

use FreePBX\modules\Ucmprovisioner\CiscoCnfGenerator;
use FreePBX\modules\Ucmprovisioner\CiscoXmlGenerator;
use FreePBX\modules\Ucmprovisioner\GrandstreamGenerator;
use FreePBX\modules\Ucmprovisioner\ModelCatalog;
use FreePBX\modules\Ucmprovisioner\TemplateEngine;
use FreePBX\modules\Ucmprovisioner\YealinkGenerator;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

final class TemplateEngineCatalogFakeModule
{
    public array $model = [];
    public array $groups = [];
    public array $templates = [];
    public function localSiteId(): int { return 1; }
    public function getSiteSettings(?int $siteId = null): array { return []; }
    public function getSite(int $siteId): array { return ['id'=>1,'name'=>'SITE-A','pbx_addr'=>'192.0.2.10','tftp_service_host'=>'192.0.2.10']; }
    public function getModel(int $id): ?array { return $id === 1 ? $this->model : null; }
    public function getGroup(int $id): ?array { return null; }
    public function getGroups(int $siteId = 0): array { return $this->groups; }
    public function getTemplates(int $siteId = 0, bool $includeGlobal = true): array { return $this->templates; }
    public function getTemplate(int $id, ?int $siteId = null): ?array { return null; }
    public function getDefaultTemplateRules(int $siteId): array { return []; }
    public function phoneServicesToken(): string { return 'catalog-test-token'; }
    public function tftpOp(array $request): array { return ['ok'=>true,'status'=>'missing','items'=>[],'bundles'=>[]]; }
}

$module = new TemplateEngineCatalogFakeModule();
$generated = 0;
$rejected = 0;
foreach (ModelCatalog::CATALOG as $index=>$row) {
    [$vendor,$modelName,$family,$lineKeys,$softkeys,$featurePolicy,$kem] = $row;
    $capabilities = ModelCatalog::capabilitiesFor((string)$vendor, (string)$modelName);
    $expect(is_array($capabilities), "$vendor $modelName has no resolved capabilities");
    $module->model = [
        'id'=>1,'vendor'=>$vendor,'model'=>$modelName,'family'=>$family,'line_keys'=>$lineKeys,
        'supports_softkeys'=>$softkeys,'supports_featurepolicy'=>$featurePolicy,'kem_support'=>$kem,
        'series_key'=>ModelCatalog::seriesKey((string)$vendor,(string)$modelName,(string)$family),
    ] + $capabilities;
    $phone = [
        'id'=>$index + 1,'site_id'=>1,'model_id'=>1,'group_id'=>0,'extension_id'=>0,
        'extension'=>'6101','mac'=>sprintf('001122%06X',$index + 1),'label'=>'Catalog User',
        'lines_json'=>'[]','overrides'=>'{}',
    ];
    $role = (string)($capabilities['role'] ?? 'desk');
    try {
        $context = (new TemplateEngine($module))->resolvePhone($phone);
    } catch (RuntimeException $error) {
        $expect(in_array($role,['dect_handset','dect_radio','dect_manager'],true), "$vendor $modelName failed in TemplateEngine: {$error->getMessage()}");
        $rejected++;
        continue;
    }
    $expect(in_array($role,['desk','dect_base'],true), "$vendor $modelName unexpectedly received a standalone context");
    $expect((string)($context['model']['model'] ?? '') === (string)$modelName, "$vendor $modelName lost its model identity in TemplateEngine");
    $expect((string)($context['settings']['pbx_addr'] ?? '') === '192.0.2.10', "$vendor $modelName lost destination PBX identity");
    $result = match ((string)$family) {
        'cisco_xml'=>(new CiscoXmlGenerator())->generate($context),
        'cisco_cnf'=>(new CiscoCnfGenerator())->generate($context),
        'yealink'=>(new YealinkGenerator())->generate($context),
        'grandstream'=>(new GrandstreamGenerator())->generate($context),
        default=>throw new RuntimeException("Unknown family $family"),
    };
    $body = (string)($result['xml'] ?? $result['content'] ?? '');
    $expect(($result['filename'] ?? null) !== null && $body !== '', "$vendor $modelName produced no device file after TemplateEngine resolution");
    if (in_array($family,['cisco_xml','grandstream'],true)) {
        $doc = new DOMDocument();
        $expect(@$doc->loadXML($body), "$vendor $modelName produced malformed XML through the complete path");
    }
    $generated++;
}

$expect($generated === 80, "Expected 80 provisionable model paths, resolved $generated");
$expect($rejected === 16, "Expected sixteen DECT role components to fail closed, rejected $rejected");
echo "TemplateEngine catalog path passed: 96 models (80 generated, 16 fail-closed role components)\n";

$module->model=['id'=>1,'vendor'=>'Yealink','model'=>'T48U','family'=>'yealink','line_keys'=>29]
    + ModelCatalog::capabilitiesFor('Yealink','T48U');
$module->groups=[['id'=>10,'site_id'=>1,'name'=>'Fixture','template_id'=>20]];
$module->templates=[['id'=>20,'site_id'=>1,'name'=>'Fixture','buttons'=>json_encode([['slot'=>3,'feature'=>'speeddial','target'=>'900']])]];
$phone=['id'=>1,'site_id'=>1,'model_id'=>1,'group_id'=>10,'extension'=>'6101','mac'=>'001122334455','label'=>'Fixture',
    'lines_json'=>json_encode([['account'=>2,'button'=>2,'extension'=>'6102']]),'overrides'=>'{}'];
$inherited=(new TemplateEngine($module))->resolvePhone($phone);
$expect(count($inherited['lines'])===3,'Fixture must include inherited buttons and both SIP accounts');
$phone['overrides']=json_encode(['buttons'=>[]]);
$cleared=(new TemplateEngine($module))->resolvePhone($phone);
$expect(count($cleared['lines'])===2 && array_column($cleared['lines'],'name')===['6101','6102'],
    'Explicit empty button override must clear inherited keys while retaining SIP accounts');
