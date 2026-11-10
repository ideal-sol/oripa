<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Line\Services\V2LineCallbackNotice;
use App\Domain\Line\Services\V2LineFriendService;
use App\Domain\Line\Services\V2LineSubjectVault;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LineFriendObservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array', 'v2_line.friendship_check_enabled' => true,
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        Cache::store('array')->clear();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function linked(): array
    {
        $user = User::query()->create(['email_display' => 'line-observation@example.test',
            'email_normalized' => 'line-observation@example.test', 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid fixture password'), 'state' => V2UserState::Active]);
        $subject = 'synthetic-line-observation';
        $account = ExternalIdentityAccount::query()->create(['user_id' => $user->id, 'provider' => 'line',
            'issuer' => 'https://access.line.me', 'subject_hash' => app(V2IdentityCorrelation::class)->hash('line|https://access.line.me|'.$subject),
            'linked_at' => now(), 'last_authenticated_at' => now()]);
        return [$user, $subject, $account];
    }

    public function test_ciphertext_backfill_is_once_only_and_unlink_prevents_read(): void
    {
        [$user, $subject, $account] = $this->linked();
        $vault = app(V2LineSubjectVault::class);
        $vault->backfill($subject);
        $ciphertext = $account->fresh()->subject_ciphertext;
        self::assertSame($subject, Crypt::decryptString($ciphertext));
        $vault->backfill($subject);
        self::assertSame($ciphertext, $account->fresh()->subject_ciphertext);
        self::assertSame($subject, $vault->rawSubjectFor($user));
        self::assertArrayNotHasKey('subject_ciphertext', $account->fresh()->toArray());
        $account->forceFill(['revoked_at' => now()])->save();
        self::assertNull($vault->rawSubjectFor($user));
    }

    public function test_false_without_friendship_rejects_delayed_follow_and_equal_time_unfollow_wins(): void
    {
        [$user, $subject, $account] = $this->linked();
        $service = app(V2LineFriendService::class);
        $at = CarbonImmutable::now()->startOfSecond();
        $request = (string) Str::uuid7();
        self::assertNull($service->applyObservation($subject, $user, false, $at, $request));
        self::assertSame(0, DB::table('line_friendships')->count());
        $service->handleEvents([['webhookEventId' => 'synthetic-delayed-follow', 'type' => 'follow',
            'source' => ['type' => 'user', 'userId' => $subject], 'timestamp' => $at->subSecond()->getTimestampMs(),
            'replyToken' => 'synthetic-unused-reply']], $request);
        self::assertSame(0, DB::table('line_friendships')->count());
        self::assertSame('skipped', DB::table('line_webhook_events')->value('reply_status'));
        self::assertNull($service->applyObservation($subject, $user, true, $at, $request));
        self::assertSame(0, DB::table('line_friendships')->count());
        self::assertNull($service->applyObservation($subject, $user, true, $at->addSecond(), $request));
        self::assertSame('friend', DB::table('line_friendships')->value('status'));
        self::assertNull($service->applyObservation($subject, $user, false, $at->addSecond(), $request));
        self::assertSame('unfollowed', DB::table('line_friendships')->value('status'));
    }

    public function test_later_reward_enablement_grants_once_and_unfollow_retains_reward(): void
    {
        [$user, $subject] = $this->linked();
        $service = app(V2LineFriendService::class);
        $at = CarbonImmutable::now()->startOfSecond();
        $request = (string) Str::uuid7();
        $read = app(\App\Domain\Line\Services\V2CurrentUserLineReadService::class);
        self::assertSame('start_friendship_recheck', $read->presentation($user)['secondary_action']['code']);
        self::assertNull($service->applyObservation($subject, $user, true, $at, $request));
        self::assertNull(DB::table('line_friendships')->value('point_operation_id'));
        self::assertSame(0, DB::table('audit_logs')->where('action_code', 'line.friend.reward.skipped')->count());
        self::assertNull($read->presentation($user)['secondary_action']);
        DB::table('line_messaging_settings')->where('id', 1)->update([
            'reward_enabled' => true, 'reward_point_amount' => 500, 'reward_expiration_days' => 60,
        ]);
        self::assertSame('start_friendship_recheck', $read->presentation($user)['secondary_action']['code']);
        self::assertNull($service->applyObservation($subject, $user, true, $at->addDay(), $request));
        $operation = DB::table('line_friendships')->value('point_operation_id');
        self::assertNotNull($operation);
        $lot = \App\Models\V2\PointLot::query()->where('grant_operation_id', $operation)->sole();
        self::assertTrue($lot->granted_at->copy()->addDays(60)->equalTo($lot->expire_at));
        self::assertTrue($read->presentation($user)['reward']['granted']);
        self::assertNull($read->presentation($user)['secondary_action']);
        self::assertNull($service->applyObservation($subject, $user, true, $at->addDays(2), $request));
        self::assertNull($service->applyObservation($subject, $user, false, $at->addDays(3), $request));
        self::assertSame($operation, DB::table('line_friendships')->value('point_operation_id'));
        self::assertSame(1, DB::table('point_operations')->count());
        config(['v2_line.friendship_check_enabled' => false]);
        self::assertNull($read->presentation($user)['secondary_action']);
    }

    public function test_notice_is_session_bound_and_consumed_once(): void
    {
        $notice = app(V2LineCallbackNotice::class);
        $notice->put('synthetic-session-a', 'reward_apply_failed');
        self::assertNull($notice->consume('synthetic-session-b'));
        self::assertSame('reward_apply_failed', $notice->consume('synthetic-session-a'));
        self::assertNull($notice->consume('synthetic-session-a'));
        $notice->put('synthetic-session-a', 'friendship_check_failed');
        $this->travel(121)->seconds();
        self::assertNull($notice->consume('synthetic-session-a'));
        $this->travelBack();
    }

    public function test_unreadable_or_different_existing_ciphertext_fails_without_overwrite(): void
    {
        foreach (['synthetic-invalid-ciphertext', Crypt::encryptString('synthetic-other')] as $ciphertext) {
            DB::beginTransaction();
            [, $subject, $account] = $this->linked();
            DB::table('external_identity_accounts')->where('id', $account->id)->update(['subject_ciphertext' => $ciphertext]);
            try {
                app(V2LineSubjectVault::class)->backfill($subject);
                self::fail('Invalid stored subject was accepted');
            } catch (\App\Domain\Line\Exceptions\V2LineMessagingException $exception) {
                self::assertStringNotContainsString($subject, $exception->getMessage());
                self::assertStringNotContainsString($ciphertext, $exception->getMessage());
            }
            self::assertSame($ciphertext, $account->fresh()->subject_ciphertext);
            DB::rollBack();
        }
    }

    public function test_reward_failure_rolls_back_financial_mutations_and_can_retry(): void
    {
        [$user, $subject] = $this->linked();
        DB::table('line_messaging_settings')->where('id', 1)->update(['reward_enabled' => true, 'reward_point_amount' => 500]);
        DB::unprepared("CREATE FUNCTION line_test_reject_ledger() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'synthetic ledger failure'; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER line_test_reject_ledger BEFORE INSERT ON point_ledger_entries FOR EACH ROW EXECUTE FUNCTION line_test_reject_ledger()");
        $service = app(V2LineFriendService::class);
        $at = CarbonImmutable::now()->startOfSecond();
        $request = (string) Str::uuid7();
        self::assertSame('reward_apply_failed', $service->applyObservation($subject, $user, true, $at, $request));
        self::assertSame('friend', DB::table('line_friendships')->value('status'));
        self::assertNull(DB::table('line_friendships')->value('point_operation_id'));
        self::assertSame(0, DB::table('point_operations')->count());
        self::assertSame(0, DB::table('point_lots')->count());
        self::assertSame(0, DB::table('point_ledger_entries')->count());
        DB::unprepared('DROP TRIGGER line_test_reject_ledger ON point_ledger_entries; DROP FUNCTION line_test_reject_ledger()');
        self::assertNull($service->applyObservation($subject, $user, true, $at->addSecond(), $request));
        self::assertNotNull(DB::table('line_friendships')->value('point_operation_id'));
        self::assertSame(1, DB::table('point_operations')->count());
    }

    public function test_old_hmac_backfill_and_message_event_do_not_change_binding(): void
    {
        [$user, $subject, $account] = $this->linked();
        $oldHash = $account->subject_hash;
        config(['v2_identity.sms_verification.phone_hmac_previous_keys' => ['base64:'.base64_encode(str_repeat('p', 32))],
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
        app(V2LineFriendService::class)->handleEvents([['type' => 'message', 'source' => ['type' => 'user', 'userId' => $subject], 'message' => ['text' => 'synthetic ignored text']]], (string) Str::uuid7());
        self::assertSame($oldHash, $account->fresh()->subject_hash);
        self::assertSame($subject, app(V2LineSubjectVault::class)->rawSubjectFor($user));
        self::assertSame(0, DB::table('line_webhook_events')->count());
        self::assertSame(0, DB::table('line_friendships')->count());
    }

    public function test_wrong_subject_is_rejected_without_changing_ciphertext(): void
    {
        [, $subject, $account] = $this->linked();
        $vault = app(V2LineSubjectVault::class);
        $vault->backfill($subject);
        $before = $account->fresh()->subject_ciphertext;
        try {
            $vault->store('external_identity_accounts', $account->fresh(), 'synthetic-other-subject');
            self::fail('Mismatched subject was accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('LINE_SUBJECT_BINDING_REJECTED', $exception->getMessage());
        }
        self::assertSame($before, $account->fresh()->subject_ciphertext);
    }

    public function test_claimed_pending_backfill_and_database_immutability_guards(): void
    {
        [$user, $subject, $account] = $this->linked();
        DB::table('line_pending_follows')->insert(['public_id' => (string) Str::uuid7(), 'subject_hash' => $account->subject_hash,
            'status' => 'claimed', 'claimed_by_user_id' => $user->id, 'followed_at' => now(), 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(V2LineFriendService::class)->applyObservation($subject, $user, true, CarbonImmutable::now(), (string) Str::uuid7());
        app(V2LineSubjectVault::class)->backfill($subject);
        foreach (['external_identity_accounts', 'line_pending_follows', 'line_friendships'] as $table) {
            $row = DB::table($table)->first();
            self::assertSame($subject, Crypt::decryptString($row->subject_ciphertext));
            foreach (['ciphertext', 'identity', 'delete', 'truncate'] as $attempt) {
                try {
                    DB::transaction(function () use ($table, $row, $attempt): void {
                        match ($attempt) {
                            'ciphertext' => DB::table($table)->where('id', $row->id)->update(['subject_ciphertext' => Crypt::encryptString('synthetic-other')]),
                            'identity' => DB::table($table)->where('id', $row->id)->update(['subject_hash' => str_repeat('b', 64)]),
                            'delete' => DB::table($table)->where('id', $row->id)->delete(),
                            'truncate' => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'),
                        };
                    });
                    self::fail('Immutable LINE mutation was accepted: '.$attempt);
                } catch (\Illuminate\Database\QueryException $exception) {
                    self::assertNotEmpty($exception->getCode());
                }
            }
        }
        self::assertSame('claimed', DB::table('line_pending_follows')->value('status'));
    }

    public function test_partial_observation_snapshot_is_rejected_by_database(): void
    {
        [, , $account] = $this->linked();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(fn () => DB::table('external_identity_accounts')->where('id', $account->id)
            ->update(['line_observed_friend' => false]));
    }
}
