<?php

declare(strict_types=1);

use App\Modules\Reservations\Http\Controllers\HoldController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/holds', [HoldController::class, 'store'])->middleware('idempotent');
    Route::get('/holds/{hold}', [HoldController::class, 'show'])->whereNumber('hold');
});
