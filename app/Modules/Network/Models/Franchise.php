<?php

namespace App\Modules\Network\Models;

use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Database\Factories\Network\FranchiseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An outside business running branches under the brand (spec §7.1).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $region_id
 * @property string $franchise_code
 * @property string $name
 * @property string $legal_name
 * @property string $owner_name
 * @property string $phone
 * @property string $email
 * @property string|null $gstin
 * @property string $pan
 * @property string $address
 * @property string|null $bank_account_no
 * @property string|null $bank_ifsc
 * @property string|null $partner_price_list_id
 * @property Money $credit_limit
 * @property Money $current_balance
 * @property FranchiseStatus $status
 * @property CarbonImmutable|null $onboarded_at
 */
final class Franchise extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** @use HasFactory<FranchiseFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $hidden = ['bank_account_no'];

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['credit_limit' => '0.00', 'current_balance' => '0.00'];

    protected function casts(): array
    {
        return [
            'bank_account_no' => 'encrypted',
            'credit_limit' => MoneyCast::class,
            'current_balance' => MoneyCast::class,
            'status' => FranchiseStatus::class,
            'onboarded_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(region: 'region_id', franchise: 'id');
    }

    /** @return HasMany<FranchiseAgreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(FranchiseAgreement::class);
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    protected static function newFactory(): FranchiseFactory
    {
        return FranchiseFactory::new();
    }
}
