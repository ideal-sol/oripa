<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SignupSmsGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        config(['cache.default' => 'array', 'v2_identity.origins.user' => 'https://storefront.example.test']);
        Cache::store('array')->clear();
        $this->mock(V2EmailVerificationNotifier::class)->shouldReceive('send');
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        $this->travelBack();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_registration_rejects_missing_blank_invalid_future_and_underage_values(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-11T14:59:59Z'));
        $service = app(V2UserAuthenticationService::class);
        foreach ([[null, null], ['', '1990-01-01'], [" \t　\n", '1990-01-01'], ['Member', null],
            ['Member', '2026-02-30'], ['Member', 'not-a-date'], ['Member', '2027-01-01'],
            ['Member', '2008-10-12'], ['Member', '2000-1-1']] as [$name, $date]) {
            try {
                $service->register('invalid@example.test', 'valid test password', '/', '192.0.2.10', fullName: $name, birthDate: $date);
                self::fail('Invalid registration must not create an account.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_REGISTRATION', $exception->errorCode);
                self::assertSame(422, $exception->status);
            }
        }
        self::assertFalse(User::query()->where('email_normalized', 'invalid@example.test')->exists());
        $this->travel(1)->seconds();
        $user = $service->register('birthday@example.test', 'valid test password', '/', '192.0.2.11', fullName: '　Synthetic Member ', birthDate: '2008-10-12');
        self::assertSame('Synthetic Member', $user->full_name);
        self::assertSame('2008-10-12', $user->birth_date->format('Y-m-d'));
        self::assertNull($user->display_name);
        self::assertNull($user->first_sms_verified_at);
        self::assertArrayNotHasKey('full_name', $user->toArray());
        self::assertArrayNotHasKey('birth_date', $user->toArray());
    }

    public function test_registration_http_requires_both_new_fields(): void
    {
        $csrf = str_repeat('b', 64);
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://storefront.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf);
        foreach (['full_name', 'birth_date'] as $field) {
            $input = ['full_name' => 'Synthetic Member', 'birth_date' => '1990-01-01',
                'email' => 'missing-field@example.test', 'password' => 'synthetic password'];
            unset($input[$field]);
            $this->postJson('/api/v2/auth/register', $input)->assertUnprocessable();
        }
        self::assertFalse(User::query()->where('email_normalized', 'missing-field@example.test')->exists());
    }

    public function test_every_member_route_rejects_direct_calls_before_controller_execution(): void
    {
        $user = User::query()->create(['email_display' => 'gate@example.test', 'email_normalized' => 'gate@example.test',
            'password_hash' => app(\App\Domain\Identity\Services\V2PasswordPolicy::class)->hash('synthetic password'),
            'email_verified_at' => now(), 'state' => V2UserState::Active]);
        Auth::guard('v2_user')->setUser($user);
        $csrf = str_repeat('a', 64);
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://storefront.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf);
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('v2.realm:user,sms', $route->middleware(), true));
        self::assertGreaterThanOrEqual(25, $routes->count());
        foreach ($routes as $route) {
            $url = '/'.preg_replace('/\{[^}]+\}/', (string) Str::uuid7(), $route->uri());
            $this->json($route->methods()[0], $url, [])
                ->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
        }
        $this->getJson('/api/v2/auth/session')->assertOk()->assertJsonPath('authenticated', true);
        $this->getJson('/api/v2/me/sms-verification')->assertOk()->assertJsonPath('verified', false);
        foreach (['/gachas', '/login-gachas', '/point-products', '/content/notices'] as $path) {
            $this->getJson('/api/v2'.$path)->assertOk();
        }
        UserPhoneNumber::query()->create(['user_id' => $user->id, 'phone_ciphertext' => Crypt::encryptString('+819012345678'),
            'phone_hmac' => hash('sha256', $user->public_id), 'verified_at' => now()]);
        $this->getJson('/api/v2/me/prizes')->assertOk();
        UserPhoneNumber::query()->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/api/v2/me/prizes')->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
    }

    public function test_first_sms_timestamp_cannot_be_reset_changed_or_cleared_in_sql(): void
    {
        $user = User::query()->create(['email_display' => 'immutable@example.test', 'email_normalized' => 'immutable@example.test',
            'password_hash' => app(\App\Domain\Identity\Services\V2PasswordPolicy::class)->hash('synthetic password'),
            'state' => V2UserState::Active, 'first_sms_verified_at' => now()->startOfSecond()]);
        foreach ([null, now()->addDay()] as $replacement) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['first_sms_verified_at' => $replacement]));
                self::fail('Database must reject qualification reset.');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertStringContainsString('first_sms_verified_at is immutable', $exception->getMessage());
            }
        }
        self::assertTrue($user->first_sms_verified_at->equalTo($user->fresh()->first_sms_verified_at));
    }
}
