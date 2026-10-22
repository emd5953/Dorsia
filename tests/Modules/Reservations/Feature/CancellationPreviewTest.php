<?php

declare(strict_types=1);

use App\Modules\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Modules\Reservations\Support\Booking;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $this->member = Fixtures::member();
    $this->token = Fixtures::token($this->member);
    $this->slot = Booking::slot();
    $this->reservationId = Booking::book($this->token, $this->slot, 'tok_ok');
    $this->previewPath = "/api/reservations/{$this->reservationId}/cancellation-preview";
    $this->cancelPath = "/api/reservations/{$this->reservationId}/cancel";
});

it('previews exactly what cancellation posts at microsecond boundaries without mutating anything', function (int $microseconds, bool $late, int $credit): void {
    $at = Booking::startsAt()->subHours(8)->addMicroseconds($microseconds);
    $this->travelTo($at);
    $before = Booking::footprint();
    $gate = Fixtures::gate($this->slot);
    $preview = Booking::get($this->previewPath, $this->token)->assertOk();
    $refund = $preview->json('data.refund');
    expect($refund)->toBe(['credit_cents' => $credit, 'forfeited_cents' => 75000 - $credit, 'currency' => 'USD', 'late' => $late])
        ->and($preview->json('data.evaluated_at'))->toBe($at->utc()->format('Y-m-d\TH:i:s.u\Z'))
        ->and($preview->json('data.early_cancellation_deadline'))->toBe('2026-11-07T17:00:00.000000Z')
        ->and($preview->headers->get('cache-control'))->toContain('no-store')
        ->and(Booking::footprint())->toBe($before)
        ->and(Fixtures::gate($this->slot))->toBe($gate)
        ->and(Booking::credit($this->member))->toBe(0);
    $cancel = Booking::post($this->cancelPath, ['expected_refund' => $refund], $this->token)->assertOk();
    expect($cancel->json('data.refund'))->toBe($refund)
        ->and(Booking::credit($this->member))->toBe($credit)
        ->and($this->slot->fresh()->booked_count)->toBe(0);
    Booking::assertLedgerClean();
})->with([[-1, false, 75000], [0, false, 75000], [1, true, 37500]]);

it('uses configured timing and floors fractional cents with the posting policy', function (): void {
    config(['reservations.cancel_window_hours' => 4, 'wallet.late_cancel.member_bps' => 3333, 'wallet.late_cancel.platform_bps' => 3333]);
    $this->travelTo(Booking::startsAt()->subHours(5));
    Booking::get($this->previewPath, $this->token)->assertOk()->assertJsonPath('data.refund.late', false)->assertJsonPath('data.cancel_window_hours', 4);
    $this->travelTo(Booking::startsAt()->subHour());
    $refund = Booking::get($this->previewPath, $this->token)->assertOk()->assertJsonPath('data.refund.credit_cents', 24997)->assertJsonPath('data.refund.forfeited_cents', 50003)->json('data.refund');
    expect(Booking::post($this->cancelPath, ['expected_refund' => $refund], $this->token)->assertOk()->json('data.refund'))->toBe($refund);
    Booking::assertLedgerClean();
});

it('requires re-review when the deadline passes and preserves exact successful replay after start', function (): void {
    $this->travelTo(Booking::startsAt()->subHours(8));
    $old = Booking::get($this->previewPath, $this->token)->assertOk()->json('data.refund');
    $this->travelTo(Booking::startsAt()->subHours(8)->addMicrosecond());
    $before = Booking::footprint();
    Booking::post($this->cancelPath, ['expected_refund' => $old], $this->token, 'stale-preview')->assertStatus(409)->assertJsonPath('error.code', 'cancellation_preview_changed');
    expect(Booking::footprint())->toBe($before)->and(Reservation::query()->findOrFail($this->reservationId)->status->value)->toBe('confirmed')->and($this->slot->fresh()->booked_count)->toBe(1);
    $current = Booking::get($this->previewPath, $this->token)->assertOk()->json('data.refund');
    Booking::post($this->cancelPath, ['expected_refund' => $current], $this->token, 'stale-preview')->assertStatus(422)->assertJsonPath('error.code', 'idempotency_mismatch');
    $first = Booking::post($this->cancelPath, ['expected_refund' => $current], $this->token, 'reviewed-preview')->assertOk();
    $after = Booking::footprint();
    $this->travelTo(Booking::startsAt()->addHour());
    $replay = Booking::post($this->cancelPath, ['expected_refund' => $current], $this->token, 'reviewed-preview')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect($replay->getContent())->toBe($first->getContent())->and(Booking::footprint())->toBe($after);
});

it('rejects a stale preview after a configured refund change', function (): void {
    $this->travelTo(Booking::startsAt()->subHour());
    $old = Booking::get($this->previewPath, $this->token)->assertOk()->json('data.refund');
    config(['wallet.late_cancel.member_bps' => 3333]);
    $before = Booking::footprint();
    Booking::post($this->cancelPath, ['expected_refund' => $old], $this->token)->assertStatus(409)->assertJsonPath('error.code', 'cancellation_preview_changed');
    expect(Booking::footprint())->toBe($before)->and(Booking::credit($this->member))->toBe(0);
});

it('hides foreign and missing bookings and requires authentication for previews', function (): void {
    Booking::get($this->previewPath, Fixtures::token(Fixtures::member()))->assertNotFound();
    Booking::get('/api/reservations/999999/cancellation-preview', $this->token)->assertNotFound();
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->getJson($this->previewPath)->assertUnauthorized();
});

it('rejects previews for cancelled reservations', function (): void {
    Booking::cancel($this->token, $this->reservationId)->assertOk();
    Booking::get($this->previewPath, $this->token)->assertStatus(409)->assertJsonPath('error.code', 'reservation_not_cancellable');
});

it('rejects preview and guarded cancellation at the exact start', function (): void {
    $refund = Booking::get($this->previewPath, $this->token)->assertOk()->json('data.refund');
    $this->travelTo(Booking::startsAt());
    Booking::get($this->previewPath, $this->token)->assertStatus(409)->assertJsonPath('error.code', 'reservation_started');
    Booking::post($this->cancelPath, ['expected_refund' => $refund], $this->token)->assertStatus(409)->assertJsonPath('error.code', 'reservation_started');
});

it('validates the entire optional expectation rather than silently ignoring malformed input', function (mixed $expectation): void {
    Booking::post($this->cancelPath, ['expected_refund' => $expectation], $this->token)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect(Reservation::query()->findOrFail($this->reservationId)->status->value)->toBe('confirmed');
})->with([[null], [[]], [['credit_cents' => 75000]], [['credit_cents' => -1, 'forfeited_cents' => 0, 'currency' => 'USD', 'late' => false]]]);

it('previews full and zero configured late refunds exactly', function (int $bps, int $credit): void {
    config(['wallet.late_cancel.member_bps' => $bps, 'wallet.late_cancel.platform_bps' => 0]);
    $this->travelTo(Booking::startsAt()->subHour());
    $refund = Booking::get($this->previewPath, $this->token)->assertOk()->assertJsonPath('data.refund.credit_cents', $credit)->json('data.refund');
    expect(Booking::post($this->cancelPath, ['expected_refund' => $refund], $this->token)->assertOk()->json('data.refund'))->toBe($refund);
    Booking::assertLedgerClean();
})->with([[0, 0], [10000, 75000]]);
