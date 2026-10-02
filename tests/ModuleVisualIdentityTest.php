<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/page.ucmprovisioner.php');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($page, '$specializedVisualTabs = [\'media\',\'phones\',\'templates\',\'firmware\']')
    && str_contains($page, '!in_array($active, $specializedVisualTabs, true)'),
    'Firmware and the other self-navigating workbenches must not receive the generic card navigator'
);
$expect(
    str_contains($page, "pageBody.classList.add('ucmp-page-body')")
    && str_contains($css, '#page_body.ucmp-page-body'),
    'The module must constrain FreePBX table layout only while its page is open'
);
$expect(
    str_contains($css, '.ucmp-module-shell .btn.btn-default')
    && str_contains($css, '.ucmp-module-shell .btn.btn-primary')
    && str_contains($css, '.ucmp-module-shell .btn.btn-danger')
    && str_contains($css, '!important'),
    'Bootstrap button variants must have one readable module-scoped cascade boundary'
);
$expect(
    str_contains($css, '.ucmp-module-shell .form-control')
    && str_contains($css, '.ucmp-module-shell .chosen-container .chosen-single')
    && str_contains($css, '.ucmp-module-shell .table')
    && str_contains($css, '.ucmp-module-shell .panel'),
    'Native, Chosen, table and panel primitives must share the module-wide visual contract'
);
$expect(
    str_contains($css, '.ucm-phonebook-toolbar')
    && str_contains($css, '.ucm-fw-upload-guidance > div')
    && str_contains($css, '.ucm-f2b-summary .well strong')
    && str_contains($css, '.ucm-phone-preview .btn.btn-link'),
    'Legacy nested widgets must follow the shared palette while the simulated phone preview stays device-colored'
);
$expect(
    str_contains($css, '.ucmp-module-shell .table-striped > tbody > tr:nth-of-type(odd) > td')
    && str_contains($css, '.ucmp-module-shell .table-hover > tbody > tr:hover > td')
    && str_contains($css, '.ucmp-module-shell pre')
    && str_contains($css, 'background:light-dark(#f3f6fa,#101620) !important'),
    'FreePBX important table and preformatted-output colors must not leak into the module shell'
);
$expect(
    !str_contains($css, 'scoped to the nine migrated management pages')
    && !str_contains($page, '$unifiedVisualTabs'),
    'The visual identity must not regress to a hand-maintained list of partially themed tabs'
);

echo "Module visual identity fixtures passed\n";
