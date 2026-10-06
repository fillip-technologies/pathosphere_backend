<?php

namespace App\Modules\Lab\Models;

use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;

/**
 * The on-premises analyser interface of one lab (spec §8.1): it signs in with
 * an API key, only from its allowed IP addresses. The key itself is shown
 * once and stored as a SHA-256 hash.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property list<string> $allowed_ips
 * @property bool $is_active
 * @property CarbonImmutable|null $last_seen_at
 */
final class InterfaceAgent extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected $hidden = ['key_hash'];

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(branch: 'branch_id');
    }
}
