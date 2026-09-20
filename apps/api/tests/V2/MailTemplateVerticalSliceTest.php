<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Mail\Exceptions\V2MailTemplateException;
use App\Domain\Mail\Services\V2MailTemplateService;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Domain\Payment\V2\Services\V2LimitedBonusCampaignService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class MailTemplateVerticalSliceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_fixed_seventeen_templates_expose_all_variables_and_no_create_or_delete_routes(): void
    {
        $service = app(V2MailTemplateService::class);
        $result = $service->templates($this->context(V2AdminRole::Admin));

        self::assertCount(17, $result['items']);
        self::assertSame([
            'email_verification',
            'registration_completed',
            'coin_purchase_completed',
            'shipping_requested',
            'shipping_completed',
            'user_closed',
            'contact_received',
            'contact_reply',
            'password_reset',
            'email_change_verification',
            'email_change_completed',
            'password_changed',
            'phone_changed',
            'agency_account_created',
            'agency_password_changed',
            'agency_email_changed',
            'agency_login_information_reissued',
        ], array_column($result['items'], 'key'));
        foreach ($result['items'] as $template) {
            self::assertCount(24, $template['variables']);
        }
        self::assertSame([
            'メールアドレス認証のお願い',
            '会員登録が完了しました',
            'コインの購入が完了しました',
            '発送依頼を受け付けました',
            '景品の発送が完了しました',
            '退会手続きが完了しました',
            'お問い合わせを受け付けました',
            'お問い合わせへのご返信',
            'パスワード再設定のご案内',
            'メールアドレス変更の確認',
            'メールアドレス変更完了のお知らせ',
            'パスワード変更完了のお知らせ',
            '電話番号変更完了のお知らせ',
            '代理店アカウント作成通知',
            '代理店パスワード変更通知',
            '代理店メールアドレス変更通知',
            '代理店ログイン情報再発行通知',
        ], array_column($result['items'], 'subject'));

        $methods = collect(app('router')->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'admin/api/v2/mail-templates'))
            ->flatMap(static fn ($route) => collect($route->methods())
                ->reject(static fn (string $method): bool => $method === 'HEAD')
                ->map(static fn (string $method): string => $method.' '.$route->uri()));
        self::assertContains('GET admin/api/v2/mail-templates', $methods);
        self::assertContains('GET admin/api/v2/mail-templates/{templateKey}', $methods);
        self::assertContains('PUT admin/api/v2/mail-templates/{templateKey}', $methods);
        self::assertContains('POST admin/api/v2/mail-templates/{templateKey}/preview', $methods);
        self::assertFalse($methods->contains(fn (string $route): bool => str_starts_with($route, 'DELETE ')));
        self::assertFalse($methods->contains(fn (string $route): bool => $route === 'POST admin/api/v2/mail-templates'));
    }

    public function test_contact_reply_defaults_edit_and_preview_keep_multiline_plain_reply_content(): void
    {
        $service = app(V2MailTemplateService::class);
        $context = $this->context(V2AdminRole::Admin);
        $current = $service->template($context, 'contact_reply');
        self::assertSame('お問い合わせへのご返信', $current['subject']);
        self::assertSame('<p>{{full_name}} 様</p><p>お問い合わせいただきありがとうございます。</p><p>{{reply_content}}</p><p>その他ご不明点がございましたら、再度お問い合わせください。</p>', $current['body_html']);
        config(['v2_mail_templates.preview_values.full_name' => 'Name & <tag>', 'v2_mail_templates.preview_values.reply_content' => "First line\n<script>alert(1)</script>"]);
        $updated = $service->update($context, 'contact_reply', [
            'subject' => '編集済み {{full_name}}',
            'body_html' => '<p>{{full_name}}</p><p>{{reply_content}}</p><script>bad()</script>',
            'expected_revision' => $current['revision'],
        ], 'contact-template-'.Str::uuid7());
        self::assertSame('編集済み {{full_name}}', $updated['subject']);
        $preview = $service->preview($context, 'contact_reply', ['body_html' => $updated['body_html']]);
        self::assertStringContainsString('Name &amp; &lt;tag&gt;', $preview['body_html']);
        self::assertStringContainsString('First line<br', $preview['body_html']);
        self::assertStringContainsString('&lt;script&gt;', $preview['body_html']);
        self::assertStringNotContainsString('<script', $preview['body_html']);
        self::assertStringNotContainsString('{{', $preview['body_html']);
    }

    public function test_update_sanitizes_rich_text_replays_and_rejects_semantic_empty_values(): void
    {
        $service = app(V2MailTemplateService::class);
        $context = $this->context(V2AdminRole::Admin);
        $current = $service->template($context, 'contact_received');
        $key = 'mail-template-'.Str::uuid7();
        $input = [
            'subject' => '  {{user_name}} お問い合わせ受付  ',
            'body_html' => '<h2 style="text-align:center">受付</h2><p><u>{{contact_body}}</u></p><img src="https://images.example.test/mail.png" onerror="bad()"><script>bad()</script>',
            'expected_revision' => $current['revision'],
        ];
        $updated = $service->update($context, 'contact_received', $input, $key);

        self::assertSame('{{user_name}} お問い合わせ受付', $updated['subject']);
        self::assertStringContainsString('<h2 style="text-align: center">受付</h2>', $updated['body_html']);
        self::assertStringContainsString('<u>{{contact_body}}</u>', $updated['body_html']);
        self::assertStringContainsString('src="https://images.example.test/mail.png"', $updated['body_html']);
        self::assertStringNotContainsString('onerror', $updated['body_html']);
        self::assertStringNotContainsString('<script', $updated['body_html']);
        self::assertTrue($service->update($context, 'contact_received', $input, $key)['idempotent_replay']);

        $unknown = $service->update($context, 'contact_received', [
            'subject' => '{{not_defined}}',
            'body_html' => '<p>{{not_defined}}</p>',
            'expected_revision' => $updated['revision'],
        ], 'unknown-variable-'.Str::uuid7());
        self::assertSame('{{not_defined}}', $unknown['subject']);
        self::assertSame('<p>{{not_defined}}</p>', $unknown['body_html']);

        foreach ([
            ['subject' => '　', 'body_html' => '<p>本文</p>'],
            ['subject' => '件名', 'body_html' => '<p>　<br></p>'],
        ] as $index => $empty) {
            try {
                $service->update($context, 'contact_received', [
                    ...$empty,
                    'expected_revision' => $unknown['revision'],
                ], 'empty-'.$index.'-'.Str::uuid7());
                self::fail('Semantic empty values must be rejected.');
            } catch (V2MailTemplateException $exception) {
                self::assertSame('MAIL_TEMPLATE_INVALID', $exception->errorCode);
            }
        }
    }

    #[DataProvider('purchaseCases')]
    public function test_purchase_mail_uses_confirmed_time_and_actual_grant(
        ?string $method, string $label, string $occurredAt, string $date
    ): void {
        $email = 'purchase-mail-'.Str::uuid7().'@example.test';
        $user = User::query()->create([
            'display_name' => '購入テスト',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        $planId = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'code' => 'mail-'.Str::uuid7(),
            'version_no' => 1, 'name' => '購入時プラン', 'amount' => 8000,
            'paid_point_amount' => 8000, 'free_point_amount' => 1500,
            'currency' => 'JPY', 'status' => 'published', 'published_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $campaigns = app(V2LimitedBonusCampaignService::class);
        $campaign = $campaigns->create(
            $planId, true, new CarbonImmutable('2026-09-01T00:00:00Z'),
            new CarbonImmutable('2026-09-30T00:00:00Z'), 500
        );
        $payments = app(V2PaymentService::class);
        $payment = $payments->createPayment(
            $user->id, $planId, 'fixture', 'mail-'.Str::uuid7(), 'mail-'.Str::uuid7(), $method
        );
        $event = $payments->recordVerifiedProviderEvent(
            'fixture', 'mail-'.Str::uuid7(), 'payment.succeeded', '{}', [],
            $payment->id, null, new CarbonImmutable($occurredAt)
        );
        $grant = $payments->confirmSucceeded($event->id);
        self::assertSame($grant->id, $payments->confirmSucceeded($event->id)->id);
        $campaigns->update(
            $campaign->id, false, new CarbonImmutable('2026-10-01T00:00:00Z'),
            new CarbonImmutable('2026-10-02T00:00:00Z'), 999
        );
        DB::table('point_purchase_plans')->where('id', $planId)->update(['name' => '変更後プラン']);
        DB::table('mail_templates')->where('template_key', 'coin_purchase_completed')->update([
            'subject_template' => '{{purchase_date}} {{acquired_coins}}',
            'body_html' => '<p>{{user_name}}|{{purchase_plan}}|{{purchase_amount}}|{{purchase_date}}|{{payment_method}}|{{acquired_coins}}|{{unknown}}</p>',
        ]);
        $expected = '<p>購入テスト|購入時プラン|8,000円|'.$date.'|'.$label.'|10,000 コイン|</p>';
        Mail::shouldReceive('html')->once()->withArgs(
            static fn (string $html, callable $callback): bool => $html === $expected
        );
        $delivery = DB::table('mail_deliveries')
            ->where('event_key', 'coin.purchase.completed:'.$payment->public_id)->firstOrFail();
        app(V2TemplateMailDeliveryService::class)->deliver($delivery->public_id);
        self::assertDatabaseHas('mail_deliveries', ['id' => $delivery->id, 'status' => 'sent']);
    }

    public static function purchaseCases(): array
    {
        return [
            ['credit_card', 'クレジットカード', '2026-09-08T14:59:59Z', '2026年9月8日'],
            ['paypay', 'PayPay', '2026-09-08T15:00:00Z', '2026年9月9日'],
            ['konbini', 'コンビニ決済', '2026-09-09T00:00:00+09:00', '2026年9月9日'],
            ['virtual_account', '銀行振込', '2026-09-08T10:00:00-05:00', '2026年9月9日'],
            [null, '', '2026-09-09T12:00:00Z', '2026年9月9日'],
        ];
    }

    public function test_html_source_save_reload_preview_and_delivery_keep_tokens_and_sanitize(): void
    {
        $service = app(V2MailTemplateService::class);
        $context = $this->context(V2AdminRole::Admin);
        $current = $service->template($context, 'password_changed');
        $source = '<div><section><script>bad()</script><p onclick="bad()"><strong>獲得コイン：</strong>{{acquired_coins}}</p><a href="javascript:bad()">link</a><img src="https://images.example.test/a.png" onerror="bad()"></section></div>';
        $updated = $service->update($context, 'password_changed', [
            'subject' => $current['subject'], 'body_html' => $source,
            'expected_revision' => $current['revision'],
        ], 'html-source-'.Str::uuid7());
        $reloaded = $service->template($context, 'password_changed');
        self::assertSame($updated['body_html'], $reloaded['body_html']);
        self::assertStringContainsString('<strong>獲得コイン：</strong>{{acquired_coins}}', $reloaded['body_html']);
        $preview = $service->preview($context, 'password_changed', ['body_html' => $source]);
        self::assertStringContainsString('10,000 コイン', $preview['body_html']);
        foreach ([$reloaded['body_html'], $preview['body_html']] as $html) {
            foreach (['<script', 'onclick', 'onerror', 'javascript:', '<div', '<section'] as $unsafe) {
                self::assertStringNotContainsString($unsafe, $html);
            }
        }
        Mail::shouldReceive('html')->once()->withArgs(
            static fn (string $html, callable $callback): bool => $html === $preview['body_html']
        );
        app(V2TemplateMailDeliveryService::class)->sendSecurity(
            'password_changed', 'html-source@example.test', ['acquired_coins' => '10,000 コイン']
        );
    }

    public function test_preview_uses_unsaved_body_dummy_values_and_has_no_side_effects(): void
    {
        Mail::fake();
        $service = app(V2MailTemplateService::class);
        $context = $this->context(V2AdminRole::Admin);
        $revision = $service->template($context, 'shipping_requested')['revision'];
        $deliveryCount = DB::table('mail_deliveries')->count();
        $preview = $service->preview($context, 'shipping_requested', [
            'body_html' => '<h3>{{user_name}}</h3><p>{{gacha_names}}</p><p>{{prize_names}}</p><p>{{not_defined}}</p><p>{{purchase_date}}|{{payment_method}}|{{acquired_coins}}</p>',
        ]);

        self::assertStringContainsString('サンプルユーザー', $preview['body_html']);
        foreach (['春のガチャ', '夏のガチャ', '景品A', '景品B', '景品C'] as $expected) {
            self::assertStringContainsString($expected, $preview['body_html']);
        }
        self::assertSame(3, substr_count($preview['body_html'], '<hr>'));
        self::assertStringNotContainsString('{{not_defined}}', $preview['body_html']);
        self::assertStringContainsString('2026年9月9日|クレジットカード|10,000 コイン', $preview['body_html']);
        self::assertSame($revision, $service->template($context, 'shipping_requested')['revision']);
        self::assertSame($deliveryCount, DB::table('mail_deliveries')->count());
        Mail::assertNothingSent();
    }

    public function test_admin_manage_permission_and_delivery_failure_are_fail_closed_without_retry_or_pii(): void
    {
        $service = app(V2MailTemplateService::class);
        $current = $service->template($this->context(V2AdminRole::Operator), 'user_closed');
        try {
            $service->update($this->context(V2AdminRole::Operator), 'user_closed', [
                'subject' => $current['subject'],
                'body_html' => $current['body_html'],
                'expected_revision' => $current['revision'],
            ], 'operator-denied-'.Str::uuid7());
            self::fail('Operator mutation must be denied.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }

        $email = 'mail-failure-'.Str::uuid7().'@example.test';
        $user = User::query()->create([
            'display_name' => 'Synthetic Mail Failure',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        $eventKey = 'user.closed:'.$user->public_id;
        $delivery = app(V2TemplateMailDeliveryService::class);
        DB::transaction(fn () => $delivery->schedule(
            'user_closed', $eventKey, 'user', $user->public_id
        ));
        DB::transaction(fn () => $delivery->schedule(
            'user_closed', $eventKey, 'user', $user->public_id
        ));
        $row = DB::table('mail_deliveries')->where('event_key', $eventKey)->first();
        self::assertNotNull($row);
        self::assertSame(1, DB::table('mail_deliveries')->where('event_key', $eventKey)->count());

        Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('synthetic failure'));
        $delivery->deliver($row->public_id);
        $delivery->deliver($row->public_id);

        self::assertDatabaseHas('users', ['public_id' => $user->public_id, 'state' => 'active']);
        self::assertDatabaseHas('mail_deliveries', [
            'public_id' => $row->public_id,
            'status' => 'failed',
            'attempts' => 1,
            'failure_code' => 'delivery_failed',
        ]);
        $serialized = json_encode(DB::table('mail_deliveries')->where('public_id', $row->public_id)->first(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($email, $serialized);
        self::assertStringNotContainsString('Synthetic Mail Failure', $serialized);
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'mail-admin-'.Str::uuid7().'@example.test';
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
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
