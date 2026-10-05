<?php

namespace App\Modules\Samples\Enums;

/** Sample journey (spec §5.4). */
enum SampleStatus: string
{
    case PendingCollection = 'pending_collection';
    case Collected = 'collected';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Rejected = 'rejected';
    case InProcess = 'in_process';
    case Processed = 'processed';
    case Stored = 'stored';
    case Discarded = 'discarded';
}
