<?php

namespace App\Modules\Locker\Enums;

/** Who opened a record (spec §7.11 record_access_logs.actor_type). */
enum AccessActorType: string
{
    case Patient = 'patient';
    case Doctor = 'doctor';
    case Staff = 'staff';
    case ShareLink = 'share_link';
    case ReportLink = 'report_link';
    case System = 'system';
    /** Sent to a health information user through ABDM, under a consent artefact. */
    case Abdm = 'abdm';
}
