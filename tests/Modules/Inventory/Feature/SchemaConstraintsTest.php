<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Inventory\Support\Fixtures;

/**
 * Runs $statement in a savepoint so a rejected write does not poison the test transaction.
 */
function rejected(Closure $statement): ?QueryException
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        return $e;
    }

    return null;
}

it('rejects held_count + booked_count > capacity inserted directly via SQL', function (): void {
    $venue = Venue::factory()->create();

    $error = rejected(fn () => DB::statement(
        "INSERT INTO slots (venue_id, starts_at, capacity, held_count, booked_count, min_party_size, max_party_size, released_at)
         VALUES (?, now() + interval '1 day', 2, 2, 1, 1, 2, now())",
        [$venue->id],
    ));

    expect($error?->getMessage())->toContain('slots_not_overbooked');
});

it('accepts held + booked exactly equal to capacity', function (): void {
    $slot = Fixtures::slot(['capacity' => 3, 'held_count' => 2, 'booked_count' => 1]);

    expect($slot->fresh()->capacity)->toBe(3);
});

it('rejects a conditional-free update that would overbook', function (): void {
    $slot = Fixtures::slot(['capacity' => 2, 'held_count' => 1, 'booked_count' => 1]);

    $error = rejected(fn () => DB::table('slots')->where('id', $slot->id)->increment('held_count'));

    expect($error?->getMessage())->toContain('slots_not_overbooked');
});

it('enforces every slots CHECK', function (string $constraint, array $override): void {
    $venue = Venue::factory()->create();

    $error = rejected(fn () => Slot::factory()->create(array_merge(['venue_id' => $venue->id], $override)));

    expect($error?->getMessage())->toContain($constraint);
})->with([
    'capacity > 0' => ['slots_capacity_check', ['capacity' => 0]],
    'held_count >= 0' => ['slots_held_count_check', ['held_count' => -1]],
    'booked_count >= 0' => ['slots_booked_count_check', ['booked_count' => -1]],
    'booked_count <= capacity' => ['slots_booked_within_capacity', ['capacity' => 1, 'booked_count' => 2]],
    'min_party_size >= 1' => ['slots_min_party_size_check', ['min_party_size' => 0, 'max_party_size' => 2]],
    'max >= min' => ['slots_max_party_size_check', ['min_party_size' => 3, 'max_party_size' => 2]],
]);

it('enforces the unique (venue, starts_at, min, max) on slots', function (): void {
    $slot = Fixtures::slot();

    $error = rejected(fn () => Slot::factory()->create([
        'venue_id' => $slot->venue_id,
        'starts_at' => $slot->starts_at,
        'min_party_size' => $slot->min_party_size,
        'max_party_size' => $slot->max_party_size,
    ]));

    expect($error)->toBeInstanceOf(UniqueConstraintViolationException::class);

    Slot::factory()->create([
        'venue_id' => $slot->venue_id,
        'starts_at' => $slot->starts_at,
        'min_party_size' => 5,
        'max_party_size' => 6,
    ]);
});

it('rejects a second active hold for the same member and slot but allows it once the first is closed', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $member = Fixtures::member();
    Hold::factory()->create(['slot_id' => $slot->id, 'member_id' => $member->id]);

    $error = rejected(fn () => Hold::factory()->create(['slot_id' => $slot->id, 'member_id' => $member->id]));

    expect($error)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($error?->getMessage())->toContain('holds_one_active_per_member_slot');

    Hold::factory()->create(['slot_id' => $slot->id, 'member_id' => $member->id, 'status' => 'expired', 'closed_at' => now(), 'close_reason' => 'expired']);
    Hold::factory()->create(['slot_id' => $slot->id, 'member_id' => $member->id, 'status' => 'released', 'closed_at' => now(), 'close_reason' => 'payment_failed']);

    expect(Hold::query()->count())->toBe(3);
});

it('rejects a duplicate hold idempotency_key', function (): void {
    $slot = Fixtures::slot();
    Hold::factory()->create(['slot_id' => $slot->id, 'idempotency_key' => 'dup']);

    $error = rejected(fn () => Hold::factory()->create(['slot_id' => $slot->id, 'idempotency_key' => 'dup']));

    expect($error)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($error?->getMessage())->toContain('holds_idempotency_key_unique');
});

it('enforces the holds CHECKs', function (string $constraint, array $override): void {
    $slot = Fixtures::slot();

    $error = rejected(fn () => Hold::factory()->create(array_merge(['slot_id' => $slot->id], $override)));

    expect($error?->getMessage())->toContain($constraint);
})->with([
    'party_size >= 1' => ['holds_party_size_check', ['party_size' => 0]],
    'status list' => ['holds_status_check', ['status' => 'pending', 'closed_at' => '2026-01-01 00:00:00']],
    'closed_at set while active' => ['holds_closed_at_check', ['closed_at' => '2026-01-01 00:00:00']],
    'closed_at missing while expired' => ['holds_closed_at_check', ['status' => 'expired']],
]);

it('enforces the venues CHECKs and unique slug', function (): void {
    $venue = Venue::factory()->create();

    expect(rejected(fn () => Venue::factory()->create(['timezone' => ''])))->not->toBeNull()
        ->and(rejected(fn () => Venue::factory()->create(['status' => 'closed']))?->getMessage())->toContain('venues_status_check')
        ->and(rejected(fn () => Venue::factory()->create(['base_per_person_cents' => 0]))?->getMessage())->toContain('venues_base_per_person_cents_check')
        ->and(rejected(fn () => Venue::factory()->create(['slug' => $venue->slug])))->toBeInstanceOf(UniqueConstraintViolationException::class);
});

it('has the contract indexes, including the partial ones', function (): void {
    $indexes = collect(DB::select("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'"))
        ->mapWithKeys(fn (object $row): array => [$row->indexname => $row->indexdef]);

    expect($indexes['holds_one_active_per_member_slot'])->toContain('UNIQUE')->toContain("WHERE ((status)::text = 'active'::text)")
        ->and($indexes['holds_active_expires_at_idx'])->toContain('(expires_at)')->toContain("WHERE ((status)::text = 'active'::text)")
        ->and($indexes['drops_unopened_opens_at_idx'])->toContain('(opens_at)')->toContain('WHERE (opened_at IS NULL)')
        ->and($indexes['holds_idempotency_key_unique'])->toContain('UNIQUE')
        ->and($indexes['cities_slug_unique'])->toContain('UNIQUE')
        ->and($indexes['venues_slug_unique'])->toContain('UNIQUE')
        ->and($indexes['venues_city_id_index'])->toContain('(city_id)')
        ->and($indexes['slots_venue_id_starts_at_index'])->toContain('(venue_id, starts_at)')
        ->and($indexes['slots_drop_id_index'])->toContain('(drop_id)')
        ->and($indexes['slots_updated_at_index'])->toContain('(updated_at)')
        ->and($indexes['slots_venue_id_starts_at_min_party_size_max_party_size_unique'])->toContain('UNIQUE');
});

it('stores every timestamp column with microsecond precision', function (): void {
    $columns = DB::select(
        "SELECT table_name, column_name, datetime_precision, data_type FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name IN ('cities', 'venues', 'drops', 'slots', 'holds') AND data_type LIKE 'timestamp%'",
    );

    expect($columns)->not->toBeEmpty();

    foreach ($columns as $column) {
        expect($column->data_type)->toBe('timestamp with time zone')
            ->and($column->datetime_precision)->toBe(6);
    }
});
