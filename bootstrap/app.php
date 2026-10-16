<?php

declare(strict_types=1);

use App\Shared\Exceptions\DomainError;
use App\Shared\Exceptions\ErrorEnvelope;
use App\Shared\Idempotency\EnsureIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotent' => EnsureIdempotency::class,
        ]);

        // API only: an unauthenticated request is a 401 envelope, never a redirect.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e): bool => true);

        $exceptions->dontReport(DomainError::class);

        // Every throwable becomes a C§8 envelope with a C§9 code, never a stack trace.
        $exceptions->render(fn (Throwable $e, Request $request) => ErrorEnvelope::fromThrowable($e));
    })->create();
