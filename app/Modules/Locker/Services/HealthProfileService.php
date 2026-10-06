<?php

namespace App\Modules\Locker\Services;

use App\Modules\Locker\Models\PatientHealthProfile;
use App\Modules\Shared\Audit\AuditLogger;

/**
 * The patient's own health notes (spec §7.11 patient_health_profiles):
 * blood group, allergies, long-term conditions and a summary.
 *
 * `abha_id` is left empty: the ABHA number is kept encrypted on the patient
 * (spec §10.2) and shown through the ABHA card endpoints.
 */
final class HealthProfileService
{
    private const FIELDS = ['blood_group', 'allergies', 'chronic_conditions', 'health_summary'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** The profile's notes; an unsaved, empty one if none were written yet. */
    public function get(PatientViewer $viewer): PatientHealthProfile
    {
        return PatientHealthProfile::query()->where('patient_id', $viewer->profile()->id)->first()
            ?? $this->blank($viewer);
    }

    /** @param  array<string, string|null>  $values  replaces every field (PUT) */
    public function replace(PatientViewer $viewer, array $values): PatientHealthProfile
    {
        $profile = $this->get($viewer);
        $isNew = ! $profile->exists;
        $profile->fill(array_merge(array_fill_keys(self::FIELDS, null), array_intersect_key($values, array_flip(self::FIELDS))));

        if (! $isNew && ! $profile->isDirty()) {
            return $profile;
        }

        $profile->save();
        $isNew
            ? $this->auditLogger->recordCreated('health_profile.created', $profile)
            : $this->auditLogger->recordChanges('health_profile.updated', $profile);

        return $profile;
    }

    private function blank(PatientViewer $viewer): PatientHealthProfile
    {
        $profile = new PatientHealthProfile;
        $profile->patient_id = $viewer->profile()->id;

        return $profile;
    }
}
