<?php
/** One-minute tick; the saved site schedule decides whether a rebuild is due. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once '/etc/freepbx.conf';
try {
    $result = \FreePBX::Ucmprovisioner()->runScheduledPhoneRebuild();
    $healthy = !empty($result['ok']) && empty($result['reporting_error']);
    $stream = $healthy ? STDOUT : STDERR;
    fwrite($stream, (string)($result['message'] ?? 'Phone rebuild scheduler finished') . PHP_EOL);
    if (!empty($result['reporting_error'])) { fwrite(STDERR, (string)$result['reporting_error'] . PHP_EOL); }
    exit($healthy ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
