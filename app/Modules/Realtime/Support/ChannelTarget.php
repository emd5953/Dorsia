<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use Illuminate\Broadcasting\Channel;

final readonly class ChannelTarget
{
    public function __construct(public Channel $channel, public string $event) {}
}
