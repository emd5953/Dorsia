<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Data\GatewayResult;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Shared\Money\Money;

interface PaymentGateway
{
    /**
     * Authorized | Declined.
     *
     * @throws GatewayTimeoutException outcome unknown
     */
    public function authorize(AuthorizeRequest $request): GatewayResult;

    /**
     * Captured | Failed.
     *
     * @throws GatewayTimeoutException
     */
    public function capture(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult;

    /**
     * Refunded | Failed (voids an uncaptured authorization).
     *
     * @throws GatewayTimeoutException
     */
    public function refund(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult;

    /**
     * PaidOut | Failed.
     *
     * @throws GatewayTimeoutException
     */
    public function payout(string $destination, Money $amount, string $idempotencyKey): GatewayResult;
}
