<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders any throwable as the C§8 error envelope using only C§9 codes.
 */
final class ErrorEnvelope
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function response(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }

    public static function fromDomainError(DomainError $e): JsonResponse
    {
        return self::response($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->details());
    }

    public static function fromThrowable(Throwable $e): JsonResponse
    {
        if ($e instanceof DomainError) {
            return self::fromDomainError($e);
        }

        if ($e instanceof ValidationException) {
            return self::response('validation_failed', 'The given data was invalid.', 422, ['errors' => $e->errors()]);
        }

        if ($e instanceof AuthenticationException) {
            return self::response('unauthenticated', 'Unauthenticated.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::response('forbidden', 'Forbidden.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::response('not_found', 'Not found.', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            return match ($e->getStatusCode()) {
                401 => self::response('unauthenticated', 'Unauthenticated.', 401),
                403 => self::response('forbidden', 'Forbidden.', 403),
                404 => self::response('not_found', 'Not found.', 404),
                405 => self::response('method_not_allowed', 'Method not allowed.', 405),
                429 => self::response('rate_limited', 'Too many requests.', 429),
                default => self::response('server_error', 'Server error.', $e->getStatusCode()),
            };
        }

        return self::response('server_error', 'Server error.', 500);
    }
}
