<?php

namespace App\Modules\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An INR amount held as whole paise, so arithmetic is exact (spec §6.6:
 * never float). It crosses the API as a two-decimal string, e.g. "1250.00"
 * (decision D7), and the database as decimal(12,2).
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(private readonly int $paise) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromPaise(int $paise): self
    {
        return new self($paise);
    }

    /** Accepts "1250", "1250.5", "1250.50" and "-10.00"; rejects more than two decimals. */
    public static function fromString(string $amount): self
    {
        if (preg_match('/^(-)?(\d{1,10})(?:\.(\d{1,2}))?$/', trim($amount), $parts) !== 1) {
            throw new InvalidArgumentException("'{$amount}' is not a valid money amount.");
        }

        $rupees = (int) $parts[2];
        $paise = (int) str_pad($parts[3] ?? '0', 2, '0');
        $total = $rupees * 100 + $paise;

        return new self($parts[1] === '-' ? -$total : $total);
    }

    public function paise(): int
    {
        return $this->paise;
    }

    public function add(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    public function subtract(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->paise * $quantity);
    }

    /**
     * A percentage of this amount, rounded half away from zero to the paisa.
     * The percentage is a decimal string with up to two places, e.g. "12.50".
     */
    public function percent(string $percentage): self
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim($percentage), $parts) !== 1) {
            throw new InvalidArgumentException("'{$percentage}' is not a valid percentage.");
        }

        $basisPoints = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
        $scaled = $this->paise * $basisPoints;
        $rounded = intdiv(abs($scaled) + 5000, 10000);

        return new self($scaled < 0 ? -$rounded : $rounded);
    }

    public function isZero(): bool
    {
        return $this->paise === 0;
    }

    public function isNegative(): bool
    {
        return $this->paise < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->paise > $other->paise;
    }

    public function isLessThan(self $other): bool
    {
        return $this->paise < $other->paise;
    }

    public function equals(self $other): bool
    {
        return $this->paise === $other->paise;
    }

    public function toDecimalString(): string
    {
        $sign = $this->paise < 0 ? '-' : '';
        $absolute = abs($this->paise);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }
}
