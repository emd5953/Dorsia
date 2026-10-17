<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Exceptions\VenueNotFoundException;
use App\Modules\Inventory\Http\Requests\AvailabilityRequest;
use App\Modules\Inventory\Http\Requests\ListVenuesRequest;
use App\Modules\Inventory\Http\Resources\AvailabilityResource;
use App\Modules\Inventory\Http\Resources\VenueResource;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Inventory\Support\SearchAvailability;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class VenueController
{
    public function index(ListVenuesRequest $request): AnonymousResourceCollection
    {
        $query = Venue::query()->where('status', 'active')->orderBy('name')->orderBy('id');

        if ($request->has('city_id')) {
            $query->where('city_id', $request->integer('city_id'));
        }

        return VenueResource::collection($query->get());
    }

    public function show(int $venue): VenueResource
    {
        return new VenueResource(Venue::query()->find($venue) ?? throw new VenueNotFoundException);
    }

    public function availability(int $venue, AvailabilityRequest $request, SearchAvailability $search): AvailabilityResource
    {
        $memberId = $request->user()?->getAuthIdentifier();

        return new AvailabilityResource($search->handle(
            is_numeric($memberId) ? (int) $memberId : 0,
            $venue,
            $request->string('date')->toString(),
            $request->integer('hour'),
            $request->integer('party_size'),
        ));
    }
}
