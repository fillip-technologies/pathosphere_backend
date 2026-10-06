<?php

namespace App\Modules\Dashboards\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** `?from=&to=` business dates; the last 30 days by default, at most a year. */
final class DashboardRequest extends FormRequest
{
    private const DEFAULT_DAYS = 30;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('to') ?? CarbonImmutable::now('Asia/Kolkata')->subDay()->toDateString());
    }

    public function from(): CarbonImmutable
    {
        $from = CarbonImmutable::parse($this->validated('from') ?? $this->to()->subDays(self::DEFAULT_DAYS - 1)->toDateString());

        // A year at most, so a dashboard never scans the whole history.
        return $from->isBefore($this->to()->subYear()) ? $this->to()->subYear()->addDay() : $from;
    }
}
