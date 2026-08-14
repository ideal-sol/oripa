<?php

namespace App\Http\Controllers\V2;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContactService;
use App\Domain\ContentContact\Services\V2ContentReadService;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Http\Responses\V2ProblemDetails;
use App\Models\V2\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2ContentContactController
{
    public function __construct(
        private readonly V2ContentReadService $content,
        private readonly V2ContactService $contacts
    ) {
    }

    public function banners(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            fn (): array => $this->content->banners(),
            cache: true
        );
    }

    public function assetContent(Request $request, string $assetId): Response|JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            $asset = $this->content->assetContent($assetId);

            return response($asset['content'], 200, [
                'Content-Type' => $asset['mime_type'],
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        } catch (V2ContentContactException $exception) {
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

    public function notices(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->content->notices(
            $request->query('cursor'),
            (int) $request->query('limit', config('v2_content_contact.cursor_page_size', 20))
        ), cache: true);
    }

    public function notice(Request $request, string $noticeId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (): array => $this->content->notice($noticeId),
            cache: true
        );
    }

    public function staticPage(Request $request, string $slug): JsonResponse
    {
        return $this->handle(
            $request,
            fn (): array => $this->content->staticPage($slug),
            cache: true
        );
    }

    public function footerPages(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            fn (): array => $this->content->footerPages(),
            cache: true
        );
    }

    public function contact(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->contacts->submit(
            $request->only(['name', 'email', 'phone', 'subject', 'body', 'website']),
            $this->user(),
            (string) ($request->ip() ?? ''),
            $this->requestId($request)
        ), 202);
    }

    /**
     * @param callable(): array<string, mixed> $callback
     */
    private function handle(
        Request $request,
        callable $callback,
        int $status = 200,
        bool $cache = false
    ): JsonResponse {
        $requestId = $this->requestId($request);
        try {
            $response = response()->json($callback(), $status, [
                'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
            $response->headers->set(
                'Cache-Control',
                $cache
                    ? 'public, max-age='.(int) config('v2_content_contact.public_cache_seconds', 60)
                    : 'private, no-store'
            );

            return $response;
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2ContentContactException $exception) {
            $response = response()->json([
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
            if ($exception->retryAfterSeconds !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
            }

            return $response;
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

    private function user(): ?User
    {
        $user = Auth::guard('v2_user')->user();

        return $user instanceof User ? $user : null;
    }
}
