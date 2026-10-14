<?php

namespace App\Http\Controllers\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CardInventoryService;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminCardInventoryController
{
    public function __construct(
        private readonly V2CardInventoryService $inventory,
        private readonly V2AdminFreshMfaAuthorizer $authorizer
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, 'cards');
    }

    public function show(Request $request, string $externalId): JsonResponse
    {
        return $this->handle($request, 'card', $externalId);
    }

    public function unlinked(Request $request): JsonResponse
    {
        return $this->handle($request, 'unlinkedPrizes');
    }

    private function handle(Request $request, string $method, ?string $externalId = null): JsonResponse
    {
        $requestId = $request->attributes->get('v2_request_id');
        $requestId = is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid7();
        $headers = ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2'];
        try {
            $context = $this->authorizer->context($request, $requestId);
            $result = $externalId === null
                ? $this->inventory->{$method}($context, $request->query())
                : $this->inventory->card($context, $externalId, $request->query());

            return response()->json($result, 200, $headers);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2CatalogException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(), 'status' => $exception->status,
                'code' => $exception->errorCode, 'request_id' => $requestId, 'retryable' => false,
            ], $exception->status, ['Content-Type' => 'application/problem+json', ...$headers]);
        }
    }
}
