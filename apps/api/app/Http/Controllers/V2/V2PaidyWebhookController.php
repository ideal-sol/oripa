<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Services\V2PaidyWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class V2PaidyWebhookController
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $configured = config('v2_paidy.webhook_path_token');
        if (! is_string($configured) || strlen($configured) < 32 || strlen($configured) > 256 || ! hash_equals($configured, $token)) {
            return response()->json(['code' => 'NOT_FOUND'], 404, ['Cache-Control' => 'no-store']);
        }
        try {
            app(V2PaidyWebhookService::class)->receive($request->json()->all());
            return response()->json(['accepted' => true], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return response()->json(['code' => 'PAYMENT_WEBHOOK_UNAVAILABLE'], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
