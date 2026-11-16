<?php

namespace App\Domain\Line\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Enums\V2LineDeliveryPurpose as Purpose;
use App\Domain\Line\Enums\V2LineRecipientGroup as Group;
use App\Domain\Line\ValueObjects\V2LineDeliveryPacing;
use App\Domain\Line\ValueObjects\V2LineRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LineDeliveryService
{
    public function save(V2AdminAuthorizationContext $context, ?int $id, int $expectedRevision, Purpose $purpose, Group $group,
        #[\SensitiveParameter] array $messages, ?CarbonImmutable $scheduledAt, ?V2LineDeliveryPacing $pacing = null, #[\SensitiveParameter] string $title = 'LINE配信'): int
    {
        $admin = $this->authorize($context);
        if (trim($title) === '' || ! mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 100) throw new \InvalidArgumentException('Invalid LINE delivery title.');
        app(V2LineDeliveryMessages::class)->validate($messages);
        // Reservation-time quota is advisory for future membership; resolving checks afresh.
        if ($scheduledAt !== null) {
            $estimate = $this->estimate($group, $purpose);
            if (! $estimate['sufficient']) throw new \RuntimeException('LINE quota insufficient.');
        }
        return DB::transaction(function () use ($context, $admin, $id, $expectedRevision, $purpose, $group, $messages, $scheduledAt, $pacing, $title): int {
            app(V2LineMessagingSettingService::class)->setting(true);
            $row = $id === null ? null : DB::table('line_deliveries')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($row !== null && (! in_array($row->status, ['draft', 'scheduled'], true) || $row->revision !== $expectedRevision)) throw new \RuntimeException('LINE delivery revision conflict.');
            $snapshot = $pacing ?? ($row === null ? $this->defaults() : $this->pacing($row->pacing));
            $values = ['category' => $purpose->value, 'recipient_group' => $group->value,
                'title_ciphertext' => Crypt::encryptString($title),
                'messages_ciphertext' => Crypt::encryptString(json_encode($messages, JSON_THROW_ON_ERROR)),
                'pacing' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'scheduled_at' => $scheduledAt,
                'status' => $scheduledAt === null ? 'draft' : 'scheduled', 'revision' => ($row?->revision ?? 0) + 1,
                'updated_by_admin_id' => $admin->id, 'updated_at' => now()];
            if ($row === null) $id = DB::table('line_deliveries')->insertGetId([...$values, 'public_id' => (string) Str::uuid(), 'created_by_admin_id' => $admin->id]);
            else DB::table('line_deliveries')->where('id', $id)->update($values);
            $this->audit($context, 'line.delivery.saved', $id);
            return $id;
        }, 1);
    }

    public function updateDefaults(V2AdminAuthorizationContext $context, int $revision, V2LineDeliveryPacing $pacing): void
    {
        $admin = $this->authorize($context);
        DB::transaction(function () use ($context, $admin, $revision, $pacing): void {
            $row = DB::table('line_delivery_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($row->revision !== $revision) throw new \RuntimeException('LINE pacing revision conflict.');
            DB::table('line_delivery_settings')->where('id', 1)->update(['revision' => $revision + 1, 'push_every' => $pacing->pushEvery,
                'push_wait_seconds' => $pacing->pushWaitSeconds, 'multicast_wait_seconds' => $pacing->multicastWaitSeconds,
                'updated_by_admin_id' => $admin->id, 'updated_at' => now()]);
            $this->audit($context, 'line.delivery.pacing.updated', 1);
        }, 1);
    }

    public function cancel(V2AdminAuthorizationContext $context, int $id, int $revision): void
    {
        $this->authorize($context);
        DB::transaction(function () use ($context, $id, $revision): void {
            app(V2LineMessagingSettingService::class)->setting(true);
            if (DB::table('line_deliveries')->where('id', $id)->where('revision', $revision)->whereIn('status', ['draft', 'scheduled'])
                ->update(['status' => 'cancelled', 'revision' => $revision + 1, 'updated_at' => now()]) !== 1) throw new \RuntimeException('LINE delivery revision conflict.');
            $this->audit($context, 'line.delivery.cancelled', $id);
        }, 1);
    }

    public function estimate(Group $group, Purpose $purpose): array
    {
        $bot = DB::table('line_bots')->where('is_active', true)->firstOrFail();
        $count = iterator_count(app(V2LineRecipientResolver::class)->resolve($group, $purpose));
        $remaining = app(V2LineDeliveryQuota::class)->remaining($bot);
        return ['recipient_count' => $count, 'remaining' => $remaining, 'sufficient' => $remaining === null || $remaining >= $count];
    }

    /** Internal resolve entry; no provider side effect runs inside its transactions. */
    public function resolve(int $id): void
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('LINE resolution requires an independent transaction.');
        $owner = (string) Str::uuid();
        $delivery = DB::transaction(function () use ($id, $owner): ?object {
            app(V2LineMessagingSettingService::class)->setting(true);
            $d = DB::table('line_deliveries')->where('id', $id)->lockForUpdate()->firstOrFail();
            $now = app(V2LineDeliverySender::class)->clock();
            if (! in_array($d->status, ['scheduled', 'resolving'], true) || CarbonImmutable::parse($d->scheduled_at)->gt($now)
                || ($d->lease_until !== null && CarbonImmutable::parse($d->lease_until)->gt($now))) return null;
            $bot = DB::table('line_bots')->where('is_active', true)->where('connection_status', 'succeeded')->firstOrFail();
            if ($d->line_bot_id !== null && ($d->line_bot_id !== $bot->id || $d->authentication_revision !== $bot->authentication_revision)) {
                DB::table('line_deliveries')->where('id', $id)->update(['status' => 'cancelled', 'lease_owner' => null, 'lease_until' => null]); return null;
            }
            DB::table('line_deliveries')->where('id', $id)->update(['status' => 'resolving', 'line_bot_id' => $bot->id,
                'authentication_revision' => $bot->authentication_revision, 'lease_owner' => $owner, 'lease_until' => $now->addMinutes(5)]);
            return DB::table('line_deliveries')->where('id', $id)->first();
        }, 1);
        if ($delivery === null) return;
        $refs = iterator_to_array(app(V2LineRecipientResolver::class)->resolve(Group::from($delivery->recipient_group), Purpose::from($delivery->category)));
        $bot = DB::table('line_bots')->where('id', $delivery->line_bot_id)->firstOrFail();
        app(V2LineDeliveryQuota::class)->remaining($bot);
        DB::transaction(function () use ($delivery, $owner, $refs): void {
            app(V2LineMessagingSettingService::class)->setting(true);
            $d = DB::table('line_deliveries')->where('id', $delivery->id)->lockForUpdate()->firstOrFail();
            $now = app(V2LineDeliverySender::class)->clock();
            if ($d->status !== 'resolving' || $d->lease_owner !== $owner || CarbonImmutable::parse($d->lease_until)->lte($now)) return;
            if (! DB::table('line_bots')->where('id', $d->line_bot_id)->where('authentication_revision', $d->authentication_revision)->where('is_active', true)->exists()) {
                DB::table('line_deliveries')->where('id', $d->id)->update(['status' => 'cancelled', 'lease_owner' => null, 'lease_until' => null]); return;
            }
            foreach ($refs as $ref) if ($ref->botId !== $d->line_bot_id || $ref->authenticationRevision !== $d->authentication_revision) throw new \RuntimeException('LINE BOT changed during resolution.');
            $quota = app(V2LineDeliveryQuota::class);
            if ($quota->available($d->line_bot_id, $quota->month($now)) < count($refs)) {
                DB::table('line_deliveries')->where('id', $d->id)->update(['status' => 'blocked_quota', 'recipient_count' => count($refs), 'lease_owner' => null, 'lease_until' => null]); return;
            }
            $messages = json_decode(Crypt::decryptString($d->messages_ciphertext), true, 512, JSON_THROW_ON_ERROR);
            $push = app(V2LineDeliveryMessages::class)->personalized($messages);
            $pacing = $this->pacing($d->pacing);
            foreach (array_chunk($refs, $push ? 1 : 500) as $i => $chunk) {
                $this->insertBatch($chunk, Purpose::from($d->category), $messages, $push ? 'push' : 'multicast', 'delivery', (string) Str::uuid(),
                    $d->id, $i, $now->addSeconds($pacing->offsetSeconds($push ? 'push' : 'multicast', $i)));
            }
            DB::table('line_deliveries')->where('id', $d->id)->update(['status' => $refs === [] ? 'completed' : 'sending',
                'recipient_count' => count($refs), 'lease_owner' => null, 'lease_until' => null, 'last_dispatched_at' => $now]);
        }, 1);
    }

    /** Trusted D4/D6 domain entry, never an HTTP endpoint. Source UUID is durable event identity. */
    public function enqueuePush(V2LineRecipient $ref, Purpose $purpose, #[\SensitiveParameter] array $messages, string $source, string $sourceKey): int
    {
        if (! in_array($source, ['automated', 'chat'], true) || ! Str::isUuid($sourceKey)) throw new \InvalidArgumentException('Invalid LINE source.');
        app(V2LineDeliveryMessages::class)->validate($messages);
        return DB::transaction(function () use ($ref, $purpose, $messages, $source, $sourceKey): int {
            app(V2LineMessagingSettingService::class)->setting(true);
            $existing = DB::table('line_delivery_batches')->where('source_key', $sourceKey)->first();
            if ($existing !== null) return $existing->id;
            if (! app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, $purpose)) throw new \RuntimeException('LINE recipient unavailable.');
            return $this->insertBatch([$ref], $purpose, $messages, 'push', $source, $sourceKey, null, 0, app(V2LineDeliverySender::class)->clock());
        }, 1);
    }

    /** D5 confirmation text is deliberately explicit; original terminal history is retained. */
    public function resend(V2AdminAuthorizationContext $context, int $id, string $confirmation, string $requestKey): int
    {
        $this->authorize($context);
        if ($confirmation !== 'すでに届いている可能性があります' || ! Str::isUuid($requestKey)) throw new \InvalidArgumentException('LINE resend confirmation required.');
        return DB::transaction(function () use ($context, $id, $requestKey): int {
            app(V2LineMessagingSettingService::class)->setting(true);
            $existing = DB::table('line_delivery_batches')->where('source_key', $requestKey)->first();
            if ($existing !== null && $existing->replaces_batch_id === $id) return $existing->id;
            $b = DB::table('line_delivery_batches')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! in_array($b->status, ['failed', 'unknown'], true)) throw new \RuntimeException('LINE resend state invalid.');
            $refs = [];
            foreach (DB::table('line_delivery_recipients')->where('batch_id', $id)->orderBy('id')->get() as $r) {
                $ref = new V2LineRecipient($b->line_bot_id, $b->authentication_revision, $r->source_table, $r->source_id, $r->user_id, Group::from($r->recipient_group));
                if (app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::from($b->category))) $refs[] = $ref;
            }
            if ($refs === []) throw new \RuntimeException('LINE recipient unavailable.');
            $new = $this->insertBatch($refs, Purpose::from($b->category), json_decode(Crypt::decryptString($b->messages_ciphertext), true, 512, JSON_THROW_ON_ERROR),
                $b->method, 'manual', $requestKey, null, 0, app(V2LineDeliverySender::class)->clock(), $id);
            $this->audit($context, 'line.delivery.resend.created', $new);
            return $new;
        }, 1);
    }

    private function insertBatch(array $refs, Purpose $purpose, #[\SensitiveParameter] array $messages, string $method, string $source,
        string $key, ?int $delivery, int $position, CarbonImmutable $due, ?int $replaces = null): int
    {
        $id = DB::table('line_delivery_batches')->insertGetId(['public_id' => (string) Str::uuid(), 'retry_key' => (string) Str::uuid(),
            'line_bot_id' => $refs[0]->botId, 'authentication_revision' => $refs[0]->authenticationRevision, 'category' => $purpose->value,
            'messages_ciphertext' => Crypt::encryptString(json_encode($messages, JSON_THROW_ON_ERROR)), 'method' => $method,
            'source_type' => $source, 'source_key' => $key, 'delivery_id' => $delivery, 'position' => $position, 'not_before' => $due, 'replaces_batch_id' => $replaces]);
        foreach ($refs as $r) DB::table('line_delivery_recipients')->insert(['batch_id' => $id, 'source_table' => $r->source,
            'source_id' => $r->sourceId, 'user_id' => $r->userId, 'recipient_group' => $r->group->value]);
        $quota = app(V2LineDeliveryQuota::class);
        if (! $quota->cover([DB::table('line_delivery_batches')->where('id', $id)->firstOrFail()],
            [$quota->month(app(V2LineDeliverySender::class)->clock())])) throw new \RuntimeException('LINE quota baseline unavailable or insufficient.');
        return $id;
    }

    public function pacing(string $json): V2LineDeliveryPacing
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_diff(array_keys($value), ['push_every', 'push_wait_seconds', 'multicast_wait_seconds'])) throw new \InvalidArgumentException('Invalid LINE pacing snapshot.');
        foreach (['push_every', 'push_wait_seconds', 'multicast_wait_seconds'] as $key) if (! is_int($value[$key] ?? null)) throw new \InvalidArgumentException('Invalid LINE pacing snapshot.');
        return new V2LineDeliveryPacing($value['push_every'], $value['push_wait_seconds'], $value['multicast_wait_seconds']);
    }
    private function defaults(): V2LineDeliveryPacing
    {
        $s = DB::table('line_delivery_settings')->where('id', 1)->firstOrFail();
        return new V2LineDeliveryPacing($s->push_every, $s->push_wait_seconds, $s->multicast_wait_seconds);
    }
    private function authorize(V2AdminAuthorizationContext $context): \App\Models\V2\Admin
    {
        $authorization = app(V2AdminFreshMfaAuthorizer::class);
        $admin = $authorization->authorizePermission($context, V2Permission::ManageLineBot);
        if (! $authorization->isFresh($authorization->validSessionForReauthentication($context))) {
            throw new \App\Domain\Identity\Exceptions\V2AuthenticationException('FRESH_MFA_REQUIRED', 403, 'Fresh Admin authentication is required.');
        }
        return $admin;
    }

    private function audit(V2AdminAuthorizationContext $c, string $action, int $id): void
    {
        app(V2AuditLogService::class)->record($action, ['request_id' => $c->requestId, 'actor_type' => 'admin', 'actor_public_id' => $c->adminPublicId, 'actor_role' => $c->role->value, 'auth_realm' => 'admin',
            'target_type' => 'line_delivery', 'metadata' => ['record_id' => $id], 'outcome' => 'success']);
    }
}
