<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Http\Requests\FamilyMemberRequest;
use App\Modules\Locker\Http\Resources\FamilyMemberResource;
use App\Modules\Locker\Http\Resources\PatientProfileResource;
use App\Modules\Locker\Models\FamilyMember;
use App\Modules\Locker\Services\FamilyService;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /me/family (spec §8): the account holder and the family they can
 * switch to. One resource, not a list: it is a handful of people.
 */
final class FamilyController
{
    public function __construct(
        private readonly FamilyService $family,
        private readonly PatientViewer $viewer,
    ) {}

    public function show(Request $request): Response
    {
        $members = $this->family->members($this->viewer);
        $profiles = $this->family->profiles($members);

        return new JsonResponse(['data' => [
            'account_holder' => PatientProfileResource::make($this->viewer->holder())->resolve($request),
            'viewing_patient_id' => $this->viewer->profile()->id,
            'members' => $members->map(fn (FamilyMember $member) => (new FamilyMemberResource($member, $profiles[$member->id] ?? null))->resolve($request))->values()->all(),
        ]]);
    }

    public function showMember(string $memberId): Response
    {
        return $this->present($this->family->find($this->viewer, $memberId));
    }

    public function store(FamilyMemberRequest $request): Response
    {
        $member = $this->family->addDependant($this->viewer, $request->validated());

        return ApiResponse::created(new FamilyMemberResource($member, null), "/api/v1/me/family/{$member->id}");
    }

    public function update(FamilyMemberRequest $request, string $memberId): Response
    {
        return $this->present($this->family->update($this->viewer, $memberId, $request->validated()));
    }

    public function destroy(string $memberId): Response
    {
        $this->family->remove($this->viewer, $memberId);

        return ApiResponse::noContent();
    }

    private function present(FamilyMember $member): Response
    {
        $profile = $this->family->profiles(new Collection([$member]))[$member->id] ?? null;

        return (new FamilyMemberResource($member, $profile))->response();
    }
}
