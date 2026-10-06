<?php

namespace App\Modules\Lab\Domain;

use App\Modules\Catalogue\Enums\ResultType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Checks a reported value against its parameter's type and normalises it:
 * numbers may carry an analyser qualifier ("<0.5", ">1000") and are kept as
 * reported, with the number itself parsed for flags and trends; options must
 * be one of the parameter's options.
 */
final class ResultValueParser
{
    private const NUMBER = '/^(<=|>=|<|>)?\s*(-?\d{1,10}(?:\.\d{1,4})?)$/';

    /**
     * @param  list<string>|null  $options
     *
     * @throws InvalidArgumentException with a message safe to show the user
     */
    public static function parse(ResultType $type, string $value, ?array $options): ParsedResultValue
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('A value is required.');
        }

        return match ($type) {
            ResultType::Numeric, ResultType::Calculated => self::number($value),
            ResultType::Option => self::option($value, $options ?? []),
            ResultType::Text => new ParsedResultValue(mb_substr($value, 0, 255), null),
        };
    }

    /** A computed number rounded to the parameter's decimal places. */
    public static function formatCalculated(BigDecimal $value, ?int $decimalPlaces): ParsedResultValue
    {
        $rounded = $value->toScale(min($decimalPlaces ?? 2, 4), RoundingMode::HALF_UP);

        return new ParsedResultValue((string) $rounded, (string) $rounded);
    }

    private static function number(string $value): ParsedResultValue
    {
        if (preg_match(self::NUMBER, $value, $parts) !== 1) {
            throw new InvalidArgumentException('Enter a number with up to 4 decimals, optionally after <, >, <= or >=.');
        }

        return new ParsedResultValue($parts[1].$parts[2], (string) BigDecimal::of($parts[2]));
    }

    /** @param  list<string>  $options */
    private static function option(string $value, array $options): ParsedResultValue
    {
        foreach ($options as $option) {
            if (strcasecmp($option, $value) === 0) {
                return new ParsedResultValue($option, null);
            }
        }

        throw new InvalidArgumentException('Choose one of: '.implode(', ', $options).'.');
    }
}
