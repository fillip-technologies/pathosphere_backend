<?php

namespace App\Modules\Ledger\Enums;

/** Settlement lifecycle (spec §5.6): draft → pending_approval → approved → settled; side exit disputed → approved. */
enum SettlementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Disputed = 'disputed';
    case Settled = 'settled';
}
