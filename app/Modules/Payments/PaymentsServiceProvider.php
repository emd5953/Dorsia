<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Console\ReapPayments;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Support\FakeGateway;
use App\Shared\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        if (config('payments.gateway') === 'fake') {
            $this->app->singleton(FakeGateway::class);
            $this->app->singleton(
                PaymentGateway::class,
                fn (Application $app): PaymentGateway => $app->make(FakeGateway::class),
            );
        }
    }

    protected function consoleCommands(): array
    {
        return [ReapPayments::class];
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('payments:reap')->everyMinute()->withoutOverlapping()->onOneServer();
    }

    public function boot(): void
    {
        $gateway = config('payments.gateway');

        if ($gateway !== 'fake') {
            throw new LogicException('Unsupported payments.gateway "'.(is_scalar($gateway) ? (string) $gateway : gettype($gateway)).'"; only "fake" exists.');
        }

        parent::boot();
    }
}
