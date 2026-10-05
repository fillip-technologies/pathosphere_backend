<?php

namespace App\Modules\Shared;

use App\Modules\Shared\Console\PrintAppUserGrants;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Database\SchemaMacros;
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
