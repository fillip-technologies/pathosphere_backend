<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RefreshTokenRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['refresh_token' => ['required', 'string', 'max:100']];
    }
}
