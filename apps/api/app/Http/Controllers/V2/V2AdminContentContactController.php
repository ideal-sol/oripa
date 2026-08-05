<?php

namespace App\Http\Controllers\V2;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminContentContactController
{
    public function __construct(
        private readonly V2ContentContactAdminService $service,
        private readonly V2AdminFreshMfaAuthorizer $authorizer
    ) {
    }

    public function banners(Request $request): JsonResponse { return $this->listing($request, 'banner'); }
    public function notices(Request $request): JsonResponse { return $this->listing($request, 'notice'); }
    public function staticPages(Request $request): JsonResponse { return $this->listing($request, 'static-page'); }
    public function createBanner(Request $request): JsonResponse { return $this->create($request, 'banner'); }
    public function createNotice(Request $request): JsonResponse { return $this->create($request, 'notice'); }
    public function previewNotice(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->previewNotice($context, $request->all())
        );
    }
    public function createStaticPage(Request $request): JsonResponse { return $this->create($request, 'static-page'); }
    public function banner(Request $request, string $contentId): JsonResponse { return $this->show($request, 'banner', $contentId); }
    public function notice(Request $request, string $contentId): JsonResponse { return $this->show($request, 'notice', $contentId); }
    public function staticPage(Request $request, string $contentId): JsonResponse { return $this->show($request, 'static-page', $contentId); }
    public function createBannerVersion(Request $request, string $contentId): JsonResponse { return $this->version($request, 'banner', $contentId); }
    public function createNoticeVersion(Request $request, string $contentId): JsonResponse { return $this->version($request, 'notice', $contentId); }
    public function createStaticPageVersion(Request $request, string $contentId): JsonResponse { return $this->version($request, 'static-page', $contentId); }
    public function publishBanner(Request $request, string $contentId, string $versionId): JsonResponse { return $this->publish($request, 'banner', $contentId, $versionId); }
    public function publishNotice(Request $request, string $contentId, string $versionId): JsonResponse { return $this->publish($request, 'notice', $contentId, $versionId); }
    public function publishStaticPage(Request $request, string $contentId, string $versionId): JsonResponse { return $this->publish($request, 'static-page', $contentId, $versionId); }
    public function unpublishBanner(Request $request, string $contentId): JsonResponse { return $this->state($request, 'banner', $contentId, false); }
    public function unpublishNotice(Request $request, string $contentId): JsonResponse { return $this->state($request, 'notice', $contentId, false); }
    public function unpublishStaticPage(Request $request, string $contentId): JsonResponse { return $this->state($request, 'static-page', $contentId, false); }
    public function archiveBanner(Request $request, string $contentId): JsonResponse { return $this->state($request, 'banner', $contentId, true); }
    public function archiveNotice(Request $request, string $contentId): JsonResponse { return $this->state($request, 'notice', $contentId, true); }
    public function archiveStaticPage(Request $request, string $contentId): JsonResponse { return $this->state($request, 'static-page', $contentId, true); }

    public function contacts(Request $request): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->contactList(
                $context,
                $request->query('cursor'),
                (int) $request->query('limit', config('v2_content_contact.cursor_page_size', 20)),
                $request->filled('status') ? (string) $request->query('status') : null,
                $request->filled('email') ? (string) $request->query('email') : null
            ));
    }

    public function contact(Request $request, string $contactId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->contactDetail($context, $contactId)
        );
    }

    public function updateContactStatus(Request $request, string $contactId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->updateContactStatus(
                    $context,
                    $contactId,
                    (string) $request->input('status'),
                    (string) $request->input('reason_code'),
                    (string) $request->header('Idempotency-Key', '')
                )
        );
    }

    public function addContactNote(Request $request, string $contactId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->addInternalNote(
                    $context,
                    $contactId,
                    (string) $request->input('note')
                ),
            201
        );
    }

    public function requestContactReply(Request $request, string $contactId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->requestReply(
                    $context,
                    $contactId,
                    (string) $request->input('message'),
                    (string) $request->header('Idempotency-Key', '')
                ),
            202
        );
    }

    private function listing(Request $request, string $type): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->contentList(
                $context,
                $type,
                $request->query('cursor'),
                (int) $request->query('limit', config('v2_content_contact.cursor_page_size', 20))
            ));
    }

    private function create(Request $request, string $type): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->createContent(
                    $context,
                    $type,
                    $request->all(),
                    $type === 'notice'
                        ? (string) $request->header('Idempotency-Key', '')
                        : ''
                ),
            201
        );
    }

    private function show(Request $request, string $type, string $id): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->contentDetail($context, $type, $id)
        );
    }

    private function version(Request $request, string $type, string $id): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->createVersion(
                    $context,
                    $type,
                    $id,
                    $request->all(),
                    $type === 'notice'
                        ? (string) $request->header('Idempotency-Key', '')
                        : ''
                ),
            201
        );
    }

    private function publish(
        Request $request,
        string $type,
        string $id,
        string $versionId
    ): JsonResponse {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array =>
                $this->service->publish($context, $type, $id, $versionId)
        );
    }

    private function state(Request $request, string $type, string $id, bool $archive): JsonResponse
    {
        return $this->handle(
            $request,
            fn (V2AdminAuthorizationContext $context): array => $archive
                ? $this->service->archive($context, $type, $id)
                : $this->service->unpublish($context, $type, $id)
        );
    }

    /**
     * @param callable(V2AdminAuthorizationContext): array<string, mixed> $callback
     */
    private function handle(Request $request, callable $callback, int $status = 200): JsonResponse
    {
        try {
            $context = $this->authorizer->context($request, $this->requestId($request));
            return response()->json($callback($context), $status, [
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $context->requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2ContentContactException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $this->requestId($request),
                'retryable' => $exception->retryable,
            ], $exception->status, [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $this->requestId($request),
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
        $value = $request->header('X-Request-Id');
        $requestId = is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
        $request->attributes->set('v2_request_id', $requestId);
        $request->headers->set('X-Request-Id', $requestId);

        return $requestId;
    }

}
