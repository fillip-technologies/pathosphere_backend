<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\PatientProfile;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Enums\FamilyRelation;
use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Models\FamilyMember;
use App\Modules\Shared\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;

/**
 * The account holder's family (spec §7.11 family_members, §8 GET /me/family):
 * members with their own UHID can be switched to; dependants not yet
 * registered are listed by name until a branch registers them.
 *
 * Always the holder's family, whichever profile is being viewed.
 */
final class FamilyService
{
    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return Collection<int, FamilyMember> */
    public function members(PatientViewer $viewer): Collection
    {
        return FamilyMember::query()
            ->whereIn('patient_id', $this->people->idsMergedInto($viewer->holder()->id))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return array<string, PatientProfile> registered members' current records by member row ID
     */
    public function profiles(Collection $members): array
    {
        $profiles = [];

        foreach ($members as $member) {
            /** @var FamilyMember $member */
            if ($member->member_patient_id !== null && ($profile = $this->people->patient($member->member_patient_id)) !== null) {
                $profiles[$member->id] = $profile;
            }
        }

        return $profiles;
    }

    public function find(PatientViewer $viewer, string $memberId): FamilyMember
    {
        return $this->members($viewer)->firstWhere('id', $memberId) ?? throw LockerError::notFound('family member');
    }

    /**
     * The member whose records the holder may open, or null.
     */
    public function switchableProfile(PatientProfile $holder, string $patientId): ?PatientProfile
    {
        $member = FamilyMember::query()
            ->whereIn('patient_id', $this->people->idsMergedInto($holder->id))
            ->whereIn('member_patient_id', $this->people->idsMergedInto($patientId))
            ->first();

        return $member === null ? null : $this->people->patient($patientId);
    }

    /** @param  array{name: string, relation: string, dob?: string|null}  $details */
    public function addDependant(PatientViewer $viewer, array $details): FamilyMember
    {
        $member = new FamilyMember([
            'name' => $details['name'],
            'relation' => FamilyRelation::from($details['relation']),
            'dob' => $details['dob'] ?? null,
        ]);
        $member->patient_id = $viewer->holder()->id;
        $member->save();
        $this->auditLogger->recordCreated('family_member.added', $member);

        return $member;
    }

    /**
     * Relation of anyone; name and date of birth only of dependants without a
     * UHID (a registered member's identity is their patient record).
     *
     * @param  array{name?: string, relation?: string, dob?: string|null}  $changes
     */
    public function update(PatientViewer $viewer, string $memberId, array $changes): FamilyMember
    {
        $member = $this->find($viewer, $memberId);

        if ($member->member_patient_id !== null && (array_key_exists('name', $changes) || array_key_exists('dob', $changes))) {
            throw LockerError::familyMemberLinked();
        }

        $member->fill(array_intersect_key($changes, array_flip(['name', 'relation', 'dob'])));

        if ($member->isDirty()) {
            $member->save();
            $this->auditLogger->recordChanges('family_member.updated', $member);
        }

        return $member;
    }

    /** Removed members are not linked again at the next sign-in. */
    public function remove(PatientViewer $viewer, string $memberId): void
    {
        $member = $this->find($viewer, $memberId);
        $member->delete();
        $this->auditLogger->record('family_member.removed', $member);
    }
}
