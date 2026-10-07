<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\MfaCodeRequest;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Auth\Services\StaffMfa;
use Illuminate\Http\JsonResponse;

/** The signed-in staff member switches their own MFA on or off (spec §10.4). */
final class MfaController
{
    public function __construct(private readonly StaffMfa $mfa) {}

    public function start(StaffContext $staff): JsonResponse
    {
        return new JsonResponse(['data' => $this->mfa->start($staff->user())]);
    }

    public function confirm(MfaCodeRequest $request, StaffContext $staff): JsonResponse
    {
        $this->mfa->confirm($staff->user(), $request->validated('code'));

        return new JsonResponse(['data' => ['mfa_enabled' => true]]);
    }

    public function disable(MfaCodeRequest $request, StaffContext $staff): JsonResponse
    {
        $this->mfa->disable($staff->user(), $request->validated('code'));

        return new JsonResponse(['data' => ['mfa_enabled' => false]]);
    }
}
