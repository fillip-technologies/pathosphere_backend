<?php

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Closure;

/**
 * Job middleware: runs the job as the system, inside one organization
 * (spec §4.3: background jobs use an explicit system scope, never none).
 */
final class WithSystemScope
{
    public function __construct(private readonly ?string $organizationId) {}

    public function handle(object $job, Closure $next): mixed
    {
        return self::run($this->organizationId, fn () => $next($job));
    }

    /**
     * For code outside the middleware pipeline, such as a job's failed() hook.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(?string $organizationId, callable $callback): mixed
    {
        return app(CurrentActor::class)->runAs(
            Actor::system($organizationId),
            fn () => app(CurrentScope::class)->runAs(ScopeContext::system($organizationId), $callback),
        );
    }
}
