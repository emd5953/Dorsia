<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Modules\Reservations\Actions\CancelReservation;
use App\Modules\Reservations\Actions\ConfirmReservation;
use App\Modules\Reservations\Actions\GetReservation;
use App\Modules\Reservations\Actions\ListReservations;
use App\Modules\Reservations\Http\Requests\ConfirmReservationRequest;
use App\Modules\Reservations\Http\Resources\CancellationResource;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Shared\Idempotency\IdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ReservationController
{
    public function store(ConfirmReservationRequest $request, ConfirmReservation $confirm): JsonResponse
    {
        $cardToken = $request->input('card_token');

        $reservation = $confirm->handle(
            $this->memberId($request),
            $request->integer('hold_id'),
            is_string($cardToken) ? $cardToken : null,
            IdempotencyKey::fromRequest($request),
        );

        return (new ReservationResource($reservation))->response()->setStatusCode(201);
    }

    public function index(Request $request, ListReservations $list): AnonymousResourceCollection
    {
        return ReservationResource::collection($list->handle($this->memberId($request)));
    }

    public function show(int $reservation, Request $request, GetReservation $get): ReservationResource
    {
        return new ReservationResource($get->handle($this->memberId($request), $reservation));
    }

    public function cancel(int $reservation, Request $request, CancelReservation $cancel): CancellationResource
    {
        return new CancellationResource($cancel->handle($this->memberId($request), $reservation));
    }

    private function memberId(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : 0;
    }
}
