<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\FamilyRelation;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone the account holder looks after (spec §7.11). With a UHID
 * (`member_patient_id`) the holder can switch to their records; without one
 * they are only listed until a branch registers them.
 *
 * @property string $id
 * @property string $patient_id
 * @property string|null $member_patient_id
 * @property string $name
 * @property FamilyRelation $relation
 * @property CarbonImmutable|null $dob
 * @property CarbonImmutable|null $deleted_at
 */
final class FamilyMember extends BaseModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['relation' => FamilyRelation::class, 'dob' => 'immutable_date'];
    }
}
