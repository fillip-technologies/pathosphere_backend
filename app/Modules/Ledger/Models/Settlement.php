<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Ledger\Enums\SettlementStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A partner's statement for one or more settlement cycles (spec §5.6, §7.8).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $settlement_no
 * @property string|null $franchise_id
 * @property string|null $b2b_client_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property Money $gross_billing
 * @property Money $partner_share
 * @property Money $hq_share
 * @property Money $tax
 * @property Money $net_amount
 * @property Money $closing_balance
 * @property SettlementDirection $direction
 * @property SettlementStatus $status
 * @property string|null $statement_pdf_path
 * @property string|null $dispute_note
 * @property string|null $approved_by
 * @property CarbonImmutable|null $settled_at
 * @property string|null $payment_reference
 * @property Collection<int, LedgerEntry> $entries
 */
final class Settlement extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'gross_billing' => MoneyCast::class,
            'partner_share' => MoneyCast::class,
            'hq_share' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'net_amount' => MoneyCast::class,
            'closing_balance' => MoneyCast::class,
            'direction' => SettlementDirection::class,
            'status' => SettlementStatus::class,
            'settled_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(franchise: 'franchise_id', b2bClient: 'b2b_client_id');
    }

    /** @return BelongsToMany<LedgerEntry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(LedgerEntry::class, 'settlement_items', 'settlement_id', 'ledger_entry_id')
            ->orderBy('partner_ledger.created_at')
            ->orderBy('partner_ledger.id');
    }
}
