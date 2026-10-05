<?php

namespace App\Modules\Shared\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * Logging channel tap: one JSON object per line, with sensitive data
 * redacted. Laravel's Context (request_id, user_id, branch_id) is added to
 * each record automatically.
 */
final class UseStructuredLogs
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter);
            }

            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new RedactSensitiveData);
            }
        }
    }
}
