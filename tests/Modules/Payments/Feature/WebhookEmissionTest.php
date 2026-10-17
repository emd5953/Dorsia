<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\MarkPaymentForCapture;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentDeclinedException;
use App\Modules\Payments\Exceptions\PaymentTimeoutException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Support\FakeGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(fn () => paymentsSecret());

/**
 * @return list<array<string, mixed>>
 */
function deliveredEvents(ArrayObject $delivered): array
{
    return array_map(fn (string $raw): array => json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $delivered->getArrayCopy());
}

it('emits nothing by default', function (): void {
    $delivered = routeWebhooksThroughKernel($this);

    app(AuthorizePayment::class)->handle(authorizeInput());

    expect($delivered->count())->toBe(0)
        ->and(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('delivers a signed authorized event through the real endpoint when emitWebhooks is on', function (): void {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        $sent[] = $request;

        return Http::response(['data' => ['duplicate' => false]]);
    });
    fakeGateway()->emitWebhooks();

    $data = app(AuthorizePayment::class)->handle(authorizeInput());

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->url())->toBe(rtrim((string) config('app.url'), '/').'/api/webhooks/payments/fake')
        ->and($sent[0]->header('Fake-Signature')[0])->toBe(FakeGateway::sign($sent[0]->body()))
        ->and($sent[0]['type'])->toBe('payment.authorized')
        ->and($sent[0]['data']['provider_ref'])->toBe($data->providerRef)
        ->and($sent[0]['data']['metadata']['payment_id'])->toBe($data->id)
        ->and($sent[0]['data']['amount_cents'])->toBe(55000);
});

it('emits authorized, captured and refunded events that settle the payment through the endpoint', function (): void {
    $delivered = routeWebhooksThroughKernel($this);
    fakeGateway()->emitWebhooks();

    $data = app(AuthorizePayment::class)->handle(authorizeInput());
    DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($data->id, 5));

    expect(array_column(deliveredEvents($delivered), 'type'))->toBe(['payment.authorized', 'payment.captured'])
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Captured)
        ->and(PaymentWebhookEvent::query()->count())->toBe(2);

    $second = app(AuthorizePayment::class)->handle(authorizeInput(null, 2));
    app(VoidPayment::class)->handle($second->id);

    expect(array_column(deliveredEvents($delivered), 'type'))->toBe(['payment.authorized', 'payment.captured', 'payment.authorized', 'payment.refunded'])
        ->and(Payment::query()->find($second->id)?->status)->toBe(PaymentStatus::Refunded);
});

it('does not emit for declines', function (): void {
    $delivered = routeWebhooksThroughKernel($this);
    fakeGateway()->emitWebhooks();

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(token: 'tok_decline')))->toThrow(PaymentDeclinedException::class)
        ->and($delivered->count())->toBe(0);
});

it('delivers every event twice with the same id when duplicateWebhooks is on, and ends in the single-delivery state', function (): void {
    $delivered = routeWebhooksThroughKernel($this);
    fakeGateway()->emitWebhooks();
    fakeGateway()->duplicateWebhooks();

    $data = app(AuthorizePayment::class)->handle(authorizeInput());
    DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($data->id, 5));

    $events = deliveredEvents($delivered);

    expect($events)->toHaveCount(4)
        ->and($events[0]['id'])->toBe($events[1]['id'])
        ->and($events[2]['id'])->toBe($events[3]['id'])
        ->and($events[0]['id'])->not->toBe($events[2]['id'])
        ->and(PaymentWebhookEvent::query()->count())->toBe(2)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Captured);
});

it('emits duplicates for tok_dup_webhook even without the global flags', function (): void {
    $delivered = routeWebhooksThroughKernel($this);

    $data = app(AuthorizePayment::class)->handle(authorizeInput(token: 'tok_dup_webhook'));
    DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($data->id, 5));

    $events = deliveredEvents($delivered);

    expect(array_column($events, 'type'))->toBe(['payment.authorized', 'payment.authorized', 'payment.captured', 'payment.captured'])
        ->and($events[0]['id'])->toBe($events[1]['id'])
        ->and(PaymentWebhookEvent::query()->count())->toBe(2)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Captured);
});

it('reads the emit and duplicate flags from config', function (): void {
    $delivered = routeWebhooksThroughKernel($this);
    config(['payments.fake.emit_webhooks' => true, 'payments.fake.duplicate_webhooks' => true]);

    app(AuthorizePayment::class)->handle(authorizeInput());

    expect($delivered->count())->toBe(2);
});

it('lets the programmatic knob turn emission off again over config', function (): void {
    $delivered = routeWebhooksThroughKernel($this);
    config(['payments.fake.emit_webhooks' => true]);
    fakeGateway()->emitWebhooks(false);

    app(AuthorizePayment::class)->handle(authorizeInput());

    expect($delivered->count())->toBe(0);
});

it('applies a payment.authorized webhook for a timed out charge: provider_ref set, void ends refunded', function (): void {
    routeWebhooksThroughKernel($this);

    // Emission is off for the call itself; the provider's late webhook is delivered by hand afterwards.
    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(holdId: 3, token: 'tok_timeout')))->toThrow(PaymentTimeoutException::class);

    $payment = Payment::query()->sole();
    $charge = fakeGateway()->charges()[0];

    expect($payment->status)->toBe(PaymentStatus::TimedOut);

    postWebhook($this, FakeGateway::webhookPayload('payment.authorized', $charge['provider_ref'], $payment->amount(), $payment->id))->assertOk();

    expect($payment->fresh()?->provider_ref)->toBe($charge['provider_ref'])
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Refunded)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('refunded');
});
