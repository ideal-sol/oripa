<?php

namespace App\Domain\Audit\Services;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    public function record(
        string $action,
        ?AdminUser $adminUser = null,
        ?User $user = null,
        ?Model $auditable = null,
        ?Request $request = null,
        ?array $metadata = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'admin_user_id' => $adminUser?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
        ]);
    }
}
