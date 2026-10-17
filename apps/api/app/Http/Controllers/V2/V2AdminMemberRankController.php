<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankAdminReadService;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminMemberRankController
{
    public function __construct(
        private readonly V2MemberRankAdminReadService $read,
        private readonly V2MemberRankAdminService $writes,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->handle($request, function ($context): array {
            $this->authorization->authorizePermission($context, V2Permission::ReadMemberRankSettings);

            return ['data' => $this->read->summary()];
        });
    }

    public function manualChanges(Request $request): JsonResponse
    {
        return $this->handle($request, function ($context) use ($request): array {
            $this->authorization->authorizePermission($context, V2Permission::ReadMemberRankSettings);

            return $this->read->history(null, $request->query(), false, true);
        });
    }

    public function history(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $userId): array {
            if (! in_array($request->query('history'), ['rank', 'points'], true)) {
                throw new V2MemberRankException('MEMBER_RANK_QUERY_INVALID', 422, '履歴の種類を確認してください。');
            }
            $user = $this->read->user($userId);

            return $this->read->history((int) $user->id, $request->query(), $request->query('history') === 'points');
        });
    }

    public function preview(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, function ($context) use ($request, $userId): array {
            $input = $request->query();
            $operation = $input['operation'] ?? null;
            unset($input['operation']);
            if (! is_string($operation)) {
                throw new V2MemberRankException('MEMBER_RANK_INPUT_INVALID', 422, '操作を確認してください。');
            }
            foreach (['delta', 'skip_count'] as $key) {
                if (isset($input[$key]) && is_string($input[$key]) && preg_match('/\A-?(0|[1-9][0-9]{0,15})\z/', $input[$key])) {
                    $input[$key] = (int) $input[$key];
                }
            }
            if (isset($input['grant_bonus']) && in_array($input['grant_bonus'], ['true', 'false'], true)) {
                $input['grant_bonus'] = $input['grant_bonus'] === 'true';
            }

            return ['data' => $this->writes->preview($context, $userId, $operation, $input)];
        });
    }

    public function changeRank(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn ($context): array => $this->writes->write(
            $context, $userId, 'rank', $request->json()->all(), (string) $request->header('Idempotency-Key', '')
        ));
    }

    public function adjustPoints(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn ($context): array => $this->writes->write(
            $context, $userId, 'points', $request->json()->all(), (string) $request->header('Idempotency-Key', '')
        ));
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        $requestId = $request->attributes->get('v2_request_id');
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid7();
            $request->attributes->set('v2_request_id', $requestId);
        }
        $headers = ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2'];
        try {
            $context = $this->authorization->context($request, $requestId);
            $result = $callback($context);

            return response()->json([...$result, 'request_id' => $requestId], 200, [...$headers,
                ...(isset($result['idempotent_replay']) ? ['Idempotency-Replayed' => $result['idempotent_replay'] ? 'true' : 'false'] : [])]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2MemberRankException|V2ReportingException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(), 'status' => $exception->status, 'code' => $exception->errorCode,
                'request_id' => $requestId, 'retryable' => false,
            ], $exception->status, [...$headers, 'Content-Type' => 'application/problem+json']);
        }
    }
}
