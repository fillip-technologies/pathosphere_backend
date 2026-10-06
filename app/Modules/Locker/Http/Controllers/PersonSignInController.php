<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Services\SignInResult;
use App\Modules\Locker\Http\Requests\OtpChallengeRequest;
use App\Modules\Locker\Http\Requests\OtpVerificationRequest;
use App\Modules\Locker\Services\PersonSignIn;
use Illuminate\Http\JsonResponse;

/**
 * Patient and doctor sign-in by SMS code (spec §8 Auth). Named as nouns
 * (decision D4): POST /auth/otp-challenges, POST /auth/otp-verifications.
 */
final class PersonSignInController
{
    public function __construct(private readonly PersonSignIn $signIn) {}

    /** 202 whether or not the phone is registered: the answer reveals nothing. */
    public function challenge(OtpChallengeRequest $request): JsonResponse
    {
        $expiresAt = $this->signIn->requestCode($request->validated('phone'), AccountOwnerType::from($request->validated('account_type')));

        return new JsonResponse(['data' => [
            'expires_at' => $expiresAt->utc()->toIso8601ZuluString(),
            'resend_after_seconds' => (int) config('pathology.otp.resend_after_seconds'),
        ]], 202);
    }

    public function verify(OtpVerificationRequest $request): JsonResponse
    {
        $tokens = $this->signIn->verifyCode(
            $request->validated('phone'),
            AccountOwnerType::from($request->validated('account_type')),
            $request->validated('code'),
            $request->ip(),
            $request->validated('device_info') ?? $request->userAgent(),
        );

        return new JsonResponse(['data' => SignInResult::authenticated($tokens)->toArray()]);
    }
}
