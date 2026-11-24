<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Line\Services\V2ConversionEventService;
use App\Models\V2\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class V2ConversionEventController
{
    public function __construct(
        private readonly V2ConversionEventService $service,
        private readonly V2RateLimiter $rateLimiter,
    ) {}

    public function pending(): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->pending($this->userId('user_read')), 200, $this->headers());
    }

    public function claim(string $id): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->claim($this->userId('user_mutation'), $id), 200, $this->headers());
    }

    public function delivered(Request $request, string $id): \Illuminate\Http\Response
    {
        $userId = $this->userId('user_mutation');
        $token = $request->input('claim_token');
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            throw new V2AuthenticationException('VALIDATION_FAILED', 422, 'A claim token is required.');
        }
        $this->service->delivered($userId, $id, $token);
        return response()->noContent(204, $this->headers());
    }

    private function userId(string $limit): int
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401, 'Authentication is required.');
        // Keep conversion traffic separate from payment mutation counters.
        $this->rateLimiter->assertSubject($limit, 'conversion-events:'.$user->public_id);
        return (int) $user->id;
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'X-Oripa-Api-Version' => '2'];
    }
}
