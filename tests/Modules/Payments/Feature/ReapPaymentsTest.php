<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Members\Member;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function (): void {
    paymentsSecret();
    Http::fake();
});

it('reaps only pending payments older than the configured threshold', function (): void {
    $member = Member::factory()->create();
    $stale = Payment::factory()->pending()->create(['member_id' => $member->id, 'created_at' => now()->subSeconds(121)]);
    $fresh = Payment::factory()->pending()->create(['member_id' => $member->id, 'created_at' => now()->subSeconds(119)]);

    Artisan::call('payments:reap');

    expect($stale->fresh()?->status)->toBe(PaymentStatus::TimedOut)
        ->and($fresh->fresh()?->status)->toBe(PaymentStatus::Pending);

    Artisan::call('payments:reap');
    expect($stale->fresh()?->status)->toBe(PaymentStatus::TimedOut);
});

it('voids only old unattached authorizations and leaves attached and younger payments alone', function (): void {
    $old = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 401));
    $young = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 402));
    $attached = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 403));
    Payment::query()->whereKey($old->id)->update(['created_at' => now()->subSeconds(421)]);
    Payment::query()->whereKey($attached->id)->update(['created_at' => now()->subSeconds(421), 'reservation_id' => 77]);

    Artisan::call('payments:reap');

    expect($old->id)->toBeInt()
        ->and(Payment::query()->findOrFail($old->id)->status)->toBe(PaymentStatus::Refunded)
        ->and(Payment::query()->findOrFail($young->id)->status)->toBe(PaymentStatus::Authorized)
        ->and(Payment::query()->findOrFail($attached->id)->status)->toBe(PaymentStatus::Authorized);

    Artisan::call('payments:reap');
    expect(Payment::query()->findOrFail($old->id)->status)->toBe(PaymentStatus::Refunded);
});

it('registers the reaper for every minute', function (): void {
    $events = app(Schedule::class)->events();

    expect(collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'payments:reap')))->not->toBeNull();
});
