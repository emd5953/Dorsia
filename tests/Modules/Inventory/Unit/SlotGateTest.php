<?php

declare(strict_types=1);

use App\Modules\Inventory\Support\SlotGate;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::connection()->flushdb();
    $this->gate = new SlotGate;
});

function seedGate(int $slotId, int $value): void
{
    Redis::connection()->command('set', ["slot_gate:{$slotId}", $value]);
}

it('reports a missing key without creating it', function (): void {
    expect($this->gate->acquire(5))->toBe(SlotGate::MISSING)
        ->and($this->gate->value(5))->toBeNull();
});

it('stores the counter under the dorsia-prefixed key dorsia:slot_gate:{id}', function (): void {
    seedGate(5, 2);

    $keys = Redis::connection()->command('keys', ['*']);

    expect($keys)->toContain('dorsia:slot_gate:5');
});

it('hands out exactly the counter value then sells out', function (): void {
    seedGate(5, 2);

    expect($this->gate->acquire(5))->toBe(1)
        ->and($this->gate->acquire(5))->toBe(0)
        ->and($this->gate->acquire(5))->toBe(SlotGate::SOLD_OUT);
});

it('leaves the value at 0, not -1, after a sold-out attempt', function (): void {
    seedGate(5, 1);
    $this->gate->acquire(5);

    expect($this->gate->acquire(5))->toBe(SlotGate::SOLD_OUT)
        ->and($this->gate->acquire(5))->toBe(SlotGate::SOLD_OUT)
        ->and($this->gate->value(5))->toBe(0);
});

it('sells out immediately when the counter starts at zero', function (): void {
    seedGate(5, 0);

    expect($this->gate->acquire(5))->toBe(SlotGate::SOLD_OUT)
        ->and($this->gate->value(5))->toBe(0);
});

it('increments on release when the key exists', function (): void {
    seedGate(5, 0);

    $this->gate->release(5);
    $this->gate->release(5);

    expect($this->gate->value(5))->toBe(2);
});

it('does nothing on release when the key is missing', function (): void {
    $this->gate->release(5);

    expect($this->gate->value(5))->toBeNull()
        ->and(Redis::connection()->command('keys', ['*']))->toBe([]);
});

it('keeps counters of different slots apart', function (): void {
    seedGate(1, 1);
    seedGate(2, 1);

    $this->gate->acquire(1);

    expect($this->gate->value(1))->toBe(0)
        ->and($this->gate->value(2))->toBe(1);
});
