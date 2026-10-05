<?php

namespace App\Modules\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps patient and secret data out of logs (spec §10, §11): no names, phone
 * numbers, Aadhaar or ABHA numbers, OTPs, tokens or passwords. Sensitive keys
 * are replaced wherever they appear in context, and stray phone/Aadhaar-like
 * digit runs in the message are masked.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'password_confirmation', 'pin',
        'otp', 'otp_code', 'code_hash', 'mfa_secret', 'totp',
        'token', 'access_token', 'refresh_token', 'api_key', 'secret', 'authorization', 'x-token',
        'phone', 'mobile', 'email', 'destination',
        'name', 'patient_name', 'owner_name', 'contact_name', 'guardian_name',
        'aadhaar', 'aadhaar_number', 'abha_number', 'abha_address',
        'bank_account_no', 'address',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->maskDigitRuns($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $values[$key] = $this->maskDigitRuns($value);
            }
        }

        return $values;
    }

    /** Masks 10–14 digit runs (phone, Aadhaar, ABHA) but keeps the last 4 digits. */
    private function maskDigitRuns(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\d-])(?:\d[ -]?){9,13}\d(?![\d-])/',
            fn (array $match): string => '******'.substr(preg_replace('/\D/', '', $match[0]) ?? '', -4),
            $text,
        ) ?? $text;
    }
}
