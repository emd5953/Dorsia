<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ConvertHoldToBooking;
use App\Modules\Inventory\Actions\ExpireHold;
use App\Modules\Inventory\Actions\GetHold;
use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Inventory\Actions\GetSlot;
use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Actions\ReleaseBooking;
use App\Modules\Inventory\Actions\ReleaseHold;
use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Data\SelloutStat;
use App\Modules\Inventory\Data\SlotData;
use App\Modules\Inventory\Enums\HoldReleaseReason;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Inventory\Exceptions\ActiveHoldExistsException;
use App\Modules\Inventory\Exceptions\DropNotFoundException;
use App\Modules\Inventory\Exceptions\DropNotOpenException;
use App\Modules\Inventory\Exceptions\HoldExpiredException;
use App\Modules\Inventory\Exceptions\HoldNotActiveException;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Inventory\Exceptions\PartySizeNotAllowedException;
use App\Modules\Inventory\Exceptions\SlotNotFoundException;
use App\Modules\Inventory\Exceptions\SlotUnavailableException;
use App\Modules\Inventory\Exceptions\SoldOutException;
use App\Modules\Inventory\Exceptions\VenueNotFoundException;
use App\Modules\Inventory\Support\SlotGate;
use App\Shared\Exceptions\DomainError;
use App\Shared\Money\Money;
use App\Shared\Outbox\Outbox;
use Carbon\CarbonImmutable;

/**
 * @return array{array<string, string>, string} [name => type, return type]
 */
function inventorySignature(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);
    $params = [];

    foreach ($reflection->getParameters() as $parameter) {
        $params[$parameter->getName()] = (string) $parameter->getType();
    }

    return [$params, (string) $reflection->getReturnType()];
}

it('declares the enums with exactly the C§5.1 cases', function (): void {
    expect(collect(HoldStatus::cases())->mapWithKeys(fn (HoldStatus $c): array => [$c->name => $c->value])->all())->toBe([
        'Active' => 'active',
        'Converted' => 'converted',
        'Released' => 'released',
        'Expired' => 'expired',
    ])->and(collect(HoldReleaseReason::cases())->mapWithKeys(fn (HoldReleaseReason $c): array => [$c->name => $c->value])->all())->toBe([
        'PaymentFailed' => 'payment_failed',
        'PaymentTimeout' => 'payment_timeout',
        'ConfirmFailed' => 'confirm_failed',
    ]);

    expect((new ReflectionEnum(HoldStatus::class))->getBackingType()?->getName())->toBe('string')
        ->and((new ReflectionEnum(HoldReleaseReason::class))->getBackingType()?->getName())->toBe('string');
});

it('declares the DTOs as final readonly with the C§5.1 constructors', function (): void {
    expect(inventorySignature(SlotData::class, '__construct')[0])->toBe([
        'id' => 'int',
        'venueId' => 'int',
        'dropId' => '?int',
        'venueTimezone' => 'string',
        'startsAt' => CarbonImmutable::class,
        'releasedAt' => CarbonImmutable::class,
        'capacity' => 'int',
        'heldCount' => 'int',
        'bookedCount' => 'int',
        'minPartySize' => 'int',
        'maxPartySize' => 'int',
        'basePerPerson' => Money::class,
        'soldOutAt' => '?'.CarbonImmutable::class,
    ])->and(inventorySignature(SlotData::class, 'available'))->toBe([[], 'int'])
        ->and(inventorySignature(HoldData::class, '__construct')[0])->toBe([
            'id' => 'int',
            'memberId' => 'int',
            'partySize' => 'int',
            'status' => HoldStatus::class,
            'expiresAt' => CarbonImmutable::class,
            'createdAt' => CarbonImmutable::class,
            'slot' => SlotData::class,
        ])->and(inventorySignature(HoldSlotInput::class, '__construct')[0])->toBe([
            'memberId' => 'int',
            'slotId' => 'int',
            'partySize' => 'int',
            'idempotencyKey' => 'string',
        ])->and(inventorySignature(SelloutStat::class, '__construct')[0])->toBe([
            'venueId' => 'int',
            'weekday' => 'int',
            'hour' => 'int',
            'p50Seconds' => 'int',
        ]);

    foreach ([SlotData::class, HoldData::class, HoldSlotInput::class, SelloutStat::class] as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
    }
});

it('declares the actions with the C§5.1 handle() signatures', function (string $class, array $params, string $return): void {
    expect(inventorySignature($class, 'handle'))->toBe([$params, $return])
        ->and((new ReflectionClass($class))->isFinal())->toBeFalse();

    $public = array_filter(
        (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! $m->isConstructor() && $m->getDeclaringClass()->getName() === $class,
    );

    expect($public)->toHaveCount(1);
})->with([
    'HoldSlot' => [HoldSlot::class, ['input' => HoldSlotInput::class, 'inTransaction' => '?callable'], HoldData::class],
    'GetHold' => [GetHold::class, ['holdId' => 'int', 'memberId' => 'int'], HoldData::class],
    'ExpireHold' => [ExpireHold::class, ['holdId' => 'int'], 'bool'],
    'ReleaseHold' => [ReleaseHold::class, ['holdId' => 'int', 'reason' => HoldReleaseReason::class], 'bool'],
    'ConvertHoldToBooking' => [ConvertHoldToBooking::class, ['holdId' => 'int', 'memberId' => 'int'], SlotData::class],
    'ReleaseBooking' => [ReleaseBooking::class, ['slotId' => 'int'], SlotData::class],
    'GetSlot' => [GetSlot::class, ['slotId' => 'int'], SlotData::class],
    'GetSelloutStats' => [GetSelloutStats::class, ['since' => CarbonImmutable::class], 'array'],
]);

it('keeps the binding HoldSlot constructor dependencies first: Outbox, then SlotGate', function (): void {
    $params = array_slice(inventorySignature(HoldSlot::class, '__construct')[0], 0, 2, true);

    expect($params)->toBe(['outbox' => Outbox::class, 'gate' => SlotGate::class]);
});

it('declares the C§5.1 exceptions with their code, status and details', function (string $class, string $code, int $status): void {
    expect(is_subclass_of($class, DomainError::class))->toBeTrue();

    $exception = match ($class) {
        PartySizeNotAllowedException::class => new PartySizeNotAllowedException(2, 4),
        HoldNotActiveException::class => new HoldNotActiveException(HoldStatus::Expired),
        default => new $class,
    };

    expect($exception->errorCode())->toBe($code)->and($exception->httpStatus())->toBe($status);
})->with([
    [SlotNotFoundException::class, 'not_found', 404],
    [VenueNotFoundException::class, 'not_found', 404],
    [DropNotFoundException::class, 'not_found', 404],
    [HoldNotFoundException::class, 'not_found', 404],
    [SlotUnavailableException::class, 'slot_unavailable', 409],
    [DropNotOpenException::class, 'drop_not_open', 409],
    [PartySizeNotAllowedException::class, 'party_size_not_allowed', 422],
    [SoldOutException::class, 'sold_out', 409],
    [ActiveHoldExistsException::class, 'active_hold_exists', 409],
    [HoldExpiredException::class, 'hold_expired', 409],
    [HoldNotActiveException::class, 'hold_not_active', 409],
]);

it('puts the documented details on the two exceptions that carry them', function (): void {
    expect((new PartySizeNotAllowedException(2, 4))->details())->toBe(['min_party_size' => 2, 'max_party_size' => 4])
        ->and((new HoldNotActiveException(HoldStatus::Converted))->details())->toBe(['status' => 'converted'])
        ->and((new SoldOutException)->details())->toBe([]);
});

it('declares AvailabilitySearched exactly as C§5.1', function (): void {
    expect(inventorySignature(AvailabilitySearched::class, '__construct')[0])->toBe([
        'memberId' => 'int',
        'venueId' => 'int',
        'venueTimezone' => 'string',
        'requestedDate' => 'string',
        'requestedHour' => 'int',
        'partySize' => 'int',
        'hadAvailability' => 'bool',
        'searchedAt' => CarbonImmutable::class,
    ]);

    $reflection = new ReflectionClass(AvailabilitySearched::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});

it('registers the C§13 commands and the config keys of C§12', function (): void {
    expect(array_keys(Artisan::all()))->toContain('inventory:expire-holds', 'inventory:open-drops')
        ->and(config('inventory.hold_ttl_seconds'))->toBe(300)
        ->and(config('inventory.hold_expiring_notice_seconds'))->toBe(60)
        ->and(config('inventory.gate.rebuild_lock_seconds'))->toBe(5)
        ->and(config('inventory.gate.rebuild_wait_ms'))->toBe(200)
        ->and(config('inventory.gate.refresh_seconds'))->toBe(5)
        ->and(config('inventory.gate.rebuild_recent_minutes'))->toBe(2)
        ->and(config('inventory.sweep_batch'))->toBe(500);
});

it('serves the C§8 Inventory routes behind auth:sanctum', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->mapWithKeys(
        fn ($route): array => [implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri() => $route->gatherMiddleware()],
    );

    foreach ([
        'GET api/cities', 'GET api/venues', 'GET api/venues/{venue}', 'GET api/venues/{venue}/availability',
        'GET api/slots/{slot}', 'GET api/drops/{drop}',
    ] as $route) {
        expect($routes)->toHaveKey($route)
            ->and($routes[$route])->toContain('auth:sanctum');
    }
});
