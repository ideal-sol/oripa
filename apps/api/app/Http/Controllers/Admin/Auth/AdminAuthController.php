<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $admin = AdminUser::query()
            ->where('email', $request->email())
            ->first();

        if (! $admin || ! $admin->is_active || ! Hash::check($request->password(), $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        $token = $admin->createToken($request->deviceName(), ['admin'])->plainTextToken;

        $this->auditLogService->record(
            action: 'admin.login',
            adminUser: $admin,
            request: $request,
            metadata: [
                'device_name' => $request->deviceName(),
            ],
        );

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'admin' => new AdminUserResource($admin),
        ]);
    }

    public function me(Request $request): AdminUserResource
    {
        return new AdminUserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $admin = $request->user();

        $request->user()?->currentAccessToken()?->delete();

        if ($admin instanceof AdminUser) {
            $this->auditLogService->record(
                action: 'admin.logout',
                adminUser: $admin,
                request: $request,
            );
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
