<?php

namespace App\Modules\Auth\Services;

use LogicException;

/** The patient or doctor signed in for this request, set by AuthenticatePerson. */
final class SignedInPerson
{
    private ?PersonAccount $account = null;

    public function set(PersonAccount $account): void
    {
        $this->account = $account;
    }

    public function account(): PersonAccount
    {
        return $this->account ?? throw new LogicException('No patient or doctor is signed in.');
    }
}
