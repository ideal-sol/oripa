<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\SmsVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmsVerificationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_verification_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'sms_verified_at'));
        $this->assertTrue(Schema::hasColumn('user_profiles', 'normalized_phone_number'));
        $this->assertTrue(Schema::hasTable('sms_verification_codes'));
        $this->assertTrue(Schema::hasColumns('sms_verification_codes', [
            'user_id',
            'phone_number',
            'purpose',
            'status',
            'code_hash',
            'attempts',
            'max_attempts',
            'resend_count',
            'last_sent_at',
            'expires_at',
            'verified_at',
        ]));
    }

    public function test_user_can_have_pending_sms_verification_code(): void
    {
        $user = User::factory()->create([
            'sms_verified_at' => null,
        ]);

        $code = SmsVerificationCode::query()->create([
            'user_id' => $user->id,
            'phone_number' => '09012345678',
            'purpose' => 'registration',
            'status' => 'pending',
            'code_hash' => Hash::make('123456'),
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['provider' => 'dummy'],
        ]);

        $this->assertTrue($code->isPending());
        $this->assertTrue($user->smsVerificationCodes()->whereKey($code->id)->exists());
        $this->assertDatabaseHas('sms_verification_codes', [
            'id' => $code->id,
            'user_id' => $user->id,
            'phone_number' => '09012345678',
            'purpose' => 'registration',
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 5,
            'resend_count' => 0,
        ]);
    }

    public function test_user_resource_exposes_sms_verification_state(): void
    {
        $unverifiedUser = User::factory()->create([
            'sms_verified_at' => null,
        ]);
        $verifiedUser = User::factory()->create([
            'sms_verified_at' => now(),
        ]);

        $unverifiedPayload = (new UserResource($unverifiedUser))->resolve();
        $verifiedPayload = (new UserResource($verifiedUser))->resolve();

        $this->assertFalse($unverifiedPayload['sms_verified']);
        $this->assertNull($unverifiedPayload['sms_verified_at']);
        $this->assertTrue($verifiedPayload['sms_verified']);
        $this->assertNotNull($verifiedPayload['sms_verified_at']);
    }
}
