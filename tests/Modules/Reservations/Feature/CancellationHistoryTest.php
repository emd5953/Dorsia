<?php

declare(strict_types=1);

use App\Modules\Reservations\Actions\ListReservations;
use App\Modules\Wallet\Actions\GetCancellationCredits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Modules\Reservations\Support\Booking;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $this->member = Fixtures::member();
    $this->token = Fixtures::token($this->member);
    $this->slot = Booking::slot();
    $this->reservationId = Booking::book($this->token, $this->slot, 'tok_ok');
});

it('reads the posted refund after policy and clock changes without writes', function (bool $late, int $memberBps, int $platformBps, int $credit): void {
    config(['wallet.late_cancel.member_bps' => $memberBps, 'wallet.late_cancel.platform_bps' => $platformBps]);
    if ($late) {
        $this->travelTo(Booking::startsAt()->subHour());
    }
    $cancel = Booking::cancel($this->token, $this->reservationId, 'history-cancel')->assertOk();
    $refund = ['credit_cents' => $credit, 'forfeited_cents' => 75000 - $credit, 'currency' => 'USD', 'late' => $late];
    expect($cancel->json('data.refund'))->toBe($refund)
        ->and($cancel->json('data.reservation.refund'))->toBe($refund);

    // Even an invalid current split must not affect an already-posted receipt.
    config(['wallet.late_cancel.member_bps' => 10000, 'wallet.late_cancel.platform_bps' => 10000, 'reservations.cancel_window_hours' => 48]);
    $this->travelTo(Booking::startsAt()->addMonth());
    $before = Booking::footprint();
    $balance = Booking::credit($this->member);
    for ($i = 0; $i < 2; $i++) {
        Booking::get("/api/reservations/{$this->reservationId}", $this->token)->assertOk()->assertJsonPath('data.refund', $refund);
        Booking::get('/api/reservations', $this->token)->assertOk()->assertJsonPath('data.0.refund', $refund);
    }
    $replay = Booking::cancel($this->token, $this->reservationId, 'history-cancel')->assertOk();
    expect($replay->getContent())->toBe($cancel->getContent())
        ->and(Booking::footprint())->toBe($before)
        ->and(Booking::credit($this->member))->toBe($balance);
})->with([
    'early' => [false, 5000, 2500, 75000],
    'late fractional cents' => [true, 3333, 2500, 24997],
    'zero refund with no posting' => [true, 0, 0, 0],
    'zero member refund with platform posting' => [true, 0, 2500, 0],
    'full late refund' => [true, 10000, 0, 75000],
]);

it('leaves confirmed reservations without refund history', function (): void {
    Booking::get("/api/reservations/{$this->reservationId}", $this->token)->assertOk()->assertJsonPath('data.refund', null);
    Booking::get('/api/reservations', $this->token)->assertOk()->assertJsonPath('data.0.refund', null);
});

it('keeps historical refunds scoped to their member in the API and Wallet action', function (): void {
    Booking::cancel($this->token, $this->reservationId)->assertOk();
    $other = Fixtures::member();
    $token = Fixtures::token($other);
    Booking::get("/api/reservations/{$this->reservationId}", $token)->assertNotFound();
    Booking::get('/api/reservations', $token)->assertOk()->assertJsonCount(0, 'data');
    expect(app(GetCancellationCredits::class)->handle($other->id, [$this->reservationId]))->toBe([])
        ->and(app(GetCancellationCredits::class)->handle($this->member->id, []))->toBe([]);
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->getJson('/api/reservations')->assertUnauthorized();
});

it('batches posted credit reads for multiple cancelled reservations', function (): void {
    $ids = [$this->reservationId, Booking::book($this->token, $this->slot, 'tok_ok'), Booking::book($this->token, $this->slot, 'tok_ok')];
    foreach ($ids as $id) {
        Booking::cancel($this->token, $id)->assertOk();
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $history = app(ListReservations::class)->handle($this->member->id);
        expect(DB::getQueryLog())->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
    }
    expect($history)->toHaveCount(3);
    foreach ($history as $reservation) {
        expect($reservation->cancellationCredit?->cents)->toBe(75000);
    }
});
