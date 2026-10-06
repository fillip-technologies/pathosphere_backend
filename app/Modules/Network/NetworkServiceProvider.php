<?php

namespace App\Modules\Network;

use App\Modules\Network\Contracts\ESignProvider;
use App\Modules\Network\Contracts\KycVerifier;
use App\Modules\Network\Infrastructure\FakeESignProvider;
use App\Modules\Network\Infrastructure\FakeKycVerifier;
use Illuminate\Support\ServiceProvider;

/** Network and franchise onboarding (spec §5.1). */
final class NetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Vendors are chosen here, never in business code (spec §3). Until the
        // KYC and e-sign vendors are contracted, the fakes stand in.
        $this->app->bind(KycVerifier::class, FakeKycVerifier::class);
        $this->app->bind(ESignProvider::class, fn () => new FakeESignProvider((string) config('services.esign.webhook_secret')));
    }
}
