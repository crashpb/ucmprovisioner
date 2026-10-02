<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
require_once dirname(__DIR__) . '/lib/DefaultTemplateResolver.php';
require_once dirname(__DIR__) . '/lib/TemplateStackResolver.php';
require_once dirname(__DIR__) . '/lib/TemplateEngine.php';

use FreePBX\modules\Ucmprovisioner\TemplateEngine;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$ucm = new class {
    public int $groupReads = 0;
    public int $templateReads = 0;
    public int $defaultReads = 0;
    public int $modelReads = 0;
    public function localSiteId(): int { return 1; }
    public function getModel(int $id): ?array {
        $this->modelReads++;
        return $id === 10
            ? ['id'=>10,'vendor'=>'Cisco','model'=>'7841','family'=>'cisco_xml','series_key'=>'cisco_78xx','role'=>'desk']
            : null;
    }
    public function getGroups(int $siteId): array {
        $this->groupReads++;
        return [[
            // A federated PBX returns its own local row id; the controller must
            // normalize ownership to the selected controller-side site id.
            'id'=>5,'site_id'=>1,'name'=>'Department A','parent_group_id'=>null,
            'template_map'=>json_encode([['template_id'=>20,'position'=>10]]),
            'member_exceptions'=>json_encode([['template_id'=>30,'extensions'=>['4200']]]),
        ],[
            'id'=>6,'site_id'=>1,'name'=>'Empty group','parent_group_id'=>null,
            'template_map'=>'[]','member_exceptions'=>'[]',
        ]];
    }
    public function getTemplates(int $siteId, bool $includeGlobal = false): array {
        $this->templateReads++;
        return [
            ['id'=>10,'name'=>'Base 78xx','target_family'=>'cisco_xml'],
            ['id'=>20,'name'=>'Department A phones','target_family'=>'cisco_xml'],
            ['id'=>30,'name'=>'Executive exception','target_family'=>'cisco_xml'],
        ];
    }
    public function getDefaultTemplateRules(int $siteId): array {
        $this->defaultReads++;
        return [['id'=>1,'template_id'=>10,'target_family'=>'cisco_xml','enabled'=>1]];
    }
    public function getTemplate(int $id, ?int $siteId = null): ?array { return null; }
};

$engine = new TemplateEngine($ucm);
$defaultTrace = $engine->templateAssignmentTrace(['site_id'=>1,'model_id'=>10,'group_id'=>null]);
$defaultApplied = array_values(array_filter($defaultTrace, static fn(array $row): bool => ($row['status'] ?? '') === 'applied'));
$expect(count($defaultApplied) === 1, 'An ungrouped phone must expose its effective default template');
$expect(($defaultApplied[0]['source'] ?? '') === 'default' && ($defaultApplied[0]['template_name'] ?? '') === 'Base 78xx', 'Default trace must retain its source and display name');

$groupTrace = $engine->templateAssignmentTrace(['site_id'=>1,'model_id'=>10,'group_id'=>5]);
$groupApplied = array_values(array_filter($groupTrace, static fn(array $row): bool => ($row['status'] ?? '') === 'applied'));
$expect(array_column($groupApplied, 'template_name') === ['Department A phones'], 'A compatible group stack must suppress the site default');
$expect(($groupApplied[0]['source'] ?? '') === 'group' && ($groupApplied[0]['group_name'] ?? '') === 'Department A', 'Group-derived template must retain its assignment origin');
$expect($ucm->templateReads === 1 && $ucm->defaultReads === 1 && $ucm->groupReads === 1, 'Phone table template resolution must cache site catalogs instead of reading them per phone');

$emptyGroupTrace = $engine->templateAssignmentTrace(['site_id'=>1,'model_id'=>10,'group_id'=>6]);
$emptyGroupApplied = array_values(array_filter($emptyGroupTrace, static fn(array $row): bool => ($row['status'] ?? '') === 'applied'));
$expect(array_column($emptyGroupApplied, 'template_name') === ['Base 78xx'], 'An empty or non-matching group must fall back to the site default');
$expect(($emptyGroupApplied[0]['source'] ?? '') === 'default', 'The empty-group fallback must remain visibly identified as Default');

$exceptionTrace = $engine->templateAssignmentTrace(['site_id'=>1,'model_id'=>10,'group_id'=>5,'extension'=>'4200']);
$exceptionApplied = array_values(array_filter($exceptionTrace, static fn(array $row): bool => ($row['status'] ?? '') === 'applied'));
$expect(array_column($exceptionApplied, 'template_name') === ['Department A phones','Executive exception'], 'A matching member exception must run after the normal group stack');
$expect(($exceptionApplied[1]['source'] ?? '') === 'exception', 'Member exceptions must have a distinct visible assignment source');

$remoteTrace = $engine->templateAssignmentTrace([
    'site_id'=>3,'model_id'=>77,'group_id'=>5,
    'm_vendor'=>'Cisco','m_model'=>'7841','m_family'=>'cisco_xml',
]);
$remoteApplied = array_values(array_filter($remoteTrace, static fn(array $row): bool => ($row['status'] ?? '') === 'applied'));
$expect(array_column($remoteApplied, 'template_name') === ['Department A phones'], 'A federated phone must resolve the same group-authoritative stack as its owning site');
$expect($ucm->modelReads === 4, 'Hydrated federated model identity must not be reinterpreted through the controller model id');
$expect($ucm->templateReads === 2 && $ucm->defaultReads === 1 && $ucm->groupReads === 2, 'Each owning site catalog must be loaded once and a matching group must not read an unused remote default');

$phonesView = (string)file_get_contents(dirname(__DIR__) . '/views/phones.php');
$expect(str_contains($phonesView, 'templateAssignmentTrace') && str_contains($phonesView, "sprintf(_('Group: %s')") && str_contains($phonesView, "_('Default')"), 'Phones table must display effective template names with their assignment source');
$expect(str_contains($phonesView, "\$siteFilter === 'all'") && str_contains($phonesView, '$groupNamesBySite'), 'All-sites inventory must fan out and keep site-owned group names separate');
$expect(
    str_contains($phonesView, 'data-site="<?php echo $rowSiteId; ?>"')
    && str_contains($phonesView, 'var bySite = {}')
    && str_contains($phonesView, 'siteIds.forEach(function (siteId)')
    && str_contains($phonesView, '(bySite[siteId] || {})[ext]')
    && !str_contains($phonesView, "if (siteId === 'all' || !siteId)"),
    'All-sites live status must query and match each owning site instead of collapsing duplicate extensions onto the controller'
);

$module = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$expect(str_contains($module, "\$row['site_id'] = \$siteId;"), 'Federated phone rows must carry their controller-side owner site id');

echo "Phone template assignment trace assertions passed\n";
