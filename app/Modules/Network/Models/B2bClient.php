<?php

namespace App\Modules\Network\Models;

use App\Modules\Network\Enums\B2bClientStatus;
use App\Modules\Network\Enums\B2bClientType;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Database\Factories\Network\B2bClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hospital, clinic, lab or corporate buying on credit (spec §7.1).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $region_id
 * @property string $serviced_by_branch_id
 * @property string $price_list_id
 * @property B2bClientType $client_type
 * @property string $client_code
 * @property string $name
 * @property string|null $gstin
 * @property string $contact_name
 * @property string $phone
 * @property string $email
 * @property string $billing_address
 * @property int $credit_days
 * @property Money $credit_limit
 * @property Money $current_balance
 * @property bool $withhold_reports_when_overdue
 * @property B2bClientStatus $status
 */
final class B2bClient extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** @use HasFactory<B2bClientFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['credit_limit' => '0.00', 'credit_days' => 30, 'current_balance' => '0.00', 'withhold_reports_when_overdue' => false];

    protected function casts(): array
    {
        return [
            'client_type' => B2bClientType::class,
            'credit_limit' => MoneyCast::class,
            'current_balance' => MoneyCast::class,
            'status' => B2bClientStatus::class,
            'withhold_reports_when_overdue' => 'boolean',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        // The servicing branch handles the client's samples, so it sees the client too.
        return new ScopeColumns(region: 'region_id', branch: 'serviced_by_branch_id', b2bClient: 'id');
    }

    protected static function newFactory(): B2bClientFactory
    {
        return B2bClientFactory::new();
    }
}
