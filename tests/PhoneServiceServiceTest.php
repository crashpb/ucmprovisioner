<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/MediaUrl.php';
require_once dirname(__DIR__) . '/lib/PhoneServiceService.php';

use FreePBX\modules\Ucmprovisioner\PhoneServiceService;

final class PhoneServiceStatementStub
{
    public function __construct(private array $rows) {}
    public function execute(array $params = []): bool { return true; }
    public function fetchAll(int $mode = 0): array { return $this->rows; }
}

final class PhoneServiceDbStub
{
    public function __construct(private array $rows) {}
    public function prepare(string $sql): PhoneServiceStatementStub { return new PhoneServiceStatementStub($this->rows); }
}

final class PhoneServiceUcmStub
{
    public function __construct(private string $base = 'https://phones.example.test:8443/pbx/') {}

    private array $services = [[
        'id'=>1,
        'site_id'=>1,
        'service_key'=>'directory',
        'name'=>'Company Directory',
        'service_type'=>'directory',
        'enabled'=>1,
        'locked'=>1,
        'menu_order'=>10,
        'settings_json'=>'{}',
        'updated'=>1,
    ]];

    public function localSiteId(): int { return 1; }
    public function phoneServicesToken(bool $create = true): string { return '0123456789abcdef0123456789abcdef'; }
    public function getSite(int $siteId): array { return ['pbx_addr'=>'10.0.0.10']; }
    public function getDb(): PhoneServiceDbStub { return new PhoneServiceDbStub($this->services); }
    public function getSetting(string $key, ?int $siteId = null, mixed $default = null): mixed
    {
        return match ($key) {
            'phone_services_base_url' => $this->base,
            'services_url' => 'http://wrong.example.test/services.php?mac=wrong',
            'directory_token' => '',
            default => $default,
        };
    }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$service = new PhoneServiceService(new PhoneServiceUcmStub());
$root = $service->render('cisco_legacy', 'aabbccddeeff', []);
$expectedRoot = 'https://phones.example.test:8443/pbx/ucmp-s.php/0123456789abcdef0123456789abcdef/cisco_legacy/aabbccddeeff';
$expect(str_contains($root['body'], $expectedRoot . '/service/directory'), 'Nested phone-service URL must retain scheme, authority, port, base path, format, and current MAC');
$expect(!str_contains($root['body'], 'wrong.example.test'), 'Legacy site-wide services_url must not leak into managed service links');

$directory = $service->render('cisco_legacy', 'aabbccddeeff', ['service','directory']);
$expect(str_contains($directory['body'], '<CiscoIPPhoneText>'), 'Missing directory cache must render a legacy-compatible text response');
$expect(str_contains(strtolower($directory['body']), 'directory is unavailable'), 'Missing directory cache must explain that the directory is unavailable');
$expect(!str_contains($directory['body'], 'ucmprovisioner-directory.php'), 'Missing directory cache must not advertise a guaranteed-403 URL');
$expect(!str_contains($directory['body'], 'CiscoIPPhoneExecute'), 'Legacy Cisco service responses must not emit unsupported execute objects');

foreach (['http://user:pass@pbx.example', 'http://pbx.example/?token=secret'] as $unsafeBase) {
    try {
        (new PhoneServiceService(new PhoneServiceUcmStub($unsafeBase)))->render('cisco_legacy', 'aabbccddeeff', []);
        throw new RuntimeException("Unsafe phone-services base URL was accepted: $unsafeBase");
    } catch (\InvalidArgumentException $e) {
    }
}

final class PhoneServiceSqliteUcmStub
{
    public function __construct(private \PDO $db) {}
    public function localSiteId(): int { return 1; }
    public function phoneServicesToken(bool $create = true): string { return '0123456789abcdef0123456789abcdef'; }
    public function getSite(int $siteId): array { return ['name'=>'Test PBX','pbx_addr'=>'10.0.0.10']; }
    public function getDb(): \PDO { return $this->db; }
    public function getSetting(string $key, ?int $siteId = null, mixed $default = null): mixed
    {
        return $key === 'directory_token' ? '' : $default;
    }
}

$db = new \PDO('sqlite::memory:');
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ucmp_phone_services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        service_key TEXT NOT NULL,
        name TEXT NOT NULL,
        service_type TEXT NOT NULL,
        enabled INTEGER NOT NULL,
        locked INTEGER NOT NULL,
        menu_order INTEGER NOT NULL,
        settings_json TEXT,
        updated INTEGER NOT NULL,
        UNIQUE(site_id,service_key)
    )'
);
$db->exec('CREATE TABLE ucmp_models (id INTEGER PRIMARY KEY, vendor TEXT, model TEXT, family TEXT, role TEXT)');
$db->exec('CREATE TABLE ucmp_phones (site_id INTEGER, mac TEXT, model_id INTEGER)');
$db->exec(
    "INSERT INTO ucmp_models (id,vendor,model,family,role) VALUES
     (1,'Yealink','T46S','yealink','desk'),
     (2,'Yealink','T48S','yealink','desk'),
     (3,'Cisco','8845','cisco_xml','desk'),
     (4,'Grandstream','GXP2130','grandstream','desk'),
     (5,'Grandstream','DP750','grandstream','dect_base')"
);
$db->exec(
    "INSERT INTO ucmp_phones (site_id,mac,model_id) VALUES
     (1,'aabbccddeeff',1),
     (1,'ffeeddccbbaa',2)"
);
$db->exec(
    "INSERT INTO ucmp_phone_services
     (site_id,service_key,name,service_type,enabled,locked,menu_order,settings_json,updated)
     VALUES (2,'remote-only','Remote only','text',1,0,77,'{\"body\":\"remote\"}',1)"
);
$managed = new PhoneServiceService(new PhoneServiceSqliteUcmStub($db));
$saved = $managed->handle([
    'op'=>'save',
    'name'=>'Yealink Notice',
    'service_key'=>'yealink-notice',
    'service_type'=>'text',
    'enabled'=>1,
    'menu_order'=>5,
    'settings'=>[
        'body'=>'Maintenance window',
        'formats'=>['yealink'],
        'target_models'=>['YEALINK|T46S'],
    ],
]);
$notice = array_values(array_filter($saved['services'], static fn(array $row): bool => $row['service_key'] === 'yealink-notice'))[0] ?? [];
$expect(($notice['settings']['formats'] ?? []) === ['yealink'], 'Saved service must retain its validated phone-family applicability');
$expect(($notice['settings']['target_models'] ?? []) === ['YEALINK|T46S'], 'Saved service must retain exact-model applicability');
$expect(isset($saved['preview_cache']['yealink-notice']['yealink']['xml']), 'Catalog must include a token-free cached output preview');
$expect(str_contains($saved['preview_cache']['directory']['yealink']['xml'], 'phone-services.invalid'), 'Cached linked preview must use a reserved placeholder host');
$expect(!str_contains($saved['preview_cache']['directory']['yealink']['xml'], '0123456789abcdef'), 'Cached linked preview must not persist the site bearer token');
$expect(count($saved['models']) === 4, 'Model selector must omit unsupported Grandstream DECT profiles');

$ciscoRoot = $managed->render('cisco', 'aabbccddeeff', []);
$yealinkRoot = $managed->render('yealink', 'aabbccddeeff', []);
$otherYealinkRoot = $managed->render('yealink', 'ffeeddccbbaa', []);
$expect(!str_contains($ciscoRoot['body'], 'Yealink Notice'), 'Cisco catalog must omit a Yealink-only service');
$expect(str_contains($yealinkRoot['body'], 'Yealink Notice'), 'Yealink catalog must include a matching service');
$expect(!str_contains($otherYealinkRoot['body'], 'Yealink Notice'), 'Exact-model service must fail closed on another model in the same family');
$expect(strpos($yealinkRoot['body'], 'Yealink Notice') < strpos($yealinkRoot['body'], 'Company Directory'), 'Numeric menu order must remain deterministic');

$excludedPreview = $managed->handle(['op'=>'preview','service_key'=>'yealink-notice','format'=>'cisco']);
$expect($excludedPreview['applicable'] === false, 'Preview must report when the selected renderer is outside service applicability');
$expect(str_contains($excludedPreview['xml'], 'Maintenance window'), 'An excluded service must still be previewable without publishing it');
$expect(str_contains($excludedPreview['warning'], 'excluded'), 'Excluded preview must explain why phones will not see it');
$matchingPreview = $managed->handle([
    'op'=>'preview','service_key'=>'yealink-notice','format'=>'yealink','model_key'=>'YEALINK|T46S',
]);
$otherModelPreview = $managed->handle([
    'op'=>'preview','service_key'=>'yealink-notice','format'=>'yealink','model_key'=>'YEALINK|T48S',
]);
$expect($matchingPreview['applicable'] === true, 'Preview must accept a targeted exact model');
$expect($otherModelPreview['applicable'] === false && str_contains($otherModelPreview['warning'], 'model'), 'Preview must report exact-model exclusion');

$hidden = $managed->handle([
    'op'=>'save',
    'name'=>'Draft Notice',
    'service_key'=>'draft-notice',
    'service_type'=>'text',
    'enabled'=>0,
    'menu_order'=>20,
    'settings'=>['body'=>'Draft', 'formats'=>['cisco']],
]);
$hiddenPreview = $managed->handle(['op'=>'preview','service_key'=>'draft-notice','format'=>'cisco']);
$expect($hiddenPreview['published'] === false && str_contains($hiddenPreview['xml'], 'Draft'), 'Hidden services must remain previewable without becoming public');

$beforeOrder = array_map(static fn(array $row): int => (int)$row['id'], $hidden['services']);
$requestedOrder = array_reverse($beforeOrder);
$reordered = $managed->handle(['op'=>'reorder','ids'=>$requestedOrder]);
$actualOrder = array_map(static fn(array $row): int => (int)$row['id'], $reordered['services']);
$expect($actualOrder === $requestedOrder, 'Reorder API must publish the complete submitted order deterministically');
$expect(
    array_map(static fn(array $row): int => (int)$row['menu_order'], $reordered['services']) === range(10, count($actualOrder) * 10, 10),
    'Reorder API must normalize menu positions to stable ten-point increments'
);
$expect(
    (int)$db->query("SELECT menu_order FROM ucmp_phone_services WHERE site_id=2 AND service_key='remote-only'")->fetchColumn() === 77,
    'Reorder API must not mutate another site'
);
try {
    $managed->handle(['op'=>'reorder','ids'=>array_slice($requestedOrder, 1)]);
    throw new RuntimeException('Incomplete service order was accepted');
} catch (\InvalidArgumentException $e) {
    $expect(str_contains($e->getMessage(), 'every service'), 'Incomplete reorder must return an actionable error');
}

foreach ([
    ['message'=>'Unsupported phone family','request'=>[
        'op'=>'save','name'=>'Bad family','service_key'=>'bad-family','service_type'=>'text',
        'enabled'=>1,'settings'=>['body'=>'x','formats'=>['unknown']],
    ]],
    ['message'=>'reserved','request'=>[
        'op'=>'save','name'=>'Reserved','service_key'=>'root','service_type'=>'text',
        'enabled'=>1,'settings'=>['body'=>'x','formats'=>['cisco']],
    ]],
    ['message'=>'selected phone family','request'=>[
        'op'=>'save','name'=>'Wrong model','service_key'=>'wrong-model','service_type'=>'text',
        'enabled'=>1,'settings'=>[
            'body'=>'x','formats'=>['cisco'],'target_models'=>['YEALINK|T46S'],
        ],
    ]],
    ['message'=>'too large','request'=>[
        'op'=>'save','name'=>'Huge','service_key'=>'huge','service_type'=>'text',
        'enabled'=>0,'settings'=>str_repeat('x', 65537),
    ]],
    ['message'=>'protected built-ins','request'=>[
        'op'=>'save','name'=>'Duplicate information','service_key'=>'duplicate-info','service_type'=>'info',
        'enabled'=>1,'settings'=>['formats'=>['cisco']],
    ]],
] as $invalid) {
    try {
        $managed->handle($invalid['request']);
        throw new RuntimeException('Invalid phone-service input was accepted: ' . $invalid['message']);
    } catch (\InvalidArgumentException $e) {
        $expect(str_contains($e->getMessage(), $invalid['message']), 'Validation must return a specific actionable error');
    }
}

$db->exec(
    "UPDATE ucmp_phone_services
        SET name='Directory Shortcut',service_type='text',enabled=0,locked=0,menu_order=42
      WHERE site_id=1 AND service_key='directory'"
);
$repaired = $managed->handle(['op'=>'list']);
$repairedDirectory = array_values(array_filter(
    $repaired['services'],
    static fn(array $row): bool => $row['service_key'] === 'directory'
))[0] ?? [];
$expect(
    ($repairedDirectory['service_type'] ?? '') === 'directory'
    && (int)($repairedDirectory['locked'] ?? 0) === 1,
    'Catalog bootstrap must repair the canonical purpose and lock of a protected route'
);
$expect(
    ($repairedDirectory['name'] ?? '') === 'Directory Shortcut'
    && (int)($repairedDirectory['enabled'] ?? 1) === 0
    && (int)($repairedDirectory['menu_order'] ?? 0) === 42,
    'Repairing a protected route must preserve its operator label, visibility and menu order'
);

$serviceView = (string)file_get_contents(dirname(__DIR__) . '/views/services.php');
$expect(
    !str_contains($serviceView, '<option value="directory"')
    && !str_contains($serviceView, '<option value="info"')
    && str_contains($serviceView, 'id="ucm-service-protected-purpose"'),
    'Protected purposes must render read-only instead of appearing as duplicate selectable options'
);
$expect(
    str_contains($serviceView, 'separate from the phone’s native Directory button')
    && str_contains($serviceView, 'A voicemail browser is not implemented'),
    'Phone Services UI must explain native phonebook independence and the supported app boundary'
);

echo "Phone service applicability, preview, order, URL and offline-directory assertions passed\n";

$unicodeLabel='A'.str_repeat('آ',20);
$unicode=$managed->handle(['op'=>'save','name'=>$unicodeLabel,'service_key'=>'unicode-label','service_type'=>'text',
    'enabled'=>1,'settings'=>['body'=>'Fixture','formats'=>['grandstream']]]);
$rendered=$managed->render('grandstream','aabbccddeeff',[]);
$xml=new DOMDocument();
$expect(@$xml->loadXML($rendered['body']),'A multibyte label must not produce invalid Grandstream service XML');
$found=false;
foreach ($xml->getElementsByTagName('SoftKey') as $key) {
    if (str_contains($key->getAttribute('commandArgs'),'/service/unicode-label')) {
        $found=true;$expect($key->getAttribute('label')==='A'.str_repeat('آ',15),'Grandstream labels must truncate complete characters');
    }
}
$expect($found,'Unicode service fixture was not rendered');
