<?php

namespace App\Domain\Line\Services;

use App\Support\V2DatabaseTimestamp;
use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Line\Contracts\V2LineMessagingTransport;
use App\Domain\Line\Exceptions\V2LineMessagingException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\LineFriendship;
use App\Models\V2\LineMessagingSetting;
use App\Models\V2\LinePendingFollow;
use App\Models\V2\LineWebhookEvent;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class V2LineFriendService
{
    public function __construct(
        private readonly V2IdentityCorrelation $correlation,
        private readonly V2LineMessagingSettingService $settings,
        private readonly V2LineMessageTemplate $templates,
        private readonly V2PointService $points,
        private readonly V2LineMessagingTransport $transport,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    public function verifySignature(
        #[SensitiveParameter] string $rawBody,
        #[SensitiveParameter] ?string $signature
    ): bool {
        $secret = config('v2_line.messaging.channel_secret');
        if (! is_string($secret) || $secret === '' || ! is_string($signature)) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }

    /** @param list<array<string, mixed>> $events */
    public function handleEvents(#[SensitiveParameter] array $events, string $requestId, ?int $expectedBotId = null): void
    {
        if (count($events) > 100) {
            throw $this->invalidWebhook();
        }
        foreach ($events as $event) {
            $subject = $event['source']['userId'] ?? null;
            if (is_string($subject) && $subject !== '' && strlen($subject) <= 255) {
                DB::transaction(function () use ($subject, $expectedBotId): void {
                    $this->settings->setting(true);
                    if ($expectedBotId !== null && app(V2LineConfiguration::class)->botId() !== $expectedBotId) return;
                    app(V2LineSubjectVault::class)->backfill($subject);
                });
            }
            $type = $event['type'] ?? null;
            if (! in_array($type, ['follow', 'unfollow'], true)) {
                continue;
            }
            $reply = $this->processEvent($event, $requestId, $expectedBotId);
            if ($reply !== null) {
                $this->sendReply(
                    $reply['event_public_id'],
                    $reply['reply_token'],
                    $reply['message'],
                    $requestId
                );
            }
        }
    }

    public function reconcileIdentity(
        string $subjectHash,
        User $user,
        string $requestId,
        bool $grantReward = true,
        array $subjectHashes = []
    ): void {
        if (DB::transactionLevel() < 1 || ! preg_match('/\A[0-9a-f]{64}\z/', $subjectHash)) {
            throw new V2LineMessagingException(
                'LINE_IDENTITY_RECONCILIATION_FAILED',
                503,
                'LINE identity reconciliation failed.',
                true
            );
        }
        $subjectHashes = $subjectHashes ?: [$subjectHash];
        if ($grantReward && $this->pendingFollows()->whereIn('subject_hash', $subjectHashes)->where('status', 'pending')->exists()) {
            app(\App\Domain\Point\Services\V2FinancialAdmission::class)->enter();
        }
        $pending = $this->pendingFollows()
            ->whereIn('subject_hash', $subjectHashes)
            ->lockForUpdate()
            ->first();
        if (! $pending instanceof LinePendingFollow || $pending->status !== 'pending') {
            return;
        }
        $setting = $this->settings->setting(true);
        $friendship = $this->friendship($subjectHash, $user, $pending->followed_at);
        if ($grantReward) $this->grantReward(
            $friendship,
            $setting,
            $user,
            CarbonImmutable::now()->startOfSecond(),
            $requestId
        );
        $pending->forceFill([
            'status' => 'claimed',
            'claimed_by_user_id' => $user->getKey(),
            'claimed_at' => now()->startOfSecond(),
        ])->save();
        $this->audit->record('line.friend.pending_claimed', [
            'request_id' => $requestId,
            'actor_type' => 'system',
            'auth_realm' => 'user',
            'target_type' => 'line_friendship',
            'target_public_id' => $friendship->public_id,
            'outcome' => 'success',
            'metadata' => [
                'rewarded' => $friendship->point_operation_id !== null,
                'user_public_id' => $user->public_id,
            ],
        ]);
        $this->outbox->enqueue(
            'identity.line-pending-follow-claimed',
            'line_friendship',
            $friendship->public_id,
            'identity.line_pending_follow.claimed',
            ['rewarded' => $friendship->point_operation_id !== null],
            'line-pending-claimed:'.$pending->public_id
        );
    }

    public function applyObservation(
        #[SensitiveParameter] string $subject,
        User $user,
        bool $friend,
        CarbonImmutable $observedAt,
        string $requestId,
        ?int $expectedBotId = null,
        ?int $expectedLoginRevision = null,
        ?int $expectedBotRevision = null
    ): ?string {
        try {
            DB::transaction(function () use ($subject, $user, $friend, $observedAt, $expectedBotId, $expectedLoginRevision, $expectedBotRevision): void {
                $this->settings->setting(true);
                if (! $this->configurationMatches($expectedBotId, $expectedLoginRevision, $expectedBotRevision)) return;
                $account = ExternalIdentityAccount::query()->where('provider', 'line')
                    ->where('issuer', 'https://access.line.me')->where('user_id', $user->id)
                    ->whereIn('subject_hash', app(V2LineSubjectVault::class)->hashes($subject))
                    ->whereNull('revoked_at')->whereNull('superseded_at')->lockForUpdate()->firstOrFail();
                if (! $this->observe($account, $observedAt->getTimestampMs(), $friend)) return;
                if ($friend) {
                    $this->friendship($account->subject_hash, $user, $observedAt->startOfSecond());
                } else {
                    $this->friendships()->where('subject_hash', $account->subject_hash)
                        ->where('user_id', $user->id)->whereNull('superseded_at')->where('status', 'friend')
                        ->update(['status' => 'unfollowed', 'unfollowed_at' => $observedAt->startOfSecond()]);
                }
                app(V2LineSubjectVault::class)->backfill($subject);
            }, 3);
        } catch (Throwable) {
            $this->recordCheckFailure($user, $requestId, 'friendship_check_failed');
            return 'friendship_check_failed';
        }
        if (! $friend) return null;
        // A separate transaction preserves the observed state on financial failure.
        try {
            DB::transaction(function () use ($subject, $user, $observedAt, $requestId, $expectedBotId, $expectedLoginRevision, $expectedBotRevision): void {
                app(\App\Domain\Point\Services\V2FinancialAdmission::class)->enter();
                $setting = $this->settings->setting(true);
                if (! $setting->reward_enabled || ! $this->configurationMatches($expectedBotId, $expectedLoginRevision, $expectedBotRevision)) return;
                $account = ExternalIdentityAccount::query()->where('provider', 'line')->where('user_id', $user->id)
                    ->whereIn('subject_hash', app(V2LineSubjectVault::class)->hashes($subject))
                    ->whereNull('revoked_at')->whereNull('superseded_at')->lockForUpdate()->first();
                if ($account === null) return;
                $friendship = $this->friendships()->where('subject_hash', $account->subject_hash)
                    ->where('user_id', $user->id)->whereNull('superseded_at')->where('status', 'friend')->lockForUpdate()->first();
                if ($friendship !== null) $this->grantReward($friendship, $setting, $user, $observedAt->startOfSecond(), $requestId);
            }, 3);
        } catch (Throwable) {
            $this->recordCheckFailure($user, $requestId, 'reward_apply_failed');
            return 'reward_apply_failed';
        }
        return null;
    }

    private function configurationMatches(?int $botId, ?int $loginRevision, ?int $botRevision): bool
    {
        $configuration = app(V2LineConfiguration::class);
        $bot = $configuration->currentBot();
        return ($botId === null || (int) $bot?->id === $botId)
            && ($loginRevision === null || $configuration->loginRevision() === $loginRevision)
            && ($botRevision === null || (int) $bot?->authentication_revision === $botRevision);
    }

    private function recordCheckFailure(User $user, string $requestId, string $reason): void
    {
        try {
            $this->audit->record('line.friend.check.failed', [
            'request_id' => $requestId, 'actor_type' => 'system', 'auth_realm' => 'user',
            'target_type' => 'user', 'target_public_id' => $user->public_id,
            'outcome' => 'failure', 'reason_code' => $reason,
            ]);
        } catch (Throwable) {
            \Illuminate\Support\Facades\Log::warning('line_check_audit_unavailable', ['reason_code' => $reason]);
        }
    }

    private function observe(ExternalIdentityAccount $account, int $milliseconds, bool $friend): bool
    {
        // Equal-time unfollow wins, regardless of arrival order or observation source.
        $botId = app(V2LineConfiguration::class)->botId();
        $snapshot = DB::table('line_friend_observations')->where('line_bot_id', $botId)->where('external_identity_account_id', $account->id)->lockForUpdate()->first();
        $previous = $snapshot?->observed_at_ms;
        if ($previous !== null && ((int) $previous > $milliseconds
            || ((int) $previous === $milliseconds && (! $snapshot->friend || $friend)))) return false;
        DB::table('line_friend_observations')->updateOrInsert(
            ['line_bot_id' => $botId, 'external_identity_account_id' => $account->id],
            ['observed_at_ms' => $milliseconds, 'friend' => $friend]
        );
        if (DB::table('line_bots')->where('id', $botId)->value('legacy')) {
            $account->forceFill(['line_observed_at_ms' => $milliseconds, 'line_observed_friend' => $friend])->save();
        }
        return true;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{event_public_id: string, reply_token: string, message: string}|null
     */
    private function processEvent(#[SensitiveParameter] array $event, string $requestId, ?int $expectedBotId): ?array
    {
        $eventId = $event['webhookEventId'] ?? null;
        $type = $event['type'] ?? null;
        $rawSubject = $event['source']['userId'] ?? null;
        $sourceType = $event['source']['type'] ?? null;
        $timestamp = $event['timestamp'] ?? null;
        if (
            ! is_string($eventId)
            || $eventId === ''
            || strlen($eventId) > 128
            || ! is_string($rawSubject)
            || $rawSubject === ''
            || strlen($rawSubject) > 191
            || $sourceType !== 'user'
            || ! is_int($timestamp)
            || $timestamp < 0
            || ! in_array($type, ['follow', 'unfollow'], true)
        ) {
            throw $this->invalidWebhook();
        }
        $replyToken = $event['replyToken'] ?? null;
        if ($type === 'follow' && (! is_string($replyToken) || $replyToken === '')) {
            throw $this->invalidWebhook();
        }
        $subjectHash = $this->subjectHash($rawSubject);
        $eventHash = $this->correlation->hash('line-webhook-event|'.$eventId);
        $occurredAt = CarbonImmutable::createFromTimestampUTC(
            intdiv($timestamp, 1000)
        )->startOfSecond();

        return DB::transaction(function () use (
            $eventHash,
            $expectedBotId,
            $subjectHash,
            $rawSubject,
            $timestamp,
            $eventId,
            $type,
            $occurredAt,
            $replyToken,
            $requestId
        ): ?array {
            if ($type === 'follow') {
                app(\App\Domain\Point\Services\V2FinancialAdmission::class)->enter();
            }
            $setting = $this->settings->setting(true);
            if ($expectedBotId !== null && app(V2LineConfiguration::class)->botId() !== $expectedBotId) return null;
            if ($this->webhookEvents()->whereIn('event_id_hash', $this->correlation->hashes('line-webhook-event|'.$eventId))->exists()) {
                $this->audit->record('line.webhook.redelivered', ['request_id' => $requestId,
                    'actor_type' => 'system', 'auth_realm' => 'system', 'target_type' => 'line_webhook_event',
                    'outcome' => 'success', 'reason_code' => 'duplicate_event']);
                return null;
            }
            $subjectHash = app(V2LineSubjectVault::class)->canonicalHash($rawSubject);
            $account = ExternalIdentityAccount::query()->where('provider', 'line')
                ->where('issuer', 'https://access.line.me')->where('subject_hash', $subjectHash)
                ->whereNull('revoked_at')->whereNull('superseded_at')->lockForUpdate()->first();
            $previousEvent = $this->webhookEvents()->where('subject_hash', $subjectHash)
                ->orderByRaw('COALESCE(event_timestamp_ms, EXTRACT(EPOCH FROM occurred_at) * 1000) DESC')
                ->orderByDesc('event_type')->first();
            $previousTime = $previousEvent?->event_timestamp_ms ?? $previousEvent?->occurred_at?->getTimestampMs();
            $stale = $previousTime !== null && ($previousTime > $timestamp
                || ($previousTime === $timestamp && $previousEvent->event_type === 'unfollow' && $type === 'follow'));
            if (! $stale && $account !== null) $stale = ! $this->observe($account, $timestamp, $type === 'follow');
            $eventPublicId = (string) Str::uuid7();
            $inserted = DB::table('line_webhook_events')->insertOrIgnore([
                'line_bot_id' => app(V2LineConfiguration::class)->botId(),
                'public_id' => $eventPublicId,
                'event_id_hash' => $eventHash,
                'subject_hash' => $subjectHash,
                'event_type' => $type,
                'stale_observation' => $stale,
                'event_timestamp_ms' => $timestamp,
                'reply_status' => $type === 'follow' && ! $stale ? 'pending' : 'skipped',
                'reply_failure_code' => null,
                'occurred_at' => V2DatabaseTimestamp::format($occurredAt),
                'reply_attempted_at' => null,
                'created_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                'updated_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
            ]);
            if ($inserted === 0) {
                $this->audit->record('line.webhook.redelivered', [
                    'request_id' => $requestId,
                    'actor_type' => 'system',
                    'auth_realm' => 'system',
                    'target_type' => 'line_webhook_event',
                    'outcome' => 'success',
                    'reason_code' => 'duplicate_event',
                ]);

                return null;
            }
            if ($stale) return null;

            if ($type === 'unfollow') {
                $this->unfollow($subjectHash, $occurredAt, $eventPublicId, $requestId);

                return null;
            }

            $setting = $this->settings->setting(true);
            $account = ExternalIdentityAccount::query()
                ->where('provider', 'line')
                ->where('issuer', 'https://access.line.me')
                ->where('subject_hash', $subjectHash)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if ($account instanceof ExternalIdentityAccount) {
                $user = User::query()->whereKey($account->user_id)->lockForUpdate()->firstOrFail();
                $friendship = $this->friendship($subjectHash, $user, $occurredAt);
                $this->grantReward(
                    $friendship,
                    $setting,
                    $user,
                    $occurredAt,
                    $requestId
                );
                $template = $setting->linked_follow_message;
                $targetPublicId = $friendship->public_id;
                $eventType = 'identity.line_follow.linked';
                $rewarded = $friendship->point_operation_id !== null;
            } else {
                $pending = $this->pendingFollows()
                    ->where('subject_hash', $subjectHash)
                    ->lockForUpdate()
                    ->first();
                if ($pending instanceof LinePendingFollow && $pending->status === 'claimed') {
                    throw new V2LineMessagingException(
                        'LINE_FOLLOW_STATE_CONFLICT',
                        409,
                        'LINE follow state conflicts with identity state.'
                    );
                }
                if (! $pending instanceof LinePendingFollow) {
                    $pending = new LinePendingFollow();
                    $pending->forceFill([
                        'line_bot_id' => app(V2LineConfiguration::class)->botId(),
                        'public_id' => (string) Str::uuid7(),
                        'subject_hash' => $subjectHash,
                        'status' => 'pending',
                        'followed_at' => $occurredAt,
                    ])->save();
                } else {
                    $pending->forceFill([
                        'status' => 'pending',
                        'followed_at' => $occurredAt,
                        'unfollowed_at' => null,
                    ])->save();
                }
                $template = $setting->pending_follow_message;
                $targetPublicId = $pending->public_id;
                $eventType = 'identity.line_follow.pending';
                $rewarded = false;
            }
            $message = $this->templates->render($template);
            app(V2LineSubjectVault::class)->backfill($rawSubject);
            $this->audit->record('line.friend.followed', [
                'request_id' => $requestId,
                'actor_type' => 'system',
                'auth_realm' => 'system',
                'target_type' => $account === null
                    ? 'line_pending_follow'
                    : 'line_friendship',
                'target_public_id' => $targetPublicId,
                'outcome' => 'success',
                'metadata' => ['identity_linked' => $account !== null, 'rewarded' => $rewarded],
            ]);
            $this->outbox->enqueue(
                'identity.line-follow-processed',
                $account === null ? 'line_pending_follow' : 'line_friendship',
                $targetPublicId,
                $eventType,
                ['identity_linked' => $account !== null, 'rewarded' => $rewarded],
                'line-follow:'.$eventPublicId
            );

            return [
                'event_public_id' => $eventPublicId,
                'reply_token' => $replyToken,
                'message' => $message,
            ];
        }, 3);
    }

    private function friendship(
        string $subjectHash,
        User $user,
        \DateTimeInterface $followedAt
    ): LineFriendship {
        $friendship = $this->friendships()
            ->where('subject_hash', $subjectHash)
            ->whereNull('superseded_at')
            ->lockForUpdate()
            ->first();
        if ($friendship !== null && (int) $friendship->user_id !== (int) $user->id
            && DB::table('account_closures')->where('user_id', $friendship->user_id)->where('closure_kind', 'normal')->exists()) {
            $friendship->forceFill(['superseded_at' => now()->startOfSecond()])->save();
            $friendship = null;
        }
        if (
            $friendship instanceof LineFriendship
            && (int) $friendship->user_id !== (int) $user->getKey()
        ) {
            throw new V2LineMessagingException(
                'LINE_FOLLOW_STATE_CONFLICT',
                409,
                'LINE follow state conflicts with identity state.'
            );
        }
        if (! $friendship instanceof LineFriendship) {
            $friendship = new LineFriendship();
            $friendship->forceFill([
                'line_bot_id' => app(V2LineConfiguration::class)->botId(),
                'public_id' => (string) Str::uuid7(),
                'subject_hash' => $subjectHash,
                'user_id' => $user->getKey(),
                'status' => 'friend',
                'followed_at' => $followedAt,
            ])->save();

            return $friendship;
        }
        $friendship->forceFill([
            'status' => 'friend',
            'followed_at' => $this->latest(
                $friendship->followed_at,
                $followedAt
            ),
            'unfollowed_at' => null,
        ])->save();

        return $friendship;
    }

    private function grantReward(
        LineFriendship $friendship,
        LineMessagingSetting $setting,
        User $user,
        \DateTimeInterface $occurredAt,
        string $requestId
    ): void {
        if ($friendship->point_operation_id !== null || DB::table('line_friendships')->where('user_id', $user->id)->whereNotNull('point_operation_id')->exists()) {
            return;
        }
        if (DB::table('account_closures')->where('user_id', $user->id)->exists()
            || DB::table('line_friendships')->where('subject_hash', $friendship->subject_hash)->where('user_id', '<>', $user->id)->exists()
            || app(\App\Domain\Identity\Services\V2PhoneBenefitEligibility::class)->reused((int) $user->id)) return;
        if (! $setting->reward_enabled) {
            $this->audit->record('line.friend.reward.skipped', [
                'request_id' => $requestId,
                'actor_type' => 'system',
                'auth_realm' => 'system',
                'target_type' => 'line_friendship',
                'target_public_id' => $friendship->public_id,
                'outcome' => 'success',
                'reason_code' => 'reward_disabled',
            ]);

            return;
        }
        $amount = (int) $setting->reward_point_amount;
        $operation = $this->points->grantLineFriendReward(
            (int) $user->getKey(),
            (int) $friendship->getKey(),
            $friendship->public_id,
            $amount,
            $occurredAt,
            (int) $setting->reward_expiration_days
        );
        if ($operation === null) {
            throw new V2LineMessagingException(
                'LINE_FRIEND_REWARD_FAILED',
                503,
                'LINE friend reward could not be completed.',
                true
            );
        }
        $friendship->forceFill([
            'point_operation_id' => $operation->getKey(),
            'rewarded_at' => now()->startOfSecond(),
        ])->save();
    }

    private function unfollow(
        string $subjectHash,
        CarbonImmutable $occurredAt,
        string $eventPublicId,
        string $requestId
    ): void {
        $friendship = $this->friendships()
            ->where('subject_hash', $subjectHash)
            ->whereNull('superseded_at')
            ->lockForUpdate()
            ->first();
        $pending = $this->pendingFollows()
            ->where('subject_hash', $subjectHash)
            ->lockForUpdate()
            ->first();
        if ($friendship instanceof LineFriendship) {
            $friendship->forceFill([
                'status' => 'unfollowed',
                'unfollowed_at' => $occurredAt,
            ])->save();
        }
        if ($pending instanceof LinePendingFollow && $pending->status === 'pending') {
            $pending->forceFill([
                'status' => 'unfollowed',
                'unfollowed_at' => $occurredAt,
            ])->save();
        }
        $target = $friendship?->public_id ?? $pending?->public_id;
        $this->audit->record('line.friend.unfollowed', [
            'request_id' => $requestId,
            'actor_type' => 'system',
            'auth_realm' => 'system',
            'target_type' => 'line_friendship',
            'target_public_id' => $target,
            'outcome' => 'success',
        ]);
        $this->outbox->enqueue(
            'identity.line-unfollow-processed',
            'line_webhook_event',
            $eventPublicId,
            'identity.line_follow.unfollowed',
            ['friendship_known' => $target !== null],
            'line-unfollow:'.$eventPublicId
        );
    }

    private function sendReply(
        string $eventPublicId,
        #[SensitiveParameter] string $replyToken,
        string $message,
        string $requestId
    ): void {
        $claimed = DB::transaction(function () use ($eventPublicId): bool {
            $this->settings->setting(true);
            $event = $this->webhookEvents()
                ->where('public_id', $eventPublicId)
                ->where('reply_status', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $event instanceof LineWebhookEvent) {
                return false;
            }
            $closed = DB::table('external_identity_accounts as e')->join('account_closures as c', 'c.user_id', '=', 'e.user_id')
                ->where('e.provider', 'line')->where('e.subject_hash', $event->subject_hash)
                ->where('e.created_at', '<=', $event->created_at)
                ->where(fn ($q) => $q->whereNull('e.superseded_at')->orWhere('e.superseded_at', '>=', $event->created_at))->exists();
            if ($closed) {
                $event->forceFill(['reply_status' => 'skipped'])->save();
                return false;
            }
            $event->forceFill([
                'reply_status' => 'processing',
                'reply_attempted_at' => now()->startOfSecond(),
            ])->save();

            return true;
        }, 3);
        if (! $claimed) {
            return;
        }

        DB::transaction(function () use ($eventPublicId, $replyToken, $message, $requestId): void {
            $this->settings->setting(true);
            $claimedEvent = LineWebhookEvent::query()->where('public_id', $eventPublicId)->firstOrFail();
            if ((int) $claimedEvent->line_bot_id !== app(V2LineConfiguration::class)->botId()) {
                $claimedEvent->forceFill(['reply_status' => 'failed', 'reply_failure_code' => 'configuration_unavailable'])->save();
                return;
            }
            try {
                $result = $this->transport->replyText($replyToken, $message);
            } catch (Throwable) {
                $result = new \App\Domain\Line\ValueObjects\V2LineReplyResult(
                    false,
                    'transport_failure'
                );
            }
            DB::transaction(function () use ($eventPublicId, $result, $requestId): void {
                $event = $this->webhookEvents()
                    ->where('public_id', $eventPublicId)
                    ->where('reply_status', 'processing')
                    ->lockForUpdate()
                    ->firstOrFail();
                $event->forceFill([
                    'reply_status' => $result->succeeded ? 'sent' : 'failed',
                    'reply_failure_code' => $result->failureCode,
                ])->save();
                $this->audit->record(
                    $result->succeeded
                        ? 'line.messaging.reply.succeeded'
                        : 'line.messaging.reply.failed',
                    [
                        'request_id' => $requestId,
                        'actor_type' => 'system',
                        'auth_realm' => 'system',
                        'target_type' => 'line_webhook_event',
                        'target_public_id' => $event->public_id,
                        'outcome' => $result->succeeded ? 'success' : 'failure',
                        'reason_code' => $result->failureCode,
                    ]
                );
            }, 3);
        }, 1); // Provider side effects must never be retried by the DB transaction.
    }

    private function subjectHash(#[SensitiveParameter] string $rawSubject): string
    {
        return $this->correlation->hash(
            'line|https://access.line.me|'.$rawSubject
        );
    }

    private function latest(
        \DateTimeInterface $first,
        \DateTimeInterface $second
    ): CarbonImmutable {
        $left = CarbonImmutable::parse($first);
        $right = CarbonImmutable::parse($second);

        return $left->greaterThan($right) ? $left : $right;
    }

    private function friendships(): \Illuminate\Database\Eloquent\Builder
    {
        return LineFriendship::query()->where('line_bot_id', app(V2LineConfiguration::class)->botId());
    }

    private function pendingFollows(): \Illuminate\Database\Eloquent\Builder
    {
        return LinePendingFollow::query()->where('line_bot_id', app(V2LineConfiguration::class)->botId());
    }

    private function webhookEvents(): \Illuminate\Database\Eloquent\Builder
    {
        return LineWebhookEvent::query()->where('line_bot_id', app(V2LineConfiguration::class)->botId());
    }

    private function invalidWebhook(): V2LineMessagingException
    {
        return new V2LineMessagingException(
            'LINE_WEBHOOK_INVALID',
            422,
            'The LINE webhook request is invalid.'
        );
    }
}
