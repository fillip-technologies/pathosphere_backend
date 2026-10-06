<?php

namespace App\Modules\Lab;

use App\Modules\Lab\Contracts\PdfRenderer;
use App\Modules\Lab\Contracts\QrCodeRenderer;
use App\Modules\Lab\Events\ReportReleased;
use App\Modules\Lab\Events\ResultCritical;
use App\Modules\Lab\Http\Middleware\AuthenticateInterfaceAgent;
use App\Modules\Lab\Infrastructure\ChromiumPdfRenderer;
use App\Modules\Lab\Infrastructure\FakePdfRenderer;
use App\Modules\Lab\Infrastructure\SvgQrCodeRenderer;
use App\Modules\Lab\Listeners\AlertCriticalResults;
use App\Modules\Lab\Listeners\OpenWorkForReceivedSample;
use App\Modules\Lab\Listeners\PublishReleasedReport;
use App\Modules\Lab\Listeners\WithdrawWorkForDepartedSample;
use App\Modules\Samples\Events\SampleReceived;
use App\Modules\Samples\Events\SampleRejected;
use App\Modules\Samples\Events\SampleRerouted;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Lab work, signing and reports (spec §5.5, §9 lab and report events). */
final class LabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Vendors are chosen here, never in business code (spec §3).
        $this->app->bind(PdfRenderer::class, fn () => match (config('services.pdf.renderer')) {
            'chromium' => new ChromiumPdfRenderer((string) config('services.pdf.chromium_binary')),
            default => new FakePdfRenderer,
        });
        $this->app->bind(QrCodeRenderer::class, SvgQrCodeRenderer::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('interface-agent', AuthenticateInterfaceAgent::class);

        // The public verify page is rate-limited (spec §10.5).
        RateLimiter::for('report-verify', fn (Request $request) => Limit::perMinute((int) config('pathology.lab.verify_requests_per_minute'))->by('verify:'.$request->ip()));

        Event::listen(SampleReceived::class, OpenWorkForReceivedSample::class);
        Event::listen(SampleRerouted::class, WithdrawWorkForDepartedSample::class);
        Event::listen(SampleRejected::class, WithdrawWorkForDepartedSample::class);
        Event::listen(ResultCritical::class, AlertCriticalResults::class);
        Event::listen(ReportReleased::class, PublishReleasedReport::class);
    }
}
