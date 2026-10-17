<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\MarkPaymentForCapture;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Data\GatewayResult;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Jobs\CapturePayment;
use App\Modules\Payments\Jobs\RefundPayment;
use App\Modules\Payments\Models\Payment;
use App\Shared\Money\Money;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function (): void {
    paymentsSecret();
    Http::fake();
});

function authorizedPayment(string $token = 'tok_ok', int $holdId = 1): Payment
{
    $data = app(AuthorizePayment::class)->handle(authorizeInput(null, $holdId, 55000, $token));

    return Payment::query()->findOrFail($data->id);
}

/** A gateway whose refund always fails or times out; everything else succeeds. */
function brokenRefundGateway(bool $timeout): PaymentGateway
{
    return new class($timeout) implements PaymentGateway
    {
        public int $refunds = 0;

        public function __construct(private readonly bool $timeout) {}

        public function authorize(AuthorizeRequest $request): GatewayResult
        {
            return new GatewayResult(GatewayStatus::Authorized, 'x');
        }

        public function capture(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            return new GatewayResult(GatewayStatus::Captured, $providerRef);
        }

        public function refund(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            $this->refunds++;

            return $this->timeout
                ? throw new GatewayTimeoutException
                : new GatewayResult(GatewayStatus::Failed, $providerRef, 'boom');
        }

        public function payout(string $destination, Money $amount, string $idempotencyKey): GatewayResult
        {
            return new GatewayResult(GatewayStatus::PaidOut, 'po');
        }
    };
}

it('captures an attached authorized payment through the queued job', function (): void {
    $payment = authorizedPayment();
    $payment->update(['reservation_id' => 5]);

    CapturePayment::dispatch($payment->id);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('captured');
});

it('does not capture an unattached, already captured or voided payment', function (): void {
    $payment = authorizedPayment();

    CapturePayment::dispatch($payment->id);
    expect($payment->fresh()?->status)->toBe(PaymentStatus::Authorized)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('authorized');

    $payment->update(['status' => PaymentStatus::Refunded, 'reservation_id' => 5]);
    CapturePayment::dispatch($payment->id);
    CapturePayment::dispatch(999_999);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Refunded)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('authorized');
});

it('retries a failed capture and ends captured when a later attempt succeeds', function (): void {
    $payment = authorizedPayment();
    $payment->update(['reservation_id' => 5]);
    fakeGateway()->failNextCapture();
    $job = new CapturePayment($payment->id);

    expect(fn () => $job->handle(fakeGateway()))->toThrow(RuntimeException::class)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Authorized);

    $job->handle(fakeGateway());

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured);
});

it('ends capture_failed and logs critical for tok_capture_fail after the final attempt', function (): void {
    Log::spy();
    $payment = authorizedPayment('tok_capture_fail');
    $payment->update(['reservation_id' => 5]);

    // The sync queue has no retries: the first failure is final and calls failed().
    expect(fn () => CapturePayment::dispatch($payment->id))->toThrow(RuntimeException::class);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::CaptureFailed);
    Log::shouldHaveReceived('critical')->once();
});

it('treats a gateway timeout during capture as a retryable failure', function (): void {
    $payment = authorizedPayment();
    $payment->update(['reservation_id' => 5]);
    $gateway = new class implements PaymentGateway
    {
        public function authorize(AuthorizeRequest $request): GatewayResult
        {
            throw new GatewayTimeoutException;
        }

        public function capture(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            throw new GatewayTimeoutException;
        }

        public function refund(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            throw new GatewayTimeoutException;
        }

        public function payout(string $destination, Money $amount, string $idempotencyKey): GatewayResult
        {
            throw new GatewayTimeoutException;
        }
    };

    expect(fn () => (new CapturePayment($payment->id))->handle($gateway))->toThrow(GatewayTimeoutException::class)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Authorized);
});

it('failed() does not touch a payment a webhook already captured', function (): void {
    Log::spy();
    $payment = authorizedPayment();
    $payment->update(['status' => PaymentStatus::Captured, 'reservation_id' => 5]);

    (new CapturePayment($payment->id))->failed(new RuntimeException('late'));

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured);
    Log::shouldNotHaveReceived('critical');
});

/** Records every capture job the (sync) queue starts; the sync driver honours afterCommit. */
function recordQueuedCaptures(): ArrayObject
{
    $queued = new ArrayObject;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use ($queued): void {
        if ($event->job->resolveName() === CapturePayment::class) {
            $queued[] = $event->job->getJobId();
        }
    });

    return $queued;
}

it('attaches a payment once and queues the capture job only after commit', function (): void {
    $queued = recordQueuedCaptures();
    $payment = authorizedPayment();

    DB::transaction(function () use ($payment, $queued): void {
        app(MarkPaymentForCapture::class)->handle($payment->id, 77);

        expect($queued->count())->toBe(0)
            ->and($payment->fresh()?->reservation_id)->toBe(77);
    });

    expect($queued->count())->toBe(1)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Captured);
});

it('captures end to end after the confirming transaction commits', function (): void {
    $payment = authorizedPayment();

    DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($payment->id, 77));

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Captured)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('captured');
});

it('queues no capture job when the surrounding transaction rolls back', function (): void {
    $queued = recordQueuedCaptures();
    $payment = authorizedPayment();

    try {
        DB::transaction(function () use ($payment): void {
            app(MarkPaymentForCapture::class)->handle($payment->id, 77);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($payment->fresh()?->reservation_id)->toBeNull()
        ->and($queued->count())->toBe(0)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('authorized');
});

it('rejects a second attach for another reservation and queues nothing more', function (): void {
    $queued = recordQueuedCaptures();
    $payment = authorizedPayment();
    DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($payment->id, 77));

    expect(fn () => DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($payment->id, 78)))
        ->toThrow(PaymentConflictException::class)
        ->and($payment->fresh()?->reservation_id)->toBe(77)
        ->and($queued->count())->toBe(1);
});

it('rejects an attach outside a transaction, of an unknown payment, or of a non-authorized one', function (): void {
    $payment = authorizedPayment();

    expect(fn () => app(MarkPaymentForCapture::class)->handle($payment->id, 1))->toThrow(LogicException::class)
        ->and($payment->fresh()?->reservation_id)->toBeNull()
        ->and(fn () => DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle(999_999, 1)))->toThrow(PaymentConflictException::class);

    foreach ([PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::TimedOut, PaymentStatus::Refunded, PaymentStatus::Captured, PaymentStatus::CaptureFailed] as $status) {
        $payment->update(['status' => $status]);

        expect(fn () => DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($payment->id, 1)))
            ->toThrow(PaymentConflictException::class);
    }
});

it('voids an unattached authorized payment exactly once', function (): void {
    $payment = authorizedPayment();

    app(VoidPayment::class)->handle($payment->id);
    app(VoidPayment::class)->handle($payment->id);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Refunded)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('refunded')
        ->and(Redis::connection()->get('fakegw:idem:payment:'.$payment->id.':void'))->toBe($payment->provider_ref);
});

it('is a no-op for attached, failed, timed out, pending, refunded and captured payments', function (PaymentStatus $status, ?int $reservation): void {
    $payment = authorizedPayment();
    $payment->update(['status' => $status, 'reservation_id' => $reservation]);

    app(VoidPayment::class)->handle($payment->id);

    expect($payment->fresh()?->status)->toBe($status)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('authorized');
})->with([
    'attached' => [PaymentStatus::Authorized, 5],
    'failed' => [PaymentStatus::Failed, null],
    'timed out' => [PaymentStatus::TimedOut, null],
    'pending' => [PaymentStatus::Pending, null],
    'refunded' => [PaymentStatus::Refunded, null],
    'captured' => [PaymentStatus::Captured, 5],
]);

it('ignores an unknown payment id', function (): void {
    app(VoidPayment::class)->handle(999_999);

    expect(fakeGateway()->charges())->toBe([]);
});

it('refuses to run inside a transaction', function (): void {
    $payment = authorizedPayment();

    expect(fn () => DB::transaction(fn () => app(VoidPayment::class)->handle($payment->id)))->toThrow(LogicException::class)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Authorized);
});

it('makes a later attach fail once the void has won', function (): void {
    $payment = authorizedPayment();
    app(VoidPayment::class)->handle($payment->id);

    expect(fn () => DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($payment->id, 9)))
        ->toThrow(PaymentConflictException::class)
        ->and($payment->fresh()?->reservation_id)->toBeNull();
});

it('makes a void a no-op once the attach has won', function (): void {
    Queue::fake();
    $payment = authorizedPayment();
    DB::transaction(fn () => app(MarkPaymentForCapture::class)->handle($payment->id, 9));

    app(VoidPayment::class)->handle($payment->id);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Authorized)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('authorized');
});

it('queues a RefundPayment retry when the gateway refund fails or times out', function (bool $timeout): void {
    Queue::fake();
    $payment = authorizedPayment();
    $gateway = brokenRefundGateway($timeout);
    app()->instance(PaymentGateway::class, $gateway);

    (new VoidPayment($gateway))->handle($payment->id);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Refunded)
        ->and($gateway->refunds)->toBe(1);
    Queue::assertPushed(RefundPayment::class, fn (RefundPayment $job) => $job->paymentId === $payment->id);
})->with([false, true]);

it('does not queue a retry when the refund succeeds', function (): void {
    Queue::fake();
    $payment = authorizedPayment();

    app(VoidPayment::class)->handle($payment->id);

    Queue::assertNothingPushed();
});

it('refunds through RefundPayment for an already voided payment and is repeatable', function (): void {
    $payment = authorizedPayment();
    $payment->update(['status' => PaymentStatus::Refunded]);

    RefundPayment::dispatch($payment->id);
    RefundPayment::dispatch($payment->id);

    expect(fakeGateway()->charges()[0]['status'])->toBe('refunded')
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Refunded);
});

it('RefundPayment moves a timed out orphan to refunded and skips other states', function (): void {
    $orphan = authorizedPayment();
    $orphan->update(['status' => PaymentStatus::TimedOut]);
    $live = authorizedPayment('tok_ok', 2);

    RefundPayment::dispatch($orphan->id);
    RefundPayment::dispatch($live->id);
    RefundPayment::dispatch(999_999);

    expect($orphan->fresh()?->status)->toBe(PaymentStatus::Refunded)
        ->and($live->fresh()?->status)->toBe(PaymentStatus::Authorized)
        ->and(array_column(fakeGateway()->charges(), 'status', 'provider_ref'))->toEqual([
            (string) $orphan->provider_ref => 'refunded',
            (string) $live->provider_ref => 'authorized',
        ]);
});

it('RefundPayment skips a payment without a provider ref', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::TimedOut, 'provider_ref' => null]);

    RefundPayment::dispatch($payment->id);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::TimedOut)
        ->and(fakeGateway()->charges())->toBe([]);
});

it('RefundPayment fails loudly and logs critical on its final failure', function (): void {
    Log::spy();
    $payment = authorizedPayment();
    $payment->update(['status' => PaymentStatus::Refunded]);
    $gateway = brokenRefundGateway(false);

    expect(fn () => (new RefundPayment($payment->id))->handle($gateway))->toThrow(RuntimeException::class);

    (new RefundPayment($payment->id))->failed(new RuntimeException('final'));

    Log::shouldHaveReceived('critical')->once();
});
