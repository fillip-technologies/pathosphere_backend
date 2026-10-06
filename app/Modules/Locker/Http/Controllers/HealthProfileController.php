<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Http\Requests\HealthProfileRequest;
use App\Modules\Locker\Http\Resources\HealthProfileResource;
use App\Modules\Locker\Services\HealthProfileService;
use App\Modules\Locker\Services\PatientViewer;
use Symfony\Component\HttpFoundation\Response;

/** GET / PUT /me/health-profile: blood group, allergies, conditions, summary. */
final class HealthProfileController
{
    public function __construct(
        private readonly HealthProfileService $profiles,
        private readonly PatientViewer $viewer,
    ) {}

    public function show(): Response
    {
        return HealthProfileResource::make($this->profiles->get($this->viewer))->response();
    }

    public function update(HealthProfileRequest $request): Response
    {
        // A singleton that always exists from the client's view: PUT answers 200 even the first time.
        return HealthProfileResource::make($this->profiles->replace($this->viewer, $request->validated()))->response()->setStatusCode(200);
    }
}
