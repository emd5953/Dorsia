<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Support\FakeGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$holdId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (! $app->environment('local') || config('payments.gateway') !== 'fake' || $holdId === false) {
    throw new RuntimeException('Specify a local fake-gateway test hold to deliver its delayed authorization callback.');
}
$gateway = $app->make(PaymentGateway::class);
if (! $gateway instanceof FakeGateway) {
    throw new RuntimeException('The configured gateway is not fake.');
}
foreach ($gateway->charges() as $charge) {
    if ($charge['idempotency_key'] !== "hold:{$holdId}:auth" || $charge['status'] !== 'authorized') {
        continue;
    }
    // C§11: a late authorization callback lets the backend refund a timed-out charge.
    $payload = json_encode([
        'id' => 'evt_frontend_late_'.$holdId,
        'type' => 'payment.authorized',
        'created' => time(),
        'data' => [
            'provider_ref' => $charge['provider_ref'],
            'amount_cents' => $charge['amount_cents'],
            'currency' => $charge['currency'],
            'metadata' => ['payment_id' => $charge['payment_id']],
        ],
    ], JSON_THROW_ON_ERROR);
    $response = Http::withHeaders(['Fake-Signature' => FakeGateway::sign($payload)])
        ->withBody($payload, 'application/json')
        ->post('http://app:8000/api/webhooks/payments/fake');
    $response->throw();
    echo $response->body().PHP_EOL;
    exit(0);
}
throw new RuntimeException('No authorized fake charge exists for this hold.');
