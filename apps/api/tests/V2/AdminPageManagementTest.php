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

final class AdminPageManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-05T03:00:00Z');
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_page_category_create_update_visibility_and_replay_are_canonical(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Admin);
        $categoryKey = 'page-category-'.Str::uuid7();
        $category = $service->createPageCategory($context, [
            'name' => 'ご利用案内', 'visibility' => 'hidden',
        ], $categoryKey);
        self::assertSame('hidden', $category['visibility']);
        self::assertTrue($service->createPageCategory($context, [
            'name' => 'ご利用案内', 'visibility' => 'hidden',
        ], $categoryKey)['idempotent_replay']);

        $input = [
            'category_id' => $category['id'], 'title' => 'ご利用ガイド',
            'body_html' => '<p>安全な本文</p><script>alert(1)</script>',
            'slug' => ' /guide/ ', 'visibility' => 'visible',
        ];
        $createKey = 'page-create-'.Str::uuid7();
        $created = $service->createManagedPage($context, $input, $createKey);
        self::assertSame('guide', $created['slug']);
        self::assertSame('visible', $created['visibility']);
        self::assertStringNotContainsString('<script', $created['body_html']);
        self::assertTrue($service->createManagedPage($context, $input, $createKey)['idempotent_replay']);
        self::assertDatabaseCount('content_static_pages', 1);
        self::assertDatabaseCount('content_versions', 1);

        $publishedVersionId = DB::table('content_static_pages')->where('public_id', $created['id'])
            ->value('published_version_id');
        $updated = $service->updateManagedPage($context, $created['id'], [
            'category_id' => $category['id'], 'title' => '更新ガイド',
            'body_html' => '<h2>更新内容</h2>', 'slug' => 'updated-guide',
            'visibility' => 'hidden',
        ], 'page-update-'.Str::uuid7());
        self::assertSame('hidden', $updated['visibility']);
        self::assertSame(2, $updated['version_number']);
        self::assertDatabaseHas('content_versions', ['id' => $publishedVersionId, 'status' => 'published']);
        self::assertNull(DB::table('content_static_pages')->where('public_id', $created['id'])
            ->value('published_version_id'));
        self::assertSame($updated['id'], $service->managedPages($context, null, 20)['items'][0]['id']);
    }

    public function test_slug_category_validation_permission_and_idempotency_fail_closed(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $admin = $this->context(V2AdminRole::Admin);
        $category = $service->createPageCategory($admin, [
            'name' => '規約', 'visibility' => 'visible',
        ], 'category-'.Str::uuid7());
        $key = 'page-key-'.Str::uuid7();
        $service->createManagedPage($admin, $this->pageInput($category['id'], 'terms'), $key);

        try {
            $service->createManagedPage($admin, $this->pageInput($category['id'], 'other'), $key);
            self::fail('A reused key with another payload must fail.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('PAGE_IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }
        try {
            $service->createManagedPage($admin, $this->pageInput($category['id'], 'bad/slug'), 'bad-'.Str::uuid7());
            self::fail('Invalid slugs must fail.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_IDENTIFIER_INVALID', $exception->errorCode);
        }
        try {
            $service->createPageCategory($this->context(V2AdminRole::Operator), [
                'name' => '拒否', 'visibility' => 'visible',
            ], 'denied-'.Str::uuid7());
            self::fail('Operator must not mutate pages.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }
    }

    public function test_routes_expose_no_page_delete_endpoint(): void
    {
        $methods = collect(app('router')->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'admin/api/v2/page-management'))
            ->flatMap(static fn ($route) => collect($route->methods())
                ->reject(static fn (string $method): bool => $method === 'HEAD')
                ->map(static fn (string $method): string => $method.' '.$route->uri()))->values();

        self::assertContains('GET admin/api/v2/page-management/categories', $methods);
        self::assertContains('POST admin/api/v2/page-management/categories', $methods);
        self::assertContains('GET admin/api/v2/page-management/pages', $methods);
        self::assertContains('POST admin/api/v2/page-management/pages', $methods);
        self::assertContains('GET admin/api/v2/page-management/pages/{pageId}', $methods);
        self::assertContains('PUT admin/api/v2/page-management/pages/{pageId}', $methods);
        self::assertFalse($methods->contains(fn (string $route): bool => str_starts_with($route, 'DELETE ')));
    }

    /** @return array<string, mixed> */
    private function pageInput(string $categoryId, string $slug): array
    {
        return [
            'category_id' => $categoryId, 'title' => 'ページ',
            'body_html' => '<p>本文</p>', 'slug' => $slug, 'visibility' => 'hidden',
        ];
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'page-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role, 'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash, 'admin_id' => $admin->id,
            'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false,
            'created_at' => now()->subHour(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(7), 'revoked_at' => null,
        ]);
        return new V2AdminAuthorizationContext(
            (int) $admin->id, $admin->public_id, $admin->role, $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
