<?php

namespace App\Modules\Shared\Notifications;

use Carbon\CarbonImmutable;

/**
 * No non-urgent messages between 21:00 and 08:00 India time (spec §9 rule 4).
 * Critical-value alerts ignore quiet hours.
 */
final class QuietHours
{
    private const TIMEZONE = 'Asia/Kolkata';

    private const STARTS_AT_HOUR = 21;

    private const ENDS_AT_HOUR = 8;

    /** When the message may go out: null means now. */
    public static function deliverAt(CarbonImmutable $now, bool $urgent): ?CarbonImmutable
    {
        if ($urgent) {
            return null;
        }

        $local = $now->setTimezone(self::TIMEZONE);

        if ($local->hour >= self::ENDS_AT_HOUR && $local->hour < self::STARTS_AT_HOUR) {
            return null;
        }

        $morning = $local->setTime(self::ENDS_AT_HOUR, 0);

        return ($local->hour >= self::STARTS_AT_HOUR ? $morning->addDay() : $morning)->utc();
    }
}
