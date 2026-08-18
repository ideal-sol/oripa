<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Payment\V2\Exceptions\V2PointPurchasePlanException;
use App\Domain\Payment\V2\Services\V2AdminLimitedBonusCampaignService;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminLimitedBonusCampaignController
{
    public function __construct(
        private readonly V2AdminLimitedBonusCampaignService $service,
        private readonly V2AdminFreshMfaAuthorizer $authorizer
    ) {
    }

    public function index(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array => [
            'items' => $this->service->listing($context, $planId),
        ]);
    }

    public function store(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->create(
                $context,
                $planId,
                $request->json()->all(),
                (string) $request->header('Idempotency-Key', '')
            ), 201, true);
    }

    public function update(Request $request, string $planId, string $campaignId): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->update(
                $context,
                $planId,
                $campaignId,
                $request->json()->all(),
                (string) $request->header('Idempotency-Key', '')
            ), 200, true);
    }

    /** @param callable(V2AdminAuthorizationContext): array<string, mixed> $callback */
    private function handle(
        Request $request,
        callable $callback,
        int $status = 200,
        bool $mutation = false
    ): JsonResponse {
        try {
            $context = $this->authorizer->context($request, $this->requestId($request));
            $result = [...$callback($context), 'request_id' => $context->requestId];

            return response()->json($result, $status, [
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $context->requestId,
                'X-Oripa-Api-Version' => '2',
                ...($mutation ? ['Idempotency-Replayed' =>
                    ($result['idempotent_replay'] ?? false) ? 'true' : 'false'] : []),
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2PointPurchasePlanException $exception) {
            $requestId = $this->requestId($request);

            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $requestId,
                'retryable' => $exception->retryable,
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

        return $requestId;
    }
}
