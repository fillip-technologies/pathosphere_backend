<?php

namespace App\Modules\Lab\Services;

/** One reported value for one parameter, as typed or as sent by an analyser. */
final class ResultInput
{
    public function __construct(
        public readonly string $parameterCode,
        public readonly string $value,
        public readonly ?string $comment = null,
    ) {}
}
