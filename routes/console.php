<?php

use App\Modules\Dashboards\Jobs\BuildDailyMetrics;
use App\Modules\Lab\Jobs\AlertExpiringSignatories;
use App\Modules\Lab\Jobs\DisableExpiredSignatories;
use App\Modules\Lab\Jobs\MonitorTurnaroundTimes;
use App\Modules\Ledger\Jobs\AlertLowWalletBalances;
use App\Modules\Ledger\Jobs\BuildSettlements;
use App\Modules\Ledger\Jobs\HoldOverduePartners;
use App\Modules\Ledger\Jobs\RemindB2bDues;
use App\Modules\Locker\Jobs\ExpireRecordShares;
use App\Modules\Locker\Jobs\RetryCareContextLinks;
use App\Modules\Locker\Jobs\SendDueReminders;
use App\Modules\Network\Jobs\AlertExpiringNetworkPapers;
use App\Modules\Network\Jobs\ExpireEndedAgreements;
use App\Modules\Samples\Jobs\AlertExpiringStock;
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

// Partner money (spec §9). Settlements are built after the cycle closes, at night.
Schedule::job(new BuildSettlements)
    ->dailyAt('02:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

Schedule::job(new AlertLowWalletBalances)
    ->hourly()
    ->withoutOverlapping();

// Off unless LEDGER_AUTO_HOLD is set (spec §12: block only with HQ Finance's approval).
Schedule::job(new HoldOverduePartners)
    ->dailyAt('03:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

Schedule::job(new RemindB2bDues)
    ->dailyAt('10:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

// Franchise agreements, papers, NABL, signatories and stock nearing expiry (spec §9).
Schedule::job(new ExpireEndedAgreements)
    ->dailyAt('00:20')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

Schedule::job(new AlertExpiringNetworkPapers)
    ->dailyAt('09:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

Schedule::job(new AlertExpiringSignatories)
    ->dailyAt('09:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

Schedule::job(new AlertExpiringStock)
    ->dailyAt('09:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

// Yesterday's dashboard summary (spec §11 observability 4), after midnight in India.
Schedule::job(new BuildDailyMetrics)
    ->dailyAt('01:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

// Health locker (spec §7.11): due reminders, and shares past their end recorded as expired.
Schedule::job(new SendDueReminders)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::job(new ExpireRecordShares)
    ->dailyAt('00:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

// ABDM M2 (spec §5.7): reports ABDM has not linked yet are sent again.
Schedule::job(new RetryCareContextLinks)
    ->hourly()
    ->withoutOverlapping();
