<?php

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Database\Factories\Auth\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A staff member (spec §7.2). Owned by the Auth module because role and
 * scope checks always need user, role and account together. Credentials
 * live in Account.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $role_id
 * @property string|null $region_id
 * @property string|null $franchise_id
 * @property string|null $branch_id
 * @property string|null $b2b_client_id
 * @property string|null $employee_code
 * @property string $name
 * @property string|null $email
 * @property string $phone
 * @property UserStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property Role $role
 */
final class User extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return ['status' => UserStatus::class];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(
            region: 'region_id',
            franchise: 'franchise_id',
            branch: 'branch_id',
            b2bClient: 'b2b_client_id',
        );
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withTrashed();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
