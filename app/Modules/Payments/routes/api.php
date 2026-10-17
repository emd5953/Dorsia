<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\FakeWebhookController;
use Illuminate\Support\Facades\Route;

// Authenticated by the Fake-Signature header, not by a bearer token (C§8).
Route::post('webhooks/payments/fake', FakeWebhookController::class);
