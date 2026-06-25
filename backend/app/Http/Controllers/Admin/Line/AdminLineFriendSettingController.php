<?php

namespace App\Http\Controllers\Admin\Line;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\UpdateLineFriendSettingRequest;
use App\Http\Resources\LineFriendSettingResource;
use App\Models\LineFriendSetting;
use Illuminate\Routing\Controller;

class AdminLineFriendSettingController extends Controller
{
    public function show(): LineFriendSettingResource
    {
        return new LineFriendSettingResource(LineFriendSetting::current());
    }

    public function update(UpdateLineFriendSettingRequest $request, AuditLogService $auditLogService): LineFriendSettingResource
    {
        $setting = LineFriendSetting::current();
        $payload = $request->validated();
        $before = $setting->only(array_keys($payload));

        $setting->fill($payload)->save();

        $auditLogService->record(
            action: 'admin.line_friend_setting.updated',
            adminUser: $request->user(),
            auditable: $setting,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $setting->only(array_keys($payload)),
            ],
        );

        return new LineFriendSettingResource($setting->refresh());
    }
}
