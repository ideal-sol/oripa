<?php

namespace Tests\V2;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContactService;
use App\Domain\Mail\Services\V2ContactReplyMailOutboxWorker;
use App\Domain\Mail\Services\V2ContactReplyMailService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\ContactInquiry;
use App\Models\V2\ShippingAddress;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\Support\V2ContactFixture;
use Tests\TestCase;

final class ContactReplyFollowUpTest extends TestCase
{
    use V2ContactFixture;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-09-20T03:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'v2_content_contact.contact_hmac_key' => 'base64:'.base64_encode(str_repeat('h', 32)),
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_identity.rate_limits.contact_ip' => [500, 3600],
            'v2_identity.rate_limits.contact_email' => [500, 3600],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Cache::store('array')->clear();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    private function input(array $extra = []): array
    {
        return [...['name' => 'Input Name', 'email' => 'input@example.test', 'subject' => 'Question', 'body' => 'Initial body', 'website' => ''], ...$extra];
    }

    private function submit(User $user, array $extra = [], ?string $key = null): array
    {
        return app(V2ContactService::class)->submit($this->input($extra), $user, '192.0.2.90', (string) Str::uuid7(), $key ?? (string) Str::uuid7());
    }

    private function inquiry(User $user): ContactInquiry
    {
        $receipt = $this->submit($user);
        return ContactInquiry::query()->where('receipt_code', $receipt['receipt_code'])->firstOrFail();
    }

    public function test_http_authentication_csrf_and_idempotency_are_required(): void
    {
        $csrf = str_repeat('a', 64);
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://storefront.example.test', 'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v2/contact-inquiries', $this->input())->assertUnauthorized();
        $user = $this->contactUser();
        $this->actingAs($user, 'v2_user')->postJson('/api/v2/contact-inquiries', $this->input())->assertAccepted();
        self::assertDatabaseHas('contact_inquiries', ['user_id' => $user->id, 'status' => 'new']);
        $this->withHeader('Idempotency-Key', '')->postJson('/api/v2/contact-inquiries', $this->input())->assertUnprocessable();
        $this->withHeader('Origin', 'https://attacker.example.test')->postJson('/api/v2/contact-inquiries', $this->input())->assertForbidden();
    }

    public function test_owner_follow_up_reopens_every_state_without_changing_initial_data(): void
    {
        $user = $this->contactUser();
        foreach (['new', 'in_progress', 'replied', 'closed'] as $status) {
            $contact = $this->inquiry($user);
            $contact->forceFill(['status' => $status, 'closed_at' => $status === 'closed' ? now() : null])->save();
            $original = $contact->only(['user_id', 'public_id', 'received_at', 'body_ciphertext', 'created_at']);
            $count = ContactInquiry::query()->count();
            CarbonImmutable::setTestNow(now()->addMinute());
            $key = (string) Str::uuid7();
            $input = ['inquiry_id' => $contact->public_id, 'body' => 'Follow up '.$status];
            $response = $this->submit($user, $input, $key);
            self::assertSame($response, $this->submit($user, $input, $key));
            self::assertSame($count, ContactInquiry::query()->count());
            self::assertEquals($original, $contact->refresh()->only(array_keys($original)));
            self::assertSame('in_progress', $contact->status);
            self::assertNull($contact->closed_at);
            $messages = DB::table('contact_user_messages')->where('contact_inquiry_id', $contact->id)->get();
            self::assertCount(1, $messages);
            self::assertSame($user->id, $messages[0]->user_id);
            self::assertNotSame($input['body'], $messages[0]->message_ciphertext);
            self::assertSame($input['body'], Crypt::decryptString($messages[0]->message_ciphertext));
            self::assertSame(now()->utc()->format('Y-m-d H:i:s'), CarbonImmutable::parse($messages[0]->created_at)->utc()->format('Y-m-d H:i:s'));
            self::assertDatabaseHas('contact_status_histories', ['contact_inquiry_id' => $contact->id, 'from_status' => $status, 'to_status' => 'in_progress', 'reason_code' => 'user_follow_up']);
            self::assertDatabaseHas('audit_logs', ['action_code' => 'contact.user_follow_up', 'actor_public_id' => $user->public_id, 'target_public_id' => $contact->public_id]);
            self::assertSame($contact->received_at->toIso8601String(), $response['received_at']);
        }
    }

    public function test_foreign_and_missing_ids_have_identical_new_receipt_shape_and_no_foreign_mutations(): void
    {
        $owner = $this->contactUser();
        $sender = $this->contactUser();
        $foreign = $this->inquiry($owner);
        $original = $foreign->getAttributes();
        $history = DB::table('contact_status_histories')->where('contact_inquiry_id', $foreign->id)->get()->toJson();
        $results = [];
        foreach ([$foreign->public_id, (string) Str::uuid7()] as $id) {
            $key = (string) Str::uuid7();
            $response = $this->submit($sender, ['inquiry_id' => $id], $key);
            self::assertSame($response, $this->submit($sender, ['inquiry_id' => $id], $key));
            $created = ContactInquiry::query()->where('receipt_code', $response['receipt_code'])->firstOrFail();
            self::assertSame($sender->id, $created->user_id);
            self::assertSame('new', $created->status);
            self::assertNotSame($foreign->public_id, $created->public_id);
            self::assertArrayNotHasKey('inquiry_id', $response);
            $results[] = array_keys($response);
        }
        self::assertSame($results[0], $results[1]);
        self::assertSame($original, $foreign->refresh()->getAttributes());
        self::assertSame($history, DB::table('contact_status_histories')->where('contact_inquiry_id', $foreign->id)->get()->toJson());
        self::assertSame(0, DB::table('contact_user_messages')->where('contact_inquiry_id', $foreign->id)->count());
    }

    public function test_malformed_ids_and_database_failure_never_fall_back(): void
    {
        $user = $this->contactUser();
        $contact = $this->inquiry($user);
        $count = ContactInquiry::query()->count();
        try {
            $this->submit($user, ['inquiry_id' => 'not-a-uuid']);
            self::fail('Malformed IDs must fail validation.');
        } catch (V2ContentContactException $exception) {
            self::assertSame(422, $exception->status);
        }
        DB::listen(static function ($query): void {
            if (str_starts_with($query->sql, 'insert into "contact_user_messages"')) {
                throw new RuntimeException('Synthetic follow-up persistence failure.');
            }
        });
        try {
            $this->submit($user, ['inquiry_id' => $contact->public_id]);
            self::fail('Persistence failures must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic follow-up persistence failure.', $exception->getMessage());
        }
        self::assertSame($count, ContactInquiry::query()->count());
        self::assertSame('new', $contact->refresh()->status);
        self::assertDatabaseCount('contact_user_messages', 0);
    }

    public function test_idempotency_is_user_scoped_and_reused_key_with_different_body_is_rejected(): void
    {
        $user = $this->contactUser();
        $key = (string) Str::uuid7();
        $first = $this->submit($user, [], $key);
        try {
            $this->submit($user, ['body' => 'Different operation'], $key);
            self::fail('A key cannot be reused with different input.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTACT_IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
        self::assertDatabaseCount('contact_inquiries', 1);
        $other = $this->submit($this->contactUser(), [], $key);
        self::assertNotSame($first['receipt_code'], $other['receipt_code']);
        self::assertDatabaseCount('contact_inquiries', 2);
    }

    public function test_distinct_operations_are_not_deduplicated_by_body_and_history_is_immutable(): void
    {
        $user = $this->contactUser();
        $key = (string) Str::uuid7();
        $first = $this->submit($user, [], $key);
        self::assertSame($first, $this->submit($user, [], $key));
        self::assertNotSame($first['receipt_code'], $this->submit($user)['receipt_code']);
        $contact = ContactInquiry::query()->where('receipt_code', $first['receipt_code'])->firstOrFail();
        $this->submit($user, ['inquiry_id' => $contact->public_id]);
        $this->submit($user, ['inquiry_id' => $contact->public_id]);
        self::assertSame(2, DB::table('contact_user_messages')->where('contact_inquiry_id', $contact->id)->count());
        foreach (['UPDATE contact_user_messages SET message_ciphertext = message_ciphertext', 'DELETE FROM contact_user_messages', 'TRUNCATE contact_user_messages'] as $sql) {
            try {
                DB::transaction(static fn () => DB::statement($sql));
                self::fail('User messages must be append-only.');
            } catch (\Illuminate\Database\QueryException) {
                self::assertDatabaseCount('contact_user_messages', 2);
            }
        }
    }

    private function reply(ContactInquiry $contact, string $body): string
    {
        $admin = DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'email_normalized' => 'admin-'.Str::uuid7().'@example.test',
            'email_display' => 'admin@example.test', 'password_hash' => app(\App\Domain\Identity\Services\V2PasswordPolicy::class)->hash('valid password'),
            'role' => 'admin', 'state' => 'active', 'created_at' => V2DatabaseTimestamp::format(now()), 'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);
        $id = (string) Str::uuid7();
        DB::table('contact_reply_requests')->insert([
            'public_id' => $id, 'contact_inquiry_id' => $contact->id, 'requested_by_admin_id' => $admin,
            'message_ciphertext' => Crypt::encryptString($body), 'request_id' => (string) Str::uuid7(), 'created_at' => V2DatabaseTimestamp::format(now()),
        ]);
        return $id;
    }

    private function enqueue(ContactInquiry $contact, string $reply, string $event): \App\Models\V2\OutboxMessage
    {
        return app(V2OutboxService::class)->enqueue('contact.notification', 'contact_inquiry', $contact->public_id, $event,
            ['contact_public_id' => $contact->public_id, 'reply_public_id' => $reply], $event.':'.$reply);
    }

    public function test_two_stage_render_uses_current_user_values_and_latest_non_deleted_address(): void
    {
        $user = $this->contactUser();
        $contact = $this->inquiry($user);
        $user->forceFill(['display_name' => '山田 <太郎> &', 'email_display' => 'current@example.test', 'email_normalized' => 'current@example.test'])->save();
        DB::table('user_phone_numbers')->insert([
            'public_id' => (string) Str::uuid7(), 'user_id' => $user->id, 'phone_ciphertext' => Crypt::encryptString('+819000000000'),
            'phone_hmac' => hash('sha256', '+819000000000'), 'verified_at' => V2DatabaseTimestamp::format(now()),
        ]);
        foreach (['古い住所', '新しい住所', '削除住所'] as $street) {
            $fields = ['user_id' => $user->id, 'correlation_hash' => str_repeat('a', 64)];
            foreach (['recipient_name' => 'Shipping Name', 'postal_code' => '1000001', 'prefecture' => '東京都', 'city' => '千代田区', 'street' => $street, 'building' => null, 'phone_number' => 'Shipping Phone'] as $field => $value) {
                $fields[$field.'_ciphertext'] = $value === null ? null : Crypt::encryptString($value);
            }
            $address = new ShippingAddress();
            $address->forceFill($fields)->save();
            if ($street === '削除住所') $address->delete();
        }
        $reply = $this->reply($contact, "発送状況を確認しました。\n{{full_name}}\n{{phone_number}}\n{{email}}\n{{address}}\n{{inquiry_url}}\n<script>alert(1)</script> & {{unknown}}");
        $mail = app(V2ContactReplyMailService::class)->render($contact->public_id, $reply);
        self::assertSame('current@example.test', $mail['recipient']);
        self::assertSame('お問い合わせへのご返信', $mail['subject']);
        self::assertStringContainsString('山田 &lt;太郎&gt; &amp;', $mail['body_html']);
        self::assertStringContainsString('+819000000000', $mail['body_html']);
        self::assertStringContainsString('新しい住所', $mail['body_html']);
        self::assertStringNotContainsString('古い住所', $mail['body_html']);
        self::assertStringNotContainsString('削除住所', $mail['body_html']);
        self::assertStringContainsString('https://storefront.example.test/contact?inquiry_id='.$contact->public_id, $mail['body_html']);
        self::assertStringContainsString('<br>', $mail['body_html']);
        self::assertStringNotContainsString('<script>', $mail['body_html']);
        self::assertStringNotContainsString('&amp;lt;', $mail['body_html']);
        self::assertStringNotContainsString('{{', $mail['body_html']);
        self::assertStringNotContainsString('input@example.test', $mail['body_html']);
    }

    public function test_missing_phone_address_and_unknown_variables_render_blank(): void
    {
        $user = $this->contactUser();
        $contact = $this->inquiry($user);
        $reply = $this->reply($contact, 'Phone[{{phone_number}}] Address[{{address}}] Unknown[{{unknown}}]');
        $mail = app(V2ContactReplyMailService::class)->render($contact->public_id, $reply);
        self::assertStringContainsString('Phone[] Address[] Unknown[]', $mail['body_html']);
    }

    public function test_consumer_never_claims_legacy_or_other_events_and_sends_only_new_reply(): void
    {
        $contact = $this->inquiry($this->contactUser());
        $reply = $this->reply($contact, 'Reply');
        $legacy = $this->enqueue($contact, $reply, 'contact.reply.requested');
        app(V2OutboxService::class)->enqueue('contact.other', 'contact_inquiry', $contact->public_id,
            V2ContactReplyMailOutboxWorker::EVENT, ['contact_public_id' => $contact->public_id, 'reply_public_id' => $reply], 'wrong-topic:'.Str::uuid7());
        $before = DB::table('outbox_messages')->orderBy('id')->get()->toJson();
        Mail::shouldReceive('html')->never();
        self::assertSame(0, app(V2ContactReplyMailOutboxWorker::class)->run('contact-fixture'));
        self::assertSame($before, DB::table('outbox_messages')->orderBy('id')->get()->toJson());
        self::assertSame('pending', $legacy->refresh()->status);
    }

    public function test_new_reply_delivers_once_to_current_email_and_reclaimed_attempt_never_sends(): void
    {
        $user = $this->contactUser();
        $contact = $this->inquiry($user);
        $reply = $this->reply($contact, 'Reply {{inquiry_url}}');
        $legacy = $this->enqueue($contact, $reply, 'contact.reply.requested');
        $legacyBefore = $legacy->getAttributes();
        $event = $this->enqueue($contact, $reply, V2ContactReplyMailOutboxWorker::EVENT);
        $user->forceFill(['email_display' => 'changed@example.test', 'email_normalized' => 'changed@example.test'])->save();
        Mail::shouldReceive('html')->once()->withArgs(static function ($body, $callback): bool {
            $message = new Message(new Email());
            $callback($message);
            self::assertSame('changed@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            return str_contains($body, '/contact?inquiry_id=');
        });
        $worker = app(V2ContactReplyMailOutboxWorker::class);
        self::assertSame(1, $worker->run('contact-fixture'));
        self::assertSame('delivered', $event->refresh()->status);
        self::assertSame($legacyBefore, $legacy->refresh()->getAttributes());
        self::assertSame(0, $worker->run('contact-fixture'));
        $uncertain = $this->enqueue($contact, $this->reply($contact, 'Uncertain'), V2ContactReplyMailOutboxWorker::EVENT);
        app(V2OutboxService::class)->claim('crashed-worker', 1, 1, [V2ContactReplyMailOutboxWorker::TOPIC], [V2ContactReplyMailOutboxWorker::EVENT]);
        CarbonImmutable::setTestNow(now()->addSeconds(2));
        self::assertSame(1, $worker->run('contact-fixture'));
        self::assertSame('failed', $uncertain->refresh()->status);
        self::assertSame('contact_reply_delivery_uncertain', $uncertain->last_error_code);
    }

    public function test_provider_acceptance_followed_by_expired_lease_is_not_sent_again(): void
    {
        $contact = $this->inquiry($this->contactUser());
        $event = $this->enqueue($contact, $this->reply($contact, 'Reply'), V2ContactReplyMailOutboxWorker::EVENT);
        Mail::shouldReceive('html')->once()->andReturnUsing(static function (): void {
            CarbonImmutable::setTestNow(now()->addMinutes(10));
        });
        $worker = app(V2ContactReplyMailOutboxWorker::class);
        self::assertSame(1, $worker->run('contact-fixture', 1));
        self::assertSame('processing', $event->refresh()->status);
        self::assertSame(1, $worker->run('replacement-worker', 1));
        self::assertSame('failed', $event->refresh()->status);
        self::assertSame('contact_reply_delivery_uncertain', $event->last_error_code);
        self::assertSame(0, $worker->run('replacement-worker'));
    }

    public function test_transport_failure_is_terminal_without_automatic_retry(): void
    {
        $contact = $this->inquiry($this->contactUser());
        $event = $this->enqueue($contact, $this->reply($contact, 'Reply'), V2ContactReplyMailOutboxWorker::EVENT);
        Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('Synthetic transport failure'));
        $worker = app(V2ContactReplyMailOutboxWorker::class);
        self::assertSame(1, $worker->run('contact-fixture'));
        self::assertSame('failed', $event->refresh()->status);
        self::assertSame('contact_reply_delivery_uncertain', $event->last_error_code);
        self::assertSame(0, $worker->run('contact-fixture'));
    }
}
