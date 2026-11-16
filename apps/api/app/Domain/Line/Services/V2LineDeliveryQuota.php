<?php

namespace App\Domain\Line\Services;

use Illuminate\Support\Facades\DB;

final class V2LineDeliveryQuota
{
    /** Deliberate over-reservation covers consumption-report lag and ambiguous attempts. */
    public function reserved(int $botId): int
    {
        $active = (int) DB::table('line_deliveries')->where('line_bot_id', $botId)->where('status', 'sending')->sum('recipient_count');
        $possiblyUsed = DB::table('line_delivery_recipients as r')->join('line_delivery_batches as b', 'b.id', '=', 'r.batch_id')
            ->leftJoin('line_deliveries as d', 'd.id', '=', 'b.delivery_id')->where('b.line_bot_id', $botId)
            ->whereRaw("b.first_attempted_at >= (date_trunc('month', clock_timestamp() AT TIME ZONE 'Asia/Tokyo') AT TIME ZONE 'Asia/Tokyo')")
            ->where(fn ($q) => $q->whereNull('d.id')->orWhere('d.status', '<>', 'sending'))->count();
        return $active + $possiblyUsed;
    }

    /** No cache authority: caller reserves under the singleton after this fresh read. */
    public function remaining(#[\SensitiveParameter] object $bot): ?int
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Quota must be read outside transactions.');
        $transport = app(V2LineBotTransport::class);
        $quota = $transport->get($bot, '/v2/bot/message/quota');
        if (($quota['type'] ?? null) === 'none') return null;
        $used = $transport->get($bot, '/v2/bot/message/quota/consumption');
        if (($quota['type'] ?? null) !== 'limited' || ! is_int($quota['value'] ?? null) || $quota['value'] < 0
            || ! is_int($used['totalUsage'] ?? null) || $used['totalUsage'] < 0) throw new \RuntimeException('LINE quota unavailable.');
        return max(0, $quota['value'] - $used['totalUsage']);
    }
}
