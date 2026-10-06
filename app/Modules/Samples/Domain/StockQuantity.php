<?php

namespace App\Modules\Samples\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * A stock quantity with two decimals (decimal(12,2), spec §7.7), held as
 * hundredths so arithmetic is exact. Never float.
 */
final class StockQuantity implements Stringable
{
    private function __construct(private readonly int $hundredths) {}

    public static function fromString(string $quantity): self
    {
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', trim($quantity), $parts) !== 1) {
            throw new InvalidArgumentException("'{$quantity}' is not a valid stock quantity.");
        }

        return new self((int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0'));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->hundredths + $other->hundredths);
    }

    /** @throws InvalidArgumentException when it would go below zero */
    public function subtract(self $other): self
    {
        if ($other->hundredths > $this->hundredths) {
            throw new InvalidArgumentException('Stock cannot go below zero.');
        }

        return new self($this->hundredths - $other->hundredths);
    }

    public function isLessThan(self $other): bool
    {
        return $this->hundredths < $other->hundredths;
    }

    public function isZero(): bool
    {
        return $this->hundredths === 0;
    }

    public function __toString(): string
    {
        return sprintf('%d.%02d', intdiv($this->hundredths, 100), $this->hundredths % 100);
    }
}
