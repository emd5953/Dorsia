<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Exceptions\ErrorEnvelope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            $token = (string) Str::uuid();

            $inserted = IdempotencyRecord::query()->insertOrIgnore([
                'member_id' => $key->memberId,
                'key' => $key->value,
                'method' => $method,
                'path' => $path,
                'request_hash' => $hash,
                'status' => 'processing',
                'lock_token' => $token,
                'locked_at' => $now,
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                return $this->execute($request, $next, $key, $token);
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
                ->where('member_id', $key->memberId)
                ->where('key', $key->value)
                ->where('status', 'processing')
                ->where('lock_token', $record->lock_token)
                ->update(['locked_at' => $now, 'lock_token' => $token]);

            if ($taken === 1) {
                return $this->execute($request, $next, $key, $token);
            }

            // Someone else took over or completed: re-read and apply the rules again.
        }

        return $this->inProgress();
    }

    private function execute(Request $request, Closure $next, IdempotencyKey $key, string $token): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            $this->owned($key, $token)->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            if ($this->owned($key, $token)->delete() === 0) {
                $this->lostOwnership($key);
            }

            return $response;
        }

        $content = $response->getContent();

        try {
            $stored = $this->owned($key, $token)->update([
                'status' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => is_string($content) ? $content : null,
                'response_content_type' => $response->headers->get('Content-Type'),
                'completed_at' => now(),
            ]);
        } catch (QueryException $e) {
            // The handler already committed its work; never turn that into a 500.
            // Drop the row so a retry re-executes instead of waiting for the stale window.
            Log::warning('Idempotency completion failed; response not stored.', [
                'member_id' => $key->memberId,
                'key' => $key->value,
                'error' => $e->getMessage(),
            ]);

            $this->owned($key, $token)->delete();

            return $response;
        }

        if ($stored === 0) {
            $this->lostOwnership($key);
        }

        return $response;
    }

    /**
     * @return Builder<IdempotencyRecord>
     */
    private function owned(IdempotencyKey $key, string $token): Builder
    {
        return IdempotencyRecord::query()
            ->where('member_id', $key->memberId)
            ->where('key', $key->value)
            ->where('lock_token', $token)
            ->where('status', 'processing');
    }

    private function lostOwnership(IdempotencyKey $key): void
    {
        Log::warning('Idempotency lock lost to a takeover; response not stored.', [
            'member_id' => $key->memberId,
            'key' => $key->value,
        ]);
    }

    private function find(IdempotencyKey $key): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where('member_id', $key->memberId)
            ->where('key', $key->value)
            ->first();
    }

    private function replay(IdempotencyRecord $record): Response
    {
        $body = $record->getRawOriginal('response_body');
        $contentType = $record->getRawOriginal('response_content_type');

        $response = new Response(is_string($body) ? $body : '', (int) $record->getRawOriginal('response_status'));

        if (is_string($contentType)) {
            $response->headers->set('Content-Type', $contentType);
        } else {
            $response->headers->remove('Content-Type');
        }

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
        $content = $request->getContent();
        $body = $request->isJson() && $content !== '' ? json_decode($content) : null;

        // Objects stay objects and lists stay lists, so {"x":{}} and {"x":[]} hash differently.
        $body ??= (object) $request->all();

        $canonical = json_encode(
            $this->canonicalize($body),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        if ($canonical === '[]') {
            $canonical = '{}';
        }

        return hash('sha256', $method."\n".$path."\n".$canonical);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        if (is_object($value)) {
            $properties = array_map($this->canonicalize(...), get_object_vars($value));
            ksort($properties, SORT_STRING);

            return (object) $properties;
        }

        return $value;
    }
}
