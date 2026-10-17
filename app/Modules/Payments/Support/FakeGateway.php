<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Data\GatewayResult;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Modules\Payments\Jobs\DeliverFakeWebhook;
use App\Shared\Money\Money;
use AssertionError;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;

/**
 * Provider-side simulation (C§11). State lives in Redis so every worker process shares it.
 * The programmatic knobs are per-instance; the instance is a container singleton.
 */
final class FakeGateway implements PaymentGateway
{
    private const IDEM = 'fakegw:idem:';

    private const CHARGES = 'fakegw:charges';

    private const PAYOUTS = 'fakegw:payouts';

    private const CALLS = 'fakegw:authorize_calls';

    private const CAPTURE_FAIL = 'fakegw:capture_fail';

    private const DUPLICATE = 'fakegw:duplicate_webhooks';

    private ?string $declineNext = null;

    private bool $timeoutNext = false;

    private bool $failNextCapture = false;

    private ?bool $emitWebhooks = null;

    private ?bool $duplicateWebhooks = null;

    private ?int $latencyMs = null;

    public function __construct(private readonly RedisFactory $redis) {}

    public function declineNext(string $code = 'card_declined'): void
    {
        $this->declineNext = $code;
    }

    public function timeoutNext(): void
    {
        $this->timeoutNext = true;
    }

    public function failNextCapture(): void
    {
        $this->failNextCapture = true;
    }

    public function emitWebhooks(bool $on = true): void
    {
        $this->emitWebhooks = $on;
    }

    public function duplicateWebhooks(bool $on = true): void
    {
        $this->duplicateWebhooks = $on;
    }

    public function latency(int $ms): void
    {
        $this->latencyMs = $ms;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function charges(): array
    {
        $charges = [];

        foreach ($this->connection()->hgetall(self::CHARGES) as $ref => $json) {
            /** @var array<string, mixed> $charge */
            $charge = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
            $charges[] = ['provider_ref' => (string) $ref] + $charge;
        }

        return $charges;
    }

    public function authorizeCalls(): int
    {
        return (int) $this->connection()->get(self::CALLS);
    }

    /**
     * @throws AssertionError unless exactly one charge exists for the key
     */
    public function assertChargedOnce(string $idempotencyKey): void
    {
        $count = count(array_filter(
            $this->charges(),
            fn (array $charge): bool => $charge['idempotency_key'] === $idempotencyKey,
        ));

        if ($count !== 1) {
            throw new AssertionError("Expected exactly one charge for [{$idempotencyKey}], found {$count}.");
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{id: string, type: string, created: int, data: array{provider_ref: string, amount_cents: int, currency: string, metadata: array<string, mixed>}}
     */
    public static function webhookPayload(string $type, string $providerRef, Money $amount, int $paymentId, ?string $eventId = null, array $metadata = []): array
    {
        return [
            'id' => $eventId ?? 'evt_'.Str::ulid(),
            'type' => $type,
            'created' => time(),
            'data' => [
                'provider_ref' => $providerRef,
                'amount_cents' => $amount->cents,
                'currency' => $amount->currency->value,
                'metadata' => ['payment_id' => $paymentId] + $metadata,
            ],
        ];
    }

    public static function sign(string $rawBody): string
    {
        return WebhookSignature::sign($rawBody);
    }

    public function authorize(AuthorizeRequest $request): GatewayResult
    {
        $this->connection()->incr(self::CALLS);
        $this->pause();

        $existing = $this->existingRef($request->idempotencyKey);

        if ($existing !== null) {
            return new GatewayResult(GatewayStatus::Authorized, $existing);
        }

        if (! $request->amount->isPositive()) {
            return new GatewayResult(GatewayStatus::Declined, null, 'invalid_amount');
        }

        if ($this->declineNext !== null) {
            $code = $this->declineNext;
            $this->declineNext = null;

            return new GatewayResult(GatewayStatus::Declined, null, $code);
        }

        $timeout = $this->timeoutNext;
        $this->timeoutNext = false;
        $token = $request->paymentMethodToken;

        if (! $timeout && $token === 'tok_timeout') {
            $timeout = true;
        }

        if (! $timeout) {
            $declined = match ($token) {
                'tok_ok', 'tok_capture_fail', 'tok_dup_webhook' => null,
                'tok_decline' => 'card_declined',
                'tok_insufficient_funds' => 'insufficient_funds',
                default => 'invalid_token',
            };

            if ($declined !== null) {
                return new GatewayResult(GatewayStatus::Declined, null, $declined);
            }
        }

        [$ref, $created] = $this->createCharge($request);

        if ($created) {
            if ($token === 'tok_capture_fail') {
                $this->connection()->sadd(self::CAPTURE_FAIL, $ref);
            }

            if ($token === 'tok_dup_webhook') {
                $this->connection()->sadd(self::DUPLICATE, $ref);
            }

            $this->emit('payment.authorized', $ref);
        }

        if ($timeout) {
            throw new GatewayTimeoutException('Fake gateway timed out after recording the charge.');
        }

        return new GatewayResult(GatewayStatus::Authorized, $ref);
    }

    public function capture(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
    {
        $this->pause();

        if ($this->existingRef($idempotencyKey) !== null) {
            return new GatewayResult(GatewayStatus::Captured, $providerRef);
        }

        $charge = $this->charge($providerRef);

        if ($charge === null || $charge['status'] !== 'authorized') {
            return new GatewayResult(GatewayStatus::Failed, $providerRef, 'invalid_state');
        }

        if (! $this->amountFits($amount, $charge)) {
            return new GatewayResult(GatewayStatus::Failed, $providerRef, 'invalid_amount');
        }

        $forced = $this->failNextCapture;
        $this->failNextCapture = false;

        if ($forced || $this->connection()->sismember(self::CAPTURE_FAIL, $providerRef)) {
            return new GatewayResult(GatewayStatus::Failed, $providerRef, 'capture_failed');
        }

        $this->transition($providerRef, $charge, 'captured', $idempotencyKey);
        $this->emit('payment.captured', $providerRef);

        return new GatewayResult(GatewayStatus::Captured, $providerRef);
    }

    public function refund(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
    {
        $this->pause();

        if ($this->existingRef($idempotencyKey) !== null) {
            return new GatewayResult(GatewayStatus::Refunded, $providerRef);
        }

        $charge = $this->charge($providerRef);

        if ($charge === null || ! in_array($charge['status'], ['authorized', 'captured'], true)) {
            return new GatewayResult(GatewayStatus::Failed, $providerRef, 'invalid_state');
        }

        if (! $this->amountFits($amount, $charge)) {
            return new GatewayResult(GatewayStatus::Failed, $providerRef, 'invalid_amount');
        }

        $this->transition($providerRef, $charge, 'refunded', $idempotencyKey);
        $this->emit('payment.refunded', $providerRef);

        return new GatewayResult(GatewayStatus::Refunded, $providerRef);
    }

    public function payout(string $destination, Money $amount, string $idempotencyKey): GatewayResult
    {
        $this->pause();

        $existing = $this->existingRef($idempotencyKey);

        if ($existing !== null) {
            return new GatewayResult(GatewayStatus::PaidOut, $existing);
        }

        if ($destination === '' || ! $amount->isPositive()) {
            return new GatewayResult(GatewayStatus::Failed, null, 'invalid_payout');
        }

        $ref = 'fake_po_'.Str::ulid();

        if (! $this->claim($idempotencyKey, $ref)) {
            return new GatewayResult(GatewayStatus::PaidOut, $this->existingRef($idempotencyKey));
        }

        $this->connection()->hset(self::PAYOUTS, $ref, json_encode([
            'amount_cents' => $amount->cents,
            'currency' => $amount->currency->value,
            'destination' => $destination,
            'idempotency_key' => $idempotencyKey,
        ], JSON_THROW_ON_ERROR));

        return new GatewayResult(GatewayStatus::PaidOut, $ref);
    }

    /**
     * Records the charge as authorized provider-side unless the key already has one.
     *
     * @return array{string, bool} provider ref, whether this call created it
     */
    private function createCharge(AuthorizeRequest $request): array
    {
        $ref = 'fake_ch_'.Str::ulid();

        if (! $this->claim($request->idempotencyKey, $ref)) {
            return [(string) $this->existingRef($request->idempotencyKey), false];
        }

        $this->connection()->hset(self::CHARGES, $ref, json_encode([
            'amount_cents' => $request->amount->cents,
            'currency' => $request->amount->currency->value,
            'status' => 'authorized',
            'idempotency_key' => $request->idempotencyKey,
            'payment_id' => $request->metadata['payment_id'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return [$ref, true];
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function transition(string $ref, array $charge, string $status, string $idempotencyKey): void
    {
        $charge['status'] = $status;

        $this->connection()->hset(self::CHARGES, $ref, json_encode($charge, JSON_THROW_ON_ERROR));
        $this->claim($idempotencyKey, $ref);
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function amountFits(Money $amount, array $charge): bool
    {
        return $amount->isPositive()
            && $amount->currency->value === $charge['currency']
            && $amount->cents <= (int) $charge['amount_cents'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function charge(string $ref): ?array
    {
        $json = $this->connection()->hget(self::CHARGES, $ref);

        if (! is_string($json)) {
            return null;
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function claim(string $idempotencyKey, string $ref): bool
    {
        return (bool) $this->connection()->setnx(self::IDEM.$idempotencyKey, $ref);
    }

    private function existingRef(string $idempotencyKey): ?string
    {
        $ref = $this->connection()->get(self::IDEM.$idempotencyKey);

        return is_string($ref) ? $ref : null;
    }

    /** One webhook per successful call when emission is on (or the charge asked for duplicates), twice when duplicating. */
    private function emit(string $type, string $ref): void
    {
        $emit = $this->emitWebhooks ?? (bool) config('payments.fake.emit_webhooks');
        $chargeDuplicate = (bool) $this->connection()->sismember(self::DUPLICATE, $ref);
        $duplicate = $chargeDuplicate || ($emit && ($this->duplicateWebhooks ?? (bool) config('payments.fake.duplicate_webhooks')));

        if (! $emit && ! $chargeDuplicate) {
            return;
        }

        $charge = $this->charge($ref);

        if ($charge === null) {
            return;
        }

        $payload = self::webhookPayload(
            $type,
            $ref,
            Money::of((int) $charge['amount_cents'], (string) $charge['currency']),
            (int) $charge['payment_id'],
        );

        for ($copy = 0; $copy < ($duplicate ? 2 : 1); $copy++) {
            DeliverFakeWebhook::dispatch($payload)->afterCommit();
        }
    }

    private function pause(): void
    {
        $ms = $this->latencyMs ?? (int) config('payments.fake.latency_ms');

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function connection(): Connection
    {
        return $this->redis->connection();
    }
}
