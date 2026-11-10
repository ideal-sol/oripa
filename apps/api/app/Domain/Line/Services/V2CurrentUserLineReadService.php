<?php

namespace App\Domain\Line\Services;

use App\Models\V2\LineMessagingSetting;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;

final class V2CurrentUserLineReadService
{
    public function __construct(private readonly V2LineFriendStateService $friendState)
    {
    }

    /** @return array<string, mixed> */
    public function presentation(User $user): array
    {
        $state = $this->friendState->forUser((int) $user->getKey());

        if (! $state['linked']) {
            $status = ['code' => 'not_linked', 'label' => 'LINE未連携'];
            $action = [
                'code' => 'start_identity_link',
                'label' => 'LINEを連携する',
                'href' => null,
            ];
        } elseif (! $state['friend_confirmed']) {
            $friendAddUrl = LineMessagingSetting::query()->whereKey(1)->value('friend_add_url');
            $hasFriendAddUrl = is_string($friendAddUrl) && $friendAddUrl !== '';
            $status = ['code' => 'friend_add_required', 'label' => '友だち追加未確認'];
            $action = [
                'code' => $hasFriendAddUrl ? 'open_friend_add_url' : 'none',
                'label' => $hasFriendAddUrl ? 'LINE公式アカウントを友だち追加する' : null,
                'href' => $hasFriendAddUrl ? $friendAddUrl : null,
            ];
        } else {
            $status = ['code' => 'confirmed', 'label' => 'LINEユーザー'];
            $action = ['code' => 'none', 'label' => null, 'href' => null];
        }

        $setting = LineMessagingSetting::query()->find(1);
        $enabled = (bool) $setting?->reward_enabled;
        $granted = DB::table('line_friendships')->where('user_id', $user->id)->whereNotNull('point_operation_id')->exists();
        $recheck = (bool) config('v2_line.friendship_check_enabled', false) && $state['linked']
            && ($status['code'] === 'friend_add_required' || ($enabled && ! $granted));
        return [...$state, 'status' => $status, 'primary_action' => $action,
            'reward' => ['enabled' => $enabled, 'point_amount' => $enabled ? (int) $setting->reward_point_amount : null, 'granted' => $granted],
            'secondary_action' => $recheck ? ['code' => 'start_friendship_recheck', 'label' => '友だち追加を確認する', 'href' => null] : null,
        ];
    }
}
