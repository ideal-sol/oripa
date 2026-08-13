<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Domain\QaDraw\Services\V2QaExecutionManagementService;
use App\Domain\QaDraw\Services\V2QaPlanManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminQaDrawController
{
    public function __construct(
        private readonly V2QaDrawAdminService $service,
        private readonly V2QaPlanManagementService $management,
        private readonly V2QaExecutionManagementService $executions,
        private readonly V2AdminFreshMfaAuthorizer $freshMfa
    ) {
    }

    public function showMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->legacyModeEnvelope(
                $this->service->mode($this->context($request), $userId)
            ));
    }

    public function saveMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $userId): array {
            $reason = $request->input('reason');
            if (! is_string($reason)) {
                throw $this->invalid();
            }

            return $this->legacyMode($this->service->saveMode(
                $this->context($request),
                $userId,
                $reason
            ));
        });
    }

    public function disableMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->legacyMode(
            $this->service->disableMode($this->context($request), $userId)
        ));
    }

    /** @param array<string, mixed> $envelope */
    private function legacyModeEnvelope(array $envelope): array
    {
        if (is_array($envelope['mode'] ?? null)) {
            $envelope['mode'] = $this->legacyMode($envelope['mode']);
        }

        return $envelope;
    }

    /** @param array<string, mixed> $mode */
    private function legacyMode(array $mode): array
    {
        $mode['ends_at'] ??= '9999-12-31T23:59:59Z';

        return $mode;
    }

    public function plans(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->plans($this->context($request), $userId));
    }

    public function createPlan(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $userId): array {
            $gachaId = $request->input('gacha_id');
            $title = $request->input('title');
            $reason = $request->input('reason');
            $items = $request->input('items');
            if (
                ! is_string($gachaId)
                || ! is_string($title)
                || ! is_string($reason)
                || ! is_array($items)
            ) {
                throw $this->invalid();
            }

            return $this->service->createPlan(
                $this->context($request),
                $userId,
                $gachaId,
                $title,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                is_string($request->input('ends_at')) ? $request->input('ends_at') : null,
                $items
            );
        });
    }

    public function showPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->plan($this->context($request), $planId));
    }

    public function updatePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $planId): array {
            $title = $request->input('title');
            $reason = $request->input('reason');
            if (! is_string($title) || ! is_string($reason)) {
                throw $this->invalid();
            }

            return $this->service->updatePlan(
                $this->context($request),
                $planId,
                $title,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                is_string($request->input('ends_at')) ? $request->input('ends_at') : null
            );
        });
    }

    public function pausePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->pausePlan(
            $this->context($request),
            $planId
        ));
    }

    public function activatePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->activatePlan(
            $this->context($request),
            $planId
        ));
    }

    public function disablePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->disablePlan(
            $this->context($request),
            $planId
        ));
    }

    public function executions(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->executions->executions(
            $this->context($request),
            $request->only([
                'plan_id',
                'user_id',
                'gacha_id',
                'draw_request_id',
                'from',
                'to',
                'cursor',
                'limit',
            ])
        ));
    }

    public function showExecution(Request $request, string $executionId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->executions->execution(
            $this->context($request),
            $executionId
        ));
    }

    public function preflightExecution(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->executions->preflight(
            $this->context($request),
            $planId,
            $this->objectInput($request)
        ));
    }

    public function execute(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->executions->execute(
            $this->context($request),
            $planId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function managementPlans(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->plans(
            $this->context($request),
            $request->only(['status', 'q', 'cursor', 'limit'])
        ));
    }

    public function createManagementPlan(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->createPlan(
            $this->context($request),
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function showManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->management->plan($this->context($request), $planId));
    }

    public function updateManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->updatePlan(
            $this->context($request),
            $planId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function enableManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->transitionPlan(
            $this->context($request),
            $planId,
            $this->idempotencyKey($request),
            $this->objectInput($request),
            'enable'
        ));
    }

    public function disableManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->transitionPlan(
            $this->context($request),
            $planId,
            $this->idempotencyKey($request),
            $this->objectInput($request),
            'disable'
        ));
    }

    public function archiveManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->archivePlan(
            $this->context($request),
            $planId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function preflightManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->management->preflight($this->context($request), $planId));
    }

    public function assignManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->assign(
            $this->context($request),
            $planId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function unassignManagementPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->unassign(
            $this->context($request),
            $planId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function managementTestUsers(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->testUsers(
            $this->context($request),
            $request->only(['cursor', 'limit'])
        ));
    }

    public function managementTestUserCandidates(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->candidates(
            $this->context($request),
            $request->only(['q', 'cursor', 'limit'])
        ));
    }

    public function saveManagementTestUser(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->saveTestUser(
            $this->context($request),
            $userId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function disableManagementTestUser(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->disableTestUser(
            $this->context($request),
            $userId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function gachaGuarantees(Request $request, string $gachaId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->gachaGuarantees(
            $this->context($request),
            $gachaId
        ));
    }

    public function saveGachaGuarantee(Request $request, string $gachaId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->management->saveGachaGuarantee(
            $this->context($request),
            $gachaId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    public function disableGachaGuarantee(
        Request $request,
        string $gachaId,
        string $userId
    ): JsonResponse {
        return $this->handle($request, fn (): array => $this->management->disableGachaGuarantee(
            $this->context($request),
            $gachaId,
            $userId,
            $this->idempotencyKey($request),
            $this->objectInput($request)
        ));
    }

    private function context(Request $request): V2AdminAuthorizationContext
    {
        return $this->freshMfa->context($request, $this->requestId($request));
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            return response()->json($callback(), 200, [
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        } catch (V2QaDrawException|V2AuthenticationException $exception) {
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
        $value = $request->header('X-Request-Id');

        return is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
    }

    /** @return array<string, mixed> */
    private function objectInput(Request $request): array
    {
        $input = $request->json()->all();
        if (! is_array($input) || array_is_list($input)) {
            throw $this->invalid();
        }

        return $input;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) ? $key : '';
    }

    private function invalid(): V2QaDrawException
    {
        return new V2QaDrawException(
            'QA_CONFIGURATION_INVALID',
            422,
            'The QA Draw request is invalid.'
        );
    }
}
