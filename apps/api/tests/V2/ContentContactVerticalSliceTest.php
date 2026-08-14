<?php

namespace Tests\V2;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContactService;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\ContentContact\Services\V2ContentHtmlSanitizer;
use App\Domain\ContentContact\Services\V2ContentReadService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ContentContactVerticalSliceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-02T03:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'v2_content_contact.contact_hmac_key' =>
                'base64:'.base64_encode(str_repeat('h', 32)),
            'v2_content_contact.contact_retention_days' => 365,
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_identity.rate_limits.contact_ip' => [5, 3600],
            'v2_identity.rate_limits.contact_email' => [3, 3600],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' =>
                'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Cache::store('array')->clear();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_html_sanitizer_keeps_document_markup_and_removes_active_content(): void
    {
        $html = <<<'HTML'
            <h2 onclick="steal()">見出し</h2>
            <p style="color:red">本文 <strong>強調</strong></p>
            <a href="javascript:alert(1)" target="_blank">危険</a>
            <a href="https://example.test/path" target="_blank">安全</a>
            <table><tr><th colspan="2">表</th></tr></table>
            <script>alert(1)</script><iframe src="https://example.test"></iframe>
            HTML;
        $sanitized = app(V2ContentHtmlSanitizer::class)->sanitize($html);

        self::assertStringContainsString('<h2>見出し</h2>', $sanitized);
        self::assertStringContainsString('<strong>強調</strong>', $sanitized);
        self::assertStringContainsString('<table>', $sanitized);
        self::assertStringContainsString('rel="noopener noreferrer"', $sanitized);
        self::assertStringNotContainsString('onclick', $sanitized);
        self::assertStringNotContainsString('style=', $sanitized);
        self::assertStringNotContainsString('javascript:', $sanitized);
        self::assertStringNotContainsString('<script', $sanitized);
        self::assertStringNotContainsString('<iframe', $sanitized);
    }

    public function test_published_content_respects_period_order_cursor_and_public_asset(): void
    {
        $context = $this->context(V2AdminRole::Admin);
        $service = app(V2ContentContactAdminService::class);
        $asset = $this->asset(true);
        $later = $service->createContent($context, 'banner', [
            'code' => 'hero-later',
            'title' => 'Later',
            'link_url' => '/gachas',
            'show_on_top' => true,
            'sort_order' => 20,
            'asset_id' => $asset,
            'publish_start_at' => now()->subMinute()->toIso8601String(),
        ]);
        $first = $service->createContent($context, 'banner', [
            'code' => 'hero-first',
            'title' => 'First',
            'link_url' => 'https://example.test/notices',
            'show_on_top' => true,
            'sort_order' => 10,
            'asset_id' => $asset,
            'publish_start_at' => now()->subMinute()->toIso8601String(),
        ]);
        $topOff = $service->createContent($context, 'banner', [
            'code' => 'hero-top-off',
            'title' => 'Top Off',
            'link_url' => '/hidden',
            'show_on_top' => false,
            'sort_order' => 5,
            'asset_id' => $asset,
            'publish_start_at' => now()->subMinute()->toIso8601String(),
        ]);
        $futureTop = $service->createContent($context, 'banner', [
            'code' => 'hero-future',
            'title' => 'Future Top',
            'link_url' => '/future',
            'show_on_top' => true,
            'sort_order' => 1,
            'asset_id' => $asset,
            'publish_start_at' => now()->addMinute()->toIso8601String(),
        ]);
        $service->publish(
            $context,
            'banner',
            $later['id'],
            $later['versions'][0]['id']
        );
        $service->publish(
            $context,
            'banner',
            $first['id'],
            $first['versions'][0]['id']
        );
        foreach ([$topOff, $futureTop] as $excludedBanner) {
            $service->publish(
                $context,
                'banner',
                $excludedBanner['id'],
                $excludedBanner['versions'][0]['id']
            );
        }

        $noticeIds = [];
        foreach ([
            ['public-now', now()->subMinute(), null, $asset],
            ['public-expiring', now()->subMinute(), now()->addMinute()],
            ['future', now()->addMinute(), null],
            ['expired', now()->subHours(2), now()->subHour()],
        ] as $noticeFixture) {
            [$slug, $start, $end] = $noticeFixture;
            $created = $service->createContent($context, 'notice', [
                'slug' => $slug,
                'title' => $slug,
                'summary' => '公開要約',
                'body_html' => '<p>本文</p>',
                'is_important' => $slug === 'public-now',
                'asset_id' => $noticeFixture[3] ?? null,
                'publish_start_at' => $start->toIso8601String(),
                'publish_end_at' => $end?->toIso8601String(),
            ]);
            $service->publish(
                $context,
                'notice',
                $created['id'],
                $created['versions'][0]['id']
            );
            $noticeIds[$slug] = $created['id'];
        }

        $read = app(V2ContentReadService::class);
        $banners = $read->banners()['items'];
        self::assertSame(
            ['First', 'Later'],
            array_column($banners, 'title')
        );
        self::assertSame('/api/v2/content/assets/'.$asset, $banners[0]['asset']['path']);
        self::assertSame('/api/v2/content/assets/'.$asset, $banners[0]['image_url']);
        $firstPage = $read->notices(null, 1);
        self::assertCount(1, $firstPage['items']);
        self::assertNotNull($firstPage['next_cursor']);
        $secondPage = $read->notices($firstPage['next_cursor'], 1);
        self::assertCount(1, $secondPage['items']);
        self::assertNull($secondPage['next_cursor']);
        self::assertSame(
            ['public-expiring', 'public-now'],
            array_column([...$firstPage['items'], ...$secondPage['items']], 'slug')
        );
        self::assertSame(
            $noticeIds['public-now'],
            $read->notice($noticeIds['public-now'])['id']
        );
        self::assertSame(
            $asset,
            $read->notice($noticeIds['public-now'])['asset']['id']
        );
        foreach (['future', 'expired'] as $hidden) {
            try {
                $read->notice($noticeIds[$hidden]);
                self::fail('Outside-period content must not be public.');
            } catch (V2ContentContactException $exception) {
                self::assertSame('CONTENT_NOT_FOUND', $exception->errorCode);
            }
        }
    }

    public function test_published_version_is_immutable_and_legal_publish_requires_fresh_mfa(): void
    {
        $stale = $this->context(V2AdminRole::Owner, now()->subMinutes(5));
        $service = app(V2ContentContactAdminService::class);
        $page = $service->createContent($stale, 'static-page', [
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'body_html' => '<p>Policy</p>',
            'asset_id' => $this->asset(true),
            'publish_start_at' => now()->subMinute()->toIso8601String(),
        ]);
        try {
            $service->publish(
                $stale,
                'static-page',
                $page['id'],
                $page['versions'][0]['id']
            );
            self::fail('Legal publish must require Fresh MFA.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('FRESH_AUTHENTICATION_REQUIRED', $exception->errorCode);
        }

        $fresh = $this->context(V2AdminRole::Owner);
        $published = $service->publish(
            $fresh,
            'static-page',
            $page['id'],
            $page['versions'][0]['id']
        );
        self::assertSame('published', $published['status']);
        self::assertTrue($published['is_legal']);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'content.legal_published',
            'outcome' => 'success',
        ]);

        try {
            DB::transaction(
                fn (): int => DB::table('content_versions')
                    ->where('public_id', $page['versions'][0]['id'])
                    ->update(['title' => 'Mutated'])
            );
            self::fail('Published Content Version must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::transaction(
                fn (): int => DB::table('content_version_assets')
                    ->whereIn(
                        'content_version_id',
                        DB::table('content_versions')
                            ->where('public_id', $page['versions'][0]['id'])
                            ->select('id')
                    )
                    ->delete()
            );
            self::fail('Published Content Asset relation must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        self::assertSame(
            'Privacy Policy',
            app(V2ContentReadService::class)
                ->staticPage('privacy')['title']
        );

        $replacement = $service->createVersion(
            $fresh,
            'static-page',
            $page['id'],
            [
                'title' => 'Privacy Policy v2',
                'body_html' => '<p>Policy v2</p>',
                'publish_start_at' => now()->subMinute()->toIso8601String(),
            ]
        );
        try {
            $service->publish(
                $stale,
                'static-page',
                $page['id'],
                $replacement['id']
            );
            self::fail('Legal replacement must require Fresh MFA.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('FRESH_AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
        $service->publish($fresh, 'static-page', $page['id'], $replacement['id']);
        self::assertSame(
            'Privacy Policy v2',
            app(V2ContentReadService::class)->staticPage('privacy')['title']
        );
        try {
            $service->archive($stale, 'static-page', $page['id']);
            self::fail('Legal archive must require Fresh MFA.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('FRESH_AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
        $service->archive($fresh, 'static-page', $page['id']);
        try {
            app(V2ContentReadService::class)->staticPage('privacy');
            self::fail('Archived Legal Content must not be public.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_NOT_FOUND', $exception->errorCode);
        }
    }

    public function test_draft_duplicate_and_non_public_assets_fail_closed(): void
    {
        $context = $this->context(V2AdminRole::Admin);
        $service = app(V2ContentContactAdminService::class);
        $draft = $service->createContent($context, 'notice', [
            'slug' => 'draft-only',
            'title' => 'Draft',
            'body_html' => '<p>Draft body</p>',
            'publish_start_at' => now()->subMinute()->toIso8601String(),
        ]);
        try {
            app(V2ContentReadService::class)->notice($draft['id']);
            self::fail('Draft Notice must not be public.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_NOT_FOUND', $exception->errorCode);
        }
        try {
            $service->createContent($context, 'notice', [
                'slug' => 'draft-only',
                'title' => 'Duplicate',
                'body_html' => '<p>Duplicate</p>',
                'publish_start_at' => now()->subMinute()->toIso8601String(),
            ]);
            self::fail('Duplicate Content identifier must fail closed.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_IDENTIFIER_CONFLICT', $exception->errorCode);
        }

        $privateNotice = $service->createContent($context, 'notice', [
            'slug' => 'private-asset',
            'title' => 'Private asset',
            'body_html' => '<p>Private asset</p>',
            'asset_id' => $this->asset(false),
            'publish_start_at' => now()->subMinute()->toIso8601String(),
        ]);
        try {
            $service->publish(
                $context,
                'notice',
                $privateNotice['id'],
                $privateNotice['versions'][0]['id']
            );
            self::fail('Non-public Content Asset must not be published.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_ASSET_NOT_PUBLIC', $exception->errorCode);
        }

        $assetlessBanner = $service->createContent($context, 'banner', [
            'code' => 'assetless-banner',
            'title' => 'Assetless',
            'link_url' => '/',
            'publish_start_at' => now()->subMinute()->toIso8601String(),
        ]);
        try {
            $service->publish(
                $context,
                'banner',
                $assetlessBanner['id'],
                $assetlessBanner['versions'][0]['id']
            );
            self::fail('Banner without Public Asset must not be published.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_PUBLIC_ASSET_REQUIRED', $exception->errorCode);
        }
    }

    public function test_contact_is_encrypted_audited_and_enqueues_notifications_atomically(): void
    {
        $requestId = (string) Str::uuid7();
        $input = $this->contactInput();
        $outboxBefore = DB::table('outbox_messages')->count();
        $result = app(V2ContactService::class)->submit(
            $input,
            null,
            '192.0.2.10',
            $requestId
        );
        self::assertSame('accepted', $result['status']);
        self::assertStringStartsWith('CNT-', $result['receipt_code']);
        $row = DB::table('contact_inquiries')
            ->where('receipt_code', $result['receipt_code'])->first();
        self::assertNotNull($row);
        self::assertNotSame($input['email'], $row->email_ciphertext);
        self::assertNotSame($input['body'], $row->body_ciphertext);
        self::assertSame($input['email'], Crypt::decryptString($row->email_ciphertext));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row->email_correlation_hash);
        self::assertDatabaseCount('contact_status_histories', 1);
        self::assertSame(
            $outboxBefore + 2,
            DB::table('outbox_messages')->count()
        );
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'contact.received',
            'outcome' => 'success',
        ]);
        $serialized = DB::table('audit_logs')->latest('id')->value('metadata_redacted');
        self::assertStringNotContainsString($input['email'], (string) $serialized);
        self::assertStringNotContainsString($input['body'], (string) $serialized);

        $before = [
            DB::table('contact_inquiries')->count(),
            DB::table('contact_status_histories')->count(),
            DB::table('outbox_messages')->count(),
        ];
        try {
            DB::transaction(function () use ($input): never {
                app(V2ContactService::class)->submit(
                    [...$input, 'email' => 'rollback@example.test'],
                    null,
                    '192.0.2.11',
                    (string) Str::uuid7()
                );
                throw new RuntimeException('force outer rollback');
            });
        } catch (RuntimeException) {
            self::assertSame($before, [
                DB::table('contact_inquiries')->count(),
                DB::table('contact_status_histories')->count(),
                DB::table('outbox_messages')->count(),
            ]);
        }
    }

    public function test_authenticated_contact_is_correlated_without_public_pii(): void
    {
        $email = 'contact-user-'.Str::uuid7().'@example.test';
        $user = User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        $result = app(V2ContactService::class)->submit(
            [...$this->contactInput(), 'email' => $email],
            $user,
            '192.0.2.12',
            (string) Str::uuid7()
        );
        self::assertDatabaseHas('contact_inquiries', [
            'receipt_code' => $result['receipt_code'],
            'user_id' => $user->id,
        ]);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($email, $serialized);
        self::assertStringNotContainsString('user_id', $serialized);
    }

    public function test_contact_admin_workflow_separates_notes_and_keeps_history_append_only(): void
    {
        $result = app(V2ContactService::class)->submit(
            $this->contactInput(),
            null,
            '192.0.2.20',
            (string) Str::uuid7()
        );
        $publicId = DB::table('contact_inquiries')
            ->where('receipt_code', $result['receipt_code'])->value('public_id');
        self::assertIsString($publicId);
        $context = $this->context(V2AdminRole::Admin);
        $service = app(V2ContentContactAdminService::class);
        $detail = $service->contactDetail($context, $publicId);
        self::assertSame('customer@example.test', $detail['email']);
        $service->addInternalNote($context, $publicId, 'Internal triage only');
        $reply = $service->requestReply($context, $publicId, 'Safe reply request');
        self::assertSame('queued', $reply['status']);
        self::assertDatabaseHas('contact_inquiries', [
            'public_id' => $publicId,
            'status' => 'in_progress',
        ]);
        self::assertDatabaseCount('contact_internal_notes', 1);
        self::assertDatabaseCount('contact_reply_requests', 1);
        self::assertDatabaseHas('outbox_messages', [
            'event_type' => 'contact.reply.requested',
        ]);
        $service->updateContactStatus(
            $context,
            $publicId,
            'closed',
            'resolved'
        );
        try {
            $service->requestReply($context, $publicId, 'Late reply');
            self::fail('Closed Contact must reject Reply Request.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTACT_CLOSED', $exception->errorCode);
        }

        try {
            DB::transaction(
                fn (): int => DB::table('contact_status_histories')
                    ->update(['reason_code' => 'tampered'])
            );
            self::fail('Contact status history must be append-only.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::transaction(
                fn (): int => DB::table('contact_inquiries')
                    ->where('public_id', $publicId)->delete()
            );
            self::fail('Contact retention must reject physical deletion.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_rate_limit_and_permission_matrix_fail_closed_without_pii(): void
    {
        $service = app(V2ContactService::class);
        $receipts = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $receipts[] = $service->submit(
                $this->contactInput(),
                null,
                '192.0.2.'.(30 + $attempt),
                (string) Str::uuid7()
            )['receipt_code'];
        }
        self::assertCount(3, array_unique($receipts));
        try {
            $service->submit(
                $this->contactInput(),
                null,
                '192.0.2.40',
                (string) Str::uuid7()
            );
            self::fail('The email correlation rate limit must reject the fourth request.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame(429, $exception->status);
        }
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'contact.rate_limited',
            'outcome' => 'failure',
        ]);
        self::assertStringNotContainsString(
            'customer@example.test',
            (string) DB::table('audit_logs')
                ->where('action_code', 'contact.rate_limited')->value('metadata_redacted')
        );

        $operator = $this->context(V2AdminRole::Operator);
        self::assertCount(
            3,
            app(V2ContentContactAdminService::class)
                ->contactList($operator, null, 20)['items']
        );
        try {
            app(V2ContentContactAdminService::class)->createContent(
                $operator,
                'notice',
                [
                    'slug' => 'operator-write',
                    'title' => 'Denied',
                    'body_html' => '<p>Denied</p>',
                    'publish_start_at' => now()->toIso8601String(),
                ]
            );
            self::fail('Operator must not manage Content.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }
    }

    public function test_contact_normalizes_unicode_rejects_oversize_and_routes_match_contract(): void
    {
        $input = [
            ...$this->contactInput(),
            'name' => "Cafe\u{0301}",
            'email' => 'unicode@example.test',
        ];
        $result = app(V2ContactService::class)->submit(
            $input,
            null,
            '192.0.2.50',
            (string) Str::uuid7()
        );
        $ciphertext = DB::table('contact_inquiries')
            ->where('receipt_code', $result['receipt_code'])
            ->value('name_ciphertext');
        self::assertSame('Café', Crypt::decryptString($ciphertext));

        foreach ([
            [...$this->contactInput(), 'body' => str_repeat('x', 5001)],
            [...$this->contactInput(), 'website' => 'bot-value'],
        ] as $invalid) {
            try {
                app(V2ContactService::class)->submit(
                    $invalid,
                    null,
                    '192.0.2.51',
                    (string) Str::uuid7()
                );
                self::fail('Invalid Contact input must be rejected.');
            } catch (V2ContentContactException $exception) {
                self::assertSame('CONTACT_REQUEST_INVALID', $exception->errorCode);
            }
        }

        $uris = collect(app('router')->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();
        self::assertContains('api/v2/content/banners', $uris);
        self::assertContains('api/v2/content/assets/{assetId}', $uris);
        self::assertContains('api/v2/contact-inquiries', $uris);
        self::assertContains('admin/api/v2/content/banners', $uris);
        self::assertContains('admin/api/v2/contact-inquiries', $uris);
        self::assertNotContains('admin/api/v2/auth/content/banners', $uris);
    }

    public function test_contact_http_requires_exact_origin_csrf_json_and_keeps_request_id(): void
    {
        config([
            'v2_identity.origins.user' => 'https://storefront.example.test',
        ]);
        $csrf = str_repeat('a', 64);
        $response = $this
            ->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->postJson('/api/v2/contact-inquiries', $this->contactInput())
            ->assertAccepted();
        $requestId = $response->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertSame($requestId, $response->json('request_id'));
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'contact.received',
            'request_id' => $requestId,
        ]);

        $this->withHeaders([
            'Origin' => 'https://attacker.example.test',
            'Sec-Fetch-Site' => 'cross-site',
            'X-XSRF-TOKEN' => $csrf,
        ])->postJson('/api/v2/contact-inquiries', $this->contactInput())
            ->assertForbidden()
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_admin_content_list_rejects_invalid_page_size(): void
    {
        try {
            app(V2ContentContactAdminService::class)->contentList(
                $this->context(V2AdminRole::Operator),
                'notice',
                null,
                0
            );
            self::fail('Invalid Admin Content page size must be rejected.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_PAGE_SIZE_INVALID', $exception->errorCode);
        }
    }

    /** @return array<string, string> */
    private function contactInput(): array
    {
        return [
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'phone' => '09000000000',
            'subject' => 'Question',
            'body' => 'Contact body containing no production data.',
            'website' => '',
        ];
    }

    private function asset(bool $public): string
    {
        $publicId = (string) Str::uuid7();
        DB::table('catalog_presentation_assets')->insert([
            'public_id' => $publicId,
            'storage_identifier' => 'content/'.Str::uuid7().'.png',
            'public_path' => '/assets/content/'.Str::uuid7().'.png',
            'checksum_sha256' => str_repeat('a', 64),
            'media_type' => 'image',
            'mime_type' => 'image/png',
            'byte_size' => 128,
            'alt_text' => 'Content image',
            'is_public' => $public,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function context(
        V2AdminRole $role,
        ?\DateTimeInterface $verifiedAt = null
    ): V2AdminAuthorizationContext {
        $email = 'content-'.Str::uuid7().'@example.test';
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
            'mfa_verified_at' => $verifiedAt ?? now(),
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
