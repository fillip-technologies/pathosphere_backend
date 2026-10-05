<?php

use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    AuthServiceProvider::class,
];
