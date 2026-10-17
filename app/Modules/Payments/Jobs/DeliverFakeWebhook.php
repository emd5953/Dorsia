<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Support\WebhookSignature;
use App\Shared\Support\Transactions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** POSTs one signed webhook event to this app's own endpoint (C§11). */
final class DeliverFakeWebhook implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 15, 60];
    }

    /**
     * @throws RequestException when the endpoint answers non-2xx, so the queue retries
     */
    public function handle(): void
    {
        Transactions::assertNone();

        $body = json_encode($this->payload, JSON_THROW_ON_ERROR);

        Http::withBody($body, 'application/json')
            ->withHeaders(['Fake-Signature' => WebhookSignature::sign($body)])
            ->post(rtrim((string) config('app.url'), '/').'/api/webhooks/payments/fake')
            ->throw();
    }
}
