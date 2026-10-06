<?php

namespace App\Modules\Locker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** What DigiLocker sent the patient back with. */
final class DigiLockerAuthorizationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:500'],
            'state' => ['required', 'string', 'max:100'],
        ];
    }
}
