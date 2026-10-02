<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Fail2banHelper.php';

use FreePBX\modules\Ucmprovisioner\Fail2banHelper;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$helper = new Fail2banHelper();
$invoke = static function (string $method, array $args) use ($helper) {
    $reflection = new ReflectionMethod($helper, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($helper, $args);
};

$expect($invoke('jailName', ['asterisk-iptables']) === 'asterisk-iptables', 'Valid jail name was rejected');
$expect($invoke('ip', ['2001:db8::1']) === '2001:db8::1', 'Valid IPv6 address was rejected');
foreach ([['jailName',["x\naction"]],['jailName',['../sshd']],['ip',['example.com']],['ip',['10.0.0.0/8']]] as [$method,$args]) {
    try { $invoke($method,$args); throw new RuntimeException("Unsafe $method value was accepted"); }
    catch (InvalidArgumentException $e) {}
}

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Fail2banHelper.php');
$expect(str_contains($source, "'unban' =>") && str_contains($source, "'test_filter' =>") && str_contains($source, 'Unknown fields are not accepted'), 'Required restrictive operations/schema are missing');
foreach (['save_jail','save_defaults','unban_all',"'ban' =>", "CLIENT, 'reload'", 'atomicWrite'] as $forbidden) {
    $expect(!str_contains($source, $forbidden), "Privileged helper still contains forbidden capability: $forbidden");
}

$lockPath = tempnam(sys_get_temp_dir(), 'ucmp-fail2ban-lock-');
$commands = [];
$bounded = new Fail2banHelper(static function (array $command) use (&$commands): array {
    $commands[] = $command;
    if ($command[1] === 'set') { return ['code'=>0, 'stdout'=>'1', 'stderr'=>'']; }
    throw new RuntimeException('Fixture jail status unavailable');
}, $lockPath);
$unban = new ReflectionMethod($bounded, 'lockedUnban');
try {
    $held = fopen($lockPath, 'c');flock($held, LOCK_EX);
    try { $unban->invoke($bounded, 'sshd', '192.0.2.1');throw new LogicException('Busy unban was not excluded'); }
    catch (RuntimeException) {}
    flock($held, LOCK_UN);fclose($held);
    $expect($commands === [], 'A busy operation must not dispatch an unban');
    $result = $unban->invoke($bounded, 'sshd', '192.0.2.1');
    $expect($result['ok'] && isset($result['reporting_error']) && count($commands) === 2,
        'A failed detail refresh must preserve the one completed unban');
} finally { unlink($lockPath); }
echo "Fail2ban restrictive security fixtures passed\n";
