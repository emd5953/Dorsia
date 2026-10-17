<?php

declare(strict_types=1);

namespace App\Modules\Realtime;

use App\Modules\Realtime\Console\PruneOutboxCommand;
use App\Modules\Realtime\Console\PublishOutboxCommand;
use App\Modules\Realtime\Http\ValidateBroadcastAuthRequest;
use App\Shared\Members\Member;
use App\Shared\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;

final class RealtimeServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Broadcast::routes(['middleware' => ['auth:sanctum', ValidateBroadcastAuthRequest::class]]);
        Broadcast::channel('member.{memberId}', fn (Member $member, int $memberId): bool => (int) $member->id === $memberId);
    }

    /** @return array<int, class-string> */
    protected function consoleCommands(): array
    {
        return [PublishOutboxCommand::class, PruneOutboxCommand::class];
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('realtime:prune-outbox')->dailyAt('05:00')->timezone('UTC')->withoutOverlapping()->onOneServer();
    }
}
