<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Shared\Members\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Modules\Reservations\Support\Booking;
use Tests\Support\Concurrency;

function reservationRaceTokens(int $count): array
{
    return Member::factory()->count($count)->create()->map(fn (Member $member): string => Fixtures::token($member))->all();
}

/** @param array{status: int, json: array<mixed>} $response */
function reservationRaceHoldId(array $response): int
{
    expect($response['status'])->toBe(201, 'Hold setup response: '.json_encode($response['json']));
    expect(isset($response['json']['data']['id']))->toBeTrue('Hold setup response: '.json_encode($response['json']));
    expect($response['json']['data']['id'])->toBeInt();

    return $response['json']['data']['id'];
}

it('allows 20 parallel confirms of one hold to create one reservation and one charge', function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $slot = Booking::slot();
    $token = reservationRaceTokens(1)[0];
    $hold = Concurrency::requests(1, fn (): array => [
        'method' => 'POST', 'uri' => '/api/holds', 'token' => $token,
        'body' => ['slot_id' => $slot->id, 'party_size' => 2], 'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ])[0];
    $holdId = reservationRaceHoldId($hold);

    $responses = Concurrency::requests(20, fn (int $i): array => [
        'method' => 'POST', 'uri' => '/api/reservations', 'token' => $token,
        'body' => ['hold_id' => $holdId, 'card_token' => 'tok_ok'],
        'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ]);

    expect(collect($responses)->every(fn (array $response): bool => $response['status'] < 500))->toBeTrue()
        ->and(Reservation::query()->count())->toBe(1)
        ->and(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('payments')->whereNotNull('reservation_id')->count())->toBe(1)
        ->and(LedgerTransaction::query()->where('type', 'booking')->count())->toBe(1)
        ->and(Hold::query()->whereKey($holdId)->value('status'))->toBe('converted')
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(0)
        ->and(Slot::query()->findOrFail($slot->id)->booked_count)->toBe(1)
        ->and(Booking::gateway()->charges())->toHaveCount(1);

    expect(array_sum(array_map(fn (array $response): int => $response['status'] === 201 ? 1 : 0, $responses)))->toBe(1);
    expect(Artisan::call('wallet:reconcile'))->toBe(0);
});

it('allows 20 parallel cancels of one reservation to post one refund', function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $slot = Booking::slot();
    $token = reservationRaceTokens(1)[0];
    $hold = Concurrency::requests(1, fn (): array => [
        'method' => 'POST', 'uri' => '/api/holds', 'token' => $token,
        'body' => ['slot_id' => $slot->id, 'party_size' => 2], 'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ])[0];
    $confirm = Concurrency::requests(1, fn (): array => [
        'method' => 'POST', 'uri' => '/api/reservations', 'token' => $token,
        'body' => ['hold_id' => reservationRaceHoldId($hold), 'card_token' => 'tok_ok'], 'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ])[0];
    expect($confirm['status'])->toBe(201);
    $reservationId = (int) $confirm['json']['data']['id'];

    $responses = Concurrency::requests(20, fn (): array => [
        'method' => 'POST', 'uri' => "/api/reservations/{$reservationId}/cancel", 'token' => $token,
        'body' => [], 'headers' => ['Idempotency-Key' => (string) Str::uuid()],
    ]);

    expect(collect($responses)->every(fn (array $response): bool => $response['status'] < 500))->toBeTrue()
        ->and(array_sum(array_map(fn (array $response): int => $response['status'] === 200 ? 1 : 0, $responses)))->toBe(1)
        ->and(Reservation::query()->findOrFail($reservationId)->status->value)->toBe('cancelled_early')
        ->and(LedgerTransaction::query()->where('type', 'cancel_early')->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->booked_count)->toBe(0);

    expect(Artisan::call('wallet:reconcile'))->toBe(0);
});

it('runs a capacity-5 hold and confirm race for 30 members with stable retry keys', function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $slot = Booking::slot(['capacity' => 5]);
    $tokens = reservationRaceTokens(30);
    $results = Concurrency::run(array_map(function (string $token) use ($slot): Closure {
        $holdKey = (string) Str::uuid();
        $confirmKey = (string) Str::uuid();

        return function () use ($token, $slot, $holdKey, $confirmKey): array {
            $held = Booking::call('POST', '/api/holds', $token, ['slot_id' => $slot->id, 'party_size' => 2], $holdKey);
            if ($held['status'] !== 201) {
                return ['hold_status' => $held['status'], 'confirm_status' => null];
            }

            $holdId = reservationRaceHoldId($held);
            $confirmed = Booking::call('POST', '/api/reservations', $token, ['hold_id' => $holdId, 'card_token' => 'tok_ok'], $confirmKey);
            if ($confirmed['status'] >= 500) {
                $confirmed = Booking::call('POST', '/api/reservations', $token, ['hold_id' => $holdId, 'card_token' => 'tok_ok'], $confirmKey);
            }

            return ['hold_status' => $held['status'], 'confirm_status' => $confirmed['status'], 'confirm_code' => $confirmed['code']];
        };
    }, $tokens));

    $winners = array_filter($results, fn (array $result): bool => $result['confirm_status'] === 201);
    expect(count($winners))->toBe(5)
        ->and(Reservation::query()->count())->toBe(5)
        ->and(DB::table('payments')->where('status', 'captured')->count())->toBe(5)
        ->and(DB::table('payments')->whereNull('reservation_id')->where('status', 'authorized')->count())->toBe(0)
        ->and(Slot::query()->findOrFail($slot->id)->booked_count)->toBe(5)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(0)
        ->and(DB::table('ledger_entries')->sum('amount_cents'))->toBe(0);

    expect(Artisan::call('wallet:reconcile'))->toBe(0);
});

it('races confirm against the expiry sweeper at expires_at minus one second for 20 iterations', function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));

    foreach (range(1, 20) as $iteration) {
        $slot = Booking::slot();
        $token = reservationRaceTokens(1)[0];
        $held = Concurrency::requests(1, fn (): array => [
            'method' => 'POST', 'uri' => '/api/holds', 'token' => $token,
            'body' => ['slot_id' => $slot->id, 'party_size' => 2], 'headers' => ['Idempotency-Key' => (string) Str::uuid()],
        ])[0];
        $holdId = reservationRaceHoldId($held);
        $expiry = Hold::query()->findOrFail($holdId)->expires_at;
        $this->travelTo($expiry->subSecond());

        $closures = [function (): int {
            Artisan::call('inventory:expire-holds');

            return 200;
        }];
        foreach (range(1, 20) as $request) {
            $key = (string) Str::uuid();
            $closures[] = fn (): array => Booking::call('POST', '/api/reservations', $token, ['hold_id' => $holdId, 'card_token' => 'tok_ok'], $key);
        }
        $results = Concurrency::run($closures);

        expect(collect(array_slice($results, 1))->every(fn (array $result): bool => $result['status'] < 500))->toBeTrue();
        $holdStatus = Hold::query()->findOrFail($holdId)->status;
        expect(in_array($holdStatus, ['converted', 'expired'], true))->toBeTrue();
        if ($holdStatus === 'converted') {
            expect(Reservation::query()->where('hold_id', $holdId)->count())->toBe(1)
                ->and(DB::table('payments')->where('hold_id', $holdId)->where('status', 'captured')->count())->toBe(1);
        } else {
            expect(Reservation::query()->where('hold_id', $holdId)->count())->toBe(0)
                ->and(DB::table('payments')->where('hold_id', $holdId)->where('status', 'captured')->count())->toBe(0);
        }
        $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    }
});
