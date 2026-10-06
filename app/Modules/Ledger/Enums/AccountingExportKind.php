<?php

namespace App\Modules\Ledger\Enums;

/**
 * What an accounting export holds (spec §3: monthly sales and settlement journals).
 *
 * sales: company branches' invoices, receipts and refunds.
 * partner_ledger: franchise account movements, which settlements are built from.
 */
enum AccountingExportKind: string
{
    case Sales = 'sales';
    case PartnerLedger = 'partner_ledger';
}
