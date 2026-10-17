<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Support;

final readonly class PriceBreakdown
{
    /**
     * @param  array<string, mixed>  $inputs  C§10 inputs shape
     */
    public function __construct(public int $perPersonCents, public array $inputs) {}
}
