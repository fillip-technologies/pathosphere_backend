<?php

namespace App\Modules\Locker\Http\Requests;

use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;

final class OtpVerificationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', Formats::PHONE],
            'account_type' => ['required', 'in:patient,doctor'],
            'code' => ['required', 'string', 'digits:'.(int) config('pathology.otp.length')],
            'device_info' => ['nullable', 'string', 'max:255'],
        ];
    }
}
