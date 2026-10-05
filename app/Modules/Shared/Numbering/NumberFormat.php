<?php

namespace App\Modules\Shared\Numbering;

use LogicException;

/**
 * Renders a document-number template such as "INV/{branch_code}/{FY}/{seq:5}".
 * `{seq:N}` pads the sequence to N digits. An unknown token is a configuration
 * mistake, so it fails loudly instead of printing a broken number.
 */
final class NumberFormat
{
    /**
     * @param  array<string, string>  $tokens
     */
    public static function render(string $template, int $sequence, array $tokens = []): string
    {
        return preg_replace_callback('/\{([A-Za-z_]+)(?::(\d{1,2}))?\}/', function (array $match) use ($sequence, $tokens): string {
            [, $name] = $match;
            $width = isset($match[2]) ? (int) $match[2] : 0;

            if ($name === 'seq') {
                return str_pad((string) $sequence, $width, '0', STR_PAD_LEFT);
            }

            if (! array_key_exists($name, $tokens)) {
                throw new LogicException("Number format token {{$name}} has no value.");
            }

            return $tokens[$name];
        }, $template) ?? throw new LogicException("Invalid number format '{$template}'.");
    }
}
