<?php

namespace App\Modules\Locker\Enums;

/** Consent lifecycle (spec §7.11); mirrors ABDM consent artefacts. */
enum ConsentStatus: string
{
    case Requested = 'requested';
    case Granted = 'granted';
    case Denied = 'denied';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
