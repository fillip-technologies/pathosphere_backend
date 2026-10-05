<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\AbhaStatus;
use App\Modules\Shared\Enums\Gender;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person served by the network, identified by UHID at every branch
 * (spec §7.3). Any branch may find a patient; their order history stays
 * limited by scope through the orders themselves.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $uhid
 * @property string $registered_branch_id
 * @property string|null $salutation
 * @property string $name
 * @property CarbonImmutable|null $dob
 * @property int|null $age_years
 * @property Gender $gender
 * @property string $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $pincode
 * @property string|null $abha_number
 * @property string|null $abha_number_hash
 * @property string|null $abha_address
 * @property AbhaStatus $abha_status
 * @property bool $abha_kyc_verified
 * @property CarbonImmutable|null $abha_linked_at
 * @property string|null $abha_linked_by
 * @property array<string, mixed>|null $abha_profile_snapshot
 * @property string|null $consent_notice_version
 * @property CarbonImmutable|null $consented_at
 * @property CarbonImmutable|null $whatsapp_opted_in_at
 * @property string|null $guardian_patient_id
 * @property string|null $merged_into_id
 */
final class Patient extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    protected $hidden = ['abha_number_hash'];

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['abha_status' => 'not_linked', 'abha_kyc_verified' => false];

    protected function casts(): array
    {
        return [
            'dob' => 'immutable_date',
            'age_years' => 'integer',
            'gender' => Gender::class,
            'abha_number' => 'encrypted',
            'abha_status' => AbhaStatus::class,
            'abha_kyc_verified' => 'boolean',
            'abha_linked_at' => 'immutable_datetime',
            'abha_profile_snapshot' => 'array',
            'consented_at' => 'immutable_datetime',
            'whatsapp_opted_in_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }

    /**
     * Keyed hash of an ABHA number, for exact lookup and uniqueness while the
     * number itself stays encrypted. Hyphens and spaces are ignored.
     */
    public static function abhaNumberHash(string $abhaNumber): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $abhaNumber) ?? '', (string) config('app.key'));
    }

    /** Age in whole years: from date of birth when known, else as recorded. */
    public function ageInYears(?CarbonImmutable $on = null): ?int
    {
        if ($this->dob === null) {
            return $this->age_years;
        }

        return (int) $this->dob->diffInYears($on ?? CarbonImmutable::now(), absolute: true);
    }

    /** Phone with all but the last 4 digits hidden, for lists (spec §10.2). */
    public function maskedPhone(): string
    {
        return str_repeat('*', max(0, strlen($this->phone) - 4)).substr($this->phone, -4);
    }
}
