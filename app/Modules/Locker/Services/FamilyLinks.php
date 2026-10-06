<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\PatientProfile;
use App\Modules\Locker\Enums\FamilyRelation;
use App\Modules\Locker\Models\FamilyMember;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Keeps the family behind one phone together (spec §7.9: "one phone may log
 * into several patients via family switch"). Everyone registered with the
 * account holder's phone becomes a switchable family member; the code they
 * signed in with went to that same phone.
 *
 * A member the holder removed stays removed.
 */
final class FamilyLinks
{
    /** @param  list<PatientProfile>  $patientsOnPhone */
    public function linkPhoneSharers(PatientProfile $holder, array $patientsOnPhone): void
    {
        $known = FamilyMember::query()
            ->withTrashed()
            ->where('patient_id', $holder->id)
            ->whereNotNull('member_patient_id')
            ->pluck('member_patient_id')
            ->all();

        foreach ($patientsOnPhone as $patient) {
            if ($patient->id === $holder->id || in_array($patient->id, $known, true)) {
                continue;
            }

            try {
                $member = new FamilyMember([
                    'name' => $patient->name,
                    'relation' => $patient->guardianPatientId === $holder->id ? FamilyRelation::Child : FamilyRelation::Other,
                    'dob' => $patient->dob?->toDateString(),
                ]);
                $member->patient_id = $holder->id;
                $member->member_patient_id = $patient->id;
                $member->save();
            } catch (UniqueConstraintViolationException) {
                // Linked by a parallel sign-in.
            }
        }
    }
}
