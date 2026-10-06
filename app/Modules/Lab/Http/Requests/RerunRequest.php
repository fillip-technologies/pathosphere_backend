<?php

namespace App\Modules\Lab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RerunRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }
}
