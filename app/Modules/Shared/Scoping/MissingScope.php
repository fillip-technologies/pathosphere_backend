<?php

namespace App\Modules\Shared\Scoping;

use LogicException;

/**
 * A scoped table was queried with no scope set. This is a programming error:
 * HTTP requests get their scope from authentication, and jobs, seeders and
 * console commands must run inside CurrentScope::runAs(ScopeContext::system()).
 */
final class MissingScope extends LogicException
{
    public static function forModel(string $modelClass): self
    {
        return new self("{$modelClass} was queried without a scope. Run the code inside CurrentScope::runAs().");
    }
}
