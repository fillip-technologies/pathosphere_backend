<?php

namespace App\Modules\Lab\Services;

/** What a result submission did, so replays can be told apart from new data. */
final class ResultChanges
{
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
    ) {}
}
