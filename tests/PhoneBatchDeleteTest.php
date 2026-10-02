<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$module = file_get_contents($root . '/Ucmprovisioner.class.php');
$tftp = file_get_contents($root . '/lib/TftpStore.php');
$page = file_get_contents($root . '/page.ucmprovisioner.php');
$phones = file_get_contents($root . '/views/phones.php');
$js = file_get_contents($root . '/assets/js/ucmprovisioner.js');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expect(str_contains($module, "\$action = (string)(\$_POST['action'] ?? '')"), 'Page mutations must be sourced only from POST');
$expect(strpos($module, '$this->assertPageMutationRequest();') > strpos($module, 'try {'), 'POST assertion must be caught instead of leaking a Whoops page');
$expect(str_contains($page, "new URL(url, window.location.href)"), 'Legacy page actions must be normalized before POST');
$expect(str_contains($page, "name: name, value: value"), 'Mutation query values must become POST fields');
$expect(!str_contains($phones, "window.location.href.split('#')[0] + ' #ucm-phones"), 'Inventory refresh must never reuse a mutation URL');
$expect(str_contains($phones, 'ucm-delete-selected'), 'Phones toolbar must expose Delete selected');
$expect(str_contains($js, "command: 'deletephones'"), 'Delete selected must use authenticated AJAX');
$expect(str_contains($js, 'FreePBX extensions remain available as unprovisioned'), 'Delete confirmation must state its boundary');
$expect(str_contains($module, "case 'deletephones':"), 'AJAX handler must route delete batches');
$expect(str_contains($module, 'public function deletePhonesLocal(array $phoneIds, ?array $expectedBindings = null): array'), 'Server must own batch validation and deletion');
$expect(str_contains($module, 'does not belong to the selected site'), 'Batch deletion must reject cross-site IDs before deleting');
$expect(str_contains($module, 'A delete batch is limited to 200 phones'), 'Batch deletion must be bounded');
$deleteStart = strpos($module, 'public function deletePhoneLocal(int $id, ?string $expectedBinding = null): array');
$deleteEnd = strpos($module, 'public function deletePhonesLocal(array $phoneIds, ?array $expectedBindings = null): array', $deleteStart ?: 0);
$deleteMethod = $deleteStart !== false && $deleteEnd !== false
    ? substr($module, $deleteStart, $deleteEnd - $deleteStart)
    : '';
$expect(
    str_contains($tftp, 'public function deletePhoneConfig(int $siteId, string $filename): array')
    && str_contains($deleteMethod, 'configFilenamesForPhone(')
    && str_contains($deleteMethod, '$store->deletePhoneConfig(')
    && str_contains($deleteMethod, 'the phone binding was preserved')
    && str_contains($deleteMethod, 'ucmp_phone_mac_suppressions')
    && str_contains($deleteMethod, 'DELETE FROM ucmp_phone_builds')
    && str_contains($deleteMethod, 'UPDATE ucmp_phones')
    && str_contains($deleteMethod, "mac=NULL,vendor=NULL,model_id=NULL,family=NULL,ip=NULL")
    && str_contains($deleteMethod, "status='discovered'")
    && !str_contains($deleteMethod, 'DELETE FROM ucmp_phones WHERE id=?')
    && strpos($deleteMethod, '$store->deletePhoneConfig(') < strpos($deleteMethod, 'UPDATE ucmp_phones'),
    'Phone deletion must clean every MAC-derived config, persist the physical-MAC tombstone, and leave an extension-only row'
);
$filenameStart = strpos($module, 'private function configFilenamesForPhone(');
$filenameEnd = strpos($module, '/* Data: settings', $filenameStart ?: 0);
$filenameMethod = $filenameStart !== false && $filenameEnd !== false
    ? substr($module, $filenameStart, $filenameEnd - $filenameStart)
    : '';
foreach (['SEP{$mac}.cnf.xml', 'SIP{$mac}.cnf', "strtolower(\$mac) . '.cfg'", "'cfg' . strtolower(\$mac) . '.xml'"] as $candidate) {
    $expect(str_contains($filenameMethod, $candidate), "Delete cleanup is missing deterministic candidate $candidate");
}
$expect(!str_contains($deleteMethod, 'model/family is unknown'), 'A model-less phone with a valid MAC must remain deletable');

echo "PhoneBatchDeleteTest: OK\n";
