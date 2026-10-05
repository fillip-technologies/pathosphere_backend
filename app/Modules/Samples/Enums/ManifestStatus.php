<?php

namespace App\Modules\Samples\Enums;

/** sample_transfers.status (spec §5.4). */
enum ManifestStatus: string
{
    case Created = 'created';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case PartiallyReceived = 'partially_received';
}
