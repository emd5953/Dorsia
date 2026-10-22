<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Support\FakeGateway;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || config('payments.gateway') !== 'fake') {
    throw new RuntimeException('Evidence inspection requires the local fake gateway.');
}
$gateway = $app->make(PaymentGateway::class);
if (! $gateway instanceof FakeGateway) {
    throw new RuntimeException('The configured gateway is not fake.');
}

// Provider-side test inspection is part of C§11; no payment table reads needed.
echo json_encode(['charges' => $gateway->charges(), 'authorize_calls' => $gateway->authorizeCalls()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
