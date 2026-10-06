<?php

namespace App\Modules\Ledger\Domain;

/** What a ledger row points back to (spec §7.8 `reference_type`). */
final class ReferenceType
{
    public const ORDER_ITEM = 'order_item';

    public const STOCK_TRANSFER = 'stock_transfer';

    public const AGREEMENT = 'agreement';

    public const SETTLEMENT = 'settlement';

    public const WALLET_TOPUP = 'wallet_topup';

    public const MANUAL = 'manual';
}
