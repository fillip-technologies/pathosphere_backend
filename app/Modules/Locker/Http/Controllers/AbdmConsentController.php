<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Http\Resources\AbdmConsentResource;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Shared\Http\Pagination\CursorPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /me/abdm-consents: which health systems the patient allowed, in their
 * ABHA app, to receive records from us, for what and until when (spec §10:
 * transparency of every ABDM share). Read-only: they are granted and revoked
 * in the ABHA app.
 */
final class AbdmConsentController
{
    public function __construct(private readonly PatientViewer $viewer) {}

    public function index(Request $request): Response
    {
        $query = Consent::query()
            ->whereIn('patient_id', $this->viewer->patientIds())
            ->whereNotNull('consent_artefact_id')
            ->orderByDesc('created_at');

        return CursorPage::respondWith($query, $request, function (Collection $consents) use ($request): array {
            $references = $consents->flatMap(fn (Consent $consent) => (array) ($consent->scope['care_context_references'] ?? []))->unique()->values()->all();
            $recordIds = AbdmCareContext::query()->whereIn('care_context_reference', $references)->pluck('medical_record_id', 'care_context_reference')->all();

            return $consents->map(fn (Consent $consent) => (new AbdmConsentResource($consent, $recordIds))->resolve($request))->all();
        });
    }
}
