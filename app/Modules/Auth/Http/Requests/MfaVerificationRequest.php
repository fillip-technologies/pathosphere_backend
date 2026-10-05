<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MfaVerificationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mfa_challenge_token' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
