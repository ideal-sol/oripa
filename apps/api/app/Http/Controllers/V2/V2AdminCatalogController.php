<?php

namespace App\Http\Controllers\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2AdminCatalogReadService;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminCatalogController
{
    public function __construct(
        private readonly V2AdminCatalogReadService $catalog,
        private readonly V2CatalogMasterMutationService $mutations,
        private readonly V2AdminFreshMfaAuthorizer $authorizer
    ) {
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->list($request, 'categories');
    }

    public function category(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->detail($request, 'category', $catalogResourceId);
    }

    public function createCategory(Request $request): JsonResponse
    {
        return $this->create($request, 'category');
    }

    public function updateCategory(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->update($request, 'category', $catalogResourceId);
    }

    public function archiveCategory(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->archive($request, 'category', $catalogResourceId);
    }

    public function tags(Request $request): JsonResponse
    {
        return $this->list($request, 'tags');
    }

    public function tag(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->detail($request, 'tag', $catalogResourceId);
    }

    public function createTag(Request $request): JsonResponse
    {
        return $this->create($request, 'tag');
    }

    public function updateTag(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->update($request, 'tag', $catalogResourceId);
    }

    public function archiveTag(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->archive($request, 'tag', $catalogResourceId);
    }

    public function ranks(Request $request): JsonResponse
    {
        return $this->list($request, 'ranks');
    }

    public function rank(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->detail($request, 'rank', $catalogResourceId);
    }

    public function createRank(Request $request): JsonResponse
    {
        return $this->create($request, 'rank');
    }

    public function updateRank(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->update($request, 'rank', $catalogResourceId);
    }

    public function archiveRank(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->archive($request, 'rank', $catalogResourceId);
    }

    public function prizes(Request $request): JsonResponse
    {
        return $this->list($request, 'prizes');
    }

    public function prize(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->detail($request, 'prize', $catalogResourceId);
    }

    public function assets(Request $request): JsonResponse
    {
        return $this->list($request, 'assets');
    }

    public function asset(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->detail($request, 'asset', $catalogResourceId);
    }

    private function list(Request $request, string $method): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->{$method}($context, $request->query())
        );
    }

    private function detail(
        Request $request,
        string $method,
        string $publicId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->{$method}($context, $publicId)
        );
    }

    private function create(Request $request, string $resource): JsonResponse
    {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->create(
                    $context,
                    $resource,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    private function update(
        Request $request,
        string $resource,
        string $publicId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->update(
                    $context,
                    $resource,
                    $publicId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    private function archive(
        Request $request,
        string $resource,
        string $publicId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->archive(
                    $context,
                    $resource,
                    $publicId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    /**
     * @param callable(V2AdminAuthorizationContext): array<string, mixed> $callback
     */
    private function handle(Request $request, callable $callback): JsonResponse
    {
        try {
            $context = $this->authorizer->context($request, $this->requestId($request));

            return response()->json($callback($context), 200, [
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $context->requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2CatalogException $exception) {
            $requestId = $this->requestId($request);

            return response()->json([
                'type' => 'https://oripa.example/problems/'
                    .strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $requestId,
                'retryable' => false,
            ], $exception->status, [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        }
    }

    /**
     * @param callable(V2AdminAuthorizationContext): array{
     *   data: array<string, mixed>,
     *   idempotent_replay: bool,
     *   status: int
     * } $callback
     */
    private function mutation(Request $request, callable $callback): JsonResponse
    {
        try {
            $context = $this->authorizer->context($request, $this->requestId($request));
            $result = $callback($context);
            $status = $result['status'];
            unset($result['status']);

            return response()->json($result, $status, [
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $context->requestId,
                'X-Oripa-Api-Version' => '2',
                'Idempotency-Replayed' => $result['idempotent_replay'] ? 'true' : 'false',
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2CatalogException $exception) {
            $requestId = $this->requestId($request);

            return response()->json([
                'type' => 'https://oripa.example/problems/'
                    .strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $requestId,
                'retryable' => false,
            ], $exception->status, [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        }
    }

    private function requestId(Request $request): string
    {
        $stored = $request->attributes->get('v2_request_id');
        if (is_string($stored) && Str::isUuid($stored)) {
            return $stored;
        }
        $header = $request->header('X-Request-Id');
        $requestId = is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid7();
        $request->attributes->set('v2_request_id', $requestId);
        $request->headers->set('X-Request-Id', $requestId);

        return $requestId;
    }
}
