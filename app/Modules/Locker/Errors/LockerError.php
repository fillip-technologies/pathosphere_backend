<?php

namespace App\Modules\Locker\Errors;

use App\Modules\Shared\Errors\DomainError;

/** Health-locker failures with stable codes (AGENT_RESTAPI rule 5). */
final class LockerError
{
    public static function patientUnavailable(): DomainError
    {
        return new DomainError('PATIENT_UNAVAILABLE', 'This patient record is no longer available. Contact the lab.', 403);
    }

    public static function profileNotFound(): DomainError
    {
        return new DomainError('PROFILE_NOT_FOUND', 'You cannot switch to this profile.', 404);
    }

    public static function recordNotFound(): DomainError
    {
        return new DomainError('NOT_FOUND', 'The record was not found.', 404);
    }

    public static function notFound(string $what): DomainError
    {
        return new DomainError('NOT_FOUND', "The {$what} was not found.", 404);
    }

    public static function documentVersionNotFound(): DomainError
    {
        return new DomainError('DOCUMENT_VERSION_NOT_FOUND', 'The record has no file with this version.', 404);
    }

    public static function notAnUpload(): DomainError
    {
        return new DomainError('RECORD_NOT_UPLOADED', 'Only records you uploaded can get a new file version.', 422);
    }

    public static function recordHasNoFile(): DomainError
    {
        return new DomainError('RECORD_HAS_NO_FILE', 'This record has no file to download.', 404);
    }

    /** @param  list<int>  $positions  indexes in medical_record_ids that cannot be shared */
    public static function recordsNotShareable(array $positions): DomainError
    {
        return new DomainError(
            'RECORD_NOT_SHAREABLE',
            'Some records are not yours or have been replaced by a corrected version.',
            422,
            array_map(fn (int $position) => ['field' => "medical_record_ids.{$position}", 'message' => 'Not one of your current records.'], $positions),
        );
    }

    public static function doctorNotRegistered(): DomainError
    {
        return new DomainError('DOCTOR_NOT_REGISTERED', 'No doctor with this phone number is registered with us. Ask your doctor to register at any of our branches, or share by link.', 422, [
            ['field' => 'shared_with.doctor_phone', 'message' => 'Not a registered doctor.'],
        ]);
    }

    public static function familyMemberIsSelf(): DomainError
    {
        return new DomainError('FAMILY_MEMBER_IS_SELF', 'You cannot add yourself as a family member.', 422);
    }

    public static function familyMemberLinked(): DomainError
    {
        return new DomainError('FAMILY_MEMBER_LINKED', 'This member shares your phone and is linked automatically; only the relation can be changed.', 422);
    }
}
