<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MfaEnrollmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['mfa_challenge_token' => ['required', 'string', 'max:100']];
    }
}
