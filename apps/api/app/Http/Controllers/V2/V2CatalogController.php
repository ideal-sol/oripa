<?php

namespace App\Http\Controllers\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2CatalogController
{
    public function __construct(
        private readonly V2CatalogReadService $catalog
    ) {
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->success(
            ['data' => $this->catalog->categories()],
            $request,
            (string) config('v2_catalog.master_cache_control')
        );
    }

    public function tags(Request $request): JsonResponse
    {
        return $this->success(
            ['data' => $this->catalog->tags()],
            $request,
            (string) config('v2_catalog.master_cache_control')
        );
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $limit = filter_var(
                $request->query('limit', config('v2_catalog.default_page_size', 20)),
                FILTER_VALIDATE_INT
            );
            if ($limit === false) {
                throw new V2CatalogException(
                    'INVALID_PAGE_SIZE',
                    422,
                    'The requested page size is invalid.'
                );
            }
            $result = $this->catalog->list(
                $limit,
                $this->queryString($request, 'cursor'),
                $this->queryString($request, 'category'),
                $this->queryString($request, 'tag'),
                Auth::guard('v2_user')->user()
            );
            $authenticated = Auth::guard('v2_user')->check();
            $response = $this->success(
                $result,
                $request,
                $authenticated
                    ? 'private, no-store'
                    : (string) config('v2_catalog.collection_cache_control')
            );
            if ($authenticated) {
                $response->headers->set('Vary', 'Cookie');
            }

            return $response;
        } catch (V2CatalogException $exception) {
            return $this->problem($request, $exception);
        }
    }

    public function show(Request $request, string $gachaId): JsonResponse
    {
        try {
            return $this->success(
                ['data' => $this->catalog->getByPublicId($gachaId)],
                $request,
                (string) config('v2_catalog.collection_cache_control')
            );
        } catch (V2CatalogException $exception) {
            return $this->problem($request, $exception);
        }
    }

    public function showBySlug(Request $request, string $slug): JsonResponse
    {
        try {
            return $this->success(
                ['data' => $this->catalog->getBySlug($slug)],
                $request,
                (string) config('v2_catalog.collection_cache_control')
            );
        } catch (V2CatalogException $exception) {
            return $this->problem($request, $exception);
        }
    }

    public function presentation(Request $request, string $gachaId): JsonResponse
    {
        try {
            $user = Auth::guard('v2_user')->user();
            $response = $this->success(
                ['data' => $this->catalog->presentationState($gachaId, $user)],
                $request,
                'private, no-store'
            );
            $response->headers->set('Vary', 'Cookie');

            return $response;
        } catch (V2CatalogException $exception) {
            return $this->problem($request, $exception);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function success(
        array $body,
        Request $request,
        string $cacheControl
    ): JsonResponse {
        $requestId = $this->requestId($request);

        return response()->json($body, 200, [
            'Cache-Control' => $cacheControl,
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ]);
    }

    private function problem(
        Request $request,
        V2CatalogException $exception
    ): JsonResponse {
        $requestId = $this->requestId($request);

        return response()->json([
            'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
            'title' => $exception->getMessage(),
            'status' => $exception->status,
            'code' => $exception->errorCode,
            'request_id' => $requestId,
            'retryable' => false,
        ], $exception->status, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ]);
    }

    private function requestId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value)
            ? $value
            : (string) Str::uuid7();
    }

    private function queryString(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
