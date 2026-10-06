<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\PatientProfile;
use LogicException;

/**
 * The patient signed in for this request and the profile they are looking
 * at: themselves, or a family member they switched to with `X-Patient-Id`
 * (spec §7.9 family switch). Set by the ResolvePatientProfile middleware.
 */
final class PatientViewer
{
    private ?PatientProfile $holder = null;

    private ?PatientProfile $profile = null;

    /** @var list<string> */
    private array $patientIds = [];

    /** @param  list<string>  $patientIds  the profile and every record merged into it */
    public function set(PatientProfile $holder, PatientProfile $profile, array $patientIds): void
    {
        $this->holder = $holder;
        $this->profile = $profile;
        $this->patientIds = $patientIds;
    }

    /** The person who signed in: the owner of the phone. */
    public function holder(): PatientProfile
    {
        return $this->holder ?? throw new LogicException('No patient is signed in.');
    }

    /** Whose records this request is about. */
    public function profile(): PatientProfile
    {
        return $this->profile ?? throw new LogicException('No patient is signed in.');
    }

    /** @return list<string> */
    public function patientIds(): array
    {
        return $this->patientIds;
    }

    public function organizationId(): string
    {
        return $this->profile()->organizationId;
    }

    public function owns(string $patientId): bool
    {
        return in_array($patientId, $this->patientIds, true);
    }
}
