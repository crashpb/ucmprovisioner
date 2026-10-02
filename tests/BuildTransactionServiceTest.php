<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/BuildPlanner.php';
require_once __DIR__ . '/../lib/BuildTransactionService.php';

use FreePBX\modules\Ucmprovisioner\BuildPlanner;
use FreePBX\modules\Ucmprovisioner\BuildTransactionService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$planner = new BuildPlanner();
$manifest = $planner->plan(
    1,
    [[
        'phone' => ['id' => 9, 'extension' => '309'],
        'model' => ['id' => 4, 'vendor' => 'Yealink', 'model' => 'T54W', 'family' => 'yealink'],
        'settings' => [],
    ]],
    [['filename' => '001122334455.cfg', 'content' => 'account.1.enable = 1', 'phone_id' => 9]],
    ['requires_patch' => false],
    ['previous_generation_id' => '20260726T010203Z-0123456789abcdef']
);

$ledger = new BuildTransactionService($db);
$ledger->begin($manifest, 'admin@example.test');
$row = $ledger->get($manifest['transaction_id']);
$expect(($row['state'] ?? '') === 'planned', 'A new transaction must begin in planned state');
$expect(($row['manifest']['plan_hash'] ?? '') === $manifest['plan_hash'], 'The exact manifest must be retained');
$expect(($row['actor'] ?? '') === 'admin@example.test', 'The sanitized actor must be retained');

$ledger->transition($manifest['transaction_id'], 'staged', ['files' => 1]);
$ledger->transition($manifest['transaction_id'], 'staged', ['files' => 1]);
$row = $ledger->get($manifest['transaction_id']);
$expect(($row['state'] ?? '') === 'staged', 'Idempotent state updates must not be mistaken for unknown transactions');
$expect(($row['details']['files'] ?? 0) === 1, 'Transition details must round-trip');

$ledger->markPhonesApplied($manifest);
$phone = $db->query('SELECT * FROM ucmp_phone_builds WHERE phone_id=9')->fetch(PDO::FETCH_ASSOC);
$expect(is_array($phone) && $phone['desired_hash'] === $phone['applied_hash'], 'Commit must reconcile desired and applied hashes');
$expect($phone['generation_id'] === $manifest['transaction_id'], 'Committed phone state must retain its generation id');

$ledger->transition($manifest['transaction_id'], 'committed');
$row = $ledger->get($manifest['transaction_id']);
$expect(($row['state'] ?? '') === 'committed' && (int)($row['finished'] ?? 0) > 0, 'A terminal transaction must be timestamped');

$invalidState = false;
try {
    $ledger->transition($manifest['transaction_id'], 'invented');
} catch (InvalidArgumentException $exception) {
    $invalidState = true;
}
$expect($invalidState, 'Unknown transaction states must fail closed');

echo "Build transaction ledger tests passed\n";
