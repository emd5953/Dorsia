<?php

declare(strict_types=1);

use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Modules\Payments\Jobs\RefundPayment;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Support\FakeGateway;
use App\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function (): void {
    paymentsSecret();
    Http::fake();
});

/**
 * @return array<string, mixed>
 */
function eventFor(Payment $payment, string $type, ?string $id = null): array
{
    return FakeGateway::webhookPayload($type, (string) $payment->provider_ref, $payment->amount(), $payment->id, $id);
}

it('rejects a bad signature with 401 invalid_signature and stores nothing', function (?string $signature): void {
    $payment = Payment::factory()->create();

    postWebhook($this, eventFor($payment, 'payment.captured'), $signature)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_signature');

    expect(PaymentWebhookEvent::query()->count())->toBe(0)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Authorized);
})->with([
    'wrong' => 'sha256=deadbeef',
    'empty' => '',
    'no prefix' => hash_hmac('sha256', 'x', 'y'),
]);

it('rejects a missing signature header and a body signed for different bytes', function (): void {
    $payment = Payment::factory()->create();
    $payload = eventFor($payment, 'payment.captured');
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/webhooks/payments/fake', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
        ->assertStatus(401);

    $this->call('POST', '/api/webhooks/payments/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_FAKE_SIGNATURE' => FakeGateway::sign($raw.' '),
    ], $raw)->assertStatus(401);

    expect(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('needs no bearer token', function (): void {
    $payment = Payment::factory()->create();

    postWebhook($this, eventFor($payment, 'payment.captured'))->assertOk();
});

it('applies a valid event once and flags the same id as duplicate', function (): void {
    $payment = Payment::factory()->create();
    $event = eventFor($payment, 'payment.captured', 'evt_same');

    postWebhook($this, $event)->assertOk()->assertExactJson(['data' => ['duplicate' => false]]);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured);

    Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Authorized->value]);

    postWebhook($this, $event)->assertOk()->assertExactJson(['data' => ['duplicate' => true]]);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Authorized)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1);

    $row = PaymentWebhookEvent::query()->sole();

    expect($row->provider)->toBe('fake')
        ->and($row->event_id)->toBe('evt_same')
        ->and($row->type)->toBe('payment.captured')
        ->and($row->provider_ref)->toBe($payment->provider_ref)
        ->and($row->payload['id'])->toBe('evt_same')
        ->and($row->processed_at)->not->toBeNull();
});

/*
| C§11 transition table, every row: [event, from, expected, expects provider_ref stored]
*/
it('applies the C11 transition table', function (string $type, PaymentStatus $from, PaymentStatus $to): void {
    $payment = Payment::factory()->create(['status' => $from, 'provider_ref' => $from === PaymentStatus::Pending ? null : 'fake_ch_TABLE']);
    $event = FakeGateway::webhookPayload($type, 'fake_ch_TABLE', Money::of(55000, 'USD'), $payment->id);

    postWebhook($this, $event)->assertOk();

    $fresh = $payment->fresh();

    expect($fresh?->status)->toBe($to)
        ->and($fresh?->provider_ref)->toBe($from === PaymentStatus::Pending && $type === 'payment.failed' ? null : 'fake_ch_TABLE');
})->with([
    'authorized from pending' => ['payment.authorized', PaymentStatus::Pending, PaymentStatus::Authorized],
    'captured from authorized' => ['payment.captured', PaymentStatus::Authorized, PaymentStatus::Captured],
    'refunded from authorized' => ['payment.refunded', PaymentStatus::Authorized, PaymentStatus::Refunded],
    'refunded from captured' => ['payment.refunded', PaymentStatus::Captured, PaymentStatus::Refunded],
    'refunded from timed_out' => ['payment.refunded', PaymentStatus::TimedOut, PaymentStatus::Refunded],
    'failed from pending' => ['payment.failed', PaymentStatus::Pending, PaymentStatus::Failed],
]);

it('requires provider_ref on authorized, captured and refunded events', function (string $type): void {
    Log::spy();
    $payment = Payment::factory()->pending()->create();
    $event = FakeGateway::webhookPayload($type, 'fake_ch_MISSING', Money::of(55000, 'USD'), $payment->id);
    $event['data']['provider_ref'] = null;

    postWebhook($this, $event)->assertOk()->assertJsonPath('data.duplicate', false);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Pending)
        ->and(PaymentWebhookEvent::query()->sole()->processed_at)->not->toBeNull();
    Log::shouldHaveReceived('warning')->once();
})->with(['payment.authorized', 'payment.captured', 'payment.refunded']);

it('records amount or currency mismatches without applying the event', function (array $override): void {
    Log::spy();
    $payment = Payment::factory()->pending()->create();
    $event = FakeGateway::webhookPayload('payment.authorized', 'fake_ch_MISMATCH', Money::of(55000, 'USD'), $payment->id);
    $event['data'] = array_replace($event['data'], $override);

    postWebhook($this, $event)->assertOk()->assertExactJson(['data' => ['duplicate' => false]]);

    $stored = PaymentWebhookEvent::query()->sole();
    expect($stored->processed_at)->toBeNull()
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()?->provider_ref)->toBeNull();
    Log::shouldHaveReceived('critical')->once();
})->with([
    'amount' => [['amount_cents' => 54999]],
    'currency' => [['currency' => 'EUR']],
]);

it('applies captured events that arrive before authorization and refunds timed out captures', function (): void {
    Queue::fake();
    $pending = Payment::factory()->pending()->create();
    $pendingEvent = FakeGateway::webhookPayload('payment.captured', 'fake_ch_EARLY', Money::of(55000, 'USD'), $pending->id);
    postWebhook($this, $pendingEvent)->assertOk();
    expect($pending->fresh()?->status)->toBe(PaymentStatus::Captured)
        ->and($pending->fresh()?->provider_ref)->toBe('fake_ch_EARLY');

    $timedOut = Payment::factory()->create(['status' => PaymentStatus::TimedOut, 'provider_ref' => null]);
    $lateEvent = FakeGateway::webhookPayload('payment.captured', 'fake_ch_LATE', Money::of(55000, 'USD'), $timedOut->id);
    postWebhook($this, $lateEvent)->assertOk();
    expect($timedOut->fresh()?->status)->toBe(PaymentStatus::TimedOut)
        ->and($timedOut->fresh()?->provider_ref)->toBe('fake_ch_LATE');
    Queue::assertPushed(RefundPayment::class, fn ($job) => $job->paymentId === $timedOut->id);
});

it('stores the decline code carried by a failed event', function (): void {
    $payment = Payment::factory()->pending()->create();
    $event = FakeGateway::webhookPayload('payment.failed', 'fake_ch_F', Money::of(55000, 'USD'), $payment->id);
    $event['data']['decline_code'] = 'card_declined';

    postWebhook($this, $event)->assertOk();

    expect($payment->fresh()?->decline_code)->toBe('card_declined');
});

it('ignores every transition the table does not list, including backwards ones', function (string $type, PaymentStatus $from): void {
    Queue::fake();
    $payment = Payment::factory()->create(['status' => $from, 'provider_ref' => 'fake_ch_NOOP']);
    $event = FakeGateway::webhookPayload($type, 'fake_ch_NOOP', Money::of(55000, 'USD'), $payment->id);

    postWebhook($this, $event)->assertOk()->assertJsonPath('data.duplicate', false);

    expect($payment->fresh()?->status)->toBe($from)
        ->and(PaymentWebhookEvent::query()->sole()->processed_at)->not->toBeNull();
})->with(function (): Generator {
    $allowed = [
        'payment.authorized' => [PaymentStatus::Pending, PaymentStatus::TimedOut],
        'payment.captured' => [PaymentStatus::Authorized, PaymentStatus::Pending, PaymentStatus::TimedOut],
        'payment.refunded' => [PaymentStatus::Authorized, PaymentStatus::Captured, PaymentStatus::TimedOut],
        'payment.failed' => [PaymentStatus::Pending],
    ];

    foreach ($allowed as $type => $froms) {
        foreach (PaymentStatus::cases() as $status) {
            if (! in_array($status, $froms, true)) {
                yield "{$type} on {$status->value}" => [$type, $status];
            }
        }
    }

    yield 'unknown type on authorized' => ['payment.disputed', PaymentStatus::Authorized];
});

it('handles out-of-order captured events and never moves backward on a later authorized event', function (): void {
    Queue::fake();
    $payment = Payment::factory()->pending()->create();

    postWebhook($this, FakeGateway::webhookPayload('payment.captured', 'fake_ch_OOO', Money::of(55000, 'USD'), $payment->id))->assertOk();
    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured)
        ->and($payment->fresh()?->provider_ref)->toBe('fake_ch_OOO');

    postWebhook($this, FakeGateway::webhookPayload('payment.authorized', 'fake_ch_OOO', Money::of(55000, 'USD'), $payment->id))->assertOk();
    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured);
});

it('never moves a captured payment back to authorized', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Captured]);

    postWebhook($this, eventFor($payment, 'payment.authorized'))->assertOk();
    postWebhook($this, eventFor($payment, 'payment.failed'))->assertOk();

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured);
});

it('finds the payment by metadata payment_id when the provider_ref is unknown', function (): void {
    $payment = Payment::factory()->pending()->create();
    $event = FakeGateway::webhookPayload('payment.authorized', 'fake_ch_NEW', Money::of(55000, 'USD'), $payment->id);

    postWebhook($this, $event)->assertOk();

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Authorized)
        ->and($payment->fresh()?->provider_ref)->toBe('fake_ch_NEW');
});

it('prefers the provider_ref over metadata when they point to different payments', function (): void {
    $a = Payment::factory()->create(['provider_ref' => 'fake_ch_A']);
    $b = Payment::factory()->create(['provider_ref' => 'fake_ch_B']);

    postWebhook($this, FakeGateway::webhookPayload('payment.captured', 'fake_ch_A', Money::of(55000, 'USD'), $b->id))->assertOk();

    expect($a->fresh()?->status)->toBe(PaymentStatus::Captured)
        ->and($b->fresh()?->status)->toBe(PaymentStatus::Authorized);
});

it('does not overwrite a payment charge with a different provider_ref found via metadata', function (): void {
    Log::spy();
    $payment = Payment::factory()->create(['provider_ref' => 'fake_ch_REAL']);

    postWebhook($this, FakeGateway::webhookPayload('payment.refunded', 'fake_ch_OTHER', Money::of(55000, 'USD'), $payment->id))->assertOk();

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Authorized)
        ->and($payment->fresh()?->provider_ref)->toBe('fake_ch_REAL');
    Log::shouldHaveReceived('warning')->once();
});

it('records an event for an unknown payment, logs a warning and answers 200', function (): void {
    Log::spy();
    $event = FakeGateway::webhookPayload('payment.captured', 'fake_ch_GHOST', Money::of(100, 'USD'), 424242, 'evt_ghost');

    postWebhook($this, $event)->assertOk()->assertJsonPath('data.duplicate', false);

    $row = PaymentWebhookEvent::query()->sole();

    expect($row->event_id)->toBe('evt_ghost')
        ->and($row->provider_ref)->toBe('fake_ch_GHOST')
        ->and($row->processed_at)->toBeNull()
        ->and(Payment::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();

    postWebhook($this, $event)->assertOk()->assertJsonPath('data.duplicate', true);
});

it('returns 422 for a signed but malformed payload and stores nothing', function (array $body): void {
    postWebhook($this, $body)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect(PaymentWebhookEvent::query()->count())->toBe(0);
})->with([
    'no id' => [['type' => 'payment.captured', 'data' => ['provider_ref' => 'x']]],
    'no type' => [['id' => 'e', 'data' => ['provider_ref' => 'x']]],
    'no data' => [['id' => 'e', 'type' => 'payment.captured']],
    'bad payment id' => [['id' => 'e', 'type' => 'payment.captured', 'data' => ['metadata' => ['payment_id' => 'abc']]]],
    'empty' => [[]],
]);

it('returns 422 for a signed body that is not JSON', function (): void {
    $raw = 'not json';

    $this->call('POST', '/api/webhooks/payments/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_FAKE_SIGNATURE' => FakeGateway::sign($raw),
    ], $raw)->assertStatus(422);
});

it('dispatches the void for an orphaned authorization and ends refunded', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::TimedOut, 'provider_ref' => null]);
    // The charge exists provider-side because tok_timeout recorded it.
    expect(fn () => fakeGateway()->authorize(new AuthorizeRequest(Money::of(55000, 'USD'), 'tok_timeout', "hold:{$payment->hold_id}:auth", ['payment_id' => $payment->id])))
        ->toThrow(GatewayTimeoutException::class);
    $ref = fakeGateway()->charges()[0]['provider_ref'];

    postWebhook($this, FakeGateway::webhookPayload('payment.authorized', $ref, Money::of(55000, 'USD'), $payment->id))->assertOk();

    expect($payment->fresh()?->provider_ref)->toBe($ref)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Refunded)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('refunded');
});

it('stores the provider_ref on an orphan but leaves it timed_out while the refund is outstanding', function (): void {
    Queue::fake();
    $payment = Payment::factory()->create(['status' => PaymentStatus::TimedOut, 'provider_ref' => null]);

    postWebhook($this, FakeGateway::webhookPayload('payment.authorized', 'fake_ch_ORPHAN', Money::of(55000, 'USD'), $payment->id))->assertOk();

    expect($payment->fresh()?->provider_ref)->toBe('fake_ch_ORPHAN')
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::TimedOut);
    Queue::assertPushed(RefundPayment::class, fn ($job) => $job->paymentId === $payment->id);
});

it('stores timestamps with microsecond precision so same-second rows order correctly', function (): void {
    $payment = Payment::factory()->create();

    postWebhook($this, eventFor($payment, 'payment.authorized', 'evt_1'));
    postWebhook($this, eventFor($payment, 'payment.authorized', 'evt_2'));
    postWebhook($this, eventFor($payment, 'payment.authorized', 'evt_3'));

    $rows = DB::select("SELECT event_id, to_char(created_at, 'US') AS micros, created_at FROM payment_webhook_events ORDER BY created_at, id");
    $times = array_map(fn (object $r): string => $r->created_at, $rows);

    expect(array_column($rows, 'event_id'))->toBe(['evt_1', 'evt_2', 'evt_3'])
        ->and(count(array_unique($times)))->toBe(3)
        ->and(array_unique(array_column($rows, 'micros')))->not->toBe(['000000']);

    $first = Payment::factory()->create();
    $second = Payment::factory()->create();
    $stamps = DB::select('SELECT created_at, updated_at FROM payments WHERE id IN (?, ?) ORDER BY id', [$first->id, $second->id]);

    expect($stamps[1]->created_at)->toBeGreaterThan($stamps[0]->created_at)
        ->and($stamps[1]->updated_at)->toBeGreaterThan($stamps[0]->updated_at);
});
