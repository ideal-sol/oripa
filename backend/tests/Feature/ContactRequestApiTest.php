<?php

namespace Tests\Feature;

use App\Mail\ContactReceivedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_request_notifies_admin_discord_when_webhook_is_configured(): void
    {
        Mail::fake();
        config(['services.discord.admin_webhook_url' => 'https://discord.test/webhook']);
        Http::fake([
            'discord.test/*' => Http::response('', 204),
        ]);

        $this->postJson('/api/contact-requests', [
            'name' => '山田 太郎',
            'email' => 'contact@example.test',
            'phone' => '09012345678',
            'body' => 'ポイント購入について確認したいです。',
        ])->assertCreated();

        $this->assertDatabaseHas('contact_requests', [
            'name' => '山田 太郎',
            'email' => 'contact@example.test',
            'phone' => '09012345678',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.test/webhook'
            && str_contains($request['content'], '【新規お問い合わせ】')
            && str_contains($request['content'], '氏名: 山田 太郎')
            && str_contains($request['content'], 'メール: contact@example.test'));
        Mail::assertSent(ContactReceivedMail::class, fn (ContactReceivedMail $mail): bool => $mail->hasTo('contact@example.test'));
    }

    public function test_contact_request_skips_discord_when_webhook_is_not_configured(): void
    {
        Mail::fake();
        config(['services.discord.admin_webhook_url' => null]);
        Http::fake();

        $this->postJson('/api/contact-requests', [
            'name' => '佐藤 花子',
            'email' => 'support@example.test',
            'phone' => '08011112222',
            'body' => '配送について確認したいです。',
        ])->assertCreated();

        Http::assertNothingSent();
        Mail::assertSent(ContactReceivedMail::class, fn (ContactReceivedMail $mail): bool => $mail->hasTo('support@example.test'));
    }
}
