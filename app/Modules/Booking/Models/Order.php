<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\OrderSource;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A booking (spec §7.5). Transaction table: never deleted, only cancelled.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $order_no
 * @property string $patient_id
 * @property string $branch_id
 * @property string|null $franchise_id
 * @property string|null $b2b_client_id
 * @property string|null $doctor_id
 * @property OrderSource $order_source
 * @property string|null $external_ref
 * @property string|null $clinical_notes
 * @property CarbonImmutable $order_date
 * @property OrderStatus $status
 * @property string|null $cancelled_reason
 * @property Patient $patient
 * @property Collection<int, OrderItem> $items
 * @property Collection<int, Invoice> $invoices
 * @property HomeCollection|null $homeCollection
 */
final class Order extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'order_source' => OrderSource::class,
            'order_date' => 'immutable_datetime',
            'status' => OrderStatus::class,
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(branch: 'branch_id', franchise: 'franchise_id', b2bClient: 'b2b_client_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasOne<HomeCollection, $this> */
    public function homeCollection(): HasOne
    {
        return $this->hasOne(HomeCollection::class);
    }
}
