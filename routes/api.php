<?php

declare(strict_types=1);

use App\Shared\Members\Http\AuthTokenController;
use App\Shared\Members\Http\MeController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/token', AuthTokenController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', MeController::class);
});
