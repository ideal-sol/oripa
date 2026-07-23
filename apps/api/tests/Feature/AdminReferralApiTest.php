<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReferralApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_user_referrals(): void
    {
        $this->actingAdmin();
        $referrer = User::factory()->create(['email' => 'referrer@example.test']);
        $referred = User::factory()->create(['email' => 'referred@example.test']);
        $referral = UserReferral::query()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => $referrer->referral_code,
            'status' => 'pending',
            'reward_point_amount' => 500,
            'reward_expiration_days' => 180,
        ]);

        $this->getJson('/admin/api/referrals?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $referral->id)
            ->assertJsonPath('data.0.referrer.email', 'referrer@example.test')
            ->assertJsonPath('data.0.referred.email', 'referred@example.test')
            ->assertJsonPath('data.0.reward_point_amount', 500);
    }

    public function test_admin_can_filter_user_referrals_by_either_user_id(): void
    {
        $this->actingAdmin();
        $target = User::factory()->create(['email' => 'target@example.test']);
        $referred = User::factory()->create(['email' => 'target-referred@example.test']);
        $anotherReferrer = User::factory()->create(['email' => 'another-referrer@example.test']);
        $anotherReferred = User::factory()->create(['email' => 'another-referred@example.test']);
        $madeReferral = UserReferral::query()->create([
            'referrer_user_id' => $target->id,
            'referred_user_id' => $referred->id,
            'referral_code' => $target->referral_code,
            'status' => 'pending',
            'reward_point_amount' => 500,
        ]);
        $receivedReferral = UserReferral::query()->create([
            'referrer_user_id' => $anotherReferrer->id,
            'referred_user_id' => $target->id,
            'referral_code' => $anotherReferrer->referral_code,
            'status' => 'pending',
            'reward_point_amount' => 300,
        ]);
        UserReferral::query()->create([
            'referrer_user_id' => $anotherReferrer->id,
            'referred_user_id' => $anotherReferred->id,
            'referral_code' => $anotherReferrer->referral_code,
            'status' => 'pending',
            'reward_point_amount' => 100,
        ]);

        $this->getJson("/admin/api/referrals?user_id={$target->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $receivedReferral->id)
            ->assertJsonPath('data.1.id', $madeReferral->id);
    }

    public function test_admin_can_update_referral_setting(): void
    {
        $admin = $this->actingAdmin();
        ReferralSetting::current();

        $this->putJson('/admin/api/referral-settings', [
            'reward_point_amount' => 800,
            'reward_expiration_days' => 90,
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.reward_point_amount', 800)
            ->assertJsonPath('data.reward_expiration_days', 90)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('referral_settings', [
            'id' => 1,
            'reward_point_amount' => 800,
            'reward_expiration_days' => 90,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.referral_setting.updated',
            'auditable_type' => ReferralSetting::class,
            'auditable_id' => 1,
        ]);
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
}
