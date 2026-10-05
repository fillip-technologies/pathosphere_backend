<?php

namespace App\Modules\Booking\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A raw gateway callback, stored before anything else happens, so it can be
 * replayed and audited (spec §7.5). (gateway, event_id) dedupes retries.
 *
 * @property string $id
 * @property string $gateway
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property bool $signature_valid
 * @property CarbonImmutable|null $processed_at
 * @property string|null $error
 */
final class PaymentWebhookEvent extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
