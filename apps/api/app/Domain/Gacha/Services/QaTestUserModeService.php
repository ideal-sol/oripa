<?php

namespace App\Domain\Gacha\Services;

use App\Domain\Audit\Services\AuditLogService;
use App\Models\AdminUser;
use App\Models\QaTestUserMode;
use App\Models\User;
use Illuminate\Http\Request;

class QaTestUserModeService
{
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function current(User $user): ?QaTestUserMode
    {
        return QaTestUserMode::query()
            ->where('user_id', $user->id)
            ->first();
    }

    public function upsert(User $user, AdminUser $adminUser, array $payload, ?Request $request = null): QaTestUserMode
    {
        $mode = $this->current($user);
        $before = $mode?->only([
            'is_enabled',
            'reason',
            'starts_at',
            'ends_at',
            'enabled_by_admin_user_id',
            'disabled_by_admin_user_id',
            'disabled_at',
        ]);

        $mode ??= new QaTestUserMode(['user_id' => $user->id]);
        $wasEnabled = (bool) $mode->exists && (bool) $mode->is_enabled;

        $mode->fill([
            'is_enabled' => true,
            'reason' => $payload['reason'],
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'],
            'enabled_by_admin_user_id' => $adminUser->id,
            'disabled_by_admin_user_id' => null,
            'disabled_at' => null,
        ]);
        $mode->save();

        $this->auditLogService->record(
            action: $wasEnabled ? 'admin.qa_test_user.updated' : 'admin.qa_test_user.enabled',
            adminUser: $adminUser,
            user: $user,
            auditable: $mode,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $mode->only([
                    'is_enabled',
                    'reason',
                    'starts_at',
                    'ends_at',
                    'enabled_by_admin_user_id',
                    'disabled_by_admin_user_id',
                    'disabled_at',
                ]),
            ],
        );

        return $mode->refresh();
    }

    public function disable(User $user, AdminUser $adminUser, ?Request $request = null): ?QaTestUserMode
    {
        $mode = $this->current($user);

        if (! $mode) {
            return null;
        }

        $before = $mode->only([
            'is_enabled',
            'reason',
            'starts_at',
            'ends_at',
            'enabled_by_admin_user_id',
            'disabled_by_admin_user_id',
            'disabled_at',
        ]);

        $mode->forceFill([
            'is_enabled' => false,
            'disabled_by_admin_user_id' => $adminUser->id,
            'disabled_at' => now(),
        ])->save();

        $this->auditLogService->record(
            action: 'admin.qa_test_user.disabled',
            adminUser: $adminUser,
            user: $user,
            auditable: $mode,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $mode->only([
                    'is_enabled',
                    'reason',
                    'starts_at',
                    'ends_at',
                    'enabled_by_admin_user_id',
                    'disabled_by_admin_user_id',
                    'disabled_at',
                ]),
            ],
        );

        return $mode->refresh();
    }

    public function isActive(?QaTestUserMode $mode): bool
    {
        if (! $mode || ! $mode->is_enabled || $mode->disabled_at !== null) {
            return false;
        }

        $now = now();

        if ($mode->starts_at !== null && $mode->starts_at->isAfter($now)) {
            return false;
        }

        return $mode->ends_at->isAfter($now);
    }
}
