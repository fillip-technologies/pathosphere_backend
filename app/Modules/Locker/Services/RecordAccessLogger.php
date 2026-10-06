<?php

namespace App\Modules\Locker\Services;

use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Models\RecordAccessLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes record_access_logs (spec §7.11, §10.7): every view, download,
 * share and revoke of a health record, by whom and from where.
 */
final class RecordAccessLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    /** @param  list<string>  $medicalRecordIds */
    public function log(array $medicalRecordIds, AccessActorType $actorType, ?string $actorId, AccessAction $action): void
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        $ipAddress = $this->request?->ip();

        $rows = array_map(fn (string $recordId) => [
            'id' => (string) Str::uuid7(),
            'medical_record_id' => $recordId,
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'action' => $action->value,
            'ip_address' => $ipAddress,
            'accessed_at' => $now,
        ], array_values(array_unique($medicalRecordIds)));

        foreach (array_chunk($rows, 500) as $chunk) {
            RecordAccessLog::query()->insert($chunk);
        }
    }

    /** For callers that know the address better than the current request (e.g. a queued event). */
    public function logFrom(string $medicalRecordId, AccessActorType $actorType, ?string $actorId, AccessAction $action, ?string $ipAddress): void
    {
        RecordAccessLog::query()->create([
            'medical_record_id' => $medicalRecordId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'ip_address' => $ipAddress,
            'accessed_at' => CarbonImmutable::now(),
        ]);
    }
}
