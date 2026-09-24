<?php

namespace App\Domain\ContentContact\Services;

use App\Support\V2DatabaseTimestamp;
use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2EmailNormalizer;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Exceptions\V2PointException;
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
        private readonly V2HmacKeyring $keyring,
        private readonly V2TemplateMailDeliveryService $templateMail,
        private readonly V2PointIdempotencyService $idempotency
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(
        array $input,
        ?User $user,
        string $ip,
        string $requestId,
        string $idempotencyKey = ''
    ): array {
        if (! $user instanceof User) {
            throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
        }
        $validationReason = 'invalid_contact';
        try {
            if (array_diff(array_keys($input), ['name', 'email', 'phone', 'subject', 'body', 'website', 'inquiry_id']) !== []) {
                throw $this->invalid();
            }
            $inquiryId = $input['inquiry_id'] ?? null;
            if (array_key_exists('inquiry_id', $input) && (! is_string($inquiryId) || ! Str::isUuid($inquiryId))) {
                throw $this->invalid();
            }
            if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
                throw $this->invalid();
            }
            if (($input['website'] ?? '') !== '') {
                $validationReason = 'honeypot';
                throw $this->invalid();
            }
            $name = $this->text($input, 'name', 1, 120);
            $email = $this->emails->normalize($this->text($input, 'email', 3, 320));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw $this->invalid();
            }
            $phone = $this->text($input, 'phone', 1, 32);
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
        $now = CarbonImmutable::parse(now())->startOfSecond();
        try {
            return DB::transaction(function () use ($input, $user, $ip, $requestId, $idempotencyKey, $inquiryId, $name, $email, $phone, $subject, $body, $now): array {
                try {
                    $claim = $this->idempotency->claim('contact.submit', 'user', $user->public_id, $idempotencyKey, $input);
                } catch (V2PointException $exception) {
                    if (! in_array($exception->getMessage(), ['IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_REQUEST_IN_PROGRESS'], true)) {
                        throw $exception;
                    }
                    throw new V2ContentContactException($exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED'
                        ? 'CONTACT_IDEMPOTENCY_KEY_REUSED' : 'CONTACT_IDEMPOTENCY_REQUEST_IN_PROGRESS', 409, 'The Contact submission conflicts with an earlier request.');
                }
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    return [
                        'receipt_code' => $response['receipt_code'],
                        'status' => $response['status'],
                        'received_at' => $response['received_at'],
                        'request_id' => $response['request_id'],
                    ];
                }
                $this->rateLimiter->assertGlobal('contact_ip', $ip);
                $this->rateLimiter->assertSubject('contact_email', $email);
                $inquiry = $inquiryId === null ? null : ContactInquiry::query()
                    ->where('user_id', $user->id)->where('public_id', $inquiryId)->lockForUpdate()->first();
                if ($inquiry instanceof ContactInquiry) {
                    $this->followUp($inquiry, $user, $body, $requestId, $now);
                    $result = [
                        'receipt_code' => $inquiry->receipt_code,
                        'status' => 'accepted',
                        'received_at' => $inquiry->received_at->toIso8601String(),
                        'request_id' => $requestId,
                    ];
                } else {
                    $result = $this->create($name, $email, $phone, $subject, $body, $user, $requestId, $now);
                    $inquiry = ContactInquiry::query()->where('receipt_code', $result['receipt_code'])->firstOrFail();
                }
                $this->idempotency->complete($claim->record, 'contact_inquiry', $inquiry->public_id, $result);
                DB::table('idempotency_records')->where('id', $claim->record->id)->update(['response_status' => 202]);
                return $result;
            }, 3);
        } catch (V2AuthenticationException $exception) {
            $this->audit->record(
                $exception->errorCode === 'RATE_LIMITED' ? 'contact.rate_limited' : 'contact.rate_limit_unavailable',
                [
                    'request_id' => $requestId,
                    'actor_type' => 'user',
                    'actor_public_id' => $user->public_id,
                    'auth_realm' => 'user',
                    'outcome' => 'failure',
                    'reason_code' => strtolower($exception->errorCode),
                ]
            );
            throw $exception;
        }
    }

    private function followUp(ContactInquiry $inquiry, User $user, string $body, string $requestId, CarbonImmutable $now): void
    {
        $publicId = (string) Str::uuid7();
        DB::table('contact_user_messages')->insert([
            'public_id' => $publicId,
            'contact_inquiry_id' => $inquiry->id,
            'user_id' => $user->id,
            'message_ciphertext' => Crypt::encryptString($body),
            'request_id' => $requestId,
            'created_at' => V2DatabaseTimestamp::format($now),
        ]);
        DB::table('contact_status_histories')->insert([
            'contact_inquiry_id' => $inquiry->id,
            'from_status' => $inquiry->status,
            'to_status' => 'in_progress',
            'actor_admin_id' => null,
            'reason_code' => 'user_follow_up',
            'request_id' => $requestId,
            'occurred_at' => V2DatabaseTimestamp::format($now),
            'created_at' => V2DatabaseTimestamp::format($now),
            'updated_at' => V2DatabaseTimestamp::format($now),
        ]);
        $inquiry->forceFill(['status' => 'in_progress', 'closed_at' => null, 'updated_at' => $now])->save();
        $this->audit->record('contact.user_follow_up', [
            'request_id' => $requestId,
            'actor_type' => 'user',
            'actor_public_id' => $user->public_id,
            'auth_realm' => 'user',
            'target_type' => 'contact_inquiry',
            'target_public_id' => $inquiry->public_id,
            'outcome' => 'success',
            'metadata' => ['message_public_id' => $publicId],
        ]);
    }

    private function create(string $name, string $email, ?string $phone, string $subject, string $body, User $user, string $requestId, CarbonImmutable $now): array
    {
        $inquiry = new ContactInquiry();
        $inquiry->forceFill([
            'receipt_code' => 'CNT-'.strtoupper(Str::random(20)),
            'user_id' => $user->id,
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
            'occurred_at' => V2DatabaseTimestamp::format($now),
            'created_at' => V2DatabaseTimestamp::format($now),
            'updated_at' => V2DatabaseTimestamp::format($now),
        ]);
        $this->audit->record('contact.received', [
            'request_id' => $requestId,
            'actor_type' => 'user',
            'actor_public_id' => $user->public_id,
            'auth_realm' => 'user',
            'target_type' => 'contact_inquiry',
            'target_public_id' => $inquiry->public_id,
            'outcome' => 'success',
            'metadata' => ['authenticated' => true],
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
        $this->templateMail->schedule(
            'contact_received',
            'contact.received:'.$inquiry->public_id,
            'contact_inquiry',
            $inquiry->public_id
        );

        return [
            'receipt_code' => $inquiry->receipt_code,
            'status' => 'accepted',
            'received_at' => $now->toIso8601String(),
            'request_id' => $requestId,
        ];
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
