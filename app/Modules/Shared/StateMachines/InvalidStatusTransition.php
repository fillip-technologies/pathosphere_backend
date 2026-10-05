<?php

namespace App\Modules\Shared\StateMachines;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;

final class InvalidStatusTransition extends DomainError
{
    public function __construct(string $entityName, string $from, string $to)
    {
        parent::__construct(
            ErrorCode::INVALID_STATUS_TRANSITION,
            "A {$entityName} cannot move from '{$from}' to '{$to}'.",
            409,
            [['field' => 'status', 'from' => $from, 'to' => $to]],
        );
    }
}
