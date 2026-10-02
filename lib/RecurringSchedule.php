<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** Shared minute-accurate schedule grammar for the module's recurring jobs. */
final class RecurringSchedule
{
    private const MODES = ['manual', 'interval', 'daily', 'weekly'];

    /** @return array{mode:string,interval_minutes:int,time:string,weekdays:array<int,int>} */
    public static function normalize($value, int $fallbackMinutes = 60, int $minimumMinutes = 5, int $maximumMinutes = 10080): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        $value = is_array($value) ? $value : [];
        $fallbackMinutes = max($minimumMinutes, min($maximumMinutes, $fallbackMinutes));
        $mode = strtolower(trim((string)($value['mode'] ?? 'interval')));
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Schedule mode must be manual, interval, daily or weekly');
        }
        $interval = filter_var($value['interval_minutes'] ?? $fallbackMinutes, FILTER_VALIDATE_INT);
        if ($interval === false || (int)$interval < $minimumMinutes || (int)$interval > $maximumMinutes) {
            if ($mode === 'interval') {
                throw new \InvalidArgumentException("Schedule interval must be between {$minimumMinutes} and {$maximumMinutes} minutes");
            }
            // Keep one canonical interval value even when this mode does not
            // use it. This also upgrades the legacy "0 = manual" setting.
            $interval = $fallbackMinutes;
        }
        $time = trim((string)($value['time'] ?? '02:00'));
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new \InvalidArgumentException('Schedule time must use 24-hour HH:MM format');
        }
        $weekdays = $value['weekdays'] ?? [1,2,3,4,5,6,7];
        if (is_string($weekdays)) {
            $decoded = json_decode($weekdays, true);
            $weekdays = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $weekdays, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($weekdays)) { $weekdays = []; }
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        sort($weekdays, SORT_NUMERIC);
        if (array_filter($weekdays, static fn(int $day): bool => $day < 1 || $day > 7)) {
            throw new \InvalidArgumentException('Schedule weekdays must be ISO day numbers 1 through 7');
        }
        if ($mode === 'weekly' && !$weekdays) {
            throw new \InvalidArgumentException('A weekly schedule requires at least one weekday');
        }
        return [
            'mode'=>$mode,
            'interval_minutes'=>(int)$interval,
            'time'=>$time,
            'weekdays'=>$mode === 'weekly' ? $weekdays : [1,2,3,4,5,6,7],
        ];
    }

    /** @return array{mode:string,interval_minutes:int,time:string,weekdays:array<int,int>} */
    public static function fromFields(array $input, int $fallbackMinutes = 60, int $minimumMinutes = 5, int $maximumMinutes = 10080): array
    {
        $stored = $input['schedule_json'] ?? null;
        if (!array_key_exists('schedule_mode', $input) && $stored !== null && $stored !== '') {
            return self::normalize($stored, $fallbackMinutes, $minimumMinutes, $maximumMinutes);
        }
        return self::normalize([
            'mode'=>$input['schedule_mode'] ?? (($fallbackMinutes === 0) ? 'manual' : 'interval'),
            // Directory-source forms submit schedule_minutes; replication forms
            // submit interval_minutes. Accept both boundary names so the shared
            // schedule grammar never replaces a saved interval with its fallback.
            'interval_minutes'=>$input['schedule_minutes'] ?? $input['interval_minutes'] ?? max($minimumMinutes, $fallbackMinutes),
            'time'=>$input['schedule_time'] ?? '02:00',
            'weekdays'=>$input['schedule_weekdays'] ?? [1,2,3,4,5,6,7],
        ], max($minimumMinutes, $fallbackMinutes), $minimumMinutes, $maximumMinutes);
    }

    public static function encode(array $schedule): string
    {
        return (string)json_encode(self::normalize($schedule), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function compatibilityMinutes(array $schedule): int
    {
        $schedule = self::normalize($schedule);
        return match ($schedule['mode']) {
            'manual' => 0,
            'daily' => 1440,
            'weekly' => 10080,
            default => $schedule['interval_minutes'],
        };
    }

    /** First scheduled instant strictly after $after. Returns zero for manual schedules. */
    public static function nextRun(array $schedule, int $after, ?\DateTimeZone $timezone = null): int
    {
        $schedule = self::normalize($schedule);
        if ($schedule['mode'] === 'manual') { return 0; }
        if ($schedule['mode'] === 'interval') {
            return max(0, $after) + ($schedule['interval_minutes'] * 60);
        }
        $timezone ??= new \DateTimeZone(date_default_timezone_get());
        $local = (new \DateTimeImmutable('@' . max(0, $after)))->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $schedule['time'], 2));
        for ($offset = 0; $offset <= 8; $offset++) {
            $candidate = $local->modify("+{$offset} day")->setTime($hour, $minute, 0);
            if ($candidate->getTimestamp() <= $after) { continue; }
            if ($schedule['mode'] === 'daily' || in_array((int)$candidate->format('N'), $schedule['weekdays'], true)) {
                return $candidate->getTimestamp();
            }
        }
        throw new \RuntimeException('Could not calculate the next scheduled run');
    }

    public static function due(array $schedule, int $lastRun, int $now): bool
    {
        $schedule = self::normalize($schedule);
        if ($schedule['mode'] === 'manual') { return false; }
        return $lastRun < 1 || self::nextRun($schedule, $lastRun) <= $now;
    }

    public static function describe(array $schedule): string
    {
        $schedule = self::normalize($schedule);
        if ($schedule['mode'] === 'manual') { return 'Manual only'; }
        if ($schedule['mode'] === 'interval') { return 'Every ' . $schedule['interval_minutes'] . ' minutes'; }
        if ($schedule['mode'] === 'daily') { return 'Daily at ' . $schedule['time']; }
        $names = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];
        return implode(', ', array_map(static fn(int $day): string => $names[$day], $schedule['weekdays'])) . ' at ' . $schedule['time'];
    }
}
