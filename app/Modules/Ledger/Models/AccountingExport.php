<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\AccountingExportFormat;
use App\Modules\Ledger\Enums\AccountingExportKind;
use App\Modules\Ledger\Enums\AccountingExportStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;

/**
 * A month of sales or partner-ledger vouchers for Tally or Zoho Books
 * (spec §3). Head-office only: no region, franchise or branch sees these.
 *
 * @property string $id
 * @property string $organization_id
 * @property AccountingExportKind $kind
 * @property AccountingExportFormat $format
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property AccountingExportStatus $status
 * @property string|null $file_path
 * @property string|null $checksum
 * @property int|null $size_bytes
 * @property int|null $voucher_count
 * @property Money|null $total_amount
 * @property string|null $error_message
 * @property CarbonImmutable|null $generated_at
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 */
final class AccountingExport extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'kind' => AccountingExportKind::class,
            'format' => AccountingExportFormat::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'status' => AccountingExportStatus::class,
            'size_bytes' => 'integer',
            'voucher_count' => 'integer',
            'total_amount' => MoneyCast::class,
            'generated_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns;
    }
}
