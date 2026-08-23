<?php

namespace Tests\V2;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAnnouncementManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-04T03:00:00Z');
        config([
            'cache.default' => 'array',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' =>
                'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_notice_create_list_update_publish_and_replay_are_canonical(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Admin);
        $input = $this->input('最初のお知らせ');
        $key = 'announcement-create-'.Str::uuid7();

        $created = $service->createContent($context, 'notice', $input, $key);
        self::assertFalse($created['idempotent_replay']);
        self::assertTrue(Str::isUuid($created['id']));
        self::assertSame('draft', $created['status']);
        self::assertStringNotContainsString('<script', $created['versions'][0]['body_html']);

        $replayed = $service->createContent($context, 'notice', $input, $key);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($created['id'], $replayed['id']);
        self::assertDatabaseCount('content_notices', 1);
        self::assertDatabaseCount('content_versions', 1);

        $list = $service->contentList($context, 'notice', null, 20);
        self::assertSame('最初のお知らせ', $list['items'][0]['latest_version']['title']);
        self::assertSame($created['versions'][0]['id'], $list['items'][0]['latest_version']['id']);
        self::assertCount(1, $service->contentList(
            $context, 'notice', null, 20, 'published,draft'
        )['items']);
        self::assertCount(0, $service->contentList(
            $context, 'notice', null, 20, 'published'
        )['items']);

        $updated = $service->createVersion(
            $context,
            'notice',
            $created['id'],
            $this->input('更新したお知らせ'),
            'announcement-update-'.Str::uuid7()
        );
        self::assertFalse($updated['idempotent_replay']);
        self::assertSame(2, $updated['version_number']);
        $published = $service->publish(
            $context,
            'notice',
            $created['id'],
            $updated['id']
        );
        self::assertSame('published', $published['status']);
        self::assertCount(1, $service->contentList(
            $context, 'notice', null, 20, 'published'
        )['items']);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'content.published',
            'target_public_id' => $created['id'],
        ]);
    }

    public function test_preview_sanitizes_html_and_period_validation_fails_closed(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Owner);
        $preview = $service->previewNotice($context, $this->input('Preview'));
        self::assertStringContainsString('<p>安全な本文</p>', $preview['body_html']);
        self::assertStringNotContainsString('<script', $preview['body_html']);
        self::assertSame('2026-08-04T03:00:00+00:00', $preview['publish_start_at']);

        try {
            $service->previewNotice($context, [
                ...$this->input('Invalid period'),
                'publish_end_at' => '2026-08-04T02:59:59Z',
            ]);
            self::fail('An inverted publication period must be rejected.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_PUBLISH_PERIOD_INVALID', $exception->errorCode);
        }
    }

    public function test_idempotency_payload_conflict_and_operator_mutation_are_rejected(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Admin);
        $key = 'announcement-conflict-'.Str::uuid7();
        $service->createContent($context, 'notice', $this->input('First'), $key);
        try {
            $service->createContent($context, 'notice', $this->input('Different'), $key);
            self::fail('A reused key with a different payload must be rejected.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }

        try {
            $service->createContent(
                $this->context(V2AdminRole::Operator),
                'notice',
                $this->input('Denied'),
                'announcement-denied-'.Str::uuid7()
            );
            self::fail('Operator must not mutate notices.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }
    }

    public function test_admin_notice_routes_are_read_write_only_without_delete(): void
    {
        $routes = collect(app('router')->getRoutes());
        $methods = $routes
            ->filter(static fn ($route): bool => str_starts_with(
                $route->uri(),
                'admin/api/v2/content/notices'
            ))
            ->flatMap(static fn ($route) => collect($route->methods())
                ->reject(static fn (string $method): bool => $method === 'HEAD')
                ->map(static fn (string $method): string => $method.' '.$route->uri()))
            ->values();

        self::assertContains('GET admin/api/v2/content/notices', $methods);
        self::assertContains('POST admin/api/v2/content/notices', $methods);
        self::assertContains('POST admin/api/v2/content/notices/preview', $methods);
        self::assertFalse($methods->contains(
            static fn (string $entry): bool => str_starts_with($entry, 'DELETE ')
        ));
    }

    /** @return array<string, mixed> */
    private function input(string $title): array
    {
        return [
            'slug' => 'notice-'.strtolower((string) Str::ulid()),
            'title' => $title,
            'summary' => null,
            'body_html' => '<p>安全な本文</p><script>alert(1)</script>',
            'is_important' => true,
            'asset_id' => null,
            'sort_order' => 0,
            'publish_start_at' => '2026-08-04T12:00:00+09:00',
            'publish_end_at' => '2026-08-31T23:59:59+09:00',
        ];
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'announcement-'.Str::uuid7().'@example.test';
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
