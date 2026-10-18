<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Hold;
use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizePaymentInput;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Jobs\CapturePayment;
use App\Modules\Reservations\Actions\ConfirmReservation;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Actions\PlanSpend;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Data\BookingPaymentInput;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Enums\AccountKind;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use App\Shared\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Modules\Reservations\Support\Booking;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $this->member = Fixtures::member();
    $this->token = Fixtures::token($this->member);
    $this->slot = Booking::slot();
});

/** Places a hold for the test member and returns its id. */
function confirmHold(?string $token = null, int $partySize = 2): int
{
    return (int) Booking::hold($token ?? test()->token, test()->slot, $partySize)->assertCreated()->json('data.id');
}

/** Takes the member's credit away through a real ledger posting (another venue), as a concurrent booking would. */
function drainCredit(int $memberId, int $cents): void
{
    DB::transaction(fn () => app(PostBookingPayment::class)->handle(new BookingPaymentInput(
        $memberId,
        9999,
        8888 + $memberId,
        7777,
        new SpendPlan(Money::of(0, 'USD'), Money::of($cents, 'USD'), Money::of(0, 'USD')),
    )));
}

function releasedReasons(): array
{
    return Fixtures::outbox('slot.released')->map(fn ($row): string => $row->payload['reason'])->all();
}

it('confirms card only: 201 Reservation, payment authorized then captured by the job, card clearing and venue payable posted', function (): void {
    Queue::fake();
    $holdId = confirmHold();

    $response = Booking::confirm($this->token, $holdId, 'tok_ok')->assertCreated();

    $reservation = Reservation::query()->sole();
    $payment = DB::table('payments')->sole();

    expect($response->json())->toBe(['data' => [
        'id' => $reservation->id,
        'hold_id' => $holdId,
        'status' => 'confirmed',
        'slot_id' => $this->slot->id,
        'venue_id' => $this->slot->venue_id,
        'party_size' => 2,
        'starts_at' => '2026-11-08T01:00:00Z',
        'local_starts_at' => '2026-11-07T20:00:00-05:00',
        'min_spend_cents' => 75000,
        'currency' => 'USD',
        'payment' => ['coupon_cents' => 0, 'credit_cents' => 0, 'card_cents' => 75000],
        'confirmed_at' => '2026-11-07T15:00:00Z',
        'cancelled_at' => null,
    ]]);

    // Authorized and attached; the capture job is queued, not run inline.
    expect($payment->status)->toBe('authorized')
        ->and((int) $payment->reservation_id)->toBe($reservation->id)
        ->and((int) $payment->amount_cents)->toBe(75000)
        ->and($reservation->payment_id)->toBe((int) $payment->id);
    Queue::assertPushed(CapturePayment::class, fn (CapturePayment $job): bool => $job->paymentId === (int) $payment->id);

    (new CapturePayment((int) $payment->id))->handle(app(PaymentGateway::class));

    expect(DB::table('payments')->where('id', $payment->id)->value('status'))->toBe('captured');

    $booking = Booking::transactions('booking');

    expect($booking)->toHaveCount(1)
        ->and(Booking::entries($booking[0]))->toBe([
            'system:0:cash' => 75000,
            "venue:{$this->slot->venue_id}:venue_payable" => -75000,
        ])
        ->and(Booking::cardClearing())->toBe(75000)
        ->and(Booking::venuePayable($this->slot->venue_id))->toBe(75000);

    $slot = $this->slot->fresh();

    expect($slot->held_count)->toBe(0)
        ->and($slot->booked_count)->toBe(1)
        ->and(Fixtures::gate($this->slot))->toBe(3)
        ->and(Hold::query()->findOrFail($holdId)->status)->toBe('converted');

    $booked = Fixtures::outbox('slot.booked');
    $confirmed = Fixtures::outbox('reservation.confirmed');

    expect($booked)->toHaveCount(1)
        ->and($booked->first()->payload['available'])->toBe(3)
        ->and($confirmed)->toHaveCount(1)
        ->and($confirmed->first()->payload)->toEqual([
            'reservation_id' => $reservation->id,
            'member_id' => $this->member->id,
            'slot_id' => $this->slot->id,
            'venue_id' => $this->slot->venue_id,
            'starts_at' => '2026-11-08T01:00:00Z',
            'party_size' => 2,
            'min_spend_cents' => 75000,
            'currency' => 'USD',
        ]);

    Booking::assertLedgerClean();
});

it('captures the card payment end to end when the queue runs', function (): void {
    Booking::confirm($this->token, confirmHold(), 'tok_ok')->assertCreated();

    expect(DB::table('payments')->sole()->status)->toBe('captured')
        ->and(Booking::gateway()->charges())->toHaveCount(1);
});

it('confirms wallet only: no payment row, credit reduced by the amount, wallet.updated written', function (): void {
    Booking::fund($this->member, 100000);
    $before = Fixtures::outbox('wallet.updated')->count();
    $holdId = confirmHold();

    $response = Booking::confirm($this->token, $holdId, null)->assertCreated();

    expect($response->json('data.payment'))->toBe(['coupon_cents' => 0, 'credit_cents' => 75000, 'card_cents' => 0])
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(Reservation::query()->sole()->payment_id)->toBeNull()
        ->and(Booking::gateway()->authorizeCalls())->toBe(0)
        ->and(Booking::credit($this->member))->toBe(25000)
        ->and(Booking::cardClearing())->toBe(0);

    $wallet = Fixtures::outbox('wallet.updated');

    expect($wallet)->toHaveCount($before + 1)
        ->and($wallet->last()->payload)->toEqual([
            'member_id' => $this->member->id,
            'currency' => 'USD',
            'credit_cents' => 25000,
            'coupon_cents' => 0,
        ]);

    Booking::assertLedgerClean();
});

it('splits wallet and card: 20000 credit on a 75000 quote authorizes exactly 55000', function (): void {
    Booking::fund($this->member, 20000);

    $response = Booking::confirm($this->token, confirmHold(), 'tok_ok')->assertCreated();

    $payment = DB::table('payments')->sole();
    $charges = Booking::gateway()->charges();

    expect($response->json('data.payment'))->toBe(['coupon_cents' => 0, 'credit_cents' => 20000, 'card_cents' => 55000])
        ->and((int) $payment->amount_cents)->toBe(55000)
        ->and($charges)->toHaveCount(1)
        ->and($charges[0]['amount_cents'])->toBe(55000)
        ->and(Booking::gateway()->authorizeCalls())->toBe(1);

    $reservation = Reservation::query()->sole();

    expect($reservation->credit_cents)->toBe(20000)
        ->and($reservation->card_cents)->toBe(55000)
        ->and(Booking::entries(Booking::transactions('booking')[0]))->toBe([
            'member:'.$this->member->id.':credit' => 20000,
            'system:0:cash' => 55000,
            "venue:{$this->slot->venue_id}:venue_payable" => -75000,
        ])
        ->and(Booking::credit($this->member))->toBe(0);

    Booking::assertLedgerClean();
});

it('spends coupons first, then credit, then card', function (): void {
    Booking::fund($this->member, 30000, AccountKind::Coupon);
    Booking::fund($this->member, 20000);

    $response = Booking::confirm($this->token, confirmHold(), 'tok_ok')->assertCreated();

    expect($response->json('data.payment'))->toBe(['coupon_cents' => 30000, 'credit_cents' => 20000, 'card_cents' => 25000])
        ->and(Booking::gateway()->charges()[0]['amount_cents'])->toBe(25000)
        ->and(Booking::balance('member', $this->member->id, AccountKind::Coupon))->toBe(0)
        ->and(Booking::credit($this->member))->toBe(0)
        ->and(Booking::entries(Booking::transactions('booking')[0]))->toBe([
            'member:'.$this->member->id.':coupon' => 30000,
            'member:'.$this->member->id.':credit' => 20000,
            'system:0:cash' => 25000,
            "venue:{$this->slot->venue_id}:venue_payable" => -75000,
        ]);

    Booking::assertLedgerClean();
});

it('leaves credit untouched while coupons cover the price, and takes only what it needs from credit', function (): void {
    Booking::fund($this->member, 10000, AccountKind::Coupon);
    Booking::fund($this->member, 100000);

    $response = Booking::confirm($this->token, confirmHold(), null)->assertCreated();

    expect($response->json('data.payment'))->toBe(['coupon_cents' => 10000, 'credit_cents' => 65000, 'card_cents' => 0])
        ->and(Booking::credit($this->member))->toBe(35000)
        ->and(DB::table('payments')->count())->toBe(0);

    Booking::assertLedgerClean();
});

it('ignores a card token when the wallet covers the price', function (): void {
    Booking::fund($this->member, 75000);

    Booking::confirm($this->token, confirmHold(), 'tok_ok')->assertCreated();

    expect(DB::table('payments')->count())->toBe(0)
        ->and(Booking::gateway()->authorizeCalls())->toBe(0);
});

it('asks for a card when one is needed: 422 card_required with the card portion, hold kept', function (): void {
    Booking::fund($this->member, 20000);
    $holdId = confirmHold();
    $before = Booking::footprint();

    Booking::confirm($this->token, $holdId, null)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'card_required')
        ->assertJsonPath('error.details.card_cents', 55000);

    expect(Hold::query()->findOrFail($holdId)->status)->toBe('active')
        ->and($this->slot->fresh()->held_count)->toBe(1)
        ->and(Booking::footprint())->toBe($before);
});

it('declines: 402 payment_failed, hold released, held_count restored, gate +1, slot.released payment_failed, nothing booked', function (): void {
    $holdId = confirmHold();
    expect(Fixtures::gate($this->slot))->toBe(3);

    Booking::confirm($this->token, $holdId, 'tok_decline')
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'payment_failed')
        ->assertJsonPath('error.details.decline_code', 'card_declined');

    $hold = Hold::query()->findOrFail($holdId);

    expect($hold->status)->toBe('released')
        ->and($hold->close_reason)->toBe('payment_failed')
        ->and($this->slot->fresh()->held_count)->toBe(0)
        ->and($this->slot->fresh()->booked_count)->toBe(0)
        ->and(Fixtures::gate($this->slot))->toBe(4)
        ->and(releasedReasons())->toBe(['payment_failed'])
        ->and(Reservation::query()->count())->toBe(0)
        ->and(DB::table('ledger_transactions')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and(Fixtures::outbox('reservation.confirmed'))->toHaveCount(0)
        ->and(DB::table('payments')->sole()->status)->toBe('failed');

    Booking::assertLedgerClean();
});

it('times out: 504 payment_timeout, hold released with reason payment_timeout', function (): void {
    $holdId = confirmHold();

    Booking::confirm($this->token, $holdId, 'tok_timeout')
        ->assertStatus(504)
        ->assertJsonPath('error.code', 'payment_timeout');

    $hold = Hold::query()->findOrFail($holdId);

    expect($hold->status)->toBe('released')
        ->and($hold->close_reason)->toBe('payment_timeout')
        ->and($this->slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($this->slot))->toBe(4)
        ->and(releasedReasons())->toBe(['payment_timeout'])
        ->and(Reservation::query()->count())->toBe(0)
        ->and(DB::table('ledger_transactions')->count())->toBe(0);

    Booking::assertLedgerClean();
});

it('lets the member hold again right after a decline', function (): void {
    Booking::confirm($this->token, confirmHold(), 'tok_decline')->assertStatus(402);

    $second = (int) Booking::hold($this->token, $this->slot)->assertCreated()->json('data.id');

    Booking::confirm($this->token, $second, 'tok_ok')->assertCreated();

    expect(Reservation::query()->count())->toBe(1);
});

it('rejects a hold that expired at expires_at exactly: 409 hold_expired, nothing authorized, hold expired', function (): void {
    $holdId = confirmHold();
    $expiresAt = Hold::query()->findOrFail($holdId)->expires_at;
    $this->travelTo($expiresAt);

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'hold_expired');

    $hold = Hold::query()->findOrFail($holdId);

    expect($hold->status)->toBe('expired')
        ->and($hold->close_reason)->toBe('expired')
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(Booking::gateway()->authorizeCalls())->toBe(0)
        ->and($this->slot->fresh()->held_count)->toBe(0)
        ->and(releasedReasons())->toBe(['expired'])
        ->and(Reservation::query()->count())->toBe(0);
});

it('still confirms one microsecond before the hold expires', function (): void {
    $holdId = confirmHold();
    $this->travelTo(Hold::query()->findOrFail($holdId)->expires_at->subMicrosecond());

    Booking::confirm($this->token, $holdId, 'tok_ok')->assertCreated();

    expect(Reservation::query()->count())->toBe(1);
});

it('voids the authorization and expires the hold when expiry is only detected inside the transaction', function (): void {
    $holdId = confirmHold();
    $expiresAt = Hold::query()->findOrFail($holdId)->expires_at;
    $real = new AuthorizePayment(app(PaymentGateway::class));

    $this->partialMock(AuthorizePayment::class, function ($mock) use ($real, $expiresAt): void {
        $mock->shouldReceive('handle')->once()->andReturnUsing(function (AuthorizePaymentInput $input) use ($real, $expiresAt) {
            $data = $real->handle($input);
            test()->travelTo($expiresAt);

            return $data;
        });
    });

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'hold_expired');

    $payment = DB::table('payments')->sole();
    $charges = Booking::gateway()->charges();

    expect($payment->status)->toBe(PaymentStatus::Refunded->value)
        ->and($payment->reservation_id)->toBeNull()
        ->and($charges)->toHaveCount(1)
        ->and($charges[0]['status'])->toBe('refunded')
        ->and(Hold::query()->findOrFail($holdId)->status)->toBe('expired')
        ->and(Hold::query()->findOrFail($holdId)->close_reason)->toBe('expired')
        ->and($this->slot->fresh()->held_count)->toBe(0)
        ->and($this->slot->fresh()->booked_count)->toBe(0)
        ->and(releasedReasons())->toBe(['expired'])
        ->and(Reservation::query()->count())->toBe(0)
        ->and(DB::table('ledger_transactions')->count())->toBe(0)
        ->and(Fixtures::outbox('slot.booked'))->toHaveCount(0)
        ->and(Fixtures::outbox('reservation.confirmed'))->toHaveCount(0);

    Booking::assertLedgerClean();
});

it('treats someone else\'s hold as not found and leaves it alone', function (): void {
    $holdId = confirmHold();
    $other = Fixtures::token(Fixtures::member());

    Booking::confirm($other, $holdId, 'tok_ok')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect(Hold::query()->findOrFail($holdId)->status)->toBe('active')
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);

    Booking::confirm($this->token, 987654, 'tok_ok')->assertNotFound();
});

it('voids and releases with confirm_failed when the wallet is drained after the card was authorized, then books again', function (): void {
    Booking::fund($this->member, 20000);
    $holdId = confirmHold();
    $real = new AuthorizePayment(app(PaymentGateway::class));
    $memberId = $this->member->id;

    $this->partialMock(AuthorizePayment::class, function ($mock) use ($real, $memberId): void {
        $drained = false;

        $mock->shouldReceive('handle')->andReturnUsing(function (AuthorizePaymentInput $input) use ($real, $memberId, &$drained) {
            $data = $real->handle($input);

            if (! $drained) {
                $drained = true;
                drainCredit($memberId, 20000);
            }

            return $data;
        });
    });

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'wallet_balance_changed');

    $hold = Hold::query()->findOrFail($holdId);

    expect(DB::table('payments')->sole()->status)->toBe('refunded')
        ->and(Booking::gateway()->charges()[0]['status'])->toBe('refunded')
        ->and($hold->status)->toBe('released')
        ->and($hold->close_reason)->toBe('confirm_failed')
        ->and(releasedReasons())->toBe(['confirm_failed'])
        ->and($this->slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($this->slot))->toBe(4)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(Booking::transactions('booking'))->toHaveCount(1)
        ->and(Fixtures::outbox('reservation.confirmed'))->toHaveCount(0);

    // Only the drain's own posting exists; the member can hold the same slot again and confirm (credit is gone: all card).
    $again = (int) Booking::hold($this->token, $this->slot)->assertCreated()->json('data.id');

    $response = Booking::confirm($this->token, $again, 'tok_ok')->assertCreated();

    expect($response->json('data.payment'))->toBe(['coupon_cents' => 0, 'credit_cents' => 0, 'card_cents' => 75000])
        ->and(DB::table('payments')->where('status', 'captured')->count())->toBe(1);

    Booking::assertLedgerClean();
});

it('keeps the hold when the wallet is drained on a wallet-only plan, and a retry with a new key re-plans', function (): void {
    Booking::fund($this->member, 100000);
    $holdId = confirmHold();
    $real = new PlanSpend;
    $memberId = $this->member->id;

    $this->partialMock(PlanSpend::class, function ($mock) use ($real, $memberId): void {
        $drained = false;

        $mock->shouldReceive('handle')->andReturnUsing(function (int $id, Money $amount) use ($real, $memberId, &$drained) {
            $plan = $real->handle($id, $amount);

            if (! $drained) {
                $drained = true;
                drainCredit($memberId, 100000);
            }

            return $plan;
        });
    });

    Booking::confirm($this->token, $holdId, null)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'wallet_balance_changed');

    expect(Hold::query()->findOrFail($holdId)->status)->toBe('active')
        ->and($this->slot->fresh()->held_count)->toBe(1)
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(releasedReasons())->toBe([]);

    $response = Booking::confirm($this->token, $holdId, 'tok_ok')->assertCreated();

    expect($response->json('data.payment'))->toBe(['coupon_cents' => 0, 'credit_cents' => 0, 'card_cents' => 75000])
        ->and(DB::table('payments')->sole()->status)->toBe('captured');

    Booking::assertLedgerClean();
});

it('answers 500 after an unexpected failure inside the transaction, voids the payment and releases the hold', function (): void {
    $holdId = confirmHold();

    $this->partialMock(PostBookingPayment::class, function ($mock): void {
        $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('ledger exploded'));
    });

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error');

    $hold = Hold::query()->findOrFail($holdId);

    expect(DB::table('payments')->sole()->status)->toBe('refunded')
        ->and(Booking::gateway()->charges()[0]['status'])->toBe('refunded')
        ->and($hold->status)->toBe('released')
        ->and($hold->close_reason)->toBe('confirm_failed')
        ->and(releasedReasons())->toBe(['confirm_failed'])
        ->and($this->slot->fresh()->held_count)->toBe(0)
        ->and($this->slot->fresh()->booked_count)->toBe(0)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(Fixtures::outbox('slot.booked'))->toHaveCount(0)
        ->and(Fixtures::outbox('reservation.confirmed'))->toHaveCount(0);
});

it('keeps the hold after an unexpected failure on a wallet-only plan (nothing was spent)', function (): void {
    Booking::fund($this->member, 100000);
    $holdId = confirmHold();

    $this->partialMock(PostBookingPayment::class, function ($mock): void {
        $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('ledger exploded'));
    });

    Booking::confirm($this->token, $holdId, null)->assertStatus(500);

    expect(Hold::query()->findOrFail($holdId)->status)->toBe('active')
        ->and($this->slot->fresh()->held_count)->toBe(1)
        ->and($this->slot->fresh()->booked_count)->toBe(0)
        ->and(Booking::credit($this->member))->toBe(100000)
        ->and(DB::table('payments')->count())->toBe(0);
});

it('writes the reservation, the ledger and reservation.confirmed inside one transaction', function (): void {
    $holdId = confirmHold();
    $levels = [];

    DB::listen(function ($query) use (&$levels): void {
        if (preg_match('/^insert into "?(reservations|outbox_events|ledger_transactions)"?/i', $query->sql, $match) === 1) {
            $levels[$match[1]][] = DB::transactionLevel() - Transactions::$baseLevel;
        }
    });

    Booking::confirm($this->token, $holdId, 'tok_ok')->assertCreated();

    expect($levels['reservations'])->each->toBeGreaterThanOrEqual(1)
        ->and($levels['ledger_transactions'])->each->toBeGreaterThanOrEqual(1)
        ->and($levels['outbox_events'])->each->toBeGreaterThanOrEqual(1);
});

it('replays an identical confirm byte for byte without writing anything', function (): void {
    Booking::fund($this->member, 20000);
    $holdId = confirmHold();

    $first = Booking::confirm($this->token, $holdId, 'tok_ok', 'confirm-key')->assertCreated();
    $before = Booking::footprint();

    $second = Booking::confirm($this->token, $holdId, 'tok_ok', 'confirm-key')->assertCreated();

    expect($second->getContent())->toBe($first->getContent())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and(Booking::footprint())->toBe($before);

    Booking::assertLedgerClean();
});

it('rejects the same key with a different body: 422 idempotency_mismatch', function (): void {
    $holdId = confirmHold();
    Booking::confirm($this->token, $holdId, 'tok_ok', 'confirm-key')->assertCreated();
    $before = Booking::footprint();

    Booking::confirm($this->token, $holdId, 'tok_decline', 'confirm-key')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_mismatch');

    expect(Booking::footprint())->toBe($before);
});

it('returns the existing reservation when the action is replayed with the same key (domain backstop)', function (): void {
    $holdId = confirmHold();
    $key = new IdempotencyKey($this->member->id, 'domain-key');

    $first = app(ConfirmReservation::class)->handle($this->member->id, $holdId, 'tok_ok', $key);
    $before = Booking::footprint();
    $second = app(ConfirmReservation::class)->handle($this->member->id, $holdId, 'tok_ok', $key);

    expect($second->id)->toBe($first->id)
        ->and($second)->toEqual($first)
        ->and(Booking::footprint())->toBe($before);
});

it('refuses a second confirm of a converted hold under a new key: 409 hold_not_active', function (): void {
    $holdId = confirmHold();
    Booking::confirm($this->token, $holdId, 'tok_ok')->assertCreated();
    $before = Booking::footprint();

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'hold_not_active')
        ->assertJsonPath('error.details.status', 'converted');

    expect(Booking::footprint())->toBe($before);
});

it('refuses a released hold: 409 hold_not_active', function (): void {
    $holdId = confirmHold();
    Booking::confirm($this->token, $holdId, 'tok_decline')->assertStatus(402);

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'hold_not_active')
        ->assertJsonPath('error.details.status', 'released');
});

it('keeps the hold on payment_in_progress and on payment_conflict', function (): void {
    $holdId = confirmHold();
    DB::table('payments')->insert([
        'member_id' => $this->member->id,
        'hold_id' => $holdId,
        'provider' => 'fake',
        'idempotency_key' => "hold:{$holdId}:auth",
        'amount_cents' => 75000,
        'currency' => 'USD',
        'status' => 'pending',
        'created_at' => now()->utc()->format('Y-m-d H:i:s.uP'),
        'updated_at' => now()->utc()->format('Y-m-d H:i:s.uP'),
    ]);

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'payment_in_progress');

    expect(Hold::query()->findOrFail($holdId)->status)->toBe('active');

    DB::table('payments')->where('hold_id', $holdId)->update(['amount_cents' => 1000]);

    Booking::confirm($this->token, $holdId, 'tok_ok')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'payment_conflict');

    expect(Hold::query()->findOrFail($holdId)->status)->toBe('active')
        ->and($this->slot->fresh()->held_count)->toBe(1)
        ->and(Reservation::query()->count())->toBe(0);
});

it('validates the body and requires a token and an Idempotency-Key', function (): void {
    $this->postJson('/api/reservations', ['hold_id' => 1], ['Idempotency-Key' => 'k'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

    $this->withToken($this->token)->postJson('/api/reservations', ['hold_id' => 1])
        ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

    Booking::post('/api/reservations', ['card_token' => 'tok_ok'], $this->token)
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    Booking::post('/api/reservations', ['hold_id' => 'x'], $this->token)
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('lets two members book two tables of the same slot independently', function (): void {
    $other = Fixtures::member();
    $otherToken = Fixtures::token($other);

    Booking::book($this->token, $this->slot, 'tok_ok');
    Booking::book($otherToken, $this->slot, 'tok_ok');

    expect(Reservation::query()->count())->toBe(2)
        ->and($this->slot->fresh()->booked_count)->toBe(2)
        ->and(Booking::venuePayable($this->slot->venue_id))->toBe(150000)
        ->and(Member::query()->count())->toBeGreaterThanOrEqual(2);

    Booking::assertLedgerClean();
});
