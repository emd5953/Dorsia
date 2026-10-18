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
    private const SAVEPOINT = 'pricing_record_search';

    public function __construct(private readonly RecordSearch $recordSearch) {}

    /** A failed search record must never break the availability request. */
    public function handle(AvailabilitySearched $event): void
    {
        // Inside a caller's transaction a failed statement aborts it. We use a raw savepoint instead of
        // DB::transaction(): on deadlock/serialization errors Laravel skips the savepoint rollback and
        // desynchronises its level counter, which we cannot repair once we swallow the exception.
        $nested = DB::transactionLevel() > 0;

        try {
            if ($nested) {
                DB::statement('SAVEPOINT '.self::SAVEPOINT);
            }

            $this->recordSearch->handle($event);

            if ($nested) {
                DB::statement('RELEASE SAVEPOINT '.self::SAVEPOINT);
            }
        } catch (Throwable $e) {
            $this->rollBackToSavepoint($nested);

            Log::warning('Failed to record availability search.', [
                'member_id' => $event->memberId,
                'venue_id' => $event->venueId,
                'exception' => $e,
            ]);
        }
    }

    private function rollBackToSavepoint(bool $nested): void
    {
        if (! $nested) {
            return;
        }

        try {
            DB::statement('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
        } catch (Throwable $e) {
            Log::warning('Could not roll back to the search savepoint.', ['exception' => $e]);
        }
    }
}
