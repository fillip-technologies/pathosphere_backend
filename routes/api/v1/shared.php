<?php

use App\Modules\Shared\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
