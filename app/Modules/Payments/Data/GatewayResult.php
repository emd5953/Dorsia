<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\GatewayStatus;

final readonly class GatewayResult
{
    public function __construct(
        public GatewayStatus $status,
        public ?string $providerRef,
        public ?string $declineCode = null,
    ) {}
}
