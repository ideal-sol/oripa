<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Services\V2TechcoinConfiguration;
use App\Domain\Payment\V2\Services\V2TechcoinKickbackService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class V2TechcoinWebhookController
{
    public function __invoke(Request $request, string $token): Response
    {
        $configuration = app(V2TechcoinConfiguration::class);
        if (! $configuration->authenticToken($token)) return $this->response(404);
        if (! $configuration->acceptsIp($request->ip())) return $this->response(403);
        $raw = $request->getContent();
        if (strlen($raw) > 16384) return $this->response(413);
        // Parse original bytes: Laravel's general trim/empty normalization must not repair financial fields.
        try {
            if ($request->isJson()) {
                $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
                if (! $decoded instanceof \stdClass) return $this->response(422);
                $input = (array) $decoded;
            } elseif (strtolower(explode(';', $request->header('Content-Type', ''))[0]) === 'application/x-www-form-urlencoded') {
                $input = [];
                foreach (explode('&', $raw) as $pair) {
                    if ($pair === '') continue;
                    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                    $key = urldecode($key);
                    if (array_key_exists($key, $input) || str_contains($key, '[')) return $this->response(422);
                    $input[$key] = urldecode($value);
                }
            } else {
                return $this->response(422);
            }
        } catch (\JsonException) {
            return $this->response(422);
        }
        $service = app(V2TechcoinKickbackService::class);
        try {
            $service->process($input);
            return $this->response(200);
        } catch (\Throwable) {
            try { $service->recordProcessingFailure($input); } catch (\Throwable) { /* Unavailable DB cannot persist a review. */ }
            // Never report/log request paths, tokens, body or exception SQL bindings from this endpoint.
            return $this->response(500);
        }
    }

    private function response(int $status): Response
    {
        return response('', $status, ['Cache-Control' => 'no-store']);
    }
}
