<?php

namespace Tests\Unit\Samples;

use App\Modules\Samples\Domain\StockQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StockQuantityTest extends TestCase
{
    public function test_quantities_add_and_subtract_exactly(): void
    {
        $onShelf = StockQuantity::fromString('12.5');

        $this->assertSame('12.50', (string) $onShelf);
        $this->assertSame('7.40', (string) $onShelf->subtract(StockQuantity::fromString('5.1')));
        $this->assertSame('12.60', (string) $onShelf->add(StockQuantity::fromString('0.1')));
        $this->assertTrue(StockQuantity::fromString('9.99')->isLessThan(StockQuantity::fromString('10')));
    }

    public function test_stock_never_goes_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockQuantity::fromString('1')->subtract(StockQuantity::fromString('1.01'));
    }
}
