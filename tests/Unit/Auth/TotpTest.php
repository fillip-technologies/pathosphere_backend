<?php

namespace Tests\Unit\Auth;

use App\Modules\Auth\Domain\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** ASCII "12345678901234567890", the RFC 6238 SHA-1 test key, in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * RFC 6238 appendix B values, truncated to the 6 digits authenticator apps show.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function rfcVectors(): iterable
    {
        yield 't=59' => [59, '287082'];
        yield 't=1111111109' => [1111111109, '081804'];
        yield 't=1234567890' => [1234567890, '005924'];
        yield 't=2000000000' => [2000000000, '279037'];
    }

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc_test_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::codeAt(self::RFC_SECRET, $time));
    }

    public function test_one_period_of_clock_drift_is_tolerated_but_not_two(): void
    {
        $now = 1_800_000_000;
        $previous = Totp::codeAt(self::RFC_SECRET, $now - 30);
        $twoBack = Totp::codeAt(self::RFC_SECRET, $now - 60);

        $this->assertTrue(Totp::verify(self::RFC_SECRET, $previous, $now));
        $this->assertFalse(Totp::verify(self::RFC_SECRET, $twoBack, $now));
    }

    public function test_malformed_codes_are_rejected(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_SECRET, '28708', 59));
        $this->assertFalse(Totp::verify(self::RFC_SECRET, 'abcdef', 59));
    }

    public function test_generated_secrets_round_trip(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret, 1_800_000_000), 1_800_000_000));
    }
}
