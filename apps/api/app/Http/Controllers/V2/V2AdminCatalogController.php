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
use Illuminate\Http\Response;
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

    public function createPrize(Request $request): JsonResponse
    {
        return $this->create($request, 'prize');
    }

    public function updatePrize(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->update($request, 'prize', $catalogResourceId);
    }

    public function archivePrize(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->archive($request, 'prize', $catalogResourceId);
    }

    public function assets(Request $request): JsonResponse
    {
        return $this->list($request, 'assets');
    }

    public function asset(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->detail($request, 'asset', $catalogResourceId);
    }

    public function createAsset(Request $request): JsonResponse
    {
        return $this->create($request, 'asset');
    }

    public function updateAsset(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->update($request, 'asset', $catalogResourceId);
    }

    public function archiveAsset(Request $request, string $catalogResourceId): JsonResponse
    {
        return $this->archive($request, 'asset', $catalogResourceId);
    }

    public function gachas(Request $request): JsonResponse
    {
        return $this->list($request, 'gachas');
    }

    public function gacha(Request $request, string $gachaId): JsonResponse
    {
        return $this->detail($request, 'gacha', $gachaId);
    }

    public function gachaUsageHistory(Request $request, string $gachaId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaUsageHistory($context, $gachaId, $request->query())
        );
    }

    public function gachaUsageHistoryDetail(
        Request $request,
        string $gachaId,
        string $drawRequestId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaUsageHistoryDetail(
                    $context,
                    $gachaId,
                    $drawRequestId
                )
        );
    }

    public function createGacha(Request $request): JsonResponse
    {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->createGacha(
                    $context,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function createGachaCore(Request $request): JsonResponse
    {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->createGachaCore(
                    $context,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function uploadGachaThumbnail(Request $request): JsonResponse
    {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->uploadGachaThumbnail(
                    $context,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function presentationAssetContent(
        Request $request,
        string $catalogResourceId
    ): Response|JsonResponse {
        try {
            $context = $this->authorizer->context($request, $this->requestId($request));
            $asset = $this->catalog->presentationAssetContent($context, $catalogResourceId);

            return response($asset['content'], 200, [
                'Content-Type' => $asset['mime_type'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
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

    public function updateGacha(Request $request, string $gachaId): JsonResponse
    {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->updateGacha(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function archiveGacha(Request $request, string $gachaId): JsonResponse
    {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->archiveGacha(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function gachaVersions(Request $request, string $gachaId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaVersions($context, $gachaId, $request->query())
        );
    }

    public function gachaVersion(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaVersion($context, $gachaId, $versionId)
        );
    }

    public function gachaVersionRanks(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaVersionRanks($context, $gachaId, $versionId)
        );
    }

    public function createGachaVersionRank(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->createGachaDraftRank(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function updateGachaVersionRank(
        Request $request,
        string $gachaId,
        string $versionId,
        string $rankId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->updateGachaDraftRank(
                    $context,
                    $gachaId,
                    $versionId,
                    $rankId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function gachaVersionPrizes(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaVersionPrizes($context, $gachaId, $versionId)
        );
    }

    public function createGachaVersionPrize(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->createGachaDraftPrize(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function updateGachaVersionPrize(
        Request $request,
        string $gachaId,
        string $versionId,
        string $prizeId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->updateGachaDraftPrize(
                    $context,
                    $gachaId,
                    $versionId,
                    $prizeId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function createGachaDraft(Request $request, string $gachaId): JsonResponse
    {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->createGachaDraft(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function cloneGachaDraft(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->cloneGachaDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function updateGachaDraft(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->updateGachaDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function archiveGachaDraft(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->archiveGachaDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function probabilityVersions(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->probabilityVersions(
                    $context,
                    $gachaId,
                    $versionId,
                    $request->query()
                )
        );
    }

    public function probabilityVersion(
        Request $request,
        string $gachaId,
        string $versionId,
        string $probabilityVersionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->probabilityVersion(
                    $context,
                    $gachaId,
                    $versionId,
                    $probabilityVersionId
                )
        );
    }

    public function publishedProbabilityCandidates(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->publishedProbabilityCandidates(
                    $context,
                    $gachaId,
                    $versionId,
                    $request->query()
                )
        );
    }

    public function gachaProbabilitySelection(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaProbabilitySelection(
                    $context,
                    $gachaId,
                    $versionId
                )
        );
    }

    public function selectGachaProbability(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->selectPublishedProbability(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function preflightGachaPublish(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->preflightGachaPublish(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function gachaPublishState(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaPublishState($context, $gachaId)
        );
    }

    public function gachaSalesState(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaSalesState($context, $gachaId)
        );
    }

    public function preflightGachaSalesPause(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->preflightGachaSalesPause(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function pauseGachaSales(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->pauseGachaSales(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function preflightGachaSalesResume(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->preflightGachaSalesResume(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function resumeGachaSales(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->resumeGachaSales(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function gachaUnpublishState(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaUnpublishState($context, $gachaId)
        );
    }

    public function preflightGachaUnpublish(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->preflightGachaUnpublish(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function unpublishGacha(
        Request $request,
        string $gachaId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->unpublishGacha(
                    $context,
                    $gachaId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function publishGachaVersionImmediately(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->publishGachaVersionImmediately(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function gachaPublishSchedule(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->catalog->gachaPublishSchedule(
                    $context,
                    $gachaId,
                    $versionId
                )
        );
    }

    public function preflightGachaPublishSchedule(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->preflightGachaPublishSchedule(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function scheduleGachaVersionPublish(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->scheduleGachaVersionPublish(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function cancelGachaVersionPublishSchedule(
        Request $request,
        string $gachaId,
        string $versionId,
        string $scheduleId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->cancelGachaVersionPublishSchedule(
                    $context,
                    $gachaId,
                    $versionId,
                    $scheduleId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function createProbabilityDraft(
        Request $request,
        string $gachaId,
        string $versionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->createProbabilityDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function cloneProbabilityDraft(
        Request $request,
        string $gachaId,
        string $versionId,
        string $probabilityVersionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->cloneProbabilityDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    $probabilityVersionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function replaceProbabilityEntries(
        Request $request,
        string $gachaId,
        string $versionId,
        string $probabilityVersionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->replaceProbabilityEntries(
                    $context,
                    $gachaId,
                    $versionId,
                    $probabilityVersionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function validateProbabilityDraft(
        Request $request,
        string $gachaId,
        string $versionId,
        string $probabilityVersionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->validateProbabilityDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    $probabilityVersionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function archiveProbabilityDraft(
        Request $request,
        string $gachaId,
        string $versionId,
        string $probabilityVersionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->archiveProbabilityDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    $probabilityVersionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function preflightProbabilityPublish(
        Request $request,
        string $gachaId,
        string $versionId,
        string $probabilityVersionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->preflightProbabilityPublish(
                    $context,
                    $gachaId,
                    $versionId,
                    $probabilityVersionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
    }

    public function publishProbabilityDraft(
        Request $request,
        string $gachaId,
        string $versionId,
        string $probabilityVersionId
    ): JsonResponse {
        return $this->mutation(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->mutations->publishProbabilityDraft(
                    $context,
                    $gachaId,
                    $versionId,
                    $probabilityVersionId,
                    (string) $request->header('Idempotency-Key', ''),
                    $request->json()->all()
                )
        );
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
