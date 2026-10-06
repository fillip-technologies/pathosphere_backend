<?php

namespace App\Modules\Network\Models;

use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Enums\FranchiseModel;
use App\Modules\Network\Enums\SettlementCycle;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commercial terms of a franchise; one active at a time (spec §7.1).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $franchise_id
 * @property string $agreement_no
 * @property FranchiseModel $franchise_model
 * @property BillingModel $billing_model
 * @property string|null $commission_pct
 * @property Money $franchise_fee
 * @property Money $security_deposit
 * @property Money|null $min_monthly_business
 * @property string|null $territory
 * @property SettlementCycle $settlement_cycle
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property string|null $signed_doc_path
 * @property string|null $esign_reference
 * @property CarbonImmutable|null $signed_at
 * @property string|null $approved_by
 * @property AgreementStatus $status
 * @property Franchise $franchise
 * @property Collection<int, TerritoryPincode> $pincodes
 */
final class FranchiseAgreement extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['franchise_fee' => '0.00', 'security_deposit' => '0.00'];

    protected function casts(): array
    {
        return [
            'franchise_model' => FranchiseModel::class,
            'billing_model' => BillingModel::class,
            'franchise_fee' => MoneyCast::class,
            'security_deposit' => MoneyCast::class,
            'min_monthly_business' => MoneyCast::class,
            'settlement_cycle' => SettlementCycle::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'signed_at' => 'immutable_datetime',
            'status' => AgreementStatus::class,
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(franchise: 'franchise_id');
    }

    /** @return BelongsTo<Franchise, $this> */
    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    /** @return HasMany<TerritoryPincode, $this> */
    public function pincodes(): HasMany
    {
        return $this->hasMany(TerritoryPincode::class, 'agreement_id');
    }

    /** @return list<string> */
    public function pincodeList(): array
    {
        return $this->pincodes->pluck('pincode')->sort()->values()->all();
    }
}
