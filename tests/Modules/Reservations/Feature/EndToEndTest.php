<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Venue;
use App\Modules\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Modules\Reservations\Support\Booking;

it('runs the whole booking story over HTTP: search, hold, confirm with wallet and card, cancel early, hold again, confirm with wallet, cancel late', function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));

    $venue = Venue::factory()->create(['timezone' => 'America/New_York', 'base_per_person_cents' => 25000]);
    $slot = Booking::slot(['capacity' => 2], $venue);
    $member = Fixtures::member();
    $token = Fixtures::token($member);
    Booking::fund($member, 20000);

    // Search availability for Saturday 20:00 local, a party of 2.
    $search = Booking::get("/api/venues/{$venue->id}/availability?date=2026-11-07&hour=20&party_size=2", $token)->assertOk();

    expect($search->json('data.had_availability'))->toBeTrue()
        ->and($search->json('data.slots.0.id'))->toBe($slot->id)
        ->and($search->json('data.slots.0.available'))->toBe(2);

    // Hold: the quote is locked to it.
    $hold = Booking::hold($token, $search->json('data.slots.0.id'))->assertCreated();

    expect($hold->json('data.quote.amount_cents'))->toBe(75000)
        ->and($hold->json('data.slot.available'))->toBe(1);

    // Confirm with 20000 of credit and the rest on the card.
    $first = Booking::confirm($token, $hold->json('data.id'), 'tok_ok')->assertCreated();

    expect($first->json('data.payment'))->toBe(['coupon_cents' => 0, 'credit_cents' => 20000, 'card_cents' => 55000])
        ->and(Booking::get('/api/wallet', $token)->json('data.credit_cents'))->toBe(0)
        ->and(DB::table('payments')->sole()->status)->toBe('captured');

    // Cancel early (10 hours out): the whole minimum comes back as credit, even though most of it was card.
    $cancelEarly = Booking::cancel($token, $first->json('data.id'))->assertOk();

    expect($cancelEarly->json('data.refund'))->toBe(['credit_cents' => 75000, 'forfeited_cents' => 0, 'currency' => 'USD', 'late' => false])
        ->and(Booking::get('/api/wallet', $token)->json('data'))->toBe(['currency' => 'USD', 'credit_cents' => 75000, 'coupon_cents' => 0])
        ->and($slot->fresh()->booked_count)->toBe(0);

    // Hold the same slot again and pay from the wallet alone.
    $again = Booking::hold($token, $slot)->assertCreated();
    $second = Booking::confirm($token, $again->json('data.id'), null)->assertCreated();

    expect($second->json('data.payment'))->toBe(['coupon_cents' => 0, 'credit_cents' => 75000, 'card_cents' => 0])
        ->and(Booking::get('/api/wallet', $token)->json('data.credit_cents'))->toBe(0)
        ->and(DB::table('payments')->count())->toBe(1);

    // Cancel inside the window (3 hours out): half comes back, a quarter goes to the platform.
    $this->travelTo(Booking::startsAt()->subHours(3));

    $cancelLate = Booking::cancel($token, $second->json('data.id'))->assertOk();

    expect($cancelLate->json('data.refund'))->toBe(['credit_cents' => 37500, 'forfeited_cents' => 37500, 'currency' => 'USD', 'late' => true])
        ->and(Booking::get('/api/wallet', $token)->json('data.credit_cents'))->toBe(37500)
        ->and(Booking::get('/api/reservations', $token)->json('data.*.status'))->toHaveCount(2)
        ->and(Reservation::query()->orderBy('id')->pluck('status')->map->value->all())->toBe(['cancelled_early', 'cancelled_late']);

    // Final balances (§5): member credit 37500; venue keeps 18750 of the second booking (75000 - 56250); platform
    // paid the 20000 goodwill and took 18750; the card cleared 55000.
    expect(Booking::credit($member))->toBe(37500)
        ->and(Booking::venuePayable($venue->id))->toBe(18750)
        ->and(Booking::platform())->toBe(18750 - 20000)
        ->and(Booking::cardClearing())->toBe(55000)
        ->and($slot->fresh()->booked_count)->toBe(0)
        ->and($slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(2)
        ->and(Artisan::call('wallet:reconcile'))->toBe(0);
});
