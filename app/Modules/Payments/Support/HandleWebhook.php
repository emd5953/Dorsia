<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Jobs\RefundPayment;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Verifies and applies one provider webhook (C§11): idempotent on event id, forward-only transitions.
 */
final class HandleWebhook
{
    private const PROVIDER = 'fake';

    /**
     * @return bool true when the event id was seen before (nothing applied)
     *
     * @throws InvalidWebhookSignatureException
     * @throws ValidationException
     */
    public function handle(string $rawBody, ?string $signature): bool
    {
        if (! WebhookSignature::verify($rawBody, $signature)) {
            throw new InvalidWebhookSignatureException;
        }

        $event = $this->validated($rawBody);

        return DB::transaction(function () use ($event): bool {
            $inserted = PaymentWebhookEvent::query()->insertOrIgnore([
                'provider' => self::PROVIDER,
                'event_id' => $event['id'],
                'type' => $event['type'],
                'provider_ref' => $event['provider_ref'],
                'payload' => json_encode($event['payload'], JSON_THROW_ON_ERROR),
                'created_at' => now()->utc()->format('Y-m-d H:i:s.uP'),
            ]);

            if ($inserted === 0) {
                return true;
            }

            $payment = $this->lockPayment($event['provider_ref'], $event['payment_id']);

            if ($payment === null) {
                Log::warning('Webhook for an unknown payment.', ['event_id' => $event['id'], 'provider_ref' => $event['provider_ref']]);

                return false;
            }

            if ($this->apply($payment, $event)) {
                PaymentWebhookEvent::query()
                    ->where('provider', self::PROVIDER)
                    ->where('event_id', $event['id'])
                    ->update(['processed_at' => now()->utc()->format('Y-m-d H:i:s.uP')]);
            }

            return false;
        });
    }

    /**
     * @return array{id: string, type: string, provider_ref: string|null, payment_id: int|null, decline_code: string|null, amount_cents: int|null, currency: string|null, payload: array<mixed>}
     */
    private function validated(string $rawBody): array
    {
        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = null;
        }

        $data = Validator::make(is_array($payload) ? $payload : [], [
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:64'],
            'data' => ['required', 'array'],
            'data.provider_ref' => ['nullable', 'string', 'max:255'],
            'data.amount_cents' => ['nullable', 'integer'],
            'data.currency' => ['nullable', 'string', 'size:3'],
            'data.decline_code' => ['nullable', 'string', 'max:64'],
            'data.metadata' => ['nullable', 'array'],
            'data.metadata.payment_id' => ['nullable', 'integer'],
        ])->validate();

        /** @var array<mixed> $payload */
        return [
            'id' => $data['id'],
            'type' => $data['type'],
            'provider_ref' => $data['data']['provider_ref'] ?? null,
            'payment_id' => isset($data['data']['metadata']['payment_id']) ? (int) $data['data']['metadata']['payment_id'] : null,
            'decline_code' => $data['data']['decline_code'] ?? null,
            'amount_cents' => isset($data['data']['amount_cents']) ? (int) $data['data']['amount_cents'] : null,
            'currency' => isset($data['data']['currency']) ? (string) $data['data']['currency'] : null,
            'payload' => $payload,
        ];
    }

    private function lockPayment(?string $providerRef, ?int $paymentId): ?Payment
    {
        if ($providerRef !== null) {
            $payment = Payment::query()->where('provider_ref', $providerRef)->lockForUpdate()->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        return $paymentId === null ? null : Payment::query()->whereKey($paymentId)->lockForUpdate()->first();
    }

    /**
     * @param  array{id: string, type: string, provider_ref: string|null, payment_id: int|null, decline_code: string|null, amount_cents: int|null, currency: string|null, payload: array<mixed>}  $event
     */
    private function apply(Payment $payment, array $event): bool
    {
        $ref = $event['provider_ref'];

        if ($event['amount_cents'] !== $payment->amount_cents || $event['currency'] !== $payment->currency) {
            Log::critical('Webhook amount or currency differs from the payment.', [
                'payment_id' => $payment->id,
                'event_id' => $event['id'],
                'amount_cents' => $event['amount_cents'],
                'currency' => $event['currency'],
            ]);

            return false;
        }

        if (in_array($event['type'], ['payment.authorized', 'payment.captured', 'payment.refunded'], true) && $ref === null) {
            Log::warning('Webhook requiring a provider_ref was ignored.', ['payment_id' => $payment->id, 'event_id' => $event['id'], 'type' => $event['type']]);

            return true;
        }

        if ($ref !== null && $payment->provider_ref !== null && $payment->provider_ref !== $ref) {
            Log::warning('Webhook provider_ref differs from the payment\'s charge; ignored.', ['payment_id' => $payment->id, 'event_id' => $event['id']]);

            return true;
        }

        $status = $payment->status;
        $values = match ([$event['type'], $status]) {
            ['payment.authorized', PaymentStatus::Pending] => ['status' => PaymentStatus::Authorized->value, 'provider_ref' => $ref],
            ['payment.authorized', PaymentStatus::TimedOut] => $ref === null ? [] : ['provider_ref' => $ref],
            ['payment.captured', PaymentStatus::Authorized] => ['status' => PaymentStatus::Captured->value],
            ['payment.captured', PaymentStatus::Pending] => ['status' => PaymentStatus::Captured->value, 'provider_ref' => $ref],
            ['payment.captured', PaymentStatus::TimedOut] => ['provider_ref' => $ref],
            ['payment.refunded', PaymentStatus::Authorized],
            ['payment.refunded', PaymentStatus::Captured],
            ['payment.refunded', PaymentStatus::TimedOut] => ['status' => PaymentStatus::Refunded->value],
            ['payment.failed', PaymentStatus::Pending] => ['status' => PaymentStatus::Failed->value, 'decline_code' => $event['decline_code']],
            default => [],
        };

        if ($values !== []) {
            $payment->update($values);
        }

        if (in_array($event['type'], ['payment.authorized', 'payment.captured'], true)
            && $status === PaymentStatus::TimedOut && $payment->provider_ref !== null) {
            // Orphaned authorization: the caller was told "timeout", so the money must be released.
            RefundPayment::dispatch($payment->id)->afterCommit();
        }

        return true;
    }
}
