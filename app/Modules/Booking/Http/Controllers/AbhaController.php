<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Contracts\AbhaProfile;
use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Http\Resources\PatientResource;
use App\Modules\Booking\Http\Resources\ProfileShareResource;
use App\Modules\Booking\Models\AbdmRequest;
use App\Modules\Booking\Models\Patient;
use App\Modules\Booking\Services\AbhaService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ABHA at the front desk (spec §5.7). Paths use nouns (decision D4):
 * /abha/verify/request-otp → POST /abha-verifications, and so on.
 */
final class AbhaController
{
    public function __construct(
        private readonly AbhaService $abha,
        private readonly StaffContext $staff,
    ) {}

    /** POST /abha-verifications: OTP to the ABHA's mobile. */
    public function startVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'abha_number' => ['required_without:abha_address', 'nullable', 'regex:/^\d{2}-?\d{4}-?\d{4}-?\d{4}$/'],
            'abha_address' => ['required_without:abha_number', 'nullable', 'string', 'max:100'],
        ]);

        $txnId = $this->abha->startVerification($this->staff, $this->patient($data['patient_id']), $data['abha_number'] ?? $data['abha_address']);

        return new JsonResponse(['data' => ['txn_id' => $txnId]]);
    }

    /** POST /abha-verifications/{txnId}/confirmation */
    public function confirmVerification(Request $request, string $txnId): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'regex:/^\d{6}$/']]);

        return $this->linked($this->abha->confirmVerification($this->staff, $txnId, $data['otp']), $request);
    }

    /** POST /abha-qr-scans */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'uuid'], 'qr_payload' => ['required', 'string', 'max:2000']]);

        return new JsonResponse(['data' => ['txn_id' => $this->abha->startFromQr($this->staff, $this->patient($data['patient_id']), $data['qr_payload'])]]);
    }

    /** POST /abha-enrolments: Aadhaar OTP, after recording consent. */
    public function startEnrolment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'aadhaar_number' => ['required', 'regex:/^\d{12}$/'],
            'consent_version' => ['required', 'string', 'max:20'],
        ]);

        $txnId = $this->abha->startEnrolment($this->staff, $this->patient($data['patient_id']), $data['aadhaar_number'], $data['consent_version']);

        return new JsonResponse(['data' => ['txn_id' => $txnId]]);
    }

    /** POST /abha-enrolments/{txnId}/confirmation */
    public function confirmEnrolment(Request $request, string $txnId): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'regex:/^\d{6}$/'], 'mobile' => ['required', 'regex:/^[6-9]\d{9}$/']]);
        $result = $this->abha->confirmEnrolment($this->staff, $txnId, $data['otp'], $data['mobile']);

        $response = $this->linked($result, $request);
        $response->setData(['data' => [...$response->getData(true)['data'], 'address_suggestions' => $result['address_suggestions']]]);

        return $response;
    }

    /** POST /abha-enrolments/{txnId}/address */
    public function chooseAddress(Request $request, string $txnId): Response
    {
        $data = $request->validate(['abha_address' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9._]+(@[a-z]+)?$/']]);

        return PatientResource::make($this->abha->chooseAddress($this->staff, $txnId, $data['abha_address']))->response();
    }

    /** GET /patients/{patient}/abha-card */
    public function card(Patient $patient): Response
    {
        $pdf = $this->abha->card($this->staff, $patient);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"abha-card-{$patient->uhid}.pdf\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    /** DELETE /patients/{patient}/abha-link */
    public function unlink(Patient $patient): Response
    {
        $this->abha->unlink($patient);

        return ApiResponse::noContent();
    }

    /** GET /abha-profile-shares?filter[branch_id]=…: Scan and Share queue. */
    public function shareQueue(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['branch_id' => 'branch_id'])
            ->apply(AbdmRequest::query()->where('api_name', 'profile_share')->where('status', AbdmRequestStatus::Pending));

        return CursorPage::respond($query, $request, ProfileShareResource::class);
    }

    /** POST /abha-profile-shares/{share}/link */
    public function linkShare(Request $request, string $shareId): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'uuid']]);
        $share = AbdmRequest::query()->where('api_name', 'profile_share')->findOrFail($shareId);

        return $this->linked($this->abha->linkSharedProfile($this->staff, $share, $this->patient($data['patient_id'])), $request);
    }

    /** @param  array{patient: Patient, profile: AbhaProfile, mismatches: list<array<string, mixed>>}  $result */
    private function linked(array $result, Request $request): JsonResponse
    {
        return new JsonResponse(['data' => [
            'patient' => PatientResource::make($result['patient'])->resolve($request),
            'abha_profile' => $result['profile']->snapshot() + ['abha_address' => $result['profile']->abhaAddress, 'kyc_verified' => $result['profile']->kycVerified],
            'mismatches' => $result['mismatches'],
        ]]);
    }

    private function patient(string $patientId): Patient
    {
        return Patient::query()->find($patientId)
            ?? throw ValidationException::withMessages(['patient_id' => 'The selected patient does not exist.']);
    }
}
