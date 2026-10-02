<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$page = (string)file_get_contents($root . '/page.ucmprovisioner.php');
$download = (string)file_get_contents($root . '/tftp_download.php');
$tftpView = (string)file_get_contents($root . '/views/tftp.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($module, 'private function assertAjaxRequest(): void')
    && str_contains($module, '$this->assertAjaxRequest();')
    && str_contains($module, "REQUEST_METHOD")
    && str_contains($module, "\$_POST['ucmp_csrf']")
    && str_contains($module, 'hash_equals($expected, $supplied)'),
    'Every BMO AJAX command must cross a POST and session-token boundary'
);

$tokenMethodStart = strpos($module, 'public function pageCsrfToken(): string');
$tokenMethodEnd = strpos($module, 'private function assertPageMutationRequest(): void', $tokenMethodStart ?: 0);
$tokenMethod = $tokenMethodStart !== false && $tokenMethodEnd !== false
    ? substr($module, $tokenMethodStart, $tokenMethodEnd - $tokenMethodStart)
    : '';
$readStoredToken = strpos($tokenMethod, '$_SESSION[$key]');
$returnStoredToken = strpos($tokenMethod, 'return $token;');
$requireWritableSession = strpos($tokenMethod, 'session_status()');
$expect(
    $readStoredToken !== false
    && $returnStoredToken !== false
    && $requireWritableSession !== false
    && $readStoredToken < $requireWritableSession
    && $returnStoredToken < $requireWritableSession,
    'A valid page token must remain usable after FreePBX releases the session lock for AJAX'
);

$expect(
    str_contains($page, '$.ajaxPrefilter')
    && str_contains($page, "moduleName !== 'ucmprovisioner'")
    && str_contains($page, "options.type = options.method = 'POST'")
    && str_contains($page, "options.processData = false")
    && str_contains($page, "options.contentType = false")
    && str_contains($page, "options.data.append('ucmp_csrf', token)")
    && str_contains($page, "ucmp_csrf=' + encodeURIComponent(token)"),
    'The module page must force its AJAX contract to POST and attach the session token to object, string, and FormData payloads'
);
$scopeGuard = strpos($page, "moduleName !== 'ucmprovisioner'");
$tokenAppend = $scopeGuard === false
    ? false
    : strpos($page, "options.data.append('ucmp_csrf', token)", $scopeGuard);
$expect(
    $scopeGuard !== false && $tokenAppend !== false && $scopeGuard < $tokenAppend,
    'The module token prefilter must leave every other FreePBX AJAX request untouched'
);
$expect(
    strpos($page, '$.ajaxPrefilter') < strpos($page, 'echo $viewHtml'),
    'The AJAX token prefilter must be installed before any view script can issue its initial request'
);

$clientFiles = array_merge(
    glob($root . '/views/*.php') ?: [],
    glob($root . '/assets/js/*.js') ?: []
);
foreach ($clientFiles as $clientFile) {
    $client = (string)file_get_contents($clientFile);
    $expect(
        !preg_match('/\$\.(?:get|getJSON)\s*\(\s*[\'"]ajax\.php[\'"]/', $client),
        basename($clientFile) . ' must not issue a GET to the POST-only module AJAX endpoint'
    );
}

$expect(
    str_contains($download, '$ucm->assertTftpDownloadRequest();')
    && str_contains($download, "\$_POST['site_id']")
    && str_contains($download, "\$_POST['paths']")
    && str_contains($tftpView, 'name="ucmp_csrf"')
    && str_contains($tftpView, 'method="post"')
    && str_contains($tftpView, 'ucm-tftp-download-selected'),
    'Single and multi TFTP downloads must use the authenticated POST/session-token boundary'
);

echo "AJAX CSRF boundary assertions passed\n";
