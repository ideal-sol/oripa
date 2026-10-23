<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2MemberRankPurchaseBonusService
{
    public function __construct(
        private readonly V2MemberRankResolver $resolver,
        private readonly V2MemberRankSettingsService $settings,
        private readonly V2PointService $points
    ) {
    }

    public static function calculate(int $amount, int $rateBasisPoints): int
    {
        if ($amount < 0 || $rateBasisPoints < 0 || $rateBasisPoints > 10000) {
            throw new \InvalidArgumentException('Member rank purchase amount or rate is invalid.');
        }

        return intdiv($amount, 10000) * $rateBasisPoints
            + intdiv(($amount % 10000) * $rateBasisPoints, 10000);
    }

    public function grantForPayment(int $paymentId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Member rank purchase requires the payment transaction.');
        }
        $setting = DB::table('member_rank_settings')->where('id', 1)->firstOrFail();
        if (! $setting->enabled) {
            return;
        }
        $eligible = app(V2MemberRankPaymentOriginPolicy::class)->eligible($paymentId);
        if ($eligible === false) {
            return;
        }
        $payment = DB::table('payments')->where('id', $paymentId)->firstOrFail();
        $grant = DB::table('payment_point_grants')->where('payment_id', $paymentId)->firstOrFail();
        $instant = CarbonImmutable::parse($grant->granted_at)->utc();
        if ($payment->status !== 'succeeded'
            || ($eligible === null && $instant->lessThan(CarbonImmutable::parse($setting->activated_at)))) {
            throw new \LogicException('Member rank purchase requires a current succeeded payment grant.');
        }
        if (DB::table('member_rank_purchase_bonuses')->where('payment_id', $paymentId)->exists()) {
            return;
        }
        if (DB::table('member_rank_point_entries')->where('payment_id', $paymentId)->exists()) {
            throw new \LogicException('Member rank purchase must precede payment rank accounting.');
        }
        $projection = $this->resolver->materialize((int) $payment->user_id, $instant);
        $version = $this->settings->effectiveAt($instant, (int) $setting->revision);
        $rank = $version['ranks'][$projection['level'] - 1];
        $amount = self::calculate((int) $payment->amount, $rank['purchase_bonus_rate_bp']);
        $operation = $this->points->grantMemberRankPurchase(
            (int) $payment->user_id, $paymentId, $payment->public_id, $amount, $instant
        );
        DB::table('member_rank_purchase_bonuses')->insert([
            'payment_id' => $paymentId, 'user_id' => $payment->user_id,
            'rank_level' => $projection['level'], 'rank_code' => $rank['code'],
            'rate_basis_points' => $rank['purchase_bonus_rate_bp'], 'paid_amount' => $payment->amount,
            'bonus_amount' => $amount, 'month' => $instant->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString(),
            'setting_revision' => $version['revision'], 'point_operation_id' => $operation?->id,
            'granted_at' => V2DatabaseTimestamp::format($instant),
        ]);
    }

    public function estimateContext(?User $user, CarbonImmutable $instant): ?array
    {
        if ($user === null) {
            return null;
        }
        $setting = DB::table('member_rank_settings')->where('id', 1)->firstOrFail();
        if (! $setting->enabled) {
            return null;
        }
        $projection = $this->resolver->project((int) $user->id, $instant);
        $version = $this->settings->effectiveAt($instant, (int) $setting->revision);
        $rank = $version['ranks'][$projection['level'] - 1];

        return [
            'rank_code' => $rank['code'], 'rank_level' => $projection['level'],
            'rate_basis_points' => $rank['purchase_bonus_rate_bp'], 'point_type' => 'free',
            'is_estimate' => true, 'as_of' => $instant->toIso8601ZuluString(),
            'label' => '購入ボーナス（無償）',
            'notice' => 'お支払い完了時のランクで付与されます。表示は見積りであり、付与額を保証するものではありません。',
        ];
    }

    public function present(int $paymentId): ?array
    {
        $row = DB::table('member_rank_purchase_bonuses as bonus')
            ->leftJoin('point_operations as operation', 'operation.id', '=', 'bonus.point_operation_id')
            ->where('bonus.payment_id', $paymentId)->select('bonus.*', 'operation.public_id as operation_public_id')->first();
        if ($row === null) {
            return null;
        }

        return [
            'rank_code' => $row->rank_code, 'rank_level' => (int) $row->rank_level,
            'rate_basis_points' => (int) $row->rate_basis_points, 'bonus_points' => (int) $row->bonus_amount,
            'point_type' => 'free', 'operation_id' => $row->operation_public_id,
            'granted_at' => CarbonImmutable::parse($row->granted_at)->utc()->toIso8601ZuluString(),
            'month' => substr($row->month, 0, 7),
        ];
    }
}
