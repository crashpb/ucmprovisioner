<?php
require_once __DIR__ . '/../lib/RecurringSchedule.php';

use FreePBX\modules\Ucmprovisioner\RecurringSchedule;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$tz = new DateTimeZone('Asia/Tehran');
$at = static fn(string $value): int => (new DateTimeImmutable($value, $tz))->getTimestamp();

$interval = RecurringSchedule::normalize(['mode'=>'interval','interval_minutes'=>17]);
$expect(RecurringSchedule::nextRun($interval, 100) === 1120, 'Interval schedules must preserve exact minute granularity');
$legacyInterval = RecurringSchedule::normalize('', 30, 15);
$expect($legacyInterval['interval_minutes'] === 30, 'An absent granular schedule must preserve the pre-upgrade interval');
$mediaFields = RecurringSchedule::fromFields(['schedule_mode'=>'interval','interval_minutes'=>23], 60);
$expect($mediaFields['interval_minutes'] === 23, 'The shared scheduler must accept the media replication interval field without replacing it with the fallback');
$daily = RecurringSchedule::normalize(['mode'=>'daily','time'=>'02:37']);
$expect(RecurringSchedule::nextRun($daily, $at('2026-08-05 01:00'), $tz) === $at('2026-08-05 02:37'), 'Daily schedule must use the selected local time');
$expect(RecurringSchedule::nextRun($daily, $at('2026-08-05 03:00'), $tz) === $at('2026-08-06 02:37'), 'Daily schedule must advance after its selected time');
$weekly = RecurringSchedule::normalize(['mode'=>'weekly','time'=>'23:41','weekdays'=>[1,3,5]]);
$expect(RecurringSchedule::nextRun($weekly, $at('2026-08-05 23:42'), $tz) === $at('2026-08-07 23:41'), 'Weekly schedule must honor exact selected weekdays and time');
$expect(!RecurringSchedule::due(RecurringSchedule::normalize(['mode'=>'manual']), 0, time()), 'Manual schedules must never become due automatically');
try { RecurringSchedule::normalize(['mode'=>'weekly','time'=>'25:00','weekdays'=>[]]); $expect(false, 'Invalid schedules must fail validation'); }
catch (InvalidArgumentException $expected) {}

echo "Recurring schedule fixtures passed\n";
