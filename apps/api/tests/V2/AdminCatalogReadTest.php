<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Catalog\Services\V2AdminCatalogReadService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminCatalogReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($fixture);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_all_fixed_admin_roles_can_read_every_catalog_resource(): void
    {
        $resources = [
            'categories' => '0198a001-0000-7000-8000-000000000001',
            'tags' => '0198a001-0000-7000-8000-000000000002',
            'ranks' => '0198a001-0000-7000-8000-000000000003',
            'prizes' => '0198a001-0000-7000-8000-000000000009',
            'presentation-assets' => '0198a001-0000-7000-8000-000000000005',
        ];
        foreach (V2AdminRole::cases() as $role) {
            $token = $this->createAdminSession($role);
            foreach ($resources as $resource => $publicId) {
                Auth::forgetGuards();
                $list = $this->asAdmin($token)
                    ->getJson("/admin/api/v2/catalog/{$resource}")
                    ->assertOk()
                    ->assertJsonStructure(['items', 'next_cursor']);
                $cacheControl = (string) $list->headers->get('Cache-Control');
                self::assertStringContainsString('private', $cacheControl);
                self::assertStringContainsString('no-store', $cacheControl);
                self::assertTrue(Str::isUuid(
                    (string) $list->headers->get('X-Request-Id')
                ));

                Auth::forgetGuards();
                $this->asAdmin($token)
                    ->getJson("/admin/api/v2/catalog/{$resource}/{$publicId}")
                    ->assertOk()
                    ->assertJsonPath('data.id', $publicId);
            }
        }
    }

    public function test_search_filter_sort_and_opaque_cursor_are_stable(): void
    {
        $now = now();
        for ($index = 0; $index < 24; $index++) {
            DB::table('catalog_categories')->insert([
                'public_id' => (string) Str::uuid7(),
                'code' => sprintf('catalog-%02d', $index),
                'slug' => sprintf('catalog-%02d', $index),
                'display_name' => sprintf('Catalog %02d', $index),
                'description' => null,
                'sort_order' => 100 + $index,
                'is_visible' => $index % 2 === 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $token = $this->createAdminSession(V2AdminRole::Operator);
        $first = $this->asAdmin($token)
            ->getJson(
                '/admin/api/v2/catalog/categories'
                .'?q=Catalog&visibility=visible&sort=code&direction=asc&limit=5'
            )
            ->assertOk()
            ->assertJsonCount(5, 'items');
        $cursor = $first->json('next_cursor');
        self::assertIsString($cursor);
        self::assertStringNotContainsString('catalog-', $cursor);

        Auth::forgetGuards();
        $second = $this->asAdmin($token)
            ->getJson(
                '/admin/api/v2/catalog/categories'
                .'?q=Catalog&visibility=visible&sort=code&direction=asc&limit=5'
                .'&cursor='.urlencode($cursor)
            )
            ->assertOk();
        self::assertNotSame(
            $first->json('items.0.id'),
            $second->json('items.0.id')
        );

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson(
                '/admin/api/v2/catalog/categories'
                .'?sort=name&direction=asc&cursor='.urlencode($cursor)
            )
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CURSOR')
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_prize_and_asset_responses_do_not_expose_internal_fields(): void
    {
        DB::table('catalog_presentation_assets')
            ->where('public_id', '0198a001-0000-7000-8000-000000000005')
            ->update(['is_public' => false]);
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $prize = $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/prizes')
            ->assertOk()
            ->json();
        Auth::forgetGuards();
        $asset = $this->asAdmin($token)
            ->getJson(
                '/admin/api/v2/catalog/presentation-assets/'
                .'0198a001-0000-7000-8000-000000000005'
            )
            ->assertOk()
            ->assertJsonPath('data.public_path', null)
            ->json();
        $encoded = json_encode([$prize, $asset], JSON_THROW_ON_ERROR);
        foreach ([
            'storage_identifier',
            'cost_price',
            'probability_ppm',
            'rank_id',
            'presentation_asset_id',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_catalog_is_fail_closed_for_missing_or_incomplete_session(): void
    {
        $this->getJson('/admin/api/v2/catalog/categories')
            ->assertUnauthorized();

        $token = $this->createAdminSession(
            V2AdminRole::Owner,
            requiresEnrollment: true
        );
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/categories')
            ->assertUnauthorized();

        $context = new V2AdminAuthorizationContext(
            999999,
            (string) Str::uuid7(),
            V2AdminRole::Owner,
            str_repeat('f', 64),
            str_repeat('e', 64),
            (string) Str::uuid7()
        );
        $this->expectException(V2AuthenticationException::class);
        app(V2AdminCatalogReadService::class)->categories($context, []);
    }

    public function test_prize_and_asset_contracts_remain_read_only(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        foreach ([
            '/admin/api/v2/catalog/prizes',
            '/admin/api/v2/catalog/presentation-assets',
        ] as $path) {
            Auth::forgetGuards();
            $this->asAdmin($token)->postJson($path, [])
                ->assertStatus(405);
        }
    }

    private function asAdmin(string $token): static
    {
        return $this
            ->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function createAdminSession(
        V2AdminRole $role,
        bool $requiresEnrollment = false
    ): string {
        $email = $role->value.'-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid catalog read test password'),
            'role' => $role->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)
                ->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => $requiresEnrollment,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]);

        return $token;
    }
}
