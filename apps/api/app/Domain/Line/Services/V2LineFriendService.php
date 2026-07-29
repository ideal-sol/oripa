<?php

namespace App\Domain\Line\Services;

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
    public function handleEvents(array $events, string $requestId): void
    {
        if (count($events) > 100) {
            throw $this->invalidWebhook();
        }
        foreach ($events as $event) {
            $type = $event['type'] ?? null;
            if (! in_array($type, ['follow', 'unfollow'], true)) {
                continue;
            }
            $reply = $this->processEvent($event, $requestId);
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
        string $requestId
    ): void {
        if (DB::transactionLevel() < 1 || ! preg_match('/\A[0-9a-f]{64}\z/', $subjectHash)) {
            throw new V2LineMessagingException(
                'LINE_IDENTITY_RECONCILIATION_FAILED',
                503,
                'LINE identity reconciliation failed.',
                true
            );
        }
        $pending = LinePendingFollow::query()
            ->where('subject_hash', $subjectHash)
            ->lockForUpdate()
            ->first();
        if (! $pending instanceof LinePendingFollow || $pending->status !== 'pending') {
            return;
        }
        $setting = $this->settings->setting(true);
        $friendship = $this->friendship($subjectHash, $user, $pending->followed_at);
        $this->grantReward(
            $friendship,
            $setting,
            $user,
            CarbonImmutable::now()->startOfSecond()
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

    /**
     * @param array<string, mixed> $event
     * @return array{event_public_id: string, reply_token: string, message: string}|null
     */
    private function processEvent(array $event, string $requestId): ?array
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
            $subjectHash,
            $type,
            $occurredAt,
            $replyToken,
            $requestId
        ): ?array {
            $eventPublicId = (string) Str::uuid7();
            $inserted = DB::table('line_webhook_events')->insertOrIgnore([
                'public_id' => $eventPublicId,
                'event_id_hash' => $eventHash,
                'subject_hash' => $subjectHash,
                'event_type' => $type,
                'reply_status' => $type === 'follow' ? 'pending' : 'skipped',
                'reply_failure_code' => null,
                'occurred_at' => $occurredAt,
                'reply_attempted_at' => null,
                'created_at' => now()->startOfSecond(),
                'updated_at' => now()->startOfSecond(),
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
                $this->grantReward($friendship, $setting, $user, $occurredAt);
                $template = $setting->linked_follow_message;
                $targetPublicId = $friendship->public_id;
                $eventType = 'identity.line_follow.linked';
                $rewarded = $friendship->point_operation_id !== null;
            } else {
                $pending = LinePendingFollow::query()
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
        $friendship = LineFriendship::query()
            ->where('subject_hash', $subjectHash)
            ->lockForUpdate()
            ->first();
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
        \DateTimeInterface $occurredAt
    ): void {
        if ($friendship->point_operation_id !== null) {
            return;
        }
        $amount = (int) $setting->reward_point_amount;
        if ($amount === 0) {
            return;
        }
        $operation = $this->points->grantLineFriendReward(
            (int) $user->getKey(),
            (int) $friendship->getKey(),
            $friendship->public_id,
            $amount,
            CarbonImmutable::parse($occurredAt)->addDays(
                (int) $setting->reward_expiration_days
            ),
            $occurredAt
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
        $friendship = LineFriendship::query()
            ->where('subject_hash', $subjectHash)
            ->lockForUpdate()
            ->first();
        $pending = LinePendingFollow::query()
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
            $event = LineWebhookEvent::query()
                ->where('public_id', $eventPublicId)
                ->where('reply_status', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $event instanceof LineWebhookEvent) {
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

        try {
            $result = $this->transport->replyText($replyToken, $message);
        } catch (Throwable) {
            $result = new \App\Domain\Line\ValueObjects\V2LineReplyResult(
                false,
                'transport_failure'
            );
        }
        DB::transaction(function () use ($eventPublicId, $result, $requestId): void {
            $event = LineWebhookEvent::query()
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

    private function invalidWebhook(): V2LineMessagingException
    {
        return new V2LineMessagingException(
            'LINE_WEBHOOK_INVALID',
            422,
            'The LINE webhook request is invalid.'
        );
    }
}
