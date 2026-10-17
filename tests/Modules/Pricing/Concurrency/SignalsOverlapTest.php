<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Pricing\Actions\ComputeDemandSignals;
use App\Modules\Pricing\Models\DemandSignal;
use App\Modules\Pricing\Models\SearchEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Concurrency;

it('serialises overlapping demand-signals runs', function (): void {
    SearchEvent::factory()->create(['created_at' => now()->subDay()]);

    Schema::create('pricing_run_log', function ($table): void {
        $table->id();
        $table->double('started');
        $table->double('ended');
    });

    // Logged to the database: forked children cannot share memory with the parent.
    app()->instance(GetSelloutStats::class, new class extends GetSelloutStats
    {
        public function handle(CarbonImmutable $since): array
        {
            $start = microtime(true);
            usleep(400_000);
            DB::table('pricing_run_log')->insert(['started' => $start, 'ended' => microtime(true)]);

            return [];
        }
    });

    try {
        Concurrency::run([
            fn (): int => app(ComputeDemandSignals::class)->handle(CarbonImmutable::now()),
            fn (): int => app(ComputeDemandSignals::class)->handle(CarbonImmutable::now()->addSecond()),
        ]);

        $runs = DB::table('pricing_run_log')->orderBy('started')->get();

        // The second run reads only after the first has committed, so the last writer is also the last reader.
        expect($runs)->toHaveCount(2)
            ->and($runs[1]->started)->toBeGreaterThanOrEqual($runs[0]->ended)
            ->and(DemandSignal::query()->count())->toBe(3)
            ->and(DemandSignal::query()->distinct()->count('computed_at'))->toBe(1);
    } finally {
        Schema::dropIfExists('pricing_run_log');
    }
});
