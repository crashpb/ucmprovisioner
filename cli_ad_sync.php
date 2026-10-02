<?php
/** One-minute runner; each enabled source enforces its own schedule, lock and backoff. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once '/etc/freepbx.conf';
try {
    $result = \FreePBX::Ucmprovisioner()->syncDirectory(false);
    foreach ((array)($result['sources'] ?? []) as $source) {
        $line = sprintf(
            '%s [%s] %s',
            (string)($source['source_key'] ?? 'unknown-source'),
            (string)($source['status'] ?? 'unknown'),
            (string)($source['message'] ?? '')
        );
        fwrite(empty($source['ok']) && empty($source['skipped']) ? STDERR : STDOUT, $line . PHP_EOL);
    }
    echo ($result['message'] ?? 'Directory run complete') . PHP_EOL;
    exit(empty($result['ok']) ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
