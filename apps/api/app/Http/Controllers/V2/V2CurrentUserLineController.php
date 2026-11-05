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

    public function show(\Illuminate\Http\Request $request): JsonResponse
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) {
            throw new V2AuthenticationException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Authentication is required.'
            );
        }

        $presentation = $this->service->presentation($user);
        $presentation['callback_result'] = app(\App\Domain\Line\Services\V2LineCallbackNotice::class)->consume(
            app(\App\Domain\Identity\Services\V2SessionManager::class)->sessionIdHash($request, \App\Domain\Identity\Enums\V2Realm::User)
        );
        return response()->json($presentation, 200, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Cookie',
            'X-Oripa-Api-Version' => '2',
        ]);
    }
}
