<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\MarkPaymentForCapture;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Exceptions\PaymentInProgressException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Support\FakeGateway;
use App\Modules\Payments\Support\HandleWebhook;
use App\Modules\Payments\Support\WebhookSignature;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(fn () => paymentsSecret());

it('creates exactly one charge and one payment for 20 parallel authorizations of one hold', function (): void {
    $member = Member::factory()->create();
    $input = authorizeInput($member, 501, 55000);

    $results = Concurrency::run(array_fill(0, 20, function () use ($input): array {
        try {
            $data = app(AuthorizePayment::class)->handle($input);

            return ['ok', $data->id, $data->status->value];
        } catch (PaymentInProgressException) {
            return ['in_progress', null, null];
        }
    }));

    $payment = Payment::query()->sole();
    $ok = array_filter($results, fn (array $r): bool => $r[0] === 'ok');

    expect(count($results))->toBe(20)
        ->and(count($ok))->toBeGreaterThanOrEqual(1)
        ->and(array_unique(array_column($ok, 1)))->toBe([array_values($ok)[0][1]])
        ->and(array_unique(array_column($ok, 2)))->toBe(['authorized'])
        ->and(count(array_filter($results, fn (array $r): bool => $r[0] === 'in_progress')) + count($ok))->toBe(20)
        ->and($payment->status)->toBe(PaymentStatus::Authorized)
        ->and(fakeGateway()->charges())->toHaveCount(1)
        ->and(fakeGateway()->authorizeCalls())->toBe(1)
        ->and(fakeGateway()->charges()[0]['provider_ref'])->toBe($payment->provider_ref);

    fakeGateway()->assertChargedOnce('hold:501:auth');
});

it('emits only once when parallel fake gateway calls race to claim the same key', function (): void {
    routeWebhooksThroughKernel($this);
    fakeGateway()->emitWebhooks();
    $request = new AuthorizeRequest(
        Money::of(5000, 'USD'),
        'tok_ok',
        'emit-race-key',
        ['payment_id' => 500],
    );

    $results = Concurrency::run(array_fill(0, 20, fn (): array => [
        'ref' => fakeGateway()->authorize($request)->providerRef,
    ]));

    expect(array_unique(array_column($results, 'ref')))->toHaveCount(1)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and(fakeGateway()->charges())->toHaveCount(1);
});

it('serializes conflicting webhook transitions and keeps the later refund', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Authorized, 'provider_ref' => 'fake_ch_lock']);
    DB::unprepared("CREATE FUNCTION payments_test_slow_refund() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.status = 'refunded' THEN PERFORM pg_sleep(0.5); END IF; RETURN NEW; END; \$\$");
    DB::unprepared('CREATE TRIGGER payments_test_slow_refund BEFORE UPDATE ON payments FOR EACH ROW EXECUTE FUNCTION payments_test_slow_refund()');
    $refunded = FakeGateway::webhookPayload('payment.refunded', 'fake_ch_lock', $payment->amount(), $payment->id, 'evt_lock_refund');
    $captured = FakeGateway::webhookPayload('payment.captured', 'fake_ch_lock', $payment->amount(), $payment->id, 'evt_lock_capture');
    $captureBody = json_encode($captured, JSON_THROW_ON_ERROR);
    $refundBody = json_encode($refunded, JSON_THROW_ON_ERROR);

    try {
        Concurrency::run([
            fn (): bool => app(HandleWebhook::class)->handle($refundBody, WebhookSignature::sign($refundBody)),
            function () use ($captureBody): bool {
                usleep(100_000);

                return app(HandleWebhook::class)->handle($captureBody, WebhookSignature::sign($captureBody));
            },
        ]);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS payments_test_slow_refund ON payments');
        DB::unprepared('DROP FUNCTION IF EXISTS payments_test_slow_refund()');
    }

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Refunded)
        ->and(PaymentWebhookEvent::query()->whereIn('event_id', ['evt_lock_capture', 'evt_lock_refund'])->count())->toBe(2);
});

it('applies one transition and stores one event for 20 parallel deliveries of the same webhook', function (): void {
    $member = Member::factory()->create();
    $token = $member->createToken('webhook')->plainTextToken;
    $payment = Payment::factory()->create(['member_id' => $member->id]);
    $event = FakeGateway::webhookPayload('payment.captured', (string) $payment->provider_ref, $payment->amount(), $payment->id, 'evt_parallel');
    [$raw, $headers] = signedWebhook($event);

    $responses = Concurrency::requests(20, fn (int $i): array => [
        'method' => 'POST',
        'uri' => '/api/webhooks/payments/fake',
        'token' => $token,
        'body' => $event,
        'headers' => ['Fake-Signature' => $headers['Fake-Signature']],
    ]);

    $duplicates = array_map(fn (array $r): mixed => $r['json']['data']['duplicate'] ?? null, $responses);

    expect(array_unique(array_column($responses, 'status')))->toBe([200])
        ->and(count(array_filter($duplicates, fn (mixed $d): bool => $d === false)))->toBe(1)
        ->and(count(array_filter($duplicates, fn (mixed $d): bool => $d === true)))->toBe(19)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and(PaymentWebhookEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Captured);

    expect($raw)->toBe(json_encode($event));
});

it('applies different events for one payment in a serial order under parallel delivery', function (): void {
    $member = Member::factory()->create();
    $token = $member->createToken('webhook')->plainTextToken;
    $payment = Payment::factory()->pending()->create(['member_id' => $member->id]);
    $types = ['payment.authorized', 'payment.captured', 'payment.refunded', 'payment.failed'];
    $events = [];

    foreach ($types as $i => $type) {
        $events[] = FakeGateway::webhookPayload($type, 'fake_ch_RACE', $payment->amount(), $payment->id, "evt_{$i}");
    }

    $responses = Concurrency::requests(20, function (int $i) use ($events, $token): array {
        $event = $events[$i % 4];

        return [
            'method' => 'POST',
            'uri' => '/api/webhooks/payments/fake',
            'token' => $token,
            'body' => $event,
            'headers' => ['Fake-Signature' => signedWebhook($event)[1]['Fake-Signature']],
        ];
    });

    expect(array_unique(array_column($responses, 'status')))->toBe([200])
        ->and(PaymentWebhookEvent::query()->count())->toBe(4)
        ->and($payment->fresh()?->status)->toBeIn([PaymentStatus::Authorized, PaymentStatus::Captured, PaymentStatus::Refunded, PaymentStatus::Failed])
        ->and($payment->fresh()?->provider_ref)->toBeIn([null, 'fake_ch_RACE']);
});

it('ends either attached and captured or voided, never both, for 20 parallel void and attach calls', function (): void {
    $member = Member::factory()->create();

    foreach (range(1, 3) as $round) {
        $data = app(AuthorizePayment::class)->handle(authorizeInput($member, 600 + $round, 55000));

        $closures = [];

        for ($i = 0; $i < 20; $i++) {
            $closures[] = $i % 2 === 0
                ? function () use ($data): string {
                    app(VoidPayment::class)->handle($data->id);

                    return 'void';
                }
            : function () use ($data, $i): string {
                try {
                    DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($data->id, 1000 + $i));

                    return 'attached';
                } catch (PaymentConflictException) {
                    return 'conflict';
                }
            };
        }

        $results = Concurrency::run($closures);
        $payment = Payment::query()->findOrFail($data->id);
        $charge = collect(fakeGateway()->charges())->firstWhere('provider_ref', $payment->provider_ref);
        $attached = count(array_filter($results, fn (string $r): bool => $r === 'attached'));

        if ($payment->status === PaymentStatus::Refunded) {
            expect($attached)->toBe(0)
                ->and($payment->reservation_id)->toBeNull()
                ->and($charge['status'])->toBe('refunded');
        } else {
            // The attach won: exactly one attach succeeded and its capture job ran after commit.
            expect($attached)->toBe(1)
                ->and($payment->status)->toBe(PaymentStatus::Captured)
                ->and($payment->reservation_id)->not->toBeNull()
                ->and($charge['status'])->toBe('captured');
        }
    }
});
