<?php

namespace App\Domain\Line\Services;

use App\Domain\Line\Enums\V2LineDeliveryPurpose;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\OutboxMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class V2LineAutomatedWorker
{
    private const SOURCES = [
        ['payment.lifecycle', 'payment.succeeded'],
        ['shipping.status', 'shipping.status.changed'],
        ['member_rank.events', 'member_rank.promoted'],
    ];

    public function run(string $worker, int $limit = 10): int
    {
        if (! config('v2_line.automated_worker_enabled', false)) return 0;
        $outbox = app(V2OutboxService::class);
        $processed = 0;
        foreach (self::SOURCES as [$topic, $type]) {
            if ($processed >= $limit) break;
            foreach ($outbox->claim($worker, $limit - $processed, 60, [$topic], [$type]) as $message) {
                try {
                    DB::transaction(function () use ($message, $worker, $outbox): void {
                        // All obligations and D3 reservations commit before the shared event ack.
                        app(V2LineMessagingSettingService::class)->setting(true);
                        foreach ($this->events($message) as [$event, $userId, $reason]) {
                            $this->handoff($message, $event, $userId, $reason);
                        }
                        $outbox->markDelivered($message->public_id, $worker);
                    }, 1);
                } catch (\Throwable) {
                    // Never expose body, user identity or provider details. Domain TX already committed.
                    if ($message->attempts >= 5) $outbox->markFailed($message->public_id, $worker, 'line_automated_handoff_hold');
                    else $outbox->retry($message->public_id, $worker, 'line_automated_handoff_retry', 60);
                }
                $processed++;
            }
        }
        return $processed;
    }

    public static function sourceKey(string $publicId, string $event): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'oripa:line:automated:'.$publicId.':'.$event)->toString();
    }

    private function handoff(OutboxMessage $message, string $event, ?int $userId, ?string $reason): void
    {
        $key = self::sourceKey($message->public_id, $event);
        if (DB::table('line_automated_outcomes')->where('source_key', $key)->exists()) return;
        // Immutable version history defines the event-time enablement boundary. Same-second enablement
        // is conservatively excluded because the existing Outbox timestamp has second precision.
        $v = DB::table('line_automated_template_versions')->where('event', $event)
            ->where('effective_at', '<', $message->created_at)->orderByDesc('revision')->first();
        $batch = null;
        $reason ??= $v === null || ! $v->enabled ? 'disabled_at_event' : null;
        if ($reason === null) {
            $messages = json_decode(Crypt::decryptString($v->messages_ciphertext), true, 512, JSON_THROW_ON_ERROR);
            app(V2LineAutomatedSettings::class)->validate($event, $messages);
            foreach ($messages as $m) if (str_contains($m['text'], '%shipping_url%')) $reason = 'shipping_url_unsupported';
            if ($reason === null) {
                $purpose = V2LineDeliveryPurpose::from($v->category);
                $ref = app(V2LineRecipientResolver::class)->linkedMember($userId, $purpose);
                if ($ref === null) $reason = 'recipient_unavailable';
                else $batch = app(V2LineDeliveryService::class)->enqueuePush($ref, $purpose, $messages, 'automated', $key);
            }
        }
        DB::table('line_automated_outcomes')->insert(['source_key' => $key, 'outbox_id' => $message->id,
            'event' => $event, 'template_revision' => $v?->revision, 'status' => $batch === null ? 'skipped' : 'queued',
            'reason_code' => $reason ?? 'durable_d3_handoff', 'batch_id' => $batch]);
    }

    /** @return list<array{string,?int,?string}> */
    private function events(OutboxMessage $message): array
    {
        if ($message->event_type === 'payment.succeeded') {
            $p = DB::table('payments')->where('public_id', $message->payload['payment_public_id'] ?? null)->firstOrFail();
            if ($message->aggregate_type !== 'payment' || $message->aggregate_public_id !== $p->public_id) throw new \RuntimeException('Invalid payment event.');
            $grant = DB::table('payment_point_grants')->where('payment_id', $p->id)->firstOrFail();
            // Grants are durable success facts, retained across refunds/chargebacks. The success writer
            // locks the member before insertion, so grant IDs give that member's serialized success order.
            $first = DB::table('payment_point_grants as g')->join('payments as p', 'p.id', '=', 'g.payment_id')
                ->where('p.user_id', $p->user_id)->min('g.id');
            return [['payment', (int) $p->user_id, null], ['first_purchase', (int) $p->user_id,
                (int) $first === (int) $grant->id ? null : 'not_first_purchase']];
        }
        if ($message->event_type === 'shipping.status.changed') {
            $s = DB::table('shipping_requests')->where('public_id', $message->aggregate_public_id)->firstOrFail();
            if ($message->aggregate_type !== 'shipping_request') throw new \RuntimeException('Invalid shipping event.');
            $reason = ($message->payload['to_status'] ?? null) === 'shipped'
                && ($message->payload['from_status'] ?? null) !== 'shipped' ? null : 'not_shipped_transition';
            if (DB::table('shipping_request_items as item')->join('user_prizes as prize', 'prize.id', '=', 'item.user_prize_id')
                ->join('draw_results as result', 'result.id', '=', 'prize.draw_result_id')
                ->where('item.shipping_request_id', $s->id)->where('result.is_qa_draw', true)->exists()) $reason = 'qa_event_excluded';
            if ($reason === null) {
                // The first durable shipped event owns the obligation, including after return/re-ship.
                $first = DB::table('outbox_messages')->where('topic', 'shipping.status')->where('event_type', 'shipping.status.changed')
                    ->where('aggregate_public_id', $message->aggregate_public_id)->where('payload->to_status', 'shipped')->min('id');
                if ((int) $first !== (int) $message->id) $reason = 'already_shipped';
            }
            return [['shipping', (int) $s->user_id, $reason]];
        }
        $change = DB::table('member_rank_promotion_identities as identity')->join('member_rank_changes as change', 'change.id', '=', 'identity.change_id')
            ->join('users as user', 'user.id', '=', 'change.user_id')->where('identity.public_id', $message->payload['change_public_id'] ?? null)
            ->where('user.public_id', $message->payload['user_public_id'] ?? null)->first(['change.user_id', 'change.from_level', 'change.to_level']);
        if ($change === null || $change->to_level <= $change->from_level) throw new \RuntimeException('Invalid promotion event.');
        return [['rank_promotion', (int) $change->user_id, null]];
    }
}
