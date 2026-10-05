<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Numbering\NumberFormat;
use LogicException;
use PHPUnit\Framework\TestCase;

final class NumberFormatTest extends TestCase
{
    public function test_it_renders_tokens_and_a_padded_sequence(): void
    {
        $number = NumberFormat::render('INV/{branch_code}/{FY}/{seq:5}', 42, ['branch_code' => 'PAT01', 'FY' => '26-27']);

        $this->assertSame('INV/PAT01/26-27/00042', $number);
    }

    public function test_an_unpadded_sequence_is_printed_as_is(): void
    {
        $this->assertSame('UH1234567', NumberFormat::render('UH{seq}', 1234567));
    }

    public function test_a_sequence_longer_than_the_padding_is_not_truncated(): void
    {
        $this->assertSame('M-123456', NumberFormat::render('M-{seq:3}', 123456));
    }

    public function test_an_unknown_token_fails_loudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('{branch_code}');

        NumberFormat::render('INV/{branch_code}/{seq}', 1);
    }
}
