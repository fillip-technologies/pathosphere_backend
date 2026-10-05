<?php

namespace App\Modules\Samples;

use App\Modules\Booking\Events\OrderConfirmed;
use App\Modules\Samples\Events\ManifestDispatched;
use App\Modules\Samples\Events\ManifestReceived;
use App\Modules\Samples\Events\SampleCollected;
use App\Modules\Samples\Events\SampleRejected;
use App\Modules\Samples\Events\SampleRerouted;
use App\Modules\Samples\Listeners\BagSampleForItsLab;
use App\Modules\Samples\Listeners\NotifyReceivingLab;
use App\Modules\Samples\Listeners\NotifySampleRejection;
use App\Modules\Samples\Listeners\PrepareSamplesForConfirmedOrder;
use App\Modules\Samples\Listeners\ScheduleMissingSampleCheck;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Samples and logistics (spec §5.4, §9 sample events). */
final class SamplesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderConfirmed::class, PrepareSamplesForConfirmedOrder::class);
        Event::listen(SampleCollected::class, BagSampleForItsLab::class);
        Event::listen(SampleRerouted::class, BagSampleForItsLab::class);
        Event::listen(SampleRejected::class, NotifySampleRejection::class);
        Event::listen(ManifestDispatched::class, NotifyReceivingLab::class);
        Event::listen(ManifestReceived::class, ScheduleMissingSampleCheck::class);
    }
}
