<?php

namespace App\Domain\Line\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LineDeliveryQuota
{
    public function month(CarbonImmutable $at): string { return $at->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString(); }
    public function nextMonth(CarbonImmutable $at): string { return $at->setTimezone('Asia/Tokyo')->startOfMonth()->addMonth()->toDateString(); }

    /** Monthly admission preflight; never issue HTTP inside a caller transaction. */
    public function refreshForAdmission(): void
    {
        $bot = DB::table('line_bots')->where('is_active', true)->where('connection_status', 'succeeded')->first();
        if ($bot === null) return;
        $month = $this->month(app(V2LineDeliverySender::class)->clock());
        $account = DB::table('line_quota_accounts')->where('line_bot_id', $bot->id)->first();
        if ($account !== null && ($account->halted || $account->authentication_revision !== $bot->authentication_revision
            || $account->usable_from > $month)) return;
        if (! DB::table('line_quota_periods')->where('line_bot_id', $bot->id)->where('month', $month)->exists()) $this->providerRemaining($bot);
    }

    public function prepareScheduledMonth(object $bot, CarbonImmutable $when): void
    {
        $this->providerRemaining($bot);
        DB::transaction(function () use ($bot, $when): void {
            $this->lock();
            $this->ensurePeriod($bot->id, $bot->authentication_revision, $this->month($when));
        }, 1);
    }

    /** Caller holds singleton. Monthly continuation never replaces the opening. */
    private function ensurePeriod(int $botId, int $revision, string $month, ?int $providerLimit = null): void
    {
        if (DB::table('line_quota_periods')->where('line_bot_id', $botId)->where('month', $month)->exists()) return;
        $now = app(V2LineDeliverySender::class)->clock();
        if (! in_array($month, [$this->month($now), $this->nextMonth($now)], true)) return;
        $account = DB::table('line_quota_accounts')->where('line_bot_id', $botId)->first();
        $bot = DB::table('line_bots')->where('id', $botId)->where('is_active', true)->where('connection_status', 'succeeded')->first();
        if ($account === null || $account->halted || $account->usable_from > $month
            || $account->authentication_revision !== $revision || $bot?->authentication_revision !== $revision) return;
        $previous = DB::table('line_quota_periods')->where('line_bot_id', $botId)->where('month', '<', $month)
            ->where('month', '>=', $account->usable_from)->orderByDesc('month')->first();
        if ($previous === null || ($previous->authentication_revision !== null && $previous->authentication_revision !== $revision)) return;
        if (DB::table('line_delivery_batches as b')->where('b.line_bot_id', $botId)->whereNotNull('b.first_attempted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('line_quota_charges as c')->whereColumn('c.batch_id', 'b.id'))->exists()) {
            $this->stop($botId, 'unaccounted_attempt');
            return;
        }
        $limit = min($previous->effective_limit, $providerLimit ?? $previous->effective_limit);
        $evidence = (string) Str::uuid();
        DB::table('line_quota_periods')->insert(['line_bot_id'=>$botId, 'month'=>$month, 'opening_used'=>0,
            'approved_limit'=>$limit, 'effective_limit'=>$limit, 'evidence_id'=>$evidence,
            'verified_by_admin_id'=>null, 'baseline_origin'=>'system', 'authentication_revision'=>$revision]);
        foreach ($this->uncarried($botId, $month)->get() as $charge) DB::table('line_quota_charges')->insert([
            'line_bot_id'=>$botId, 'month'=>$month, 'batch_id'=>$charge->batch_id, 'units'=>$charge->units, 'state'=>'unknown']);
        app(V2AuditLogService::class)->record('line.quota.period.generated', ['request_id'=>$evidence,
            'actor_type'=>'system', 'auth_realm'=>'system', 'target_type'=>'line_bot',
            'metadata'=>['record_id'=>$botId, 'month'=>$month], 'outcome'=>'success']);
    }

    /** Trusted management entry, not HTTP. Evidence UUID identifies a private operational record. */
    public function confirmBaseline(V2AdminAuthorizationContext $context, int $botId, int $revision, string $month,
        int $used, int $limit, string $evidenceId, string $confirmation, ?CarbonImmutable $cut = null): void
    {
        $this->authorize($context);
        if (! Str::isUuid($evidenceId) || $used < 0 || $limit < $used
            || $confirmation !== '課金対象送信の一元管理・正確な基準値・未確定の外部送信なしを確認しました') throw new \InvalidArgumentException('Verified LINE quota evidence required.');
        DB::transaction(function () use ($context, $botId, $revision, $month, $used, $limit, $evidenceId, $cut): void {
            $this->lock();
            $now = app(V2LineDeliverySender::class)->clock();
            if ($cut !== null && ($cut->gt($now) || $this->month($cut) !== $this->month($now))) throw new \InvalidArgumentException('Invalid LINE quota evidence cut.');
            if (! in_array($month, [$this->month($now), $this->nextMonth($now)], true)
                || ($month !== $this->month($now) && $used !== 0)) throw new \InvalidArgumentException('Invalid LINE quota month.');
            DB::table('line_bots')->where('id', $botId)->where('authentication_revision', $revision)->where('connection_status', 'succeeded')->firstOrFail();
            if (DB::table('line_delivery_batches as b')->where('b.line_bot_id', $botId)->whereNotNull('b.first_attempted_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('line_quota_charges as c')->whereColumn('c.batch_id', 'b.id'))->exists()) throw new \RuntimeException('Unaccounted LINE attempts require reconciliation.');
            $account = DB::table('line_quota_accounts')->where('line_bot_id', $botId)->first();
            // No same-month rebase can erase an incident or overlap uncertain local sends.
            if ($account?->halted && $account->halted_at !== null && $month <= $this->month(CarbonImmutable::parse($account->halted_at))) throw new \RuntimeException('LINE quota month remains halted.');
            DB::table('line_quota_accounts')->updateOrInsert(['line_bot_id' => $botId], ['authentication_revision' => $revision,
                'usable_from' => $account === null || $account->halted || $account->authentication_revision !== $revision ? $month : $account->usable_from,
                'halted' => false, 'halt_reason' => null, 'halted_at' => null, 'updated_at' => $now]);
            DB::table('line_quota_periods')->insert(['line_bot_id' => $botId, 'month' => $month, 'opening_used' => $used,
                'approved_limit' => $limit, 'effective_limit' => $limit, 'evidence_id' => $evidenceId, 'verified_by_admin_id' => $context->adminId, ...($cut === null ? [] : ['baseline_cut_at' => V2DatabaseTimestamp::format($cut)])]);
            // Unresolved effects survive the retry window and every later month.
            foreach (DB::table('line_quota_charges as c')->join('line_delivery_batches as b', 'b.id', '=', 'c.batch_id')
                ->where('c.line_bot_id', $botId)->where('c.state', 'unknown')->where('b.status', '<>', 'accepted')
                ->select('c.batch_id', 'c.units')->distinct()->get() as $charge) {
                DB::table('line_quota_charges')->insertOrIgnore(['line_bot_id' => $botId, 'month' => $month,
                    'batch_id' => $charge->batch_id, 'units' => $charge->units, 'state' => 'unknown']);
            }
            $this->audit($context, 'line.quota.baseline.confirmed', $botId, $evidenceId);
        }, 1);
    }

    public function halt(V2AdminAuthorizationContext $context, int $botId, string $evidenceId): void
    {
        $this->authorize($context);
        if (! Str::isUuid($evidenceId)) throw new \InvalidArgumentException('LINE quota evidence required.');
        DB::transaction(function () use ($context, $botId, $evidenceId): void {
            $this->lock(); $this->stop($botId, 'external_send_reported');
            $this->audit($context, 'line.quota.halted', $botId, $evidenceId);
        }, 1);
    }

    public function reserved(int $botId, ?string $month = null): int
    {
        return (int) DB::table('line_quota_charges')->where('line_bot_id', $botId)
            ->where('month', $month ?? $this->month(app(V2LineDeliverySender::class)->clock()))->sum('units');
    }

    public function available(int $botId, string $month): int
    {
        $a = DB::table('line_quota_accounts as a')->join('line_bots as b', 'b.id', '=', 'a.line_bot_id')
            ->where('a.line_bot_id', $botId)->where('a.halted', false)->where('a.usable_from', '<=', $month)->whereColumn('a.authentication_revision', 'b.authentication_revision')->exists();
        $p = DB::table('line_quota_periods')->where('line_bot_id', $botId)->where('month', $month)->first();
        $carry = (int) $this->uncarried($botId, $month)->get()->sum('units');
        return ! $a || $p === null ? 0 : max(0, $p->effective_limit - $p->opening_used - $this->reserved($botId, $month) - $carry);
    }

    public function unknown(int $botId, string $month): int
    {
        return (int) DB::table('line_quota_charges')->where('line_bot_id', $botId)->where('month', $month)->where('state', 'unknown')->sum('units')
            + (int) $this->uncarried($botId, $month)->get()->sum('units');
    }

    /** Net local budget. Never subtract approximate consumption from local history. */
    public function remaining(#[\SensitiveParameter] object $bot): int
    {
        $this->providerRemaining($bot);
        return $this->available($bot->id, $this->month(app(V2LineDeliverySender::class)->clock()));
    }

    /** HTTP outside transactions. Approximate usage may stop, but never credit, admission. */
    public function providerRemaining(#[\SensitiveParameter] object $bot): ?int
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Quota must be read outside transactions.');
        $month = $this->month(app(V2LineDeliverySender::class)->clock());
        $transport = app(V2LineBotTransport::class);
        $quota = $transport->get($bot, '/v2/bot/message/quota');
        $used = $transport->get($bot, '/v2/bot/message/quota/consumption');
        if (! in_array($quota['type'] ?? null, ['none', 'limited'], true)
            || ($quota['type'] === 'limited' && (! is_int($quota['value'] ?? null) || $quota['value'] < 0 || $quota['value'] > 9007199254740991))
            || ! is_int($used['totalUsage'] ?? null) || $used['totalUsage'] < 0 || $used['totalUsage'] > 9007199254740991) throw new \RuntimeException('LINE quota unavailable.');
        DB::transaction(function () use ($bot, $month, $quota, $used): void {
            $this->lock();
            if ($month !== $this->month(app(V2LineDeliverySender::class)->clock())) throw new \RuntimeException('LINE quota observation crossed month.');
            $current = DB::table('line_bots')->where('id', $bot->id)->where('is_active', true)->where('connection_status', 'succeeded')->first();
            if ($current?->authentication_revision !== $bot->authentication_revision) throw new \RuntimeException('LINE BOT revision conflict.');
            $limit = $quota['type'] === 'limited' ? $quota['value'] : null;
            $this->initialize($bot, $month, $limit, $used['totalUsage']);
            $this->ensurePeriod($bot->id, $bot->authentication_revision, $month, $limit);
            $p = DB::table('line_quota_periods')->where('line_bot_id', $bot->id)->where('month', $month)->first();
            if ($p === null) return;
            DB::table('line_quota_accounts')->where('line_bot_id', $bot->id)->update(['last_checked_at'=>app(V2LineDeliverySender::class)->clock()]);
            $exposed = (int) DB::table('line_quota_charges')->where('line_bot_id', $bot->id)->where('month', $month)->where('state', '<>', 'reserved')->sum('units');
            $carry = (int) $this->uncarried($bot->id, $month)->get()->sum('units');
            if ($used['totalUsage'] > $p->opening_used + $exposed + $carry) $this->stop($bot->id,
                $p->baseline_origin === 'system_initial' ? 'provider_usage_exceeds_estimated_ledger' : 'provider_usage_exceeds_ledger');
            if ($limit !== null) foreach (DB::table('line_quota_periods')->where('line_bot_id', $bot->id)->where('month', '>=', $month)->get() as $period) {
                DB::table('line_quota_periods')->where('line_bot_id', $bot->id)->where('month', $period->month)
                    ->update(['effective_limit' => min($period->effective_limit, $limit)]);
            }
        }, 1);
        return $quota['type'] === 'none' ? null : max(0, $quota['value'] - $used['totalUsage']);
    }

    /** Fresh observation, singleton held and BOT/month revalidated by the caller. */
    private function initialize(object $bot, string $month, ?int $limit, int $used): void
    {
        $account = DB::table('line_quota_accounts')->where('line_bot_id', $bot->id)->first();
        if ($account !== null) return; // Never clear a halt, rebase or adopt another generation.
        if ($limit === null) throw new \RuntimeException('LINE quota numeric limit unavailable.');
        if (DB::table('line_delivery_batches')->where('line_bot_id', $bot->id)
                ->where(fn ($q) => $q->whereNotNull('first_attempted_at')->orWhereIn('status', ['sending','retrying','accepted','unknown','failed']))->exists()
            || DB::table('line_quota_charges')->where('line_bot_id', $bot->id)->exists()) {
            $this->stop($bot->id, 'initial_history_requires_reconciliation');
            return;
        }
        $now = app(V2LineDeliverySender::class)->clock();
        $evidence = (string) Str::uuid();
        DB::table('line_quota_accounts')->insert(['line_bot_id'=>$bot->id,
            'authentication_revision'=>$bot->authentication_revision, 'usable_from'=>$month,
            'halted'=>false, 'last_checked_at'=>$now, 'updated_at'=>$now]);
        DB::table('line_quota_periods')->insert(['line_bot_id'=>$bot->id, 'month'=>$month,
            'opening_used'=>$used, 'approved_limit'=>$limit, 'effective_limit'=>$limit,
            'evidence_id'=>$evidence, 'verified_by_admin_id'=>null, 'baseline_origin'=>'system_initial',
            'authentication_revision'=>$bot->authentication_revision, 'baseline_cut_at'=>$now]);
        app(V2AuditLogService::class)->record('line.quota.initial.generated', ['request_id'=>$evidence,
            'actor_type'=>'system', 'auth_realm'=>'system', 'target_type'=>'line_bot',
            'metadata'=>['record_id'=>$bot->id, 'month'=>$month], 'outcome'=>'success']);
    }

    /** All deltas planned under singleton before inserts: whole-campaign admission. */
    public function cover(array $batches, array $months): bool
    {
        $this->lock(); $inserts = []; $groups = [];
        foreach ($batches as $b) {
            $groups[$b->line_bot_id][$b->id] = $b;
        }
        // Observations live only inside this singleton-locked call. Recheck on
        // every Phase A; never cache provider, generation or month decisions.
        foreach ($groups as $botId => $group) foreach (array_unique($months) as $month) {
            $revision = reset($group)->authentication_revision;
            foreach ($group as $b) if ($b->authentication_revision !== $revision) return false;
            $this->ensurePeriod($botId, $revision, $month);
            $account = DB::table('line_quota_accounts')->where('line_bot_id', $botId)->first();
            $period = DB::table('line_quota_periods')->where('line_bot_id', $botId)->where('month', $month)->first();
            if ($account === null || $account->halted || $account->usable_from > $month
                || $account->authentication_revision !== $revision || $period === null) return false;
            foreach ($this->uncarried($botId, $month)->get() as $charge) DB::table('line_quota_charges')->insert([
                'line_bot_id' => $botId, 'month' => $month, 'batch_id' => $charge->batch_id, 'units' => $charge->units, 'state' => 'unknown']);
            $delta = 0;
            foreach (array_chunk($group, 500, true) as $chunk) {
                $existing = DB::table('line_quota_charges')->where('line_bot_id', $botId)->where('month', $month)
                    ->whereIn('batch_id', array_keys($chunk))->pluck('batch_id')->all();
                $missing = array_diff_key($chunk, array_flip($existing));
                if ($missing === []) continue;
                $counts = DB::table('line_delivery_recipients')->whereIn('batch_id', array_keys($missing))
                    ->selectRaw('batch_id, count(*) AS units')->groupBy('batch_id')->pluck('units', 'batch_id');
                foreach ($missing as $b) {
                    $units = (int) ($counts[$b->id] ?? 0); $delta += $units;
                    $inserts[] = ['line_bot_id' => $botId, 'month' => $month, 'batch_id' => $b->id, 'units' => $units,
                        'state' => $b->first_attempted_at === null ? 'reserved' : 'unknown'];
                }
            }
            if ($delta > 0 && $this->available($botId, $month) < $delta) return false;
            // Also reject an already covered campaign after a provider limit cut.
            if ($period->opening_used + $this->reserved($botId, $month) > $period->effective_limit) return false;
        }
        foreach (array_chunk($inserts, 500) as $values) DB::table('line_quota_charges')->insert($values);
        return true;
    }

    public function prepare(object $batch, CarbonImmutable $now): bool
    {
        $batches = $batch->delivery_id === null ? [$batch] : DB::table('line_delivery_batches')->where('delivery_id', $batch->delivery_id)
            ->whereIn('status', ['pending', 'sending', 'retrying'])->orderBy('id')->get()->all();
        $last = $now;
        foreach ($batches as $b) if (CarbonImmutable::parse($b->not_before)->gt($last)) $last = CarbonImmutable::parse($b->not_before);
        $end = $this->month($last->addHours(23)->addSeconds(30));
        // Only current/next-month baselines can be certified. A longer campaign waits.
        if ($end > $this->nextMonth($now)) return false;
        $months = [$this->month($now), $end];
        if (! $this->cover($batches, $months)) return false;
        DB::table('line_quota_charges')->where('batch_id', $batch->id)->where('state', 'reserved')->update(['state' => 'unknown', 'updated_at' => $now]);
        return true;
    }

    /** Phase B cannot allocate: phase A must already have committed exposure. */
    public function permits(object $batch, CarbonImmutable $now): bool
    {
        $a = DB::table('line_quota_accounts')->where('line_bot_id', $batch->line_bot_id)->first();
        if ($a === null || $a->halted || $a->authentication_revision !== $batch->authentication_revision) return false;
        foreach (array_unique([$this->month($now), $this->month($now->addSeconds(30))]) as $month) {
            if ($a->usable_from > $month || $this->uncarried($batch->line_bot_id, $month)->exists()) return false;
            if (! DB::table('line_quota_charges')->where('batch_id', $batch->id)->where('month', $month)->where('state', 'unknown')->exists()) return false;
            $p = DB::table('line_quota_periods')->where('line_bot_id', $batch->line_bot_id)->where('month', $month)->firstOrFail();
            if ($p->opening_used + $this->reserved($batch->line_bot_id, $month) > $p->effective_limit) return false;
        }
        return true;
    }

    public function accepted(int $batchId): void
    {
        DB::table('line_quota_charges')->where('batch_id', $batchId)->where('state', 'unknown')->update(['state' => 'accepted', 'updated_at' => app(V2LineDeliverySender::class)->clock()]);
    }

    private function uncarried(int $botId, string $month): \Illuminate\Database\Query\Builder
    {
        return DB::table('line_quota_charges as c')->join('line_delivery_batches as b', 'b.id', '=', 'c.batch_id')
            ->where('c.line_bot_id', $botId)->where('c.month', '<', $month)->where('c.state', 'unknown')->where('b.status', '<>', 'accepted')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('line_quota_charges as present')->whereColumn('present.batch_id', 'c.batch_id')->where('present.month', $month))
            ->select('c.batch_id', 'c.units')->distinct();
    }

    private function stop(int $botId, string $reason): void
    {
        $bot = DB::table('line_bots')->where('id', $botId)->firstOrFail();
        DB::table('line_quota_accounts')->updateOrInsert(['line_bot_id' => $botId], ['halted' => true, 'halt_reason' => $reason,
            'authentication_revision' => $bot->authentication_revision, 'usable_from' => $this->month(app(V2LineDeliverySender::class)->clock()),
            'halted_at' => app(V2LineDeliverySender::class)->clock(), 'updated_at' => app(V2LineDeliverySender::class)->clock()]);
    }

    private function lock(): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('LINE quota reservation requires a transaction.');
        app(V2LineMessagingSettingService::class)->setting(true);
    }

    private function authorize(V2AdminAuthorizationContext $context): void
    {
        $authorization = app(V2AdminFreshMfaAuthorizer::class);
        $authorization->authorizePermission($context, V2Permission::ManageLineBot);
    }

    private function audit(V2AdminAuthorizationContext $context, string $action, int $botId, string $evidenceId): void
    {
        app(V2AuditLogService::class)->record($action, ['request_id' => $context->requestId, 'actor_type' => 'admin',
            'actor_public_id' => $context->adminPublicId, 'actor_role' => $context->role->value, 'auth_realm' => 'admin',
            'target_type' => 'line_bot', 'metadata' => ['record_id' => $botId, 'evidence_id' => $evidenceId], 'outcome' => 'success']);
    }
}
