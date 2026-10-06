<?php

namespace App\Modules\Booking;

use App\Modules\Booking\Contracts\AbdmClient;
use App\Modules\Booking\Contracts\Geocoder;
use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Events\OrderConfirmed;
use App\Modules\Booking\Infrastructure\DisabledGeocoder;
use App\Modules\Booking\Infrastructure\FakeAbdmClient;
use App\Modules\Booking\Infrastructure\FakePaymentGateway;
use App\Modules\Booking\Infrastructure\RazorpayGateway;
use App\Modules\Booking\Listeners\SendBookingConfirmation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Vendors are chosen here, never in business code (spec §3).
        $this->app->bind(PaymentGateway::class, fn () => match (config('pathology.payments.gateway')) {
            'razorpay' => new RazorpayGateway(
                (string) config('services.razorpay.key_id'),
                (string) config('services.razorpay.key_secret'),
                (string) config('services.razorpay.webhook_secret'),
            ),
            default => new FakePaymentGateway((string) config('services.razorpay.webhook_secret')),
        });

        $this->app->bind(Geocoder::class, DisabledGeocoder::class);

        // Until ABDM sandbox onboarding, the fake client stands in (spec §5.7).
        $this->app->bind(AbdmClient::class, fn () => new FakeAbdmClient((string) config('services.abdm.callback_secret')));

        // PartnerChargePolicy is bound by the Ledger module (partner ledger and wallets).
    }

    public function boot(): void
    {
        // ABHA endpoints are rate-limited (spec §10.5): OTPs cost money and invite abuse.
        RateLimiter::for('abha', fn (Request $request) => Limit::perMinute(10)->by('abha:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Event::listen(OrderConfirmed::class, SendBookingConfirmation::class);
    }
}
