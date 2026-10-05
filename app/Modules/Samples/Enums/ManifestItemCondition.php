<?php

namespace App\Modules\Samples\Enums;

/** sample_transfer_items.condition: how a sample arrived at the receiving lab. */
enum ManifestItemCondition: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
