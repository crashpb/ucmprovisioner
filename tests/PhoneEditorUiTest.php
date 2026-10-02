<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/views/phones.php');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};

foreach ([
    'ucmp-phone-editor-v2', 'ucm-extension-select', 'ucm-label-input', 'ucm-phone-site',
    'ucm-phone-group', 'ucm-vendor', 'ucm-model', 'ucm-mac-select', 'ucm-mac-value',
    'ucm-secret', 'ucm-phone-lines-panel', 'ucm-phone-lines-table', 'ucm-phone-add-line',
    'ucm-phone-lines-json', 'ucm-dect-section', 'ucm-dect-handset-inventory',
    'ucm-dect-route-list', 'ucm-dect-route-editor', 'ucm-driver', 'ucm-transport',
    'ucm-sip-port', 'ucm-endpoint-effective', 'ucm-pe-summary-endpoint',
] as $id) {
    $expect(substr_count($view, 'id="' . $id . '"') === 1, "#$id must exist exactly once");
}

$expect(substr_count($view, 'data-phone-edit-tab=') === 2, 'Phone editor must expose exactly two tabs');
$expect(substr_count($view, 'data-phone-edit-tab="provisioning"') === 1, 'Provisioning must be the primary editor tab');
$expect(substr_count($view, 'data-phone-edit-tab="advanced"') === 1, 'Advanced must be the only separate editor tab');
$expect(substr_count($view, 'data-phone-edit-panel="provisioning"') === 1, 'Provisioning panel must exist exactly once');
$expect(substr_count($view, 'data-phone-edit-panel="advanced"') === 1, 'Advanced panel must exist exactly once');
foreach (['assignment', 'hardware', 'accounts', 'dect'] as $removedTab) {
    $expect(!str_contains($view, 'data-phone-edit-tab="' . $removedTab . '"'), "The obsolete $removedTab editor tab must not return");
}

foreach ([
    'name="action" value="savephone"', 'name="extension"', 'name="label"',
    'name="group_id"', 'name="model_id"', 'name="model_key"', 'name="mac"', 'name="driver"',
    'name="transport"', 'name="sip_port"',
    'name="secret"', 'name="overrides"', 'name="rebuild_after" value="1"',
    'name="replace_manual" value="1"',
] as $contract) {
    $expect(str_contains($view, $contract), "Missing preserved phone-save contract: $contract");
}
$expect(str_contains($view, 'name="view" value="phone"') && str_contains($view, 'name="site_filter"'), 'Phone saves must preserve the current editor and site context');

$expect(str_contains($view, 'class="ucm-primary-line-row"'), 'Account 1 must be visible in the unified account table');
$expect(str_contains($view, "function addPhoneLineRow(line)"), 'Additional account creation logic must remain');
$expect(str_contains($view, "'#ucm-phone-add-line'"), 'The Add account control must remain wired');
$expect(str_contains($view, "'.ucm-line-del'"), 'Additional accounts must remain removable');
$expect(str_contains($view, 'Shared SIP server profile is not an extension.'), 'Grandstream profile wording must distinguish a profile from an extension');
$expect(str_contains($view, 'function renderDectRoutes()'), 'DECT account routes must render from real active account rows');
$expect(str_contains($view, 'function openDectRouteEditor(account)'), 'DECT handset assignment must have a searchable route editor');
$expect(str_contains($view, 'multi-cell DECT topology'), 'Unsupported multicell manager/radio generation must stay explicitly blocked');
$expect(str_contains($view, 'class="pe2-compat-matrix" aria-hidden="true"'), 'Legacy matrix inputs must remain as a hidden serialization compatibility layer');
$expect(str_contains($view, 'function refreshEndpointProfile()'), 'The editor must visibly resolve inherited Cisco/non-Cisco endpoint defaults');
$expect(str_contains($view, 'data-driver="<?php echo htmlspecialchars'), 'Extension choices must carry their real FreePBX driver');
$expect(str_contains($view, 'selected extension driver does not match the resolved phone endpoint'), 'The editor must block a driver mismatch before save');

$expect(
    str_contains($css, '#ucmp-phone-editor-v2 {')
    && str_contains($css, '#ucmp-phone-editor-v2 [data-phone-edit-panel][hidden]')
    && str_contains($css, '#ucmp-phone-editor-v2 .pe2-layout')
    && str_contains($css, '#ucmp-phone-editor-v2 .pe2-compat-matrix'),
    'Phone editor styling and hidden compatibility controls must remain root-scoped'
);
$expect(
    str_contains($css, '#ucmp-phone-editor-v2 .table > tbody > tr > td')
    && str_contains($css, 'color:light-dark(#172033,#edf2f8)'),
    'Phone editor table cells must override Bootstrap dark text inside the dark workbench'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Phone editor layout, save-contract and DECT-routing assertions passed\n";

require_once $root . '/vendor/autoload.php';
interface BMO {}
class FreePBX_Helpers {}
require_once $root . '/Ucmprovisioner.class.php';
define('FREEPBX_IS_AUTH', true);
if (!function_exists('_')) { function _(string $message): string { return $message; } }
$viewModule = new class {
    public bool $missingModel = false;
    public function localSiteId(): int { return 1; }
    public function getPhone(int $id, int $site): array { return ['id'=>$id,'site_id'=>$site,'model_id'=>7,
        'm_vendor'=>'Cisco','m_model'=>'7841','m_family'=>'cisco_xml','vendor'=>'Cisco','extension'=>'4009',
        'mac'=>'001122334455','label'=>'Fixture','ip'=>'','lines_json'=>'[]']; }
    public function getSites(): array { return [['id'=>2,'name'=>'Fixture branch']]; }
    public function getModels(): array { return $this->missingModel ? [] : [
        ['id'=>7,'vendor'=>'Yealink','model'=>'W70B','family'=>'yealink','line_keys'=>10],
        ['id'=>8,'vendor'=>'Cisco','model'=>'7841','family'=>'cisco_xml','line_keys'=>4]]; }
    public function getSiteSettings(int $site): array { return []; }
    public function getGroups(int $site): array { return []; }
    public function getPhones(array $filter): array { return []; }
    public function getAllSipExtensions(int $site): array { return []; }
    public function getBoundDevice(string $extension, int $site): ?array { return null; }
};
$render = static function (object $ucm): array {
    $_REQUEST = ['view'=>'phone','id'=>3,'site_id'=>2]; ob_start();
    try { include dirname(__DIR__) . '/views/phones.php'; return [(string)ob_get_contents(),$selectedEndpointModel]; }
    finally { ob_end_clean(); }
};
$must = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
[$html,$resolved] = $render($viewModule);
$must(($resolved['model'] ?? '') === '7841', 'Stable remote model identity must win over a colliding controller ID');
$viewModule->missingModel = true;
[$html] = $render($viewModule);
$document = new DOMDocument(); $oldErrors = libxml_use_internal_errors(true);
try { $document->loadHTML($html, LIBXML_NONET); } finally { libxml_clear_errors(); libxml_use_internal_errors($oldErrors); }
$xpath = new DOMXPath($document);
foreach (['inherit','udp','tcp','tls'] as $transport) {
    $must($xpath->query('//fieldset[@id="ucm-transport"]//input[@type="radio" and @name="transport" and @value="' . $transport . '" and not(@disabled)]')->length === 1,
        'Cisco XML editor must offer a usable, submitted ' . $transport . ' choice');
}
$must($xpath->query('//select[@id="ucm-extension-select"]/option[@value="4009" and @selected]')->length === 1,
    'An unavailable primary extension remains explicitly selected');
$must($xpath->query('//select[@id="ucm-model"]/option[@value="Cisco|7841" and @selected]')->length === 1,
    'An unavailable saved model remains visible instead of becoming an empty selection');
echo "Phone rendered identity and missing-inventory fixtures passed\n";
