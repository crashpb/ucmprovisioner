<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$phpFiles = [];
foreach ([
    $root . '/*.php',
    $root . '/lib/*.php',
    $root . '/views/*.php',
    $root . '/tools/*.php',
    __DIR__ . '/*.php',
] as $pattern) {
    foreach (glob($pattern) ?: [] as $file) {
        $phpFiles[$file] = true;
    }
}
foreach ([
    $root . '/resources/ucmp-fail2ban',
    $root . '/resources/ucmp-patch-manager',
] as $file) {
    if (is_file($file)) {
        $phpFiles[$file] = true;
    }
}
ksort($phpFiles, SORT_NATURAL | SORT_FLAG_CASE);

$tests = glob(__DIR__ . '/*Test.php') ?: [];
natcasesort($tests);

$failures = [];
$run = static function (array $command, string $cwd): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHP test process');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
};

echo "Linting " . count($phpFiles) . " PHP files\n";
foreach (array_keys($phpFiles) as $file) {
    [$status, $stdout, $stderr] = $run([PHP_BINARY, '-l', $file], $root);
    if ($status !== 0) {
        $failures[] = 'lint: ' . substr($file, strlen($root) + 1);
        fwrite(STDERR, $stdout . $stderr);
    }
}

echo "Running " . count($tests) . " assertion scripts\n";
foreach ($tests as $test) {
    $name = basename($test);
    [$status, $stdout, $stderr] = $run([PHP_BINARY, $test], $root);
    echo $status === 0 ? "[PASS] $name\n" : "[FAIL] $name\n";
    if ($stdout !== '') {
        echo $stdout;
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($status !== 0) {
        $failures[] = 'test: ' . $name;
    }
}

if ($failures) {
    fwrite(STDERR, "\nFailures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "\nAll lint and assertion checks passed.\n";
