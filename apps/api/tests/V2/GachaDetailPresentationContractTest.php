<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GachaDetailPresentationContractTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-29T00:00:00Z');
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_public_detail_exposes_sale_state_without_user_state_in_public_cache(): void
    {
        $this->import();

        $onSale = $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'on_sale');
        self::assertStringContainsString(
            'public',
            (string) $onSale->headers->get('Cache-Control')
        );
        $serialized = $onSale->getContent();
        self::assertIsString($serialized);
        foreach (['user_state', 'eligible', 'daily_limit', 'allowed_draw_counts', 'cta'] as $key) {
            self::assertStringNotContainsString('"'.$key.'"', $serialized);
        }

        CarbonImmutable::setTestNow('2025-12-31T23:59:59Z');
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'coming_soon');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.display.show_price_points', true);

        CarbonImmutable::setTestNow('2027-01-01T00:00:00Z');
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'ended');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.display.show_price_points', false)
            ->assertJsonPath('data.display.show_total_count', false)
            ->assertJsonPath('data.display.show_drawn_count', false);

        CarbonImmutable::setTestNow('2026-07-29T00:00:00Z');
        DB::table('gacha_draw_states')->update([
            'status' => 'sold_out',
            'sold_count' => 1000,
            'sold_out_at' => now(),
        ]);
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'sold_out');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.display.show_price_points', false)
            ->assertJsonPath('data.display.show_total_count', false)
            ->assertJsonPath('data.display.show_drawn_count', false);
    }

    public function test_anonymous_and_all_user_states_are_private_and_machine_readable(): void
    {
        $this->import();

        $anonymous = $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.user_state', 'unauthenticated')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.ineligible_reason', 'authentication_required')
            ->assertJsonPath('data.cta.state', 'enabled')
            ->assertJsonPath('data.cta.action', 'login')
            ->assertJsonPath('data.daily_limit.used', null);
        $cacheControl = (string) $anonymous->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('Cookie', $anonymous->headers->get('Vary'));

        $this->authenticate($this->user());
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.user_state', 'authenticated')
            ->assertJsonPath('data.audience', 'all_users')
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.ineligible_reason', null)
            ->assertJsonPath('data.allowed_draw_counts', [1, 5, 10, 100])
            ->assertJsonPath('data.daily_limit.unlimited', true)
            ->assertJsonPath('data.cta.action', 'draw');
    }

    public function test_first_time_audience_reuses_completed_normal_draw_rules(): void
    {
        $this->import(audience: 'first_time_users');
        $user = $this->user();
        $this->authenticate($user);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', true);

        $drawId = $this->draw($user, 1);
        self::assertNotSame('', $drawId);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.ineligible_reason', 'audience_not_eligible');
    }

    public function test_line_audience_requires_link_and_confirmed_friendship(): void
    {
        $this->import(audience: 'line_users');
        $user = $this->user();
        $this->authenticate($user);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', false);
        $subjectHash = hash('sha256', 'line-'.$user->public_id);
        DB::table('external_identity_accounts')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'provider' => 'line',
            'issuer' => 'https://access.line.me',
            'subject_hash' => $subjectHash,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', false);
        DB::table('line_friendships')->insert([
            'public_id' => (string) Str::uuid7(),
            'subject_hash' => $subjectHash,
            'user_id' => $user->id,
            'status' => 'friend',
            'followed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', true);
    }

    public function test_catalog_keeps_authenticated_ineligible_gacha_private(): void
    {
        $this->import(audience: 'line_users');
        $user = $this->user();
        $this->authenticate($user);

        $response = $this->getJson('/api/v2/gachas')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.presentation.user_state', 'authenticated')
            ->assertJsonPath('data.0.presentation.eligible', false)
            ->assertJsonPath(
                'data.0.presentation.ineligible_reason',
                'audience_not_eligible'
            );

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    public function test_daily_limit_allowed_counts_and_jst_reset_match_draw_authority(): void
    {
        CarbonImmutable::setTestNow('2026-07-29T14:59:59Z');
        $this->import(dailyLimit: 10);
        $user = $this->user();
        $this->authenticate($user);
        $this->draw($user, 5);

        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.daily_limit.limit', 10)
            ->assertJsonPath('data.daily_limit.used', 5)
            ->assertJsonPath('data.daily_limit.remaining', 5)
            ->assertJsonPath('data.daily_limit.resets_at', '2026-07-29T15:00:00Z')
            ->assertJsonPath('data.allowed_draw_counts', [1, 5]);

        $this->draw($user, 5);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.ineligible_reason', 'daily_limit_reached')
            ->assertJsonPath('data.allowed_draw_counts', []);

        CarbonImmutable::setTestNow('2026-07-29T15:00:00Z');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.daily_limit.used', 0)
            ->assertJsonPath('data.daily_limit.remaining', 10)
            ->assertJsonPath('data.allowed_draw_counts', [1, 5, 10]);
    }

    private function import(
        int $dailyLimit = 0,
        string $audience = 'all_users'
    ): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['versions'][0]['daily_draw_limit'] = $dailyLimit;
        $fixture['versions'][0]['audience_code'] = $audience;
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $index = 0;
        $values = [5_000, 50_000, 150_000, 999_999];
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static function () use (&$index, $values): int {
                    $value = $values[$index % count($values)];
                    $index++;

                    return $value;
                }
            )
        );
    }

    private function user(): User
    {
        $email = 'presentation-'.Str::uuid().'@example.test';
        $user = User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            10_000,
            now()->addYear(),
            'presentation-points-'.Str::uuid()
        );

        return $user;
    }

    private function authenticate(User $user): void
    {
        Auth::guard('v2_user')->setUser($user);
    }

    private function draw(User $user, int $count): string
    {
        return app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            $count,
            'presentation-draw-'.Str::uuid(),
            (string) Str::uuid7()
        )['id'];
    }

    private function presentationUrl(): string
    {
        return '/api/v2/gacha-presentations/'.self::GACHA_ID;
    }
}
