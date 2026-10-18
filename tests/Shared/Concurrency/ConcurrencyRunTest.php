<?php

declare(strict_types=1);

use Spatie\Fork\Exceptions\CouldNotManageTask;
use Tests\Support\Concurrency;

afterEach(function (): void {
    Concurrency::$childTimeoutSeconds = 30;
    Concurrency::$beforeBarrier = null;
});

it('raises with the real error of a throwing child', function (): void {
    $closures = [];

    for ($i = 0; $i < 4; $i++) {
        $closures[] = function () use ($i): int {
            if ($i === 2) {
                throw new DomainException('real-child-error');
            }

            return $i;
        };
    }

    expect(fn () => Concurrency::run($closures))
        ->toThrow(RuntimeException::class, 'Concurrency child 2 failed: DomainException: real-child-error');
});

it('kills a hung child and reports the timeout', function (): void {
    Concurrency::$childTimeoutSeconds = 2;

    $closures = [
        fn (): int => 1,
        function (): int {
            sleep(60);

            return 2;
        },
    ];

    $started = microtime(true);

    expect(fn () => Concurrency::run($closures))
        ->toThrow(RuntimeException::class, 'Concurrency child 1 produced no result (timed out after 2s');

    expect(microtime(true) - $started)->toBeLessThan(10.0);
});

it('releases the barrier when a child fails before reaching it and reports the real error', function (): void {
    Concurrency::$beforeBarrier = function (int $index): void {
        if ($index === 1) {
            throw new LogicException('failed-before-barrier');
        }
    };

    $started = microtime(true);

    expect(fn () => Concurrency::run([fn (): int => 0, fn (): int => 1, fn (): int => 2]))
        ->toThrow(RuntimeException::class, 'Concurrency child 1 failed: LogicException: failed-before-barrier');

    // Siblings were released at once, not stranded until the 30 s timeout.
    expect(microtime(true) - $started)->toBeLessThan(10.0);
});

it('starts all children within a tight window however unevenly they get there', function (): void {
    // Child i is delayed i * 20 ms before the barrier: without it the spread would be ~380 ms.
    Concurrency::$beforeBarrier = fn (int $index) => usleep($index * 20_000);

    $closures = [];

    for ($i = 0; $i < 20; $i++) {
        $closures[] = fn (): float => microtime(true);
    }

    $starts = Concurrency::run($closures);

    expect(max($starts) - min($starts))->toBeLessThan(0.1);
});

it('terminates siblings of a crashed child before cleaning up', function (): void {
    $marker = sys_get_temp_dir().'/dorsia-sibling-'.bin2hex(random_bytes(4));

    $closures = [
        function (): int {
            usleep(200_000);
            exit(3); // hard crash, non-zero status
        },
        function () use ($marker): int {
            usleep(1_500_000);
            touch($marker); // only reached if the sibling was left running

            return 1;
        },
    ];

    try {
        expect(fn () => Concurrency::run($closures))->toThrow(CouldNotManageTask::class);

        sleep(2);

        expect(file_exists($marker))->toBeFalse()
            ->and(glob(sys_get_temp_dir().'/dorsia-barrier-*'))->toBe([]);
    } finally {
        @unlink($marker);
    }
});
