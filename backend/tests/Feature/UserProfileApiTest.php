<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create(['name' => 'Profile User']);
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 100,
            'free_balance' => 200,
        ]);
        UserProfile::query()->create([
            'user_id' => $user->id,
            'last_name' => '山田',
            'first_name' => '太郎',
            'postal_code' => '100-0001',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Profile User')
            ->assertJsonPath('data.profile.last_name', '山田')
            ->assertJsonPath('data.wallet.total_balance', 300);
    }

    public function test_authenticated_user_can_update_profile_and_address(): void
    {
        $user = User::factory()->create(['name' => 'Before Name']);
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 0,
            'free_balance' => 0,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/me/profile', [
            'name' => 'After Name',
            'last_name' => '佐藤',
            'first_name' => '花子',
            'last_name_kana' => 'サトウ',
            'first_name_kana' => 'ハナコ',
            'postal_code' => '150-0001',
            'prefecture' => '東京都',
            'city' => '渋谷区',
            'address_line1' => '神宮前1-1',
            'address_line2' => 'テストビル202',
            'phone_number' => '09012345678',
            'birth_date' => '1990-01-02',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'After Name')
            ->assertJsonPath('data.profile.last_name', '佐藤')
            ->assertJsonPath('data.profile.birth_date', '1990-01-02');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'After Name',
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'postal_code' => '150-0001',
            'address_line1' => '神宮前1-1',
        ]);
    }

    public function test_profile_update_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/me/profile', [
            'name' => '',
            'birth_date' => 'invalid-date',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'birth_date']);
    }
}
