<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Listeners;

use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Pricing\Actions\RecordSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordAvailabilitySearch
{
    public function __construct(private readonly RecordSearch $recordSearch) {}

    /** A failed search record must never break the availability request. */
    public function handle(AvailabilitySearched $event): void
    {
        try {
            // Savepoint when nested, so a failed insert cannot abort the caller's transaction.
            DB::transaction(fn (): bool => $this->recordSearch->handle($event));
        } catch (Throwable $e) {
            Log::warning('Failed to record availability search.', [
                'member_id' => $event->memberId,
                'venue_id' => $event->venueId,
                'exception' => $e,
            ]);
        }
    }
}
