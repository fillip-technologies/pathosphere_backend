<?php

namespace App\Modules\Samples\Enums;

/** Kit and consumable supply between branches (spec §7.7). */
enum StockTransferStatus: string
{
    case Requested = 'requested';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
