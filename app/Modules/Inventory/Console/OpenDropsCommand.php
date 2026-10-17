<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Support\SlotRows;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class OpenDropsCommand extends Command
{
    protected $signature = 'inventory:open-drops';

    protected $description = 'Mark due drops as opened and write drop.opened';

    public function handle(Outbox $outbox): int
    {
        $ids = DB::table('drops')
            ->whereNull('opened_at')
            ->where('opens_at', '<=', now())
            ->orderBy('opens_at')
            ->orderBy('id')
            ->pluck('id');

        $opened = 0;

        foreach ($ids as $id) {
            $opened += DB::transaction(function () use ($id, $outbox): int {
                $now = now();

                // The conditional update is the once-only guard, even with two schedulers running.
                $drop = DB::selectOne(
                    'UPDATE drops SET opened_at = ?, updated_at = ? WHERE id = ? AND opened_at IS NULL RETURNING *',
                    [$now, $now, $id],
                );

                if ($drop === null) {
                    return 0;
                }

                $outbox->record(OutboxTopic::DropOpened, [
                    'drop_id' => (int) $drop->id,
                    'venue_id' => (int) $drop->venue_id,
                    'opens_at' => SlotRows::iso(SlotRows::time($drop->opens_at)),
                    'slot_ids' => array_map(intval(...), DB::table('slots')->where('drop_id', $id)->orderBy('id')->pluck('id')->all()),
                ]);

                return 1;
            });
        }

        $this->info("Opened {$opened} drops.");

        return self::SUCCESS;
    }
}
