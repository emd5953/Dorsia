<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Spatie\Fork\Fork;

final class Concurrency
{
    /**
     * Runs each closure in its own forked process after DB::purge()/reconnect and Redis purge.
     * Closures must return serializable values. Returns results in order.
     *
     * @param  array<int, Closure>  $closures
     * @return array<int, mixed>
     */
    public static function run(array $closures): array
    {
        // Children must open their own connections; never share a socket with the parent.
        self::purgeConnections();

        // Every child connects, then waits for a common start instant so they really collide.
        $startAt = microtime(true) + 0.75;

        $closures = array_map(
            fn (Closure $closure): Closure => function () use ($closure, $startAt): mixed {
                DB::connection()->getPdo();

                $wait = $startAt - microtime(true);

                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }

                return $closure();
            },
            $closures,
        );

        try {
            $results = Fork::new()->run(...array_values($closures));

            // Fork collects results in completion order, keyed by closure index.
            ksort($results);

            return array_values($results);
        } finally {
            self::purgeConnections();
        }
    }

    /**
     * Fires $n requests in parallel through the in-process HTTP kernel, each with a real bearer token.
     * Header names in the result are lower-cased.
     *
     * @param  Closure(int): array{method: string, uri: string, token: string, body?: array<mixed>, headers?: array<string, string>}  $make
     * @return list<array{status: int, json: array<mixed>, headers: array<string, string>}>
     */
    public static function requests(int $n, Closure $make): array
    {
        // Build every spec in the parent so tokens and fixtures exist before forking.
        $specs = [];

        for ($i = 0; $i < $n; $i++) {
            $specs[] = $make($i);
        }

        $closures = array_map(
            fn (array $spec): Closure => fn (): array => self::dispatch($spec),
            $specs,
        );

        /** @var list<array{status: int, json: array<mixed>, headers: array<string, string>}> $results */
        $results = self::run($closures);

        return $results;
    }

    /**
     * Deletes every row of every table except migrations (used after concurrency tests).
     */
    public static function truncateAll(): void
    {
        $tables = DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"
        );

        if ($tables === []) {
            return;
        }

        $names = implode(', ', array_map(
            fn (object $row): string => '"'.str_replace('"', '""', (string) $row->tablename).'"',
            $tables,
        ));

        DB::unprepared("TRUNCATE TABLE {$names} RESTART IDENTITY CASCADE");
    }

    /**
     * @param  array{method: string, uri: string, token: string, body?: array<mixed>, headers?: array<string, string>}  $spec
     * @return array{status: int, json: array<mixed>, headers: array<string, string>}
     */
    private static function dispatch(array $spec): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$spec['token'],
        ];

        foreach ($spec['headers'] ?? [] as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $body = $spec['body'] ?? null;

        $request = Request::create(
            $spec['uri'],
            $spec['method'],
            [],
            [],
            [],
            $server,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        app('auth')->forgetGuards();

        $response = app(Kernel::class)->handle($request);

        $decoded = json_decode((string) $response->getContent(), true);

        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            $headers[(string) $name] = (string) (is_array($values) ? ($values[0] ?? '') : $values);
        }

        return [
            'status' => $response->getStatusCode(),
            'json' => is_array($decoded) ? $decoded : [],
            'headers' => $headers,
        ];
    }

    private static function purgeConnections(): void
    {
        DB::purge();
        Redis::purge('default');
        Redis::purge('cache');
    }
}
