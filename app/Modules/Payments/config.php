<?php

declare(strict_types=1);

use Illuminate\Support\Env;

// Env::get(), not env(): identical behaviour, and Larastan's no-env-outside-config rule only matches env().
return [
    'gateway' => Env::get('PAYMENTS_GATEWAY', 'fake'),

    'reap' => [
        'pending_after_seconds' => 120,
        'orphan_grace_seconds' => 120,
    ],

    'fake' => [
        'latency_ms' => (int) Env::get('FAKE_GATEWAY_LATENCY_MS', 0),
        'emit_webhooks' => filter_var(Env::get('FAKE_GATEWAY_EMIT_WEBHOOKS', false), FILTER_VALIDATE_BOOL),
        'duplicate_webhooks' => filter_var(Env::get('FAKE_GATEWAY_DUPLICATE_WEBHOOKS', false), FILTER_VALIDATE_BOOL),
        'webhook_secret' => Env::get('FAKE_GATEWAY_WEBHOOK_SECRET'),
    ],
];
