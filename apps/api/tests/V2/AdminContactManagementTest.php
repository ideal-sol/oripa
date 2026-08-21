<?php

namespace Tests\V2;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContactService;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminContactManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-05T03:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'v2_content_contact.contact_hmac_key' =>
                'base64:'.base64_encode(str_repeat('h', 32)),
            'v2_content_contact.contact_previous_hmac_keys' => [],
            'v2_content_contact.contact_retention_days' => 365,
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_identity.rate_limits.contact_ip' => [20, 3600],
            'v2_identity.rate_limits.contact_email' => [20, 3600],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' =>
                'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Cache::store('array')->clear();
    }

    public function test_email_filter_reads_previous_correlation_key_and_new_rows_use_active_key(): void
    {
        $old = 'base64:'.base64_encode(str_repeat('o', 32));
        $new = 'base64:'.base64_encode(str_repeat('n', 32));
        config([
            'v2_content_contact.contact_hmac_key' => $old,
            'v2_content_contact.contact_previous_hmac_keys' => [],
        ]);
        $historical = $this->submit('rotation@example.test', 'Historical inquiry');
        config([
            'v2_content_contact.contact_hmac_key' => $new,
            'v2_content_contact.contact_previous_hmac_keys' => [$old],
        ]);

        $filtered = app(V2ContentContactAdminService::class)->contactList(
            $this->context(V2AdminRole::Operator),
            null,
            20,
            null,
            'ROTATION@example.test'
        );
        self::assertSame([$historical], array_column($filtered['items'], 'id'));

        $current = $this->submit('current@example.test', 'Current inquiry');
        $historicalHash = DB::table('contact_inquiries')
            ->where('public_id', $historical)->value('email_correlation_hash');
        $currentHash = DB::table('contact_inquiries')
            ->where('public_id', $current)->value('email_correlation_hash');
        self::assertNotSame($historicalHash, $currentHash);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_v1_columns_filters_detail_and_history_use_public_ids(): void
    {
        $first = $this->submit('first@example.test', '最初のお問い合わせ');
        CarbonImmutable::setTestNow('2026-08-05T03:01:00Z');
        $second = $this->submit('second@example.test', '次のお問い合わせ');
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Operator);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $list = $service->contactList($context, null, 20);
        $listQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual(3, $listQueryCount);
        self::assertSame([$second, $first], array_column($list['items'], 'id'));
        self::assertSame('お客様', $list['items'][0]['name']);
        self::assertSame('second@example.test', $list['items'][0]['email']);
        self::assertSame('09000000000', $list['items'][0]['phone']);
        self::assertSame('次のお問い合わせ', $list['items'][0]['body_excerpt']);
        self::assertArrayNotHasKey('user_id', $list['items'][0]);

        $filtered = $service->contactList(
            $context,
            null,
            20,
            'new',
            'FIRST@example.test'
        );
        self::assertCount(1, $filtered['items']);
        self::assertSame($first, $filtered['items'][0]['id']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $detail = $service->contactDetail($context, $first);
        $detailQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual(10, $detailQueryCount);
        self::assertSame('お問い合わせ件名', $detail['subject']);
        self::assertSame('最初のお問い合わせ', $detail['body']);
        self::assertCount(1, $detail['status_history']);
        self::assertSame([], $detail['reply_requests']);
        self::assertArrayNotHasKey('assigned_admin_id', $detail);
    }

    public function test_reply_and_status_mutations_are_canonical_replays(): void
    {
        $publicId = $this->submit('replay@example.test', '返信対象');
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Admin);
        $replyKey = 'contact-reply-'.Str::uuid7();
        $outboxBefore = DB::table('outbox_messages')->count();

        $reply = $service->requestReply(
            $context,
            $publicId,
            '確認してご連絡します。',
            $replyKey
        );
        self::assertFalse($reply['idempotent_replay']);
        $replay = $service->requestReply(
            $context,
            $publicId,
            '確認してご連絡します。',
            $replyKey
        );
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($reply['id'], $replay['id']);
        self::assertDatabaseCount('contact_reply_requests', 1);
        self::assertSame($outboxBefore + 1, DB::table('outbox_messages')->count());

        $statusKey = 'contact-status-'.Str::uuid7();
        $updated = $service->updateContactStatus(
            $context,
            $publicId,
            'replied',
            'admin_marked_replied',
            $statusKey
        );
        self::assertFalse($updated['idempotent_replay']);
        $statusReplay = $service->updateContactStatus(
            $context,
            $publicId,
            'replied',
            'admin_marked_replied',
            $statusKey
        );
        self::assertTrue($statusReplay['idempotent_replay']);
        self::assertSame('replied', $statusReplay['status']);

        $detail = $service->contactDetail($context, $publicId);
        self::assertCount(1, $detail['reply_requests']);
        self::assertSame('確認してご連絡します。', $detail['reply_requests'][0]['message']);
        self::assertCount(3, $detail['status_history']);
    }

    public function test_key_conflict_validation_and_operator_mutation_fail_closed(): void
    {
        $publicId = $this->submit('denied@example.test', '拒否境界');
        $service = app(V2ContentContactAdminService::class);
        $admin = $this->context(V2AdminRole::Admin);
        $key = 'contact-conflict-'.Str::uuid7();
        $service->requestReply($admin, $publicId, 'First', $key);
        try {
            $service->requestReply($admin, $publicId, 'Different', $key);
            self::fail('A reused Idempotency-Key with a different request must fail.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTACT_IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }

        try {
            $service->contactList($admin, null, 20, 'unknown');
            self::fail('An unknown status filter must fail.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTACT_FILTER_INVALID', $exception->errorCode);
        }

        try {
            $service->updateContactStatus(
                $this->context(V2AdminRole::Operator),
                $publicId,
                'closed',
                'operator_denied',
                'contact-denied-'.Str::uuid7()
            );
            self::fail('Operator must not mutate Contact Inquiry.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }
    }

    private function submit(string $email, string $body): string
    {
        $result = app(V2ContactService::class)->submit([
            'name' => 'お客様',
            'email' => $email,
            'phone' => '09000000000',
            'subject' => 'お問い合わせ件名',
            'body' => $body,
            'website' => '',
        ], null, '192.0.2.'.(10 + DB::table('contact_inquiries')->count()), (string) Str::uuid7());

        return (string) DB::table('contact_inquiries')
            ->where('receipt_code', $result['receipt_code'])->value('public_id');
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'contact-admin-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now()->subHour(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(7),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)
                ->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
