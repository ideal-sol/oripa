<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankPaymentAdjustmentService
{
    public function __construct(
        private readonly V2MemberRankResolver $resolver,
        private readonly V2MemberRankSettingsService $settings,
        private readonly V2MemberRankCorrectionService $corrections,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function context(object $payment): array
    {
        $query = DB::table('member_rank_settings')->where('id', 1);
        $setting = (DB::transactionLevel() > 0 ? $query->sharedLock() : $query)->firstOrFail();
        $grant = DB::table('payment_point_grants')->where('payment_id', $payment->id)->firstOrFail();
        $entry = DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->where('reason', 'payment')->first();
        $user = DB::table('users')->where('id', $payment->user_id)->firstOrFail();
        $state = DB::table('member_rank_states')->where('user_id', $payment->user_id)->first();
        $grantedAt = CarbonImmutable::parse($grant->granted_at)->utc();
        $eligible = app(V2MemberRankPaymentOriginPolicy::class)->eligible((int) $payment->id);
        if ($eligible === false && ($entry !== null
            || DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->exists())) {
            throw new V2PaymentException('PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        }
        $closedAccount = DB::table('account_closures')->where('user_id', $payment->user_id)->exists();
        if ($entry !== null && (! $setting->enabled || $state === null
            || (! $closedAccount && ($state->frozen_at !== null || in_array($user->state, ['closed', 'anonymized'], true))))) {
            throw new V2PaymentException('PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        }
        if ($entry === null && (($setting->enabled && $setting->activated_at !== null && ($eligible === true
                || ($eligible === null && $grantedAt->greaterThanOrEqualTo(CarbonImmutable::parse($setting->activated_at)->setTimezone('Asia/Tokyo')->startOfMonth()))))
            || DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->where('bonus_amount', '>', 0)->exists())) {
            throw new V2PaymentException('PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        }
        if ($entry !== null && ((int) $entry->user_id !== (int) $payment->user_id
            || (int) $entry->applied_delta !== (int) $payment->amount
            || ! $grantedAt->equalTo(CarbonImmutable::parse($entry->granted_at)))) {
            throw new V2PaymentException('PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        }

        return ['entry' => $entry, 'state' => $state, 'granted_at' => $grantedAt,
            'month' => $grantedAt->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString()];
    }

    public function lock(object $payment): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Rank adjustment locking requires the owning transaction.');
        }
        $context = $this->context($payment);
        if ($context['entry'] === null) {
            return;
        }
        DB::table('member_rank_states')->where('user_id', $payment->user_id)->lockForUpdate()->firstOrFail();
        DB::table('member_rank_month_totals')->where('user_id', $payment->user_id)
            ->where('month', $context['month'])->lockForUpdate()->firstOrFail();
        DB::table('member_rank_entitlements')->where('user_id', $payment->user_id)->orderBy('level')->lockForUpdate()->get();
    }

    public function preview(object $payment, CarbonImmutable $instant, int $freeDebit = 0): array
    {
        $context = $this->context($payment);
        $result = ['month' => $context['month'], 'effect' => 'none', 'requested_points' => 0,
            'applied_points' => 0, 'before_rank' => null, 'after_rank' => null, 'recoveries' => []];
        if ($context['entry'] === null) {
            return $result;
        }
        $total = (int) DB::table('member_rank_month_totals')->where('user_id', $payment->user_id)
            ->where('month', $context['month'])->value('points');
        $current = $context['month'] === $instant->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString();
        $projection = $this->resolver->project((int) $payment->user_id, $instant);
        $level = $projection['level'];
        $applied = min((int) $payment->amount, max(0, $total));
        $result = [...$result, 'effect' => $current ? 'current_month' : 'past_month',
            'requested_points' => (int) $payment->amount, 'applied_points' => $applied,
            'before_rank' => $this->code($level), 'after_rank' => $this->code($level)];
        if (! $current || $applied === 0) {
            return $result;
        }
        $version = $this->settings->effectiveAt($instant->setTimezone('Asia/Tokyo')->startOfMonth());
        $after = $total - $applied;
        if ($context['state']->frozen_at === null) {
            $result['after_rank'] = $this->code(min($level, max($projection['baseline_level'], $this->corrections->reachedLevel($after, $version))));
        }
        $available = max(0, $this->corrections->spendableFree((int) $payment->user_id, $instant) - $freeDebit);
        foreach ($this->corrections->recoverable((int) $payment->user_id, $context['month'], $after) as $achievement) {
            $amount = (int) $achievement->bonus_amount;
            $recovered = min($amount, $available);
            $available -= $recovered;
            $result['recoveries'][] = ['rank' => $this->code((int) $achievement->level), 'requested_amount' => $amount,
                'recovered_amount' => $recovered, 'shortfall_amount' => $amount - $recovered];
        }

        return $result;
    }

    public function apply(object $payment, object $adjustment, array $bonus, CarbonImmutable $instant, ?int $adminId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Rank adjustment requires the payment transaction.');
        }
        $context = $this->context($payment);
        $plan = $this->preview($payment, $instant);
        $entryId = null;
        if ($context['entry'] !== null) {
            $current = $plan['effect'] === 'current_month';
            $revision = (int) $context['state']->revision;
            if ($current) {
                $this->resolver->materialize((int) $payment->user_id, $instant);
            }
            $entryId = DB::table('member_rank_point_entries')->insertGetId([
                'user_id' => $payment->user_id, 'month' => $context['month'], 'reason' => 'payment_adjustment',
                'adjustment_id' => $adjustment->id, 'origin_payment_id' => $payment->id,
                'requested_delta' => -$plan['requested_points'], 'applied_delta' => -$plan['applied_points'],
                'admin_id' => $adminId,
                'granted_at' => V2DatabaseTimestamp::format($context['granted_at']),
                'recorded_at' => V2DatabaseTimestamp::format($instant),
            ]);
            DB::table('member_rank_month_totals')->where('user_id', $payment->user_id)->where('month', $context['month'])
                ->decrement('points', $plan['applied_points']);
            if ($current && $plan['applied_points'] > 0) {
                $this->corrections->applyDecrease((int) $payment->user_id, $entryId,
                    $this->settings->effectiveAt($instant->setTimezone('Asia/Tokyo')->startOfMonth()), $instant);
                if ($context['state']->frozen_at === null) {
                    DB::table('member_rank_states')->where('user_id', $payment->user_id)->update([
                        'revision' => $revision + 1, 'updated_at' => V2DatabaseTimestamp::format($instant),
                    ]);
                }
            }
        }
        DB::table('member_rank_payment_adjustments')->insert([
            'public_id' => (string) Str::uuid7(), 'adjustment_id' => $adjustment->id, 'payment_id' => $payment->id,
            'user_id' => $payment->user_id, 'point_entry_id' => $entryId, 'month' => $context['month'],
            'point_operation_id' => DB::table('payment_adjustment_point_operations')->where('payment_adjustment_id', $adjustment->id)->sole()->point_operation_id,
            'effect' => $plan['effect'], 'requested_points' => $plan['requested_points'], 'applied_points' => $plan['applied_points'],
            'before_level' => $plan['before_rank'] === null ? null : array_search($plan['before_rank'], V2MemberRankSettingsService::CODES, true) + 1,
            'after_level' => $plan['after_rank'] === null ? null : array_search($plan['after_rank'], V2MemberRankSettingsService::CODES, true) + 1,
            'purchase_bonus_required' => $bonus['required_amount'], 'purchase_bonus_reversed' => $bonus['reversed_amount'],
            'purchase_bonus_shortfall' => $bonus['shortfall_amount'], 'occurred_at' => V2DatabaseTimestamp::format($instant),
        ]);
        $this->audit->record('member_rank.payment.adjusted', [
            'actor_type' => $adminId === null ? 'system' : 'admin',
            'actor_public_id' => $adminId === null ? null
                : DB::table('admins')->where('id', $adminId)->value('public_id'),
            'target_type' => 'payment_adjustment', 'target_public_id' => $adjustment->public_id,
            'metadata' => ['payment_id' => $payment->public_id, 'month' => $plan['month'], 'effect' => $plan['effect'],
                'requested_points' => $plan['requested_points'], 'applied_points' => $plan['applied_points'],
                'purchase_bonus_shortfall' => $bonus['shortfall_amount']],
        ]);
    }

    public function present(int $adjustmentId): array
    {
        $row = DB::table('member_rank_payment_adjustments')->where('adjustment_id', $adjustmentId)->first();
        if ($row === null) {
            return [];
        }
        $recoveries = $row->point_entry_id === null ? [] : DB::table('member_rank_bonus_recoveries as recovery')
            ->join('member_rank_achievements as achievement', 'achievement.id', '=', 'recovery.achievement_id')
            ->where('recovery.point_entry_id', $row->point_entry_id)->orderBy('achievement.level')
            ->get(['achievement.level', 'recovery.requested_amount', 'recovery.recovered_amount', 'recovery.shortfall_amount'])
            ->map(fn (object $recovery): array => ['rank' => $this->code((int) $recovery->level),
                'requested_amount' => (int) $recovery->requested_amount, 'recovered_amount' => (int) $recovery->recovered_amount,
                'shortfall_amount' => (int) $recovery->shortfall_amount])->all();

        return ['purchase_bonus' => ['required_amount' => (int) $row->purchase_bonus_required,
            'reversed_amount' => (int) $row->purchase_bonus_reversed, 'shortfall_amount' => (int) $row->purchase_bonus_shortfall],
            'rank_adjustment' => ['month' => $row->month, 'effect' => $row->effect,
                'requested_points' => (int) $row->requested_points, 'applied_points' => (int) $row->applied_points,
                'before_rank' => $row->before_level === null ? null : $this->code((int) $row->before_level),
                'after_rank' => $row->after_level === null ? null : $this->code((int) $row->after_level),
                'recoveries' => $recoveries]];
    }

    private function code(int $level): string
    {
        return V2MemberRankSettingsService::CODES[$level - 1];
    }
}
