<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/PhoneAssignment.php';
use FreePBX\modules\Ucmprovisioner\PhoneAssignment;
$expect = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};

$target = ['id'=>35, 'extension'=>'4024', 'mac'=>'DCEB94CF48AE', 'prev_extension'=>null];
$other = ['id'=>100, 'extension'=>'4031', 'mac'=>'001200B486A9', 'prev_extension'=>null];

$swap = PhoneAssignment::plan($target, $other, '4031', 'swap');
$expect($swap['target_extension'] === '4031', 'Swap must assign the requested extension to the selected physical phone');
$expect($swap['target_prev_extension'] === '4024', 'Swap must retain the target phone previous extension for restart signaling');
$expect($swap['other_extension'] === '4024', 'Swap must give the displaced phone the target previous extension');
$expect($swap['other_prev_extension'] === '4031', 'Swap must retain the displaced phone previous extension');
$expect($swap['target_status'] === 'bound' && $swap['other_status'] === 'bound', 'Both real phones remain bound after a swap');

$move = PhoneAssignment::plan($target, $other, '4031', 'move');
$expect($move['target_extension'] === '4031', 'Move must assign the requested extension');
$expect($move['other_extension'] === null && $move['other_status'] === 'unassigned', 'Move must unassign the displaced real phone');

$blank = PhoneAssignment::plan($target, null, '', 'swap');
$expect($blank['target_extension'] === null && $blank['target_status'] === 'unassigned', 'Blank selection must unassign a real phone');

$adFallback = PhoneAssignment::fallbackLabel([
    'id'=>'4019',
    'description'=>'FreePBX Name',
    'identity'=>['label'=>'j.smith','display_name'=>'Jane Smith'],
    'final_overrides'=>['line_label'=>'Executive Desk'],
]);
$expect(
    $adFallback === ['label'=>'j.smith','source'=>'accepted AD short label'],
    'Assignment fallback must prefer the accepted AD short label without copying a final audited override'
);
$displayFallback = PhoneAssignment::fallbackLabel([
    'id'=>'4019','description'=>'FreePBX Name','identity'=>['label'=>'','display_name'=>'Jane Smith'],
]);
$expect(
    $displayFallback['label'] === 'Jane Smith' && $displayFallback['source'] === 'accepted AD display name',
    'Accepted AD display name must be the fallback when its short label is blank'
);
$freePbxFallback = PhoneAssignment::fallbackLabel(['id'=>'4019','description'=>'FreePBX Name']);
$expect(
    $freePbxFallback['label'] === 'FreePBX Name' && $freePbxFallback['source'] === 'FreePBX display name',
    'FreePBX description must remain the fallback when no accepted AD identity exists'
);

$view = (string)file_get_contents(dirname(__DIR__) . '/views/phones.php');
$backend = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$expect(
    str_contains($view, 'name="sync_assignment_label"')
    && str_contains($view, 'Reassigning here never edits or deletes those audited overrides'),
    'Phone editor must expose the label-follow choice and explain audited override ownership'
);
$expect(
    str_contains($backend, '$syncAssignmentLabel')
    && str_contains($backend, "'label_sync'=>['target'=>\$targetLabelSynced,'other'=>\$otherLabelSynced]"),
    'Assignment backend must authoritatively synchronize both target and swapped-phone fallback labels'
);

echo "Phone assignment plans passed\n";
