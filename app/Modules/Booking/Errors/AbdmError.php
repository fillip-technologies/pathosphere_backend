<?php

namespace App\Modules\Booking\Errors;

use App\Modules\Shared\Errors\DomainError;

/** ABDM failures. ABHA is optional: none of these block a booking (spec §5.7). */
final class AbdmError extends DomainError
{
    public static function otpInvalid(): self
    {
        return new self('ABDM_OTP_INVALID', 'The OTP is incorrect or has expired.', 422, [['field' => 'otp']]);
    }

    public static function notFound(): self
    {
        return new self('ABHA_NOT_FOUND', 'No ABHA exists for that number or address.', 422);
    }

    public static function unavailable(): self
    {
        return new self('ABDM_UNAVAILABLE', 'ABDM is not responding. Register the patient without ABHA and link it later.', 503);
    }

    public static function sessionExpired(): self
    {
        return new self('ABHA_SESSION_EXPIRED', 'The ABHA session has expired. Verify the ABHA again.', 409);
    }

    public static function alreadyLinked(string $uhid): self
    {
        return new self('ABHA_ALREADY_LINKED', "This ABHA is already linked to patient {$uhid}.", 409);
    }

    public static function invalidQr(): self
    {
        return new self('ABHA_QR_INVALID', 'The scanned code is not an ABHA QR code.', 422, [['field' => 'qr_payload']]);
    }

    public static function invalidCallback(): self
    {
        return new self('INVALID_SIGNATURE', 'The ABDM callback could not be verified.', 401);
    }
}
