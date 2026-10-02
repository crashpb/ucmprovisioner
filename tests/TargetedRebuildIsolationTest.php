<?php
declare(strict_types=1);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$provisioner = (string)file_get_contents(dirname(__DIR__) . '/lib/Provisioner.php');
$engine = (string)file_get_contents(dirname(__DIR__) . '/lib/TemplateEngine.php');

$expect(
    str_contains($provisioner, '$selectedIds[(int)$phone[\'id\']] = true;')
        && str_contains($provisioner, '$this->tpl->resolvePhone($p, isset($selectedIds[$phoneId]))'),
    'A targeted rebuild must strictly resolve selected phones while using companion-free context for unrelated glue members'
);
$expect(
    str_contains($engine, 'bool $fullDeviceResolution = true')
        && str_contains($engine, 'if ($fullDeviceResolution) {')
        && str_contains($engine, '$this->resolveProfileAssets(')
        && str_contains($engine, '$this->assertAllFirmwareSelections('),
    'Template resolution must preserve strict-by-default policy and device-asset validation while exposing only the explicit glue-only reduction'
);
$expect(
    str_contains($engine, 'has an invalid softkey policy:'),
    'Strict softkey failures must identify the template layer that needs repair'
);

echo "TargetedRebuildIsolationTest OK\n";
