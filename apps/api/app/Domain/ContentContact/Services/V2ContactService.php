<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2EmailNormalizer;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\ContactInquiry;
use App\Models\V2\User;
use App\Support\V2HmacKeyring;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Normalizer;
use RuntimeException;

final class V2ContactService
{
    public function __construct(
        private readonly V2EmailNormalizer $emails,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox,
        private readonly V2HmacKeyring $keyring
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(
        array $input,
        ?User $user,
        string $ip,
        string $requestId
    ): array {
        $validationReason = 'invalid_contact';
        try {
            if (($input['website'] ?? '') !== '') {
                $validationReason = 'honeypot';
                throw $this->invalid();
            }
            $name = $this->text($input, 'name', 1, 120);
            $email = $this->emails->normalize($this->text($input, 'email', 3, 320));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw $this->invalid();
            }
            $phone = $this->nullableText($input, 'phone', 32);
            $subject = $this->text($input, 'subject', 1, 191);
            $body = $this->text($input, 'body', 1, 5000);
            if (strlen($body) > (int) config(
                'v2_content_contact.contact_body_max_bytes',
                20_000
            )) {
                throw $this->invalid();
            }
        } catch (V2ContentContactException $exception) {
            $this->auditRejected($requestId, $validationReason);
            throw $exception;
        }
        try {
            $this->rateLimiter->assertGlobal('contact_ip', $ip);
            $this->rateLimiter->assertSubject('contact_email', $email);
        } catch (V2AuthenticationException $exception) {
            $this->audit->record(
                $exception->errorCode === 'RATE_LIMITED'
                    ? 'contact.rate_limited'
                    : 'contact.rate_limit_unavailable',
                [
                    'request_id' => $requestId,
                    'actor_type' => $user === null ? 'system' : 'user',
                    'actor_public_id' => $user?->public_id,
                    'auth_realm' => $user === null ? null : 'user',
                    'outcome' => 'failure',
                    'reason_code' => strtolower($exception->errorCode),
                ]
            );
            throw $exception;
        }
        $now = CarbonImmutable::parse(now())->startOfSecond();

        return DB::transaction(function () use (
            $name,
            $email,
            $phone,
            $subject,
            $body,
            $user,
            $requestId,
            $now
        ): array {
            $inquiry = new ContactInquiry();
            $inquiry->forceFill([
                'receipt_code' => 'CNT-'.strtoupper(Str::random(20)),
                'user_id' => $user?->id,
                'name_ciphertext' => Crypt::encryptString($name),
                'email_ciphertext' => Crypt::encryptString($email),
                'phone_ciphertext' => $phone === null ? null : Crypt::encryptString($phone),
                'subject_ciphertext' => Crypt::encryptString($subject),
                'body_ciphertext' => Crypt::encryptString($body),
                'email_correlation_hash' => $this->correlation($email),
                'status' => 'new',
                'received_at' => $now,
                'retention_until' => $now->addDays(
                    (int) config('v2_content_contact.contact_retention_days', 365)
                ),
            ])->save();
            DB::table('contact_status_histories')->insert([
                'contact_inquiry_id' => $inquiry->id,
                'from_status' => null,
                'to_status' => 'new',
                'actor_admin_id' => null,
                'reason_code' => 'contact_received',
                'request_id' => $requestId,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->record('contact.received', [
                'request_id' => $requestId,
                'actor_type' => $user === null ? 'system' : 'user',
                'actor_public_id' => $user?->public_id,
                'auth_realm' => $user === null ? null : 'user',
                'target_type' => 'contact_inquiry',
                'target_public_id' => $inquiry->public_id,
                'outcome' => 'success',
                'metadata' => ['authenticated' => $user !== null],
            ]);
            foreach (['contact.receipt.requested', 'contact.admin_notification.requested'] as $event) {
                $this->outbox->enqueue(
                    'contact.notification',
                    'contact_inquiry',
                    $inquiry->public_id,
                    $event,
                    ['contact_public_id' => $inquiry->public_id],
                    $event.':'.$inquiry->public_id
                );
            }

            return [
                'receipt_code' => $inquiry->receipt_code,
                'status' => 'accepted',
                'received_at' => $now->toIso8601String(),
                'request_id' => $requestId,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $field, int $minimum, int $maximum): string
    {
        $value = $input[$field] ?? null;
        if (! is_string($value)) {
            throw $this->invalid();
        }
        $value = $this->normalize(trim($value));
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw $this->invalid();
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function nullableText(array $input, string $field, int $maximum): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return $this->text($input, $field, 1, $maximum);
    }

    private function normalize(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            throw new RuntimeException('Unicode normalization support is unavailable.');
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (! is_string($normalized)) {
            throw $this->invalid();
        }

        return $normalized;
    }

    private function correlation(string $email): string
    {
        return $this->keyring->activeHash(
            'v2_content_contact.contact_hmac_key',
            $email,
            'Contact correlation key'
        );
    }

    private function auditRejected(string $requestId, string $reason): void
    {
        $this->audit->record('contact.validation_rejected', [
            'request_id' => $requestId,
            'actor_type' => 'system',
            'outcome' => 'failure',
            'reason_code' => $reason,
        ]);
    }

    private function invalid(): V2ContentContactException
    {
        return new V2ContentContactException(
            'CONTACT_REQUEST_INVALID',
            422,
            'The Contact request is invalid.'
        );
    }
}
