<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\WalletTopupStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;

/**
 * A franchise paying into its wallet through a gateway payment link. Credited
 * to the ledger only when the gateway's webhook confirms the payment.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $franchise_id
 * @property Money $amount
 * @property WalletTopupStatus $status
 * @property string $gateway
 * @property string|null $payment_link_id
 * @property CarbonImmutable|null $link_expires_at
 * @property string|null $gateway_payment_id
 * @property CarbonImmutable|null $paid_at
 */
final class WalletTopup extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => WalletTopupStatus::class,
            'link_expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(franchise: 'franchise_id');
    }
}
