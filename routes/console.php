<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('idempotency:prune')
    ->dailyAt('04:30')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
