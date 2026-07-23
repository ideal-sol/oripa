<?php

namespace App\Domain\Line\Services;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Services\PointLotService;
use App\Models\LineFriendLink;
use App\Models\LineFriendLinkEvent;
use App\Models\LineFriendSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LineFriendLinkService
{
    public function __construct(private readonly PointLotService $pointLotService)
    {
    }

    public function handleEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->handleEvent($event);
        }
    }

    private function handleEvent(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $lineUserId = (string) data_get($event, 'source.userId', '');

        if ($lineUserId === '') {
            $this->recordEvent(null, null, $type, null, 'ignored', $event);

            return;
        }

        if ($type === 'follow') {
            $this->handleFollow($lineUserId, $event);

            return;
        }

        if ($type === 'unfollow') {
            $this->handleUnfollow($lineUserId, $event);

            return;
        }

        if ($type === 'message' && data_get($event, 'message.type') === 'text') {
            $this->handleCodeMessage($lineUserId, trim((string) data_get($event, 'message.text')), $event);

            return;
        }

        $this->recordEvent(null, $lineUserId, $type, null, 'ignored', $event);
    }

    private function handleFollow(string $lineUserId, array $event): void
    {
        DB::transaction(function () use ($lineUserId, $event): void {
            $link = LineFriendLink::query()->firstOrNew(['line_user_id' => $lineUserId]);
            $link->forceFill([
                'status' => $link->user_id ? 'linked' : 'friend',
                'followed_at' => $link->followed_at ?? now(),
                'blocked_at' => null,
            ])->save();

            $this->recordEvent($link, $lineUserId, 'follow', null, 'received', $event);
        });
    }

    private function handleUnfollow(string $lineUserId, array $event): void
    {
        DB::transaction(function () use ($lineUserId, $event): void {
            $link = LineFriendLink::query()->firstOrNew(['line_user_id' => $lineUserId]);
            $link->forceFill([
                'status' => 'blocked',
                'blocked_at' => now(),
            ])->save();

            $this->recordEvent($link, $lineUserId, 'unfollow', null, 'received', $event);
        });
    }

    private function handleCodeMessage(string $lineUserId, string $code, array $event): void
    {
        DB::transaction(function () use ($lineUserId, $code, $event): void {
            $normalizedCode = strtoupper($code);
            $link = LineFriendLink::query()->where('line_user_id', $lineUserId)->lockForUpdate()->first();

            if ($link?->user_id) {
                $this->recordEvent($link, $lineUserId, 'message', $code, 'ignored', [
                    ...$event,
                    'reason' => 'line_user_already_linked',
                ]);

                return;
            }

            $user = User::query()
                ->where('line_link_code', $normalizedCode)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                $this->recordEvent($link, $lineUserId, 'message', $code, 'failed', [
                    ...$event,
                    'reason' => 'invalid_code',
                ]);

                return;
            }

            if ($user->line_linked_at || $user->line_user_id) {
                $this->recordEvent($link, $lineUserId, 'message', $code, 'failed', [
                    ...$event,
                    'reason' => 'user_already_linked',
                ]);

                return;
            }

            $setting = LineFriendSetting::current();
            $link ??= LineFriendLink::query()->create([
                'line_user_id' => $lineUserId,
                'status' => 'friend',
                'followed_at' => now(),
            ]);

            $link->forceFill([
                'user_id' => $user->id,
                'status' => 'linked',
                'link_code' => $normalizedCode,
                'reward_point_amount' => $setting->is_active ? (int) $setting->reward_point_amount : 0,
                'linked_at' => now(),
                'blocked_at' => null,
            ])->save();

            $user->forceFill([
                'line_user_id' => $lineUserId,
                'line_linked_at' => now(),
            ])->save();

            if ($setting->is_active && (int) $setting->reward_point_amount > 0 && ! $link->rewarded_at) {
                $expireDays = $setting->reward_expiration_days ?? (int) config('oripa.free_point_expiration_days', 180);

                $this->pointLotService->grantFree(
                    user: $user,
                    amount: (int) $setting->reward_point_amount,
                    expireAt: now()->addDays((int) $expireDays),
                    sourceType: PointLotSourceType::LineFriend,
                    sourceId: $link->id,
                    ledgerType: PointLedgerType::Grant,
                    relatedType: 'line_friend_link',
                    relatedId: $link->id,
                    description: 'LINE friend link reward.',
                );

                $link->forceFill(['rewarded_at' => now()])->save();
            }

            $this->recordEvent($link, $lineUserId, 'message', $code, 'linked', $event);
        });
    }

    private function recordEvent(?LineFriendLink $link, ?string $lineUserId, string $eventType, ?string $messageText, string $status, array $metadata): LineFriendLinkEvent
    {
        return LineFriendLinkEvent::query()->create([
            'user_id' => $link?->user_id,
            'line_friend_link_id' => $link?->id,
            'line_user_id' => $lineUserId,
            'event_type' => $eventType,
            'message_text' => $messageText,
            'status' => $status,
            'metadata' => $metadata,
        ]);
    }
}
