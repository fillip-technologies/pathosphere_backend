<?php

namespace App\Modules\Lab\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** A lab's interface agent and the addresses (or CIDR ranges) it may call from. */
final class CreateInterfaceAgentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'allowed_ips' => ['required', 'array', 'min:1', 'max:20'],
            'allowed_ips.*' => ['required', 'string', 'max:49', $this->ipOrRange(...)],
        ];
    }

    private function ipOrRange(string $attribute, mixed $value, Closure $fail): void
    {
        [$address, $bits] = array_pad(explode('/', (string) $value, 2), 2, null);
        $isIp = filter_var($address, FILTER_VALIDATE_IP) !== false;
        $maxBits = str_contains((string) $address, ':') ? 128 : 32;
        $bitsOk = $bits === null || (ctype_digit($bits) && (int) $bits <= $maxBits);

        if (! $isIp || ! $bitsOk) {
            $fail('Enter an IP address or a CIDR range such as 203.0.113.0/24.');
        }
    }
}
