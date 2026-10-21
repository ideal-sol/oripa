<?php

namespace App\Domain\Draw\Services;

use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Catalog\Services\V2GachaPurchaseCondition;
use App\Domain\Line\Services\V2LineFriendStateService;
use App\Domain\MemberRank\Services\V2MemberRankGachaCondition;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2DrawEligibilityService
{
    public function __construct(private readonly V2LineFriendStateService $lineFriendState)
    {
    }

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
        int $firstTimeEligibleDays,
        int $dailyLimit,
        CarbonImmutable $occurredAt,
        ?object $version = null,
        ?array $memberRank = null,
        ?array $purchases = null
    ): array {
        $authenticated = $user instanceof User;
        $audienceEligible = $authenticated
            && $this->isAudienceEligible(
                $user,
                $audienceCode,
                $firstTimeEligibleDays,
                $occurredAt
            );
        $used = $authenticated
            ? $this->dailyUsage($user->id, $gachaId, $occurredAt)
            : null;
        $unlimited = $dailyLimit === 0;
        $remaining = $used === null
            ? null
            : ($unlimited ? null : max(0, $dailyLimit - $used));
        $memberRank ??= $authenticated ? app(V2MemberRankResolver::class)->project($user->id, $occurredAt) : null;
        $rankEligible = $memberRank !== null && V2MemberRankGachaCondition::matches($version ?? (object) [], $memberRank);
        $purchases ??= $authenticated ? V2GachaPurchaseCondition::aggregate($user->id) : null;
        $purchaseReason = $purchases === null ? null : V2GachaPurchaseCondition::reason($version ?? (object) [], $purchases);

        return [
            'authenticated' => $authenticated,
            'audience_code' => $audienceCode,
            'audience_eligible' => $audienceEligible,
            'ineligible_reason' => ! $authenticated
                ? 'authentication_required'
                : (! $audienceEligible ? 'audience_not_eligible' : ($rankEligible ? $purchaseReason : 'member_rank_not_eligible')),
            'purchase_progress' => V2GachaPurchaseCondition::progress($version ?? (object) [], $purchases),
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
    ): array {
        $lockedUser = DB::table('users')
            ->where('id', $user->id)
            ->lockForUpdate()
            ->first();
        $memberRank = app(V2MemberRankResolver::class)->materialize($user->id, $occurredAt);
        $snapshotLevel = $memberRank['enabled'] ? (int) $memberRank['level'] : null;
        $snapshot = ['member_rank_level' => $snapshotLevel, 'purchase_count_snapshot' => null, 'purchase_amount_snapshot' => null];
        if (in_array($gacha->gacha_type, ['login_daily', 'signup_once'], true)) {
            if ($lockedUser === null || $drawCount !== 1) {
                throw new V2DrawException('INVALID_DRAW_REQUEST', 422, 'A login Gacha permits one Draw.');
            }
            if ($lockedUser->state !== 'active') {
                throw new V2DrawException('GACHA_AUDIENCE_NOT_ELIGIBLE', 403, 'An active User is required.');
            }
            $eligibility = $this->evaluateLogin($lockedUser, $gacha, $version, $occurredAt, $memberRank);
            if (! $eligibility['eligible']) {
                if ($eligibility['reason'] === 'member_rank_not_eligible') {
                    throw new V2DrawException('GACHA_MEMBER_RANK_NOT_ELIGIBLE', 403, 'The member rank does not satisfy this Gacha condition.');
                }
                throw new V2DrawException(
                    $eligibility['reason'] === 'already_used' ? 'DAILY_DRAW_LIMIT_EXCEEDED' : 'GACHA_AUDIENCE_NOT_ELIGIBLE',
                    $eligibility['reason'] === 'already_used' ? 409 : 403,
                    'The user is not eligible for this login Gacha.'
                );
            }

            return $snapshot;
        }
        if (
            $lockedUser === null
            || ! $this->isAudienceEligible(
                $lockedUser,
                (string) $version->audience_code,
                (int) $version->first_time_eligible_days,
                $occurredAt
            )
        ) {
            throw new V2DrawException(
                'GACHA_AUDIENCE_NOT_ELIGIBLE',
                403,
                'The user is not eligible for this Gacha.'
            );
        }

        if (! V2MemberRankGachaCondition::matches($version, $memberRank)) {
            throw new V2DrawException('GACHA_MEMBER_RANK_NOT_ELIGIBLE', 403, 'The member rank does not satisfy this Gacha condition.');
        }
        $dailyLimit = (int) $version->daily_draw_limit;
        $purchases = V2GachaPurchaseCondition::aggregate($user->id);
        $purchaseReason = V2GachaPurchaseCondition::reason($version, $purchases);
        if ($purchaseReason !== null) {
            throw new V2DrawException('GACHA_'.strtoupper($purchaseReason), 403, 'The purchase history does not satisfy this Gacha condition.');
        }
        $snapshot['purchase_count_snapshot'] = $purchases['purchase_count'];
        $snapshot['purchase_amount_snapshot'] = $purchases['purchase_amount'];
        if ($dailyLimit === 0) {
            return $snapshot;
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

        return $snapshot;
    }

    public function evaluateLogin(?object $user, object $gacha, object $version, CarbonImmutable $occurredAt, ?array $memberRank = null): array
    {
        $bounds = $this->jstDayBounds($occurredAt);
        if ($user === null) {
            return ['eligible' => false, 'used' => false, 'reason' => 'authentication_required',
                'resets_at' => $gacha->gacha_type === 'login_daily' ? $bounds['end']->toIso8601ZuluString() : null];
        }
        $memberRank ??= app(V2MemberRankResolver::class)->project($user->id, $occurredAt);
        $usage = DB::table('draw_requests as request')
            ->join('gacha_draw_states as state', 'state.id', '=', 'request.gacha_draw_state_id')
            ->where('request.user_id', $user->id)
            ->where('request.status', 'completed')->where('request.is_qa_draw', false);
        if ($gacha->gacha_type === 'login_daily' && $memberRank['enabled']) {
            $usage->join('catalog_gachas as used_gacha', 'used_gacha.id', '=', 'state.gacha_id')
                ->where('used_gacha.gacha_type', 'login_daily');
        } else {
            $usage->where('state.gacha_id', $gacha->id);
        }
        if ($gacha->gacha_type === 'login_daily') {
            $usage->where('request.completed_at', '>=', $bounds['start']->toIso8601String())
                ->where('request.completed_at', '<', $bounds['end']->toIso8601String());
        }
        $used = $usage->exists();
        $qualifiedAt = $gacha->gacha_type === 'signup_once'
            ? app(\App\Domain\Identity\Services\V2RegistrationQualification::class)->origin($user, 'first_registration_qualified_at')
            : null;
        $qualified = $gacha->gacha_type === 'login_daily'
            || ($gacha->gacha_type === 'signup_once' && $qualifiedAt !== null
                && $qualifiedAt->greaterThanOrEqualTo(CarbonImmutable::parse($version->publish_start_at)));

        $rankEligible = V2MemberRankGachaCondition::matches($version, $memberRank);

        return [
            'eligible' => $qualified && $rankEligible && ! $used,
            'used' => $used,
            'reason' => ! $qualified ? 'registration_not_qualified'
                : (! $rankEligible ? 'member_rank_not_eligible' : ($used ? 'already_used' : null)),
            'resets_at' => $gacha->gacha_type === 'login_daily' ? $bounds['end']->toIso8601ZuluString() : null,
        ];
    }

    private function isAudienceEligible(
        object $user,
        string $audienceCode,
        int $firstTimeEligibleDays,
        CarbonImmutable $occurredAt
    ): bool
    {
        return match ($audienceCode) {
            'all_users' => true,
            'first_time_users' => $this->isWithinRegistrationWindow(
                $user,
                $firstTimeEligibleDays,
                $occurredAt
            ),
            'line_users' => $this->lineFriendState->isLineUser((int) $user->id),
            default => false,
        };
    }

    private function isWithinRegistrationWindow(
        object $user,
        int $eligibleDays,
        CarbonImmutable $occurredAt
    ): bool
    {
        if ($eligibleDays < 1 || ! isset($user->created_at)) {
            return false;
        }

        $registeredAt = CarbonImmutable::parse((string) $user->created_at);

        return $occurredAt->greaterThanOrEqualTo($registeredAt)
            && $occurredAt->lessThanOrEqualTo($registeredAt->addDays($eligibleDays));
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

}
