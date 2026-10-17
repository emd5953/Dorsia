<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Modules\Reservations\Actions\GetHoldView;
use App\Modules\Reservations\Actions\PlaceHold;
use App\Modules\Reservations\Http\Requests\PlaceHoldRequest;
use App\Modules\Reservations\Http\Resources\HoldResource;
use App\Shared\Idempotency\IdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HoldController
{
    public function store(PlaceHoldRequest $request, PlaceHold $placeHold): JsonResponse
    {
        $view = $placeHold->handle(
            $this->memberId($request),
            $request->integer('slot_id'),
            $request->integer('party_size'),
            IdempotencyKey::fromRequest($request),
        );

        return (new HoldResource($view))->response()->setStatusCode(201);
    }

    public function show(int $hold, Request $request, GetHoldView $getHoldView): HoldResource
    {
        return new HoldResource($getHoldView->handle($this->memberId($request), $hold));
    }

    private function memberId(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : 0;
    }
}
