<?php

namespace Tests\Unit;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2AdminUserReadService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2AdminUserReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        config([
            'cache.default' => 'array',
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('u', 32)),
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_list_and_detail_use_display_name_wallet_balances_and_opaque_cursor(): void
    {
        $first = $this->user(null, 'active');
        $second = $this->user('表示名あり', 'restricted');
        $this->wallet($second->id, 10, 30, 7, 11);
        $service = app(V2AdminUserReadService::class);
        $context = $this->context(V2AdminRole::Operator);

        $page = $service->users($context, null, 1);
        self::assertCount(1, $page['items']);
        self::assertSame($second->public_id, $page['items'][0]['id']);
        self::assertSame('表示名あり', $page['items'][0]['display_name']);
        self::assertSame('restricted', $page['items'][0]['status']);
        self::assertSame([
            'total_balance' => 40,
            'paid_balance' => 10,
            'free_balance' => 30,
        ], $page['items'][0]['point_balance']);
        self::assertNotNull($page['next_cursor']);
        self::assertStringNotContainsString((string) $second->id, $page['next_cursor']);

        $next = $service->users($context, $page['next_cursor'], 1);
        self::assertSame($first->public_id, $next['items'][0]['id']);
        self::assertNull($next['items'][0]['display_name']);
        self::assertNull($next['items'][0]['point_balance']);
        self::assertNull($next['next_cursor']);

        $detail = $service->user($context, $second->public_id)['data'];
        self::assertSame($second->email_display, $detail['email']);
        self::assertArrayNotHasKey('password_hash', $detail);
        self::assertArrayNotHasKey('email_normalized', $detail);
        self::assertArrayNotHasKey('paid_reserved_balance', $detail['point_balance']);
    }

    public function test_gacha_history_is_past_inclusive_user_prize_data_with_public_ids(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../V2/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $user = $this->user('抽選ユーザー', 'active');
        app(V2PointService::class)->grantFree(
            $user->id,
            1000,
            now()->addYear(),
            'admin-user-read-points-'.Str::uuid7()
        );
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(static fn (): int => 1)
        );
        app(V2DrawService::class)->create(
            $user,
            $fixture['gachas'][0]['public_id'],
            1,
            'admin-user-read-draw-key',
            (string) Str::uuid7()
        );

        $history = app(V2AdminUserReadService::class)->gachaHistory(
            $this->context(V2AdminRole::Owner),
            $user->public_id,
            null,
            20
        );

        self::assertCount(1, $history['items']);
        self::assertSame($user->public_id, $history['user_id']);
        self::assertSame('stored', $history['items'][0]['status']);
        self::assertTrue(Str::isUuid($history['items'][0]['id']));
        self::assertTrue(Str::isUuid($history['items'][0]['draw_result_id']));
        self::assertTrue(Str::isUuid($history['items'][0]['gacha_id']));
        self::assertTrue(Str::isUuid($history['items'][0]['prize_id']));
        self::assertArrayNotHasKey('user_id', $history['items'][0]);
        self::assertArrayNotHasKey('gacha_version_prize_id', $history['items'][0]);
    }

    private function user(?string $displayName, string $state): User
    {
        $email = 'admin-user-read-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => $displayName,
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::from($state),
        ]);
    }

    private function wallet(
        int $userId,
        int $paid,
        int $free,
        int $paidReserved,
        int $freeReserved
    ): void {
        DB::table('wallets')->insert([
            'user_id' => $userId,
            'paid_balance' => $paid,
            'free_balance' => $free,
            'paid_reserved_balance' => $paidReserved,
            'free_reserved_balance' => $freeReserved,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'admin-user-reader-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', random_bytes(32));

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
