<?php

declare(strict_types=1);

use App\Modules\Payments\Support\FakeGateway;
use App\Modules\Payments\Support\WebhookSignature;
use App\Shared\Money\Money;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(fn () => paymentsSecret());

it('signs the raw body as sha256= plus the hex HMAC with the webhook secret', function (): void {
    $body = '{"id":"evt_1"}';

    expect(FakeGateway::sign($body))->toBe('sha256='.hash_hmac('sha256', $body, 'test-secret'))
        ->and(FakeGateway::sign($body))->toBe(WebhookSignature::sign($body))
        ->and(FakeGateway::sign($body.' '))->not->toBe(FakeGateway::sign($body));
});

it('verifies only the exact signature', function (): void {
    $body = '{"id":"evt_1"}';
    $good = WebhookSignature::sign($body);

    expect(WebhookSignature::verify($body, $good))->toBeTrue()
        ->and(WebhookSignature::verify($body.'x', $good))->toBeFalse()
        ->and(WebhookSignature::verify($body, null))->toBeFalse()
        ->and(WebhookSignature::verify($body, ''))->toBeFalse()
        ->and(WebhookSignature::verify($body, substr($good, 0, -1)))->toBeFalse()
        ->and(WebhookSignature::verify($body, $good.'0'))->toBeFalse()
        ->and(WebhookSignature::verify($body, strtoupper($good)))->toBeFalse()
        ->and(WebhookSignature::verify($body, substr($good, 7)))->toBeFalse();
});

it('verifies with a different secret only against that secret', function (): void {
    $body = '{}';
    $signature = WebhookSignature::sign($body);

    config(['payments.fake.webhook_secret' => 'other-secret']);

    expect(WebhookSignature::verify($body, $signature))->toBeFalse();
});

it('never verifies when no secret is configured', function (): void {
    config(['payments.fake.webhook_secret' => null]);
    $forged = 'sha256='.hash_hmac('sha256', '{}', '');

    expect(WebhookSignature::verify('{}', $forged))->toBeFalse();

    config(['payments.fake.webhook_secret' => '']);

    expect(WebhookSignature::verify('{}', $forged))->toBeFalse();
});

it('compares in constant time via hash_equals', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Payments/Support/WebhookSignature.php'));

    expect($source)->toContain('hash_equals(')
        ->and($source)->not->toMatch('/sign\([^)]*\)\s*[!=]==?|[!=]==?\s*self::sign/');
});

it('builds the C11 webhook payload', function (): void {
    $payload = FakeGateway::webhookPayload('payment.captured', 'fake_ch_X', Money::of(55000, 'USD'), 12, 'evt_fixed');

    expect($payload['id'])->toBe('evt_fixed')
        ->and($payload['type'])->toBe('payment.captured')
        ->and($payload['created'])->toBeInt()
        ->and($payload['data'])->toBe([
            'provider_ref' => 'fake_ch_X',
            'amount_cents' => 55000,
            'currency' => 'USD',
            'metadata' => ['payment_id' => 12],
        ]);
});

it('generates a unique event id when none is given', function (): void {
    $a = FakeGateway::webhookPayload('payment.authorized', 'r', Money::of(1, 'USD'), 1);
    $b = FakeGateway::webhookPayload('payment.authorized', 'r', Money::of(1, 'USD'), 1);

    expect($a['id'])->toStartWith('evt_')->and($a['id'])->not->toBe($b['id']);
});
