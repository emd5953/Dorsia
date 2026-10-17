<?php

declare(strict_types=1);

use App\Shared\Idempotency\IdempotencyRecord;
use App\Shared\Members\Member;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('uses the microsecond format', function (): void {
    expect(Timestamps::FORMAT)->toBe('Y-m-d H:i:s.uP');
});

it('formats bindings in UTC with microseconds', function (): void {
    $time = CarbonImmutable::parse('2026-10-16 20:15:30.123456', 'America/New_York');

    expect(Timestamps::bind($time))->toBe('2026-10-17 00:15:30.123456+00:00');
});

it('declares microsecond timestamptz columns', function (): void {
    $columns = DB::select(
        "SELECT table_name, column_name, datetime_precision FROM information_schema.columns
         WHERE table_schema = 'public' AND data_type = 'timestamp with time zone'
           AND table_name IN ('members','personal_access_tokens','failed_jobs','idempotency_keys','outbox_events')"
    );

    expect($columns)->not->toBeEmpty();

    foreach ($columns as $column) {
        expect($column->datetime_precision)->toBe(6, "{$column->table_name}.{$column->column_name}");
    }
});

it('keeps microseconds and order for outbox rows written in the same second', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00.100000'));
    DB::transaction(fn () => app(Outbox::class)->record(OutboxTopic::SlotHeld, ['n' => 1]));

    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00.100001'));
    DB::transaction(fn () => app(Outbox::class)->record(OutboxTopic::SlotHeld, ['n' => 2]));

    $events = OutboxEvent::query()->orderBy('created_at')->get();

    expect($events->pluck('payload.n')->all())->toBe([1, 2])
        ->and($events[0]->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-16 12:00:00.100000')
        ->and($events[1]->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-16 12:00:00.100001');
});

it('round-trips microseconds on members and idempotency rows', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00.654321'));

    $member = Member::factory()->create();
    $record = IdempotencyRecord::query()->create([
        'member_id' => $member->id,
        'key' => 'ts-1',
        'method' => 'POST',
        'path' => '/x',
        'request_hash' => str_repeat('a', 64),
        'status' => 'processing',
        'lock_token' => (string) Str::uuid(),
        'locked_at' => now(),
        'created_at' => now(),
    ]);

    expect(Member::query()->findOrFail($member->id)->created_at->format('u'))->toBe('654321')
        ->and(IdempotencyRecord::query()->findOrFail($record->id)->locked_at->format('u'))->toBe('654321');
});
