<?php

namespace App\Modules\Shared\Http\Middleware;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A stored response for an `Idempotency-Key` (spec §8.5). A null
 * `response_status` means the first request is still being processed.
 *
 * @property string $id
 * @property string $idempotency_key
 * @property string $actor_key
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 * @property array<string, string>|null $response_headers
 * @property CarbonImmutable $expires_at
 */
final class IdempotencyRecord extends Model
{
    use HasUuids;
    use Prunable;

    protected $table = 'idempotency_keys';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_headers' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now());
    }

    public function isCompleted(): bool
    {
        return $this->response_status !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
