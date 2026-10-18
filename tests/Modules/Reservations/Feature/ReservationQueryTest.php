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
});

it('lists only the caller\'s reservations, newest starts_at first', function (): void {
    $mine = [
        Reservation::factory()->create(['member_id' => $this->member->id, 'starts_at' => '2026-12-01 20:00:00']),
        Reservation::factory()->create(['member_id' => $this->member->id, 'starts_at' => '2026-12-05 20:00:00']),
        Reservation::factory()->create(['member_id' => $this->member->id, 'starts_at' => '2026-12-03 20:00:00']),
    ];
    Reservation::factory()->create(['starts_at' => '2026-12-09 20:00:00']);

    $response = Booking::get('/api/reservations', $this->token)->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$mine[1]->id, $mine[2]->id, $mine[0]->id])
        ->and(array_keys($response->json()))->toBe(['data']);
});

it('returns an empty list for a member without reservations', function (): void {
    Reservation::factory()->create();

    expect(Booking::get('/api/reservations', $this->token)->assertOk()->json())->toBe(['data' => []]);
});

it('caps the list at 100 reservations', function (): void {
    Reservation::factory()->count(101)->create(['member_id' => $this->member->id]);

    expect(Booking::get('/api/reservations', $this->token)->assertOk()->json('data'))->toHaveCount(100);
});

it('lists real bookings with the payment split and local time', function (): void {
    $slot = Booking::slot();
    Booking::fund($this->member, 20000);
    $id = Booking::book($this->token, $slot, 'tok_ok');

    $row = Booking::get('/api/reservations', $this->token)->assertOk()->json('data.0');

    expect($row['id'])->toBe($id)
        ->and($row['payment'])->toBe(['coupon_cents' => 0, 'credit_cents' => 20000, 'card_cents' => 55000])
        ->and($row['local_starts_at'])->toBe('2026-11-07T20:00:00-05:00')
        ->and($row['starts_at'])->toBe('2026-11-08T01:00:00Z');
});

it('shows one reservation to its owner and hides it from everyone else', function (): void {
    $slot = Booking::slot();
    $id = Booking::book($this->token, $slot, 'tok_ok');

    $shown = Booking::get("/api/reservations/{$id}", $this->token)->assertOk();

    expect($shown->json('data.id'))->toBe($id)
        ->and($shown->json('data.status'))->toBe('confirmed')
        ->and($shown->json('data.slot_id'))->toBe($slot->id)
        ->and($shown->json('data.min_spend_cents'))->toBe(75000)
        ->and($shown->json('data.cancelled_at'))->toBeNull();

    Booking::get("/api/reservations/{$id}", Fixtures::token(Fixtures::member()))
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');

    Booking::get('/api/reservations/987654', $this->token)->assertNotFound();
});

it('shows a cancelled reservation with its status and cancelled_at', function (): void {
    $id = Booking::book($this->token, Booking::slot(), 'tok_ok');
    Booking::cancel($this->token, $id)->assertOk();

    Booking::get("/api/reservations/{$id}", $this->token)->assertOk()
        ->assertJsonPath('data.status', 'cancelled_early')
        ->assertJsonPath('data.cancelled_at', '2026-11-07T15:00:00Z');
});

it('requires authentication on every reservation route', function (): void {
    $this->getJson('/api/reservations')->assertUnauthorized();
    $this->getJson('/api/reservations/1')->assertUnauthorized();
    $this->postJson('/api/reservations', [], ['Idempotency-Key' => 'k'])->assertUnauthorized();
    $this->postJson('/api/reservations/1/cancel', [], ['Idempotency-Key' => 'k'])->assertUnauthorized();
});
