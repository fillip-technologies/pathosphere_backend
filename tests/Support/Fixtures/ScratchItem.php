<?php

namespace Tests\Support\Fixtures;

use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use Illuminate\Support\Facades\DB;

/**
 * A throwaway business-like model for testing shared infrastructure. Its
 * table is TEMPORARY, so creating it does not commit the test transaction.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string $name
 * @property SampleStatus $status
 * @property Money $amount
 * @property string|null $created_by
 * @property string|null $updated_by
 */
final class ScratchItem extends BaseModel
{
    protected $table = 'scratch_items';

    protected function casts(): array
    {
        return [
            'status' => SampleStatus::class,
            'amount' => MoneyCast::class,
        ];
    }

    public static function createTable(): void
    {
        DB::statement('create temporary table scratch_items (
            id char(36) primary key,
            organization_id char(36) not null,
            branch_id char(36) null,
            name varchar(100) not null,
            status varchar(32) not null,
            amount decimal(12,2) not null default 0,
            created_by char(36) null,
            updated_by char(36) null,
            created_at datetime(6) null,
            updated_at datetime(6) null
        )');
    }
}
