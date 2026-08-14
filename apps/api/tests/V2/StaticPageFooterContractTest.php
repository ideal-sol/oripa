<?php

namespace Tests\V2;

use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaticPageFooterContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-09-05T03:00:00Z');
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

    public function test_footer_list_contains_only_current_footer_pages_in_stable_order(): void
    {
        $context = $this->context();
        $this->publishedPage($context, 'terms', '利用規約', true, 20, now()->subHour(), now()->addHour());
        $privacy = $this->publishedPage($context, 'privacy', 'プライバシー', true, 10, now()->subHour(), null);
        $this->publishedPage($context, 'guide', 'ガイド', false, 1, now()->subHour(), null);
        $this->publishedPage($context, 'future', '公開前', true, 1, now()->addHour(), now()->addHours(2));
        $this->publishedPage($context, 'ended', '公開終了', true, 1, now()->subHours(2), now()->subHour());

        $response = $this->getJson('/api/v2/content/footer-pages');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertJsonPath('items.0.id', $privacy)
            ->assertJsonPath('items.0.slug', 'privacy')
            ->assertJsonPath('items.0.title', 'プライバシー')
            ->assertJsonPath('items.1.slug', 'terms')
            ->assertJsonCount(2, 'items');
        $this->getJson('/api/v2/content/pages/privacy')
            ->assertOk()
            ->assertJsonPath('body_html', '<p>プライバシー</p>');
    }

    private function publishedPage(
        V2AdminAuthorizationContext $context,
        string $slug,
        string $title,
        bool $showInFooter,
        int $sortOrder,
        \DateTimeInterface $start,
        ?\DateTimeInterface $end
    ): string {
        $service = app(V2ContentContactAdminService::class);
        $page = $service->createContent($context, 'static-page', [
            'slug' => $slug,
            'title' => $title,
            'body_html' => '<p>'.$title.'</p>',
            'publish_start_at' => $start->format(DATE_ATOM),
            'publish_end_at' => $end?->format(DATE_ATOM),
            'sort_order' => $sortOrder,
        ]);
        DB::table('content_static_pages')->where('public_id', $page['id'])
            ->update(['show_in_footer' => $showInFooter]);
        $service->publish($context, 'static-page', $page['id'], $page['versions'][0]['id']);

        return $page['id'];
    }

    private function context(): V2AdminAuthorizationContext
    {
        $email = 'footer-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Admin,
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
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(11),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
