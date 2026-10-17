<?php

declare(strict_types=1);

use App\Modules\Pricing\Actions\RecordSearch;
use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use Tests\Modules\Pricing\Support\Searches;

it('resolves a DST-skipped local hour to the next real hour and still dedupes on the searched hour', function (): void {
    $member = Member::factory()->create();
    $action = app(RecordSearch::class);

    // US spring forward: Sunday 2027-03-14, 02:00 does not exist; the clock jumps to 03:00 EDT.
    $first = $action->handle(Searches::event($member->id, date: '2027-03-14', hour: 2));
    $second = $action->handle(Searches::event($member->id, date: '2027-03-14', hour: 2));
    $next = $action->handle(Searches::event($member->id, date: '2027-03-14', hour: 3));

    $skipped = SearchEvent::query()->where('requested_hour', 2)->firstOrFail();

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($next)->toBeTrue()
        ->and($skipped->local_weekday)->toBe(7)
        // 03:00 EDT = 07:00 UTC
        ->and($skipped->requested_at->utc()->toDateTimeString())->toBe('2027-03-14 07:00:00');
});

it('uses the first occurrence of a repeated fall-back hour', function (): void {
    $member = Member::factory()->create();

    // US fall back: 2026-11-01 01:00 happens twice; the first (EDT) is used.
    app(RecordSearch::class)->handle(Searches::event($member->id, date: '2026-11-01', hour: 1));

    expect(SearchEvent::query()->firstOrFail()->requested_at->utc()->toDateTimeString())->toBe('2026-11-01 05:00:00');
});
