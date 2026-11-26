<?php

namespace App\Domain\Line\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Manual D7 orchestration. Staging never participates in recipient resolution. */
final class V2LineFriendIdBackfill
{
    private const RUNS = 'line_friend_id_runs';
    private const ROWS = 'line_friend_id_staging';
    private const LOCK = 741107116;
    private ?int $pid = null;

    public function execute(string $actor, ?string $resume = null, bool $cleanup = false): array
    {
        $id = null;
        if (! Str::isUuid($actor) || ($resume !== null && ! Str::isUuid($resume)) || DB::transactionLevel() !== 0) {
            return ['state' => 'failed', 'reason' => 'invalid_invocation'];
        }
        try {
            foreach ([self::RUNS, self::ROWS, 'line_bots', 'line_login_settings', 'line_messaging_settings',
                'line_recovered_friends', 'line_friendships', 'line_pending_follows', 'external_identity_accounts', 'audit_logs'] as $table) {
                if (! Schema::hasTable($table)) throw new RuntimeException('prerequisite');
            }
            $lock = DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired, pg_backend_pid() AS pid', [self::LOCK]);
            if (! $lock->acquired) return ['state' => 'failed', 'reason' => 'already_running'];
            $this->pid = (int) $lock->pid;
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->activeKeyVersion();
            $this->cleanAbandoned($actor);
            if ($cleanup) return ['state' => 'completed', 'reason' => 'cleanup'];
            app(V2LineSubjectVault::class)->hashes('d7-key-readiness');
            Crypt::decryptString(Crypt::encryptString('d7-key-readiness'));
            $bot = DB::transaction(fn () => $this->fence());
            if ($resume !== null) {
                $run = DB::table(self::RUNS)->where('id', $resume)->first();
                if ($run === null || ! in_array($run->state, ['ready', 'applying'], true)
                    || (int) $run->line_bot_id !== (int) $bot->id
                    || (int) $run->authentication_revision !== (int) $bot->authentication_revision) throw new RuntimeException('resume_rejected');
                $id = $resume;
            } else {
                $id = (string) Str::uuid();
                DB::table(self::RUNS)->insert(['id' => $id, 'actor_public_id' => $actor, 'line_bot_id' => $bot->id,
                    'authentication_revision' => $bot->authentication_revision, 'state' => 'fetching',
                    'created_at' => V2DatabaseTimestamp::format(now()), 'expires_at' => V2DatabaseTimestamp::format(now()->addDay())]);
                $this->acquire($id, $bot);
            }
            $this->apply($id, $bot);
            $this->audit($id, $actor);
            return $this->summary($id);
        } catch (Throwable) {
            // Neither exception chains nor SQL/HTTP bindings may reach console/logs.
            if ($id !== null) {
                try {
                    $this->ownsLock();
                    $run = DB::table(self::RUNS)->where('id', $id)->first();
                    if ($run?->state === 'fetching') {
                        DB::table(self::RUNS)->where('id', $id)->update(['state' => 'failed', 'failures' => DB::raw('failures + 1')]);
                        $this->purge($id);
                    } elseif ($run !== null) {
                        DB::table(self::RUNS)->where('id', $id)->increment('failures');
                    }
                    $this->audit($id, $actor);
                    return $this->summary($id);
                } catch (Throwable) {
                    return ['run' => $id, 'state' => 'unknown', 'reason' => 'database_or_audit_unavailable'];
                }
            }
            return ['state' => 'failed', 'reason' => 'prerequisite_or_resume_rejected'];
        } finally {
            if ($this->pid !== null) {
                try { DB::select('SELECT pg_advisory_unlock(?)', [self::LOCK]); } catch (Throwable) {}
                $this->pid = null;
            }
        }
    }

    private function acquire(string $id, object $bot): void
    {
        $next = null; $seen = []; $deadline = hrtime(true) + 3_600_000_000_000;
        $expires = now()->addHour(); // Stricter than LINE's 24-hour continuation-token lifetime.
        do {
            $this->ownsLock();
            if (hrtime(true) >= $deadline || now()->gte($expires) || count($seen) >= 10000) throw new RuntimeException('acquisition_limit');
            DB::transaction(fn () => $this->fence($bot));
            // Conservative acquisition instant: never manufacture a later observation after DB delay.
            $observed = now()->getTimestampMs();
            $page = app(V2LineBotTransport::class)->followerIds($bot, $next);
            if ($page['status'] !== 200) {
                DB::table(self::RUNS)->where('id', $id)->update(['http_status' => $page['status']]);
                throw new RuntimeException('acquisition_failed');
            }
            if (hrtime(true) >= $deadline || now()->gte($expires)) throw new RuntimeException('expired');
            $next = $page['next'];
            if ($next !== null) {
                $digest = hash('sha256', $next);
                if (isset($seen[$digest])) throw new RuntimeException('paging_cycle');
                $seen[$digest] = true;
            }
            DB::transaction(function () use ($id, $bot, $page, $observed): void {
                $this->fence($bot);
                $rows = [];
                foreach ($page['userIds'] as $subject) {
                    $hash = app(V2LineSubjectVault::class)->hashes($subject)[0];
                    $rows[$hash] = ['run_id' => $id, 'subject_hash' => $hash,
                        'subject_ciphertext' => Crypt::encryptString($subject), 'observed_at_ms' => $observed];
                }
                foreach (array_chunk(array_values($rows), 100) as $chunk) DB::table(self::ROWS)->insertOrIgnore($chunk);
                DB::table(self::RUNS)->where('id', $id)->update(['pages' => DB::raw('pages + 1'),
                    'fetched' => DB::raw('fetched + '.count($page['userIds']))]);
            }, 1);
            unset($page);
            if ($next !== null) usleep(500000);
        } while ($next !== null);
        DB::transaction(function () use ($id, $bot): void {
            $this->fence($bot);
            $unique = DB::table(self::ROWS)->where('run_id', $id)->count();
            DB::table(self::RUNS)->where('id', $id)->update(['state' => 'ready', 'unique_count' => $unique,
                'duplicates' => DB::raw('fetched - '.$unique)]);
        });
    }

    private function apply(string $id, object $bot): void
    {
        while (true) {
            $done = DB::transaction(function () use ($id, $bot): bool {
                $this->fence($bot);
                $run = DB::table(self::RUNS)->where('id', $id)->lockForUpdate()->first();
                if (! in_array($run->state, ['ready', 'applying'], true) || now()->gte($run->expires_at)) throw new RuntimeException('run_expired');
                $row = DB::table(self::ROWS)->where('run_id', $id)->orderBy('id')->lockForUpdate()->first();
                if ($row === null) {
                    DB::table(self::RUNS)->where('id', $id)->update(['state' => 'completed']);
                    return true;
                }
                $subject = Crypt::decryptString($row->subject_ciphertext);
                $vault = app(V2LineSubjectVault::class);
                $hashes = $vault->hashes($subject);
                if (! in_array($row->subject_hash, $hashes, true)) throw new RuntimeException('binding');
                $existing = DB::table('line_recovered_friends')->where('line_bot_id', $bot->id)->whereIn('subject_hash', $hashes)->first();
                $counter = $existing === null ? 'created' : ((int) $existing->observed_at_ms < (int) $row->observed_at_ms ? 'updated' : 'unchanged');
                $filled = 0;
                foreach (['external_identity_accounts', 'line_friendships', 'line_pending_follows', 'line_recovered_friends'] as $table) {
                    $q = DB::table($table)->whereIn('subject_hash', $hashes)->whereNull('subject_ciphertext');
                    if ($table === 'external_identity_accounts') $q->where('provider', 'line')->where('issuer', 'https://access.line.me');
                    $filled += $q->orderBy('id')->lockForUpdate()->get(['id'])->count();
                }
                $vault->backfill($subject);
                app(V2LineRecoveredFriendRegistry::class)->record((int) $bot->id, (int) $bot->authentication_revision, $subject, (int) $row->observed_at_ms);
                DB::table(self::ROWS)->where('id', $row->id)->delete();
                DB::table(self::RUNS)->where('id', $id)->update(['state' => 'applying', 'applied' => DB::raw('applied + 1'),
                    $counter => DB::raw($counter.' + 1'), 'filled' => DB::raw('filled + '.$filled)]);
                return false;
            }, 1);
            if ($done) return;
        }
    }

    private function fence(?object $expected = null): object
    {
        $this->ownsLock();
        DB::statement("SET LOCAL lock_timeout = '1s'");
        DB::statement("SET LOCAL statement_timeout = '5s'");
        app(V2LineMessagingSettingService::class)->setting(true);
        $bots = DB::table('line_bots')->where('is_active', true)->limit(2)->get();
        if ($bots->count() !== 1) throw new RuntimeException('bot');
        $bot = $bots->first();
        if ($bot->channel_id === null || $bot->channel_secret_ciphertext === null || $bot->connected_at === null
            || $bot->connection_status !== 'succeeded' || ($expected !== null && ($expected->id !== $bot->id
                || $expected->authentication_revision !== $bot->authentication_revision))) throw new RuntimeException('bot');
        return $bot;
    }

    private function ownsLock(): void
    {
        if ($this->pid === null || (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid !== $this->pid) throw new RuntimeException('connection_changed');
    }

    private function purge(string $id): void
    {
        do {
            $this->ownsLock();
            $ids = DB::table(self::ROWS)->where('run_id', $id)->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isNotEmpty()) DB::table(self::ROWS)->whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());
    }

    private function cleanAbandoned(string $actor): void
    {
        foreach (DB::table(self::RUNS)->where(fn ($q) => $q->where('state', 'fetching')
            ->orWhere(fn ($expired) => $expired->whereIn('state', ['ready', 'applying'])->where('expires_at', '<=', V2DatabaseTimestamp::format(now())))
            ->orWhere(fn ($residual) => $residual->whereIn('state', ['failed', 'expired'])->whereExists(fn ($rows) =>
                $rows->selectRaw('1')->from(self::ROWS)->whereColumn('run_id', self::RUNS.'.id'))))
            ->orderBy('id')->lazyById(100) as $run) {
            $this->ownsLock();
            DB::table(self::RUNS)->where('id', $run->id)->update(['state' => $run->state === 'completed' ? 'completed' : 'expired']);
            $this->purge($run->id);
            if (! in_array($run->state, ['completed', 'expired'], true)) $this->audit($run->id, $actor);
        }
    }

    private function summary(string $id): array
    {
        $r = DB::table(self::RUNS)->where('id', $id)->first();
        $data = ['run' => $id, 'state' => $r->state, 'bot_id' => (int) $r->line_bot_id,
            'result' => $r->state === 'completed' ? 'completed' : ((int) $r->applied > 0 ? 'partial' : 'failed')];
        foreach (['pages', 'fetched', 'unique_count', 'applied', 'created', 'updated', 'unchanged', 'duplicates', 'filled', 'failures', 'http_status'] as $key) $data[$key] = (int) $r->$key;
        return $data;
    }

    private function audit(string $id, string $actor): void
    {
        $data = $this->summary($id);
        app(V2AuditLogService::class)->record('line.friend_ids.backfill', ['actor_type' => 'system',
            'actor_public_id' => $actor, 'target_type' => 'line_friend_id_run', 'target_public_id' => $id,
            'outcome' => $data['state'] === 'completed' ? 'success' : 'failure', 'metadata' => $data]);
    }
}
