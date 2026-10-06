<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\OtpPurpose;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\OtpVerification;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One-time codes by SMS (spec §10.3): 5-minute expiry, 5 attempts, a new
 * code no sooner than 30 seconds after the last, and a daily cap per phone.
 *
 * The code is sent straight to the SMS vendor and never written to the
 * notifications table; only its keyed hash is stored.
 */
final class OtpChallenges
{
    public function __construct(private readonly SmsSender $sms) {}

    /** Sends a new code to the phone. Earlier unused codes stop working. */
    public function send(string $phone, OtpPurpose $purpose, ?string $accountId, string $brandName): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $code = $this->newCode();

        $otp = DB::transaction(function () use ($phone, $purpose, $accountId, $now, $code): OtpVerification {
            $recent = OtpVerification::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->where('created_at', '>=', $now->subDay())
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->get();

            $last = $recent->first();
            $resendAfter = (int) config('pathology.otp.resend_after_seconds');

            if ($last !== null && $last->created_at->addSeconds($resendAfter)->isAfter($now)) {
                throw AuthError::otpResendTooSoon((int) ceil($now->diffInSeconds($last->created_at->addSeconds($resendAfter), absolute: true)));
            }

            if ($recent->count() >= (int) config('pathology.otp.daily_limit_per_phone')) {
                throw AuthError::otpDailyLimitReached();
            }

            // Only the newest code is valid.
            OtpVerification::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('verified_at')
                ->where('expires_at', '>', $now)
                ->update(['expires_at' => $now]);

            return OtpVerification::query()->create([
                'account_id' => $accountId,
                'phone' => $phone,
                'code_hash' => $this->hash($phone, $code),
                'purpose' => $purpose,
                'expires_at' => $now->addMinutes((int) config('pathology.otp.expiry_minutes')),
            ]);
        });

        $minutes = (int) config('pathology.otp.expiry_minutes');
        $this->sms->sendSms(
            $phone,
            "{$code} is your code to sign in to {$brandName}. It is valid for {$minutes} minutes. Do not share it with anyone.",
            config('pathology.otp.sms_template_id'),
        );

        return $otp->expires_at;
    }

    /**
     * Checks a code against the newest one sent to the phone. A wrong code
     * uses up a try; after the last try the code is dead.
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): void
    {
        $outcome = DB::transaction(function () use ($phone, $purpose, $code): ?callable {
            $otp = OtpVerification::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('verified_at')
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            if ($otp === null) {
                return fn () => AuthError::otpInvalid();
            }

            if (! $otp->expires_at->isFuture()) {
                return fn () => AuthError::otpExpired();
            }

            if ($otp->attempts >= (int) config('pathology.otp.max_attempts')) {
                return fn () => AuthError::otpAttemptsExceeded();
            }

            if (! hash_equals($otp->code_hash, $this->hash($phone, $code))) {
                // The failed try must be saved, so the error is raised after commit.
                $otp->update(['attempts' => $otp->attempts + 1]);

                return fn () => AuthError::otpInvalid();
            }

            $otp->update(['verified_at' => CarbonImmutable::now()]);

            return null;
        });

        if ($outcome !== null) {
            throw $outcome();
        }
    }

    private function newCode(): string
    {
        $length = (int) config('pathology.otp.length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /** Keyed, so a leaked table cannot be brute-forced over the small code space. */
    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', "{$phone}|{$code}", (string) config('app.key'));
    }
}
