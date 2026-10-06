<?php

namespace App\Modules\Ledger\Enums;

enum AccountingExportStatus: string
{
    case Queued = 'queued';
    case Ready = 'ready';
    case Failed = 'failed';
}
