<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\AbhaStatus;
use App\Modules\Booking\Enums\ReportDelivery;
use App\Modules\Booking\Models\Doctor;
use App\Modules\Booking\Models\Patient;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * What the health locker (spec §7.11) needs from patients and doctors: who
 * a phone belongs to for OTP sign-in, a patient's own profile, the records
 * merged into it, and how to reach a person.
 *
 * Patients and doctors are organization-wide rows, and the caller here is a
 * patient or doctor proven by OTP, so lookups run with a system scope.
 */
final class PeopleDirectory
{
    private const MAX_MERGE_CHAIN = 10;

    public function __construct(private readonly CurrentScope $currentScope) {}

    /**
     * Patients registered with this phone (families share phones, spec
     * §7.3), oldest first. Merged and deleted records are left out.
     *
     * @return list<PatientProfile>
     */
    public function patientsWithPhone(string $phone): array
    {
        return $this->asSystem(fn (): array => Patient::query()
            ->where('phone', self::normalisePhone($phone))
            ->whereNull('merged_into_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Patient $patient) => $this->profileOf($patient))
            ->all());
    }

    /** The patient record that stands for this one today, following merges; null if deleted. */
    public function patient(string $patientId): ?PatientProfile
    {
        return $this->asSystem(function () use ($patientId): ?PatientProfile {
            $patient = $this->surviving($patientId);

            return $patient === null ? null : $this->profileOf($patient);
        });
    }

    /**
     * The patient and every record merged into it (spec §7.3): orders and
     * reports keep the ID they were made under, so a person's history spans
     * all of them.
     *
     * @return list<string>
     */
    public function idsMergedInto(string $patientId): array
    {
        return $this->asSystem(function () use ($patientId): array {
            $ids = [$patientId];
            $frontier = [$patientId];

            for ($hops = 0; $frontier !== [] && $hops < self::MAX_MERGE_CHAIN; $hops++) {
                $frontier = Patient::query()->withTrashed()->whereIn('merged_into_id', $frontier)->pluck('id')->all();
                $ids = [...$ids, ...$frontier];
            }

            return array_values(array_unique($ids));
        });
    }

    /**
     * The patient (following merges) with their linked ABHA; null when no
     * ABHA is linked (spec §5.7: ABHA is optional).
     */
    public function abhaIdentity(string $patientId): ?AbhaIdentity
    {
        return $this->asSystem(function () use ($patientId): ?AbhaIdentity {
            $patient = $this->surviving($patientId);

            if ($patient === null || $patient->abha_status !== AbhaStatus::Linked || $patient->abha_number === null) {
                return null;
            }

            return new AbhaIdentity(
                $patient->id,
                $patient->organization_id,
                $patient->uhid,
                $patient->name,
                $patient->gender,
                $patient->dob,
                self::yearOfBirth($patient),
                $patient->abha_number,
                $patient->abha_address,
            );
        });
    }

    /**
     * Patients who could be the person ABDM is looking up: the ABHA number
     * linked at our desk, or the mobile number ABDM verified. Merged and
     * deleted records are left out.
     *
     * @return list<AbhaDiscoveryCandidate>
     */
    public function abhaDiscoveryCandidates(?string $abhaNumber, ?string $phone): array
    {
        $hash = $abhaNumber === null ? null : Patient::abhaNumberHash($abhaNumber);
        $digits = $phone === null ? null : self::normalisePhone($phone);

        if ($hash === null && ($digits === null || $digits === '')) {
            return [];
        }

        return $this->asSystem(fn (): array => Patient::query()
            ->whereNull('merged_into_id')
            ->where(function ($query) use ($hash, $digits): void {
                if ($hash !== null) {
                    $query->orWhere('abha_number_hash', $hash);
                }

                if ($digits !== null && $digits !== '') {
                    $query->orWhere('phone', $digits);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Patient $patient) => new AbhaDiscoveryCandidate(
                $patient->id,
                $patient->organization_id,
                $patient->uhid,
                $patient->name,
                $patient->gender,
                self::yearOfBirth($patient),
                $hash !== null && $patient->abha_status === AbhaStatus::Linked && $patient->abha_number_hash === $hash,
                $digits !== null && $patient->phone === $digits,
            ))
            ->all());
    }

    /** The referring doctor who signs in with this phone: the earliest registered one. */
    public function doctorWithPhone(string $phone): ?DoctorProfile
    {
        $digits = self::normalisePhone($phone);

        return $this->asSystem(function () use ($digits): ?DoctorProfile {
            $doctor = Doctor::query()
                ->where('phone', 'like', "%{$digits}")
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->first(fn (Doctor $doctor) => self::normalisePhone((string) $doctor->phone) === $digits);

            return $doctor === null ? null : $this->doctorProfileOf($doctor);
        });
    }

    public function doctor(string $doctorId): ?DoctorProfile
    {
        return $this->asSystem(function () use ($doctorId): ?DoctorProfile {
            $doctor = Doctor::query()->find($doctorId);

            return $doctor === null ? null : $this->doctorProfileOf($doctor);
        });
    }

    /**
     * @param  list<string>  $doctorIds
     * @return array<string, DoctorProfile> by doctor ID, deleted doctors included
     */
    public function doctors(array $doctorIds): array
    {
        return $this->asSystem(fn (): array => Doctor::query()
            ->withTrashed()
            ->whereKey(array_values(array_unique($doctorIds)))
            ->get()
            ->mapWithKeys(fn (Doctor $doctor) => [$doctor->id => $this->doctorProfileOf($doctor)])
            ->all());
    }

    public function patientRecipient(string $patientId): ?NotificationRecipient
    {
        return $this->asSystem(function () use ($patientId): ?NotificationRecipient {
            $patient = Patient::query()->find($patientId);

            return $patient === null
                ? null
                : new NotificationRecipient('patient', $patient->id, $patient->organization_id, $patient->phone, $patient->email, $patient->whatsapp_opted_in_at !== null);
        });
    }

    /** The doctor, reached the way they asked to receive reports; null if they asked for none. */
    public function doctorRecipient(string $doctorId): ?NotificationRecipient
    {
        return $this->asSystem(function () use ($doctorId): ?NotificationRecipient {
            $doctor = Doctor::query()->find($doctorId);

            if ($doctor === null || $doctor->report_delivery === ReportDelivery::None) {
                return null;
            }

            return new NotificationRecipient(
                'doctor',
                $doctor->id,
                $doctor->organization_id,
                $doctor->report_delivery === ReportDelivery::Email ? null : $doctor->phone,
                $doctor->email,
                $doctor->report_delivery === ReportDelivery::Whatsapp,
            );
        });
    }

    /** The last ten digits: how patient phones are stored (spec §7.3). */
    public static function normalisePhone(string $phone): string
    {
        return substr(preg_replace('/\D/', '', $phone) ?? '', -10);
    }

    private function surviving(string $patientId): ?Patient
    {
        $patient = Patient::query()->find($patientId);

        for ($hops = 0; $patient !== null && $patient->merged_into_id !== null && $hops < self::MAX_MERGE_CHAIN; $hops++) {
            $patient = Patient::query()->find($patient->merged_into_id);
        }

        return $patient;
    }

    /** From the date of birth, else from the age given at registration. */
    private static function yearOfBirth(Patient $patient): ?int
    {
        if ($patient->dob !== null) {
            return $patient->dob->year;
        }

        return $patient->age_years === null ? null : $patient->created_at->year - $patient->age_years;
    }

    private function profileOf(Patient $patient): PatientProfile
    {
        return new PatientProfile(
            $patient->id,
            $patient->organization_id,
            $patient->uhid,
            $patient->name,
            $patient->gender,
            $patient->dob,
            $patient->ageInYears(),
            $patient->maskedPhone(),
            $patient->abha_status,
            $patient->guardian_patient_id,
        );
    }

    private function doctorProfileOf(Doctor $doctor): DoctorProfile
    {
        return new DoctorProfile($doctor->id, $doctor->organization_id, $doctor->name, $doctor->specialization, $doctor->clinic_name);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asSystem(callable $callback): mixed
    {
        return $this->currentScope->runAs(ScopeContext::system(), $callback);
    }
}
