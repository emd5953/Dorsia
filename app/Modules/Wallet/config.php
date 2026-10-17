<?php

declare(strict_types=1);

use Illuminate\Support\Env;

// Env::get() is what env() calls; Larastan forbids the env() helper outside the root config directory,
// and module config files are merged by the provider rather than loaded from there.
return [
    'currency' => 'USD',

    // The split is an assumption (the published policy only says 50% is forfeited), so it lives in config.
    'late_cancel' => [
        'member_bps' => (int) Env::get('LATE_CANCEL_MEMBER_BPS', 5000),
        'platform_bps' => (int) Env::get('LATE_CANCEL_PLATFORM_BPS', 2500),
    ],
];
