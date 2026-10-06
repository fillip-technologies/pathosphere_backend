<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Services\DigiLockerSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DigiLocker connection. The authorization URL is shown until the patient
 * has signed in at DigiLocker; tokens and the PKCE verifier never leave us.
 *
 * @mixin DigiLockerSession
 */
final class DigiLockerSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $connected = $this->status() === DigiLockerSession::CONNECTED;

        return [
            'id' => $this->id,
            'status' => $this->status(),
            'authorization_url' => $connected ? null : $this->authorizationUrl,
            'digilocker_account_name' => $this->accountName,
            'expires_at' => $this->expiresAt->toIso8601ZuluString(),
        ];
    }
}
