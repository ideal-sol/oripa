<?php

namespace App\Http\Controllers\Admin\Referral;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\UpdateReferralSettingRequest;
use App\Http\Resources\ReferralSettingResource;
use App\Models\ReferralSetting;
use Illuminate\Routing\Controller;

class AdminReferralSettingController extends Controller
{
    public function show(): ReferralSettingResource
    {
        return new ReferralSettingResource(ReferralSetting::current());
    }

    public function update(UpdateReferralSettingRequest $request, AuditLogService $auditLogService): ReferralSettingResource
    {
        $setting = ReferralSetting::current();
        $payload = $request->validated();
        $before = $setting->only(array_keys($payload));

        $setting->fill($payload)->save();

        $auditLogService->record(
            action: 'admin.referral_setting.updated',
            adminUser: $request->user(),
            auditable: $setting,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $setting->only(array_keys($payload)),
            ],
        );

        return new ReferralSettingResource($setting->refresh());
    }
}
