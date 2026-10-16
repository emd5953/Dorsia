<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final readonly class IdempotencyKey
{
    /**
     * @throws IdempotencyKeyInvalidException unless $value matches /^[A-Za-z0-9._:-]{1,200}$/
     */
    public function __construct(public int $memberId, public string $value)
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $value) !== 1) {
            throw new IdempotencyKeyInvalidException;
        }
    }

    /**
     * Reads the Idempotency-Key header and the authenticated member.
     *
     * @throws IdempotencyKeyRequiredException
     */
    public static function fromRequest(Request $request): self
    {
        $header = $request->header('Idempotency-Key');

        if (! is_string($header) || $header === '') {
            throw new IdempotencyKeyRequiredException;
        }

        $member = $request->user();

        if ($member === null) {
            throw new AuthenticationException;
        }

        $memberId = $member->getAuthIdentifier();

        return new self(is_numeric($memberId) ? (int) $memberId : 0, $header);
    }

    public function scoped(): string
    {
        return "{$this->memberId}:{$this->value}";
    }
}
