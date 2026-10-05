<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SignInRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'login_identifier' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'device_info' => ['nullable', 'string', 'max:255'],
        ];
    }
}
