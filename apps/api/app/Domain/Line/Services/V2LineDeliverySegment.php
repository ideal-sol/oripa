<?php

namespace App\Domain\Line\Services;

use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\Payment\V2\Services\V2PaymentCommercialQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2LineDeliverySegment
{
    public const KEYS = ['rank_levels', 'purchase_min', 'purchase_max', 'points_min', 'points_max',
        'purchase_within_days', 'purchase_absent_days', 'registered_from', 'registered_to', 'followed_from', 'followed_to'];

    public function normalize(array $input): array
    {
        if (array_diff(array_keys($input), self::KEYS)) $this->invalid();
        $out = [];
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $input)) continue;
            $v = $input[$key];
            if ($key === 'rank_levels') {
                if (! is_array($v) || ! array_is_list($v) || count($v) < 1 || count($v) > 7) $this->invalid();
                foreach ($v as $level) if (! is_int($level) || $level < 1 || $level > 7) $this->invalid();
                $v = array_values(array_unique($v)); sort($v);
            } elseif (str_ends_with($key, '_from') || str_ends_with($key, '_to')) {
                if (! is_string($v) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $v)) $this->invalid();
                [$y, $m, $d] = array_map('intval', explode('-', $v));
                if (! checkdate($m, $d, $y)) $this->invalid();
            } elseif (! is_int($v) || $v < 0 || $v > (str_ends_with($key, '_days') ? 36500 : 9007199254740991)) $this->invalid();
            $out[$key] = $v;
        }
        foreach ([['purchase_min', 'purchase_max'], ['points_min', 'points_max'], ['registered_from', 'registered_to'], ['followed_from', 'followed_to']] as [$min, $max]) {
            if (isset($out[$min], $out[$max]) && $out[$min] > $out[$max]) $this->invalid();
        }
        if (isset($out['purchase_within_days'], $out['purchase_absent_days'])) $this->invalid();
        return $out;
    }

    /** At most one D2 page. Same predicate used by preview and actual resolution. */
    public function filter(array $pairs, array $segment, CarbonImmutable $now): array
    {
        $s = $this->normalize($segment);
        if ($s === [] || $pairs === []) return $pairs;
        $ids = array_values(array_unique(array_filter(array_map(fn ($p) => $p[0]->userId, $pairs))));
        $users = DB::table('users')->whereIn('id', $ids)->get()->keyBy('id');
        $wallets = DB::table('wallets')->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $ranks = isset($s['rank_levels']) ? app(V2MemberRankResolver::class)->projectMany($ids, $now) : [];
        $adjustments = DB::table('payment_adjustments')->where('status', 'succeeded')
            ->whereIn('payment_id', fn ($q) => $q->select('id')->from('payments')->whereIn('user_id', $ids))
            ->whereIn('type', ['refund', 'chargeback', 'chargeback_reversal'])
            ->selectRaw("payment_id, SUM(CASE WHEN type = 'chargeback_reversal' THEN -amount ELSE amount END) AS reversed")->groupBy('payment_id');
        $purchases = DB::table('payments as p')->whereIn('p.user_id', $ids)
            ->tap(fn ($q) => V2PaymentCommercialQuery::apply($q, 'p.id'))
            ->join('payment_point_grants as g', 'g.payment_id', '=', 'p.id')
            ->leftJoinSub($adjustments, 'a', 'a.payment_id', '=', 'p.id')
            ->selectRaw('p.user_id, SUM(GREATEST(0, p.amount - COALESCE(a.reversed, 0))) AS net, MAX(p.succeeded_at) AS last_purchase')
            ->groupBy('p.user_id')->get()->keyBy('user_id');
        $memberConditions = array_diff(array_keys($s), ['followed_from', 'followed_to']);
        return array_values(array_filter($pairs, function ($pair) use ($s, $memberConditions, $users, $wallets, $ranks, $purchases, $now): bool {
            [$ref, $binding] = $pair;
            $user = $ref->userId === null ? null : $users->get($ref->userId);
            if ($memberConditions !== [] && $user === null) return false;
            if (isset($s['rank_levels']) && (! ($ranks[$ref->userId]['enabled'] ?? false)
                || ! in_array($ranks[$ref->userId]['level'], $s['rank_levels'], true))) return false;
            $wallet = $wallets->get($ref->userId); $purchase = $purchases->get($ref->userId);
            foreach (['purchase' => (int) ($purchase?->net ?? 0), 'points' => (int) ($wallet?->paid_balance ?? 0) + (int) ($wallet?->free_balance ?? 0)] as $key => $value) {
                if (isset($s[$key.'_min']) && $value < $s[$key.'_min']) return false;
                if (isset($s[$key.'_max']) && $value > $s[$key.'_max']) return false;
            }
            foreach (['registered' => $user?->created_at, 'followed' => $ref->source === 'line_recovered_friends' ? null : ($binding->followed_at ?? null)] as $key => $date) {
                if (! isset($s[$key.'_from']) && ! isset($s[$key.'_to'])) continue;
                if ($date === null) return false;
                $day = CarbonImmutable::parse($date)->setTimezone('Asia/Tokyo')->toDateString();
                if (isset($s[$key.'_from']) && $day < $s[$key.'_from']) return false;
                if (isset($s[$key.'_to']) && $day > $s[$key.'_to']) return false;
            }
            $last = $purchase?->last_purchase === null ? null : CarbonImmutable::parse($purchase->last_purchase);
            if (isset($s['purchase_within_days']) && ($last === null || $last->lt($now->subDays($s['purchase_within_days'])))) return false;
            if (isset($s['purchase_absent_days']) && $last !== null && $last->gt($now->subDays($s['purchase_absent_days']))) return false;
            return true;
        }));
    }

    private function invalid(): never { throw new \InvalidArgumentException('LINE_SEGMENT_INVALID'); }
}
