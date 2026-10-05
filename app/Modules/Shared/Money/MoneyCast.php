<?php

namespace App\Modules\Shared\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a decimal(12,2) column to Money and back. The database returns
 * decimals as strings, so no float is ever involved.
 *
 * @implements CastsAttributes<Money, Money|string>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromString((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->toDecimalString(),
            default => Money::fromString($value)->toDecimalString(),
        };
    }
}
