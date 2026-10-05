<?php

use App\Modules\Shared\Errors\ApiExceptionRenderer;
use App\Modules\Shared\Http\Middleware\AssignRequestId;
use App\Modules\Shared\Http\Middleware\EnforceIdempotency;
use App\Modules\Shared\Http\Middleware\RejectMalformedJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        $middleware->alias(['idempotent' => EnforceIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $exception, Request $request) => app(ApiExceptionRenderer::class)->render($exception, $request));
    })->create();
