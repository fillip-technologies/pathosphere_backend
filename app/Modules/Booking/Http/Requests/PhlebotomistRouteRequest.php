<?php

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PhlebotomistRouteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A business day in India; today when left out.
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
