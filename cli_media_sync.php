<?php
/** One-minute tick; saved jobs enforce their own cadence, lock and retry policy. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once '/etc/freepbx.conf';
try {
    $module = \FreePBX::Ucmprovisioner();
    $results = [];
    foreach ([
        'media'=>static fn() => $module->runScheduledMediaReplication(),
        'configuration'=>static fn() => $module->runScheduledConfigurationSync(),
        'firmware'=>static fn() => $module->runScheduledFirmwareReplication(),
    ] as $name=>$run) {
        try { $results[$name] = $run(); }
        catch (\Throwable $error) { $results[$name] = ['ok'=>false,'status'=>'error','message'=>$error->getMessage()]; }
    }
    $ok = !array_filter($results, static fn(array $result): bool => empty($result['ok']));
    $summary = [];
    foreach ($results as $name=>$result) {
        $state = !empty($result['ok']) ? (string)($result['status'] ?? 'success') : 'error';
        $summary[] = $name . '=' . $state . ': ' . (string)($result['message'] ?? ($name . ' scheduler finished'));
    }
    fwrite($ok ? STDOUT : STDERR, implode('; ', $summary) . PHP_EOL);
    exit($ok ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
