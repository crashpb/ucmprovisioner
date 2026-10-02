<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ManagedAssetScanJob.php';

use FreePBX\modules\Ucmprovisioner\ManagedAssetScanJob;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = 100;
$jobs = new ManagedAssetScanJob($db, static function () use (&$now): int { return $now; });
$first = $jobs->resumeOrStart(1);
$expect(
    preg_match('/^[a-f0-9]{32}$/', $first['token']) === 1
    && count($first['state']['queue']) === 1,
    'A new managed asset scan must start with one durable root-directory task'
);

$state = $first['state'];
$state['queue'] = [['type'=>'file','path'=>'Classic.raw','name'=>'Classic.raw','size'=>4]];
$state['directories'] = 1;
$now = 110;
$jobs->save(1, $first['token'], $state);
$resumed = $jobs->resumeOrStart(1, $first['token']);
$expect(
    $resumed['started'] === 100
    && $resumed['state']['queue'][0]['path'] === 'Classic.raw',
    'A later POST must resume the exact cursor without changing the stale-retirement cutoff'
);

$wrongTokenRejected = false;
try {
    $jobs->resumeOrStart(1, str_repeat('f', 32));
} catch (RuntimeException $e) {
    $wrongTokenRejected = str_contains($e->getMessage(), 'already active');
}
$expect($wrongTokenRejected, 'A second browser must not replace an active site scan cursor');

$now = 120;
$jobs->save(1, $first['token'], $state, 'Cannot stream deleted wallpaper');
$erroredContinuation = $jobs->resumeOrStart(1, $first['token']);
$expect(
    $erroredContinuation['token'] === $first['token'],
    'An explicit token must retain resumable control of its errored cursor'
);
$erroredReplacement = $jobs->resumeOrStart(1);
$expect(
    $erroredReplacement['token'] !== $first['token']
    && $erroredReplacement['state']['queue'][0]['type'] === 'directory',
    'A user-started blank-token scan must replace an errored cursor at TFTP root'
);

$now = 1021;
$expiredContinuationRejected = false;
try {
    $jobs->resumeOrStart(1, $erroredReplacement['token']);
} catch (RuntimeException $e) {
    $expiredContinuationRejected = str_contains($e->getMessage(), 'expired');
}
$expect($expiredContinuationRejected, 'An explicit expired scan token must fail without silently changing cursors');
$expiredReplacement = $jobs->resumeOrStart(1);
$expect(
    $expiredReplacement['token'] !== $erroredReplacement['token'],
    'A blank-token scan must replace an expired idle cursor'
);

$incompleteRejected=false;
try { $jobs->finish(1, $expiredReplacement['token']); } catch (RuntimeException) { $incompleteRejected=true; }
$expect($incompleteRejected,'An unfinished cursor must not certify deletion safety');
$completed=$expiredReplacement['state'];$completed['queue']=[];
$jobs->save(1,$expiredReplacement['token'],$completed);
$jobs->finish(1, $expiredReplacement['token']);
$expect($jobs->verified(1,'locale'),'Completed scan must provide bounded fresh verification');
$replacement = $jobs->resumeOrStart(1);
$expect($replacement['token'] !== $expiredReplacement['token'], 'Completing a scan must remove its durable cursor');
$expect($jobs->reset(1), 'Restart must remove an active cursor');
$fresh = $jobs->resumeOrStart(1);
$expect($fresh['token'] !== $replacement['token'], 'Restart must create a fresh cursor');
$expect(!$jobs->reset(2), 'Restart on an idle site must be harmless');

$jobs->reset(1);
$media = $jobs->resumeOrStart(1, '', ['locale', 'background', 'ringtone', 'background']);
$expect(
    $media['state']['kinds'] === ['background', 'ringtone', 'locale']
    && array_keys($media['state']['by_kind']) === ['background', 'ringtone', 'locale'],
    'A scoped scan must persist one canonical ordered set of requested asset kinds'
);
$scopeConflictRejected = false;
try {
    $jobs->resumeOrStart(1, '', ['firmware']);
} catch (RuntimeException $e) {
    $scopeConflictRejected = str_contains($e->getMessage(), 'different scope');
}
$expect($scopeConflictRejected, 'A blank-token scan must not silently adopt another active scan scope');
$scopedResume = $jobs->resumeOrStart(1, $media['token'], ['background', 'ringtone', 'locale']);
$expect(
    $scopedResume['state']['kinds'] === ['background', 'ringtone', 'locale'],
    'A scoped continuation must retain its retirement boundary'
);

echo "Managed asset scan cursor fixtures passed\n";
