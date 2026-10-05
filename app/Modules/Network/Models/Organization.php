<?php

namespace App\Modules\Network\Models;

use App\Modules\Network\Enums\OrganizationStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Database\Factories\Network\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The brand / head office (spec §7.1).
 *
 * @property string $id
 * @property string $name
 * @property string $legal_name
 * @property string|null $gstin
 * @property string|null $pan
 * @property string|null $cin
 * @property string $hq_address
 * @property string|null $logo_path
 * @property array<string, mixed> $settings
 * @property OrganizationStatus $status
 * @property CarbonImmutable $updated_at
 */
final class Organization extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => OrganizationStatus::class,
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(organization: 'id', visibleToWholeOrganization: true);
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
