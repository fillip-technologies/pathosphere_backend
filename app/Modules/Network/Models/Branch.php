<?php

namespace App\Modules\Network\Models;

use App\Modules\Network\Enums\BranchOwnerType;
use App\Modules\Network\Enums\BranchStatus;
use App\Modules\Network\Enums\BranchType;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Database\Factories\Network\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Any physical site: lab, PSC or pick-up point (spec §7.1).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $region_id
 * @property BranchOwnerType $owner_type
 * @property string|null $franchise_id
 * @property string $branch_code
 * @property string $name
 * @property BranchType $branch_type
 * @property string|null $nabl_certificate_no
 * @property CarbonImmutable|null $nabl_valid_till
 * @property string|null $hfr_id
 * @property string|null $clinical_establishment_reg_no
 * @property string $address
 * @property string $pincode
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string $phone
 * @property array<string, mixed>|null $working_hours
 * @property BranchStatus $status
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Branch extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'owner_type' => BranchOwnerType::class,
            'branch_type' => BranchType::class,
            'status' => BranchStatus::class,
            'working_hours' => 'array',
            'nabl_valid_till' => 'immutable_date',
            'opened_at' => 'immutable_date',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(region: 'region_id', franchise: 'franchise_id', branch: 'id');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<Franchise, $this> */
    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
