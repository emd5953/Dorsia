<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Spatie\Fork\Fork;
use Throwable;

final class Concurrency
{
    /** Per-child limit; a child still running after this is killed by SIGALRM and reported. */
    public static int $childTimeoutSeconds = 30;

    /**
     * Test seam for this helper's own tests: called in each child with its index, before the
     * child announces itself at the barrier.
     *
     * @var (Closure(int): void)|null
     */
    public static ?Closure $beforeBarrier = null;

    /**
     * Runs each closure in its own forked process after DB::purge()/reconnect and Redis purge.
     * Closures must return serializable values. Returns results in order.
     *
     * Every child connects, announces itself, then waits until all siblings have arrived, so
     * they really collide. A child that fails before the barrier still announces itself, so
     * siblings are never stranded; its real error is what run() throws.
     *
     * @param  array<int, Closure>  $closures
     * @return array<int, mixed>
     */
    public static function run(array $closures): array
    {
        // Children must open their own connections; never share a socket with the parent.
        self::purgeConnections();

        $closures = array_values($closures);
        $count = count($closures);
        $timeout = self::$childTimeoutSeconds;
        $barrier = sys_get_temp_dir().'/dorsia-barrier-'.bin2hex(random_bytes(6));
        mkdir($barrier);

        $wrapped = [];

        foreach ($closures as $index => $closure) {
            $wrapped[] = function () use ($closure, $index, $count, $barrier, $timeout): array {
                pcntl_signal(SIGALRM, SIG_DFL);
                pcntl_alarm($timeout);

                file_put_contents("{$barrier}/pid-{$index}", (string) getmypid());

                $failure = null;

                try {
                    if (self::$beforeBarrier !== null) {
                        (self::$beforeBarrier)($index);
                    }

                    DB::connection()->getPdo();
                } catch (Throwable $e) {
                    $failure = $e;
                } finally {
                    touch("{$barrier}/arrived-{$index}");
                }

                while (count(glob("{$barrier}/arrived-*") ?: []) < $count) {
                    usleep(500);
                }

                if ($failure === null) {
                    try {
                        return ['ok' => true, 'value' => $closure()];
                    } catch (Throwable $e) {
                        $failure = $e;
                    }
                }

                return ['ok' => false, 'error' => $failure::class.': '.$failure->getMessage()];
            };
        }

        $crashed = true;

        try {
            $results = Fork::new()->run(...$wrapped);
            $crashed = false;

            // Fork collects results in completion order, keyed by closure index.
            ksort($results);

            $values = [];
            $missing = [];

            foreach ($results as $index => $result) {
                if (! is_array($result) || ! array_key_exists('ok', $result)) {
                    $missing[] = $index;

                    continue;
                }

                // A real error beats a timeout report from another child.
                if ($result['ok'] !== true) {
                    throw new RuntimeException('Concurrency child '.$index.' failed: '.$result['error']);
                }

                $values[] = $result['value'];
            }

            if ($missing !== []) {
                throw new RuntimeException('Concurrency child '.implode(', ', $missing).' produced no result (timed out after '.$timeout.'s or was killed).');
            }

            return $values;
        } finally {
            if ($crashed) {
                // Fork aborted while siblings may still run: terminate and reap them before
                // the barrier directory disappears under them.
                foreach (glob("{$barrier}/pid-*") ?: [] as $file) {
                    $pid = (int) file_get_contents($file);

                    if ($pid > 0) {
                        posix_kill($pid, SIGKILL);
                        pcntl_waitpid($pid, $status);
                    }
                }
            }

            foreach (glob("{$barrier}/*") ?: [] as $file) {
                unlink($file);
            }

            rmdir($barrier);
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
