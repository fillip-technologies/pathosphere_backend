<?php

namespace App\Modules\Auth\Domain;

use InvalidArgumentException;

/**
 * Time-based one-time passwords (RFC 6238) for staff MFA, compatible with
 * Google Authenticator, Microsoft Authenticator and similar apps.
 */
final class Totp
{
    private const PERIOD_SECONDS = 30;

    private const DIGITS = 6;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random 160-bit secret, base32-encoded for authenticator apps. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function codeAt(string $base32Secret, int $unixTime): string
    {
        $counter = intdiv($unixTime, self::PERIOD_SECONDS);
        $hash = hash_hmac('sha1', pack('J', $counter), self::base32Decode($base32Secret), true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Accepts the code for the current period and one period either side, to
     * allow for clock drift between server and phone.
     */
    public static function verify(string $base32Secret, string $code, int $unixTime): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        foreach ([-1, 0, 1] as $drift) {
            if (hash_equals(self::codeAt($base32Secret, $unixTime + $drift * self::PERIOD_SECONDS), $code)) {
                return true;
            }
        }

        return false;
    }

    /** otpauth:// URI for showing as a QR code during enrolment. */
    public static function provisioningUri(string $base32Secret, string $accountLabel, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            $base32Secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD_SECONDS,
        );
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));
        $bits = '';

        foreach (str_split($base32) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);

            if ($value === false) {
                throw new InvalidArgumentException('The MFA secret is not valid base32.');
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr((int) bindec($byte));
            }
        }

        return $bytes;
    }
}
