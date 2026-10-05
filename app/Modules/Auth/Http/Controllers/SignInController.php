<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\MfaEnrollmentRequest;
use App\Modules\Auth\Http\Requests\MfaVerificationRequest;
use App\Modules\Auth\Http\Requests\RefreshTokenRequest;
use App\Modules\Auth\Http\Requests\SignInRequest;
use App\Modules\Auth\Services\SignInResult;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Auth\Services\StaffSignIn;
use App\Modules\Auth\Services\TokenIssuer;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Staff sign-in, MFA, token refresh and sign-out (spec §8 Auth). */
final class SignInController
{
    public function __construct(private readonly StaffSignIn $signIn) {}

    public function login(SignInRequest $request): JsonResponse
    {
        $result = $this->signIn->withPassword(
            $request->validated('login_identifier'),
            $request->validated('password'),
            $request->ip(),
            $request->validated('device_info') ?? $request->userAgent(),
        );

        return new JsonResponse(['data' => $result->toArray()]);
    }

    public function startMfaEnrollment(MfaEnrollmentRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->signIn->startMfaEnrollment($request->validated('mfa_challenge_token'))]);
    }

    public function completeMfa(MfaVerificationRequest $request): JsonResponse
    {
        $tokens = $this->signIn->completeMfa($request->validated('mfa_challenge_token'), $request->validated('code'));

        return new JsonResponse(['data' => SignInResult::authenticated($tokens)->toArray()]);
    }

    public function refresh(RefreshTokenRequest $request, TokenIssuer $tokenIssuer): JsonResponse
    {
        $tokens = $tokenIssuer->rotate($request->validated('refresh_token'));

        return new JsonResponse(['data' => SignInResult::authenticated($tokens)->toArray()]);
    }

    public function logout(StaffContext $staff, TokenIssuer $tokenIssuer): Response
    {
        $session = $staff->session();

        if ($session !== null) {
            $tokenIssuer->revoke($session);
        }

        return ApiResponse::noContent();
    }
}
