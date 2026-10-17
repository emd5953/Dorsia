<?php

declare(strict_types=1);

use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Pricing\Actions\RecordSearch;
use App\Modules\Pricing\Listeners\RecordAvailabilitySearch;
use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Modules\Pricing\Support\Searches;

it('is registered for AvailabilitySearched', function (): void {
    expect(Event::hasListeners(AvailabilitySearched::class))->toBeTrue();
});

it('records the search when the event is dispatched', function (): void {
    $member = Member::factory()->create();

    event(Searches::event($member->id, partySize: 6));

    expect(SearchEvent::query()->count())->toBe(1)->and(SearchEvent::query()->firstOrFail()->party_band)->toBe('5+');
});

it('does not throw or record anything when the insert fails', function (): void {
    Log::spy();
    $member = Member::factory()->create();

    // party size 21 violates the table CHECK
    event(Searches::event($member->id, partySize: 21));

    expect(SearchEvent::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});

it('does not poison the caller transaction when the insert fails', function (): void {
    $member = Member::factory()->create();

    DB::transaction(function () use ($member): void {
        event(Searches::event($member->id, partySize: 21));
        event(Searches::event($member->id, partySize: 2));
    });

    expect(SearchEvent::query()->count())->toBe(1);
});

it('swallows any Throwable from the action and logs a warning', function (): void {
    Log::spy();
    $this->mock(RecordSearch::class, function ($mock): void {
        $mock->shouldReceive('handle')->andThrow(new Error('boom'));
    });

    event(Searches::event(1));

    Log::shouldHaveReceived('warning')->once();
});

it('can be invoked directly without throwing', function (): void {
    $this->mock(RecordSearch::class, function ($mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('db down'));
    });

    app(RecordAvailabilitySearch::class)->handle(Searches::event(1));

    expect(true)->toBeTrue();
});
