<?php

namespace App\Domain\Mail\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\OutboxMessage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class V2ContactReplyMailOutboxWorker
{
    public const TOPIC = 'contact.notification';
    public const EVENT = 'contact.reply.email.requested';

    public function __construct(
        private readonly V2OutboxService $outbox,
        private readonly V2ContactReplyMailService $mail,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function run(string $worker, int $limit = 10): int
    {
        if ($limit < 1 || $limit > (int) config('v2_outbox.maximum_claim_size', 100)) {
            throw new RuntimeException('Contact Mail claim limit is invalid.');
        }
        $processed = 0;
        while ($processed < $limit) {
            $message = $this->outbox->claim($worker, 1, null, [self::TOPIC], [self::EVENT])->first();
            if (! $message instanceof OutboxMessage) {
                break;
            }
            $this->process($message, $worker);
            $processed++;
        }
        return $processed;
    }

    private function process(OutboxMessage $message, string $worker): void
    {
        $outcome = 'failure';
        $error = 'contact_reply_delivery_failed';
        try {
            if ($message->attempts !== 1) {
                $error = 'contact_reply_delivery_uncertain';
                throw new RuntimeException('Contact Mail delivery requires reconciliation.');
            }
            $payload = $message->payload;
            $keys = array_keys($payload);
            sort($keys);
            if ($message->topic !== self::TOPIC || $message->event_type !== self::EVENT
                || $message->aggregate_type !== 'contact_inquiry'
                || $keys !== ['contact_public_id', 'reply_public_id']
                || ! is_string($payload['contact_public_id']) || ! Str::isUuid($payload['contact_public_id'])
                || ! is_string($payload['reply_public_id']) || ! Str::isUuid($payload['reply_public_id'])
                || $message->aggregate_public_id !== $payload['contact_public_id']) {
                throw new RuntimeException('Contact Mail payload is invalid.');
            }
            $mail = $this->mail->render($payload['contact_public_id'], $payload['reply_public_id']);
            $message->refresh();
            if ($message->status !== 'processing' || $message->locked_by !== $worker
                || $message->attempts !== 1 || $message->lease_expires_at?->isFuture() !== true) {
                throw new RuntimeException('Contact Mail lease is unavailable.');
            }
            $error = 'contact_reply_delivery_uncertain';
            $this->mail->send($mail);
            $this->outbox->markDelivered($message->public_id, $worker);
            $outcome = 'success';
            $error = null;
        } catch (Throwable) {
            try {
                $this->outbox->markFailed($message->public_id, $worker, $error);
            } catch (Throwable) {
            }
        }
        try {
            $this->audit->record('contact.reply_mail_processed', [
                'actor_type' => 'system',
                'target_type' => 'outbox_message',
                'target_public_id' => $message->public_id,
                'outcome' => $outcome,
                'reason_code' => $error,
                'metadata' => ['attempt' => $message->attempts],
            ]);
        } catch (Throwable) {
        }
    }
}
