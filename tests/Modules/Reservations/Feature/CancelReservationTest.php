<?php

declare(strict_types=1);

use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Enums\AccountKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Modules\Reservations\Support\Booking;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $this->member = Fixtures::member();
    $this->token = Fixtures::token($this->member);
    $this->slot = Booking::slot();
    $this->venueId = $this->slot->venue_id;
});

/** Books a reservation paid entirely by card (quote 75000) at the frozen "now". */
function bookedByCard(): int
{
    return Booking::book(test()->token, test()->slot, 'tok_ok');
}

function releasedByCancel(): array
{
    return Fixtures::outbox('slot.released')->filter(fn ($row): bool => $row->payload['reason'] === 'cancelled')->values()->all();
}

it('cancels exactly 8 hours before starts_at as early (D15): full refund to credit, nothing forfeited', function (): void {
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(8));

    $response = Booking::cancel($this->token, $id)->assertOk();

    expect($response->json('data.reservation.status'))->toBe('cancelled_early')
        ->and($response->json('data.reservation.cancelled_at'))->toBe('2026-11-07T17:00:00Z')
        ->and($response->json('data.refund'))->toBe([
            'credit_cents' => 75000,
            'forfeited_cents' => 0,
            'currency' => 'USD',
            'late' => false,
        ])
        ->and(Booking::credit($this->member))->toBe(75000)
        ->and(Booking::venuePayable($this->venueId))->toBe(0)
        ->and(Booking::platform())->toBe(0)
        ->and($this->slot->fresh()->booked_count)->toBe(0)
        ->and(Fixtures::gate($this->slot))->toBe(4)
        ->and(Reservation::query()->sole()->status->value)->toBe('cancelled_early');

    $released = releasedByCancel();
    $early = Booking::transactions('cancel_early');

    expect($released)->toHaveCount(1)
        ->and($released[0]->payload['available'])->toBe(4)
        ->and($early)->toHaveCount(1)
        ->and(Booking::transactions('cancel_late'))->toBe([])
        ->and(Booking::entries($early[0]))->toBe([
            "venue:{$this->venueId}:venue_payable" => 75000,
            'member:'.$this->member->id.':credit' => -75000,
        ]);

    Booking::assertLedgerClean();
});

it('cancels one second inside the window as late: 50% to the member, 25% to the platform, 75% leaves venue payable', function (): void {
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(8)->addSecond());

    $response = Booking::cancel($this->token, $id)->assertOk();

    expect($response->json('data.reservation.status'))->toBe('cancelled_late')
        ->and($response->json('data.refund'))->toBe([
            'credit_cents' => 37500,
            'forfeited_cents' => 37500,
            'currency' => 'USD',
            'late' => true,
        ])
        ->and(Booking::credit($this->member))->toBe(37500)
        ->and(Booking::platform())->toBe(18750)
        ->and(Booking::venuePayable($this->venueId))->toBe(75000 - 56250)
        ->and($this->slot->fresh()->booked_count)->toBe(0)
        ->and(Fixtures::gate($this->slot))->toBe(4)
        ->and(releasedByCancel())->toHaveCount(1);

    $late = Booking::transactions('cancel_late');

    expect($late)->toHaveCount(1)
        ->and(Booking::entries($late[0]))->toBe([
            "venue:{$this->venueId}:venue_payable" => 56250,
            'member:'.$this->member->id.':credit' => -37500,
            'system:0:platform' => -18750,
        ]);

    Booking::assertLedgerClean();
});

it('treats a cancel one microsecond past the boundary as late', function (): void {
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(8)->addMicrosecond());

    Booking::cancel($this->token, $id)->assertOk()->assertJsonPath('data.refund.late', true);
});

it('treats a cancel one microsecond before the boundary as early', function (): void {
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(8)->subMicrosecond());

    Booking::cancel($this->token, $id)->assertOk()->assertJsonPath('data.refund.late', false);
});

it('refunds a card-paid reservation to wallet credit and leaves the payment captured', function (): void {
    Booking::fund($this->member, 20000);
    $id = Booking::book($this->token, $this->slot, 'tok_ok');
    $payment = DB::table('payments')->sole();

    expect($payment->status)->toBe('captured');

    Booking::cancel($this->token, $id)->assertOk()->assertJsonPath('data.refund.credit_cents', 75000);

    expect(Booking::credit($this->member))->toBe(75000)
        ->and(DB::table('payments')->sole()->status)->toBe('captured')
        ->and(DB::table('payments')->sole()->reservation_id)->toBe($payment->reservation_id)
        ->and(Booking::gateway()->charges()[0]['status'])->toBe('captured')
        ->and(Booking::cardClearing())->toBe(55000);

    Booking::assertLedgerClean();
});

it('rejects a cancel at starts_at and after it: 409 reservation_started, reservation untouched', function (): void {
    $id = bookedByCard();
    $before = Booking::footprint();

    foreach ([Booking::startsAt(), Booking::startsAt()->addSecond(), Booking::startsAt()->addHours(3)] as $moment) {
        $this->travelTo($moment);

        Booking::cancel($this->token, $id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'reservation_started');
    }

    expect(Reservation::query()->sole()->status->value)->toBe('confirmed')
        ->and($this->slot->fresh()->booked_count)->toBe(1)
        ->and(Booking::footprint())->toBe($before);
});

it('still cancels one microsecond before starts_at (late)', function (): void {
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subMicrosecond());

    Booking::cancel($this->token, $id)->assertOk()->assertJsonPath('data.refund.late', true);
});

it('refuses to cancel twice under a new key: 409 reservation_not_cancellable', function (): void {
    $id = bookedByCard();
    Booking::cancel($this->token, $id)->assertOk();
    $before = Booking::footprint();

    Booking::cancel($this->token, $id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'reservation_not_cancellable')
        ->assertJsonPath('error.details.status', 'cancelled_early');

    expect(Booking::footprint())->toBe($before)
        ->and($this->slot->fresh()->booked_count)->toBe(0);

    Booking::assertLedgerClean();
});

it('replays a cancel with the same key: identical 200, nothing written again', function (): void {
    $id = bookedByCard();
    $first = Booking::cancel($this->token, $id, 'cancel-key')->assertOk();
    $before = Booking::footprint();

    $second = Booking::cancel($this->token, $id, 'cancel-key')->assertOk();

    expect($second->getContent())->toBe($first->getContent())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and(Booking::footprint())->toBe($before);
});

it('moves the early/late boundary with reservations.cancel_window_hours', function (): void {
    config(['reservations.cancel_window_hours' => 4]);
    $early = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(5));

    // 5 hours out is early under a 4-hour window (it is late under the default 8, see below).
    Booking::cancel($this->token, $early)->assertOk()->assertJsonPath('data.refund.late', false);

    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $atBoundary = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(4));

    Booking::cancel($this->token, $atBoundary)->assertOk()->assertJsonPath('data.refund.late', false);

    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $inside = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(4)->addSecond());

    Booking::cancel($this->token, $inside)->assertOk()->assertJsonPath('data.refund.late', true);

    Booking::assertLedgerClean();
});

it('treats 5 hours out as late under the default 8-hour window', function (): void {
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHours(5));

    Booking::cancel($this->token, $id)->assertOk()->assertJsonPath('data.refund.late', true);
});

it('follows the configured late-cancel split', function (): void {
    config(['wallet.late_cancel.member_bps' => 3333, 'wallet.late_cancel.platform_bps' => 3333]);
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHour());

    $response = Booking::cancel($this->token, $id)->assertOk();

    // D14: floors, the remainder stays with the venue.
    expect($response->json('data.refund.credit_cents'))->toBe(24997)
        ->and($response->json('data.refund.forfeited_cents'))->toBe(50003)
        ->and(Booking::platform())->toBe(24997)
        ->and(Booking::venuePayable($this->venueId))->toBe(75000 - 49994);

    Booking::assertLedgerClean();
});

it('handles a 100% forfeit config: no ledger transaction, null transaction id tolerated, table still released', function (): void {
    config(['wallet.late_cancel.member_bps' => 0, 'wallet.late_cancel.platform_bps' => 0]);
    $id = bookedByCard();
    $this->travelTo(Booking::startsAt()->subHour());
    $before = DB::table('ledger_transactions')->count();

    $response = Booking::cancel($this->token, $id)->assertOk();

    expect($response->json('data.reservation.status'))->toBe('cancelled_late')
        ->and($response->json('data.refund'))->toBe([
            'credit_cents' => 0,
            'forfeited_cents' => 75000,
            'currency' => 'USD',
            'late' => true,
        ])
        ->and(DB::table('ledger_transactions')->count())->toBe($before)
        ->and(Booking::transactions('cancel_late'))->toBe([])
        ->and(Booking::credit($this->member))->toBe(0)
        ->and(Booking::venuePayable($this->venueId))->toBe(75000)
        ->and($this->slot->fresh()->booked_count)->toBe(0)
        ->and(releasedByCancel())->toHaveCount(1);

    Booking::assertLedgerClean();
});

it('lets the freed table be booked again after a cancel', function (): void {
    $slot = Booking::slot(['capacity' => 1]);
    $id = Booking::book($this->token, $slot, 'tok_ok');
    $other = Fixtures::token(Fixtures::member());

    Booking::hold($other, $slot)->assertStatus(409)->assertJsonPath('error.code', 'sold_out');

    Booking::cancel($this->token, $id)->assertOk();

    Booking::book($other, $slot, 'tok_ok');

    expect($slot->fresh()->booked_count)->toBe(1);
});

it('treats another member\'s reservation and an unknown id as not found', function (): void {
    $id = bookedByCard();
    $other = Fixtures::token(Fixtures::member());
    $before = Booking::footprint();

    Booking::cancel($other, $id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    Booking::cancel($this->token, 987654)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    expect(Booking::footprint())->toBe($before)
        ->and(Reservation::query()->sole()->status->value)->toBe('confirmed');
});

it('requires a token and an Idempotency-Key to cancel', function (): void {
    $id = bookedByCard();

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->postJson("/api/reservations/{$id}/cancel", [], ['Idempotency-Key' => 'k'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

    $this->withToken($this->token)->postJson("/api/reservations/{$id}/cancel")
        ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
});

it('rolls the whole cancel back if the refund fails', function (): void {
    $id = bookedByCard();

    $this->partialMock(PostCancellationRefund::class, function ($mock): void {
        $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('ledger down'));
    });

    Booking::cancel($this->token, $id)->assertStatus(500);

    expect(Reservation::query()->sole()->status->value)->toBe('confirmed')
        ->and(Reservation::query()->sole()->cancelled_at)->toBeNull()
        ->and($this->slot->fresh()->booked_count)->toBe(1)
        ->and(releasedByCancel())->toHaveCount(0);
});

it('credits only member credit, never coupons, however the booking was paid', function (): void {
    Booking::fund($this->member, 30000, AccountKind::Coupon);
    $id = Booking::book($this->token, $this->slot, 'tok_ok');

    Booking::cancel($this->token, $id)->assertOk();

    expect(Booking::credit($this->member))->toBe(75000)
        ->and(Booking::balance('member', $this->member->id, AccountKind::Coupon))->toBe(0);

    Booking::assertLedgerClean();
});
