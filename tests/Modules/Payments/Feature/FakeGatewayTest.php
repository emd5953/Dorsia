<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Shared\Money\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function (): void {
    paymentsSecret();
    Http::fake();
});

function gatewayRequest(string $token = 'tok_ok', string $key = 'k1', int $cents = 5000, int $paymentId = 7): AuthorizeRequest
{
    return new AuthorizeRequest(Money::of($cents, 'USD'), $token, $key, ['payment_id' => $paymentId]);
}

it('resolves the interface to one shared fake singleton', function (): void {
    expect(app(PaymentGateway::class))->toBe(app(PaymentGateway::class))
        ->and(app(PaymentGateway::class))->toBe(fakeGateway());
});

it('authorizes tok_ok, tok_capture_fail and tok_dup_webhook with a fake_ch_ ulid ref', function (string $token): void {
    $result = fakeGateway()->authorize(gatewayRequest($token));

    expect($result->status)->toBe(GatewayStatus::Authorized)
        ->and($result->providerRef)->toMatch('/^fake_ch_[0-9a-z]{26}$/i')
        ->and($result->declineCode)->toBeNull();
})->with(['tok_ok', 'tok_capture_fail', 'tok_dup_webhook']);

it('declines by token with the C11 decline codes', function (string $token, string $code): void {
    $result = fakeGateway()->authorize(gatewayRequest($token));

    expect($result->status)->toBe(GatewayStatus::Declined)
        ->and($result->providerRef)->toBeNull()
        ->and($result->declineCode)->toBe($code)
        ->and(fakeGateway()->charges())->toBe([]);
})->with([
    ['tok_decline', 'card_declined'],
    ['tok_insufficient_funds', 'insufficient_funds'],
    ['tok_nonsense', 'invalid_token'],
    ['', 'invalid_token'],
]);

it('records the charge provider-side and then times out for tok_timeout', function (): void {
    expect(fn () => fakeGateway()->authorize(gatewayRequest('tok_timeout', 'kt')))->toThrow(GatewayTimeoutException::class);

    $charges = fakeGateway()->charges();

    expect($charges)->toHaveCount(1)
        ->and($charges[0]['status'])->toBe('authorized')
        ->and($charges[0]['idempotency_key'])->toBe('kt')
        ->and($charges[0]['payment_id'])->toBe(7);
});

it('is idempotent on the key: same ref, one charge, and a retry after a timeout sees the charge', function (): void {
    $a = fakeGateway()->authorize(gatewayRequest('tok_ok', 'same'));
    $b = fakeGateway()->authorize(gatewayRequest('tok_ok', 'same'));

    expect($b->providerRef)->toBe($a->providerRef)
        ->and(fakeGateway()->charges())->toHaveCount(1)
        ->and(fakeGateway()->authorizeCalls())->toBe(2);

    fakeGateway()->assertChargedOnce('same');

    expect(fn () => fakeGateway()->authorize(gatewayRequest('tok_timeout', 'retry')))->toThrow(GatewayTimeoutException::class);
    $retry = fakeGateway()->authorize(gatewayRequest('tok_timeout', 'retry'));
    // The recorded charge answers the retry instead of timing out again or double charging.
    expect($retry->status)->toBe(GatewayStatus::Authorized)
        ->and(fakeGateway()->charges())->toHaveCount(2);
});

it('assertChargedOnce fails for zero and for several charges', function (): void {
    expect(fn () => fakeGateway()->assertChargedOnce('none'))->toThrow(AssertionError::class);

    fakeGateway()->authorize(gatewayRequest('tok_ok', 'a'));
    fakeGateway()->assertChargedOnce('a');
    Redis::connection()->hset('fakegw:charges', 'fake_ch_dup', json_encode(['idempotency_key' => 'a', 'amount_cents' => 1, 'currency' => 'USD', 'status' => 'authorized', 'payment_id' => 1]));

    expect(fn () => fakeGateway()->assertChargedOnce('a'))->toThrow(AssertionError::class);
});

it('declineNext declines exactly one authorize with the given code, then resets', function (): void {
    fakeGateway()->declineNext('do_not_honor');

    $first = fakeGateway()->authorize(gatewayRequest('tok_ok', 'd1'));
    $second = fakeGateway()->authorize(gatewayRequest('tok_ok', 'd2'));

    expect($first->status)->toBe(GatewayStatus::Declined)
        ->and($first->declineCode)->toBe('do_not_honor')
        ->and($second->status)->toBe(GatewayStatus::Authorized);

    fakeGateway()->declineNext();

    expect(fakeGateway()->authorize(gatewayRequest('tok_ok', 'd3'))->declineCode)->toBe('card_declined');
});

it('timeoutNext times out exactly one authorize after recording the charge, then resets', function (): void {
    fakeGateway()->timeoutNext();

    expect(fn () => fakeGateway()->authorize(gatewayRequest('tok_ok', 't1')))->toThrow(GatewayTimeoutException::class)
        ->and(fakeGateway()->charges())->toHaveCount(1)
        ->and(fakeGateway()->authorize(gatewayRequest('tok_ok', 't2'))->status)->toBe(GatewayStatus::Authorized);
});

it('captures an authorized charge and refuses to capture twice under another key', function (): void {
    $ref = (string) fakeGateway()->authorize(gatewayRequest())->providerRef;

    $captured = fakeGateway()->capture($ref, Money::of(5000, 'USD'), 'cap1');
    $replay = fakeGateway()->capture($ref, Money::of(5000, 'USD'), 'cap1');
    $again = fakeGateway()->capture($ref, Money::of(5000, 'USD'), 'cap2');

    expect($captured->status)->toBe(GatewayStatus::Captured)
        ->and($captured->providerRef)->toBe($ref)
        ->and($replay->status)->toBe(GatewayStatus::Captured)
        ->and($again->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('captured');
});

it('applies the capture amount rules', function (): void {
    $ref = (string) fakeGateway()->authorize(gatewayRequest())->providerRef;

    expect(fakeGateway()->capture($ref, Money::of(5001, 'USD'), 'c1')->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->capture($ref, Money::zero(Money::of(1, 'USD')->currency), 'c2')->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->capture('fake_ch_unknown', Money::of(1, 'USD'), 'c3')->declineCode)->toBe('invalid_state')
        ->and(fakeGateway()->capture($ref, Money::of(4000, 'USD'), 'c4')->status)->toBe(GatewayStatus::Captured);
});

it('failNextCapture fails exactly one capture, then resets', function (): void {
    $ref = (string) fakeGateway()->authorize(gatewayRequest())->providerRef;
    fakeGateway()->failNextCapture();

    $failed = fakeGateway()->capture($ref, Money::of(5000, 'USD'), 'c1');
    $ok = fakeGateway()->capture($ref, Money::of(5000, 'USD'), 'c1');

    expect($failed->status)->toBe(GatewayStatus::Failed)
        ->and($failed->declineCode)->toBe('capture_failed')
        ->and($ok->status)->toBe(GatewayStatus::Captured);
});

it('tok_capture_fail fails every capture of its charge', function (): void {
    $ref = (string) fakeGateway()->authorize(gatewayRequest('tok_capture_fail'))->providerRef;

    expect(fakeGateway()->capture($ref, Money::of(5000, 'USD'), 'c1')->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->capture($ref, Money::of(5000, 'USD'), 'c1')->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('authorized');
});

it('refunds authorized and captured charges, idempotently, and applies amount rules', function (): void {
    $a = (string) fakeGateway()->authorize(gatewayRequest('tok_ok', 'ra'))->providerRef;
    $b = (string) fakeGateway()->authorize(gatewayRequest('tok_ok', 'rb'))->providerRef;
    fakeGateway()->capture($b, Money::of(5000, 'USD'), 'cb');

    expect(fakeGateway()->refund($a, Money::of(5001, 'USD'), 'r0')->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->refund($a, Money::of(5000, 'USD'), 'r1')->status)->toBe(GatewayStatus::Refunded)
        ->and(fakeGateway()->refund($a, Money::of(5000, 'USD'), 'r1')->status)->toBe(GatewayStatus::Refunded)
        ->and(fakeGateway()->refund($a, Money::of(5000, 'USD'), 'r2')->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->refund($b, Money::of(5000, 'USD'), 'r3')->status)->toBe(GatewayStatus::Refunded)
        ->and(fakeGateway()->refund('fake_ch_unknown', Money::of(1, 'USD'), 'r4')->status)->toBe(GatewayStatus::Failed)
        ->and(array_column(fakeGateway()->charges(), 'status'))->toBe(['refunded', 'refunded']);
});

it('pays out idempotently and rejects bad destinations or amounts', function (): void {
    $first = fakeGateway()->payout('acct_1', Money::of(2500, 'USD'), 'po1');
    $replay = fakeGateway()->payout('acct_1', Money::of(2500, 'USD'), 'po1');

    expect($first->status)->toBe(GatewayStatus::PaidOut)
        ->and($first->providerRef)->toStartWith('fake_po_')
        ->and($replay->providerRef)->toBe($first->providerRef)
        ->and(Redis::connection()->hlen('fakegw:payouts'))->toBe(1)
        ->and(fakeGateway()->payout('', Money::of(2500, 'USD'), 'po2')->status)->toBe(GatewayStatus::Failed)
        ->and(fakeGateway()->payout('acct_1', Money::zero(Money::of(1, 'USD')->currency), 'po3')->status)->toBe(GatewayStatus::Failed);
});

it('declines a zero-amount authorization', function (): void {
    $request = new AuthorizeRequest(Money::of(0, 'USD'), 'tok_ok', 'z', ['payment_id' => 1]);

    expect(fakeGateway()->authorize($request)->declineCode)->toBe('invalid_amount');
});

it('latency delays every call by the configured milliseconds', function (): void {
    fakeGateway()->latency(120);

    $start = microtime(true);
    fakeGateway()->authorize(gatewayRequest());
    $elapsed = (microtime(true) - $start) * 1000;

    expect($elapsed)->toBeGreaterThanOrEqual(115)->and($elapsed)->toBeLessThan(1500);

    fakeGateway()->latency(0);
    $start = microtime(true);
    fakeGateway()->authorize(gatewayRequest('tok_ok', 'fast'));

    expect((microtime(true) - $start) * 1000)->toBeLessThan(100);
});

it('reads the latency from config when no knob is set', function (): void {
    config(['payments.fake.latency_ms' => 100]);

    $start = microtime(true);
    fakeGateway()->authorize(gatewayRequest());

    expect((microtime(true) - $start) * 1000)->toBeGreaterThanOrEqual(95);
});

it('keeps provider state in Redis under the C11 keys', function (): void {
    $ref = (string) fakeGateway()->authorize(gatewayRequest('tok_ok', 'redis-key'))->providerRef;

    expect(Redis::connection()->get('fakegw:idem:redis-key'))->toBe($ref)
        ->and(Redis::connection()->hexists('fakegw:charges', $ref))->toBeTruthy();
});
