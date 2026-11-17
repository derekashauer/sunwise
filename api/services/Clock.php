<?php
/**
 * Clock
 *
 * One place for date math. The rules:
 * - Timestamps in the database (completed_at, performed_at, ...) are UTC,
 *   because SQLite's datetime('now') and CURRENT_TIMESTAMP are UTC.
 * - Due dates are plain calendar dates in the plant owner's own time zone.
 * - PHP's default time zone is set to the signed-in user's zone for each
 *   request, so date('Y-m-d') is "today" for that person.
 */

class Clock
{
    /**
     * Months when slow-release fertilizer is paused (September to February).
     */
    public const REST_MONTHS = [9, 10, 11, 12, 1, 2];

    /**
     * Switch PHP's default time zone to the user's saved zone.
     */
    public static function useUserTimezone(?int $userId): void
    {
        if (!$userId) {
            return;
        }
        $stmt = db()->prepare('SELECT timezone FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        self::useTimezone($stmt->fetchColumn() ?: null);
    }

    /**
     * Switch PHP's default time zone, ignoring invalid names.
     */
    public static function useTimezone(?string $timezone): void
    {
        if ($timezone && self::isValidTimezone($timezone)) {
            date_default_timezone_set($timezone);
        } else {
            date_default_timezone_set(DEFAULT_TIMEZONE);
        }
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Today's date in the current time zone.
     */
    public static function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * A date N days from today (negative for the past).
     */
    public static function daysFromToday(int $days): string
    {
        return self::addDays(self::today(), $days);
    }

    /**
     * Add days to a YYYY-MM-DD date.
     */
    public static function addDays(string $date, int $days): string
    {
        $d = new DateTimeImmutable($date . ' 00:00:00');
        return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /**
     * Whole calendar days from $from to $to (both YYYY-MM-DD).
     */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable(substr($from, 0, 10));
        $b = new DateTimeImmutable(substr($to, 0, 10));
        return (int) $a->diff($b)->format('%r%a');
    }

    /**
     * Current UTC timestamp in the format SQLite uses.
     */
    public static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * The local calendar date of a UTC timestamp from the database.
     */
    public static function utcToLocalDate(string $utc): string
    {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    /**
     * UTC start and end (exclusive) of a local calendar day, for SQL ranges.
     *
     * @return array{0: string, 1: string}
     */
    public static function localDayUtcRange(string $localDate): array
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($localDate . ' 00:00:00', $tz);
        $end = $start->modify('+1 day');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Convert a local date or date-time from the user into a UTC timestamp.
     * A bare date is treated as noon that day. Returns null if it can't parse
     * or is in the future.
     */
    public static function localToUtc(?string $local): ?string
    {
        if (!$local) {
            return null;
        }
        $local = trim($local);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $local)) {
            $local .= ' 12:00:00';
        }
        $local = str_replace('T', ' ', $local);
        try {
            $d = new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get()));
        } catch (Exception $e) {
            return null;
        }
        $utc = $d->setTimezone(new DateTimeZone('UTC'));
        // A bare "today" at noon may still be later than now; clamp to now.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($utc > $now) {
            if ($d->format('Y-m-d') > self::today()) {
                return null;
            }
            $utc = $now;
        }
        return $utc->format('Y-m-d H:i:s');
    }

    /**
     * Northern hemisphere season for a date (defaults to today).
     */
    public static function season(?string $date = null): string
    {
        $month = (int) date('n', strtotime($date ?? self::today()));
        if ($month >= 3 && $month <= 5) {
            return 'spring';
        }
        if ($month >= 6 && $month <= 8) {
            return 'summer';
        }
        if ($month >= 9 && $month <= 11) {
            return 'fall';
        }
        return 'winter';
    }

    /**
     * Whether a date falls in the fertilizer rest months.
     */
    public static function isRestMonth(string $date): bool
    {
        return in_array((int) substr($date, 5, 2), self::REST_MONTHS, true);
    }

    /**
     * The first March 1 on or after a date.
     */
    public static function nextMarchFirst(string $date): string
    {
        $year = (int) substr($date, 0, 4);
        $candidate = sprintf('%04d-03-01', $year);
        return $candidate >= $date ? $candidate : sprintf('%04d-03-01', $year + 1);
    }
}
