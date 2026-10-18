<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Support\HandleWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FakeWebhookController
{
    public function __invoke(Request $request, HandleWebhook $handler): JsonResponse
    {
        $duplicate = $handler->handle($request->getContent(), $request->header('Fake-Signature'));

        return response()->json(['data' => ['duplicate' => $duplicate]]);
    }
}
