<?php

namespace App\Domain\Line\Services;

use App\Domain\Line\Enums\V2LineDeliveryPurpose as Purpose;
use App\Domain\Line\Enums\V2LineRecipientGroup as Group;
use App\Domain\Line\ValueObjects\V2LineRecipient;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\MemberRank\Services\V2MemberRankGachaCondition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LineDeliverySender
{
    public function send(int $batchId): void
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('LINE dispatch requires an independent commit boundary.');
        $candidate = DB::table('line_delivery_batches')->where('id', $batchId)->firstOrFail();
        if (! in_array($candidate->status, ['pending', 'sending', 'retrying'], true)) return;
        $transport = app(V2LineBotTransport::class);
        if ($candidate->delivery_id !== null && ! DB::table('line_delivery_batches')->where('delivery_id', $candidate->delivery_id)->whereNotNull('first_attempted_at')->exists()) {
            $bot = $this->bot($candidate);
            if ($bot !== null) app(V2LineDeliveryQuota::class)->remaining($bot);
        }
        $owner = $this->prepare($batchId);
        if ($owner === null) return;
        $bot = $this->bot($candidate);
        if ($bot !== null) {
            app(V2LineDeliveryQuota::class)->remaining($bot);
            $transport->warmDeliveryToken($bot);
        }
        // Phase A has committed. Any subsequent failure must retain its identity.
        $outcome = DB::transaction(function () use ($batchId, $owner, $transport): ?array {
            $started = hrtime(true);
            $this->lock();
            $batch = DB::table('line_delivery_batches')->where('id', $batchId)->lockForUpdate()->firstOrFail();
            $now = $this->clock();
            if ($batch->status !== 'sending' || $batch->lease_owner !== $owner || CarbonImmutable::parse($batch->lease_until)->lte($now)) return null;
            if ($this->retryExpired($batch->first_attempted_at, $now)) {
                $this->finish($batch, 'unknown', 'retry_window_expired'); $this->completeDelivery($batch->delivery_id); return null;
            }
            $bot = $this->bot($batch);
            if ($bot === null) { $this->finish($batch, 'unknown', 'bot_generation_changed'); $this->completeDelivery($batch->delivery_id); return null; }
            $recipients = $this->recipients($batchId);
            $eligible = app(V2LineRecipientResolver::class)->eligibleBatchBeforeSend(
                $recipients->map(fn ($row) => $this->reference($batch, $row))->all(), Purpose::from($batch->category));
            foreach ($recipients as $i => $row) {
                if (! $eligible[$i]) {
                    $this->finish($batch, 'unknown', 'recipient_changed'); $this->completeDelivery($batch->delivery_id); return null;
                }
                if (hrtime(true) - $started > 5_000_000_000) throw new \RuntimeException('LINE validation time budget exceeded.');
            }
            try {
                $envelope = json_decode(Crypt::decryptString($batch->payload_ciphertext), true, 512, JSON_THROW_ON_ERROR);
                if ($envelope['bot'] !== (int) $batch->line_bot_id || $envelope['revision'] !== (int) $batch->authentication_revision
                    || $envelope['key'] !== $batch->retry_key || $envelope['method'] !== $batch->method || ! is_string($envelope['json'])) throw new \RuntimeException;
            } catch (\Throwable) {
                $this->finish($batch, 'unknown', 'payload_unavailable'); $this->completeDelivery($batch->delivery_id); return null;
            }
            if (! app(V2LineDeliveryQuota::class)->permits($batch, $this->clock())) return null;
            $result = $transport->deliver($bot, $batch->method, $batch->retry_key, $envelope['json']);
            $status = $result['status'];
            if (in_array($status, [200, 409], true)) {
                $this->finish($batch, 'accepted', null, $result['request_id']);
                app(V2LineDeliveryQuota::class)->accepted($batchId);
            }
            elseif ($status === 401) $this->finish($batch, 'failed', 'bot_auth_failed');
            elseif ($status === 429 || $status === 0 || $status >= 500) {
                $delay = [30, 120, 600, 1800, 3600][min(4, $batch->attempts - 1)];
                DB::table('line_delivery_batches')->where('id', $batchId)->update(['status' => 'retrying',
                    'reason_code' => $status === 429 ? 'rate_or_quota_pending' : 'transport_uncertain',
                    'not_before' => $this->clock()->addSeconds($delay), 'lease_owner' => null, 'lease_until' => null, 'updated_at' => $this->clock()]);
            } else $this->finish($batch, 'failed', 'provider_rejected');
            $this->completeDelivery($batch->delivery_id);
            return ['status' => $status, 'bot' => $bot];
        }, 1);
        $transport->cacheRefreshedDeliveryToken();
        // 429 classification never trusts provider prose and never holds send locks.
        if (($outcome['status'] ?? null) === 429) {
            try { $remaining = app(V2LineDeliveryQuota::class)->providerRemaining($outcome['bot']); }
            catch (\Throwable) { return; } // Finite retries within the immutable 23-hour window.
            $size = DB::table('line_delivery_recipients')->where('batch_id', $batchId)->where('status', 'pending')->count();
            if ($remaining !== null && $remaining < $size) DB::transaction(function () use ($batchId): void {
                $this->lock();
                $batch = DB::table('line_delivery_batches')->where('id', $batchId)->lockForUpdate()->firstOrFail();
                if ($batch->status === 'retrying' && $batch->reason_code === 'rate_or_quota_pending') {
                    $this->finish($batch, 'failed', 'quota_exceeded');
                    $this->completeDelivery($batch->delivery_id);
                }
            }, 1);
        }
    }

    private function prepare(int $batchId): ?string
    {
        return DB::transaction(function () use ($batchId): ?string {
            $started = hrtime(true);
            $this->lock();
            $b = DB::table('line_delivery_batches')->where('id', $batchId)->lockForUpdate()->firstOrFail();
            $now = $this->clock();
            if (! in_array($b->status, ['pending', 'retrying', 'sending'], true)
                || CarbonImmutable::parse($b->not_before)->gt($now)
                || ($b->lease_until !== null && CarbonImmutable::parse($b->lease_until)->gt($now))) return null;
            if ($b->first_attempted_at !== null && $this->retryExpired($b->first_attempted_at, $now)) {
                $this->finish($b, 'unknown', 'retry_window_expired'); $this->completeDelivery($b->delivery_id); return null;
            }
            if ($this->bot($b) === null) {
                $this->finish($b, $b->payload_ciphertext === null ? 'skipped' : 'unknown', 'bot_generation_changed');
                $this->completeDelivery($b->delivery_id); return null;
            }
            if (! app(V2LineDeliveryQuota::class)->prepare($b, $now)) {
                if ($b->delivery_id !== null && ! DB::table('line_delivery_batches')->where('delivery_id', $b->delivery_id)->whereNotNull('first_attempted_at')->exists()) {
                    DB::table('line_deliveries')->where('id', $b->delivery_id)->update(['status' => 'blocked_quota']);
                    DB::table('line_delivery_batches')->where('delivery_id', $b->delivery_id)->where('status', 'pending')
                        ->update(['status' => 'skipped', 'reason_code' => 'quota_exceeded']);
                }
                return null;
            }
            $values = [];
            if ($b->payload_ciphertext === null) {
                $ids = []; $profile = [];
                $recipients = $this->recipients($batchId);
                $eligible = app(V2LineRecipientResolver::class)->eligibleBatchBeforeSend(
                    $recipients->map(fn ($row) => $this->reference($b, $row))->all(), Purpose::from($b->category));
                foreach ($recipients as $i => $row) {
                    $ref = $this->reference($b, $row);
                    if (! $eligible[$i]) {
                        DB::table('line_delivery_recipients')->where('id', $row->id)->update(['status' => 'skipped']); continue;
                    }
                    $binding = DB::table($ref->source)->where('id', $ref->sourceId)->where('line_bot_id', $ref->botId)->firstOrFail();
                    if (! app(V2LineSubjectVault::class)->deliveryBindingMatches($binding)) throw new \RuntimeException('LINE subject unavailable.');
                    $ids[] = Crypt::decryptString($binding->subject_ciphertext);
                    if ($b->method === 'push' && $ref->userId !== null) {
                        $wallet = DB::table('wallets')->where('user_id', $ref->userId)->first();
                        $rank = app(V2MemberRankResolver::class)->project($ref->userId);
                        $profile = ['username' => V2LineProfileName::forUser(DB::table('users')->where('id', $ref->userId)->first()),
                            'rank' => $rank['enabled'] ? V2MemberRankGachaCondition::rank($rank['level'])['name'] : '',
                            'points' => (int) ($wallet?->paid_balance ?? 0) + (int) ($wallet?->free_balance ?? 0)];
                    }
                    if (hrtime(true) - $started > 5_000_000_000) throw new \RuntimeException('LINE validation time budget exceeded.');
                }
                $ids = array_values(array_unique($ids));
                if ($ids === []) { $this->finish($b, 'skipped', 'no_eligible_recipient'); $this->completeDelivery($b->delivery_id); return null; }
                if (($b->method === 'push' && count($ids) !== 1) || count($ids) > 500) throw new \LogicException('Invalid LINE batch size.');
                try {
                    $messages = json_decode(Crypt::decryptString($b->messages_ciphertext), true, 512, JSON_THROW_ON_ERROR);
                    $rendered = app(V2LineDeliveryMessages::class)->render($messages, $profile);
                } catch (\Throwable) {
                    $this->finish($b, 'failed', 'message_unavailable'); $this->completeDelivery($b->delivery_id); return null;
                }
                $json = json_encode(['to' => $b->method === 'push' ? $ids[0] : $ids,
                    'messages' => $rendered], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $values = ['payload_ciphertext' => Crypt::encryptString(json_encode(['bot' => (int) $b->line_bot_id,
                    'revision' => (int) $b->authentication_revision, 'key' => $b->retry_key, 'method' => $b->method, 'json' => $json], JSON_THROW_ON_ERROR)),
                    'first_attempted_at' => $now];
            }
            $owner = (string) Str::uuid();
            DB::table('line_delivery_batches')->where('id', $batchId)->update([...$values,
                'status' => 'sending', 'attempts' => $b->attempts + 1, 'lease_owner' => $owner,
                'lease_until' => $now->addSeconds(30), 'updated_at' => $now]);
            return $owner;
        }, 1);
    }

    private function lock(): void
    {
        // PostgreSQL17 also releases locks if the client is paused or disappears
        // during HTTP; PHP/HTTP timeouts alone cannot bound a stopped process.
        DB::statement("SET LOCAL transaction_timeout = '20s'");
        DB::statement("SET LOCAL lock_timeout = '2s'");
        DB::statement("SET LOCAL statement_timeout = '5s'");
        app(V2LineMessagingSettingService::class)->setting(true);
    }

    public function clock(): CarbonImmutable { return CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant')->instant); }

    public function retryExpired(string $firstAttempt, CarbonImmutable $databaseNow): bool
    {
        $first = CarbonImmutable::parse($firstAttempt);
        return $first->gt($databaseNow) || $first->addHours(23)->lt($databaseNow);
    }

    private function bot(object $b): ?object
    {
        return DB::table('line_bots')->where('id', $b->line_bot_id)->where('authentication_revision', $b->authentication_revision)
            ->where('is_active', true)->where('connection_status', 'succeeded')->whereNotNull('connected_at')->first();
    }

    private function recipients(int $id): iterable
    {
        return DB::table('line_delivery_recipients')->where('batch_id', $id)->where('status', 'pending')->orderBy('id')->get();
    }

    private function reference(object $b, object $r): V2LineRecipient
    {
        return new V2LineRecipient((int) $b->line_bot_id, (int) $b->authentication_revision, $r->source_table,
            (int) $r->source_id, $r->user_id === null ? null : (int) $r->user_id, Group::from($r->recipient_group));
    }

    private function finish(object $b, string $status, ?string $reason, ?string $requestId = null): void
    {
        DB::table('line_delivery_batches')->where('id', $b->id)->update(['status' => $status, 'reason_code' => $reason,
            'request_id' => $requestId, 'lease_owner' => null, 'lease_until' => null, 'updated_at' => $this->clock()]);
    }

    public function completeDelivery(?int $id): void
    {
        if ($id === null) return;
        $states = DB::table('line_delivery_batches')->where('delivery_id', $id)->pluck('status')->all();
        if (array_intersect($states, ['pending', 'sending', 'retrying'])) return;
        DB::table('line_deliveries')->where('id', $id)->where('status', 'sending')->update([
            'status' => array_intersect($states, ['failed', 'unknown']) ? 'completed_with_errors' : 'completed', 'updated_at' => $this->clock()]);
    }
}
