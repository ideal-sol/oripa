<?php

namespace App\Http\Controllers\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2BannerImportService;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Point\Exceptions\V2PointException;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class V2AdminBannerImportController
{
    public function __construct(
        private readonly V2BannerImportService $service,
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        return $this->handle($request, 'index');
    }

    public function store(Request $request): JsonResponse|Response
    {
        return $this->handle($request, 'store');
    }

    public function show(Request $request, string $importId): JsonResponse|Response
    {
        return $this->handle($request, 'show', $importId);
    }

    public function failures(Request $request, string $importId): JsonResponse|Response
    {
        return $this->handle($request, 'failures', $importId);
    }

    private function handle(Request $request, string $action, ?string $id = null): JsonResponse|Response
    {
        $requestId = $request->attributes->get('v2_request_id');
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid7();
            $request->attributes->set('v2_request_id', $requestId);
        }
        $headers = ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2'];
        try {
            $context = $this->authorizer->context($request, $requestId);
            if ($action === 'failures') {
                return response($this->service->failureCsv($context, $id), 200, [
                    ...$headers, 'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="banner-import-failures.csv"',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
            $result = match ($action) {
                'index' => $this->service->listing($context),
                'store' => $this->service->create($context, $request->all(), (string) $request->header('Idempotency-Key', '')),
                'show' => $this->service->show($context, $id),
            };
            return response()->json($result, $action === 'store' ? 202 : 200, $headers);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2ContentContactException|V2CatalogException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(), 'status' => $exception->status, 'code' => $exception->errorCode,
                'request_id' => $requestId, 'retryable' => false,
                ...($exception instanceof V2CatalogException ? $exception->details : []),
            ], $exception->status, [...$headers, 'Content-Type' => 'application/problem+json']);
        } catch (V2PointException) {
            return response()->json([
                'type' => 'https://oripa.example/problems/idempotency_conflict', 'title' => '受付キーを確認してください。',
                'status' => 409, 'code' => 'IDEMPOTENCY_CONFLICT', 'request_id' => $requestId, 'retryable' => false,
            ], 409, [...$headers, 'Content-Type' => 'application/problem+json']);
        } catch (\Throwable) {
            return response()->json([
                'type' => 'https://oripa.example/problems/banner_import_failed', 'title' => '取込処理を完了できませんでした。',
                'status' => 503, 'code' => 'BANNER_IMPORT_FAILED', 'request_id' => $requestId, 'retryable' => true,
            ], 503, [...$headers, 'Content-Type' => 'application/problem+json']);
        }
    }
}
