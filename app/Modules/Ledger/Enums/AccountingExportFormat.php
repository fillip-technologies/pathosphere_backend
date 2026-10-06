<?php

namespace App\Modules\Ledger\Enums;

/** The accounting package a file is made for. */
enum AccountingExportFormat: string
{
    /** Tally Prime / ERP 9 XML import (Gateway of Tally → Import → Vouchers). */
    case TallyXml = 'tally_xml';
    /** Zoho Books manual journal import (CSV). */
    case ZohoBooksCsv = 'zoho_books_csv';
}
