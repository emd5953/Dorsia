<?php

declare(strict_types=1);

use App\Modules\Wallet\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/wallet', WalletController::class);
});
