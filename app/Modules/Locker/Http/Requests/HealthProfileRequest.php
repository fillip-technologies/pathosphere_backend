<?php

namespace App\Modules\Locker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The whole health profile (PUT): fields left out are cleared. */
final class HealthProfileRequest extends FormRequest
{
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'blood_group' => ['nullable', 'in:'.implode(',', self::BLOOD_GROUPS)],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'chronic_conditions' => ['nullable', 'string', 'max:2000'],
            'health_summary' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
