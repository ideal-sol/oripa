<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankPublicReadService;
use App\Models\V2\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2MemberRankController
{
    public function __construct(private readonly V2MemberRankPublicReadService $service)
    {
    }

    public function current(Request $request): JsonResponse
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) {
            throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
        }

        return $this->respond($request, fn (): array => $this->service->current($user->id), true);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->service->listing(), false);
    }

    private function respond(Request $request, callable $read, bool $personal): JsonResponse
    {
        $supplied = $request->header('X-Request-Id');
        $requestId = is_string($supplied) && Str::isUuid($supplied) ? $supplied : (string) Str::uuid7();
        $headers = [
            'Cache-Control' => $personal ? 'private, no-store' : 'no-store',
            'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2',
            ...($personal ? ['Vary' => 'Cookie'] : []),
        ];
        try {
            return response()->json($read(), 200, $headers);
        } catch (V2MemberRankException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(), 'status' => $exception->status,
                'code' => $exception->errorCode, 'request_id' => $requestId, 'retryable' => true,
            ], $exception->status, [...$headers, 'Content-Type' => 'application/problem+json']);
        }
    }
}
