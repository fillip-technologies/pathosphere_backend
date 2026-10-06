<?php

namespace App\Modules\Lab\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\DivisionByZeroException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Evaluates a calculated parameter's formula (spec: e.g. LDL = TC - HDL -
 * (TG / 5)) with exact decimals. Only numbers, parameter codes, + - * /,
 * unary minus and parentheses are understood; nothing is ever passed to
 * eval(), so a formula cannot run code.
 */
final class FormulaEvaluator
{
    private const DIVISION_SCALE = 10;

    /** @var list<array{type: string, value: string}> */
    private array $tokens;

    private int $position = 0;

    /** @param  array<string, string|null>  $values  parameter code => decimal string */
    private function __construct(string $formula, private readonly array $values)
    {
        $this->tokens = self::tokenize($formula);
    }

    /**
     * The formula's value, or null when an input has no numeric value yet or
     * the formula divides by zero.
     *
     * @param  array<string, string|null>  $valuesByCode  numeric values keyed by upper-case parameter code
     *
     * @throws InvalidArgumentException when the formula is malformed
     */
    public static function evaluate(string $formula, array $valuesByCode): ?BigDecimal
    {
        $evaluator = new self($formula, array_change_key_case($valuesByCode, CASE_UPPER));

        try {
            $result = $evaluator->expression();
        } catch (DivisionByZeroException) {
            return null;
        }

        if ($evaluator->position !== count($evaluator->tokens)) {
            throw new InvalidArgumentException("Unexpected '{$evaluator->tokens[$evaluator->position]['value']}' in formula.");
        }

        return $result;
    }

    /**
     * Parameter codes the formula reads.
     *
     * @return list<string> upper-case codes
     */
    public static function inputs(string $formula): array
    {
        $codes = array_map(
            fn (array $token): string => $token['value'],
            array_filter(self::tokenize($formula), fn (array $token): bool => $token['type'] === 'name'),
        );

        return array_values(array_unique($codes));
    }

    /** expression := term (('+' | '-') term)* */
    private function expression(): ?BigDecimal
    {
        $value = $this->term();

        while ($this->peekOperator(['+', '-'])) {
            $operator = $this->next()['value'];
            $right = $this->term();
            $value = $value === null || $right === null ? null : ($operator === '+' ? $value->plus($right) : $value->minus($right));
        }

        return $value;
    }

    /** term := factor (('*' | '/') factor)* */
    private function term(): ?BigDecimal
    {
        $value = $this->factor();

        while ($this->peekOperator(['*', '/'])) {
            $operator = $this->next()['value'];
            $right = $this->factor();

            if ($value === null || $right === null) {
                $value = null;

                continue;
            }

            $value = $operator === '*'
                ? $value->multipliedBy($right)
                : $value->dividedBy($right, self::DIVISION_SCALE, RoundingMode::HALF_UP);
        }

        return $value;
    }

    /** factor := number | name | '-' factor | '(' expression ')' */
    private function factor(): ?BigDecimal
    {
        $token = $this->next();

        return match (true) {
            $token['type'] === 'number' => BigDecimal::of($token['value']),
            $token['type'] === 'name' => $this->input($token['value']),
            $token['value'] === '-' => $this->factor()?->negated(),
            $token['value'] === '(' => $this->parenthesised(),
            default => throw new InvalidArgumentException("Unexpected '{$token['value']}' in formula."),
        };
    }

    private function parenthesised(): ?BigDecimal
    {
        $value = $this->expression();

        if ($this->next()['value'] !== ')') {
            throw new InvalidArgumentException('Unbalanced parentheses in formula.');
        }

        return $value;
    }

    private function input(string $code): ?BigDecimal
    {
        if (! array_key_exists($code, $this->values)) {
            throw new InvalidArgumentException("Unknown parameter {$code} in formula.");
        }

        $value = $this->values[$code];

        return $value === null ? null : BigDecimal::of($value);
    }

    /** @param  list<string>  $operators */
    private function peekOperator(array $operators): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token['type'] === 'operator' && in_array($token['value'], $operators, true);
    }

    /** @return array{type: string, value: string} */
    private function next(): array
    {
        return $this->tokens[$this->position++] ?? throw new InvalidArgumentException('The formula ends too early.');
    }

    /** @return list<array{type: string, value: string}> */
    private static function tokenize(string $formula): array
    {
        if (preg_match_all('/\s*(?:(\d+(?:\.\d+)?)|([A-Za-z][A-Za-z0-9_]*)|([-+*\/()])|(\S))/', $formula, $matches, PREG_SET_ORDER) === false) {
            throw new InvalidArgumentException('The formula cannot be read.');
        }

        return array_map(fn (array $match): array => match (true) {
            ($match[1] ?? '') !== '' => ['type' => 'number', 'value' => $match[1]],
            ($match[2] ?? '') !== '' => ['type' => 'name', 'value' => strtoupper($match[2])],
            ($match[3] ?? '') !== '' => ['type' => in_array($match[3], ['(', ')'], true) ? 'paren' : 'operator', 'value' => $match[3]],
            default => throw new InvalidArgumentException("Unexpected '{$match[4]}' in formula."),
        }, $matches);
    }
}
