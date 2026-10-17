<?php

declare(strict_types=1);

use App\Modules\Inventory\Exceptions\ActiveHoldExistsException;
use App\Modules\Inventory\Models\Hold;
use Illuminate\Redis\Events\CommandExecuted;
use Tests\Modules\Inventory\Support\Fixtures;

it('answers a duplicate hold of the same member without taking or touching a gate unit', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $member = Fixtures::member();
    Fixtures::hold($slot, $member);

    $commands = [];
    Fixtures::listenRedis(function (CommandExecuted $command) use (&$commands): void {
        $commands[] = strtolower($command->command);
    });

    expect(fn () => Fixtures::hold($slot, $member))->toThrow(ActiveHoldExistsException::class);

    expect($commands)->toBe([])

        ->and(Fixtures::gate($slot))->toBe(1)
        ->and(Hold::query()->count())->toBe(1);

    // Guard against a listener that never fires: a hold by another member does reach Redis.
    Fixtures::hold($slot);

    expect($commands)->not->toBeEmpty();
});

it('still lets a member hold again once their own hold has expired', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $member = Fixtures::member();
    Fixtures::hold($slot, $member);
    Hold::query()->update(['expires_at' => now()->subSecond()]);

    $again = Fixtures::hold($slot, $member);

    expect($again->status->value)->toBe('active')
        ->and(Hold::query()->where('status', 'active')->count())->toBe(1)
        ->and($slot->fresh()->held_count)->toBe(1);
});
