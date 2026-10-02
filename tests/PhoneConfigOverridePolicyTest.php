<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Provisioner.php';

use FreePBX\modules\Ucmprovisioner\Provisioner;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$phones = [
    ['id'=>1,'site_id'=>1,'extension'=>'301','mac'=>'001122334401','model_id'=>1,'config_mode'=>'managed','exclude_bulk_rebuild'=>0],
    ['id'=>2,'site_id'=>1,'extension'=>'302','mac'=>'001122334402','model_id'=>1,'config_mode'=>'manual','exclude_bulk_rebuild'=>0],
    ['id'=>3,'site_id'=>1,'extension'=>'303','mac'=>'001122334403','model_id'=>1,'config_mode'=>'managed','exclude_bulk_rebuild'=>1],
    ['id'=>4,'site_id'=>1,'extension'=>'304','mac'=>'','model_id'=>1,'config_mode'=>'managed','exclude_bulk_rebuild'=>0],
];
$ucm = new class($phones) {
    public function __construct(private array $phones) {}
    public function getPhones(): array { return $this->phones; }
};

$provisioner = (new ReflectionClass(Provisioner::class))->newInstanceWithoutConstructor();
$ucmProperty = new ReflectionProperty(Provisioner::class, 'ucm');
$ucmProperty->setAccessible(true);
$ucmProperty->setValue($provisioner, $ucm);
$select = new ReflectionMethod(Provisioner::class, 'selectPhones');
$select->setAccessible(true);
$notices = new ReflectionMethod(Provisioner::class, 'rebuildPolicyNotices');
$notices->setAccessible(true);
$buildable = new ReflectionMethod(Provisioner::class, 'buildablePhones');
$buildable->setAccessible(true);

$fleet = $buildable->invoke($provisioner, 1);
$all = $select->invoke($provisioner, 'all', 1, false);
$expect(array_column($all, 'id') === [1], 'Rebuild All must skip manual and explicit bulk-exclusion rows');
$expect($select->invoke($provisioner, '2', 1, false) === [], 'A selected manual config may not be silently replaced');
$expect(array_column($select->invoke($provisioner, '2', 1, true), 'id') === [2], 'One explicit replace action must admit its manual phone');
$expect(array_column($select->invoke($provisioner, '3', 1, false), 'id') === [3], 'Bulk exclusion must not disable an individual rebuild');

$warnings = $notices->invoke($provisioner, 'all', $fleet, false);
$expect(count($warnings) === 2, 'Rebuild All must explain every skipped phone config exactly once');
$expect(
    !array_filter($warnings, static fn(string $warning): bool =>
        !str_contains($warning, 'Asterisk/BLF glue was still recomputed')
    ),
    'Skip notices must make the fleet-wide glue behavior explicit'
);

$module = (string)file_get_contents(dirname(__DIR__) . '/module.xml');
$collision = $phones[0];$collision['id']=301;$collision['extension']='999';$collision['mac']='001122334499';
$ucmProperty->setValue($provisioner, new class([...$phones,$collision]) {
    public function __construct(private array $phones) {}
    public function getPhones(): array { return $this->phones; }
});
$expect(array_column($select->invoke($provisioner, '301', 1, false), 'id') === [301],
    'A GUI phone ID must never also rebuild another phone with the same extension number');
$expect(array_column($select->invoke($provisioner, 'ext:301', 1, false), 'id') === [1],
    'Explicit extension selection must remain available without ID ambiguity');
$expect($select->invoke($provisioner, 'ext:302', 1, false) === []
    && count($notices->invoke($provisioner, 'ext:302', $fleet, false)) === 1,
    'Explicit extension selection must retain manual-file policy and notices');
$missingModel=['id'=>31,'site_id'=>1,'extension'=>'170','mac'=>'001122334417','model_id'=>null];
$ucmProperty->setValue($provisioner,new class($missingModel) {
    public function __construct(private array $phone) {}
    public function getPhones():array {return [$this->phone];}
    public function localSiteId():int {return 1;}
});
$expect(Provisioner::missingBuildFields($missingModel)===['phone model'],'Inventory must identify the missing model');
foreach (['31','ext:170','all'] as $selection) {
    $result=$provisioner->rebuild($selection,false,1);
    $expect(!$result['ok'] && str_contains($result['message'],'extension 170') && str_contains($result['message'],'assign phone model'),'GUI IDs and extension selections must explain the missing model');
}
$class = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
foreach (['config_mode','config_override_sha256','config_overridden_at','exclude_bulk_rebuild'] as $field) {
    $expect(str_contains($module, 'name="' . $field . '"'), "module.xml is missing $field");
    $expect(str_contains($class, "\$add('$field'"), "Runtime upgrade is missing $field");
}

echo "Phone config override policy tests passed\n";
