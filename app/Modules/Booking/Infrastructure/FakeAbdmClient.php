<?php

namespace App\Modules\Booking\Infrastructure;

use App\Modules\Booking\Contracts\AbdmClient;
use App\Modules\Booking\Contracts\AbdmTransaction;
use App\Modules\Booking\Contracts\AbhaProfile;
use App\Modules\Booking\Contracts\AbhaSession;
use App\Modules\Booking\Errors\AbdmError;
use Illuminate\Support\Str;

/**
 * Local and test stand-in for ABDM until sandbox onboarding (spec §5.7). The
 * OTP is always 123456; any well-formed ABHA number exists except one ending
 * in 0000; callbacks are signed with HMAC-SHA256 of the body using
 * config('services.abdm.callback_secret').
 */
final class FakeAbdmClient implements AbdmClient
{
    public const VALID_OTP = '123456';

    public function __construct(private readonly string $callbackSecret) {}

    public function requestAbhaOtp(string $abhaNumberOrAddress): AbdmTransaction
    {
        if (str_ends_with(preg_replace('/\D/', '', $abhaNumberOrAddress) ?? '', '0000')) {
            throw AbdmError::notFound();
        }

        return new AbdmTransaction('txn-'.base64_encode($abhaNumberOrAddress), (string) Str::uuid());
    }

    public function verifyAbhaOtp(string $txnId, string $otp): AbhaSession
    {
        $this->assertOtp($otp);
        $identifier = base64_decode(Str::after($txnId, 'txn-'));
        $isAddress = str_contains($identifier, '@');

        return new AbhaSession(
            $this->profile($isAddress ? '91-1234-5678-9012' : $identifier, $isAddress ? $identifier : null, kycVerified: false),
            'xtoken-'.Str::random(20),
            (string) Str::uuid(),
        );
    }

    public function requestAadhaarOtp(string $aadhaarNumber): AbdmTransaction
    {
        return new AbdmTransaction('enrol-'.Str::random(12), (string) Str::uuid());
    }

    public function enrolByAadhaar(string $txnId, string $otp, string $mobile): AbhaSession
    {
        $this->assertOtp($otp);
        $number = sprintf('91-%04d-%04d-%04d', random_int(1000, 9999), random_int(1000, 9999), random_int(1001, 9999));

        return new AbhaSession(
            $this->profile($number, null, kycVerified: true, mobile: $mobile),
            'xtoken-'.Str::random(20),
            (string) Str::uuid(),
            ['asha.kumari', 'asha.k1990', 'kumari.asha'],
        );
    }

    public function setAbhaAddress(string $xToken, string $abhaAddress): AbhaProfile
    {
        return $this->profile('91-1234-5678-9012', $abhaAddress, kycVerified: true);
    }

    public function abhaCard(string $xToken): string
    {
        return "%PDF-1.4\n% Fake ABHA card\n%%EOF\n";
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        $signature = $headers['x-abdm-signature'] ?? '';

        return $this->callbackSecret !== '' && hash_equals(hash_hmac('sha256', $rawBody, $this->callbackSecret), $signature);
    }

    private function assertOtp(string $otp): void
    {
        if ($otp !== self::VALID_OTP) {
            throw AbdmError::otpInvalid();
        }
    }

    private function profile(string $abhaNumber, ?string $abhaAddress, bool $kycVerified, ?string $mobile = '9876501234'): AbhaProfile
    {
        return new AbhaProfile($abhaNumber, $abhaAddress, 'Asha Kumari', 'F', 1990, $mobile, $kycVerified);
    }
}
