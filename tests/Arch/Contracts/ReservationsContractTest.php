<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ConvertHoldToBooking;
use App\Modules\Inventory\Actions\ExpireHold;
use App\Modules\Inventory\Actions\GetHold;
use App\Modules\Inventory\Actions\ReleaseBooking;
use App\Modules\Inventory\Actions\ReleaseHold;
use App\Modules\Inventory\Data\HoldData;
use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\MarkPaymentForCapture;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Pricing\Actions\GetQuoteForHold;
use App\Modules\Pricing\Data\QuoteData;
use App\Modules\Reservations\Actions\CancelReservation;
use App\Modules\Reservations\Actions\ConfirmReservation;
use App\Modules\Reservations\Actions\GetHoldView;
use App\Modules\Reservations\Actions\GetReservation;
use App\Modules\Reservations\Actions\ListReservations;
use App\Modules\Reservations\Actions\PlaceHold;
use App\Modules\Reservations\Actions\PreviewCancellation;
use App\Modules\Reservations\Data\CancellationData;
use App\Modules\Reservations\Data\CancellationExpectation;
use App\Modules\Reservations\Data\CancellationPreview;
use App\Modules\Reservations\Data\HoldView;
use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Support\CancellationPolicy;
use App\Modules\Wallet\Actions\PlanSpend;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Data\SpendPlan;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Money\Money;
use App\Shared\Outbox\Outbox;
use Carbon\CarbonImmutable;

/** @return array{array<string, string>, string} */
function reservationsSignature(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);
    $parameters = [];

    foreach ($reflection->getParameters() as $parameter) {
        $parameters[$parameter->getName()] = (string) $parameter->getType();
    }

    return [$parameters, (string) $reflection->getReturnType()];
}

it('declares the reservation enum and readonly DTO constructors from C§5.6', function (): void {
    expect(collect(ReservationStatus::cases())->mapWithKeys(fn (ReservationStatus $case): array => [$case->name => $case->value])->all())->toBe([
        'Confirmed' => 'confirmed', 'CancelledEarly' => 'cancelled_early', 'CancelledLate' => 'cancelled_late',
    ])
        ->and(reservationsSignature(HoldView::class, '__construct')[0])->toBe(['hold' => HoldData::class, 'quote' => '?'.QuoteData::class])
        ->and(reservationsSignature(CancellationData::class, '__construct')[0])->toBe(['reservation' => ReservationData::class, 'late' => 'bool', 'creditRefunded' => Money::class, 'forfeited' => Money::class]);

    foreach ([HoldView::class, ReservationData::class, CancellationData::class] as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
    }

    expect(reservationsSignature(ReservationData::class, '__construct')[0])->toBe([
        'id' => 'int', 'memberId' => 'int', 'holdId' => 'int', 'slotId' => 'int', 'venueId' => 'int',
        'venueTimezone' => 'string', 'startsAt' => CarbonImmutable::class, 'partySize' => 'int', 'minSpend' => Money::class,
        'payment' => SpendPlan::class, 'status' => ReservationStatus::class, 'confirmedAt' => CarbonImmutable::class,
        'cancelledAt' => '?'.CarbonImmutable::class, 'cancellationCredit' => '?'.Money::class,
    ]);
});

it('declares public action signatures from C§5.6', function (): void {
    expect(reservationsSignature(PlaceHold::class, 'handle'))->toBe([['memberId' => 'int', 'slotId' => 'int', 'partySize' => 'int', 'key' => IdempotencyKey::class], HoldView::class])
        ->and(reservationsSignature(GetHoldView::class, 'handle'))->toBe([['memberId' => 'int', 'holdId' => 'int'], HoldView::class])
        ->and(reservationsSignature(ConfirmReservation::class, 'handle'))->toBe([['memberId' => 'int', 'holdId' => 'int', 'cardToken' => '?string', 'key' => IdempotencyKey::class], ReservationData::class])
        ->and(reservationsSignature(CancelReservation::class, 'handle'))->toBe([['memberId' => 'int', 'reservationId' => 'int', 'expected' => '?'.CancellationExpectation::class], CancellationData::class])
        ->and(reservationsSignature(GetReservation::class, 'handle'))->toBe([['memberId' => 'int', 'reservationId' => 'int'], ReservationData::class])
        ->and(reservationsSignature(ListReservations::class, 'handle'))->toBe([['memberId' => 'int'], 'array']);
});

it('keeps the documented confirm dependency order', function (): void {
    $dependencies = array_slice(reservationsSignature(ConfirmReservation::class, '__construct')[0], 0, 11, true);

    expect($dependencies)->toBe([
        'getHold' => GetHold::class, 'expireHold' => ExpireHold::class, 'releaseHold' => ReleaseHold::class,
        'convertHold' => ConvertHoldToBooking::class, 'getQuote' => GetQuoteForHold::class, 'planSpend' => PlanSpend::class,
        'postBookingPayment' => PostBookingPayment::class, 'authorizePayment' => AuthorizePayment::class,
        'markPaymentForCapture' => MarkPaymentForCapture::class, 'voidPayment' => VoidPayment::class, 'outbox' => Outbox::class,
    ]);
    expect(reservationsSignature(CancelReservation::class, '__construct')[0])->toBe([
        'releaseBooking' => ReleaseBooking::class, 'postRefund' => PostCancellationRefund::class, 'policy' => CancellationPolicy::class,
    ]);
});

it('declares additive cancellation preview contracts and keeps the guard optional', function (): void {
    expect(reservationsSignature(PreviewCancellation::class, 'handle'))->toBe([['memberId' => 'int', 'reservationId' => 'int'], CancellationPreview::class])
        ->and((new ReflectionMethod(CancelReservation::class, 'handle'))->getParameters()[2]->getDefaultValue())->toBeNull();
    expect(reservationsSignature(CancellationExpectation::class, '__construct')[0])->toBe(['creditCents' => 'int', 'forfeitedCents' => 'int', 'currency' => 'string', 'late' => 'bool']);
    expect(reservationsSignature(CancellationPreview::class, '__construct')[0])->toBe([
        'reservationId' => 'int', 'late' => 'bool', 'creditRefunded' => Money::class, 'forfeited' => Money::class,
        'evaluatedAt' => CarbonImmutable::class, 'earlyDeadline' => CarbonImmutable::class, 'startsAt' => CarbonImmutable::class,
        'venueTimezone' => 'string', 'cancelWindowHours' => 'int',
    ]);
    foreach ([CancellationExpectation::class, CancellationPreview::class] as $class) {
        expect((new ReflectionClass($class))->isReadOnly())->toBeTrue()->and((new ReflectionClass($class))->isFinal())->toBeTrue();
    }
});
