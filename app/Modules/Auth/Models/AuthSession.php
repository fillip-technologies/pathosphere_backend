<?php

namespace App\Modules\Auth\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed-in device: holds the hash of its rotating refresh token
 * (spec §7.9). Revoking it also revokes its access tokens.
 *
 * @property string $id
 * @property string $account_id
 * @property string $refresh_token_hash
 * @property string|null $device_info
 * @property string|null $ip_address
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property Account $account
 */
final class AuthSession extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Name given to this session's Sanctum access tokens, so they can be revoked together. */
    public function accessTokenName(): string
    {
        return "session:{$this->id}";
    }
}
