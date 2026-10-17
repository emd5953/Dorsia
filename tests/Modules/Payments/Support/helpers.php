<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizePaymentInput;
use App\Modules\Payments\Support\FakeGateway;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

const PAYMENTS_TEST_SECRET = 'test-secret';

function paymentsSecret(): void
{
    config(['payments.fake.webhook_secret' => PAYMENTS_TEST_SECRET]);
}

function fakeGateway(): FakeGateway
{
    $gateway = app(PaymentGateway::class);

    assert($gateway instanceof FakeGateway);

    return $gateway;
}

function authorizeInput(?Member $member = null, int $holdId = 1, int $cents = 55000, string $token = 'tok_ok'): AuthorizePaymentInput
{
    return new AuthorizePaymentInput(($member ?? Member::factory()->create())->id, $holdId, Money::of($cents, 'USD'), $token);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{string, array<string, string>} raw body and signed headers
 */
function signedWebhook(array $payload): array
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    return [$raw, ['Fake-Signature' => FakeGateway::sign($raw), 'Content-Type' => 'application/json']];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postWebhook(TestCase $test, array $payload, ?string $signature = null): Illuminate\Testing\TestResponse
{
    [$raw, $headers] = signedWebhook($payload);

    if ($signature !== null) {
        $headers['Fake-Signature'] = $signature;
    }

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', '/api/webhooks/payments/fake', [], [], [], $server, $raw);
}

/**
 * Routes the gateway's outgoing webhook deliveries into the real endpoint through the HTTP kernel.
 *
 * @return ArrayObject<int, string> raw bodies delivered, filled as deliveries happen
 */
function routeWebhooksThroughKernel(TestCase $test): ArrayObject
{
    $delivered = new ArrayObject;

    Http::fake(function (Request $request) use ($test, $delivered) {
        $delivered[] = $request->body();

        $response = $test->call('POST', '/api/webhooks/payments/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_FAKE_SIGNATURE' => $request->header('Fake-Signature')[0] ?? '',
        ], $request->body());

        return Http::response((string) $response->getContent(), $response->getStatusCode());
    });

    return $delivered;
}
