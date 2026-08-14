<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Services\V2PointProductReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2PointProductController
{
    public function __construct(
        private readonly V2PointProductReadService $products
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('v2_user')->user();
        $response = response()->json([
            'data' => $this->products->listing($user),
        ], 200, [
            'Cache-Control' => $user === null
                ? (string) config('v2_payment.point_product_collection_cache_control')
                : 'private, no-store',
            'X-Request-Id' => $this->requestId($request),
            'X-Oripa-Api-Version' => '2',
        ]);
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }

    private function requestId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value)
            ? $value
            : (string) Str::uuid7();
    }
}
