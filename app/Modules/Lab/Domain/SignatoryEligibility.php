<?php

namespace App\Modules\Lab\Domain;

use App\Modules\Catalogue\Enums\SigningDiscipline;
use Carbon\CarbonImmutable;

/**
 * Who may sign a department's section of a report (spec §7.2 service rule,
 * §10 legal rules): an active signatory row for that user, the report's
 * processing lab and the department, still within its validity, whose
 * discipline covers the department's.
 *
 * Pathologists may sign every discipline; microbiologists and biochemists
 * only their own (spec §10: the 2017 Supreme Court ruling and later rules).
 */
final class SignatoryEligibility
{
    /** @param  list<SignatoryCredential>  $credentials  the user's signatory rows */
    public static function credentialFor(
        array $credentials,
        string $userId,
        string $labId,
        string $departmentId,
        SigningDiscipline $departmentDiscipline,
        CarbonImmutable $today,
    ): ?SignatoryCredential {
        foreach ($credentials as $credential) {
            if ($credential->userId === $userId
                && $credential->labId === $labId
                && $credential->departmentId === $departmentId
                && $credential->isActive
                && ! self::hasExpired($credential, $today)
                && self::disciplineCovers($credential->discipline, $departmentDiscipline)) {
                return $credential;
            }
        }

        return null;
    }

    public static function disciplineCovers(SigningDiscipline $signatory, SigningDiscipline $department): bool
    {
        return $signatory === SigningDiscipline::Pathology || $signatory === $department;
    }

    public static function hasExpired(SignatoryCredential $credential, CarbonImmutable $today): bool
    {
        return $credential->validTill !== null && $credential->validTill->startOfDay()->lessThan($today->startOfDay());
    }
}
