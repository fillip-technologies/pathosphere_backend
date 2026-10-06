<?php

namespace App\Modules\Locker;

use App\Modules\Lab\Events\ReportPdfViewed;
use App\Modules\Lab\Events\ReportReleased;
use App\Modules\Locker\Console\BackfillLocker;
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Contracts\DigiLocker;
use App\Modules\Locker\Http\Middleware\ResolveDoctor;
use App\Modules\Locker\Http\Middleware\ResolvePatientProfile;
use App\Modules\Locker\Infrastructure\ApiSetuDigiLocker;
use App\Modules\Locker\Infrastructure\DisabledDigiLocker;
use App\Modules\Locker\Infrastructure\FakeAbdmHipGateway;
use App\Modules\Locker\Infrastructure\FakeDigiLocker;
use App\Modules\Locker\Listeners\CopyReleasedReportToLocker;
use App\Modules\Locker\Listeners\LinkReleasedReportToAbha;
use App\Modules\Locker\Listeners\LogReportPdfView;
use App\Modules\Locker\Services\DoctorViewer;
use App\Modules\Locker\Services\PatientViewer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Patient health locker, Engine 17 (spec §7.11), patient and doctor sign-in,
 * and our labs as ABDM Health Information Providers (spec §5.7 M2).
 */
final class LockerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PatientViewer::class);
        $this->app->scoped(DoctorViewer::class);

        // Until ABDM sandbox onboarding only the fake gateway exists; it records what would be sent.
        $this->app->singleton(AbdmHipGateway::class, fn () => match (config('services.abdm.hip_gateway')) {
            'fake' => new FakeAbdmHipGateway((string) config('services.abdm.callback_secret'), (string) config('services.abdm.cm_id')),
            default => throw new LogicException('Only the fake ABDM HIP gateway exists until sandbox onboarding (ABDM_HIP_GATEWAY=fake).'),
        });

        // DigiLocker (spec §3): off until partner onboarding; the fake (one instance, so it keeps its sign-ins) for local use.
        $this->app->singleton(DigiLocker::class, fn () => match (config('services.digilocker.client')) {
            'api_setu' => new ApiSetuDigiLocker(
                (string) config('services.digilocker.base_url'),
                (string) config('services.digilocker.client_id'),
                (string) config('services.digilocker.client_secret'),
                (string) config('services.digilocker.redirect_uri'),
            ),
            'fake' => new FakeDigiLocker,
            default => new DisabledDigiLocker,
        });

        $this->commands([BackfillLocker::class]);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('patient-profile', ResolvePatientProfile::class);
        $router->aliasMiddleware('doctor-context', ResolveDoctor::class);

        // OTP endpoints (spec §10.5): per IP, and per phone so one number
        // cannot be flooded from many addresses. Guessing a code is limited
        // by its five tries; these limits stop floods and SMS cost.
        RateLimiter::for('otp-challenges', fn (Request $request): array => [
            Limit::perMinute(10)->by('otp-challenge-ip:'.$request->ip()),
            Limit::perMinute(3)->by('otp-challenge-phone:'.preg_replace('/\D/', '', (string) $request->input('phone'))),
        ]);
        RateLimiter::for('otp-verifications', fn (Request $request): array => [
            Limit::perMinute(20)->by('otp-verify-ip:'.$request->ip()),
            Limit::perMinute(10)->by('otp-verify-phone:'.preg_replace('/\D/', '', (string) $request->input('phone'))),
        ]);
        // DigiLocker calls cost a round trip to a government service; one patient needs only a few a minute.
        RateLimiter::for('digilocker', fn (Request $request) => Limit::perMinute(30)->by('digilocker:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('share-links', fn (Request $request) => Limit::perMinute((int) config('pathology.locker.share_link_requests_per_minute'))->by('share-link:'.$request->ip()));

        Event::listen(ReportReleased::class, CopyReleasedReportToLocker::class);
        Event::listen(ReportReleased::class, LinkReleasedReportToAbha::class);
        Event::listen(ReportPdfViewed::class, LogReportPdfView::class);
    }
}
