<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Pricing\Actions\ComputeDemandSignals;
use App\Modules\Pricing\Actions\GetQuoteForHold;
use App\Modules\Pricing\Actions\PruneSearchEvents;
use App\Modules\Pricing\Actions\QuoteHold;
use App\Modules\Pricing\Actions\RecordSearch;
use App\Modules\Pricing\Data\QuoteData;
use App\Modules\Pricing\Data\QuoteHoldInput;
use App\Modules\Pricing\Exceptions\QuoteNotFoundException;
use App\Modules\Pricing\Listeners\RecordAvailabilitySearch;
use App\Modules\Pricing\Support\PartyBands;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, string>  $params  name => type
 * @return array{array<string, string>, string}
 */
function pricingSignature(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);
    $params = [];

    foreach ($reflection->getParameters() as $parameter) {
        $params[$parameter->getName()] = (string) $parameter->getType();
    }

    return [$params, (string) $reflection->getReturnType()];
}

it('declares the DTOs with the C§5.2 constructor', function (): void {
    expect(pricingSignature(QuoteHoldInput::class, '__construct')[0])->toBe([
        'holdId' => 'int',
        'slotId' => 'int',
        'venueId' => 'int',
        'venueTimezone' => 'string',
        'startsAt' => CarbonImmutable::class,
        'partySize' => 'int',
        'basePerPerson' => Money::class,
        'expiresAt' => CarbonImmutable::class,
    ])->and(pricingSignature(QuoteData::class, '__construct')[0])->toBe([
        'id' => 'int',
        'holdId' => 'int',
        'slotId' => 'int',
        'partySize' => 'int',
        'perPerson' => Money::class,
        'amount' => Money::class,
        'inputs' => 'array',
        'expiresAt' => CarbonImmutable::class,
    ]);

    expect((new ReflectionClass(QuoteHoldInput::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(QuoteHoldInput::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(QuoteData::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(QuoteData::class))->isReadOnly())->toBeTrue();
});

it('declares the actions with the C§5.2 signatures', function (string $class, array $params, string $return): void {
    expect(pricingSignature($class, 'handle'))->toBe([$params, $return])
        ->and((new ReflectionClass($class))->isFinal())->toBeFalse();

    $public = array_filter(
        (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! $m->isConstructor() && $m->getDeclaringClass()->getName() === $class,
    );

    expect($public)->toHaveCount(1);
})->with([
    'QuoteHold' => [QuoteHold::class, ['input' => QuoteHoldInput::class], QuoteData::class],
    'GetQuoteForHold' => [GetQuoteForHold::class, ['holdId' => 'int'], QuoteData::class],
    'RecordSearch' => [RecordSearch::class, ['event' => AvailabilitySearched::class], 'bool'],
    'ComputeDemandSignals' => [ComputeDemandSignals::class, ['now' => CarbonImmutable::class], 'int'],
    'PruneSearchEvents' => [PruneSearchEvents::class, ['now' => CarbonImmutable::class], 'int'],
]);

it('injects GetSelloutStats into ComputeDemandSignals', function (): void {
    expect(pricingSignature(ComputeDemandSignals::class, '__construct')[0])->toBe(['sellout' => GetSelloutStats::class]);
});

it('declares QuoteNotFoundException as a LogicException', function (): void {
    expect(is_subclass_of(QuoteNotFoundException::class, LogicException::class))->toBeTrue();
});

it('declares PartyBands::forPartySize', function (): void {
    $method = new ReflectionMethod(PartyBands::class, 'forPartySize');

    expect($method->isStatic())->toBeTrue()
        ->and(pricingSignature(PartyBands::class, 'forPartySize'))->toBe([['partySize' => 'int'], 'string']);
});

it('declares the listener for AvailabilitySearched', function (): void {
    expect(pricingSignature(RecordAvailabilitySearch::class, 'handle'))->toBe([['event' => AvailabilitySearched::class], 'void']);
});

it('ships every C§12 pricing config key with its default', function (): void {
    expect(config('pricing.bands'))->toBe([
        ['key' => '1-2', 'min' => 1, 'max' => 2],
        ['key' => '3-4', 'min' => 3, 'max' => 4],
        ['key' => '5+', 'min' => 5, 'max' => null],
    ])
        ->and(config('pricing.thin_band_threshold'))->toBe(5)
        ->and(config('pricing.signals_window_days'))->toBe(28)
        ->and(config('pricing.search_retention_days'))->toBe(90)
        ->and(config('pricing.tiers.peak'))->toBe(['bps' => 15000, 'days' => [5, 6], 'hours' => [19, 20, 21]])
        ->and(config('pricing.tiers.shoulder'))->toBe(['bps' => 12000, 'days' => [4, 5, 6, 7], 'hours' => [18, 19, 20, 21, 22]])
        ->and(config('pricing.tiers.off_peak.bps'))->toBe(10000)
        ->and(config('pricing.demand.per_unmet_search_bps'))->toBe(100)
        ->and(config('pricing.demand.unmet_cap_bps'))->toBe(5000)
        ->and(config('pricing.demand.sellout'))->toBe([
            ['max_seconds' => 60, 'bps' => 2500],
            ['max_seconds' => 600, 'bps' => 1000],
        ])
        ->and(config('pricing.rounding_cents'))->toBe(100)
        ->and(config('pricing.min_per_person_cents'))->toBe(1000);
});

it('registers the pricing commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('pricing:compute-demand-signals')->toContain('pricing:prune-search-events');
});

it('reads Inventory only through the contract classes', function (): void {
    $allowed = [
        'App\Modules\Inventory\Actions\GetSelloutStats',
        'App\Modules\Inventory\Data\SelloutStat',
        'App\Modules\Inventory\Events\AvailabilitySearched',
    ];
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Pricing')));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/App\\\\Modules\\\\Inventory\\\\[A-Za-z\\\\]+/', (string) file_get_contents($file->getPathname()), $matches);
        $found = array_merge($found, $matches[0]);
    }

    expect(array_values(array_diff(array_unique($found), $allowed)))->toBe([]);
});

it('never queries Inventory tables', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Pricing')));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        expect((string) file_get_contents($file->getPathname()))->not->toMatch('/\b(from|join|into|update)\s+[\'"`]?(slots|holds|venues|drops|cities)\b/i');
    }
});

it('keeps money math integer-only in PriceCalculator', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Pricing/Support/PriceCalculator.php'));

    expect($source)->not->toMatch('/\bfloat\b|\bround\(|\bfloor\(|\bceil\(/')
        ->and($source)->not->toMatch('/[\w\)]\s\/\s[\w\(]/');
});
