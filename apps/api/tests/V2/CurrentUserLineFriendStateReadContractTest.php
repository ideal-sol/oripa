<?php

namespace Tests\V2;

use App\Domain\Draw\Services\V2DrawEligibilityService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Line\Services\V2LineFriendStateService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CurrentUserLineFriendStateReadContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-17T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_unlinked_user_gets_backend_authoritative_link_presentation(): void
    {
        $user = $this->user();
        Auth::guard('v2_user')->setUser($user);

        $response = $this->getJson('/api/v2/me/line-friend-state')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertExactJson([
                'linked' => false,
                'friend_confirmed' => false,
                'is_line_user' => false,
                'status' => ['code' => 'not_linked', 'label' => 'LINE未連携'],
                'primary_action' => [
                    'code' => 'start_identity_link',
                    'label' => 'LINEを連携する',
                    'href' => null,
                ],
            ]);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    public function test_linked_non_friend_uses_existing_friend_add_url_without_provider_data(): void
    {
        $user = $this->user();
        $this->lineIdentity($user, 'linked-non-friend');
        DB::table('line_messaging_settings')->where('id', 1)->update([
            'friend_add_url' => 'https://line.me/R/ti/p/synthetic',
        ]);
        Auth::guard('v2_user')->setUser($user);

        $response = $this->getJson('/api/v2/me/line-friend-state')
            ->assertOk()
            ->assertExactJson([
                'linked' => true,
                'friend_confirmed' => false,
                'is_line_user' => false,
                'status' => [
                    'code' => 'friend_add_required',
                    'label' => '友だち追加未確認',
                ],
                'primary_action' => [
                    'code' => 'open_friend_add_url',
                    'label' => 'LINE公式アカウントを友だち追加する',
                    'href' => 'https://line.me/R/ti/p/synthetic',
                ],
            ]);

        $serialized = (string) $response->getContent();
        foreach ([
            'subject_hash',
            'issuer',
            'provider',
            'channel_secret',
            'channel_access_token',
            'linked_follow_message',
            'reward_point_amount',
            'internal_id',
        ] as $internalField) {
            self::assertStringNotContainsString($internalField, $serialized);
        }
    }

    public function test_matching_friendship_is_the_same_canonical_line_user_result_used_by_draw(): void
    {
        $user = $this->user();
        $subjectHash = $this->lineIdentity($user, 'confirmed-friend');
        $this->friendship($user, $subjectHash, 'friend');
        Auth::guard('v2_user')->setUser($user);

        $this->getJson('/api/v2/me/line-friend-state')
            ->assertOk()
            ->assertExactJson([
                'linked' => true,
                'friend_confirmed' => true,
                'is_line_user' => true,
                'status' => ['code' => 'confirmed', 'label' => 'LINEユーザー'],
                'primary_action' => ['code' => 'none', 'label' => null, 'href' => null],
            ]);

        self::assertTrue(app(V2LineFriendStateService::class)->isLineUser($user->id));
        $drawEligibility = app(V2DrawEligibilityService::class)->evaluate(
            $user,
            999999,
            'line_users',
            7,
            0,
            CarbonImmutable::now()
        );
        self::assertTrue($drawEligibility['audience_eligible']);
    }

    public function test_unmatched_unfollowed_or_revoked_state_never_becomes_line_user(): void
    {
        $user = $this->user();
        $subjectHash = $this->lineIdentity($user, 'current-identity');
        $this->friendship($user, hash('sha256', 'different-subject'), 'friend');
        Auth::guard('v2_user')->setUser($user);

        $this->getJson('/api/v2/me/line-friend-state')
            ->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('friend_confirmed', false)
            ->assertJsonPath('is_line_user', false);

        DB::table('external_identity_accounts')
            ->where('user_id', $user->id)
            ->where('subject_hash', $subjectHash)
            ->update(['revoked_at' => now()]);
        $this->getJson('/api/v2/me/line-friend-state')
            ->assertOk()
            ->assertJsonPath('linked', false)
            ->assertJsonPath('friend_confirmed', false)
            ->assertJsonPath('is_line_user', false);

        $other = $this->user();
        $otherSubject = $this->lineIdentity($other, 'unfollowed-identity');
        $this->friendship($other, $otherSubject, 'unfollowed');
        Auth::guard('v2_user')->setUser($other);
        $this->getJson('/api/v2/me/line-friend-state')
            ->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('friend_confirmed', false)
            ->assertJsonPath('is_line_user', false);
    }

    public function test_read_does_not_mutate_line_domain_and_missing_url_has_no_unsafe_cta(): void
    {
        $user = $this->user();
        $this->lineIdentity($user, 'read-only');
        DB::table('line_messaging_settings')->where('id', 1)->update([
            'friend_add_url' => null,
        ]);
        Auth::guard('v2_user')->setUser($user);
        $before = $this->lineTableCounts();

        $this->getJson('/api/v2/me/line-friend-state')
            ->assertOk()
            ->assertJsonPath('primary_action.code', 'none')
            ->assertJsonPath('primary_action.label', null)
            ->assertJsonPath('primary_action.href', null);

        self::assertSame($before, $this->lineTableCounts());
    }

    public function test_read_requires_current_user_and_returns_typed_private_problem(): void
    {
        $response = $this->getJson('/api/v2/me/line-friend-state')
            ->assertUnauthorized()
            ->assertHeader('Vary', 'Cookie')
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED')
            ->assertJsonPath('retryable', false);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    private function user(): User
    {
        $email = 'line-friend-state-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Synthetic LINE reader',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function lineIdentity(User $user, string $subject): string
    {
        $subjectHash = hash('sha256', $subject);
        DB::table('external_identity_accounts')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'provider' => 'line',
            'issuer' => 'https://access.line.me',
            'subject_hash' => $subjectHash,
            'linked_at' => now(),
            'last_authenticated_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $subjectHash;
    }

    private function friendship(User $user, string $subjectHash, string $status): void
    {
        DB::table('line_friendships')->insert([
            'public_id' => (string) Str::uuid7(),
            'subject_hash' => $subjectHash,
            'user_id' => $user->id,
            'status' => $status,
            'followed_at' => now()->subMinute(),
            'unfollowed_at' => $status === 'unfollowed' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function lineTableCounts(): array
    {
        return [
            'external_identity_accounts' => DB::table('external_identity_accounts')->count(),
            'line_friendships' => DB::table('line_friendships')->count(),
            'line_pending_follows' => DB::table('line_pending_follows')->count(),
            'line_webhook_events' => DB::table('line_webhook_events')->count(),
            'point_operations' => DB::table('point_operations')->count(),
        ];
    }
}
