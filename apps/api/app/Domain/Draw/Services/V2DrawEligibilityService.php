<?php

namespace App\Domain\Draw\Services;

use App\Domain\Draw\Exceptions\V2DrawException;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2DrawEligibilityService
{
    /**
     * @return array{
     *   authenticated: bool,
     *   audience_code: string,
     *   audience_eligible: bool,
     *   ineligible_reason: ?string,
     *   daily: array{
     *     limit: int,
     *     unlimited: bool,
     *     used: ?int,
     *     remaining: ?int,
     *     resets_at: string
     *   }
     * }
     */
    public function evaluate(
        ?User $user,
        int $gachaId,
        string $audienceCode,
        int $dailyLimit,
        CarbonImmutable $occurredAt
    ): array {
        $authenticated = $user instanceof User;
        $audienceEligible = $authenticated
            && $this->isAudienceEligible($user->id, $audienceCode);
        $used = $authenticated
            ? $this->dailyUsage($user->id, $gachaId, $occurredAt)
            : null;
        $unlimited = $dailyLimit === 0;
        $remaining = $used === null
            ? null
            : ($unlimited ? null : max(0, $dailyLimit - $used));

        return [
            'authenticated' => $authenticated,
            'audience_code' => $audienceCode,
            'audience_eligible' => $audienceEligible,
            'ineligible_reason' => ! $authenticated
                ? 'authentication_required'
                : ($audienceEligible ? null : 'audience_not_eligible'),
            'daily' => [
                'limit' => $dailyLimit,
                'unlimited' => $unlimited,
                'used' => $used,
                'remaining' => $remaining,
                'resets_at' => $this->jstDayBounds($occurredAt)['end']
                    ->toIso8601ZuluString(),
            ],
        ];
    }

    public function assertForDraw(
        User $user,
        object $gacha,
        object $version,
        int $drawCount,
        CarbonImmutable $occurredAt
    ): void {
        $lockedUser = DB::table('users')
            ->where('id', $user->id)
            ->lockForUpdate()
            ->first();
        if (
            $lockedUser === null
            || ! $this->isAudienceEligible($user->id, (string) $version->audience_code)
        ) {
            throw new V2DrawException(
                'GACHA_AUDIENCE_NOT_ELIGIBLE',
                403,
                'The user is not eligible for this Gacha.'
            );
        }

        $dailyLimit = (int) $version->daily_draw_limit;
        if ($dailyLimit === 0) {
            return;
        }
        if ($dailyLimit < 0) {
            throw new V2DrawException(
                'GACHA_NOT_DRAWABLE',
                409,
                'The requested Gacha has an invalid daily Draw limit.'
            );
        }

        $used = $this->dailyUsage($user->id, (int) $gacha->id, $occurredAt);
        if ($used > $dailyLimit - $drawCount) {
            throw new V2DrawException(
                'DAILY_DRAW_LIMIT_EXCEEDED',
                409,
                'The daily Draw limit would be exceeded.'
            );
        }
    }

    private function isAudienceEligible(int $userId, string $audienceCode): bool
    {
        return match ($audienceCode) {
            'all_users' => true,
            'first_time_users' => ! DB::table('draw_requests')
                ->where('user_id', $userId)
                ->where('status', 'completed')
                ->where('is_qa_draw', false)
                ->exists(),
            'line_users' => $this->isConfirmedLineFriend($userId),
            default => false,
        };
    }

    private function dailyUsage(
        int $userId,
        int $gachaId,
        CarbonImmutable $occurredAt
    ): int {
        $bounds = $this->jstDayBounds($occurredAt);

        return (int) DB::table('draw_requests as request')
            ->join(
                'gacha_draw_states as state',
                'state.id',
                '=',
                'request.gacha_draw_state_id'
            )
            ->where('request.user_id', $userId)
            ->where('state.gacha_id', $gachaId)
            ->where('request.status', 'completed')
            ->where('request.is_qa_draw', false)
            ->whereRaw(
                'request.completed_at >= ?::timestamptz',
                [$bounds['start']->toIso8601String()]
            )
            ->whereRaw(
                'request.completed_at < ?::timestamptz',
                [$bounds['end']->toIso8601String()]
            )
            ->sum('request.executed_count');
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function jstDayBounds(CarbonImmutable $occurredAt): array
    {
        $start = $occurredAt->setTimezone('Asia/Tokyo')->startOfDay()->utc();

        return ['start' => $start, 'end' => $start->addDay()];
    }

    private function isConfirmedLineFriend(int $userId): bool
    {
        return DB::table('external_identity_accounts as identity')
            ->join('line_friendships as friendship', function ($join): void {
                $join->on('friendship.user_id', '=', 'identity.user_id')
                    ->on('friendship.subject_hash', '=', 'identity.subject_hash');
            })
            ->where('identity.user_id', $userId)
            ->where('identity.provider', 'line')
            ->where('identity.issuer', 'https://access.line.me')
            ->whereNull('identity.revoked_at')
            ->where('friendship.status', 'friend')
            ->whereNull('friendship.unfollowed_at')
            ->exists();
    }
}
