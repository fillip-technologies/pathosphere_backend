<?php

namespace App\Modules\Locker\Http\Requests;

use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;

/** Ask for a sign-in code by SMS: a patient or a referring doctor. */
final class OtpChallengeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', Formats::PHONE],
            'account_type' => ['required', 'in:patient,doctor'],
        ];
    }
}
