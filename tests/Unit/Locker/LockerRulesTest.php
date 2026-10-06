<?php

namespace Tests\Unit\Locker;

use App\Modules\Locker\Domain\AccountHolder;
use App\Modules\Locker\Domain\TrendPoint;
use App\Modules\Locker\Domain\TrendSeries;
use PHPUnit\Framework\TestCase;

final class LockerRulesTest extends TestCase
{
    public function test_the_account_holder_is_the_earliest_patient_without_a_guardian(): void
    {
        $this->assertSame('mother', AccountHolder::choose([
            ['id' => 'child', 'guardian_patient_id' => 'mother'],
            ['id' => 'mother', 'guardian_patient_id' => null],
            ['id' => 'grandmother', 'guardian_patient_id' => null],
        ]));
    }

    public function test_when_everyone_has_a_guardian_the_earliest_holds_the_login(): void
    {
        $this->assertSame('first', AccountHolder::choose([
            ['id' => 'first', 'guardian_patient_id' => 'x'],
            ['id' => 'second', 'guardian_patient_id' => 'y'],
        ]));
        $this->assertNull(AccountHolder::choose([]));
    }

    public function test_points_are_ordered_by_date_and_the_latest_change_gives_the_direction(): void
    {
        $series = TrendSeries::of('HB', [
            $this->point('2026-10-12', '12.8000'),
            $this->point('2026-03-01', '13.0000'),
            $this->point('2026-08-10', '11.2000'),
        ]);

        $this->assertSame(['2026-03-01', '2026-08-10', '2026-10-12'], array_map(fn (TrendPoint $point) => $point->recordDate, $series->points));
        $this->assertSame('up', $series->direction());
        $this->assertSame('12.8000', $series->latest()?->valueNumeric);
        $this->assertSame('Haemoglobin', $series->parameterName());
        $this->assertSame('g/dL', $series->unit());

        $this->assertSame('down', TrendSeries::of('HB', [$this->point('2026-01-01', '13.0000'), $this->point('2026-02-01', '12.9999')])->direction());
        $this->assertSame('same', TrendSeries::of('HB', [$this->point('2026-01-01', '13.0000'), $this->point('2026-02-01', '13.0000')])->direction());
    }

    public function test_no_direction_from_one_value_text_results_or_changed_units(): void
    {
        $this->assertNull(TrendSeries::of('HB', [$this->point('2026-01-01', '13.0000')])->direction());
        $this->assertNull(TrendSeries::of('HIV', [$this->point('2026-01-01', null), $this->point('2026-02-01', null)])->direction());
        $this->assertNull(TrendSeries::of('HB', [$this->point('2026-01-01', '13.0000'), $this->point('2026-02-01', '130.0000', 'g/L')])->direction());
        // Text values between numbers are skipped.
        $this->assertSame('up', TrendSeries::of('HB', [$this->point('2026-01-01', '11.0000'), $this->point('2026-02-01', '12.0000'), $this->point('2026-03-01', null)])->direction());
        $this->assertNull(TrendSeries::of('HB', [])->latest());
    }

    private function point(string $date, ?string $numeric, string $unit = 'g/dL'): TrendPoint
    {
        return new TrendPoint($date, "record-{$date}", 'Haemoglobin', $numeric ?? 'Reactive', $numeric, $unit, '12 - 15', null, 'Patna Clinical Lab');
    }
}
