<?php

namespace App\Modules\Ledger\Enums;

enum WalletTopupStatus: string
{
    /** Payment link sent; waiting for the gateway webhook. */
    case Pending = 'pending';
    case Paid = 'paid';
}
