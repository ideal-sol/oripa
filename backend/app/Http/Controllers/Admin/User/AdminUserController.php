<?php

namespace App\Http\Controllers\Admin\User;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()
            ->with(['wallet', 'profile'])
            ->withCount(['pointLots', 'pointLedgers'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('q')) {
            $keyword = '%'.$request->string('q')->toString().'%';
            $query->where(function ($query) use ($keyword): void {
                $query
                    ->where('name', 'like', $keyword)
                    ->orWhere('email', 'like', $keyword);
            });
        }

        return UserResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['wallet', 'profile'])->loadCount(['pointLots', 'pointLedgers']));
    }

    public function update(UpdateUserRequest $request, User $user, AuditLogService $auditLogService): UserResource
    {
        $before = $user->only(array_keys($request->validated()));

        $user->fill($request->validated());
        $user->save();

        $auditLogService->record(
            action: 'admin.user.updated',
            adminUser: $request->user(),
            user: $user,
            auditable: $user,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $user->only(array_keys($request->validated())),
            ],
        );

        return new UserResource($user->refresh()->load(['wallet', 'profile'])->loadCount(['pointLots', 'pointLedgers']));
    }
}
