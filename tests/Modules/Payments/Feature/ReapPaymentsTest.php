<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Console\ReapPayments;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Data\GatewayResult;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function (): void {
    paymentsSecret();
    Http::fake();
});

it('reaps only pending payments older than the configured threshold', function (): void {
    $this->travelTo(now()->startOfSecond());
    $member = Member::factory()->create();
    $stale = Payment::factory()->pending()->create(['member_id' => $member->id, 'created_at' => now()->subSeconds(121)]);
    $boundary = Payment::factory()->pending()->create(['member_id' => $member->id, 'created_at' => now()->subSeconds(120)]);
    $fresh = Payment::factory()->pending()->create(['member_id' => $member->id, 'created_at' => now()->subSeconds(119)]);

    Artisan::call('payments:reap');

    expect($stale->fresh()?->status)->toBe(PaymentStatus::TimedOut)
        ->and($boundary->fresh()?->status)->toBe(PaymentStatus::Pending)
        ->and($fresh->fresh()?->status)->toBe(PaymentStatus::Pending);

    Artisan::call('payments:reap');
    expect($stale->fresh()?->status)->toBe(PaymentStatus::TimedOut);
});

it('voids only old unattached authorizations and leaves attached and younger payments alone', function (): void {
    $old = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 401));
    $young = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 402));
    $attached = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 403));
    Payment::query()->whereKey($old->id)->update(['created_at' => now()->subSeconds(421)]);
    Payment::query()->whereKey($attached->id)->update(['created_at' => now()->subSeconds(421), 'reservation_id' => 77]);

    Artisan::call('payments:reap');

    expect($old->id)->toBeInt()
        ->and(Payment::query()->findOrFail($old->id)->status)->toBe(PaymentStatus::Refunded)
        ->and(Payment::query()->findOrFail($young->id)->status)->toBe(PaymentStatus::Authorized)
        ->and(Payment::query()->findOrFail($attached->id)->status)->toBe(PaymentStatus::Authorized);

    Artisan::call('payments:reap');
    expect(Payment::query()->findOrFail($old->id)->status)->toBe(PaymentStatus::Refunded);
});

it('does not ask VoidPayment to process an attached authorization', function (): void {
    $old = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 404));
    $attached = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 405));
    Payment::query()->whereKey($old->id)->update(['created_at' => now()->subSeconds(421)]);
    Payment::query()->whereKey($attached->id)->update(['created_at' => now()->subSeconds(421), 'reservation_id' => 78]);
    $void = Mockery::mock(VoidPayment::class, [app(PaymentGateway::class)])->makePartial();
    $void->shouldReceive('handle')->once()->with($old->id)->passthru();
    $void->shouldNotReceive('handle')->with($attached->id);
    app()->instance(VoidPayment::class, $void);

    (new ReapPayments)->handle($void);

    expect(Payment::query()->findOrFail($old->id)->status)->toBe(PaymentStatus::Refunded)
        ->and(Payment::query()->findOrFail($attached->id)->status)->toBe(PaymentStatus::Authorized);
});

it('uses the exact hold ttl plus grace orphan threshold', function (): void {
    $this->travelTo(now()->startOfSecond());
    config(['inventory.hold_ttl_seconds' => 300, 'payments.reap.orphan_grace_seconds' => 120]);
    $boundary = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 770));
    $inside = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 771));
    Payment::query()->whereKey($boundary->id)->update(['created_at' => now()->subSeconds(421)]);
    Payment::query()->whereKey($inside->id)->update(['created_at' => now()->subSeconds(419)]);

    Artisan::call('payments:reap');

    expect(Payment::query()->findOrFail($boundary->id)->status)->toBe(PaymentStatus::Refunded)
        ->and(Payment::query()->findOrFail($inside->id)->status)->toBe(PaymentStatus::Authorized);
});

it('continues reaping the batch when one void refund throws', function (): void {
    Queue::fake();
    $first = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 780));
    $second = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 781));
    Payment::query()->whereIn('id', [$first->id, $second->id])->update(['created_at' => now()->subSeconds(421)]);
    $gateway = new class implements PaymentGateway
    {
        public int $refundCalls = 0;

        public function authorize(AuthorizeRequest $request): GatewayResult
        {
            return new GatewayResult(GatewayStatus::Authorized, 'ref');
        }

        public function capture(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            return new GatewayResult(GatewayStatus::Captured, $providerRef);
        }

        public function refund(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            if (++$this->refundCalls === 1) {
                throw new RuntimeException('transient refund error');
            }

            return new GatewayResult(GatewayStatus::Refunded, $providerRef);
        }

        public function payout(string $destination, Money $amount, string $idempotencyKey): GatewayResult
        {
            return new GatewayResult(GatewayStatus::PaidOut, 'po');
        }
    };
    app()->instance(PaymentGateway::class, $gateway);

    Artisan::call('payments:reap');

    expect($gateway->refundCalls)->toBe(2)
        ->and(Payment::query()->findOrFail($first->id)->status)->toBe(PaymentStatus::Refunded)
        ->and(Payment::query()->findOrFail($second->id)->status)->toBe(PaymentStatus::Refunded);
});

it('registers the reaper for every minute', function (): void {
    $events = app(Schedule::class)->events();

    expect(collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'payments:reap')))->not->toBeNull();
});
