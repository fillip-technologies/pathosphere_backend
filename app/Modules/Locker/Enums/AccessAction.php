<?php

namespace App\Modules\Locker\Enums;

/** What was done to a record (spec §7.11 record_access_logs.action). */
enum AccessAction: string
{
    case View = 'view';
    case Download = 'download';
    case Share = 'share';
    case Revoke = 'revoke';
}
