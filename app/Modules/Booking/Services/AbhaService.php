<?php

namespace App\Modules\Booking\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Contracts\AbdmClient;
use App\Modules\Booking\Contracts\AbhaProfile;
use App\Modules\Booking\Contracts\AbhaSession;
use App\Modules\Booking\Enums\AbdmDirection;
use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Enums\AbhaStatus;
use App\Modules\Booking\Errors\AbdmError;
use App\Modules\Booking\Models\AbdmRequest;
use App\Modules\Booking\Models\Patient;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Enums\Gender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ABHA at registration, milestone M1 (spec §5.7): verify an existing ABHA,
 * scan an ABHA QR, create one from Aadhaar, choose an address, print the
 * card, unlink, and receive "Scan and Share" profiles.
 *
 * Rules kept here: the Aadhaar number is never stored or logged; consent is
 * recorded before enrolment; demographics that differ from the desk's are
 * reported, never overwritten; every ABDM call is logged without OTPs,
 * Aadhaar or tokens; the X-Token lives only for the desk session.
 */
final class AbhaService
{
    private const FLOW_MINUTES = 10;

    private const PURPOSE_VERIFY = 'verify';

    private const PURPOSE_ENROL = 'enrol';

    public function __construct(
        private readonly AbdmClient $abdm,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function startVerification(StaffContext $staff, Patient $patient, string $abhaNumberOrAddress): string
    {
        $transaction = $this->logged($staff, $patient, 'abha_otp_request', fn () => $this->abdm->requestAbhaOtp($abhaNumberOrAddress), [
            'identifier' => self::maskIdentifier($abhaNumberOrAddress),
        ]);

        $this->rememberFlow($transaction->txnId, ['patient_id' => $patient->id, 'purpose' => self::PURPOSE_VERIFY]);

        return $transaction->txnId;
    }

    /** @return array{patient: Patient, profile: AbhaProfile, mismatches: list<array<string, mixed>>} */
    public function confirmVerification(StaffContext $staff, string $txnId, string $otp): array
    {
        $flow = $this->flow($txnId, self::PURPOSE_VERIFY);
        $patient = Patient::query()->findOrFail($flow['patient_id']);
        $session = $this->logged($staff, $patient, 'abha_otp_verify', fn () => $this->abdm->verifyAbhaOtp($txnId, $otp), [], $txnId);

        return $this->completeLink($staff, $patient, $session);
    }

    /**
     * An ABHA QR holds JSON with the ABHA number (`hidn`) and address (`hid`).
     * It identifies the ABHA; ownership is still proven by OTP.
     */
    public function startFromQr(StaffContext $staff, Patient $patient, string $qrPayload): string
    {
        $data = json_decode($qrPayload, true);
        $abhaNumber = is_array($data) ? ($data['hidn'] ?? $data['abha_number'] ?? null) : null;

        if (! is_string($abhaNumber) || strlen(preg_replace('/\D/', '', $abhaNumber) ?? '') !== 14) {
            throw AbdmError::invalidQr();
        }

        return $this->startVerification($staff, $patient, $abhaNumber);
    }

    /** Starts ABHA creation from Aadhaar, after the patient's consent (spec §5.7 rule 3). */
    public function startEnrolment(StaffContext $staff, Patient $patient, string $aadhaarNumber, string $consentVersion): string
    {
        // The Aadhaar number is passed to ABDM and dropped: not in the log, not in the cache.
        $transaction = $this->logged($staff, $patient, 'enrol_request_otp', fn () => $this->abdm->requestAadhaarOtp($aadhaarNumber), [
            'consent_version' => $consentVersion,
        ]);

        $this->rememberFlow($transaction->txnId, ['patient_id' => $patient->id, 'purpose' => self::PURPOSE_ENROL, 'consent_version' => $consentVersion]);

        return $transaction->txnId;
    }

    /** @return array{patient: Patient, profile: AbhaProfile, mismatches: list<array<string, mixed>>, address_suggestions: list<string>} */
    public function confirmEnrolment(StaffContext $staff, string $txnId, string $otp, string $mobile): array
    {
        $flow = $this->flow($txnId, self::PURPOSE_ENROL);
        $patient = Patient::query()->findOrFail($flow['patient_id']);
        $session = $this->logged($staff, $patient, 'enrol_by_aadhaar', fn () => $this->abdm->enrolByAadhaar($txnId, $otp, $mobile), [
            'consent_version' => $flow['consent_version'],
        ], $txnId);

        return $this->completeLink($staff, $patient, $session) + ['address_suggestions' => $session->addressSuggestions];
    }

    public function chooseAddress(StaffContext $staff, string $txnId, string $abhaAddress): Patient
    {
        $flow = $this->flow($txnId, self::PURPOSE_ENROL);
        $patient = Patient::query()->findOrFail($flow['patient_id']);
        $xToken = $this->xTokenFor($patient);

        $profile = $this->logged($staff, $patient, 'set_abha_address', fn () => $this->abdm->setAbhaAddress($xToken, $abhaAddress), [
            'abha_address' => $abhaAddress,
        ], $txnId);

        return DB::transaction(function () use ($patient, $profile): Patient {
            $patient->abha_address = $profile->abhaAddress;
            $patient->save();
            $this->auditLogger->recordChanges('patient.abha_address', $patient);

            return $patient;
        });
    }

    /** The ABHA card PDF; needs an ABHA verified in this desk session. */
    public function card(StaffContext $staff, Patient $patient): string
    {
        $xToken = $this->xTokenFor($patient);

        return $this->logged($staff, $patient, 'abha_card', fn () => $this->abdm->abhaCard($xToken), []);
    }

    /** Removes the link; the history stays in abdm_requests and the audit log. */
    public function unlink(Patient $patient): Patient
    {
        return DB::transaction(function () use ($patient): Patient {
            $patient->forceFill([
                'abha_number' => null,
                'abha_number_hash' => null,
                'abha_address' => null,
                'abha_status' => AbhaStatus::Unlinked,
                'abha_kyc_verified' => false,
                'abha_profile_snapshot' => null,
            ])->save();
            $this->auditLogger->recordChanges('patient.abha_unlink', $patient);
            Cache::forget($this->xTokenKey($patient));

            return $patient;
        });
    }

    /**
     * Stores a "Scan and Share" profile for the branch whose facility QR the
     * patient scanned (spec §5.7 path 4).
     *
     * @param  array<string, mixed>  $profile  name, gender, year_of_birth, abha_number, abha_address, mobile
     */
    public function receiveSharedProfile(string $branchId, string $requestId, array $profile): AbdmRequest
    {
        return AbdmRequest::query()->create([
            'branch_id' => $branchId,
            'direction' => AbdmDirection::Callback,
            'api_name' => 'profile_share',
            'request_id' => $requestId,
            'status' => AbdmRequestStatus::Pending,
            'payload_masked' => [
                'name' => $profile['name'] ?? null,
                'gender' => $profile['gender'] ?? null,
                'year_of_birth' => $profile['year_of_birth'] ?? null,
                'mobile' => $profile['mobile'] ?? null,
                'abha_address' => $profile['abha_address'] ?? null,
                // The queue must be able to link it, but the number stays encrypted at rest.
                'abha_number_encrypted' => isset($profile['abha_number']) ? Crypt::encryptString((string) $profile['abha_number']) : null,
            ],
        ]);
    }

    /**
     * Links a queued shared profile to the patient the desk registered or found.
     *
     * @return array{patient: Patient, profile: AbhaProfile, mismatches: list<array<string, mixed>>}
     */
    public function linkSharedProfile(StaffContext $staff, AbdmRequest $share, Patient $patient): array
    {
        $data = $share->payload_masked ?? [];
        $encrypted = $data['abha_number_encrypted'] ?? null;

        if ($share->status !== AbdmRequestStatus::Pending || ! is_string($encrypted)) {
            throw ValidationException::withMessages(['share' => 'This shared profile was already handled or has no ABHA number.']);
        }

        $profile = new AbhaProfile(
            Crypt::decryptString($encrypted),
            $data['abha_address'] ?? null,
            (string) ($data['name'] ?? ''),
            (string) ($data['gender'] ?? 'O'),
            isset($data['year_of_birth']) ? (int) $data['year_of_birth'] : null,
            $data['mobile'] ?? null,
            false,
        );

        return DB::transaction(function () use ($staff, $share, $patient, $profile): array {
            $result = $this->link($staff, $patient, $profile);
            $share->update(['status' => AbdmRequestStatus::Success, 'patient_id' => $patient->id]);

            return $result;
        });
    }

    /** @return array{patient: Patient, profile: AbhaProfile, mismatches: list<array<string, mixed>>} */
    private function completeLink(StaffContext $staff, Patient $patient, AbhaSession $session): array
    {
        $result = $this->link($staff, $patient, $session->profile);

        // The patient's ABDM token lives only for the desk session (spec §5.7 rule 5).
        // The flow itself stays until it expires: enrolment still needs it to set the address.
        Cache::put($this->xTokenKey($patient), Crypt::encryptString($session->xToken), now()->addMinutes(self::FLOW_MINUTES));

        return $result;
    }

    /** @return array{patient: Patient, profile: AbhaProfile, mismatches: list<array<string, mixed>>} */
    private function link(StaffContext $staff, Patient $patient, AbhaProfile $profile): array
    {
        $hash = Patient::abhaNumberHash($profile->abhaNumber);
        $holder = Patient::query()->where('abha_number_hash', $hash)->whereKeyNot($patient->id)->first();

        if ($holder !== null) {
            throw AbdmError::alreadyLinked($holder->uhid);
        }

        return DB::transaction(function () use ($staff, $patient, $profile, $hash): array {
            $patient->forceFill([
                'abha_number' => $profile->abhaNumber,
                'abha_number_hash' => $hash,
                'abha_address' => $profile->abhaAddress ?? $patient->abha_address,
                'abha_status' => AbhaStatus::Linked,
                'abha_kyc_verified' => $profile->kycVerified || $patient->abha_kyc_verified,
                'abha_linked_at' => now(),
                'abha_linked_by' => $staff->user()->id,
                'abha_profile_snapshot' => $profile->snapshot(),
            ])->save();
            $this->auditLogger->recordChanges('patient.abha_link', $patient);

            return ['patient' => $patient, 'profile' => $profile, 'mismatches' => self::mismatches($patient, $profile)];
        });
    }

    /**
     * Where ABDM's demographics differ from what the desk recorded. Staff
     * decide which is right; nothing is overwritten (spec §5.7 rule 4).
     *
     * @return list<array<string, mixed>>
     */
    private static function mismatches(Patient $patient, AbhaProfile $profile): array
    {
        $abhaGender = match ($profile->gender) {
            'M' => Gender::Male,
            'F' => Gender::Female,
            default => Gender::Other,
        };
        $patientBirthYear = $patient->dob->year ?? ($patient->age_years === null ? null : (int) now()->year - $patient->age_years);

        $differences = [];

        if (mb_strtolower(trim($patient->name)) !== mb_strtolower(trim($profile->name))) {
            $differences[] = ['field' => 'name', 'patient_value' => $patient->name, 'abha_value' => $profile->name];
        }

        if ($patient->gender !== $abhaGender) {
            $differences[] = ['field' => 'gender', 'patient_value' => $patient->gender->value, 'abha_value' => $abhaGender->value];
        }

        // An age typed at the desk is approximate: allow a year either way.
        if ($profile->yearOfBirth !== null && $patientBirthYear !== null && abs($patientBirthYear - $profile->yearOfBirth) > 1) {
            $differences[] = ['field' => 'year_of_birth', 'patient_value' => $patientBirthYear, 'abha_value' => $profile->yearOfBirth];
        }

        return $differences;
    }

    /**
     * Calls ABDM and writes one abdm_requests row, success or failure.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @param  array<string, mixed>  $safePayload  only fields that are safe to keep
     * @return T
     */
    private function logged(StaffContext $staff, Patient $patient, string $apiName, callable $call, array $safePayload, ?string $txnId = null): mixed
    {
        $log = fn (AbdmRequestStatus $status, ?string $errorCode, ?string $resultTxnId) => AbdmRequest::query()->create([
            'patient_id' => $patient->id,
            'branch_id' => $staff->user()->branch_id,
            'direction' => AbdmDirection::Outbound,
            'api_name' => $apiName,
            'request_id' => (string) Str::uuid(),
            'txn_id' => $resultTxnId ?? $txnId,
            'status' => $status,
            'error_code' => $errorCode,
            'payload_masked' => $safePayload === [] ? null : $safePayload,
            'requested_by' => $staff->user()->id,
        ]);

        try {
            $result = $call();
        } catch (AbdmError $error) {
            $log(AbdmRequestStatus::Failed, $error->errorCode, null);

            throw $error;
        }

        $log(AbdmRequestStatus::Success, null, is_object($result) && property_exists($result, 'txnId') ? $result->txnId : null);

        return $result;
    }

    /** @param  array<string, string>  $flow */
    private function rememberFlow(string $txnId, array $flow): void
    {
        Cache::put($this->flowKey($txnId), Crypt::encryptString((string) json_encode($flow)), now()->addMinutes(self::FLOW_MINUTES));
    }

    /** @return array<string, string> */
    private function flow(string $txnId, string $purpose): array
    {
        $stored = Cache::get($this->flowKey($txnId));
        $flow = is_string($stored) ? json_decode(Crypt::decryptString($stored), true) : null;

        if (! is_array($flow) || ($flow['purpose'] ?? null) !== $purpose) {
            throw AbdmError::sessionExpired();
        }

        return $flow;
    }

    private function xTokenFor(Patient $patient): string
    {
        $stored = Cache::get($this->xTokenKey($patient));

        return is_string($stored) ? Crypt::decryptString($stored) : throw AbdmError::sessionExpired();
    }

    private function flowKey(string $txnId): string
    {
        return 'abha_flow:'.hash('sha256', $txnId);
    }

    private function xTokenKey(Patient $patient): string
    {
        return 'abha_xtoken:'.$patient->id;
    }

    private static function maskIdentifier(string $identifier): string
    {
        return str_contains($identifier, '@') ? $identifier : '**-****-****-'.substr(preg_replace('/\D/', '', $identifier) ?? '', -4);
    }
}
