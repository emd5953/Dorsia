<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Exceptions\DropNotFoundException;
use App\Modules\Inventory\Http\Resources\DropResource;
use App\Modules\Inventory\Models\Drop;

final class DropController
{
    public function show(int $drop): DropResource
    {
        return new DropResource(Drop::query()->find($drop) ?? throw new DropNotFoundException);
    }
}
