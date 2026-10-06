<?php

use App\Modules\Auth\Http\Middleware\AuthenticatePerson;
use App\Modules\Auth\Http\Middleware\AuthenticateStaff;
use App\Modules\Auth\Http\Middleware\RequirePermission;
use App\Modules\Shared\Errors\ApiExceptionRenderer;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Http\Middleware\AssignRequestId;
use App\Modules\Shared\Http\Middleware\EnforceIdempotency;
use App\Modules\Shared\Http\Middleware\RejectMalformedJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->api(append: [RejectMalformedJson::class]);
        $middleware->alias([
            'idempotent' => EnforceIdempotency::class,
            'staff' => AuthenticateStaff::class,
            'person' => AuthenticatePerson::class,
            'permission' => RequirePermission::class,
        ]);

        // Scope and permissions must be known before route-model binding runs
        // its (scoped) queries, so out-of-scope IDs become 404 and missing
        // permissions 403 before any row is looked up.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuthenticateStaff::class);
        $middleware->appendToPriorityList(after: AuthenticateStaff::class, append: RequirePermission::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        // Business-rule and client errors are answered, not alerted on (spec §11).
        $exceptions->dontReport([DomainError::class]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $exception, Request $request) => app(ApiExceptionRenderer::class)->render($exception, $request));
    })->create();
