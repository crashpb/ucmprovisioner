<?php
/** One-minute tick; the saved schedule, overlap lock and config-write guard decide whether it runs. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once '/etc/freepbx.conf';
try {
    $result = \FreePBX::Ucmprovisioner()->runScheduledPublishMesh();
    $healthy = !empty($result['ok']) && empty($result['reporting_error']);
    $stream = $healthy ? STDOUT : STDERR;
    fwrite($stream, (string)($result['message'] ?? 'BLF mesh scheduler finished') . PHP_EOL);
    if (!empty($result['reporting_error'])) { fwrite(STDERR, (string)$result['reporting_error'] . PHP_EOL); }
    exit($healthy ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
