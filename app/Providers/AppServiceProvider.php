<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Idempotency\PruneIdempotencyKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            DB::prohibitDestructiveCommands();
        }

        $this->commands([PruneIdempotencyKeys::class]);
    }
}
