<?php

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MergePatientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['merge_into_id' => ['required', 'uuid']];
    }
}
