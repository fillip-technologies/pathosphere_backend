<?php

namespace App\Modules\Locker\Domain;

/**
 * Who owns the login when several patients share a phone (spec §7.3:
 * "families share phones"; §7.9: one login per phone, family switch).
 *
 * The holder is the earliest registered patient without a guardian, i.e.
 * the adult who most likely gave their number; if every patient on the phone
 * has a guardian, the earliest one. The others become switchable family.
 */
final class AccountHolder
{
    /**
     * @param  list<array{id: string, guardian_patient_id: string|null}>  $patientsOldestFirst
     */
    public static function choose(array $patientsOldestFirst): ?string
    {
        foreach ($patientsOldestFirst as $patient) {
            if ($patient['guardian_patient_id'] === null) {
                return $patient['id'];
            }
        }

        return $patientsOldestFirst[0]['id'] ?? null;
    }
}
