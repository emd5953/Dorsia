<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Needs a real commit (no wrapper transaction), hence the truncation-based suite.
it('does not report a dead-letter critical when the commit fails, so a retry cannot double-report', function (): void {
    $row = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ['venue_id' => 2], 'created_at' => now()]);

    DB::unprepared('CREATE FUNCTION realtime_test_fail_commit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'commit failed\'; END $$');
    DB::unprepared('CREATE CONSTRAINT TRIGGER realtime_test_fail_commit AFTER UPDATE ON outbox_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION realtime_test_fail_commit()');

    try {
        Log::spy();
        expect(fn () => app(PublishOutboxBatch::class)->handle())->toThrow(PDOException::class);
        Log::shouldNotHaveReceived('critical');
    } finally {
        DB::unprepared('DROP TRIGGER realtime_test_fail_commit ON outbox_events');
        DB::unprepared('DROP FUNCTION realtime_test_fail_commit()');
    }

    expect($row->fresh()->attempts)->toBe(0);
});
