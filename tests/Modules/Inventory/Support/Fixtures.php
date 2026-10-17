<?php

declare(strict_types=1);

namespace Tests\Modules\Inventory\Support;

use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Inventory\Support\SlotGate;
use App\Shared\Members\Member;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Test fixtures for the Inventory and hold tests.
 */
final class Fixtures
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function slot(array $attributes = [], ?Venue $venue = null): Slot
    {
        $venue ??= Venue::factory()->create();

        return Slot::factory()->create(array_merge(['venue_id' => $venue->id], $attributes));
    }

    public static function member(): Member
    {
        return Member::factory()->create();
    }

    public static function token(Member $member): string
    {
        return $member->createToken('test')->plainTextToken;
    }

    public static function hold(Slot $slot, ?Member $member = null, int $partySize = 2, ?string $key = null): HoldData
    {
        $member ??= self::member();

        return app(HoldSlot::class)->handle(new HoldSlotInput($member->id, $slot->id, $partySize, $key ?? (string) Str::uuid()));
    }

    /**
     * @return Collection<int, OutboxEvent>
     */
    public static function outbox(?string $topic = null): Collection
    {
        $query = OutboxEvent::query()->orderBy('id');

        if ($topic !== null) {
            $query->where('topic', $topic);
        }

        return $query->get();
    }

    public static function gate(Slot|int $slot): ?int
    {
        return app(SlotGate::class)->value($slot instanceof Slot ? $slot->id : $slot);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function drop(Venue $venue, array $attributes = []): Drop
    {
        return Drop::factory()->create(array_merge(['venue_id' => $venue->id], $attributes));
    }
}
