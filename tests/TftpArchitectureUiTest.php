<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/views/sites.php');
$js = (string)file_get_contents($root . '/assets/js/ucmprovisioner.js');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$bmo = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$connection = (string)file_get_contents($root . '/lib/SiteConnection.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

foreach (['value="pbx"','value="docker"','ucm-pbx-tftp-inspect','ucm-pbx-tftp-bootstrap','ucm-tftp-cutover-preview','ucm-tftp-cutover-apply'] as $control) {
    $expect(str_contains($view, $control), "Sites editor is missing $control");
}
$expect(substr_count($view, 'name="tftp_mode"') === 2, 'TFTP mode must be one two-choice radio group');
$expect(str_contains($view, 'SHA-256') && str_contains($view, 'source remains active'), 'Cutover safety boundary is not visible');
foreach (['bootstrappbxtftp','tftparchitecture','cutover_preview','cutover_apply'] as $action) {
    $expect(str_contains($js, $action), "Sites JavaScript is missing $action");
}
foreach (['ucm-tftp-mode-grid','ucm-tftp-mode-card','ucm-tftp-checks','ucm-tftp-cutover'] as $selector) {
    $expect(str_contains($css, $selector), "TFTP architecture style is missing $selector");
}
foreach (['adoptPbxTftpEnrollment','inspectPbxTftpLocal','activateTftpArchitectureLocal'] as $rpc) {
    $expect(str_contains($bmo, "'$rpc'"), "Federation allowlist is missing $rpc");
}
$expect(str_contains($bmo, "\$vals['pbx_tftp_bootstrapped_at'] = 0;"), 'Changing the PBX host must invalidate its TFTP bootstrap marker');
$expect(str_contains($connection, 'return $this->tftpSftp();'), 'Existing storage engines no longer resolve through the active TFTP backend');

echo "PBX/Docker TFTP Sites workflow contract passed\n";
