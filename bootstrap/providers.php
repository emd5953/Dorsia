<?php

declare(strict_types=1);

use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Pricing\PricingServiceProvider;
use App\Modules\Realtime\RealtimeServiceProvider;
use App\Modules\Reservations\ReservationsServiceProvider;
use App\Modules\Wallet\WalletServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    InventoryServiceProvider::class,
    PricingServiceProvider::class,
    ReservationsServiceProvider::class,
    WalletServiceProvider::class,
    PaymentsServiceProvider::class,
    RealtimeServiceProvider::class,
];
