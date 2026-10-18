<?php

declare(strict_types=1);

use App\Modules\Reservations\Http\Controllers\HoldController;
use App\Modules\Reservations\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/holds', [HoldController::class, 'store'])->middleware('idempotent');
    Route::get('/holds/{hold}', [HoldController::class, 'show'])->whereNumber('hold');

    Route::post('/reservations', [ReservationController::class, 'store'])->middleware('idempotent');
    Route::get('/reservations', [ReservationController::class, 'index']);
    Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->whereNumber('reservation');
    Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])
        ->whereNumber('reservation')
        ->middleware('idempotent');
});
