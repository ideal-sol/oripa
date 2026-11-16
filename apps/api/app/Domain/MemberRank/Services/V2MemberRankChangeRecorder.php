<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Outbox\Services\V2OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankChangeRecorder
{
    /** Append the actual history and its event in the owning domain transaction. */
    public function insert(array $attributes): int
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Rank history requires the owning transaction.');
        $id = DB::table('member_rank_changes')->insertGetId($attributes);
        if ((int) $attributes['to_level'] <= (int) $attributes['from_level']) return $id;
        $publicId = (string) Str::uuid();
        DB::table('member_rank_promotion_identities')->insert(['change_id' => $id, 'public_id' => $publicId]);
        $user = DB::table('users')->where('id', $attributes['user_id'])->firstOrFail();
        app(V2OutboxService::class)->enqueue('member_rank.events', 'member_rank_change', $publicId, 'member_rank.promoted', [
            'user_public_id' => $user->public_id,
            'from_rank_code' => V2MemberRankSettingsService::CODES[(int) $attributes['from_level'] - 1],
            'to_rank_code' => V2MemberRankSettingsService::CODES[(int) $attributes['to_level'] - 1],
            'change_public_id' => $publicId,
        ], 'member_rank.promoted:'.$publicId);
        return $id;
    }
}
