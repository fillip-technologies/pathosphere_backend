<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Context\CurrentActor;

/**
 * Fills `created_by` / `updated_by` (spec §6.5) from the current actor.
 * System work leaves them null, which the spec reads as "done by the system".
 */
trait RecordsActor
{
    public static function bootRecordsActor(): void
    {
        static::creating(function (self $model): void {
            if (! $model->recordsActor()) {
                return;
            }

            $userId = app(CurrentActor::class)->userId();
            $model->setAttribute('created_by', $model->getAttribute('created_by') ?? $userId);
            $model->setAttribute('updated_by', $model->getAttribute('updated_by') ?? $userId);
        });

        static::updating(function (self $model): void {
            if (! $model->recordsActor()) {
                return;
            }

            $model->setAttribute('updated_by', app(CurrentActor::class)->userId());
        });
    }

    /** Append-only and log tables have no actor columns; they override this. */
    public function recordsActor(): bool
    {
        return true;
    }
}
