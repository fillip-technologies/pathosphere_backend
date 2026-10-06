<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * One row of a partner's account with HQ (spec §7.8). Append-only:
 * corrections are new rows, and the database user cannot UPDATE or DELETE
 * here in production. Only LedgerPostingService writes it.
 *
 * Branch staff never see the ledger (spec §4: a processing lab must not see
 * a franchise's money); franchise and B2B client users see their own.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $franchise_id
 * @property string|null $b2b_client_id
 * @property LedgerEntryType $entry_type
 * @property string $reference_type
 * @property string|null $reference_id
 * @property Money $debit
 * @property Money $credit
 * @property Money $balance_after
 * @property string $narration
 * @property string|null $idempotency_key
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 */
final class LedgerEntry extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected $table = 'partner_ledger';

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'debit' => MoneyCast::class,
            'credit' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(franchise: 'franchise_id', b2bClient: 'b2b_client_id');
    }

    /** `created_by` is set by the posting service; there is no `updated_by` on an append-only table. */
    public function recordsActor(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Ledger rows are append-only; post a correcting entry instead.'));
        self::deleting(fn () => throw new LogicException('Ledger rows are append-only; post a correcting entry instead.'));
    }
}
