<?php

namespace App\Domain\Line\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Line\Exceptions\V2LineMessagingException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class V2LineAutomatedSettings
{
    public const EVENTS = ['shipping', 'payment', 'first_purchase', 'rank_promotion'];

    public function read(V2AdminAuthorizationContext $context): array
    {
        app(V2AdminFreshMfaAuthorizer::class)->authorizePermission($context, V2Permission::ReadLineBot);
        return ['data' => array_map(function (string $event): array {
            $head = DB::table('line_automated_templates')->where('event', $event)->firstOrFail();
            $v = DB::table('line_automated_template_versions')->where('event', $event)->where('revision', $head->revision)->first();
            return ['event' => $event, 'revision' => (int) $head->revision, 'enabled' => (bool) ($v?->enabled ?? false),
                'category' => $v?->category ?? (in_array($event, ['shipping', 'payment'], true) ? 'transactional' : 'promotional'),
                'messages' => $v === null ? [['type' => 'text', 'text' => '']] : json_decode(Crypt::decryptString($v->messages_ciphertext), true, 512, JSON_THROW_ON_ERROR),
                'effective_at' => $v?->effective_at];
        }, self::EVENTS)];
    }

    public function update(V2AdminAuthorizationContext $context, string $event, #[\SensitiveParameter] array $input): array
    {
        $auth = app(V2AdminFreshMfaAuthorizer::class);
        $admin = $auth->authorizePermission($context, V2Permission::ManageLineBot);
        if (! $auth->isFresh($auth->validSessionForReauthentication($context))) {
            throw new V2AuthenticationException('FRESH_MFA_REQUIRED', 403, 'Fresh Admin authentication is required.');
        }
        if (! in_array($event, self::EVENTS, true) || array_diff(array_keys($input), ['enabled', 'category', 'messages', 'expected_revision'])
            || ! is_bool($input['enabled'] ?? null) || ! is_int($input['expected_revision'] ?? null)
            || ! in_array($input['category'] ?? null, ['transactional', 'promotional'], true)
            || (in_array($event, ['shipping', 'payment'], true) && $input['category'] !== 'transactional')
            || ! is_array($input['messages'] ?? null)) $this->invalid();
        $this->validate($event, $input['messages']);
        DB::transaction(function () use ($context, $admin, $event, $input): void {
            $head = DB::table('line_automated_templates')->where('event', $event)->lockForUpdate()->firstOrFail();
            if ((int) $head->revision !== $input['expected_revision']) {
                throw new V2LineMessagingException('LINE_AUTOMATED_REVISION_CONFLICT', 409, '設定が変更されています。再取得してください。');
            }
            $revision = (int) $head->revision + 1;
            DB::table('line_automated_template_versions')->insert([
                'event' => $event, 'revision' => $revision, 'enabled' => $input['enabled'], 'category' => $input['category'],
                'messages_ciphertext' => Crypt::encryptString(json_encode($input['messages'], JSON_THROW_ON_ERROR)),
                'effective_at' => DB::raw('clock_timestamp()'), 'created_by_admin_id' => $admin->id,
            ]);
            DB::table('line_automated_templates')->where('event', $event)->update(['revision' => $revision]);
            app(V2AuditLogService::class)->record('line.automated.updated', ['request_id' => $context->requestId,
                'actor_type' => 'admin', 'actor_public_id' => $context->adminPublicId, 'actor_role' => $context->role->value,
                'auth_realm' => 'admin', 'target_type' => 'line_automated_template',
                'metadata' => ['event' => $event, 'revision' => $revision, 'enabled' => $input['enabled']], 'outcome' => 'success']);
        }, 1);
        return $this->read($context);
    }

    public function validate(string $event, #[\SensitiveParameter] array $messages): void
    {
        $copy = $messages;
        foreach ($copy as &$m) {
            if (! is_array($m) || ($m['type'] ?? null) !== 'text' || ! is_string($m['text'] ?? null)) $this->invalid();
            if ($event === 'shipping') {
                // Validate length against the original text; substitution may only shorten this sentinel.
                $m['text'] = str_replace('%shipping_url%', 'shipping_url__', $m['text']);
            }
        }
        unset($m);
        try { app(V2LineDeliveryMessages::class)->validate($copy); }
        catch (\InvalidArgumentException) { $this->invalid(); }
    }

    private function invalid(): never
    {
        throw new V2LineMessagingException('LINE_AUTOMATED_INPUT_INVALID', 422, '通知の本文・区分・置換タグを確認してください。');
    }
}
