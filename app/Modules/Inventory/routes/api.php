<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\CityController;
use App\Modules\Inventory\Http\Controllers\DropController;
use App\Modules\Inventory\Http\Controllers\SlotController;
use App\Modules\Inventory\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/cities', [CityController::class, 'index']);
    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/{venue}', [VenueController::class, 'show'])->whereNumber('venue');
    Route::get('/venues/{venue}/availability', [VenueController::class, 'availability'])->whereNumber('venue');
    Route::get('/slots/{slot}', [SlotController::class, 'show'])->whereNumber('slot');
    Route::get('/drops/{drop}', [DropController::class, 'show'])->whereNumber('drop');
});
