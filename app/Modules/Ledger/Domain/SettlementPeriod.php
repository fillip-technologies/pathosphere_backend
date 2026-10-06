<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Network\Enums\SettlementCycle;
use Carbon\CarbonImmutable;

/**
 * Settlement cycle boundaries, in business dates (Asia/Kolkata). Weeks run
 * Monday to Sunday, fortnights 1–15 and 16–month end, months by calendar.
 */
final class SettlementPeriod
{
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /** The period containing the given date. */
    public static function containing(SettlementCycle $cycle, CarbonImmutable $date): self
    {
        $date = $date->startOfDay();

        return match ($cycle) {
            SettlementCycle::Weekly => new self($date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()),
            SettlementCycle::Fortnightly => $date->day <= 15
                ? new self($date->startOfMonth(), $date->setDay(15))
                : new self($date->setDay(16), $date->endOfMonth()->startOfDay()),
            SettlementCycle::Monthly => new self($date->startOfMonth(), $date->endOfMonth()->startOfDay()),
        };
    }

    /** The most recent period that ended before today. */
    public static function lastClosedBefore(SettlementCycle $cycle, CarbonImmutable $today): self
    {
        return self::containing($cycle, self::containing($cycle, $today)->start->subDay());
    }

    public function label(): string
    {
        return $this->start->toDateString().'..'.$this->end->toDateString();
    }
}
