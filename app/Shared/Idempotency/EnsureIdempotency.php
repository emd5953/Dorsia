<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Exceptions\ErrorEnvelope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Replays the first result for a (member, Idempotency-Key) pair, C§8.
 * Registered under the alias `idempotent`; must run after `auth:sanctum`.
 */
final class EnsureIdempotency
{
    private const MAX_ATTEMPTS = 3;

    public function handle(Request $request, Closure $next): Response
    {
        $key = IdempotencyKey::fromRequest($request);
        $method = strtoupper($request->method());
        $path = $request->getPathInfo();
        $hash = $this->requestHash($request, $method, $path);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $now = now();

            $inserted = IdempotencyRecord::query()->insertOrIgnore([
                'member_id' => $key->memberId,
                'key' => $key->value,
                'method' => $method,
                'path' => $path,
                'request_hash' => $hash,
                'status' => 'processing',
                'locked_at' => $now,
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                return $this->execute($request, $next, $key);
            }

            $record = $this->find($key);

            if ($record === null) {
                // Deleted between our insert attempt and the read (5xx or prune): try again.
                continue;
            }

            if (! hash_equals($record->request_hash, $hash)) {
                throw new IdempotencyMismatchException;
            }

            if ($record->status === 'completed') {
                return $this->replay($record);
            }

            $staleAfter = (int) config('idempotency.stale_after_seconds');

            if ($record->locked_at->greaterThan($now->subSeconds($staleAfter))) {
                return $this->inProgress();
            }

            $taken = IdempotencyRecord::query()
                ->whereKey($record->id)
                ->where('status', 'processing')
                ->where('locked_at', $record->getRawOriginal('locked_at'))
                ->update(['locked_at' => $now]);

            if ($taken === 1) {
                return $this->execute($request, $next, $key);
            }

            return $this->inProgress();
        }

        return $this->inProgress();
    }

    private function execute(Request $request, Closure $next, IdempotencyKey $key): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            $this->forget($key);

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->forget($key);

            return $response;
        }

        $content = $response->getContent();
        $body = is_string($content) && json_validate($content) ? $content : null;

        IdempotencyRecord::query()
            ->where('member_id', $key->memberId)
            ->where('key', $key->value)
            ->update([
                'status' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => $body,
                'completed_at' => now(),
            ]);

        return $response;
    }

    private function find(IdempotencyKey $key): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where('member_id', $key->memberId)
            ->where('key', $key->value)
            ->first();
    }

    private function forget(IdempotencyKey $key): void
    {
        IdempotencyRecord::query()
            ->where('member_id', $key->memberId)
            ->where('key', $key->value)
            ->delete();
    }

    private function replay(IdempotencyRecord $record): Response
    {
        $status = (int) $record->getRawOriginal('response_status');
        $body = $record->getRawOriginal('response_body');

        $response = is_string($body)
            ? new JsonResponse($body, $status, json: true)
            : new Response('', $status);

        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    private function inProgress(): Response
    {
        $response = ErrorEnvelope::fromDomainError(new IdempotencyInProgressException);
        $response->headers->set('Retry-After', '1');

        return $response;
    }

    private function requestHash(Request $request, string $method, string $path): string
    {
        $body = $request->isJson() ? $request->json()->all() : $request->all();
        $canonical = $body === [] ? '{}' : json_encode(
            $this->canonicalize($body),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $method."\n".$path."\n".$canonical);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonicalize(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
