<?php

namespace App\Modules\Ledger\Enums;

/** Voucher types as accounting packages know them. */
enum VoucherType: string
{
    case Sales = 'Sales';
    case Receipt = 'Receipt';
    case Payment = 'Payment';
    case Journal = 'Journal';
}
