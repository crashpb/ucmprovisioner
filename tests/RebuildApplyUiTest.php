<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$javascript = (string)file_get_contents($root . '/assets/js/ucmprovisioner.js');
$provisioner = (string)file_get_contents($root . '/lib/Provisioner.php');
$tftpStore = (string)file_get_contents($root . '/lib/TftpStore.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$renderStart = strpos($javascript, 'function renderApplyResult');
$renderEnd = strpos($javascript, 'function doRebuild', $renderStart === false ? 0 : $renderStart);
$render = ($renderStart !== false && $renderEnd !== false)
    ? substr($javascript, $renderStart, $renderEnd - $renderStart)
    : '';
$expect(!str_contains($render, '(r2.push || []).filter'), 'Apply result must not treat the associative push receipt as an array');
$expect(str_contains($render, 'Array.isArray(push)') && str_contains($render, 'Array.isArray(push.files)'), 'Apply result must normalize both legacy arrays and the current push.files receipt');
$expect(str_contains($render, 'var failedPush = pushFiles.filter') && !str_contains($render, 'r2.push.filter'), 'Only the normalized push file list may be filtered');
$expect(str_contains($render, 'Array.isArray(r2.restarts)') && str_contains($render, 'Array.isArray(r2.notices)'), 'Optional apply result lists must be shape-safe');

$doneStart = strpos($javascript, '.done(function (resp2)');
$doneEnd = strpos($javascript, '.fail(function ()', $doneStart === false ? 0 : $doneStart);
$done = ($doneStart !== false && $doneEnd !== false)
    ? substr($javascript, $doneStart, $doneEnd - $doneStart)
    : '';
$renderPosition = strpos($done, 'renderApplyResult(r2, ok)');
$hidePosition = strpos($done, '$applyBtn.hide()');
$closePosition = strpos($done, "\$cancelBtn.text('Close').show()");
$expect($renderPosition !== false, 'Apply completion callback must render the server result');
$expect($hidePosition !== false && $hidePosition < $renderPosition, 'Apply must leave the Applying state before optional detail rendering');
$expect($closePosition !== false && $closePosition < $renderPosition, 'Close must become available before optional detail rendering');
$expect(str_contains($done, 'try {') && str_contains($done, 'catch (renderError)') && str_contains($done, 'Apply response received.'), 'A result rendering fault must have a visible terminal fallback');
$expect(str_contains($done, "if (ok) { \$(document).trigger('ucm:rebuild-applied'); }"), 'A successful backend apply must refresh Phones even if detail rendering fails');

$expect(str_contains($provisioner, "'push' => \$commit"), 'Regression fixture must track Provisioner associative push receipt');
$expect(str_contains($tftpStore, "'files' => \$receipt['files']"), 'Regression fixture must track TFTP receipt files');

echo "Rebuild apply UI assertions passed\n";
