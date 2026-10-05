<?php

namespace App\Modules\Samples\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Recording a draw; offline apps send the real collection time later. */
final class CollectSampleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'collected_at' => ['nullable', 'date', 'before_or_equal:now', 'after:-3 days'],
        ];
    }

    public function collectedAt(): ?CarbonImmutable
    {
        $collectedAt = $this->validated('collected_at');

        return $collectedAt === null ? null : CarbonImmutable::parse($collectedAt)->utc();
    }
}
