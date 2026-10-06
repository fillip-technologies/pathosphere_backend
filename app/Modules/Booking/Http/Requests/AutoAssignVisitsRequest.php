<?php

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AutoAssignVisitsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid'],
            // A business day in India.
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
