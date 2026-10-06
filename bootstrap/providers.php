<?php

use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Booking\BookingServiceProvider;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Lab\LabServiceProvider;
use App\Modules\Ledger\LedgerServiceProvider;
use App\Modules\Locker\LockerServiceProvider;
use App\Modules\Network\NetworkServiceProvider;
use App\Modules\Samples\SamplesServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    AuthServiceProvider::class,
    NetworkServiceProvider::class,
    CatalogueServiceProvider::class,
    BookingServiceProvider::class,
    SamplesServiceProvider::class,
    LabServiceProvider::class,
    LedgerServiceProvider::class,
    LockerServiceProvider::class,
];
