<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/WebPhonebook.php';
require_once __DIR__ . '/WebPhonebookFixture.php';

use FreePBX\modules\Ucmprovisioner\WebPhonebook;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$reject = static function (callable $action, string $message) use ($expect): void {
    try { $action(); } catch (Throwable) { return; }
    $expect(false, $message);
};
$root = sys_get_temp_dir() . '/ucmp-webbook-ownership-' . bin2hex(random_bytes(8));
mkdir($root . '/directory', 0700, true);
file_put_contents($root . '/directory/directory.json', json_encode(webPhonebookFixture(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$service = new WebPhonebook($root);
$base = [
    'enabled'=>true,
    'networks'=>['192.0.2.128/25'],
    'books'=>['company'],
    'fields'=>WebPhonebook::DEFAULT_FIELDS,
];
$ownerA = [
    'job_key'=>'cfg:1:7', 'source_site_key'=>'site-a', 'source_site_name'=>'SITE-A',
    'source_revision'=>str_repeat('a', 64), 'hard_lock'=>false,
];
$ownerB = [
    'job_key'=>'cfg:2:8', 'source_site_key'=>'site-b', 'source_site_name'=>'SITE-B',
    'source_revision'=>str_repeat('b', 64), 'hard_lock'=>true,
];

try {
    $expect($service->management() === [], 'New policy must have no management owner');
    file_put_contents($root . '/web-phonebook.json', json_encode(['management'=>['hard_lock'=>true]], JSON_THROW_ON_ERROR));
    $reject(fn()=> $service->management(), 'Malformed management metadata was accepted');
    unlink($root . '/web-phonebook.json');
    $service->savePolicy($base, $service->policyRevision());
    $before = $service->policy();
    $unmanagedRevision = $service->policyRevision();

    $applied = $service->applyManagedPolicy($base, $ownerA);
    $expect($applied['ok'] && $applied['status'] === 'active' && $applied['changed'], 'First managed policy apply failed');
    $expect($service->policyRevision() !== $unmanagedRevision, 'Ownership metadata did not participate in policy revision');
    $reject(fn()=> $service->savePolicy($base, $unmanagedRevision), 'Stale local save ignored ownership metadata revision');
    $expect(!empty($service->management()) && !$service->management()['hard_lock'], 'Soft management metadata was not published');
    $expect(!array_key_exists('management', $service->policy()), 'Public policy leaked management metadata');

    $local = array_replace($base, ['policy_label'=>'Local edit']);
    $service->savePolicy($local, $service->policyRevision());
    $expect($service->management()['status'] === 'paused', 'Soft-managed local edit did not pause ownership');
    $paused = $service->applyManagedPolicy($base, $ownerA);
    $expect($paused['ok'] && $paused['status'] === 'paused' && !$paused['changed'], 'Paused soft owner was overwritten without force');
    $expect($service->policy()['policy_label'] === 'Local edit', 'Paused soft owner changed effective policy');
    $expect($service->resumeManagedPolicy($ownerA['job_key']), 'Matching soft owner did not resume');
    $expect($service->management()['status'] === 'active', 'Resume did not mark owner active');

    $forced = $service->applyManagedPolicy($base, $ownerA, true);
    $expect($forced['ok'] && $forced['status'] === 'active' && $forced['changed'], 'Forced same-owner apply did not reconcile');
    $expect($service->policy() === WebPhonebook::normalizePolicy($base), 'Forced same-owner apply produced wrong policy');

    $hard = $service->applyManagedPolicy($base, $ownerB, true);
    $expect(!$hard['ok'] && $hard['status'] === 'owner_conflict', 'Force stole another owner');
    $expect($service->management()['source_site_key'] === 'site-a', 'Cross-source force changed ownership');
    $expect($service->policy() === $before, 'Cross-source force changed effective policy');

    $hardA = array_replace($ownerA, ['hard_lock'=>true, 'source_revision'=>str_repeat('c', 64)]);
    $hardApplied = $service->applyManagedPolicy($base, $hardA);
    $expect($hardApplied['ok'] && $service->management()['hard_lock'], 'Same-owner hard lock was not established');
    $reject(fn()=> $service->savePolicy($local, $service->policyRevision()), 'Local save bypassed hard lock');
    $sameHard = $service->applyManagedPolicy($local, $hardA);
    $expect($sameHard['ok'] && $sameHard['changed'] && $service->policy()['policy_label'] === 'Local edit', 'Same-owner hard lock did not enforce overwrite');

    $rawBeforeMissing = file_get_contents($root . '/web-phonebook.json');
    $missing = $service->applyManagedPolicy(array_replace($base, ['books'=>['not-published']]), $hardA, true);
    $expect($missing['ok'] && $missing['status'] === 'missing_dependency' && $missing['missing'] === ['not-published'], 'Missing web phonebook dependency was not reported');
    $expect(file_get_contents($root . '/web-phonebook.json') === $rawBeforeMissing, 'Missing dependency changed policy or ownership metadata');

    $wrongRelease = $service->releaseManagedPolicy($ownerB['job_key']);
    $expect($wrongRelease['ok'] && $wrongRelease['status'] === 'owner_conflict', 'Mismatched release was not contained');
    $expect($service->management()['source_site_key'] === 'site-a', 'Mismatched release changed owner');
    $release = $service->releaseManagedPolicy($ownerA['job_key']);
    $expect($release['ok'] && $release['changed'] && $service->management() === [], 'Matching release did not remove ownership');
    $expect($service->policy()['policy_label'] === 'Local edit', 'Release changed effective policy');

    $acquired = $service->applyManagedPolicy($base, $ownerB);
    $expect($acquired['ok'] && $service->management()['source_site_key'] === 'site-b', 'Released policy could not acquire new owner');
    echo "Web phonebook ownership, hard-lock, pause, dependency and release checks passed\n";
} finally {
    foreach (['web-phonebook.json','web-phonebook.lock','directory/directory.json'] as $file) {
        if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); }
    }
    rmdir($root . '/directory');
    rmdir($root);
}
