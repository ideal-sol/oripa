<?php

namespace Tests\V2;

use App\Domain\Line\Enums\V2LineDeliveryPurpose as Purpose;
use App\Domain\Line\Enums\V2LineRecipientGroup as Group;
use App\Domain\Line\Services\V2LineFriendService;
use App\Domain\Line\Services\V2LineRecipientResolver;
use App\Domain\Line\Services\V2LineRecoveredFriendRegistry;
use App\Domain\Line\Services\V2LineSubjectVault;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LineRecipientResolverTest extends TestCase
{
    use \Tests\Support\TestsDatabaseTimeZones;

    private object $bot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array', 'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        Http::preventStrayRequests();
        DB::beginTransaction();
        DB::table('line_messaging_settings')->update(['reward_enabled' => false, 'reward_point_amount' => 0]);
        $id = DB::table('line_bots')->insertGetId(['public_id' => (string) Str::uuid7(), 'name' => 'Synthetic D2',
            'channel_id' => '9876543', 'channel_secret_ciphertext' => Crypt::encryptString('synthetic-only'),
            'is_active' => true, 'connection_status' => 'succeeded', 'connected_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->bot = DB::table('line_bots')->where('id', $id)->first();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_groups_purposes_paging_and_no_plaintext_or_transport(): void
    {
        $this->linked('synthetic-linked');
        $this->recover('synthetic-unlinked');
        foreach (Purpose::cases() as $purpose) {
            self::assertCount(1, $this->recipients(Group::LinkedMembers, $purpose));
            self::assertCount(1, $this->recipients(Group::UnlinkedFriends, $purpose));
            $all = $this->recipients(Group::AllFriends, $purpose);
            self::assertCount(2, $all);
            foreach ($all as $ref) self::assertTrue(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, $purpose));
            self::assertStringNotContainsString('synthetic-linked', json_encode($all));
            self::assertStringNotContainsString('subject', json_encode($all));
        }
        Http::assertNothingSent();
    }

    public function test_inactive_bot_has_no_legacy_fallback_and_switch_back_invalidates_reference(): void
    {
        $this->recover('switch-subject');
        $ref = $this->recipients()[0];
        DB::table('line_bots')->where('id', $this->bot->id)->update(['is_active' => false]);
        self::assertSame([], $this->recipients());
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::Transactional));
        DB::table('line_bots')->where('id', $this->bot->id)->update(['is_active' => true, 'revision' => 3, 'authentication_revision' => 3]);
        self::assertCount(1, $this->recipients());
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::Transactional));
    }

    public function test_recovered_friend_moves_to_linked_after_real_friend_observation_without_reward(): void
    {
        $this->recover('later-linked');
        $old = $this->recipients(Group::UnlinkedFriends)[0];
        $this->linked('later-linked');
        self::assertSame([], $this->recipients(Group::UnlinkedFriends));
        self::assertCount(1, $this->recipients(Group::LinkedMembers));
        self::assertCount(1, $this->recipients());
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($old, Purpose::Promotional));
        self::assertSame(0, DB::table('line_friendships')->where('line_bot_id', $this->bot->id)->whereNotNull('point_operation_id')->count());
    }

    public function test_member_suspension_and_revocation_never_fall_through_to_unlinked(): void
    {
        $user = $this->linked('safety-member');
        $this->recover('safety-member');
        $ref = $this->recipients()[0];
        DB::table('users')->where('id', $user->id)->update(['state' => 'suspended']);
        self::assertSame([], $this->recipients());
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::Transactional));
        DB::table('users')->where('id', $user->id)->update(['state' => 'active']);
        DB::table('external_identity_accounts')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        self::assertSame([], $this->recipients());
    }

    #[DataProvider('databaseTimeZones')]
    public function test_newer_negative_observation_blocks_old_recovery_and_stale_follow(string $zone): void
    {
        $this->assertDatabaseTimeZone($zone);
        $user = $this->linked('block-member');
        $ref = $this->recipients()[0];
        $service = app(V2LineFriendService::class);
        $now = CarbonImmutable::now();
        $service->applyObservation('block-member', $user, false, $now->addSecond(), (string) Str::uuid7());
        $service->applyObservation('block-member', $user, true, $now, (string) Str::uuid7());
        self::assertSame([], $this->recipients());
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::Promotional));
        $service->applyObservation('block-member', $user, true, $now->addSeconds(2), (string) Str::uuid7());
        self::assertCount(1, $this->recipients());
    }

    public function test_recovery_is_idempotent_monotonic_encrypted_and_has_no_follow_date(): void
    {
        $this->recover('recovery-only');
        $before = DB::table('line_recovered_friends')->sole();
        app(V2LineRecoveredFriendRegistry::class)->record((int) $this->bot->id, 1, 'recovery-only', (int) $before->observed_at_ms - 1);
        self::assertEquals($before, DB::table('line_recovered_friends')->sole());
        self::assertSame('recovery-only', Crypt::decryptString($before->subject_ciphertext));
        self::assertFalse(property_exists($before, 'followed_at'));
        self::assertSame(0, DB::table('line_pending_follows')->where('line_bot_id', $this->bot->id)->count());
        foreach ([['subject_hash' => str_repeat('a', 64)], ['subject_ciphertext' => 'replacement'], ['line_bot_id' => $this->bot->id + 1]] as $mutation) {
            try {
                DB::transaction(fn () => DB::table('line_recovered_friends')->where('id', $before->id)->update($mutation));
                self::fail('Immutable binding accepted');
            } catch (\Illuminate\Database\QueryException $error) {
                self::assertSame('P0001', $error->errorInfo[0]);
            }
        }
        foreach ([fn () => DB::table('line_recovered_friends')->where('id', $before->id)->delete(),
            fn () => DB::statement('TRUNCATE line_recovered_friends')] as $remove) {
            try {
                DB::transaction($remove);
                self::fail('Recovered identity removal accepted');
            } catch (\Illuminate\Database\QueryException $error) {
                self::assertSame('P0001', $error->errorInfo[0]);
            }
        }
    }

    public function test_key_rotation_retains_canonical_subject_and_single_recipient(): void
    {
        $this->recover('rotation-subject');
        $first = DB::table('line_recovered_friends')->sole();
        config(['v2_identity.sms_verification.phone_hmac_previous_keys' => ['base64:'.base64_encode(str_repeat('p', 32))],
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
        $this->recover('rotation-subject');
        self::assertSame($first->subject_hash, app(V2LineSubjectVault::class)->canonicalHash('rotation-subject'));
        self::assertCount(1, $this->recipients());
        self::assertTrue(app(V2LineRecipientResolver::class)->eligibleBeforeSend($this->recipients()[0], Purpose::Transactional));
        $this->identityOnly('rotation-subject');
        self::assertCount(1, $this->recipients(Group::LinkedMembers));
        self::assertSame([], $this->recipients(Group::UnlinkedFriends));
    }

    public function test_corrupt_ciphertext_is_rejected_at_immediate_send_boundary(): void
    {
        DB::table('line_recovered_friends')->insert(['line_bot_id' => $this->bot->id,
            'subject_hash' => app(V2LineSubjectVault::class)->canonicalHash('corrupt'),
            'subject_ciphertext' => 'corrupt-synthetic-envelope', 'observed_at_ms' => now()->getTimestampMs()]);
        $ref = $this->recipients()[0];
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::Transactional));
        Http::assertNothingSent();
    }

    private function recipients(Group $group = Group::AllFriends, Purpose $purpose = Purpose::Transactional): array
    {
        return iterator_to_array(app(V2LineRecipientResolver::class)->resolve($group, $purpose, 1));
    }

    public function test_pending_follow_and_d7_overlap_are_one_subject_and_unfollow_wins_ties(): void
    {
        $subject = 'pending-recovery-overlap';
        $hash = app(V2LineSubjectVault::class)->canonicalHash($subject);
        $time = now()->getTimestampMs();
        DB::table('line_pending_follows')->insert(['line_bot_id' => $this->bot->id, 'public_id' => (string) Str::uuid7(),
            'subject_hash' => $hash, 'subject_ciphertext' => Crypt::encryptString($subject), 'status' => 'pending', 'followed_at' => now()]);
        $this->recover($subject);
        self::assertCount(1, $this->recipients());
        $recoveryTime = DB::table('line_recovered_friends')->sole()->observed_at_ms;
        DB::table('line_webhook_events')->insert(['line_bot_id' => $this->bot->id, 'public_id' => (string) Str::uuid7(),
            'subject_hash' => $hash, 'event_id_hash' => hash('sha256', 'block-overlap'), 'event_type' => 'unfollow',
            'event_timestamp_ms' => $recoveryTime, 'occurred_at' => now(), 'reply_status' => 'skipped']);
        self::assertSame([], $this->recipients());
        app(V2LineRecoveredFriendRegistry::class)->record((int) $this->bot->id, 1, $subject, $time - 1);
        self::assertSame([], $this->recipients());
    }

    public function test_other_bot_recovery_is_not_used_and_stale_registration_is_rejected(): void
    {
        $this->recover('only-old-bot');
        $this->identityOnly('only-old-bot');
        DB::table('line_bots')->where('id', $this->bot->id)->update(['is_active' => false]);
        DB::table('line_bots')->insert(['public_id' => (string) Str::uuid7(), 'name' => 'Other synthetic BOT',
            'channel_id' => '2222222', 'channel_secret_ciphertext' => Crypt::encryptString('synthetic-other'),
            'is_active' => true, 'connection_status' => 'succeeded', 'connected_at' => now()]);
        self::assertSame([], $this->recipients());
        try {
            $this->recover('rejected-input');
            self::fail('Stale BOT accepted');
        } catch (\App\Domain\Line\Exceptions\V2LineMessagingException $e) {
            self::assertStringNotContainsString('rejected-input', $e->getMessage());
        }
        self::assertSame(1, DB::table('line_recovered_friends')->count());
    }

    public function test_missing_ciphertext_and_closed_member_are_excluded(): void
    {
        $user = $this->linked('closed-member');
        foreach (['closed', 'anonymized', 'pending_verification', 'verification_failed'] as $state) {
            DB::table('users')->where('id', $user->id)->update(['state' => $state]);
            self::assertSame([], $this->recipients());
        }
        $hash = app(V2LineSubjectVault::class)->canonicalHash('missing-envelope');
        DB::table('line_pending_follows')->insert(['line_bot_id' => $this->bot->id, 'public_id' => (string) Str::uuid7(),
            'subject_hash' => $hash, 'status' => 'pending', 'followed_at' => now()]);
        DB::table('line_webhook_events')->insert(['line_bot_id' => $this->bot->id, 'public_id' => (string) Str::uuid7(),
            'subject_hash' => $hash, 'event_id_hash' => hash('sha256', 'missing-envelope'), 'event_type' => 'follow',
            'event_timestamp_ms' => now()->getTimestampMs(), 'occurred_at' => now(), 'reply_status' => 'pending']);
        self::assertSame([], $this->recipients());
    }

    public function test_closure_ledger_excludes_even_an_active_member(): void
    {
        $user = $this->linked('closure-ledger-member');
        $ref = $this->recipients()[0];
        DB::table('account_closures')->insert(['user_id' => $user->id, 'completed_at' => now(),
            'closed_by' => 'self', 'closure_kind' => 'normal', 'forfeited_points' => 0,
            'forfeited_prizes' => 0, 'request_id' => (string) Str::uuid7()]);
        self::assertSame([], $this->recipients());
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::Transactional));
    }

    private function recover(string $subject): void
    {
        app(V2LineRecoveredFriendRegistry::class)->record((int) $this->bot->id, 1, $subject, now()->getTimestampMs());
    }

    private function linked(string $subject): User
    {
        $user = $this->identityOnly($subject);
        app(V2LineFriendService::class)->applyObservation($subject, $user, true, CarbonImmutable::now(), (string) Str::uuid7());
        return $user;
    }

    private function identityOnly(string $subject): User
    {
        $email = Str::uuid7().'@example.test';
        $user = User::query()->create(['email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'password_hash' => '$argon2id$synthetic', 'password_login_enabled' => true, 'state' => 'active']);
        ExternalIdentityAccount::query()->create(['user_id' => $user->id, 'provider' => 'line',
            'issuer' => 'https://access.line.me', 'subject_hash' => app(V2LineSubjectVault::class)->canonicalHash($subject),
            'linked_at' => now(), 'last_authenticated_at' => now()]);
        // Existing OAuth completion's vault/reconciliation connection points.
        app(V2LineSubjectVault::class)->backfill($subject);
        app(V2LineFriendService::class)->reconcileIdentity(app(V2LineSubjectVault::class)->canonicalHash($subject),
            $user, (string) Str::uuid7(), true, app(V2LineSubjectVault::class)->hashes($subject));
        return $user;
    }

    public function test_human_d7_observation_survives_later_link_with_friend_check_off_without_reward(): void
    {
        config(['v2_line.friendship_check_enabled' => false]);
        $this->recover('recovered-before-link');
        $before = DB::table('line_recovered_friends')->sole();
        $old = $this->recipients(Group::UnlinkedFriends)[0];
        DB::table('line_messaging_settings')->update(['reward_enabled' => true, 'reward_point_amount' => 500]);
        $operations = DB::table('point_operations')->count();
        $user = $this->identityOnly('recovered-before-link');
        self::assertSame([], $this->recipients(Group::UnlinkedFriends));
        $linked = $this->recipients(Group::LinkedMembers);
        self::assertCount(1, $linked);
        self::assertCount(1, $this->recipients());
        self::assertSame($user->id, $linked[0]->userId);
        self::assertSame('line_recovered_friends', $linked[0]->source);
        self::assertTrue(app(V2LineRecipientResolver::class)->eligibleBeforeSend($linked[0], Purpose::Promotional));
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($old, Purpose::Transactional));
        self::assertEquals($before, DB::table('line_recovered_friends')->sole());
        self::assertSame(0, DB::table('line_friendships')->where('line_bot_id', $this->bot->id)->count());
        self::assertSame($operations, DB::table('point_operations')->count());
        Http::assertNothingSent();
    }

    public function test_d7_linked_observation_yields_to_newer_block_and_recovers_only_on_new_follow(): void
    {
        $subject = 'recovery-block-refollow';
        $time = now()->subMinute()->getTimestampMs();
        app(V2LineRecoveredFriendRegistry::class)->record((int) $this->bot->id, 1, $subject, $time);
        $user = $this->identityOnly($subject);
        $ref = $this->recipients()[0];
        $service = app(V2LineFriendService::class);
        $event = ['type' => 'unfollow', 'timestamp' => $time + 1000,
            'source' => ['type' => 'user', 'userId' => $subject], 'webhookEventId' => 'synthetic-d7-block'];
        $service->handleEvents([$event], (string) Str::uuid7(), (int) $this->bot->id);
        $service->handleEvents([$event], (string) Str::uuid7(), (int) $this->bot->id);
        self::assertSame([], $this->recipients());
        self::assertFalse(app(V2LineRecipientResolver::class)->eligibleBeforeSend($ref, Purpose::Transactional));
        // A delayed recovery response and old follow must not resurrect a blocked recipient.
        app(V2LineRecoveredFriendRegistry::class)->record((int) $this->bot->id, 1, $subject, $time);
        $event['type'] = 'follow'; $event['replyToken'] = 'synthetic-only';
        $event['timestamp'] = $time; $event['webhookEventId'] = 'synthetic-d7-old-follow';
        $service->handleEvents([$event], (string) Str::uuid7(), (int) $this->bot->id);
        self::assertSame([], $this->recipients());
        // Real later follow restores eligibility. Existing D1 reply uses a fake only.
        $this->app->instance(\App\Domain\Line\Contracts\V2LineMessagingTransport::class,
            new class implements \App\Domain\Line\Contracts\V2LineMessagingTransport {
                public function replyText(#[\SensitiveParameter] string $replyToken, string $message): \App\Domain\Line\ValueObjects\V2LineReplyResult
                {
                    return new \App\Domain\Line\ValueObjects\V2LineReplyResult(true);
                }
            });
        $event['timestamp'] = $time + 2000; $event['webhookEventId'] = 'synthetic-d7-refollow';
        app(V2LineFriendService::class)->handleEvents([$event], (string) Str::uuid7(), (int) $this->bot->id);
        self::assertCount(1, $this->recipients(Group::LinkedMembers));
        Http::assertNothingSent();
    }

    public function test_d7_subject_mismatch_cannot_inherit_another_members_observation(): void
    {
        $this->recover('actual-recovered-subject');
        $this->identityOnly('different-subject');
        self::assertSame([], $this->recipients(Group::LinkedMembers));
        self::assertCount(1, $this->recipients(Group::UnlinkedFriends));
    }
}
