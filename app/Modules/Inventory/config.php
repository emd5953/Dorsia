<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [
    'hold_ttl_seconds' => (int) Env::get('HOLD_TTL_SECONDS', 300),
    'hold_expiring_notice_seconds' => 60,
    'gate' => [
        'rebuild_lock_seconds' => 5,
        'rebuild_wait_ms' => 200,
        'refresh_seconds' => 5,
        'rebuild_recent_minutes' => 2,
    ],
    'sweep_batch' => 500,
    'hold_key_lock' => [
        'seconds' => 10,
        'wait_seconds' => 5,
    ],
];
