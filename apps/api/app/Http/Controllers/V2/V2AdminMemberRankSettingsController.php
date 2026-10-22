<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Domain\MemberRank\Services\V2MemberRankPrelaunchActivation;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminMemberRankSettingsController
{
    public function __construct(
        private readonly V2MemberRankSettingsService $settings,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->handle($request, fn ($context): array => ['data' => $this->settings->read($context)]);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->handle($request, fn ($context): array => $this->settings->update(
            $context, (string) $request->header('Idempotency-Key', ''), $request->json()->all()
        ));
    }

    public function cancel(Request $request): JsonResponse
    {
        return $this->handle($request, fn ($context): array => $this->settings->update(
            $context, (string) $request->header('Idempotency-Key', ''), $request->json()->all(), true
        ));
    }

    public function activatePrelaunch(Request $request): JsonResponse
    {
        return $this->handle($request, fn ($context): array => app(V2MemberRankPrelaunchActivation::class)->activate(
            $context, (string) $request->header('Idempotency-Key', ''), $request->json()->all()
        ));
    }

    public function history(Request $request): JsonResponse
    {
        return $this->handle($request, function ($context) use ($request): array {
            $before = $request->query('before_revision');
            if ($before !== null && ! is_string($before)) {
                throw new V2MemberRankException('MEMBER_RANK_SETTINGS_INVALID', 422, '履歴の指定を確認してください。');
            }

            return $this->settings->history($context, $before);
        });
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        $requestId = $request->attributes->get('v2_request_id');
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid7();
            $request->attributes->set('v2_request_id', $requestId);
        }
        try {
            $context = $this->authorization->context($request, $requestId);
            $result = $callback($context);

            return response()->json([...$result, 'request_id' => $requestId], 200, [
                'Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
                ...(array_key_exists('idempotent_replay', $result)
                    ? ['Idempotency-Replayed' => $result['idempotent_replay'] ? 'true' : 'false'] : []),
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2MemberRankException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(), 'status' => $exception->status,
                'code' => $exception->errorCode, 'request_id' => $requestId, 'retryable' => false,
            ], $exception->status, [
                'Content-Type' => 'application/problem+json', 'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2',
            ]);
        }
    }
}
