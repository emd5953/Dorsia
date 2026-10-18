<?php

declare(strict_types=1);

return [
    'outbox' => [
        'batch' => 100,
        'sleep_ms' => 100,
        'retention_days' => 7,
        'max_attempts' => 5,
    ],
];
