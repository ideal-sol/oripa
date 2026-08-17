<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Line\Services\V2CurrentUserLineReadService;
use App\Models\V2\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class V2CurrentUserLineController
{
    public function __construct(private readonly V2CurrentUserLineReadService $service)
    {
    }

    public function show(): JsonResponse
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) {
            throw new V2AuthenticationException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Authentication is required.'
            );
        }

        return response()->json($this->service->presentation($user), 200, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Cookie',
            'X-Oripa-Api-Version' => '2',
        ]);
    }
}
