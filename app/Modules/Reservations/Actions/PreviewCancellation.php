<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Reservations\Data\CancellationPreview;
use App\Modules\Reservations\Support\CancellationPolicy;

class PreviewCancellation
{
    public function __construct(private readonly GetReservation $get, private readonly CancellationPolicy $policy) {}

    public function handle(int $memberId, int $reservationId): CancellationPreview
    {
        return $this->policy->preview($this->get->handle($memberId, $reservationId), now()->toImmutable());
    }
}
