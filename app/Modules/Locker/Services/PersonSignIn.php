<?php

namespace App\Modules\Locker\Services;

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Enums\OtpPurpose;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Services\IssuedTokens;
use App\Modules\Auth\Services\OtpChallenges;
use App\Modules\Auth\Services\PersonAccount;
use App\Modules\Auth\Services\PersonAccounts;
use App\Modules\Booking\Services\PatientProfile;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Domain\AccountHolder;
use App\Modules\Network\Services\NetworkDirectory;
use Carbon\CarbonImmutable;

/**
 * Patients and referring doctors sign in with a code sent to their phone
 * (spec §8.1). The answer to a code request is the same whether or not the
 * phone is known, so the endpoint cannot be used to find out who is a
 * patient; a code is only sent to phones on record.
 */
final class PersonSignIn
{
    public function __construct(
        private readonly OtpChallenges $otps,
        private readonly PersonAccounts $accounts,
        private readonly PeopleDirectory $people,
        private readonly NetworkDirectory $network,
        private readonly FamilyLinks $familyLinks,
    ) {}

    /** @return CarbonImmutable when the code (if one was sent) expires */
    public function requestCode(string $phone, AccountOwnerType $accountType): CarbonImmutable
    {
        $phone = PeopleDirectory::normalisePhone($phone);
        $organizationId = $this->organizationOfPhone($phone, $accountType);

        if ($organizationId === null) {
            return CarbonImmutable::now()->addMinutes((int) config('pathology.otp.expiry_minutes'));
        }

        return $this->otps->send(
            $phone,
            OtpPurpose::Login,
            $this->accounts->findByPhone($accountType, $phone)?->id,
            $this->network->organizationName($organizationId),
        );
    }

    public function verifyCode(string $phone, AccountOwnerType $accountType, string $code, ?string $ipAddress, ?string $deviceInfo): IssuedTokens
    {
        $phone = PeopleDirectory::normalisePhone($phone);
        $this->otps->verify($phone, OtpPurpose::Login, $code);

        $account = match ($accountType) {
            AccountOwnerType::Patient => $this->patientAccount($phone),
            AccountOwnerType::Doctor => $this->doctorAccount($phone),
            AccountOwnerType::User => throw AuthError::invalidCredentials(),
        };

        return $this->accounts->signIn($account, $ipAddress, $deviceInfo);
    }

    private function patientAccount(string $phone): PersonAccount
    {
        $patients = $this->people->patientsWithPhone($phone);

        if ($patients === []) {
            // The patient record changed phone between the code and its use.
            throw AuthError::otpInvalid();
        }

        $account = $this->accounts->findByPhone(AccountOwnerType::Patient, $phone);

        if ($account === null) {
            $holderId = AccountHolder::choose(array_map(
                fn (PatientProfile $patient) => ['id' => $patient->id, 'guardian_patient_id' => $patient->guardianPatientId],
                $patients,
            ));
            $account = $this->accounts->findOrCreate(AccountOwnerType::Patient, $phone, (string) $holderId);
        }

        $holder = $this->people->patient($account->ownerId) ?? throw AuthError::accountDisabled();

        if ($holder->id !== $account->ownerId) {
            // The holder's record was merged into another; the login follows it.
            $this->accounts->moveTo($account, $holder->id);
        }

        $this->familyLinks->linkPhoneSharers($holder, array_values(array_filter(
            $patients,
            fn (PatientProfile $patient) => $patient->organizationId === $holder->organizationId,
        )));

        return $account;
    }

    private function doctorAccount(string $phone): PersonAccount
    {
        $doctor = $this->people->doctorWithPhone($phone) ?? throw AuthError::otpInvalid();

        return $this->accounts->findOrCreate(AccountOwnerType::Doctor, $phone, $doctor->id);
    }

    private function organizationOfPhone(string $phone, AccountOwnerType $accountType): ?string
    {
        return match ($accountType) {
            AccountOwnerType::Patient => ($this->people->patientsWithPhone($phone)[0] ?? null)?->organizationId,
            AccountOwnerType::Doctor => $this->people->doctorWithPhone($phone)?->organizationId,
            AccountOwnerType::User => null,
        };
    }
}
