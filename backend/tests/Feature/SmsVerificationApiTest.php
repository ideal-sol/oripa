<?php

namespace Tests\Feature;

use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\DTO\SmsMessage;
use App\Domain\Notification\DTO\SmsSendResult;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\SmsVerificationCode;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmsVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSmsSender::reset();
        $this->app->instance(SmsSender::class, new FakeSmsSender());
        config([
            'services.sms.verification_code_ttl_minutes' => 10,
            'services.sms.verification_code_max_attempts' => 5,
        ]);
    }

    public function test_authenticated_user_can_send_sms_verification_code(): void
    {
        $user = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $user->id,
            'phone_number' => '090-1111-2222',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/sms-verification')
            ->assertCreated()
            ->assertJsonPath('message', 'SMS verification code has been sent.')
            ->assertJsonPath('data.phone_number', '09011112222');

        $verification = SmsVerificationCode::query()->firstOrFail();
        $this->assertSame($user->id, $verification->user_id);
        $this->assertSame('pending', $verification->status);
        $this->assertSame('09011112222', $verification->phone_number);
        $this->assertMatchesRegularExpression('/\d{6}/', FakeSmsSender::$messages[0]->body);
        $this->assertNotSame($this->lastSentCode(), $verification->code_hash);
    }

    public function test_authenticated_user_can_verify_sms_code(): void
    {
        $user = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $user->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/sms-verification')->assertCreated();
        $code = $this->lastSentCode();

        $this->postJson('/api/me/sms-verification/verify', [
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'SMS verification has been completed.')
            ->assertJsonPath('user.sms_verified', true)
            ->assertJsonPath('user.sms_verified_at', fn (?string $value): bool => $value !== null);

        $this->assertNotNull($user->refresh()->sms_verified_at);
        $this->assertDatabaseHas('sms_verification_codes', [
            'user_id' => $user->id,
            'status' => 'verified',
        ]);
    }

    public function test_sms_verification_grants_referral_reward_once(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $referred->id,
            'phone_number' => '09012345678',
        ]);
        $referral = UserReferral::query()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => $referrer->referral_code,
            'status' => 'pending',
            'reward_point_amount' => 700,
            'reward_expiration_days' => 180,
        ]);
        Sanctum::actingAs($referred);

        $this->postJson('/api/me/sms-verification')->assertCreated();
        $this->postJson('/api/me/sms-verification/verify', [
            'code' => $this->lastSentCode(),
        ])->assertOk();

        $this->assertDatabaseHas('user_referrals', [
            'id' => $referral->id,
            'status' => 'rewarded',
        ]);
        $this->assertSame(700, (int) $referrer->wallet()->firstOrFail()->free_balance);
        $this->assertSame(1, PointLot::query()->where('source_type', 'referral')->where('source_id', $referral->id)->count());
        $this->assertSame(1, PointLedger::query()->where('related_type', 'user_referral')->where('related_id', $referral->id)->count());

        $this->postJson('/api/me/sms-verification/verify', [
            'code' => $this->lastSentCode(),
        ])->assertUnprocessable();

        $this->assertSame(700, (int) $referrer->wallet()->firstOrFail()->free_balance);
        $this->assertSame(1, PointLot::query()->where('source_type', 'referral')->where('source_id', $referral->id)->count());
    }

    public function test_invalid_sms_code_increments_attempts(): void
    {
        $user = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $user->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/sms-verification')->assertCreated();

        $this->postJson('/api/me/sms-verification/verify', [
            'code' => '000000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertDatabaseHas('sms_verification_codes', [
            'user_id' => $user->id,
            'status' => 'pending',
            'attempts' => 1,
        ]);
        $this->assertNull($user->refresh()->sms_verified_at);
    }

    public function test_resend_cancels_previous_pending_code_and_creates_new_code(): void
    {
        $user = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $user->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/sms-verification')->assertCreated();
        $firstId = SmsVerificationCode::query()->firstOrFail()->id;

        $this->postJson('/api/me/sms-verification/resend')
            ->assertOk()
            ->assertJsonPath('message', 'SMS verification code has been resent.');

        $this->assertSame(2, SmsVerificationCode::query()->count());
        $this->assertDatabaseHas('sms_verification_codes', [
            'id' => $firstId,
            'status' => 'canceled',
        ]);
        $this->assertDatabaseHas('sms_verification_codes', [
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
        $this->assertCount(2, FakeSmsSender::$messages);
    }

    public function test_sms_verification_status_can_be_fetched(): void
    {
        $user = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $user->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/sms-verification')->assertCreated();

        $this->getJson('/api/me/sms-verification')
            ->assertOk()
            ->assertJsonPath('data.sms_verified', false)
            ->assertJsonPath('data.pending', true)
            ->assertJsonPath('data.phone_number', '09012345678');
    }

    public function test_unverified_duplicate_phone_number_can_send_sms(): void
    {
        $firstUser = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $firstUser->id,
            'phone_number' => '09012345678',
        ]);
        $secondUser = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $secondUser->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($secondUser);

        $this->postJson('/api/me/sms-verification')
            ->assertCreated()
            ->assertJsonPath('data.phone_number', '09012345678');
    }

    public function test_sms_send_is_rejected_when_active_user_already_verified_phone_number(): void
    {
        $owner = User::factory()->create([
            'sms_verified_at' => now(),
            'status' => 'active',
        ]);
        UserProfile::query()->create([
            'user_id' => $owner->id,
            'phone_number' => '09012345678',
        ]);
        $otherUser = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $otherUser->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson('/api/me/sms-verification')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);

        $this->assertSame(0, SmsVerificationCode::query()->count());
        $this->assertCount(0, FakeSmsSender::$messages);
    }

    public function test_suspended_verified_phone_number_cannot_be_reused(): void
    {
        $owner = User::factory()->create([
            'sms_verified_at' => now(),
            'status' => 'suspended',
        ]);
        UserProfile::query()->create([
            'user_id' => $owner->id,
            'phone_number' => '09012345678',
        ]);
        $otherUser = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $otherUser->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson('/api/me/sms-verification')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_withdrawn_verified_phone_number_can_be_reused(): void
    {
        $owner = User::factory()->create([
            'sms_verified_at' => now(),
            'status' => 'withdrawn',
        ]);
        UserProfile::query()->create([
            'user_id' => $owner->id,
            'phone_number' => '09012345678',
        ]);
        $otherUser = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $otherUser->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson('/api/me/sms-verification')
            ->assertCreated()
            ->assertJsonPath('data.phone_number', '09012345678');
    }

    public function test_verified_user_phone_change_releases_old_phone_number(): void
    {
        $owner = User::factory()->create([
            'sms_verified_at' => now(),
            'status' => 'active',
        ]);
        UserProfile::query()->create([
            'user_id' => $owner->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($owner);

        $this->putJson('/api/me/profile', [
            'name' => $owner->name,
            'phone_number' => '08011112222',
        ])->assertOk()
            ->assertJsonPath('data.sms_verified', false);

        $otherUser = User::factory()->create(['sms_verified_at' => null]);
        UserProfile::query()->create([
            'user_id' => $otherUser->id,
            'phone_number' => '09012345678',
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson('/api/me/sms-verification')
            ->assertCreated()
            ->assertJsonPath('data.phone_number', '09012345678');
    }

    private function lastSentCode(): string
    {
        preg_match('/(\d{6})/', FakeSmsSender::$messages[array_key_last(FakeSmsSender::$messages)]->body, $matches);

        return $matches[1];
    }
}

class FakeSmsSender implements SmsSender
{
    /** @var array<int, SmsMessage> */
    public static array $messages = [];

    public static function reset(): void
    {
        self::$messages = [];
    }

    public function send(SmsMessage $message): SmsSendResult
    {
        self::$messages[] = $message;

        return SmsSendResult::sent('fake', 'fake-'.count(self::$messages));
    }
}
