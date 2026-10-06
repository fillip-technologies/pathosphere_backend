<?php

namespace App\Modules\Lab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AmendReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
