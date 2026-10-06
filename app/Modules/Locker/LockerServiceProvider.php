<?php

namespace App\Modules\Locker;

use App\Modules\Lab\Events\ReportPdfViewed;
use App\Modules\Lab\Events\ReportReleased;
use App\Modules\Locker\Console\BackfillLocker;
use App\Modules\Locker\Http\Middleware\ResolveDoctor;
use App\Modules\Locker\Http\Middleware\ResolvePatientProfile;
use App\Modules\Locker\Listeners\CopyReleasedReportToLocker;
use App\Modules\Locker\Listeners\LogReportPdfView;
use App\Modules\Locker\Services\DoctorViewer;
use App\Modules\Locker\Services\PatientViewer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Patient health locker, Engine 17 (spec §7.11), and patient and doctor sign-in. */
final class LockerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PatientViewer::class);
        $this->app->scoped(DoctorViewer::class);

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
        RateLimiter::for('share-links', fn (Request $request) => Limit::perMinute((int) config('pathology.locker.share_link_requests_per_minute'))->by('share-link:'.$request->ip()));

        Event::listen(ReportReleased::class, CopyReleasedReportToLocker::class);
        Event::listen(ReportPdfViewed::class, LogReportPdfView::class);
    }
}
