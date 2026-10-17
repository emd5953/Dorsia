<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * `Fake-Signature` header value: "sha256=" . hex HMAC-SHA256 of the raw body with the webhook secret (C§11).
 */
final class WebhookSignature
{
    public static function sign(string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, self::secret());
    }

    /** Constant-time. An unset secret never verifies. */
    public static function verify(string $rawBody, ?string $signature): bool
    {
        if (self::secret() === '' || $signature === null) {
            return false;
        }

        return hash_equals(self::sign($rawBody), $signature);
    }

    private static function secret(): string
    {
        $secret = config('payments.fake.webhook_secret');

        return is_string($secret) ? $secret : '';
    }
}
