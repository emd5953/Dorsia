<?php

declare(strict_types=1);

use App\Modules\Reservations\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<string, mixed>  $overrides
 */
function reservationRowRejected(array $overrides, string $constraint): void
{
    $caught = null;

    try {
        DB::transaction(fn () => DB::table('reservations')->insert(Reservation::factory()->raw($overrides)));
    } catch (QueryException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull("the database accepted a reservation that must violate {$constraint}")
        ->and($caught?->getMessage())->toContain($constraint);
}

it('creates the C§3 reservations columns', function (): void {
    expect(Schema::getColumnListing('reservations'))->toBe([
        'id', 'member_id', 'hold_id', 'slot_id', 'venue_id', 'venue_timezone', 'starts_at', 'party_size',
        'min_spend_cents', 'coupon_cents', 'credit_cents', 'card_cents', 'currency', 'payment_id', 'status',
        'idempotency_key', 'confirmed_at', 'cancelled_at', 'created_at', 'updated_at',
    ]);
});

it('stores microsecond timestamps', function (): void {
    $row = Reservation::factory()->create(['confirmed_at' => '2026-11-07 15:00:00.123456']);

    expect(DB::table('reservations')->where('id', $row->id)->value('confirmed_at'))->toContain('15:00:00.123456');
});

it('accepts a consistent row and a cancelled one', function (): void {
    Reservation::factory()->create();
    Reservation::factory()->create(['status' => 'cancelled_late', 'cancelled_at' => now()]);

    expect(Reservation::query()->count())->toBe(2);
});

it('rejects amounts that do not add up to min_spend', function (): void {
    reservationRowRejected(['credit_cents' => 1], 'reservations_split_check');
    reservationRowRejected(['card_cents' => 49999], 'reservations_split_check');
});

it('rejects negative parts and a non-positive minimum', function (): void {
    reservationRowRejected(['coupon_cents' => -1, 'card_cents' => 50001], 'reservations_amounts_check');
    reservationRowRejected(['min_spend_cents' => 0, 'card_cents' => 0], 'reservations_min_spend_check');
});

it('rejects a party of zero and an unknown status', function (): void {
    reservationRowRejected(['party_size' => 0], 'reservations_party_size_check');
    reservationRowRejected(['status' => 'completed'], 'reservations_a_status_check');
});

it('ties cancelled_at to the status', function (): void {
    reservationRowRejected(['status' => 'cancelled_early', 'cancelled_at' => null], 'reservations_cancelled_at_check');
    reservationRowRejected(['status' => 'confirmed', 'cancelled_at' => now()], 'reservations_cancelled_at_check');
});

it('allows one reservation per hold and per idempotency key', function (): void {
    $row = Reservation::factory()->create();

    reservationRowRejected(['hold_id' => $row->hold_id], 'reservations_hold_id_unique');
    reservationRowRejected(['idempotency_key' => $row->idempotency_key], 'reservations_idempotency_key_unique');
});

it('only keeps a foreign key to members', function (): void {
    $foreignKeys = DB::select(
        "SELECT a.attname AS col, c2.relname AS referenced FROM pg_constraint c
         JOIN pg_class c1 ON c1.oid = c.conrelid JOIN pg_class c2 ON c2.oid = c.confrelid
         JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
         WHERE c.contype = 'f' AND c1.relname = 'reservations'"
    );

    expect(collect($foreignKeys)->map(fn (object $fk): string => "{$fk->col}->{$fk->referenced}")->all())->toBe(['member_id->members']);
});
