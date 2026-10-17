<?php

declare(strict_types=1);

return [
    'outbox' => [
        'batch' => 100,
        'sleep_ms' => 100,
        'retention_days' => 7,
    ],
];
