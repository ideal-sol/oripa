<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\LineFriendLink;
use App\Models\LineFriendSetting;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LineFriendApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.line.channel_secret' => 'line-channel-secret',
            'services.line.channel_access_token' => 'line-channel-access-token',
            'services.line.friend_add_url' => 'https://line.me/R/ti/p/@luxepack',
        ]);
    }

    public function test_admin_can_update_line_friend_setting_and_see_counts(): void
    {
        $admin = $this->actingAdmin();
        LineFriendSetting::current();
        LineFriendLink::query()->create([
            'line_user_id' => 'line-friend-1',
            'status' => 'friend',
            'followed_at' => now(),
        ]);
        LineFriendLink::query()->create([
            'line_user_id' => 'line-blocked-1',
            'status' => 'blocked',
            'blocked_at' => now(),
        ]);

        $this->putJson('/admin/api/line-friend-settings', [
            'friend_add_url' => 'https://line.me/R/ti/p/@new-luxepack',
            'reward_point_amount' => 500,
            'reward_expiration_days' => 90,
            'is_active' => true,
            'auto_reply_message' => 'コードを送信してください。',
        ])
            ->assertOk()
            ->assertJsonPath('data.friend_add_url', 'https://line.me/R/ti/p/@new-luxepack')
            ->assertJsonPath('data.reward_point_amount', 500)
            ->assertJsonPath('data.reward_expiration_days', 90)
            ->assertJsonPath('data.auto_reply_message', 'コードを送信してください。')
            ->assertJsonPath('data.friends_count', 1)
            ->assertJsonPath('data.blocked_count', 1);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.line_friend_setting.updated',
            'auditable_type' => LineFriendSetting::class,
            'auditable_id' => 1,
        ]);
    }

    public function test_line_webhook_rejects_invalid_signature(): void
    {
        $this->postJson('/api/line/webhook', ['events' => []], [
            'X-Line-Signature' => 'invalid',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Invalid LINE signature.');
    }

    public function test_line_follow_event_creates_friend_and_replies_auto_message(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true]),
        ]);
        LineFriendSetting::current()->forceFill([
            'auto_reply_message' => 'LINE連携コードを送信してください。',
        ])->save();
        $payload = [
            'events' => [
                [
                    'type' => 'follow',
                    'replyToken' => 'reply-token-1',
                    'source' => ['userId' => 'line-user-follow'],
                ],
            ],
        ];

        $this->postJson('/api/line/webhook', $payload, $this->lineSignatureHeaders($payload))
            ->assertOk()
            ->assertJsonPath('message', 'OK');

        $this->assertDatabaseHas('line_friend_links', [
            'line_user_id' => 'line-user-follow',
            'status' => 'friend',
        ]);
        $this->assertDatabaseHas('line_friend_link_events', [
            'line_user_id' => 'line-user-follow',
            'event_type' => 'follow',
            'status' => 'received',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.line.me/v2/bot/message/reply'
            && $request['messages'][0]['text'] === 'LINE連携コードを送信してください。');
    }

    public function test_line_code_message_links_user_and_grants_reward_once(): void
    {
        LineFriendSetting::current()->forceFill([
            'reward_point_amount' => 600,
            'reward_expiration_days' => 120,
            'is_active' => true,
        ])->save();
        $user = User::factory()->create([
            'line_link_code' => 'LNTESTCODE1',
            'line_user_id' => null,
            'line_linked_at' => null,
        ]);

        $payload = [
            'events' => [
                [
                    'type' => 'message',
                    'replyToken' => 'reply-token-2',
                    'source' => ['userId' => 'line-user-link'],
                    'message' => [
                        'type' => 'text',
                        'text' => 'lntestcode1',
                    ],
                ],
            ],
        ];

        $this->postJson('/api/line/webhook', $payload, $this->lineSignatureHeaders($payload))
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'line_user_id' => 'line-user-link',
        ]);
        $this->assertDatabaseHas('line_friend_links', [
            'user_id' => $user->id,
            'line_user_id' => 'line-user-link',
            'status' => 'linked',
            'link_code' => 'LNTESTCODE1',
            'reward_point_amount' => 600,
        ]);
        $this->assertSame(600, (int) $user->wallet()->firstOrFail()->free_balance);
        $this->assertSame(1, PointLot::query()->where('source_type', 'line_friend')->count());
        $this->assertSame(1, PointLedger::query()->where('related_type', 'line_friend_link')->count());

        $this->postJson('/api/line/webhook', $payload, $this->lineSignatureHeaders($payload))
            ->assertOk();

        $this->assertSame(600, (int) $user->wallet()->firstOrFail()->free_balance);
        $this->assertSame(1, PointLot::query()->where('source_type', 'line_friend')->count());
    }

    public function test_user_resource_exposes_line_link_state(): void
    {
        config(['services.line.friend_add_url' => 'https://line.me/R/ti/p/@luxepack']);
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['user']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.line_link_code', fn (?string $value): bool => is_string($value) && str_starts_with($value, 'LN'))
            ->assertJsonPath('data.line_linked', false)
            ->assertJsonPath('data.line_friend_add_url', 'https://line.me/R/ti/p/@luxepack');
    }

    private function actingAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }

    private function lineSignatureHeaders(array $payload): array
    {
        $body = json_encode($payload);

        return [
            'X-Line-Signature' => base64_encode(hash_hmac('sha256', $body, 'line-channel-secret', true)),
        ];
    }
}
