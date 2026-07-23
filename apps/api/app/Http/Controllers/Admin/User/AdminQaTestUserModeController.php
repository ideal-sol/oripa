<?php

namespace App\Http\Controllers\Admin\User;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Services\QaTestUserModeService;
use App\Http\Requests\Admin\UpsertQaTestUserModeRequest;
use App\Http\Resources\QaTestUserModeResource;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminQaTestUserModeController extends Controller
{
    public function show(Request $request, User $user, QaTestUserModeService $service): JsonResponse
    {
        $this->authorizeOwner($request);

        $mode = $service->current($user);

        return response()->json([
            'data' => $mode ? QaTestUserModeResource::make($mode)->resolve() : null,
        ]);
    }

    public function upsert(UpsertQaTestUserModeRequest $request, User $user, QaTestUserModeService $service): JsonResponse
    {
        /** @var AdminUser $adminUser */
        $adminUser = $request->user();

        $mode = $service->upsert(
            user: $user,
            adminUser: $adminUser,
            payload: $request->validated(),
            request: $request,
        );

        return response()->json([
            'data' => QaTestUserModeResource::make($mode)->resolve(),
        ]);
    }

    public function destroy(Request $request, User $user, QaTestUserModeService $service): JsonResponse
    {
        $this->authorizeOwner($request);

        /** @var AdminUser $adminUser */
        $adminUser = $request->user();
        $mode = $service->disable($user, $adminUser, $request);

        return response()->json([
            'data' => $mode ? QaTestUserModeResource::make($mode)->resolve() : null,
        ]);
    }

    private function authorizeOwner(Request $request): void
    {
        $adminUser = $request->user();

        abort_unless(
            $adminUser instanceof AdminUser && $adminUser->role === AdminRole::Owner,
            403,
            'Owner access is required.',
        );
    }
}
