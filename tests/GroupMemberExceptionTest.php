<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TemplateEngine.php';

use FreePBX\modules\Ucmprovisioner\TemplateEngine;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$engine = new TemplateEngine(new class {});
$merge = new ReflectionMethod($engine, 'mergeTemplateLayers');
$baseButtons = [
    ['slot'=>2,'feature'=>'blf','target'=>'4001'],
    ['slot'=>3,'feature'=>'blf','target'=>'4002'],
];
$exceptionButtons = [
    ['slot'=>2,'feature'=>'blf','target'=>'4101'],
    ['slot'=>3,'feature'=>'blf','target'=>'4102'],
    ['slot'=>4,'feature'=>'blf','target'=>'4103'],
    ['slot'=>5,'feature'=>'blf','target'=>'4104'],
];
$merged = $merge->invoke($engine, [
    ['id'=>1,'name'=>'Department','setting_overrides'=>json_encode(['ringtone'=>'base','vlan'=>120]),'buttons'=>json_encode($baseButtons)],
    ['id'=>2,'name'=>'Executive','setting_overrides'=>json_encode(['ringtone'=>'executive']),'buttons'=>json_encode($exceptionButtons),'_replace_buttons'=>true],
], false);
$buttons = json_decode((string)$merged['buttons'], true);
$settings = json_decode((string)$merged['setting_overrides'], true);
$expect(array_column($buttons, 'target') === ['4101','4102','4103','4104'], 'A non-empty member-exception key list must replace the inherited list');
$expect($settings === ['ringtone'=>'executive','vlan'=>120], 'Explicit exception settings must win while unspecified settings remain inherited');

$inherited = $merge->invoke($engine, [
    ['id'=>1,'name'=>'Department','buttons'=>json_encode($baseButtons)],
    ['id'=>2,'name'=>'Blank exception','buttons'=>'[]','_replace_buttons'=>true],
], false);
$expect(array_column(json_decode((string)$inherited['buttons'], true), 'target') === ['4001','4002'], 'A blank exception key list must inherit instead of clearing the resolved list');

$groupsView = (string)file_get_contents(dirname(__DIR__) . '/views/groups.php');
$expect(str_contains($groupsView, 'ucm-group-exception-select-visible') && str_contains($groupsView, 'ucm-group-exception-clear-visible'), 'Member exceptions must support multi-selection tools');
$expect(str_contains($groupsView, 'ucm-group-members-clear-visible'), 'The existing Clear visible membership action must remain available');
$expect(str_contains($groupsView, 'most-specific site default'), 'Empty and non-matching groups must explain their site-default fallback');

$moduleXml = (string)file_get_contents(dirname(__DIR__) . '/module.xml');
$moduleClass = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$expect(str_contains($moduleXml, '<field name="member_exceptions"') && str_contains($moduleClass, "ADD COLUMN member_exceptions"), 'Fresh install and upgrade schemas must both persist member exceptions');

echo "Group member exception assertions passed\n";

if (!interface_exists('BMO')) { interface BMO {} }
if (!class_exists('FreePBX_Helpers')) { class FreePBX_Helpers {} }
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
if (!defined('FREEPBX_IS_AUTH')) { define('FREEPBX_IS_AUTH', true); }
if (!function_exists('_')) { function _(string $message): string { return $message; } }
$viewModule = new class {
    public array $templateSites = [];
    public function localSiteId(): int { return 1; }
    public function getSite(int $id): array { return ['id'=>$id]; }
    public function getSites(): array { return [['id'=>2,'name'=>'Branch <text>']]; }
    public function getGroup(int $id, int $siteId): array { return ['id'=>$id,'site_id'=>$siteId,'name'=>'Policy',
        'template_map'=>'[{"template_id":17}]','member_exceptions'=>'[{"template_id":18,"extensions":["4002"]}]',
        'member_extensions'=>['4001','4002']]; }
    public function getTemplates(int $siteId=0, bool $cached=true): array { $this->templateSites[]=$siteId; return []; }
    public function getPhones(array $filters): array { return []; }
    public function getGroups(int $siteId): array { return []; }
    public function getAllSipExtensions(int $siteId): array { return [['id'=>'4001','description'=>'Available member']]; }
    public function configurationSyncOp(array $request): array { return ['ok'=>true,'jobs'=>[]]; }
    public function configurationSyncStates(int $siteId, string $kind): array { return []; }
};
$render = static function (object $ucm, array $request): string {
    $previous = $_REQUEST; $_REQUEST = $request; ob_start();
    try { include dirname(__DIR__) . '/views/groups.php'; return (string)ob_get_contents(); }
    finally { ob_end_clean(); $_REQUEST = $previous; }
};
$html = $render($viewModule, ['view'=>'group','id'=>31,'site_id'=>2]);
$expect(preg_match('/var TEMPLATES = (.*);/', $html, $match) === 1, 'Rendered group template choices are available');
$choices = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
$expect(array_column($choices, 'id') === [17,18], 'Missing layer and exception references remain visible and serializable');
$document = new DOMDocument(); $previousErrors = libxml_use_internal_errors(true);
try { $document->loadHTML($html, LIBXML_NONET); } finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrors); }
$xpath = new DOMXPath($document);
$expect($xpath->query('//input[@name="member_extensions[]" and @value="4002" and @checked]')->length === 1, 'Unavailable saved membership remains selected');
$expect($xpath->query('//select[@name="site_id" and @disabled]')->length === 1
    && $xpath->query('//input[@name="site_id" and @value="2"]')->length === 1, 'An existing group keeps its owning site');
$render($viewModule, ['site_filter'=>2]);
$expect($viewModule->templateSites === [2,2], 'Editor and list labels use the selected site template inventory');
echo "Group rendered-reference preservation fixtures passed\n";
