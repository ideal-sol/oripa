<?php

namespace App\Domain\Line\Services;

use App\Domain\Line\Enums\V2LineDeliveryPurpose;
use App\Domain\Line\Enums\V2LineRecipientGroup;
use App\Domain\Line\ValueObjects\V2LineRecipient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class V2LineRecipientResolver
{
    private const SOURCES = ['line_friendships', 'line_pending_follows', 'line_recovered_friends'];

    /** Individual domain notifications must never select an unlinked friend. */
    public function linkedMember(int $userId, V2LineDeliveryPurpose $purpose): ?V2LineRecipient
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Member resolution requires the caller transaction.');
        app(V2LineMessagingSettingService::class)->setting(true);
        $bot = $this->activeBot();
        if ($bot === null) return null;
        $hashes = DB::table('external_identity_accounts')->where('user_id', $userId)->where('provider', 'line')
            ->where('issuer', 'https://access.line.me')->whereNull('revoked_at')->whereNull('superseded_at')->pluck('subject_hash')->all();
        $refs = $this->classify($bot, $hashes, V2LineRecipientGroup::LinkedMembers);
        if (count($refs) !== 1 || $refs[0][0]->userId !== $userId) return null;
        return $this->eligibleBeforeSend($refs[0][0], $purpose) ? $refs[0][0] : null;
    }

    /** Bounded keyset traversal. References must be rechecked immediately before sending.
     * @return \Generator<int, V2LineRecipient>
     */
    public function resolve(V2LineRecipientGroup $group, V2LineDeliveryPurpose $purpose, int $chunkSize = 200, array $segment = []): \Generator
    {
        if ($chunkSize < 1 || $chunkSize > 500) throw new InvalidArgumentException('Invalid recipient chunk size.');
        $bot = $this->activeBot();
        if ($bot === null) return;
        $segment = app(V2LineDeliverySegment::class)->normalize($segment);
        $instant = \Carbon\CarbonImmutable::now();
        $after = '';
        do {
            $page = DB::transaction(function () use ($bot, $after, $chunkSize, $group): array {
                app(V2LineMessagingSettingService::class)->setting(true);
                if (! $this->sameBot($bot)) return [[], []];
                $query = null;
                foreach (self::SOURCES as $table) {
                    $part = DB::table($table)->select('subject_hash')->where('line_bot_id', $bot->id)->where('subject_hash', '>', $after);
                    $query = $query === null ? $part : $query->union($part);
                }
                $hashes = DB::query()->fromSub($query, 'subjects')->orderBy('subject_hash')->limit($chunkSize)->pluck('subject_hash')->all();
                return [$hashes, $hashes === [] ? [] : $this->classify($bot, $hashes, $group)];
            });
            foreach (app(V2LineDeliverySegment::class)->filter($page[1], $segment, $instant) as [$reference]) yield $reference;
            if ($page[0] === []) return;
            $after = end($page[0]);
        } while (count($page[0]) === $chunkSize);
    }

    /** No permission to send is cached. D3 must fence the actual transport against state changes. */
    public function eligibleBeforeSend(V2LineRecipient $reference, V2LineDeliveryPurpose $purpose): bool
    {
        return $this->eligibleBatchBeforeSend([$reference], $purpose)[0];
    }

    /** Same final predicate for at most one multicast batch; locks survive in the caller.
     * @param list<V2LineRecipient> $references
     * @return list<bool>
     */
    public function eligibleBatchBeforeSend(array $references, V2LineDeliveryPurpose $purpose): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Pre-send eligibility requires the caller transaction.');
        if (! array_is_list($references) || count($references) > 500) throw new InvalidArgumentException('Invalid recipient batch.');
        foreach ($references as $reference) if (! $reference instanceof V2LineRecipient) throw new InvalidArgumentException('Invalid recipient reference.');
        return DB::transaction(function () use ($references): array {
            app(V2LineMessagingSettingService::class)->setting(true);
            $bot = $this->activeBot();
            $eligible = array_fill(0, count($references), false);
            if ($bot === null) return $eligible;
            $valid = array_filter($references, fn ($r) => in_array($r->source, self::SOURCES, true)
                && (int) $bot->id === $r->botId && (int) $bot->authentication_revision === $r->authenticationRevision);
            $users = array_values(array_unique(array_filter(array_map(fn ($r) => $r->userId, $valid), fn ($id) => $id !== null)));
            if ($users !== []) {
                DB::table('users')->whereIn('id', $users)->orderBy('id')->lockForUpdate()->get();
                DB::table('external_identity_accounts')->whereIn('user_id', $users)
                    ->where('provider', 'line')->where('issuer', 'https://access.line.me')->orderBy('user_id')->orderBy('id')->lockForUpdate()->get();
            }
            $hashes = [];
            foreach (self::SOURCES as $source) {
                $ids = array_map(fn ($r) => $r->sourceId, array_filter($valid, fn ($r) => $r->source === $source));
                if ($ids !== []) $hashes = [...$hashes, ...DB::table($source)->whereIn('id', $ids)->where('line_bot_id', $bot->id)->pluck('subject_hash')->all()];
            }
            if ($hashes === []) return $eligible;
            $current = [];
            foreach ($this->classify($bot, array_values(array_unique($hashes)), V2LineRecipientGroup::AllFriends) as [$ref, $binding]) {
                if (app(V2LineSubjectVault::class)->deliveryBindingMatches($binding)) $current[$ref->source.':'.$ref->sourceId] = $ref;
            }
            foreach ($valid as $i => $ref) $eligible[$i] = ($current[$ref->source.':'.$ref->sourceId] ?? null) == $ref;
            return $eligible;
        }, 1);
    }

    private function activeBot(): ?object
    {
        $bots = DB::table('line_bots')->where('is_active', true)->limit(2)->get();
        if ($bots->count() !== 1) return null;
        $bot = $bots->first();
        return $bot->channel_id !== null && $bot->channel_secret_ciphertext !== null
            && $bot->connection_status === 'succeeded' && $bot->connected_at !== null ? $bot : null;
    }

    private function sameBot(object $expected): bool
    {
        $current = $this->activeBot();
        return $current !== null && $current->id === $expected->id
            && $current->authentication_revision === $expected->authentication_revision;
    }

    /** Fixed query count per chunk, no per-recipient DB or provider calls.
     * @param list<string> $hashes
     * @return list<array{V2LineRecipient, object}>
     */
    private function classify(object $bot, array $hashes, V2LineRecipientGroup $group): array
    {
        $identities = DB::table('external_identity_accounts as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('account_closures as c', 'c.user_id', '=', 'e.user_id')
            ->where('e.provider', 'line')->where('e.issuer', 'https://access.line.me')->whereIn('e.subject_hash', $hashes)
            ->select('e.*', 'u.state as user_state', 'c.id as closure_id')->get()->groupBy('subject_hash');
        $sources = [];
        foreach (self::SOURCES as $table) {
            $query = DB::table($table)->where('line_bot_id', $bot->id)->whereIn('subject_hash', $hashes);
            if ($table === 'line_friendships') $query->whereNull('superseded_at');
            $sources[$table] = $query->get()->keyBy('subject_hash');
        }
        $events = DB::table('line_webhook_events')->where('line_bot_id', $bot->id)->whereIn('subject_hash', $hashes)
            ->selectRaw("DISTINCT ON (subject_hash) subject_hash, event_type, COALESCE(event_timestamp_ms, (EXTRACT(EPOCH FROM occurred_at) * 1000)::bigint) AS observed_at_ms")
            ->orderBy('subject_hash')->orderByDesc('observed_at_ms')->orderByDesc('event_type')->get()->keyBy('subject_hash');
        $observations = DB::table('line_friend_observations as o')->join('external_identity_accounts as e', 'e.id', '=', 'o.external_identity_account_id')
            ->where('o.line_bot_id', $bot->id)->whereIn('e.subject_hash', $hashes)
            ->where('e.provider', 'line')->where('e.issuer', 'https://access.line.me')
            ->select('o.*', 'e.subject_hash')->get()->groupBy('subject_hash');
        $result = [];
        foreach ($hashes as $hash) {
            $accounts = $identities->get($hash, collect());
            $live = $accounts->filter(fn ($a) => $a->revoked_at === null && $a->superseded_at === null);
            if ($live->count() > 1) continue;
            $account = $live->first();
            $linked = $account !== null;
            if ($linked && (! in_array($account->user_state, ['active', 'restricted'], true) || $account->closure_id !== null)) continue;
            // Invalidated member identities must never fall through into the unlinked audience.
            if (! $linked && $accounts->isNotEmpty()) continue;
            $kind = $linked ? V2LineRecipientGroup::LinkedMembers : V2LineRecipientGroup::UnlinkedFriends;
            if ($group !== V2LineRecipientGroup::AllFriends && $group !== $kind) continue;
            $friendship = $sources['line_friendships']->get($hash);
            $pending = $sources['line_pending_follows']->get($hash);
            $recovered = $sources['line_recovered_friends']->get($hash);
            if ($linked) {
                if ($friendship !== null && (int) $friendship->user_id !== (int) $account->user_id) continue;
                if ($friendship !== null && $friendship->status === 'friend' && $friendship->unfollowed_at === null
                    && is_string($friendship->subject_ciphertext) && $friendship->subject_ciphertext !== '') {
                    $source = 'line_friendships'; $row = $friendship;
                } elseif ($recovered !== null) {
                    // Human D2/D7 decision: a real follower-list observation survives
                    // later linking, without inventing a follow date or granting a reward.
                    $source = 'line_recovered_friends'; $row = $recovered;
                } else continue;
            } else {
                if ($friendship !== null || ($pending !== null && $pending->status === 'claimed')) continue;
                if ($pending !== null && $pending->status === 'pending' && $pending->unfollowed_at === null) {
                    $source = 'line_pending_follows'; $row = $pending;
                } elseif ($recovered !== null) {
                    $source = 'line_recovered_friends'; $row = $recovered;
                } else continue;
            }
            // Merge observations without inventing a follow time. Equal-time negative wins.
            $latest = -1; $friend = false;
            $states = [];
            if ($recovered !== null) $states[] = [(int) $recovered->observed_at_ms, true];
            foreach ([$friendship, $pending] as $history) {
                if ($history?->unfollowed_at !== null) $states[] = [\Carbon\CarbonImmutable::parse($history->unfollowed_at)->getTimestampMs(), false];
            }
            if (($event = $events->get($hash)) !== null) $states[] = [(int) $event->observed_at_ms, $event->event_type === 'follow'];
            foreach ($observations->get($hash, collect()) as $observation) $states[] = [(int) $observation->observed_at_ms, (bool) $observation->friend];
            foreach ($states as [$time, $value]) {
                if ($time > $latest || ($time === $latest && ! $value)) { $latest = $time; $friend = $value; }
            }
            if (! $friend || ! is_string($row->subject_ciphertext) || $row->subject_ciphertext === '') continue;
            $result[] = [new V2LineRecipient((int) $bot->id, (int) $bot->authentication_revision,
                $source, (int) $row->id, $linked ? (int) $account->user_id : null, $kind), $row];
        }
        return $result;
    }
}
