<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Services\V2UnivapayWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class V2UnivapayWebhookController
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $path = config('v2_univapay.webhook_path_token');
        if (! is_string($path) || strlen($path) < 32 || ! hash_equals($path, $token)) return $this->response(404);
        $expected = config('v2_univapay.webhook_auth_token');
        $actual = preg_replace('/\ABearer\s+/i', '', trim((string) $request->header('Authorization')));
        if (! is_string($expected) || strlen($expected) < 32 || ! hash_equals($expected, trim($actual))) return $this->response(401);
        $ips = config('v2_univapay.notify_allowed_ips', '');
        if (! is_string($ips) || ($ips !== '' && ! in_array($request->ip(), array_map('trim', explode(',', $ips)), true))) return $this->response(401);
        try {
            // Database Outbox only: QUEUE_CONNECTION=sync cannot execute provider HTTP here.
            $body = strlen($request->getContent()) <= 131072 ? json_decode($request->getContent(), true) : null;
            app(V2UnivapayWebhookService::class)->receive(is_array($body) ? $body : []);
        } catch (\Throwable) {
            // Durable acceptance failed. 503 asks the provider to retry without the stop-on-500 policy.
            return $this->response(503);
        }

        return $this->response(200);
    }

    private function response(int $status): JsonResponse
    {
        return response()->json(['accepted' => $status === 200], $status, ['Cache-Control' => 'no-store']);
    }
}
