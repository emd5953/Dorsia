<?php

declare(strict_types=1);

return [

    /*
    | A `processing` row whose lock is older than this is taken over by the
    | next request that carries the same key (C§8).
    */
    'stale_after_seconds' => 30,

    /*
    | Rows older than this are deleted by `idempotency:prune`.
    */
    'retention_hours' => 24,

];
