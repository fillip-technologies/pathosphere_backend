<?php

use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Booking\BookingServiceProvider;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    AuthServiceProvider::class,
    CatalogueServiceProvider::class,
    BookingServiceProvider::class,
];
