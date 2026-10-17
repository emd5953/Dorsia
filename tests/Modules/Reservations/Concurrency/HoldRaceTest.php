<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Shared\Exceptions\DomainError;
use App\Shared\Members\Member;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Support\Concurrency;

/**
 * @return list<string> bearer tokens of $n fresh members
 */
function raceTokens(int $n): array
{
    return Member::factory()->count($n)->create()->map(fn (Member $m): string => Fixtures::token($m))->all();
}

/**
 * @param  list<array{status: int, json: array<mixed>, headers: array<string, string>}>  $responses
 * @return array<string, int> "status code" => count
 */
function tally(array $responses): array
{
    $tally = [];

    foreach ($responses as $response) {
        $key = $response['status'].' '.($response['json']['error']['code'] ?? 'ok');
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }

    ksort($tally);

    return $tally;
}

function assertNoServerErrors(array $responses): void
{
    foreach ($responses as $response) {
        expect($response['status'])->toBeLessThan(500);
    }
}

it('lets 40 members race a capacity-5 slot: exactly 5 holds, 35 sold_out, no 5xx, gate never below Postgres', function (): void {
    $slot = Fixtures::slot(['capacity' => 5]);
    $tokens = raceTokens(40);

    $responses = Concurrency::requests(40, fn (int $i): array => [
        'method' => 'POST',
        'uri' => '/api/holds',
        'token' => $tokens[$i],
        'body' => ['slot_id' => $slot->id, 'party_size' => 2],
        'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ]);

    assertNoServerErrors($responses);

    expect(tally($responses))->toBe(['201 ok' => 5, '409 sold_out' => 35])
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(5)
        ->and(Hold::query()->where('status', 'active')->count())->toBe(5)
        ->and(Hold::query()->distinct('member_id')->count('member_id'))->toBe(5)
        ->and(OutboxEvent::query()->where('topic', 'slot.held')->count())->toBe(5);

    // C§6.1: a refresh SET may overwrite in-flight decrements, so the gate can end too high, never too low.
    expect(Fixtures::gate($slot))->not->toBeNull()->toBeGreaterThanOrEqual(0);
});

it('still sells exactly 5 when the gate key is deleted just before firing, and rebuilds it (never below Postgres)', function (): void {
    $slot = Fixtures::slot(['capacity' => 5]);
    $tokens = raceTokens(40);
    Redis::connection()->flushdb();

    expect(Fixtures::gate($slot))->toBeNull();

    $responses = Concurrency::requests(40, fn (int $i): array => [
        'method' => 'POST',
        'uri' => '/api/holds',
        'token' => $tokens[$i],
        'body' => ['slot_id' => $slot->id, 'party_size' => 2],
        'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ]);

    assertNoServerErrors($responses);

    expect(tally($responses))->toBe(['201 ok' => 5, '409 sold_out' => 35])
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(5)
        ->and(Hold::query()->where('status', 'active')->count())->toBe(5);

    // Rebuilt (key exists), and at or above Postgres truth (0 free tables): C§6.1 allows too high, never too low.
    expect(Fixtures::gate($slot))->not->toBeNull()->toBeGreaterThanOrEqual(0);
});

it('creates exactly one hold for 20 identical requests from one member with one key', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $token = raceTokens(1)[0];
    $key = (string) Str::uuid();

    $responses = Concurrency::requests(20, fn (int $i): array => [
        'method' => 'POST',
        'uri' => '/api/holds',
        'token' => $token,
        'body' => ['slot_id' => $slot->id, 'party_size' => 2],
        'headers' => ['Idempotency-Key' => $key],
    ]);

    assertNoServerErrors($responses);

    $tally = tally($responses);
    expect(array_diff(array_keys($tally), ['201 ok', '409 idempotency_in_progress']))->toBe([])
        ->and($tally['201 ok'] ?? 0)->toBeGreaterThanOrEqual(1);

    $created = array_filter($responses, fn (array $r): bool => $r['status'] === 201 && ! isset($r['headers']['idempotent-replayed']));
    $ids = array_unique(array_map(fn (array $r): int => $r['json']['data']['id'], array_filter($responses, fn (array $r): bool => $r['status'] === 201)));

    expect($created)->toHaveCount(1)
        ->and($ids)->toHaveCount(1)
        ->and(Hold::query()->count())->toBe(1)
        ->and(OutboxEvent::query()->where('topic', 'slot.held')->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('creates exactly one hold for 20 parallel requests from one member with different keys', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $token = raceTokens(1)[0];

    $responses = Concurrency::requests(20, fn (int $i): array => [
        'method' => 'POST',
        'uri' => '/api/holds',
        'token' => $token,
        'body' => ['slot_id' => $slot->id, 'party_size' => 2],
        'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ]);

    assertNoServerErrors($responses);

    $tally = tally($responses);
    expect(array_diff(array_keys($tally), ['201 ok', '409 active_hold_exists', '409 sold_out']))->toBe([])
        ->and($tally['201 ok'] ?? 0)->toBe(1)
        ->and(Hold::query()->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(1)
        ->and(OutboxEvent::query()->where('topic', 'slot.held')->count())->toBe(1);

    // Losers of the rebuild-lock race may hold without a unit (contract), so the gate may end too high (a rebuild mid-flight can only over-count), never too low.
    expect(Fixtures::gate($slot))->toBeGreaterThanOrEqual(1);
});

it('keeps held + booked within capacity and never ends with the gate below Postgres while the sweeper frees tables under 20 racers', function (): void {
    $slot = Fixtures::slot(['capacity' => 5]);

    foreach (range(1, 5) as $ignored) {
        Fixtures::hold($slot);
    }

    // Every hold is overdue but unswept; the gate sits at 0.
    Hold::query()->update(['expires_at' => now()->subSecond()]);
    expect(Fixtures::gate($slot))->toBe(0);

    $members = Member::factory()->count(20)->create();

    $closures = [
        fn (): string => (string) Artisan::call('inventory:expire-holds'),
        fn (): string => (string) Artisan::call('inventory:expire-holds'),
    ];

    foreach ($members as $member) {
        $closures[] = function () use ($slot, $member): string {
            try {
                app(HoldSlot::class)->handle(new HoldSlotInput($member->id, $slot->id, 2, (string) Str::uuid()));

                return 'held';
            } catch (DomainError $e) {
                return $e->errorCode();
            }
        };
    }

    $results = Concurrency::run($closures);
    $racers = array_slice($results, 2);

    expect(array_diff(array_unique($racers), ['held', 'sold_out']))->toBe([])
        ->and(array_count_values($racers)['held'] ?? 0)->toBeLessThanOrEqual(5);

    $row = Slot::query()->findOrFail($slot->id);
    $active = Hold::query()->where('status', 'active')->count();

    expect($row->held_count + $row->booked_count)->toBeLessThanOrEqual($row->capacity)
        ->and($row->held_count)->toBe($active)
        ->and($active)->toBe(array_count_values($racers)['held'] ?? 0)
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(5)
        ->and(OutboxEvent::query()->where('topic', 'slot.released')->count())->toBe(5)
        ->and(Fixtures::gate($slot))->toBeGreaterThanOrEqual($row->capacity - $row->held_count - $row->booked_count);
});
