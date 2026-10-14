<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Integrations\Notion\NotionException;
use App\Domain\Integrations\Notion\NotionSyncService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

final class V2AdminNotionController
{
    public function __construct(private readonly NotionSyncService $service, private readonly V2AdminFreshMfaAuthorizer $authorizer) {}

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, 'listing');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->handle($request, 'create');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->handle($request, 'show', $id);
    }

    public function status(Request $request): JsonResponse
    {
        return $this->handle($request, 'status');
    }

    private function handle(Request $request, string $action, ?string $id = null): JsonResponse
    {
        $requestId = $request->attributes->get('v2_request_id') ?? (string) Str::uuid7();
        $headers = ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2'];
        try {
            $context = $this->authorizer->context($request, $requestId);
            $result = match ($action) {
                'create' => $this->service->create($context, (string) $request->header('Idempotency-Key', '')),
                'show' => $this->service->show($context, $id),
                'listing' => $this->service->listing($context),
                'status' => $this->service->status($context),
            };

            return response()->json($result, $action === 'create' ? 202 : 200, $headers);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (Throwable $exception) {
            $status = $exception instanceof NotionException ? $exception->status : ($exception instanceof V2PointException ? 409 : 503);
            $code = $exception instanceof NotionException ? $exception->errorCode : ($exception instanceof V2PointException ? 'IDEMPOTENCY_CONFLICT' : 'NOTION_SYNC_FAILED');

            return response()->json(['type' => 'https://oripa.example/problems/'.strtolower($code),
                'title' => 'Notion同期の受付・参照を完了できませんでした。', 'status' => $status, 'code' => $code,
                'request_id' => $requestId, 'retryable' => $status === 503,
            ], $status, [...$headers, 'Content-Type' => 'application/problem+json']);
        }
    }
}
