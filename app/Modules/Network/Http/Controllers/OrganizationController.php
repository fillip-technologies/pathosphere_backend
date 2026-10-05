<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Network\Http\Resources\OrganizationResource;
use App\Modules\Network\Models\Organization;
use App\Modules\Network\Services\OrganizationService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use Symfony\Component\HttpFoundation\Response;

/** The signed-in staff member's organization: GET and PATCH /organization. */
final class OrganizationController
{
    public function __construct(private readonly StaffContext $staff) {}

    public function show(): Response
    {
        $organization = $this->current();

        return EntityTag::attach(OrganizationResource::make($organization)->response(), $organization);
    }

    public function update(UpdateOrganizationRequest $request, OrganizationService $organizations): Response
    {
        $organization = $this->current();
        EntityTag::assertIfMatch($request, $organization);

        $organization = $organizations->update($organization, $request->validated());

        return EntityTag::attach(OrganizationResource::make($organization)->response(), $organization);
    }

    private function current(): Organization
    {
        return Organization::query()->findOrFail($this->staff->user()->organization_id);
    }
}
