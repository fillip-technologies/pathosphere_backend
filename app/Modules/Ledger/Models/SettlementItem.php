<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * Links a ledger row to the settlement that covers it. The unique
 * `ledger_entry_id` means a row is settled at most once (spec §5.6).
 * Read through its settlement, which carries the scope.
 *
 * @property string $id
 * @property string $settlement_id
 * @property string $ledger_entry_id
 */
final class SettlementItem extends BaseModel
{
    public function recordsActor(): bool
    {
        return false;
    }
}
