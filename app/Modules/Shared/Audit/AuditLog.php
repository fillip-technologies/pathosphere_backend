<?php

namespace App\Modules\Shared\Audit;

use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only trail of changes to clinical, financial and permission data
 * (spec §7.10, §10.7). Rows are never updated or deleted by the app; the
 * database user is also denied UPDATE/DELETE on this table in production.
 * Scoped, so franchise owners see only their own trail.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $user_id
 * @property string $action
 * @property string $entity_type
 * @property string $entity_id
 * @property array<string, mixed>|null $old_value
 * @property array<string, mixed>|null $new_value
 * @property string|null $franchise_id
 * @property string|null $branch_id
 * @property string|null $ip_address
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
final class AuditLog extends Model implements HasScopeColumns
{
    use BelongsToScope;
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(franchise: 'franchise_id', branch: 'branch_id');
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Audit log rows are append-only.'));
        self::deleting(fn () => throw new LogicException('Audit log rows are append-only.'));
    }
}
