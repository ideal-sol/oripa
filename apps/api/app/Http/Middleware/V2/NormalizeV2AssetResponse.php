<?php

namespace App\Http\Middleware\V2;

use App\Domain\Catalog\Services\V2AssetPublicPathResolver;
use App\Domain\Catalog\Services\V2AssetResponseNormalizer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class NormalizeV2AssetResponse
{
    public function __construct(
        private readonly V2AssetPublicPathResolver $resolver,
        private readonly V2AssetResponseNormalizer $normalizer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v2/*', 'admin/api/v2/*')) {
            return $next($request);
        }
        try {
            $enabled = $this->resolver->enabled();
        } catch (InvalidArgumentException) {
            return $this->unavailable();
        }
        $response = $next($request);
        if (! $enabled || ! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }
        $data = $response->getData(true);
        if (! is_array($data)) {
            return $response;
        }
        try {
            $normalized = $this->normalizer->normalize($data);
        } catch (InvalidArgumentException) {
            return $this->unavailable();
        }
        if ($normalized !== $data) {
            $response->setData($normalized);
            $response->headers->remove('Content-Length');
            $response->headers->remove('ETag');
        }

        return $response;
    }

    private function unavailable(): JsonResponse
    {
        $requestId = (string) Str::uuid();

        return response()->json([
            'type' => 'https://oripa.example/problems/asset_delivery_unavailable',
            'title' => 'Asset delivery is unavailable.',
            'status' => 503,
            'code' => 'ASSET_DELIVERY_UNAVAILABLE',
            'request_id' => $requestId,
            'retryable' => false,
        ], 503, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'private, no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ]);
    }
}
