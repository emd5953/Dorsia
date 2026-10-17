<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ExpireHold;
use App\Modules\Inventory\Actions\GetHold;
use App\Modules\Inventory\Actions\ReleaseHold;
use App\Modules\Inventory\Enums\HoldReleaseReason;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Inventory\Models\Hold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Modules\Inventory\Support\Fixtures;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00.123456'));
});

it('keeps a hold active one microsecond before expires_at and expires it at expires_at exactly', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $hold = Fixtures::hold($slot, $member);

    $this->travelTo($hold->expiresAt->subMicrosecond());
    expect(app(GetHold::class)->handle($hold->id, $member->id)->status->value)->toBe('active')
        ->and($slot->fresh()->held_count)->toBe(1);

    $this->travelTo($hold->expiresAt);
    $view = app(GetHold::class)->handle($hold->id, $member->id);

    expect($view->status->value)->toBe('expired')
        ->and($view->slot->heldCount)->toBe(0)
        ->and($slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(3);

    $released = Fixtures::outbox('slot.released');
    expect($released)->toHaveCount(1)
        ->and($released->first()->payload['reason'])->toBe('expired')
        ->and($released->first()->payload['available'])->toBe(3)
        ->and($released->first()->payload['slot_id'])->toBe($slot->id);

    $row = Hold::query()->findOrFail($hold->id);
    expect($row->status)->toBe('expired')
        ->and($row->close_reason)->toBe('expired')
        ->and($row->closed_at?->equalTo($hold->expiresAt))->toBeTrue();
});

it('expires lazily once: a second read changes nothing', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $hold = Fixtures::hold($slot, $member);

    $this->travelTo($hold->expiresAt);
    app(GetHold::class)->handle($hold->id, $member->id);
    app(GetHold::class)->handle($hold->id, $member->id);

    expect(Fixtures::outbox('slot.released'))->toHaveCount(1)
        ->and(Fixtures::gate($slot))->toBe(3)
        ->and($slot->fresh()->held_count)->toBe(0);
});

it('hides another member\'s hold and unknown holds as not found', function (): void {
    $slot = Fixtures::slot();
    $hold = Fixtures::hold($slot);

    expect(fn () => app(GetHold::class)->handle($hold->id, Fixtures::member()->id))->toThrow(HoldNotFoundException::class)
        ->and(fn () => app(GetHold::class)->handle(999_999, $hold->memberId))->toThrow(HoldNotFoundException::class);
});

it('ExpireHold does nothing before expires_at, expires at it, and reports true only once', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    $expire = app(ExpireHold::class);

    $this->travelTo($hold->expiresAt->subMicrosecond());
    expect($expire->handle($hold->id))->toBeFalse()
        ->and($slot->fresh()->held_count)->toBe(1);

    $this->travelTo($hold->expiresAt);
    expect($expire->handle($hold->id))->toBeTrue()
        ->and($expire->handle($hold->id))->toBeFalse()
        ->and($expire->handle(999_999))->toBeFalse()
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(1)
        ->and(Fixtures::gate($slot))->toBe(2);
});

it('ReleaseHold releases once with the reason and is a no-op the second time', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    $release = app(ReleaseHold::class);

    expect($release->handle($hold->id, HoldReleaseReason::PaymentFailed))->toBeTrue();

    $row = Hold::query()->findOrFail($hold->id);
    expect($row->status)->toBe('released')
        ->and($row->close_reason)->toBe('payment_failed')
        ->and($slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(2);

    expect($release->handle($hold->id, HoldReleaseReason::PaymentFailed))->toBeFalse()
        ->and($release->handle($hold->id, HoldReleaseReason::PaymentTimeout))->toBeFalse()
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(1)
        ->and(Fixtures::outbox('slot.released')->first()->payload['reason'])->toBe('payment_failed')
        ->and(Fixtures::gate($slot))->toBe(2)
        ->and($slot->fresh()->held_count)->toBe(0);
});

it('ReleaseHold carries the payment_timeout reason into the outbox', function (): void {
    $hold = Fixtures::hold(Fixtures::slot());

    app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentTimeout);

    expect(Fixtures::outbox('slot.released')->first()->payload['reason'])->toBe('payment_timeout');
});

it('HoldCloser does not release a gate unit when its enclosing transaction rolls back', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    $gate = Fixtures::gate($slot);

    try {
        DB::transaction(function () use ($hold): void {
            app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentFailed);
            throw new RuntimeException('rollback closer');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Fixtures::gate($slot))->toBe($gate)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Hold::query()->findOrFail($hold->id)->status)->toBe('active')
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(0);
});

it('ReleaseHold does not touch a hold that was already expired or converted', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    $this->travelTo($hold->expiresAt);
    app(ExpireHold::class)->handle($hold->id);

    expect(app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentFailed))->toBeFalse()
        ->and(Hold::query()->findOrFail($hold->id)->status)->toBe('expired')
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(1);
});

it('does not return a gate unit when the gate key is missing', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    Redis::connection()->flushdb();

    app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentFailed);

    expect(Fixtures::gate($slot))->toBeNull();
});
