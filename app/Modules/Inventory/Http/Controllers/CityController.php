<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Http\Resources\CityResource;
use App\Modules\Inventory\Models\City;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CityController
{
    public function index(): AnonymousResourceCollection
    {
        return CityResource::collection(City::query()->orderBy('name')->orderBy('id')->get());
    }
}
