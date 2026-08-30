<?php

namespace App\Domain\Mail\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\OutboxMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class V2IdentityMailOutboxWorker
{
    private const TOPICS = [
        'identity.password-reset',
        'identity.email-change-verification',
        'identity.email-change-completed',
        'identity.password-changed',
    ];

    public function __construct(
        private readonly V2OutboxService $outbox,
        private readonly V2TemplateMailDeliveryService $mail,
        private readonly V2IdentityMailUrlBuilder $urls,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function run(string $worker, int $limit = 10): int
    {
        $messages = $this->outbox->claim($worker, $limit, null, self::TOPICS);
        foreach ($messages as $message) {
            $this->process($message, $worker);
        }

        return $messages->count();
    }

    private function process(OutboxMessage $message, string $worker): void
    {
        try {
            [$template, $recipient, $values] = $this->delivery($message);
            $this->mail->sendSecurity($template, $recipient, $values);
            $this->outbox->markDelivered($message->public_id, $worker);
            $this->safeAudit($message, 'success');
        } catch (Throwable) {
            $maximum = (int) config('v2_outbox.identity_mail_maximum_attempts', 5);
            $errorCode = 'identity_mail_delivery_failed';
            if ($message->attempts >= $maximum) {
                $this->outbox->markFailed($message->public_id, $worker, $errorCode);
            } else {
                $this->outbox->retry(
                    $message->public_id,
                    $worker,
                    $errorCode,
                    (int) config('v2_outbox.identity_mail_retry_seconds', 60)
                );
            }
            Log::warning('Identity Mail Outbox delivery failed.', [
                'outbox_message_id' => $message->public_id,
                'topic' => $message->topic,
                'attempt' => $message->attempts,
            ]);
            $this->safeAudit($message, 'failure', $errorCode);
        }
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} */
    private function delivery(OutboxMessage $message): array
    {
        if (
            ! in_array($message->topic, self::TOPICS, true)
            || $message->aggregate_type !== 'user'
            || ! is_string($message->aggregate_public_id)
            || ! Str::isUuid($message->aggregate_public_id)
            || ($message->payload['encryption_format'] ?? null) !== 'laravel-v1'
            || ! is_string($message->payload['message_ciphertext'] ?? null)
        ) {
            throw new RuntimeException('Identity Mail Outbox message is invalid.');
        }
        $payload = json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        if (! is_array($payload)) {
            throw new RuntimeException('Identity Mail Outbox payload is invalid.');
        }
        $recipient = $payload['recipient'] ?? null;
        $userPublicId = $payload['user_public_id'] ?? null;
        if (
            ! is_string($recipient)
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($userPublicId)
            || ! Str::isUuid($userPublicId)
            || ! hash_equals($message->aggregate_public_id, $userPublicId)
        ) {
            throw new RuntimeException('Identity Mail Outbox recipient is invalid.');
        }
        $userName = DB::table('users')->where('public_id', $userPublicId)->value('display_name');
        $base = ['user_name' => is_string($userName) ? $userName : ''];

        return match ($message->topic) {
            'identity.password-reset' => [
                'password_reset',
                $recipient,
                [...$base, ...$this->passwordResetValues($payload)],
            ],
            'identity.email-change-verification' => [
                'email_change_verification',
                $recipient,
                [...$base, ...$this->emailChangeValues($payload)],
            ],
            'identity.email-change-completed' => [
                'email_change_completed',
                $recipient,
                $base,
            ],
            'identity.password-changed' => [
                'password_changed',
                $recipient,
                $base,
            ],
        };
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function passwordResetValues(array $payload): array
    {
        $this->assertExactKeys($payload, [
            'recipient',
            'redirect_path',
            'reset_token',
            'user_public_id',
        ]);
        $token = $this->token($payload['reset_token'] ?? null);
        $path = $this->path($payload['redirect_path'] ?? null);

        return [
            'reset_url' => $this->urls->passwordReset(
                (string) $payload['user_public_id'],
                $token,
                $path
            ),
            'expires_in_minutes' => (string) config(
                'v2_identity.password_reset.ttl_minutes',
                60
            ),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function emailChangeValues(array $payload): array
    {
        $this->assertExactKeys($payload, [
            'recipient',
            'redirect_path',
            'request_public_id',
            'user_public_id',
            'verification_token',
        ]);
        $requestPublicId = $payload['request_public_id'] ?? null;
        if (! is_string($requestPublicId) || ! Str::isUuid($requestPublicId)) {
            throw new RuntimeException('Identity Mail request is invalid.');
        }

        return [
            'email_change_verification_url' => $this->urls->emailChange(
                $requestPublicId,
                $this->token($payload['verification_token'] ?? null),
                $this->path($payload['redirect_path'] ?? null)
            ),
            'expires_in_minutes' => (string) config(
                'v2_identity.email_change.ttl_minutes',
                60
            ),
        ];
    }

    /** @param array<string, mixed> $payload @param list<string> $expected */
    private function assertExactKeys(array $payload, array $expected): void
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException('Identity Mail payload fields are invalid.');
        }
    }

    private function token(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A[0-9a-f]{64}\z/', $value)) {
            throw new RuntimeException('Identity Mail token is invalid.');
        }

        return $value;
    }

    private function path(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 255) {
            throw new RuntimeException('Identity Mail redirect is invalid.');
        }

        return $value;
    }

    private function safeAudit(
        OutboxMessage $message,
        string $outcome,
        ?string $reason = null
    ): void {
        try {
            $this->audit->record('identity.mail.'.($outcome === 'success' ? 'delivered' : 'failed'), [
                'actor_type' => 'system',
                'target_type' => 'outbox_message',
                'target_public_id' => $message->public_id,
                'outcome' => $outcome,
                'reason_code' => $reason,
                'metadata' => [
                    'topic' => $message->topic,
                    'attempt' => $message->attempts,
                ],
            ]);
        } catch (Throwable) {
        }
    }
}
