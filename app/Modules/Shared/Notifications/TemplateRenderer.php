<?php

namespace App\Modules\Shared\Notifications;

use LogicException;

/**
 * Fills `{{variable}}` placeholders. A missing variable is a bug in the
 * caller, so it fails loudly rather than sending "Dear {{patient_name}}".
 */
final class TemplateRenderer
{
    /** @param  array<string, string>  $variables */
    public static function render(string $template, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/', function (array $match) use ($variables): string {
            if (! array_key_exists($match[1], $variables)) {
                throw new LogicException("Notification variable {{{$match[1]}}} was not provided.");
            }

            return $variables[$match[1]];
        }, $template) ?? throw new LogicException('Invalid notification template.');
    }
}
