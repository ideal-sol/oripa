<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'last_name' => '山田',
            'first_name' => '太郎',
            'phone_number' => '09012345678',
            'device_name' => 'feature-test',
        ])
            ->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'new-user@example.test')
            ->assertJsonPath('user.wallet.total_balance', 0)
            ->assertJsonPath('user.profile.last_name', '山田')
            ->assertJsonStructure(['access_token']);

        $this->assertDatabaseHas('users', [
            'email' => 'new-user@example.test',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'last_name' => '山田',
            'first_name' => '太郎',
        ]);
        $this->assertDatabaseHas('wallets', [
            'paid_balance' => 0,
            'free_balance' => 0,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_register_payload_is_validated(): void
    {
        User::factory()->create(['email' => 'used@example.test']);

        $this->postJson('/api/register', [
            'name' => '',
            'email' => 'used@example.test',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_active_user_can_login_and_receive_token(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 1000,
            'free_balance' => 200,
        ]);

        $this->postJson('/api/login', [
            'email' => 'user@example.test',
            'password' => 'secret-password',
            'device_name' => 'feature-test',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'user@example.test')
            ->assertJsonPath('user.wallet.total_balance', 1200)
            ->assertJsonStructure(['access_token']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_invalid_or_inactive_user(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'suspended',
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_logout_current_token(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('feature-test', ['user']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
