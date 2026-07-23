<?php

namespace App\Http\Controllers\Admin\User;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Services\QaDrawPlanService;
use App\Http\Requests\Admin\StoreQaDrawPlanRequest;
use App\Http\Requests\Admin\UpdateQaDrawPlanRequest;
use App\Http\Resources\QaDrawPlanResource;
use App\Models\AdminUser;
use App\Models\QaDrawPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminQaDrawPlanController extends Controller
{
    public function index(Request $request, User $user, QaDrawPlanService $service): JsonResponse
    {
        $this->authorizeOwner($request);

        return response()->json([
            'data' => QaDrawPlanResource::collection($service->listForUser($user))->resolve(),
        ]);
    }

    public function store(StoreQaDrawPlanRequest $request, User $user, QaDrawPlanService $service): JsonResponse
    {
        /** @var AdminUser $adminUser */
        $adminUser = $request->user();
        $plan = $service->create($user, $adminUser, $request->validated(), $request);

        return response()->json([
            'data' => QaDrawPlanResource::make($plan)->resolve(),
        ]);
    }

    public function show(Request $request, QaDrawPlan $plan): JsonResponse
    {
        $this->authorizeOwner($request);

        return response()->json([
            'data' => QaDrawPlanResource::make(
                $plan->load(['gacha', 'items.prize.rank', 'items.rankImageAsset', 'items.drawVideoAsset'])
            )->resolve(),
        ]);
    }

    public function update(UpdateQaDrawPlanRequest $request, QaDrawPlan $plan, QaDrawPlanService $service): JsonResponse
    {
        /** @var AdminUser $adminUser */
        $adminUser = $request->user();
        $plan = $service->update($plan, $adminUser, $request->validated(), $request);

        return response()->json([
            'data' => QaDrawPlanResource::make($plan)->resolve(),
        ]);
    }

    public function destroy(Request $request, QaDrawPlan $plan, QaDrawPlanService $service): JsonResponse
    {
        $this->authorizeOwner($request);

        /** @var AdminUser $adminUser */
        $adminUser = $request->user();
        $plan = $service->disable($plan, $adminUser, $request);

        return response()->json([
            'data' => QaDrawPlanResource::make($plan)->resolve(),
        ]);
    }

    public function pause(Request $request, QaDrawPlan $plan, QaDrawPlanService $service): JsonResponse
    {
        $this->authorizeOwner($request);

        /** @var AdminUser $adminUser */
        $adminUser = $request->user();
        $plan = $service->pause($plan, $adminUser, $request);

        return response()->json([
            'data' => QaDrawPlanResource::make($plan)->resolve(),
        ]);
    }

    public function activate(Request $request, QaDrawPlan $plan, QaDrawPlanService $service): JsonResponse
    {
        $this->authorizeOwner($request);

        /** @var AdminUser $adminUser */
        $adminUser = $request->user();
        $plan = $service->activate($plan, $adminUser, $request);

        return response()->json([
            'data' => QaDrawPlanResource::make($plan)->resolve(),
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
