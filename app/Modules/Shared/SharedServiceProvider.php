<?php

namespace App\Modules\Shared;

use App\Modules\Shared\Console\PrintAppUserGrants;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Database\SchemaMacros;
use App\Modules\Shared\Notifications\Contracts\DeliveryReportParser;
use App\Modules\Shared\Notifications\Contracts\EmailSender;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use App\Modules\Shared\Notifications\Contracts\WhatsAppSender;
use App\Modules\Shared\Notifications\Infrastructure\LogMessageSender;
use App\Modules\Shared\Notifications\Infrastructure\SignedJsonDeliveryReports;
use App\Modules\Shared\Scoping\CurrentScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentActor::class);
        $this->app->scoped(CurrentScope::class);

        $this->commands([PrintAppUserGrants::class]);

        // Vendors are swapped here, never in business code (spec §3).
        $this->app->bind(SmsSender::class, LogMessageSender::class);
        $this->app->bind(WhatsAppSender::class, LogMessageSender::class);
        $this->app->bind(EmailSender::class, LogMessageSender::class);
        $this->app->bind(DeliveryReportParser::class, fn () => new SignedJsonDeliveryReports((string) config('services.messaging.webhook_secret')));
    }

    public function boot(): void
    {
        SchemaMacros::register();

        Date::use(CarbonImmutable::class);

        // Fail loudly in development on lazy loading, unknown attributes and
        // silently dropped mass-assignment, instead of shipping hidden bugs.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
