<?php

declare(strict_types=1);

return [
    'bands' => [
        ['key' => '1-2', 'min' => 1, 'max' => 2],
        ['key' => '3-4', 'min' => 3, 'max' => 4],
        ['key' => '5+', 'min' => 5, 'max' => null],
    ],
    'thin_band_threshold' => 5,
    'signals_window_days' => 28,
    'search_retention_days' => 90,
    'tiers' => [
        'peak' => ['bps' => 15000, 'days' => [5, 6], 'hours' => [19, 20, 21]],
        'shoulder' => ['bps' => 12000, 'days' => [4, 5, 6, 7], 'hours' => [18, 19, 20, 21, 22]],
        'off_peak' => ['bps' => 10000],
    ],
    'demand' => [
        'per_unmet_search_bps' => 100,
        'unmet_cap_bps' => 5000,
        'sellout' => [
            ['max_seconds' => 60, 'bps' => 2500],
            ['max_seconds' => 600, 'bps' => 1000],
        ],
    ],
    'rounding_cents' => 100,
    'min_per_person_cents' => 1000,
];
