<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Http\Requests\DigiLockerAuthorizationRequest;
use App\Modules\Locker\Http\Requests\DigiLockerImportRequest;
use App\Modules\Locker\Http\Resources\DigiLockerDocumentResource;
use App\Modules\Locker\Http\Resources\DigiLockerSessionResource;
use App\Modules\Locker\Http\Resources\MedicalRecordDetailResource;
use App\Modules\Locker\Services\DigiLockerImports;
use App\Modules\Locker\Services\DigiLockerListing;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * DigiLocker pull into the health locker (spec §3, Phase 9): connect, sign in
 * at DigiLocker, list the documents issued to the patient, keep some.
 */
final class DigiLockerController
{
    public function __construct(
        private readonly DigiLockerImports $imports,
        private readonly PatientViewer $viewer,
    ) {}

    /** POST /me/digilocker-sessions: send the patient to authorization_url. */
    public function store(): Response
    {
        $session = $this->imports->start($this->viewer);

        return ApiResponse::created(DigiLockerSessionResource::make($session), "/api/v1/me/digilocker-sessions/{$session->id}");
    }

    public function show(string $sessionId): Response
    {
        return DigiLockerSessionResource::make($this->imports->find($this->viewer, $sessionId))->response();
    }

    /** POST /me/digilocker-sessions/{id}/authorization: the code and state DigiLocker returned. */
    public function authorize(DigiLockerAuthorizationRequest $request, string $sessionId): Response
    {
        $session = $this->imports->authorize($this->viewer, $sessionId, $request->validated('code'), $request->validated('state'));

        return DigiLockerSessionResource::make($session)->response();
    }

    /** GET /me/digilocker-sessions/{id}/documents: what DigiLocker holds, in one page. */
    public function documents(string $sessionId): Response
    {
        $listings = $this->imports->documents($this->viewer, $sessionId);

        return new JsonResponse([
            'data' => array_map(fn (DigiLockerListing $listing) => DigiLockerDocumentResource::make($listing)->resolve(), $listings),
            'pagination' => ['next_cursor' => null, 'limit' => count($listings)],
        ]);
    }

    /** POST /me/digilocker-sessions/{id}/imports: 201 with the new record, 200 when it was already here. */
    public function import(DigiLockerImportRequest $request, string $sessionId): Response
    {
        [$record, $added] = $this->imports->import(
            $this->viewer,
            $sessionId,
            $request->validated('uri'),
            $request->validated('category'),
            $request->validated('title'),
        );

        return $added
            ? ApiResponse::created(MedicalRecordDetailResource::make($record), "/api/v1/me/records/{$record->id}")
            : MedicalRecordDetailResource::make($record)->response();
    }
}
