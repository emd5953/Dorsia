<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Modules\Payments\Models\PaymentWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentWebhookEvent>
 */
final class PaymentWebhookEventFactory extends Factory
{
    protected $model = PaymentWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = 'evt_'.Str::ulid();

        return [
            'provider' => 'fake',
            'event_id' => $eventId,
            'type' => 'payment.authorized',
            'provider_ref' => null,
            'payload' => ['id' => $eventId, 'type' => 'payment.authorized'],
            'processed_at' => null,
        ];
    }
}
