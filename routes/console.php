<?php

use App\Modules\Shared\Http\Middleware\IdempotencyRecord;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (spec §9). Each phase adds the jobs for the tables it owns.
| Prunable models live in app/Modules, so they are listed explicitly.
*/

Schedule::command('model:prune', ['--model' => [IdempotencyRecord::class]])
    ->daily()
    ->withoutOverlapping();
