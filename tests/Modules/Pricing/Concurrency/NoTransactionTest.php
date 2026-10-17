<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Pricing\Actions\ComputeDemandSignals;
use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Pricing\Support\Searches;

// DatabaseTruncation: no wrapper transaction, so these run exactly as in production.

it('records a search through the listener when no transaction is open', function (): void {
    $member = Member::factory()->create();

    expect(DB::transactionLevel())->toBe(0);

    event(Searches::event($member->id));

    expect(SearchEvent::query()->count())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
});

it('holds no advisory lock after ComputeDemandSignals returns', function (): void {
    SearchEvent::factory()->create(['created_at' => now()->subDay()]);
    $this->mock(GetSelloutStats::class, fn ($mock) => $mock->shouldReceive('handle')->andReturn([]));

    app(ComputeDemandSignals::class)->handle(now()->toImmutable());

    expect((int) DB::selectOne(
        "SELECT COUNT(*) AS c FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()",
    )->c)->toBe(0);
});
