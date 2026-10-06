<?php

use App\Modules\Lab\Jobs\DisableExpiredSignatories;
use App\Modules\Lab\Jobs\MonitorTurnaroundTimes;
use App\Modules\Samples\Jobs\MonitorTransitDelays;
use App\Modules\Shared\Http\Middleware\IdempotencyRecord;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (spec §9). Each phase adds the jobs for the tables it owns.
| Prunable models live in app/Modules, so they are listed explicitly.
*/

Schedule::command('model:prune', ['--model' => [IdempotencyRecord::class]])
    ->daily()
    ->withoutOverlapping();

// Samples in transit past their stability limit (spec §9).
Schedule::job(new MonitorTransitDelays)
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// Tests past their turnaround time without a released report (spec §9).
Schedule::job(new MonitorTurnaroundTimes)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Signatories past their registration validity stop signing (spec §9).
Schedule::job(new DisableExpiredSignatories)
    ->dailyAt('00:15')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();
