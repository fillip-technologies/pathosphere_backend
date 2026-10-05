<?php

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Enums\AuthMethod;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * One login identity for a person: staff user, patient or doctor (spec §7.9).
 * Sanctum access tokens belong to the account.
 *
 * @property string $id
 * @property AccountOwnerType $owner_type
 * @property string $owner_id
 * @property string $login_identifier
 * @property string|null $password_hash
 * @property AuthMethod $auth_method
 * @property bool $mfa_enabled
 * @property string|null $mfa_secret
 * @property int $failed_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $last_login_at
 * @property bool $is_active
 */
final class Account extends BaseModel implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;

    protected $hidden = ['password_hash', 'mfa_secret'];

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['mfa_enabled' => false, 'failed_attempts' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'owner_type' => AccountOwnerType::class,
            'auth_method' => AuthMethod::class,
            'mfa_enabled' => 'boolean',
            'mfa_secret' => 'encrypted',
            'failed_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
