<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Models\V2\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2AdminPermissionController
{
    public function __construct(
        private readonly V2PermissionAuthorizer $permissions
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $admin = Auth::guard('v2_admin')->user();
        if (! $admin instanceof Admin) {
            throw new V2AuthenticationException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Admin authentication is required.'
            );
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'role' => $admin->role->value,
            'permissions' => $this->permissions->effectivePermissions($admin->role),
            'request_id' => $requestId,
        ], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Request-Id' => $requestId,
        ]);
    }

    private function requestId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
    }
}
