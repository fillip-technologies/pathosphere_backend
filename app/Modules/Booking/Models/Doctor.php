<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\ReportDelivery;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A referring doctor (spec §7.3). There are deliberately no commission
 * fields: referral payments to doctors are prohibited (spec §10).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $registration_no
 * @property string|null $specialization
 * @property string|null $clinic_name
 * @property string|null $phone
 * @property string|null $email
 * @property ReportDelivery $report_delivery
 */
final class Doctor extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['report_delivery' => 'whatsapp'];

    protected function casts(): array
    {
        return ['report_delivery' => ReportDelivery::class];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }
}
