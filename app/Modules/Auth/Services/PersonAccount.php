<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\AccountOwnerType;

/** A patient's or doctor's login, as other modules see it. */
final class PersonAccount
{
    public function __construct(
        public readonly string $id,
        public readonly AccountOwnerType $ownerType,
        public readonly string $ownerId,
        public readonly string $phone,
        public readonly bool $isActive,
    ) {}
}
