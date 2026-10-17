<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Inventory\Data\SelloutStat;
use App\Modules\Pricing\Actions\ComputeDemandSignals;
use App\Modules\Pricing\Actions\RecordSearch;
use App\Modules\Pricing\Models\DemandSignal;
use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use Carbon\CarbonImmutable;
use Tests\Modules\Pricing\Support\Searches;

/** @param list<SelloutStat> $stats */
function fakeSellout(array $stats = []): void
{
    test()->mock(GetSelloutStats::class, function ($mock) use ($stats): void {
        $mock->shouldReceive('handle')->andReturn($stats);
    });
}

function unmetSearch(string $band, CarbonImmutable $createdAt, int $venue = 1, int $weekday = 5, int $hour = 20, bool $available = false): void
{
    SearchEvent::factory()->create([
        'venue_id' => $venue,
        'local_weekday' => $weekday,
        'requested_hour' => $hour,
        'party_band' => $band,
        'had_availability' => $available,
        'created_at' => $createdAt,
    ]);
}

/** @return array<string, array{int, int|null}> band => [unmet, p50] */
function signalRows(int $venue = 1, int $weekday = 5, int $hour = 20): array
{
    return DemandSignal::query()
        ->where(['venue_id' => $venue, 'weekday' => $weekday, 'hour' => $hour])
        ->orderBy('party_band')
        ->get()
        ->mapWithKeys(fn (DemandSignal $row): array => [$row->party_band => [$row->unmet_searches, $row->sellout_seconds_p50]])
        ->all();
}

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC');
    $this->travelTo($this->now);
});

it('counts only unmet searches inside the 28-day window', function (): void {
    fakeSellout();

    unmetSearch('1-2', $this->now->subDays(1));
    unmetSearch('1-2', $this->now->subDays(28));                    // exactly on the boundary: included
    unmetSearch('1-2', $this->now->subDays(28)->subSecond());       // just outside
    unmetSearch('1-2', $this->now->subDays(29));
    unmetSearch('1-2', $this->now->subDays(2), available: true);    // had availability: not unmet

    app(ComputeDemandSignals::class)->handle($this->now);

    expect(signalRows()['1-2'][0])->toBe(2);
});

it('groups unmet searches per band and writes a row for every band', function (): void {
    fakeSellout();

    unmetSearch('1-2', $this->now->subDay());
    unmetSearch('3-4', $this->now->subDay());
    unmetSearch('3-4', $this->now->subDay());

    $written = app(ComputeDemandSignals::class)->handle($this->now);

    expect($written)->toBe(3)
        ->and(signalRows())->toBe(['1-2' => [1, null], '3-4' => [2, null], '5+' => [0, null]]);
});

it('copies the sell-out p50 to every band row and sets bid_count to 0', function (): void {
    fakeSellout([new SelloutStat(1, 5, 20, 45)]);

    unmetSearch('3-4', $this->now->subDay());

    app(ComputeDemandSignals::class)->handle($this->now);

    expect(signalRows())->toBe(['1-2' => [0, 45], '3-4' => [1, 45], '5+' => [0, 45]])
        ->and(DemandSignal::query()->where('bid_count', '<>', 0)->count())->toBe(0)
        ->and(DemandSignal::query()->count())->toBe(3);
});

it('creates rows for combinations present only in the sell-out stats', function (): void {
    fakeSellout([new SelloutStat(2, 7, 22, 300)]);

    $written = app(ComputeDemandSignals::class)->handle($this->now);

    expect($written)->toBe(3)->and(signalRows(2, 7, 22))->toBe(['1-2' => [0, 300], '3-4' => [0, 300], '5+' => [0, 300]]);
});

it('keeps venues, weekdays and hours apart', function (): void {
    fakeSellout([new SelloutStat(1, 5, 20, 100)]);

    unmetSearch('1-2', $this->now->subDay(), venue: 2, weekday: 6, hour: 19);

    app(ComputeDemandSignals::class)->handle($this->now);

    expect(signalRows(1, 5, 20)['1-2'])->toBe([0, 100])
        ->and(signalRows(2, 6, 19)['1-2'])->toBe([1, null])
        ->and(DemandSignal::query()->count())->toBe(6);
});

it('asks Inventory for stats since the window start', function (): void {
    $this->mock(GetSelloutStats::class, function ($mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->withArgs(fn (CarbonImmutable $since): bool => $since->equalTo($this->now->subDays(28)))
            ->andReturn([]);
    });

    app(ComputeDemandSignals::class)->handle($this->now);
});

it('raises only the 3-4 band when tables for 2 are open but 4 are sold out', function (): void {
    fakeSellout();
    $member = Member::factory()->create();
    $other = Member::factory()->create();
    $record = app(RecordSearch::class);

    // Inventory reports had_availability per party size: a table for 2 is free, none for 4.
    $record->handle(Searches::event($member->id, partySize: 2, hadAvailability: true));
    $record->handle(Searches::event($member->id, partySize: 4, hadAvailability: false));
    $record->handle(Searches::event($other->id, partySize: 4, hadAvailability: false));

    app(ComputeDemandSignals::class)->handle(CarbonImmutable::now());

    // 2026-11-06 is a Friday, hour 20.
    expect(signalRows(1, 5, 20))->toBe(['1-2' => [0, null], '3-4' => [2, null], '5+' => [0, null]]);
});

it('deletes stale combinations from a previous run', function (): void {
    fakeSellout();
    DemandSignal::factory()->create(['venue_id' => 9, 'weekday' => 1, 'hour' => 3, 'computed_at' => $this->now->subMinutes(15)]);
    unmetSearch('1-2', $this->now->subDay());

    app(ComputeDemandSignals::class)->handle($this->now);

    expect(DemandSignal::query()->where('venue_id', 9)->count())->toBe(0)
        ->and(DemandSignal::query()->where('venue_id', 1)->count())->toBe(3);
});

it('overwrites old counts and sell-out values for a surviving combination', function (): void {
    fakeSellout([new SelloutStat(1, 5, 20, 30)]);
    DemandSignal::factory()->create(['party_band' => '1-2', 'unmet_searches' => 40, 'sellout_seconds_p50' => 900, 'computed_at' => $this->now->subMinutes(15)]);
    unmetSearch('1-2', $this->now->subDay());

    app(ComputeDemandSignals::class)->handle($this->now);

    expect(signalRows()['1-2'])->toBe([1, 30]);
});

it('is idempotent and stamps the new computed_at on a re-run', function (): void {
    fakeSellout([new SelloutStat(1, 5, 20, 45)]);
    unmetSearch('1-2', $this->now->subDay());
    unmetSearch('5+', $this->now->subDay());

    $action = app(ComputeDemandSignals::class);
    $firstCount = $action->handle($this->now);
    $first = DemandSignal::query()->orderBy('party_band')->get(['party_band', 'unmet_searches', 'sellout_seconds_p50', 'bid_count'])->toArray();

    $later = $this->now->addMinutes(15);
    $secondCount = $action->handle($later);
    $second = DemandSignal::query()->orderBy('party_band')->get(['party_band', 'unmet_searches', 'sellout_seconds_p50', 'bid_count'])->toArray();

    expect($secondCount)->toBe($firstCount)
        ->and($second)->toBe($first)
        ->and(DemandSignal::query()->count())->toBe(3)
        ->and(DemandSignal::query()->where('computed_at', $later->utc()->format('Y-m-d H:i:s.uP'))->count())->toBe(3);
});

it('keeps sub-second precision so a run never deletes its own rows', function (): void {
    fakeSellout();
    unmetSearch('1-2', $this->now->subDay());

    $written = app(ComputeDemandSignals::class)->handle($this->now->setMicroseconds(100));

    expect($written)->toBe(3)->and(DemandSignal::query()->count())->toBe(3);
});

it('removes every row when there are no searches or stats', function (): void {
    fakeSellout();
    DemandSignal::factory()->create(['computed_at' => $this->now->subMinutes(15)]);

    $written = app(ComputeDemandSignals::class)->handle($this->now);

    expect($written)->toBe(0)->and(DemandSignal::query()->count())->toBe(0);
});

it('leaves existing signals untouched when Inventory fails', function (): void {
    $this->mock(GetSelloutStats::class, function ($mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('inventory down'));
    });
    DemandSignal::factory()->create(['computed_at' => $this->now->subMinutes(15)]);

    try {
        app(ComputeDemandSignals::class)->handle($this->now);
    } catch (RuntimeException) {
    }

    expect(DemandSignal::query()->count())->toBe(1);
});
