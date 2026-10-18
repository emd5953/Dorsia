<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [
    // A cancel more than this many hours before starts_at is early (exactly on the boundary counts as early, D15).
    'cancel_window_hours' => (int) Env::get('CANCEL_WINDOW_HOURS', 8),
];
