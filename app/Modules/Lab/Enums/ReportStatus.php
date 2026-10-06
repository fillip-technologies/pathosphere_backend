<?php

namespace App\Modules\Lab\Enums;

/** Report lifecycle (spec §5.5). */
enum ReportStatus: string
{
    case Draft = 'draft';
    case PendingSignature = 'pending_signature';
    case Signed = 'signed';
    case Released = 'released';
    case Amended = 'amended';
    case Withheld = 'withheld';

    /** Released content is final; it changes only through a new version. */
    public function isPublished(): bool
    {
        return $this === self::Released || $this === self::Amended;
    }
}
