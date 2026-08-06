<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Referral\Exceptions\V2ReferralException;
use App\Domain\Referral\Services\V2ReferralPointSettingService;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminReferralPointSettingController
{
    public function __construct(
        private readonly V2ReferralPointSettingService $settings,
        private readonly V2AdminFreshMfaAuthorizer $authorizer
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->handle($request, fn ($context): array => [
            'data' => $this->settings->read($context),
            'request_id' => $context->requestId,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->handle($request, fn ($context): array => [
            ...$this->settings->update(
                $context,
                (string) $request->header('Idempotency-Key', ''),
                $request->json()->all()
            ),
            'request_id' => $context->requestId,
        ], true);
    }

    /** @param callable(\App\Domain\Identity\Contracts\V2AdminAuthorizationContext): array<string, mixed> $callback */
    private function handle(Request $request, callable $callback, bool $mutation = false): JsonResponse
    {
        try {
            $context = $this->authorizer->context($request, $this->requestId($request));
            $result = $callback($context);

            return response()->json($result, 200, [
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $context->requestId,
                'X-Oripa-Api-Version' => '2',
                ...($mutation ? [
                    'Idempotency-Replayed' => ($result['idempotent_replay'] ?? false)
                        ? 'true'
                        : 'false',
                ] : []),
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2ReferralException $exception) {
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
