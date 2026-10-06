<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Who opened, downloaded, shared or revoked a record, and when (spec §7.11,
 * §10.7). Append-only: the app never changes a row, and the production
 * database user cannot (db:app-user-grants).
 *
 * @property string $id
 * @property string $medical_record_id
 * @property AccessActorType $actor_type
 * @property string|null $actor_id
 * @property AccessAction $action
 * @property string|null $ip_address
 * @property CarbonImmutable $accessed_at
 */
final class RecordAccessLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'actor_type' => AccessActorType::class,
            'action' => AccessAction::class,
            'accessed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Record access log rows are append-only.'));
        self::deleting(fn () => throw new LogicException('Record access log rows are append-only.'));
    }
}
