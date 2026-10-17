<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateBroadcastAuthRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $socketId = $request->input('socket_id');
        $channel = $request->input('channel_name');

        if (! is_string($socketId) || preg_match('/^\d+\.\d+$/D', $socketId) !== 1
            || ! is_string($channel)
            || preg_match('/^(?:private-member\.[1-9]\d*|venue\.[1-9]\d*\.slots|drop\.[1-9]\d*)$/D', $channel) !== 1) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
