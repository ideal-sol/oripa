<?php

namespace App\Domain\Line\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Line\Exceptions\V2LineMessagingException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\LineMessagingSetting;
use Illuminate\Support\Facades\DB;

final class V2LineMessagingSettingService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2LineMessageTemplate $templates,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    /** @return array<string, mixed> */
    public function read(V2AdminAuthorizationContext $context): array
    {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageLineMessaging,
            false,
            'identity.line.messaging.read'
        );

        return $this->serialize($this->setting());
    }

    /**
     * @param array<string, mixed> $input
     * @return array{linked_follow_message: string, pending_follow_message: string}
     */
    public function preview(
        V2AdminAuthorizationContext $context,
        array $input
    ): array {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageLineMessaging,
            false,
            'identity.line.messaging.preview'
        );
        $payload = $this->validateMessages($input, false);

        return [
            'linked_follow_message' => $this->templates->render(
                $payload['linked_follow_message']
            ),
            'pending_follow_message' => $this->templates->render(
                $payload['pending_follow_message']
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool}
     */
    public function update(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageLineMessaging,
            true,
            'identity.line.messaging.update'
        );
        try {
            $this->rateLimiter->assertSubject(
                'critical_admin_mutation',
                $admin->public_id
            );
        } catch (V2AuthenticationException $exception) {
            $this->audit->record('line.messaging.setting.rate_limited', [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'target_type' => 'line_messaging_setting',
                'outcome' => 'failure',
                'reason_code' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }
        if (! isset($input['expected_revision']) || ! is_int($input['expected_revision'])) {
            throw $this->invalid();
        }
        $messages = $this->validateMessages($input, true);
        $request = [
            'expected_revision' => $input['expected_revision'],
            ...$messages,
        ];

        try {
            return DB::transaction(function () use (
                $context,
                $admin,
                $idempotencyKey,
                $request
            ): array {
                $claim = $this->idempotency->claim(
                    'identity.line.messaging.update',
                    'admin',
                    $admin->public_id,
                    $idempotencyKey,
                    $request
                );
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! isset($response['data'])) {
                        throw new V2LineMessagingException(
                            'LINE_MESSAGING_UNAVAILABLE',
                            503,
                            'LINE Messaging configuration is unavailable.',
                            true
                        );
                    }

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }
                $setting = LineMessagingSetting::query()->whereKey(1)->lockForUpdate()->first();
                if (! $setting instanceof LineMessagingSetting) {
                    throw new V2LineMessagingException(
                        'LINE_MESSAGING_UNAVAILABLE',
                        503,
                        'LINE Messaging configuration is unavailable.',
                        true
                    );
                }
                if ((int) $setting->revision !== $request['expected_revision']) {
                    throw new V2LineMessagingException(
                        'LINE_MESSAGING_REVISION_CONFLICT',
                        409,
                        'The LINE Messaging setting was updated by another operation.'
                    );
                }
                $before = [
                    'linked_follow_message_checksum' => hash(
                        'sha256',
                        $setting->linked_follow_message
                    ),
                    'pending_follow_message_checksum' => hash(
                        'sha256',
                        $setting->pending_follow_message
                    ),
                    'revision' => (int) $setting->revision,
                ];
                $setting->forceFill([
                    'linked_follow_message' => $request['linked_follow_message'],
                    'pending_follow_message' => $request['pending_follow_message'],
                    'revision' => (int) $setting->revision + 1,
                    'updated_by_admin_id' => $admin->getKey(),
                    'updated_at' => now()->startOfSecond(),
                ])->save();
                $data = $this->serialize($setting->refresh());
                $this->audit->record('line.messaging.setting.updated', [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin',
                    'actor_public_id' => $admin->public_id,
                    'actor_role' => $admin->role->value,
                    'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'line_messaging_setting',
                    'target_public_id' => $setting->public_id,
                    'before' => $before,
                    'after' => [
                        'linked_follow_message_checksum' => hash(
                            'sha256',
                            $setting->linked_follow_message
                        ),
                        'pending_follow_message_checksum' => hash(
                            'sha256',
                            $setting->pending_follow_message
                        ),
                        'revision' => (int) $setting->revision,
                    ],
                    'outcome' => 'success',
                ]);
                $this->outbox->enqueue(
                    'identity.line-messaging-setting-updated',
                    'line_messaging_setting',
                    $setting->public_id,
                    'identity.line_messaging_setting.updated',
                    ['revision' => (int) $setting->revision],
                    'line-messaging-setting:'.$setting->public_id.':'.$setting->revision
                );
                $this->idempotency->complete(
                    $claim->record,
                    'line_messaging_setting',
                    $setting->public_id,
                    ['data' => $data]
                );

                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            $code = $exception->getMessage();
            throw new V2LineMessagingException(
                $code === 'IDEMPOTENCY_KEY_REUSED'
                    ? 'IDEMPOTENCY_KEY_REUSED'
                    : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The LINE Messaging update conflicts with another request.'
            );
        } catch (V2AuthenticationException|V2LineMessagingException $exception) {
            throw $exception;
        }
    }

    public function setting(bool $lock = false): LineMessagingSetting
    {
        $query = LineMessagingSetting::query()->whereKey(1);
        if ($lock) {
            $query->lockForUpdate();
        }
        $setting = $query->first();
        if (! $setting instanceof LineMessagingSetting) {
            throw new V2LineMessagingException(
                'LINE_MESSAGING_UNAVAILABLE',
                503,
                'LINE Messaging configuration is unavailable.',
                true
            );
        }
        $this->templates->normalize($setting->linked_follow_message);
        $this->templates->normalize($setting->pending_follow_message);

        return $setting;
    }

    /** @param array<string, mixed> $input */
    private function validateMessages(array $input, bool $allowRevision): array
    {
        $allowed = ['linked_follow_message', 'pending_follow_message'];
        if ($allowRevision) {
            $allowed[] = 'expected_revision';
        }
        if (
            array_diff(array_keys($input), $allowed) !== []
            || ! isset($input['linked_follow_message'], $input['pending_follow_message'])
            || ! is_string($input['linked_follow_message'])
            || ! is_string($input['pending_follow_message'])
        ) {
            throw $this->invalid();
        }

        return [
            'linked_follow_message' => $this->templates->normalize(
                $input['linked_follow_message']
            ),
            'pending_follow_message' => $this->templates->normalize(
                $input['pending_follow_message']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(LineMessagingSetting $setting): array
    {
        return [
            'id' => $setting->public_id,
            'linked_follow_message' => $setting->linked_follow_message,
            'pending_follow_message' => $setting->pending_follow_message,
            'login_relative_path' => $setting->login_relative_path,
            'revision' => (int) $setting->revision,
            'updated_at' => $setting->updated_at->toIso8601String(),
        ];
    }

    private function invalid(): V2LineMessagingException
    {
        return new V2LineMessagingException(
            'LINE_MESSAGING_SETTING_INVALID',
            422,
            'The LINE Messaging setting is invalid.'
        );
    }
}
