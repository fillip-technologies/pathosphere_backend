<?php

namespace App\Modules\Lab\Enums;

/** Where a result sits against its reference range (spec §5.5 step 1). */
enum ResultFlag: string
{
    case Normal = 'normal';
    case Low = 'low';
    case High = 'high';
    case CriticalLow = 'critical_low';
    case CriticalHigh = 'critical_high';
    case Abnormal = 'abnormal';

    public function isCritical(): bool
    {
        return $this === self::CriticalLow || $this === self::CriticalHigh;
    }

    /** The letter printed beside the value on the report. */
    public function printedMark(): string
    {
        return match ($this) {
            self::Normal => '',
            self::Low => 'L',
            self::High => 'H',
            self::CriticalLow => 'LL',
            self::CriticalHigh => 'HH',
            self::Abnormal => '*',
        };
    }
}
