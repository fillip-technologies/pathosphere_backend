<?php

namespace App\Modules\Booking\Contracts;

/**
 * ABDM boundary for ABHA milestone M1 (spec §5.7). The real adapter encrypts
 * Aadhaar numbers, OTPs and mobiles with ABDM's public key and caches the
 * gateway token; it is built after sandbox onboarding. Until then the fake
 * client stands in.
 *
 * Failures throw AbdmError.
 */
interface AbdmClient
{
    /** Sends an OTP to the mobile linked to an ABHA number or address. */
    public function requestAbhaOtp(string $abhaNumberOrAddress): AbdmTransaction;

    public function verifyAbhaOtp(string $txnId, string $otp): AbhaSession;

    /** The Aadhaar number goes to ABDM encrypted and is never kept (spec §5.7 rule 1). */
    public function requestAadhaarOtp(string $aadhaarNumber): AbdmTransaction;

    /** Creates an ABHA from Aadhaar e-KYC and suggests ABHA addresses. */
    public function enrolByAadhaar(string $txnId, string $otp, string $mobile): AbhaSession;

    public function setAbhaAddress(string $xToken, string $abhaAddress): AbhaProfile;

    /** The printable ABHA card, as PDF bytes. */
    public function abhaCard(string $xToken): string;

    /**
     * Checks that a callback really comes from the ABDM gateway.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyCallback(array $headers, string $rawBody): bool;
}
