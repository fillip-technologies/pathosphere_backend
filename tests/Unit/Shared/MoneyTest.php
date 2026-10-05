<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function parsedAmounts(): iterable
    {
        yield 'whole rupees' => ['1250', '1250.00'];
        yield 'one decimal' => ['1250.5', '1250.50'];
        yield 'two decimals' => ['1250.05', '1250.05'];
        yield 'negative' => ['-10.25', '-10.25'];
        yield 'zero' => ['0', '0.00'];
    }

    #[DataProvider('parsedAmounts')]
    public function test_it_parses_decimal_strings_exactly(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::fromString($input)->toDecimalString());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'three decimals' => ['10.005'];
        yield 'letters' => ['ten'];
        yield 'empty' => [''];
        yield 'comma separator' => ['1,250.00'];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_invalid_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromString($input);
    }

    public function test_arithmetic_is_exact_where_floats_drift(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 as floats.
        $sum = Money::fromString('0.10')->add(Money::fromString('0.20'));

        $this->assertSame('0.30', $sum->toDecimalString());
        $this->assertSame('-0.10', Money::fromString('0.20')->subtract(Money::fromString('0.30'))->toDecimalString());
        $this->assertSame('3750.00', Money::fromString('1250')->multiply(3)->toDecimalString());
    }

    public function test_percent_rounds_half_away_from_zero_to_the_paisa(): void
    {
        $this->assertSame('125.00', Money::fromString('1250.00')->percent('10')->toDecimalString());
        $this->assertSame('0.13', Money::fromString('1.00')->percent('12.50')->toDecimalString());
        $this->assertSame('-0.13', Money::fromString('-1.00')->percent('12.50')->toDecimalString());
        $this->assertSame('1250.00', Money::fromString('1250.00')->percent('100')->toDecimalString());
    }

    public function test_it_serializes_to_a_two_decimal_string_in_json(): void
    {
        $this->assertSame('{"amount":"1250.00"}', json_encode(['amount' => Money::fromString('1250')]));
    }

    public function test_comparisons(): void
    {
        $small = Money::fromString('10.00');
        $large = Money::fromString('20.00');

        $this->assertTrue($large->isGreaterThan($small));
        $this->assertTrue($small->isLessThan($large));
        $this->assertTrue($small->equals(Money::fromPaise(1000)));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::fromString('-1')->isNegative());
    }
}
